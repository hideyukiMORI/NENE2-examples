<?php

declare(strict_types=1);

namespace TotpLog\Totp;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    public function __construct(
        private readonly TotpRepository $repo,
        private readonly TotpGenerator $totp,
        private readonly JsonResponseFactory $json,
    ) {}

    public function register(Router $router): void
    {
        $router->post('/users', $this->handleCreateUser(...));
        $router->get('/users/{userId}/totp', $this->handleGetStatus(...));
        $router->post('/users/{userId}/totp/setup', $this->handleSetup(...));
        $router->post('/users/{userId}/totp/enable', $this->handleEnable(...));
        $router->post('/users/{userId}/totp/verify', $this->handleVerify(...));
        $router->delete('/users/{userId}/totp', $this->handleDisable(...));
    }

    private function handleCreateUser(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        if (!isset($body['name']) || !is_string($body['name']) || trim($body['name']) === '') {
            throw new ValidationException([new ValidationError('name', 'name is required', 'required')]);
        }
        $now = $this->now();
        $id = $this->repo->createUser(trim($body['name']), $now);
        return $this->json->create(['id' => $id, 'name' => trim($body['name']), 'created_at' => $now], 201);
    }

    private function handleGetStatus(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->userId($request);
        if ($this->repo->findUser($userId) === null) {
            return $this->json->create(['error' => 'User not found'], 404);
        }
        $secret = $this->repo->findSecret($userId);
        $now = $this->now();
        return $this->json->create([
            'user_id' => $userId,
            'enabled' => $secret !== null && (int) $secret['is_enabled'] === 1,
            'setup' => $secret !== null,
            'locked' => $this->repo->isLocked($userId, $now),
        ]);
    }

    private function handleSetup(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->userId($request);
        $user = $this->repo->findUser($userId);
        if ($user === null) {
            return $this->json->create(['error' => 'User not found'], 404);
        }

        $secret = $this->totp->generateSecret();
        $this->repo->upsertSecret($userId, $secret, $this->now());

        return $this->json->create([
            'user_id' => $userId,
            'secret' => $secret,
            'otpauth_uri' => $this->totp->buildOtpAuthUri($secret, (string) $user['name']),
        ], 201);
    }

    private function handleEnable(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->userId($request);
        if ($this->repo->findUser($userId) === null) {
            return $this->json->create(['error' => 'User not found'], 404);
        }

        $secretRow = $this->repo->findSecret($userId);
        if ($secretRow === null) {
            return $this->json->create(['error' => 'TOTP not set up'], 409);
        }
        if ((int) $secretRow['is_enabled'] === 1) {
            return $this->json->create(['error' => 'TOTP already enabled'], 409);
        }

        $now = $this->now();
        if ($this->repo->isLocked($userId, $now)) {
            return $this->json->create(['error' => 'Account temporarily locked'], 423);
        }

        $code = $this->parseCode($request);
        if ($code === null) {
            throw new ValidationException([new ValidationError('code', 'code is required', 'required')]);
        }

        $matchedStep = $this->totp->verify((string) $secretRow['secret'], $code);
        if ($matchedStep === null || $this->repo->isStepUsed($userId, $matchedStep)) {
            $this->repo->recordFailure($userId, $now);
            return $this->json->create(['error' => 'Invalid or replayed code'], 401);
        }

        $this->repo->markStepUsed($userId, $matchedStep, $now);
        $this->repo->enable($userId);
        return $this->json->create(['user_id' => $userId, 'enabled' => true]);
    }

    private function handleVerify(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->userId($request);
        if ($this->repo->findUser($userId) === null) {
            return $this->json->create(['error' => 'User not found'], 404);
        }

        $secretRow = $this->repo->findSecret($userId);
        if ($secretRow === null || (int) $secretRow['is_enabled'] !== 1) {
            return $this->json->create(['error' => 'TOTP not enabled'], 409);
        }

        $now = $this->now();
        if ($this->repo->isLocked($userId, $now)) {
            return $this->json->create(['error' => 'Account temporarily locked'], 423);
        }

        $code = $this->parseCode($request);
        if ($code === null) {
            throw new ValidationException([new ValidationError('code', 'code is required', 'required')]);
        }

        $matchedStep = $this->totp->verify((string) $secretRow['secret'], $code);
        if ($matchedStep === null || $this->repo->isStepUsed($userId, $matchedStep)) {
            $this->repo->recordFailure($userId, $now);
            $row = $this->repo->findSecret($userId);
            $attempts = $row !== null ? (int) $row['failed_attempts'] : 0;
            return $this->json->create([
                'error' => 'Invalid or replayed code',
                'failed_attempts' => $attempts,
            ], 401);
        }

        $this->repo->markStepUsed($userId, $matchedStep, $now);
        $this->repo->resetFailures($userId);
        return $this->json->create(['user_id' => $userId, 'verified' => true]);
    }

    private function handleDisable(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->userId($request);
        if ($this->repo->findUser($userId) === null) {
            return $this->json->create(['error' => 'User not found'], 404);
        }

        $secretRow = $this->repo->findSecret($userId);
        if ($secretRow === null || (int) $secretRow['is_enabled'] !== 1) {
            return $this->json->create(['error' => 'TOTP not enabled'], 409);
        }

        $now = $this->now();
        $code = $this->parseCode($request);
        if ($code === null) {
            throw new ValidationException([new ValidationError('code', 'code is required', 'required')]);
        }

        $matchedStep = $this->totp->verify((string) $secretRow['secret'], $code);
        if ($matchedStep === null || $this->repo->isStepUsed($userId, $matchedStep)) {
            $this->repo->recordFailure($userId, $now);
            return $this->json->create(['error' => 'Invalid or replayed code'], 401);
        }

        $this->repo->disable($userId);
        return $this->json->createEmpty(204);
    }

    private function parseCode(ServerRequestInterface $request): ?string
    {
        $body = (array) ($request->getParsedBody() ?? []);
        if (!isset($body['code']) || !is_string($body['code']) || $body['code'] === '') {
            return null;
        }
        return $body['code'];
    }

    private function userId(ServerRequestInterface $request): int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        return (int) ($params['userId'] ?? 0);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
