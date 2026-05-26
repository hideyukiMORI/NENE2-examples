<?php

declare(strict_types=1);

namespace CouponLog\Coupon;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    public function __construct(
        private readonly Router $router,
        private readonly CouponRepository $repository,
        private readonly JsonResponseFactory $responseFactory,
    ) {
    }

    public function register(): void
    {
        $this->router->post('/coupons', $this->handleCreate(...));
        $this->router->get('/coupons/{code}', $this->handleGet(...));
        $this->router->delete('/coupons/{code}', $this->handleDeactivate(...));
        $this->router->post('/coupons/{code}/use', $this->handleUse(...));
        $this->router->get('/coupons/{code}/uses', $this->handleListUses(...));
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

    private function handleCreate(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireUserId($request);
        if ($actorId === null) {
            return $this->responseFactory->create(['error' => 'authentication required'], 401);
        }
        if (!$this->isAdmin($request)) {
            return $this->responseFactory->create(['error' => 'admin role required'], 403);
        }

        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            $body = [];
        }

        $code = isset($body['code']) && is_string($body['code']) ? trim($body['code']) : '';
        if ($code === '') {
            return $this->responseFactory->create(['error' => 'code is required'], 422);
        }

        $discountPct = isset($body['discount_pct']) && is_int($body['discount_pct']) ? $body['discount_pct'] : null;
        if ($discountPct === null || $discountPct < 1 || $discountPct > 100) {
            return $this->responseFactory->create(['error' => 'discount_pct must be 1-100'], 422);
        }

        $maxUses = isset($body['max_uses']) && is_int($body['max_uses']) && $body['max_uses'] >= 0 ? $body['max_uses'] : 0;
        $expiresAt = isset($body['expires_at']) && is_string($body['expires_at']) && $body['expires_at'] !== '' ? $body['expires_at'] : null;

        $now = date('c');
        $id = $this->repository->createCoupon($actorId, $code, $discountPct, $maxUses, $expiresAt, $now);

        return $this->responseFactory->create([
            'id' => $id,
            'code' => $code,
            'discount_pct' => $discountPct,
            'max_uses' => $maxUses,
            'use_count' => 0,
            'is_active' => true,
            'expires_at' => $expiresAt,
            'created_by' => $actorId,
            'created_at' => $now,
        ], 201);
    }

    private function routeParam(ServerRequestInterface $request, string $key): string
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        return (string) ($params[$key] ?? '');
    }

    private function handleGet(ServerRequestInterface $request): ResponseInterface
    {
        $code = $this->routeParam($request, 'code');
        $coupon = $this->repository->findByCode($code);
        if ($coupon === null) {
            return $this->responseFactory->create(['error' => 'coupon not found'], 404);
        }

        return $this->responseFactory->create($this->formatCoupon($coupon));
    }

    private function handleDeactivate(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireUserId($request);
        if ($actorId === null) {
            return $this->responseFactory->create(['error' => 'authentication required'], 401);
        }
        if (!$this->isAdmin($request)) {
            return $this->responseFactory->create(['error' => 'admin role required'], 403);
        }

        $code = $this->routeParam($request, 'code');
        $coupon = $this->repository->findByCode($code);
        if ($coupon === null) {
            return $this->responseFactory->create(['error' => 'coupon not found'], 404);
        }

        $this->repository->deactivateCoupon((int) $coupon['id']);
        return $this->responseFactory->create(['message' => 'coupon deactivated', 'code' => $code]);
    }

    private function handleUse(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireUserId($request);
        if ($actorId === null) {
            return $this->responseFactory->create(['error' => 'authentication required'], 401);
        }

        $code = $this->routeParam($request, 'code');
        $coupon = $this->repository->findByCode($code);
        if ($coupon === null) {
            return $this->responseFactory->create(['error' => 'coupon not found'], 404);
        }

        if (!(bool) $coupon['is_active']) {
            return $this->responseFactory->create(['error' => 'coupon is not active'], 422);
        }

        $now = date('c');
        if ($coupon['expires_at'] !== null && $now > (string) $coupon['expires_at']) {
            return $this->responseFactory->create(['error' => 'coupon has expired'], 422);
        }

        $maxUses = (int) $coupon['max_uses'];
        if ($maxUses > 0 && (int) $coupon['use_count'] >= $maxUses) {
            return $this->responseFactory->create(['error' => 'coupon use limit reached'], 422);
        }

        $existing = $this->repository->findUse((int) $coupon['id'], $actorId);
        if ($existing !== null) {
            return $this->responseFactory->create(['error' => 'coupon already used by this user'], 422);
        }

        $useId = $this->repository->recordUse((int) $coupon['id'], $actorId, $now);

        return $this->responseFactory->create([
            'id' => $useId,
            'coupon_id' => (int) $coupon['id'],
            'code' => $code,
            'discount_pct' => (int) $coupon['discount_pct'],
            'user_id' => $actorId,
            'used_at' => $now,
        ], 201);
    }

    private function handleListUses(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireUserId($request);
        if ($actorId === null) {
            return $this->responseFactory->create(['error' => 'authentication required'], 401);
        }
        if (!$this->isAdmin($request)) {
            return $this->responseFactory->create(['error' => 'admin role required'], 403);
        }

        $code = $this->routeParam($request, 'code');
        $coupon = $this->repository->findByCode($code);
        if ($coupon === null) {
            return $this->responseFactory->create(['error' => 'coupon not found'], 404);
        }

        $uses = $this->repository->listUses((int) $coupon['id']);
        return $this->responseFactory->create([
            'code' => $code,
            'use_count' => count($uses),
            'uses' => array_map(fn (array $u) => [
                'id' => (int) $u['id'],
                'user_id' => (int) $u['user_id'],
                'user_name' => $u['user_name'],
                'used_at' => $u['used_at'],
            ], $uses),
        ]);
    }

    /**
     * @param array<string, mixed> $coupon
     * @return array<string, mixed>
     */
    private function formatCoupon(array $coupon): array
    {
        return [
            'id' => (int) $coupon['id'],
            'code' => $coupon['code'],
            'discount_pct' => (int) $coupon['discount_pct'],
            'max_uses' => (int) $coupon['max_uses'],
            'use_count' => (int) $coupon['use_count'],
            'is_active' => (bool) $coupon['is_active'],
            'expires_at' => $coupon['expires_at'],
            'created_by' => (int) $coupon['created_by'],
            'created_at' => $coupon['created_at'],
        ];
    }
}
