<?php

declare(strict_types=1);

namespace CollectionLog\Collection;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    public function __construct(
        private readonly Router $router,
        private readonly CollectionRepository $repository,
        private readonly JsonResponseFactory $responseFactory,
    ) {
    }

    public function register(): void
    {
        $this->router->post('/collections', $this->handleCreate(...));
        $this->router->get('/collections/{id}', $this->handleGet(...));
        $this->router->put('/collections/{id}', $this->handleUpdate(...));
        $this->router->delete('/collections/{id}', $this->handleDelete(...));
        $this->router->post('/collections/{id}/items', $this->handleAddItem(...));
        $this->router->delete('/collections/{id}/items/{articleId}', $this->handleRemoveItem(...));
    }

    private function requireUserId(ServerRequestInterface $request): int
    {
        return (int) $request->getHeaderLine('X-User-Id');
    }

    private function handleCreate(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireUserId($request);
        if ($actorId <= 0) {
            return $this->responseFactory->create(['error' => 'X-User-Id required'], 401);
        }

        $actor = $this->repository->findUserById($actorId);
        if ($actor === null) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $body = json_decode((string) $request->getBody(), true);
        $body = is_array($body) ? $body : [];

        $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
        if ($name === '') {
            return $this->responseFactory->create(['error' => 'name is required'], 422);
        }
        if (strlen($name) > 100) {
            return $this->responseFactory->create(['error' => 'name too long (max 100)'], 422);
        }

        $isPublic = isset($body['is_public']) && $body['is_public'] === true;

        $now = date('c');
        $id = $this->repository->createCollection($actorId, $name, $isPublic, $now);
        $collection = $this->repository->findCollectionById($id);

        return $this->responseFactory->create($this->formatCollection($collection ?? [], []), 201);
    }

    private function handleGet(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireUserId($request);
        $id = (int) Router::param($request, 'id');

        $collection = $this->repository->findCollectionById($id);

        // Private collections: return 404 to non-owners (prevent existence disclosure)
        if ($collection === null) {
            return $this->responseFactory->create(['error' => 'collection not found'], 404);
        }

        $isOwner = (int) $collection['user_id'] === $actorId;
        $isPublic = (bool) $collection['is_public'];

        if (!$isOwner && !$isPublic) {
            return $this->responseFactory->create(['error' => 'collection not found'], 404);
        }

        $items = $this->repository->listItems($id);
        return $this->responseFactory->create($this->formatCollection($collection, $items), 200);
    }

    private function handleUpdate(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireUserId($request);
        if ($actorId <= 0) {
            return $this->responseFactory->create(['error' => 'X-User-Id required'], 401);
        }

        $id = (int) Router::param($request, 'id');
        $collection = $this->repository->findCollectionById($id);

        if ($collection === null) {
            return $this->responseFactory->create(['error' => 'collection not found'], 404);
        }

        if ((int) $collection['user_id'] !== $actorId) {
            return $this->responseFactory->create(['error' => 'access denied'], 403);
        }

        $body = json_decode((string) $request->getBody(), true);
        $body = is_array($body) ? $body : [];

        $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : (string) $collection['name'];
        if ($name === '') {
            return $this->responseFactory->create(['error' => 'name cannot be empty'], 422);
        }
        if (strlen($name) > 100) {
            return $this->responseFactory->create(['error' => 'name too long (max 100)'], 422);
        }

        $isPublic = array_key_exists('is_public', $body) ? (bool) $body['is_public'] : (bool) $collection['is_public'];

        $this->repository->updateCollection($id, $name, $isPublic, date('c'));
        $updated = $this->repository->findCollectionById($id);
        $items = $this->repository->listItems($id);

        return $this->responseFactory->create($this->formatCollection($updated ?? [], $items), 200);
    }

    private function handleDelete(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireUserId($request);
        if ($actorId <= 0) {
            return $this->responseFactory->create(['error' => 'X-User-Id required'], 401);
        }

        $id = (int) Router::param($request, 'id');
        $collection = $this->repository->findCollectionById($id);

        if ($collection === null) {
            return $this->responseFactory->create(['error' => 'collection not found'], 404);
        }

        if ((int) $collection['user_id'] !== $actorId) {
            return $this->responseFactory->create(['error' => 'access denied'], 403);
        }

        $this->repository->deleteCollection($id);
        return $this->responseFactory->create(['message' => 'collection deleted'], 200);
    }

    private function handleAddItem(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireUserId($request);
        if ($actorId <= 0) {
            return $this->responseFactory->create(['error' => 'X-User-Id required'], 401);
        }

        $id = (int) Router::param($request, 'id');
        $collection = $this->repository->findCollectionById($id);

        if ($collection === null) {
            return $this->responseFactory->create(['error' => 'collection not found'], 404);
        }

        if ((int) $collection['user_id'] !== $actorId) {
            return $this->responseFactory->create(['error' => 'access denied'], 403);
        }

        $body = json_decode((string) $request->getBody(), true);
        $body = is_array($body) ? $body : [];

        $articleId = isset($body['article_id']) && is_int($body['article_id']) ? $body['article_id'] : null;
        if ($articleId === null) {
            return $this->responseFactory->create(['error' => 'article_id required'], 422);
        }

        $article = $this->repository->findArticleById($articleId);
        if ($article === null) {
            return $this->responseFactory->create(['error' => 'article not found'], 404);
        }

        $existing = $this->repository->findItem($id, $articleId);
        if ($existing !== null) {
            return $this->responseFactory->create(['message' => 'already in collection', 'article_id' => $articleId], 200);
        }

        $count = $this->repository->countItems($id);
        if ($count >= CollectionRepository::maxItems()) {
            return $this->responseFactory->create([
                'error' => 'collection is full',
                'max' => CollectionRepository::maxItems(),
            ], 422);
        }

        $this->repository->addItem($id, $articleId, date('c'));

        return $this->responseFactory->create(['message' => 'article added', 'article_id' => $articleId], 201);
    }

    private function handleRemoveItem(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireUserId($request);
        if ($actorId <= 0) {
            return $this->responseFactory->create(['error' => 'X-User-Id required'], 401);
        }

        $id = (int) Router::param($request, 'id');
        $articleId = (int) Router::param($request, 'articleId');

        $collection = $this->repository->findCollectionById($id);

        if ($collection === null) {
            return $this->responseFactory->create(['error' => 'collection not found'], 404);
        }

        if ((int) $collection['user_id'] !== $actorId) {
            return $this->responseFactory->create(['error' => 'access denied'], 403);
        }

        $item = $this->repository->findItem($id, $articleId);
        if ($item === null) {
            return $this->responseFactory->create(['error' => 'article not in collection'], 404);
        }

        $this->repository->removeItem($id, $articleId);
        return $this->responseFactory->create(['message' => 'article removed'], 200);
    }

    /**
     * @param array<string, mixed> $collection
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function formatCollection(array $collection, array $items): array
    {
        $formattedItems = array_map(fn (array $item) => [
            'article_id' => (int) $item['article_id'],
            'title' => (string) ($item['article_title'] ?? ''),
            'position' => (int) $item['position'],
            'added_at' => (string) $item['added_at'],
        ], $items);

        return [
            'id' => (int) ($collection['id'] ?? 0),
            'user_id' => (int) ($collection['user_id'] ?? 0),
            'name' => (string) ($collection['name'] ?? ''),
            'is_public' => (bool) ($collection['is_public'] ?? false),
            'item_count' => count($formattedItems),
            'items' => $formattedItems,
            'created_at' => (string) ($collection['created_at'] ?? ''),
            'updated_at' => (string) ($collection['updated_at'] ?? ''),
        ];
    }
}
