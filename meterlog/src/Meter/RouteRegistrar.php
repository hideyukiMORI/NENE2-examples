<?php

declare(strict_types=1);

namespace Meterlog\Meter;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    /** Hardcoded secrets for FT simplicity — use env-based config in production. */
    private const string ADMIN_KEY   = 'admin-secret';
    private const string MACHINE_KEY = 'machine-secret';

    public function __construct(
        private readonly Router              $router,
        private readonly MeterRepository    $repository,
        private readonly JsonResponseFactory $responseFactory,
    ) {
    }

    public function register(): void
    {
        // Quota management (admin)
        $this->router->post('/quotas', $this->handleUpsertQuota(...));
        $this->router->get('/quotas/{userId}', $this->handleGetQuotaStatus(...));

        // Usage recording (machine client)
        $this->router->post('/usage', $this->handleRecordUsage(...));

        // Usage queries
        $this->router->get('/usage/{userId}/breakdown', $this->handleBreakdown(...));
        $this->router->post('/usage/check', $this->handleCheck(...));
    }

    // ── POST /quotas ──────────────────────────────────────────────────────
    private function handleUpsertQuota(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->responseFactory->create(['error' => 'Admin key required.'], 401);
        }

        $body       = $this->parseBody($request);
        $userId     = isset($body['user_id']) && is_int($body['user_id']) ? $body['user_id'] : 0;
        $dailyLimit = isset($body['daily_limit']) && is_int($body['daily_limit']) ? $body['daily_limit'] : 0;

        if ($userId <= 0) {
            return $this->responseFactory->create(['error' => 'user_id must be a positive integer.'], 422);
        }

        if ($dailyLimit <= 0) {
            return $this->responseFactory->create(['error' => 'daily_limit must be a positive integer.'], 422);
        }

        $quota = $this->repository->upsertQuota($userId, $dailyLimit, date('c'));

        return $this->responseFactory->create($quota->toArray(), 200);
    }

    // ── GET /quotas/{userId} ──────────────────────────────────────────────
    // Returns today's quota status for the given user.
    // Public endpoint — any client may check remaining quota.
    private function handleGetQuotaStatus(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->intRouteParam($request, 'userId');

        if ($userId <= 0) {
            return $this->responseFactory->create(['error' => 'Invalid user_id.'], 422);
        }

        $today  = date('Y-m-d');
        $status = $this->repository->statusForToday($userId, $today);

        return $this->responseFactory->create($status);
    }

    // ── POST /usage ───────────────────────────────────────────────────────
    private function handleRecordUsage(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isMachineClient($request)) {
            return $this->responseFactory->create(['error' => 'Machine key required.'], 401);
        }

        $body     = $this->parseBody($request);
        $userId   = isset($body['user_id']) && is_int($body['user_id']) ? $body['user_id'] : 0;
        $endpoint = isset($body['endpoint']) && is_string($body['endpoint']) ? trim($body['endpoint']) : '';

        if ($userId <= 0) {
            return $this->responseFactory->create(['error' => 'user_id must be a positive integer.'], 422);
        }

        if ($endpoint === '') {
            return $this->responseFactory->create(['error' => 'endpoint is required.'], 422);
        }

        $now    = date('c');
        $dayKey = substr($now, 0, 10);

        $this->repository->record($userId, $endpoint, $now);

        return $this->responseFactory->create([
            'recorded' => true,
            'user_id'  => $userId,
            'endpoint' => $endpoint,
            'day_key'  => $dayKey,
        ], 201);
    }

    // ── GET /usage/{userId}/breakdown ─────────────────────────────────────
    // Returns per-endpoint call counts for a user on the given day.
    // Requires the caller to be the same user (X-User-Id) or an admin.
    private function handleBreakdown(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->intRouteParam($request, 'userId');

        if ($userId <= 0) {
            return $this->responseFactory->create(['error' => 'Invalid user_id.'], 422);
        }

        // Authorization: own user or admin
        if (!$this->isAdmin($request) && !$this->isOwner($request, $userId)) {
            return $this->responseFactory->create(['error' => 'Access denied.'], 403);
        }

        // ?date=YYYY-MM-DD (default: today)
        $params  = $request->getQueryParams();
        $dateRaw = isset($params['date']) && is_string($params['date']) ? $params['date'] : '';
        $date    = $this->validateDate($dateRaw);

        if ($date === null) {
            return $this->responseFactory->create(['error' => 'date must be YYYY-MM-DD format.'], 422);
        }

        $breakdown = $this->repository->breakdownForDay($userId, $date);

        return $this->responseFactory->create([
            'user_id'   => $userId,
            'date'      => $date,
            'breakdown' => $breakdown,
            'total'     => array_sum(array_column($breakdown, 'count')),
        ]);
    }

    // ── POST /usage/check ─────────────────────────────────────────────────
    // Lightweight quota gate — returns whether the user is still within quota.
    // Machine clients use this before recording actual usage.
    private function handleCheck(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isMachineClient($request)) {
            return $this->responseFactory->create(['error' => 'Machine key required.'], 401);
        }

        $body   = $this->parseBody($request);
        $userId = isset($body['user_id']) && is_int($body['user_id']) ? $body['user_id'] : 0;

        if ($userId <= 0) {
            return $this->responseFactory->create(['error' => 'user_id must be a positive integer.'], 422);
        }

        $today  = date('Y-m-d');
        $status = $this->repository->statusForToday($userId, $today);

        return $this->responseFactory->create([
            'user_id'   => $status['user_id'],
            'day'       => $status['day'],
            'allowed'   => $status['allowed'],
            'remaining' => $status['remaining'],
            'used'      => $status['used'],
            'limit'     => $status['daily_limit'],
        ]);
    }

    // ── Auth helpers ──────────────────────────────────────────────────────

    private function isAdmin(ServerRequestInterface $request): bool
    {
        $key = $request->getHeaderLine('X-Admin-Key');

        return $key !== '' && hash_equals(self::ADMIN_KEY, $key);
    }

    private function isMachineClient(ServerRequestInterface $request): bool
    {
        $key = $request->getHeaderLine('X-Machine-Key');

        return $key !== '' && hash_equals(self::MACHINE_KEY, $key);
    }

    private function isOwner(ServerRequestInterface $request, int $userId): bool
    {
        $header = $request->getHeaderLine('X-User-Id');
        $callerId = $header !== '' ? (int) $header : 0;

        return $callerId > 0 && $callerId === $userId;
    }

    // ── Misc helpers ──────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function parseBody(ServerRequestInterface $request): array
    {
        $body = json_decode((string) $request->getBody(), true);

        return is_array($body) ? $body : [];
    }

    private function intRouteParam(ServerRequestInterface $request, string $key): int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);

        return (int) ($params[$key] ?? 0);
    }

    /**
     * Returns YYYY-MM-DD string if valid, null otherwise.
     * An empty string defaults to today.
     */
    private function validateDate(string $raw): ?string
    {
        if ($raw === '') {
            return date('Y-m-d');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            return null;
        }

        $ts = strtotime($raw);

        if ($ts === false) {
            return null;
        }

        // Re-format to catch calendar overflows (e.g. 2024-02-30 → Mar 1)
        if (date('Y-m-d', $ts) !== $raw) {
            return null;
        }

        return $raw;
    }
}
