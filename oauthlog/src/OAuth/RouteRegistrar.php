<?php

declare(strict_types=1);

namespace OAuthLog\OAuth;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    public function __construct(
        private readonly OAuthRepository $repo,
        private readonly MockOAuthProvider $provider,
        private readonly JsonResponseFactory $json,
    ) {}

    public function register(Router $router): void
    {
        $router->post('/auth/oauth/start', $this->handleStart(...));
        $router->post('/auth/oauth/callback', $this->handleCallback(...));
        $router->post('/auth/logout', $this->handleLogout(...));
        $router->get('/me', $this->handleMe(...));
    }

    private function handleStart(ServerRequestInterface $request): ResponseInterface
    {
        $state = bin2hex(random_bytes(32));
        $now = $this->now();
        $this->repo->createState($state, $now);

        return $this->json->create([
            'state'    => $state,
            'auth_url' => $this->provider->buildAuthUrl($state),
        ], 201);
    }

    private function handleCallback(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $state = isset($body['state']) && is_string($body['state']) ? $body['state'] : '';
        $code  = isset($body['code'])  && is_string($body['code']) ? $body['code'] : '';

        if ($state === '') {
            throw new ValidationException([new ValidationError('state', 'state is required', 'required')]);
        }
        if ($code === '') {
            throw new ValidationException([new ValidationError('code', 'code is required', 'required')]);
        }

        $now = $this->now();

        if (!$this->repo->isStateValid($state, $now)) {
            return $this->json->create(['error' => 'Invalid, expired, or already used state'], 400);
        }

        if ($this->repo->isCodeUsed($code)) {
            return $this->json->create(['error' => 'Authorization code already used'], 400);
        }

        $userInfo = $this->provider->exchangeCode($code);
        if ($userInfo === null) {
            return $this->json->create(['error' => 'Invalid authorization code'], 401);
        }

        // Mark both state and code as consumed before issuing session
        $this->repo->markStateUsed($state, $now);
        $this->repo->markCodeUsed($code, $now);

        $userId = $this->repo->upsertUser($userInfo, $now);
        $user   = $this->repo->findUser($userId);

        $token = bin2hex(random_bytes(32));
        $this->repo->createSession($userId, $token, $now);

        return $this->json->create([
            'token' => $token,
            'user'  => $user,
        ], 200);
    }

    private function handleLogout(ServerRequestInterface $request): ResponseInterface
    {
        $token = $this->bearerToken($request);
        if ($token === null) {
            return $this->json->create(['error' => 'Authentication required'], 401);
        }

        $now = $this->now();
        $session = $this->repo->findSession($token, $now);
        if ($session === null) {
            return $this->json->create(['error' => 'Invalid or expired session'], 401);
        }

        $this->repo->revokeSession($token, $now);
        return $this->json->createEmpty(204);
    }

    private function handleMe(ServerRequestInterface $request): ResponseInterface
    {
        $token = $this->bearerToken($request);
        if ($token === null) {
            return $this->json->create(['error' => 'Authentication required'], 401);
        }

        $now = $this->now();
        $session = $this->repo->findSession($token, $now);
        if ($session === null) {
            return $this->json->create(['error' => 'Invalid or expired session'], 401);
        }

        $user = $this->repo->findUser((int) $session['user_id']);
        if ($user === null) {
            return $this->json->create(['error' => 'User not found'], 404);
        }

        return $this->json->create($user);
    }

    private function bearerToken(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }
        $token = substr($header, 7);
        return $token === '' ? null : $token;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
