<?php

declare(strict_types=1);

namespace WishlistLog\Wishlist;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const array VALID_PRIORITIES = ['high', 'medium', 'low'];

    public function __construct(
        private readonly Router $router,
        private readonly WishlistRepository $repository,
        private readonly JsonResponseFactory $responseFactory,
    ) {
    }

    public function register(): void
    {
        $this->router->post('/wishlists', $this->handleCreate(...));
        $this->router->get('/wishlists/{id}', $this->handleGet(...));
        $this->router->put('/wishlists/{id}', $this->handleUpdate(...));
        $this->router->delete('/wishlists/{id}', $this->handleDelete(...));
        $this->router->post('/wishlists/{id}/items', $this->handleAddItem(...));
        $this->router->delete('/wishlists/{id}/items/{productId}', $this->handleRemoveItem(...));
    }

    private function requireUserId(ServerRequestInterface $request): ?int
    {
        $val = $request->getHeaderLine('X-User-Id');
        return $val !== '' ? (int) $val : null;
    }

    private function routeParam(ServerRequestInterface $request, string $key): string
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        return (string) ($params[$key] ?? '');
    }

    private function handleCreate(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireUserId($request);
        if ($actorId === null) {
            return $this->responseFactory->create(['error' => 'authentication required'], 401);
        }

        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            $body = [];
        }

        $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
        if ($name === '') {
            return $this->responseFactory->create(['error' => 'name is required'], 422);
        }

        $isPublic = isset($body['is_public']) && $body['is_public'] === true;
        $now = date('c');
        $id = $this->repository->create($actorId, $name, $isPublic, $now);

        return $this->responseFactory->create([
            'id' => $id,
            'user_id' => $actorId,
            'name' => $name,
            'is_public' => $isPublic,
            'item_count' => 0,
            'items' => [],
            'created_at' => $now,
            'updated_at' => $now,
        ], 201);
    }

    private function handleGet(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireUserId($request);
        $id = (int) $this->routeParam($request, 'id');

        $wishlist = $this->repository->findById($id);
        if ($wishlist === null) {
            return $this->responseFactory->create(['error' => 'wishlist not found'], 404);
        }

        $isOwner = $actorId !== null && (int) $wishlist['user_id'] === $actorId;
        $isPublic = (bool) $wishlist['is_public'];

        if (!$isOwner && !$isPublic) {
            return $this->responseFactory->create(['error' => 'wishlist not found'], 404);
        }

        $items = $this->repository->listItems($id);
        return $this->responseFactory->create($this->formatWishlist($wishlist, $items));
    }

    private function handleUpdate(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireUserId($request);
        if ($actorId === null) {
            return $this->responseFactory->create(['error' => 'authentication required'], 401);
        }

        $id = (int) $this->routeParam($request, 'id');
        $wishlist = $this->repository->findById($id);
        if ($wishlist === null) {
            return $this->responseFactory->create(['error' => 'wishlist not found'], 404);
        }
        if ((int) $wishlist['user_id'] !== $actorId) {
            return $this->responseFactory->create(['error' => 'access denied'], 403);
        }

        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            $body = [];
        }

        $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : (string) $wishlist['name'];
        if ($name === '') {
            return $this->responseFactory->create(['error' => 'name cannot be empty'], 422);
        }
        $isPublic = array_key_exists('is_public', $body) ? (bool) $body['is_public'] : (bool) $wishlist['is_public'];
        $now = date('c');

        $this->repository->update($id, $name, $isPublic, $now);
        $items = $this->repository->listItems($id);
        $updated = array_merge($wishlist, ['name' => $name, 'is_public' => $isPublic ? 1 : 0, 'updated_at' => $now]);
        return $this->responseFactory->create($this->formatWishlist($updated, $items));
    }

    private function handleDelete(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireUserId($request);
        if ($actorId === null) {
            return $this->responseFactory->create(['error' => 'authentication required'], 401);
        }

        $id = (int) $this->routeParam($request, 'id');
        $wishlist = $this->repository->findById($id);
        if ($wishlist === null) {
            return $this->responseFactory->create(['error' => 'wishlist not found'], 404);
        }
        if ((int) $wishlist['user_id'] !== $actorId) {
            return $this->responseFactory->create(['error' => 'access denied'], 403);
        }

        $this->repository->delete($id);
        return $this->responseFactory->create(['message' => 'wishlist deleted']);
    }

    private function handleAddItem(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireUserId($request);
        if ($actorId === null) {
            return $this->responseFactory->create(['error' => 'authentication required'], 401);
        }

        $id = (int) $this->routeParam($request, 'id');
        $wishlist = $this->repository->findById($id);
        if ($wishlist === null) {
            return $this->responseFactory->create(['error' => 'wishlist not found'], 404);
        }
        if ((int) $wishlist['user_id'] !== $actorId) {
            return $this->responseFactory->create(['error' => 'access denied'], 403);
        }

        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            $body = [];
        }

        $productId = isset($body['product_id']) && is_int($body['product_id']) ? $body['product_id'] : null;
        if ($productId === null) {
            return $this->responseFactory->create(['error' => 'product_id is required'], 422);
        }

        $product = $this->repository->findProductById($productId);
        if ($product === null) {
            return $this->responseFactory->create(['error' => 'product not found'], 404);
        }

        $priority = isset($body['priority']) && is_string($body['priority']) && in_array($body['priority'], self::VALID_PRIORITIES, true)
            ? $body['priority']
            : 'medium';
        $note = isset($body['note']) && is_string($body['note']) && $body['note'] !== '' ? $body['note'] : null;

        $existing = $this->repository->findItem($id, $productId);
        if ($existing !== null) {
            return $this->responseFactory->create([
                'message' => 'already in wishlist',
                'product_id' => $productId,
                'priority' => $existing['priority'],
                'note' => $existing['note'],
            ], 200);
        }

        $now = date('c');
        $this->repository->addItem($id, $productId, $priority, $note, $now);
        return $this->responseFactory->create([
            'message' => 'product added',
            'product_id' => $productId,
            'priority' => $priority,
            'note' => $note,
        ], 201);
    }

    private function handleRemoveItem(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireUserId($request);
        if ($actorId === null) {
            return $this->responseFactory->create(['error' => 'authentication required'], 401);
        }

        $id = (int) $this->routeParam($request, 'id');
        $productId = (int) $this->routeParam($request, 'productId');

        $wishlist = $this->repository->findById($id);
        if ($wishlist === null) {
            return $this->responseFactory->create(['error' => 'wishlist not found'], 404);
        }
        if ((int) $wishlist['user_id'] !== $actorId) {
            return $this->responseFactory->create(['error' => 'access denied'], 403);
        }

        $item = $this->repository->findItem($id, $productId);
        if ($item === null) {
            return $this->responseFactory->create(['error' => 'item not in wishlist'], 404);
        }

        $this->repository->removeItem($id, $productId);
        return $this->responseFactory->create(['message' => 'item removed', 'product_id' => $productId]);
    }

    /**
     * @param array<string, mixed> $wishlist
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function formatWishlist(array $wishlist, array $items): array
    {
        return [
            'id' => (int) $wishlist['id'],
            'user_id' => (int) $wishlist['user_id'],
            'name' => $wishlist['name'],
            'is_public' => (bool) $wishlist['is_public'],
            'item_count' => count($items),
            'items' => array_map(fn (array $item) => [
                'product_id' => (int) $item['product_id'],
                'product_name' => $item['product_name'],
                'priority' => $item['priority'],
                'note' => $item['note'],
                'added_at' => $item['added_at'],
            ], $items),
            'created_at' => $wishlist['created_at'],
            'updated_at' => $wishlist['updated_at'],
        ];
    }
}
