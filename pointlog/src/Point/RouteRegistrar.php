<?php

declare(strict_types=1);

namespace PointLog\Point;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const int MAX_EARN_PER_TRANSACTION = 10000;
    private const int MAX_ADJUST_PER_TRANSACTION = 100000;

    public function __construct(
        private readonly Router $router,
        private readonly PointRepository $repository,
        private readonly JsonResponseFactory $responseFactory,
    ) {
    }

    public function register(): void
    {
        $this->router->get('/users/{userId}/points', $this->handleGetBalance(...));
        $this->router->get('/users/{userId}/points/history', $this->handleGetHistory(...));
        $this->router->post('/users/{userId}/points/earn', $this->handleEarn(...));
        $this->router->post('/users/{userId}/points/spend', $this->handleSpend(...));
        $this->router->post('/users/{userId}/points/adjust', $this->handleAdjust(...));
    }

    private function requireUserId(ServerRequestInterface $request): ?int
    {
        $val = $request->getHeaderLine('X-User-Id');
        return $val !== '' ? (int) $val : null;
    }

    private function isAdmin(ServerRequestInterface $request): bool
    {
        return $request->getHeaderLine('X-User-Role') === 'admin';
    }

    private function routeParam(ServerRequestInterface $request, string $key): string
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        return (string) ($params[$key] ?? '');
    }

    private function handleGetBalance(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireUserId($request);
        if ($actorId === null) {
            return $this->responseFactory->create(['error' => 'authentication required'], 401);
        }

        $targetUserId = (int) $this->routeParam($request, 'userId');
        if ($targetUserId !== $actorId && !$this->isAdmin($request)) {
            return $this->responseFactory->create(['error' => 'access denied'], 403);
        }

        $user = $this->repository->findUserById($targetUserId);
        if ($user === null) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $balance = $this->repository->getBalance($targetUserId);
        return $this->responseFactory->create([
            'user_id' => $targetUserId,
            'balance' => $balance,
        ]);
    }

    private function handleGetHistory(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireUserId($request);
        if ($actorId === null) {
            return $this->responseFactory->create(['error' => 'authentication required'], 401);
        }

        $targetUserId = (int) $this->routeParam($request, 'userId');
        if ($targetUserId !== $actorId && !$this->isAdmin($request)) {
            return $this->responseFactory->create(['error' => 'access denied'], 403);
        }

        $user = $this->repository->findUserById($targetUserId);
        if ($user === null) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $transactions = $this->repository->listTransactions($targetUserId);
        return $this->responseFactory->create([
            'user_id' => $targetUserId,
            'balance' => $this->repository->getBalance($targetUserId),
            'transactions' => array_map(fn (array $t) => $this->formatTransaction($t), $transactions),
        ]);
    }

    private function handleEarn(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireUserId($request);
        if ($actorId === null) {
            return $this->responseFactory->create(['error' => 'authentication required'], 401);
        }

        $targetUserId = (int) $this->routeParam($request, 'userId');
        if ($targetUserId !== $actorId && !$this->isAdmin($request)) {
            return $this->responseFactory->create(['error' => 'access denied'], 403);
        }

        $user = $this->repository->findUserById($targetUserId);
        if ($user === null) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            $body = [];
        }

        $amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : null;
        if ($amount === null || $amount <= 0) {
            return $this->responseFactory->create(['error' => 'amount must be a positive integer'], 422);
        }
        if ($amount > self::MAX_EARN_PER_TRANSACTION) {
            return $this->responseFactory->create(['error' => 'amount exceeds maximum per transaction', 'max' => self::MAX_EARN_PER_TRANSACTION], 422);
        }

        $description = isset($body['description']) && is_string($body['description']) && $body['description'] !== ''
            ? trim($body['description'])
            : 'Points earned';
        $referenceId = isset($body['reference_id']) && is_string($body['reference_id']) && $body['reference_id'] !== ''
            ? $body['reference_id']
            : null;

        if ($referenceId !== null) {
            $existing = $this->repository->findByReferenceId($targetUserId, $referenceId);
            if ($existing !== null) {
                return $this->responseFactory->create($this->formatTransaction($existing), 200);
            }
        }

        $now = date('c');
        $balance = $this->repository->getBalance($targetUserId);
        $balanceAfter = $balance + $amount;
        $id = $this->repository->addTransaction($targetUserId, 'earn', $amount, $balanceAfter, $description, $referenceId, $now);
        $tx = $this->repository->findTransactionById($id);

        return $this->responseFactory->create($this->formatTransaction($tx ?? []), 201);
    }

    private function handleSpend(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireUserId($request);
        if ($actorId === null) {
            return $this->responseFactory->create(['error' => 'authentication required'], 401);
        }

        $targetUserId = (int) $this->routeParam($request, 'userId');
        if ($targetUserId !== $actorId && !$this->isAdmin($request)) {
            return $this->responseFactory->create(['error' => 'access denied'], 403);
        }

        $user = $this->repository->findUserById($targetUserId);
        if ($user === null) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            $body = [];
        }

        $amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : null;
        if ($amount === null || $amount <= 0) {
            return $this->responseFactory->create(['error' => 'amount must be a positive integer'], 422);
        }

        $balance = $this->repository->getBalance($targetUserId);
        if ($balance < $amount) {
            return $this->responseFactory->create(['error' => 'insufficient points', 'balance' => $balance, 'required' => $amount], 422);
        }

        $description = isset($body['description']) && is_string($body['description']) && $body['description'] !== ''
            ? trim($body['description'])
            : 'Points spent';
        $referenceId = isset($body['reference_id']) && is_string($body['reference_id']) && $body['reference_id'] !== ''
            ? $body['reference_id']
            : null;

        if ($referenceId !== null) {
            $existing = $this->repository->findByReferenceId($targetUserId, $referenceId);
            if ($existing !== null) {
                return $this->responseFactory->create($this->formatTransaction($existing), 200);
            }
        }

        $now = date('c');
        $balanceAfter = $balance - $amount;
        $id = $this->repository->addTransaction($targetUserId, 'spend', $amount, $balanceAfter, $description, $referenceId, $now);
        $tx = $this->repository->findTransactionById($id);

        return $this->responseFactory->create($this->formatTransaction($tx ?? []), 201);
    }

    private function handleAdjust(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireUserId($request);
        if ($actorId === null) {
            return $this->responseFactory->create(['error' => 'authentication required'], 401);
        }
        if (!$this->isAdmin($request)) {
            return $this->responseFactory->create(['error' => 'admin role required'], 403);
        }

        $targetUserId = (int) $this->routeParam($request, 'userId');
        $user = $this->repository->findUserById($targetUserId);
        if ($user === null) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            $body = [];
        }

        $amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : null;
        if ($amount === null || $amount <= 0) {
            return $this->responseFactory->create(['error' => 'amount must be a positive integer'], 422);
        }
        if ($amount > self::MAX_ADJUST_PER_TRANSACTION) {
            return $this->responseFactory->create(['error' => 'amount exceeds maximum per transaction'], 422);
        }

        $adjustType = isset($body['adjust_type']) && $body['adjust_type'] === 'subtract' ? 'subtract' : 'add';

        $description = isset($body['description']) && is_string($body['description']) && $body['description'] !== ''
            ? trim($body['description'])
            : 'Admin adjustment';

        $now = date('c');
        $balance = $this->repository->getBalance($targetUserId);

        if ($adjustType === 'subtract') {
            if ($balance < $amount) {
                return $this->responseFactory->create(['error' => 'insufficient points for adjustment', 'balance' => $balance], 422);
            }
            $balanceAfter = $balance - $amount;
        } else {
            $balanceAfter = $balance + $amount;
        }

        $id = $this->repository->addTransaction($targetUserId, 'adjust', $amount, $balanceAfter, $description, null, $now);
        $tx = $this->repository->findTransactionById($id);

        return $this->responseFactory->create($this->formatTransaction($tx ?? []), 201);
    }

    /**
     * @param array<string, mixed> $t
     * @return array<string, mixed>
     */
    private function formatTransaction(array $t): array
    {
        return [
            'id' => isset($t['id']) ? (int) $t['id'] : null,
            'user_id' => isset($t['user_id']) ? (int) $t['user_id'] : null,
            'type' => $t['type'] ?? null,
            'amount' => isset($t['amount']) ? (int) $t['amount'] : null,
            'balance_after' => isset($t['balance_after']) ? (int) $t['balance_after'] : null,
            'description' => $t['description'] ?? null,
            'reference_id' => $t['reference_id'] ?? null,
            'created_at' => $t['created_at'] ?? null,
        ];
    }
}
