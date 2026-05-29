<?php

declare(strict_types=1);

namespace AssetLog\Asset;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    public function __construct(
        private readonly AssetRepository $repo,
        private readonly JsonResponseFactory $json,
        private readonly string $adminKey,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/assets', $this->handleCreate(...));
        $router->get('/assets', $this->handleList(...));
        $router->get('/assets/{id}', $this->handleGet(...));
        $router->post('/assets/{id}/checkout', $this->handleCheckout(...));
        $router->post('/assets/{id}/checkin', $this->handleCheckin(...));
        $router->get('/assets/{id}/history', $this->handleHistory(...));
    }

    private function handleCreate(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->json->create(['error' => 'Admin key required'], 403);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $name = $body['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw new ValidationException([
                new ValidationError('name', 'name must be a non-empty string', 'invalid_value'),
            ]);
        }

        $now = $this->now();
        $id = $this->repo->create(trim($name), $now);
        $asset = $this->repo->findById($id);
        assert($asset !== null);

        return $this->json->create($this->project($asset, true), 201);
    }

    private function handleList(ServerRequestInterface $request): ResponseInterface
    {
        $isAdmin = $this->isAdmin($request);
        $assets = array_map(
            fn (array $a): array => $this->project($a, $isAdmin),
            $this->repo->listAll(),
        );
        return $this->json->create(['assets' => $assets, 'count' => count($assets)]);
    }

    private function handleGet(ServerRequestInterface $request): ResponseInterface
    {
        $asset = $this->repo->findById($this->idParam($request));
        if ($asset === null) {
            return $this->json->create(['error' => 'Asset not found'], 404);
        }
        return $this->json->create($this->project($asset, $this->isAdmin($request)));
    }

    private function handleCheckout(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->resolveUserId($request);
        if ($userId === null) {
            return $this->json->create(['error' => 'Valid X-User-Id header required'], 401);
        }

        $result = $this->repo->checkout($this->idParam($request), $userId, $this->now());
        return $this->mapResult($request, $result);
    }

    private function handleCheckin(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->resolveUserId($request);
        if ($userId === null) {
            return $this->json->create(['error' => 'Valid X-User-Id header required'], 401);
        }

        $result = $this->repo->checkin($this->idParam($request), $userId, $this->now());
        return $this->mapResult($request, $result);
    }

    private function handleHistory(ServerRequestInterface $request): ResponseInterface
    {
        $assetId = $this->idParam($request);
        if ($this->repo->findById($assetId) === null) {
            return $this->json->create(['error' => 'Asset not found'], 404);
        }

        $history = array_map(
            static fn (array $h): array => [
                'id' => (int) $h['id'],
                'user_id' => (int) $h['user_id'],
                'action' => (string) $h['action'],
                'acted_at' => (string) $h['acted_at'],
            ],
            $this->repo->history($assetId),
        );
        return $this->json->create(['history' => $history, 'count' => count($history)]);
    }

    /**
     * Map a repository result string to the documented HTTP status, returning
     * the refreshed asset on success.
     */
    private function mapResult(ServerRequestInterface $request, string $result): ResponseInterface
    {
        return match ($result) {
            'not_found' => $this->json->create(['error' => 'Asset not found'], 404),
            'unavailable' => $this->json->create(['error' => 'Asset is currently held'], 409),
            'already_available' => $this->json->create(['error' => 'Asset is not checked out'], 409),
            'not_holder' => $this->json->create(['error' => 'You do not hold this asset'], 403),
            default => $this->successResponse($request),
        };
    }

    private function successResponse(ServerRequestInterface $request): ResponseInterface
    {
        $asset = $this->repo->findById($this->idParam($request));
        assert($asset !== null);
        return $this->json->create($this->project($asset, $this->isAdmin($request)));
    }

    /**
     * Project an asset row for the response. The public projection never
     * exposes `holder_id` (IDOR); only an admin key reveals the holder.
     *
     * @param array<string, mixed> $asset
     * @return array<string, mixed>
     */
    private function project(array $asset, bool $isAdmin): array
    {
        $out = [
            'id' => (int) $asset['id'],
            'name' => (string) $asset['name'],
            'available' => $asset['holder_id'] === null,
            'created_at' => (string) $asset['created_at'],
            'updated_at' => (string) $asset['updated_at'],
        ];
        if ($isAdmin) {
            $out['holder_id'] = $asset['holder_id'] === null ? null : (int) $asset['holder_id'];
        }
        return $out;
    }

    /** Constant-time admin check; fail-closed when no key is configured. */
    private function isAdmin(ServerRequestInterface $request): bool
    {
        if ($this->adminKey === '') {
            return false;
        }
        $provided = $request->getHeaderLine('X-Admin-Key');
        return $provided !== '' && hash_equals($this->adminKey, $provided);
    }

    /** ReDoS-safe user id resolution: digits only, length-capped. */
    private function resolveUserId(ServerRequestInterface $request): ?int
    {
        $raw = $request->getHeaderLine('X-User-Id');
        if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
            return null;
        }
        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }

    private function idParam(ServerRequestInterface $request): int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        return (int) ($params['id'] ?? 0);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
