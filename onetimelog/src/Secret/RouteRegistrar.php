<?php

declare(strict_types=1);

namespace OneTimeLog\Secret;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\V;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const string TOKEN_PATTERN = '/\A[0-9a-f]{64}\z/';

    public function __construct(
        private readonly SecretRepository $repo,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/secrets', $this->create(...));
        $router->get('/secrets', $this->list(...));
        $router->get('/secrets/{token}', $this->consume(...));
        $router->delete('/secrets/{token}', $this->cancel(...));
    }

    private function create(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        $body = (array) ($request->getParsedBody() ?? []);

        $message = V::str($body['message'] ?? null, 10000);
        if ($message === null || $message === '') {
            throw new ValidationException([new ValidationError('message', 'message must be a non-empty string', 'invalid_value')]);
        }
        $password = V::str($body['password'] ?? null, 512);
        $passwordHash = ($password === null || $password === '') ? null : hash('sha256', $password);

        $expiresAt = null;
        if (array_key_exists('expires_at', $body) && $body['expires_at'] !== null) {
            $expiresAt = V::isoDatetime($body['expires_at']);
            if ($expiresAt === null) {
                throw new ValidationException([new ValidationError('expires_at', 'expires_at must be ISO 8601 with ±HH:MM offset', 'invalid_value')]);
            }
        }

        // Server-managed fields (token, consumed, created_at) — never from body.
        $token = bin2hex(random_bytes(32));
        $this->repo->create($userId, $token, $message, $passwordHash, $expiresAt, (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'));

        return $this->json->create(['token' => $token, 'expires_at' => $expiresAt], 201);
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        $limit = V::queryInt($request->getQueryParams(), 'limit', 1, 100, 20);
        if ($limit === null) {
            throw new ValidationException([new ValidationError('limit', 'limit must be 1..100', 'invalid_value')]);
        }
        $secrets = array_map(
            static fn (array $s): array => [
                'token' => (string) $s['token'],
                'consumed' => ((int) $s['consumed']) === 1,
                'expires_at' => $s['expires_at'] === null ? null : (string) $s['expires_at'],
                'created_at' => (string) $s['created_at'],
            ],
            $this->repo->listOwned($userId, $limit),
        );
        return $this->json->create(['secrets' => $secrets, 'count' => count($secrets)]);
    }

    private function consume(ServerRequestInterface $request): ResponseInterface
    {
        $token = $this->tokenParam($request);
        if ($token === null) {
            return $this->notFound(); // malformed token — same 404, no oracle
        }
        $secret = $this->repo->findByToken($token);
        if ($secret === null || ((int) $secret['consumed']) === 1 || $this->isExpired($secret)) {
            return $this->notFound();
        }
        // Password (constant-time). Wrong/absent password → 404 (no existence oracle).
        if ($secret['password_hash'] !== null) {
            $provided = V::str($request->getQueryParams()['password'] ?? null, 512);
            if ($provided === null || !hash_equals((string) $secret['password_hash'], hash('sha256', $provided))) {
                return $this->notFound();
            }
        }
        // Atomic guard: only the race winner gets the message.
        if (!$this->repo->markConsumed($token)) {
            return $this->notFound();
        }
        return $this->json->create(['message' => (string) $secret['message']]);
    }

    private function cancel(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        $token = $this->tokenParam($request);
        if ($token === null || !$this->repo->deleteOwnedUnconsumed($token, $userId)) {
            return $this->notFound();
        }
        return $this->json->createEmpty(204);
    }

    /** @param array<string, mixed> $secret */
    private function isExpired(array $secret): bool
    {
        if ($secret['expires_at'] === null) {
            return false;
        }
        $exp = \DateTimeImmutable::createFromFormat(DATE_ATOM, (string) $secret['expires_at']);
        return $exp !== false && $exp <= new \DateTimeImmutable();
    }

    private function tokenParam(ServerRequestInterface $request): ?string
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $token = (string) ($params['token'] ?? '');
        return preg_match(self::TOKEN_PATTERN, $token) === 1 ? $token : null;
    }

    private function unauthorized(): ResponseInterface
    {
        return $this->json->create(['error' => 'Valid X-User-Id header required'], 401);
    }

    private function notFound(): ResponseInterface
    {
        return $this->json->create(['error' => 'Secret not found or already used'], 404);
    }
}
