<?php

declare(strict_types=1);

namespace Grantlog\Grant;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    // Maximum grant lifetime: 30 days
    private const int MAX_TTL_SECONDS = 30 * 24 * 3600;

    // Maximum resource string length (prevents megabyte-scale identifiers)
    private const int MAX_RESOURCE_LENGTH = 500;

    public function __construct(
        private readonly Router              $router,
        private readonly GrantRepository    $repository,
        private readonly JsonResponseFactory $responseFactory,
    ) {
    }

    public function register(): void
    {
        $this->router->post('/grants', $this->handleCreate(...));
        $this->router->get('/grants/issued', $this->handleIssued(...));
        $this->router->get('/grants/received', $this->handleReceived(...));
        $this->router->delete('/grants/{id}', $this->handleRevoke(...));
        $this->router->post('/grants/{id}/use', $this->handleUse(...));
    }

    // ── POST /grants ──────────────────────────────────────────────────────
    private function handleCreate(ServerRequestInterface $request): ResponseInterface
    {
        $grantorId = $this->callerId($request);

        if ($grantorId === null) {
            return $this->responseFactory->create(['error' => 'X-User-Id header required.'], 401);
        }

        $body      = $this->parseBody($request);
        $granteeId = $this->intField($body, 'grantee_id');
        $resource  = $this->strField($body, 'resource');
        $scopeRaw  = $this->strField($body, 'scope') ?: 'read';
        $expiresAt = $this->strField($body, 'expires_at');

        if ($granteeId === null || $granteeId <= 0) {
            return $this->responseFactory->create(['error' => 'grantee_id must be a positive integer.'], 422);
        }

        if ($granteeId === $grantorId) {
            return $this->responseFactory->create(['error' => 'Cannot grant access to yourself.'], 422);
        }

        if ($resource === null || $resource === '') {
            return $this->responseFactory->create(['error' => 'resource is required.'], 422);
        }

        if (mb_strlen($resource, 'UTF-8') > self::MAX_RESOURCE_LENGTH) {
            return $this->responseFactory->create(['error' => 'resource exceeds maximum length.'], 422);
        }

        $scope = GrantScope::tryFrom($scopeRaw);

        if ($scope === null) {
            return $this->responseFactory->create(
                ['error' => "scope must be one of: read, write, admin."],
                422,
            );
        }

        if ($expiresAt === null || $expiresAt === '') {
            return $this->responseFactory->create(['error' => 'expires_at is required (ISO 8601).'], 422);
        }

        $now   = date('c');
        $expTs = strtotime($expiresAt);

        if ($expTs === false) {
            return $this->responseFactory->create(['error' => 'expires_at is not a valid datetime.'], 422);
        }

        if ($expTs <= strtotime($now)) {
            return $this->responseFactory->create(['error' => 'expires_at must be in the future.'], 422);
        }

        if ($expTs - strtotime($now) > self::MAX_TTL_SECONDS) {
            return $this->responseFactory->create(['error' => 'expires_at exceeds maximum TTL of 30 days.'], 422);
        }

        try {
            $grant = $this->repository->create($grantorId, $granteeId, $resource, $scope, $expiresAt, $now);
        } catch (\Throwable) {
            return $this->responseFactory->create(['error' => 'A grant for this resource already exists.'], 409);
        }

        return $this->responseFactory->create($grant->toArray(), 201);
    }

    // ── GET /grants/issued ────────────────────────────────────────────────
    private function handleIssued(ServerRequestInterface $request): ResponseInterface
    {
        $callerId = $this->callerId($request);

        if ($callerId === null) {
            return $this->responseFactory->create(['error' => 'X-User-Id header required.'], 401);
        }

        $grants = $this->repository->findByGrantor($callerId);

        return $this->responseFactory->create([
            'grantor_id' => $callerId,
            'data'       => array_map(static fn (Grant $g): array => $g->toArray(), $grants),
        ]);
    }

    // ── GET /grants/received ──────────────────────────────────────────────
    private function handleReceived(ServerRequestInterface $request): ResponseInterface
    {
        $callerId = $this->callerId($request);

        if ($callerId === null) {
            return $this->responseFactory->create(['error' => 'X-User-Id header required.'], 401);
        }

        $grants = $this->repository->findByGrantee($callerId);

        return $this->responseFactory->create([
            'grantee_id' => $callerId,
            'data'       => array_map(static fn (Grant $g): array => $g->toArray(), $grants),
        ]);
    }

    // ── DELETE /grants/{id} ───────────────────────────────────────────────
    private function handleRevoke(ServerRequestInterface $request): ResponseInterface
    {
        $callerId = $this->callerId($request);

        if ($callerId === null) {
            return $this->responseFactory->create(['error' => 'X-User-Id header required.'], 401);
        }

        $id    = $this->intRouteParam($request, 'id');
        $grant = $this->repository->find($id);

        // IDOR: return 404 for both "not found" and "not your grant"
        if ($grant === null || $grant->grantorId !== $callerId) {
            return $this->responseFactory->create(['error' => "Grant #{$id} not found."], 404);
        }

        try {
            $revoked = $this->repository->revoke($id, date('c'));
        } catch (\RuntimeException $e) {
            return $this->responseFactory->create(['error' => $e->getMessage()], 409);
        }

        return $this->responseFactory->create($revoked->toArray());
    }

    // ── POST /grants/{id}/use ─────────────────────────────────────────────
    private function handleUse(ServerRequestInterface $request): ResponseInterface
    {
        $callerId = $this->callerId($request);

        if ($callerId === null) {
            return $this->responseFactory->create(['error' => 'X-User-Id header required.'], 401);
        }

        $id    = $this->intRouteParam($request, 'id');
        $grant = $this->repository->find($id);

        // IDOR: 404 for "not found" or "caller is not the grantee"
        if ($grant === null || $grant->granteeId !== $callerId) {
            return $this->responseFactory->create(['error' => "Grant #{$id} not found."], 404);
        }

        $now = date('c');

        if ($grant->isRevoked()) {
            return $this->responseFactory->create([
                'error'      => 'Grant has been revoked.',
                'revoked_at' => $grant->revokedAt,
            ], 403);
        }

        if ($grant->isExpired($now)) {
            return $this->responseFactory->create([
                'error'      => 'Grant has expired.',
                'expires_at' => $grant->expiresAt,
            ], 403);
        }

        $this->repository->recordUse($id);

        return $this->responseFactory->create([
            'granted'    => true,
            'grant_id'   => $grant->id,
            'resource'   => $grant->resource,
            'scope'      => $grant->scope->value,
            'used_count' => $grant->usedCount + 1,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function callerId(ServerRequestInterface $request): ?int
    {
        $header = $request->getHeaderLine('X-User-Id');
        // Reject: missing, empty, non-numeric, zero, negative
        if ($header === '' || !ctype_digit($header)) {
            return null;
        }
        $id = (int) $header;

        return $id > 0 ? $id : null;
    }

    /** @return array<string, mixed> */
    private function parseBody(ServerRequestInterface $request): array
    {
        $body = json_decode((string) $request->getBody(), true);

        return is_array($body) ? $body : [];
    }

    /**
     * @param array<string, mixed> $body
     * Strict: returns null if the key is missing OR the value is not a PHP int.
     * Rejects string "1", null, boolean — no implicit coercion.
     */
    private function intField(array $body, string $key): ?int
    {
        if (!array_key_exists($key, $body)) {
            return null;
        }

        return is_int($body[$key]) ? $body[$key] : null;
    }

    /**
     * @param array<string, mixed> $body
     * Strict: returns null if missing; trims and returns string value.
     */
    private function strField(array $body, string $key): ?string
    {
        if (!array_key_exists($key, $body)) {
            return null;
        }
        $v = $body[$key];

        return is_string($v) ? trim($v) : null;
    }

    private function intRouteParam(ServerRequestInterface $request, string $key): int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);

        return (int) ($params[$key] ?? 0);
    }
}
