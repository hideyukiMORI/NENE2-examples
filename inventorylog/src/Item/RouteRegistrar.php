<?php

declare(strict_types=1);

namespace InventoryLog\Item;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const string SKU_PATTERN = '/\A[A-Z0-9\-]{1,32}\z/';
    private const int MAX_QUANTITY = 1000000;

    public function __construct(
        private readonly ItemRepository $repo,
        private readonly JsonResponseFactory $json,
        private readonly string $adminKey,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/items', $this->handleCreate(...));
        $router->get('/items/{id}', $this->handleGet(...));
        $router->post('/items/{id}/adjust', $this->handleAdjust(...));
        $router->get('/items/{id}/history', $this->handleHistory(...));
    }

    private function handleCreate(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $errors = [];

        $sku = $body['sku'] ?? null;
        if (!is_string($sku) || preg_match(self::SKU_PATTERN, $sku) !== 1) {
            $errors[] = new ValidationError('sku', 'sku must match [A-Z0-9-]{1,32}', 'invalid_value');
        }
        $name = $body['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            $errors[] = new ValidationError('name', 'name must be a non-empty string', 'invalid_value');
        }
        $quantity = $body['quantity'] ?? 0;
        if (!is_int($quantity) || $quantity < 0 || $quantity > self::MAX_QUANTITY) {
            $errors[] = new ValidationError('quantity', 'quantity must be an integer in 0..1000000', 'invalid_value');
        }
        $priceCents = $body['price_cents'] ?? 0;
        if (!is_int($priceCents) || $priceCents < 0) {
            $errors[] = new ValidationError('price_cents', 'price_cents must be a non-negative integer', 'invalid_value');
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        assert(is_string($sku) && is_string($name) && is_int($quantity) && is_int($priceCents));

        $id = $this->repo->create($sku, trim($name), $quantity, $priceCents, $this->now());
        if ($id === null) {
            return $this->json->create(['error' => 'An item with that SKU already exists'], 409);
        }
        return $this->json->create($this->view((array) $this->repo->findById($id)), 201);
    }

    private function handleGet(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        if ($id === null) {
            return $this->notFound();
        }
        $item = $this->repo->findById($id);
        if ($item === null) {
            return $this->notFound();
        }
        return $this->json->create($this->view($item));
    }

    private function handleAdjust(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }
        $id = $this->idParam($request);
        if ($id === null) {
            return $this->notFound();
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $delta = $body['delta'] ?? null;
        if (!is_int($delta) || $delta === 0 || abs($delta) > self::MAX_QUANTITY) {
            throw new ValidationException([
                new ValidationError('delta', 'delta must be a non-zero integer with |delta| <= 1000000', 'invalid_value'),
            ]);
        }
        $reason = $body['reason'] ?? '';
        if (!is_string($reason)) {
            throw new ValidationException([new ValidationError('reason', 'reason must be a string', 'invalid_type')]);
        }

        return match ($this->repo->adjust($id, $delta, $reason, $this->now())) {
            'not_found' => $this->notFound(),
            'insufficient_stock' => $this->json->create(['error' => 'Insufficient stock'], 409),
            default => $this->json->create($this->view((array) $this->repo->findById($id))),
        };
    }

    private function handleHistory(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        if ($id === null || $this->repo->findById($id) === null) {
            return $this->notFound();
        }
        $history = array_map(
            static fn (array $h): array => [
                'id' => (int) $h['id'],
                'delta' => (int) $h['delta'],
                'reason' => (string) $h['reason'],
                'quantity_after' => (int) $h['quantity_after'],
                'created_at' => (string) $h['created_at'],
            ],
            $this->repo->history($id),
        );
        return $this->json->create(['history' => $history, 'count' => count($history)]);
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function view(array $item): array
    {
        return [
            'id' => (int) $item['id'],
            'sku' => (string) $item['sku'],
            'name' => (string) $item['name'],
            'quantity' => (int) $item['quantity'],
            'price_cents' => (int) $item['price_cents'],
            'updated_at' => (string) $item['updated_at'],
        ];
    }

    private function isAdmin(ServerRequestInterface $request): bool
    {
        if ($this->adminKey === '') {
            return false;
        }
        $provided = $request->getHeaderLine('X-Admin-Key');
        return $provided !== '' && hash_equals($this->adminKey, $provided);
    }

    /** ReDoS-safe path id: digits only, length-capped. Returns null when invalid. */
    private function idParam(ServerRequestInterface $request): ?int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $raw = (string) ($params['id'] ?? '');
        if (!ctype_digit($raw) || strlen($raw) > 18) {
            return null;
        }
        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }

    private function forbidden(): ResponseInterface
    {
        return $this->json->create(['error' => 'Admin key required'], 403);
    }

    private function notFound(): ResponseInterface
    {
        return $this->json->create(['error' => 'Item not found'], 404);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
