<?php

declare(strict_types=1);

namespace BatchLog\Item;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\V;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    public function __construct(
        private readonly ItemRepository $repo,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/batch', $this->batch(...));
        $router->get('/items', $this->list(...));
    }

    private function batch(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        $body = (array) ($request->getParsedBody() ?? []);

        // ── Batch-level validation: 422 before iterating ──
        $items = $body['items'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new ValidationException([new ValidationError('items', '"items" must be a JSON array', 'invalid_type')]);
        }
        if ($items === []) {
            throw new ValidationException([new ValidationError('items', '"items" must not be empty', 'invalid_value')]);
        }
        if (count($items) > ItemRepository::MAX_BATCH) {
            throw new ValidationException([new ValidationError('items', 'batch must not exceed ' . ItemRepository::MAX_BATCH . ' items', 'out_of_range')]);
        }

        // ── Item-level validation: per-item, partial success (200) ──
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
        $created = [];
        $errors = [];
        foreach ($items as $index => $rawItem) {
            $error = $this->validateItem($rawItem);
            if ($error !== null) {
                $errors[] = ['index' => (int) $index, 'error' => $error];
                continue;
            }
            /** @var array<string, mixed> $rawItem */
            $id = $this->repo->create(
                $userId,
                (string) V::str($rawItem['name'], 100),
                (int) V::bodyInt($rawItem['quantity'], 1, 999),
                (int) V::bodyInt($rawItem['price_cents'], 0, 100000000),
                $now,
            );
            $created[] = $this->view((array) $this->repo->findById($id));
        }

        return $this->json->create([
            'created' => $created,
            'errors' => $errors,
            'total_created' => count($created),
            'total_errors' => count($errors),
        ]);
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        $limit = V::queryInt($request->getQueryParams(), 'limit', 1, 100, 20);
        if ($limit === null) {
            throw new ValidationException([new ValidationError('limit', 'limit must be an integer between 1 and 100', 'invalid_value')]);
        }
        return $this->json->create(['items' => array_map($this->view(...), $this->repo->listOwned($userId, $limit))]);
    }

    /** Validate one batch item; return an error message or null when valid. */
    private function validateItem(mixed $rawItem): ?string
    {
        // JSON object only — scalars and JSON arrays ([1,2]) are rejected.
        if (!is_array($rawItem) || array_is_list($rawItem)) {
            return 'each item must be a JSON object';
        }
        if (V::str($rawItem['name'] ?? null, 100) === null || ($rawItem['name'] ?? '') === '') {
            return 'name is required (max 100 chars)';
        }
        if (V::bodyInt($rawItem['quantity'] ?? null, 1, 999) === null) {
            return 'quantity must be an integer between 1 and 999';
        }
        if (V::bodyInt($rawItem['price_cents'] ?? null, 0, 100000000) === null) {
            return 'price_cents must be a non-negative integer';
        }
        return null;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function view(array $item): array
    {
        return [
            'id' => (int) $item['id'],
            'user_id' => (int) $item['user_id'],
            'name' => (string) $item['name'],
            'quantity' => (int) $item['quantity'],
            'price_cents' => (int) $item['price_cents'],
            'created_at' => (string) $item['created_at'],
        ];
    }

    private function unauthorized(): ResponseInterface
    {
        return $this->json->create(['error' => 'Valid X-User-Id header required'], 401);
    }
}
