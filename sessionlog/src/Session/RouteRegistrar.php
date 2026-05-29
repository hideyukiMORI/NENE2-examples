<?php

declare(strict_types=1);

namespace SessionLog\Session;

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
        private readonly SessionRepository $repo,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/sessions', $this->create(...));
        $router->get('/sessions', $this->list(...));
        $router->delete('/sessions/{token}', $this->revokeOne(...));
        $router->delete('/sessions', $this->revokeAll(...));
    }

    private function create(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        $body = (array) ($request->getParsedBody() ?? []);
        // Only safe fields are read; token/user_id/created_at/revoked_at are server-set.
        $deviceName = V::str($body['device_name'] ?? '', 200);
        $ipAddress = V::str($body['ip_address'] ?? '', 45);
        if ($deviceName === null || $ipAddress === null) {
            throw new ValidationException([new ValidationError('device_name', 'device_name / ip_address must be strings', 'invalid_type')]);
        }

        $token = bin2hex(random_bytes(32));
        $this->repo->create($userId, $token, $deviceName, $ipAddress, (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'));
        return $this->json->create(['token' => $token, 'device_name' => $deviceName], 201);
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        $limit = V::queryInt($request->getQueryParams(), 'limit', 1, 100, 50);
        if ($limit === null) {
            throw new ValidationException([new ValidationError('limit', 'limit must be 1..100', 'invalid_value')]);
        }
        $sessions = array_map(
            static fn (array $s): array => [
                'token' => (string) $s['token'],
                'device_name' => (string) $s['device_name'],
                'ip_address' => (string) $s['ip_address'],
                'created_at' => (string) $s['created_at'],
            ],
            $this->repo->listActive($userId, $limit),
        );
        return $this->json->create(['sessions' => $sessions, 'count' => count($sessions)]);
    }

    private function revokeOne(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        $token = $this->tokenParam($request);
        // Malformed token, wrong user, not found, already revoked → identical 404.
        if ($token === null || !$this->repo->revokeForUser($token, $userId, $this->now())) {
            return $this->notFound();
        }
        return $this->json->createEmpty(204);
    }

    private function revokeAll(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        $current = $request->getHeaderLine('X-Current-Session');
        if (preg_match(self::TOKEN_PATTERN, $current) !== 1) {
            throw new ValidationException([new ValidationError('X-Current-Session', 'X-Current-Session must be a valid session token', 'invalid_value')]);
        }
        $revoked = $this->repo->revokeAllExcept($userId, $current, $this->now());
        return $this->json->create(['revoked' => $revoked]);
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
        return $this->json->create(['error' => 'Session not found'], 404);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
