<?php

declare(strict_types=1);

namespace OtpLog\Otp;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    public function __construct(
        private readonly Router $router,
        private readonly OtpRepository $repository,
        private readonly JsonResponseFactory $responseFactory,
    ) {
    }

    public function register(): void
    {
        $this->router->post('/otp/request', $this->handleRequest(...));
        $this->router->post('/otp/verify', $this->handleVerify(...));
        $this->router->get('/otp/session', $this->handleGetSession(...));
        $this->router->delete('/otp/session', $this->handleDeleteSession(...));
    }

    private function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        $body = is_array($body) ? $body : [];

        $email = isset($body['email']) && is_string($body['email']) ? trim($body['email']) : '';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->responseFactory->create(['error' => 'valid email required'], 422);
        }
        if (strlen($email) > 254) {
            return $this->responseFactory->create(['error' => 'email too long'], 422);
        }

        $now = date('c');
        $userId = $this->repository->findOrCreateUser($email, $now);

        $rawCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = hash('sha256', $rawCode);
        $this->repository->createOtp($userId, $codeHash, $now);

        // Always 202 — prevents user enumeration
        // In production: send email. In this FT we return the code for testing.
        return $this->responseFactory->create([
            'message' => 'OTP code sent',
            'code' => $rawCode,
        ], 202);
    }

    private function handleVerify(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        $body = is_array($body) ? $body : [];

        $email = isset($body['email']) && is_string($body['email']) ? trim($body['email']) : '';
        $code = isset($body['code']) && is_string($body['code']) ? trim($body['code']) : '';

        if ($email === '' || $code === '') {
            return $this->responseFactory->create(['error' => 'email and code are required'], 422);
        }
        if (!preg_match('/^\d{6}$/', $code)) {
            return $this->responseFactory->create(['error' => 'code must be 6 digits'], 422);
        }

        $user = $this->repository->findUserByEmail($email);
        if ($user === null) {
            return $this->responseFactory->create(['error' => 'invalid code'], 401);
        }

        $otp = $this->repository->findLatestOtpForUser((int) $user['id']);
        if ($otp === null) {
            return $this->responseFactory->create(['error' => 'invalid code'], 401);
        }

        $now = date('c');

        // Lockout check
        if ($otp['locked_until'] !== null && $now < (string) $otp['locked_until']) {
            return $this->responseFactory->create(['error' => 'too many attempts, try again later'], 429);
        }

        // Expiry check (before used_at)
        if ($now > (string) $otp['expires_at']) {
            return $this->responseFactory->create(['error' => 'code expired'], 401);
        }

        // Used check
        if ($otp['used_at'] !== null) {
            return $this->responseFactory->create(['error' => 'code already used'], 401);
        }

        // Code check
        $codeHash = hash('sha256', $code);
        if (!hash_equals((string) $otp['code_hash'], $codeHash)) {
            $this->repository->incrementAttempt((int) $otp['id'], $now);
            return $this->responseFactory->create(['error' => 'invalid code'], 401);
        }

        $this->repository->markOtpUsed((int) $otp['id'], $now);

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $this->repository->createSession((int) $user['id'], $tokenHash, $now);

        return $this->responseFactory->create([
            'session_token' => $rawToken,
            'user_id' => (int) $user['id'],
        ], 200);
    }

    private function handleGetSession(ServerRequestInterface $request): ResponseInterface
    {
        $token = $this->extractBearerToken($request);
        if ($token === '') {
            return $this->responseFactory->create(['error' => 'Bearer token required'], 401);
        }

        $tokenHash = hash('sha256', $token);
        $session = $this->repository->findSessionByTokenHash($tokenHash);
        if ($session === null) {
            return $this->responseFactory->create(['error' => 'invalid session'], 401);
        }

        $now = date('c');
        if ($session['revoked_at'] !== null) {
            return $this->responseFactory->create(['error' => 'session revoked'], 401);
        }
        if ($now > (string) $session['expires_at']) {
            return $this->responseFactory->create(['error' => 'session expired'], 401);
        }

        return $this->responseFactory->create([
            'user_id' => (int) $session['user_id'],
            'expires_at' => (string) $session['expires_at'],
        ], 200);
    }

    private function handleDeleteSession(ServerRequestInterface $request): ResponseInterface
    {
        $token = $this->extractBearerToken($request);
        if ($token === '') {
            return $this->responseFactory->create(['error' => 'Bearer token required'], 401);
        }

        $tokenHash = hash('sha256', $token);
        $session = $this->repository->findSessionByTokenHash($tokenHash);
        if ($session !== null && $session['revoked_at'] === null) {
            $this->repository->revokeSession($tokenHash, date('c'));
        }

        return $this->responseFactory->create(['message' => 'logged out'], 200);
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
