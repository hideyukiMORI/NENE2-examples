<?php

declare(strict_types=1);

namespace VerifyLog\Verification;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\V;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const int MAX_ATTEMPTS = 3;
    private const int TTL_MINUTES = 10;

    /** @param \Closure(): string $codeGenerator */
    public function __construct(
        private readonly VerificationRepository $repo,
        private readonly JsonResponseFactory $json,
        private readonly \Closure $codeGenerator,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/verifications', $this->request(...));
        $router->post('/verifications/{id}/check', $this->check(...));
        $router->get('/verifications/{id}', $this->status(...));
    }

    private function request(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $contact = V::str($body['contact'] ?? null, 320);
        if ($contact === null || $contact === '') {
            throw new ValidationException([new ValidationError('contact', 'contact must be a non-empty string', 'invalid_value')]);
        }

        $code = ($this->codeGenerator)();
        $now = new \DateTimeImmutable();
        $id = $this->repo->create(
            $contact,
            hash('sha256', $code),
            $now->modify('+' . self::TTL_MINUTES . ' minutes')->format('Y-m-d\TH:i:s\Z'),
            $now->format('Y-m-d\TH:i:s\Z'),
        );

        // Always 202 — the code is delivered out of band (SMS/email), never in
        // the response, and the status never reveals whether the contact existed.
        return $this->json->create(['id' => $id, 'status' => 'pending'], 202);
    }

    private function check(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        $v = $id === 0 ? null : $this->repo->find($id);
        if ($v === null) {
            return $this->json->create(['error' => 'Verification not found'], 404);
        }
        if ($v['verified_at'] !== null) {
            return $this->json->create(['error' => 'This verification has already been completed'], 410);
        }
        if ($this->isExpired((string) $v['expires_at'])) {
            return $this->json->create(['error' => 'Verification has expired. Request a new code'], 410);
        }
        if ((int) $v['attempts_count'] >= self::MAX_ATTEMPTS) {
            return $this->json->create(['error' => 'Too many failed attempts. Request a new code'], 429);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $code = $body['code'] ?? null;
        if (!is_string($code) || !ctype_digit($code) || strlen($code) !== 6) {
            throw new ValidationException([new ValidationError('code', 'code must be a 6-digit string', 'invalid_value')]);
        }

        // Fail-first: count the attempt before comparing.
        $this->repo->incrementAttempts($id);
        $attempts = (int) $v['attempts_count'] + 1;

        if (!hash_equals((string) $v['code_hash'], hash('sha256', $code))) {
            if ($attempts >= self::MAX_ATTEMPTS) {
                return $this->json->create(['error' => 'Too many failed attempts. Request a new code'], 429);
            }
            return $this->json->create(['error' => 'Incorrect code', 'attempts_left' => self::MAX_ATTEMPTS - $attempts], 422);
        }

        $this->repo->markVerified($id, (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'));
        return $this->json->create(['verified' => true]);
    }

    private function status(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        $v = $id === 0 ? null : $this->repo->find($id);
        if ($v === null) {
            return $this->json->create(['error' => 'Verification not found'], 404);
        }
        return $this->json->create([
            'id' => (int) $v['id'],
            'verified' => $v['verified_at'] !== null,
            'attempts_left' => max(0, self::MAX_ATTEMPTS - (int) $v['attempts_count']),
        ]);
    }

    private function isExpired(string $expiresAt): bool
    {
        $exp = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $expiresAt, new \DateTimeZone('UTC'));
        return $exp !== false && $exp <= new \DateTimeImmutable();
    }

    private function idParam(ServerRequestInterface $request): int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $raw = (string) ($params['id'] ?? '');
        return ctype_digit($raw) && strlen($raw) <= 18 ? (int) $raw : 0;
    }
}
