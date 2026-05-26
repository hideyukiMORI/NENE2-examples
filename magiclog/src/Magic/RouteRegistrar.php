<?php

declare(strict_types=1);

namespace MagicLog\Magic;

use Nene2\Http\JsonResponseFactory;
use Nene2\Http\JsonRequestBodyParser;
use Nene2\Routing\Router;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly Router $router,
        private readonly MagicRepository $repository,
        private readonly JsonResponseFactory $responseFactory,
    ) {
    }

    public function register(): void
    {
        $this->router->post('/auth/request', $this->handleRequest(...));
        $this->router->post('/auth/verify', $this->handleVerify(...));
        $this->router->post('/auth/logout', $this->handleLogout(...));
        $this->router->get('/me', $this->handleMe(...));
    }

    private function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $body = JsonRequestBodyParser::parse($request);
        $email = isset($body['email']) && is_string($body['email']) ? trim($body['email']) : '';

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->responseFactory->create(['error' => 'valid email is required'], 422);
        }

        if (strlen($email) > 254) {
            return $this->responseFactory->create(['error' => 'email too long'], 422);
        }

        $now = date('c');
        $userId = $this->repository->findOrCreateUser($email, $now);

        // Generate 256-bit random token, store only the SHA-256 hash
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('c', time() + 900); // 15 minutes

        $this->repository->createMagicLink($userId, $tokenHash, $expiresAt, $now);

        // Always 202 — prevent user enumeration
        return $this->responseFactory->create([
            'message' => 'if this email is registered, a magic link has been sent',
            // In real apps: send $rawToken via email. For FT: return it for testing.
            'token' => $rawToken,
        ], 202);
    }

    private function handleVerify(ServerRequestInterface $request): ResponseInterface
    {
        $body = JsonRequestBodyParser::parse($request);
        $rawToken = isset($body['token']) && is_string($body['token']) ? $body['token'] : '';

        if ($rawToken === '') {
            return $this->responseFactory->create(['error' => 'token is required'], 422);
        }

        $tokenHash = hash('sha256', $rawToken);
        $link = $this->repository->findMagicLinkByTokenHash($tokenHash);

        if ($link === null) {
            return $this->responseFactory->create(['error' => 'invalid or expired token'], 401);
        }

        $now = date('c');

        // Check expiry BEFORE used_at to prevent timing-based enumeration
        if ($now > (string) $link['expires_at']) {
            return $this->responseFactory->create(['error' => 'token has expired'], 401);
        }

        if ($link['used_at'] !== null) {
            return $this->responseFactory->create(['error' => 'token has already been used'], 401);
        }

        $linkId = (int) $link['id'];
        $userId = (int) $link['user_id'];

        $this->repository->markMagicLinkUsed($linkId, $now);

        // Issue session token
        $rawSessionToken = bin2hex(random_bytes(32));
        $sessionTokenHash = hash('sha256', $rawSessionToken);
        $sessionExpiresAt = date('c', time() + 86400); // 24 hours

        $this->repository->createSession($userId, $sessionTokenHash, $sessionExpiresAt, $now);

        return $this->responseFactory->create([
            'session_token' => $rawSessionToken,
            'expires_at' => $sessionExpiresAt,
        ], 200);
    }

    private function handleLogout(ServerRequestInterface $request): ResponseInterface
    {
        $rawToken = $this->extractBearerToken($request);

        if ($rawToken === '') {
            return $this->responseFactory->createEmpty(204);
        }

        $tokenHash = hash('sha256', $rawToken);
        $session = $this->repository->findSessionByTokenHash($tokenHash);

        if ($session !== null && $session['revoked_at'] === null) {
            $this->repository->revokeSession((int) $session['id'], date('c'));
        }

        // Always 204 — don't reveal session existence
        return $this->responseFactory->createEmpty(204);
    }

    private function handleMe(ServerRequestInterface $request): ResponseInterface
    {
        $rawToken = $this->extractBearerToken($request);

        if ($rawToken === '') {
            return $this->responseFactory->create(['error' => 'authentication required'], 401);
        }

        $tokenHash = hash('sha256', $rawToken);
        $session = $this->repository->findSessionByTokenHash($tokenHash);

        if ($session === null) {
            return $this->responseFactory->create(['error' => 'invalid session token'], 401);
        }

        if ($session['revoked_at'] !== null) {
            return $this->responseFactory->create(['error' => 'session has been revoked'], 401);
        }

        $now = date('c');
        if ($now > (string) $session['expires_at']) {
            return $this->responseFactory->create(['error' => 'session has expired'], 401);
        }

        $user = $this->repository->findUserById((int) $session['user_id']);
        if ($user === null) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        return $this->responseFactory->create([
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'created_at' => (string) $user['created_at'],
        ], 200);
    }

    private function extractBearerToken(ServerRequestInterface $request): string
    {
        $header = $request->getHeaderLine('Authorization');
        if (!str_starts_with($header, 'Bearer ')) {
            return '';
        }
        return trim(substr($header, 7));
    }
}
