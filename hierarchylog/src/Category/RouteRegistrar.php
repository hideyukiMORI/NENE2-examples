<?php

declare(strict_types=1);

namespace Hierarchylog\Category;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly Router $router,
        private readonly CategoryRepository $repository,
        private readonly JsonResponseFactory $responseFactory,
    ) {
    }

    public function register(): void
    {
        $this->router->get('/categories', $this->handleList(...));
        $this->router->post('/categories', $this->handleCreate(...));
        $this->router->get('/categories/{id}', $this->handleGet(...));
        $this->router->get('/categories/{id}/subtree', $this->handleSubtree(...));
        $this->router->put('/categories/{id}', $this->handleUpdate(...));
        $this->router->patch('/categories/{id}/move', $this->handleMove(...));
        $this->router->delete('/categories/{id}', $this->handleDelete(...));
    }

    // ── GET /categories?parent_id={id} ───────────────────────────────────
    private function handleList(ServerRequestInterface $request): ResponseInterface
    {
        $params   = $request->getQueryParams();
        $parentId = isset($params['parent_id']) && $params['parent_id'] !== ''
            ? (int) $params['parent_id']
            : null;

        $items = $this->repository->listByParent($parentId);

        return $this->responseFactory->create([
            'data'      => array_map(static fn (Category $c): array => $c->toArray(), $items),
            'parent_id' => $parentId,
        ]);
    }

    // ── POST /categories ─────────────────────────────────────────────────
    private function handleCreate(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->parseBody($request);

        $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
        if ($name === '') {
            return $this->responseFactory->create(
                ['error' => 'name is required'],
                422,
            );
        }

        $parentId = isset($body['parent_id']) && is_int($body['parent_id'])
            ? $body['parent_id']
            : null;

        try {
            $category = $this->repository->create($name, $parentId, date('c'));
        } catch (CategoryNotFoundException $e) {
            return $this->responseFactory->create(['error' => $e->getMessage()], 404);
        } catch (CategoryDepthException $e) {
            return $this->responseFactory->create(['error' => $e->getMessage()], 422);
        }

        return $this->responseFactory->create($category->toArray(), 201);
    }

    // ── GET /categories/{id} ─────────────────────────────────────────────
    private function handleGet(ServerRequestInterface $request): ResponseInterface
    {
        $id       = $this->intRouteParam($request, 'id');
        $category = $this->repository->findById($id);

        if ($category === null) {
            return $this->responseFactory->create(['error' => "Category #{$id} not found."], 404);
        }

        $ancestors = $this->repository->ancestors($id);

        return $this->responseFactory->create([
            'data'      => $category->toArray(),
            'ancestors' => array_map(static fn (Category $c): array => $c->toArray(), $ancestors),
        ]);
    }

    // ── GET /categories/{id}/subtree ─────────────────────────────────────
    private function handleSubtree(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->intRouteParam($request, 'id');

        try {
            $descendants = $this->repository->subtree($id);
        } catch (CategoryNotFoundException $e) {
            return $this->responseFactory->create(['error' => $e->getMessage()], 404);
        }

        return $this->responseFactory->create([
            'root_id' => $id,
            'data'    => array_map(static fn (Category $c): array => $c->toArray(), $descendants),
        ]);
    }

    // ── PUT /categories/{id} ─────────────────────────────────────────────
    private function handleUpdate(ServerRequestInterface $request): ResponseInterface
    {
        $id   = $this->intRouteParam($request, 'id');
        $body = $this->parseBody($request);

        $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
        if ($name === '') {
            return $this->responseFactory->create(['error' => 'name is required'], 422);
        }

        $updated = $this->repository->updateName($id, $name);
        if (!$updated) {
            return $this->responseFactory->create(['error' => "Category #{$id} not found."], 404);
        }

        $category = $this->repository->findById($id);
        assert($category !== null);

        return $this->responseFactory->create($category->toArray());
    }

    // ── PATCH /categories/{id}/move ──────────────────────────────────────
    private function handleMove(ServerRequestInterface $request): ResponseInterface
    {
        $id   = $this->intRouteParam($request, 'id');
        $body = $this->parseBody($request);

        // parent_id may be null (move to root) or an integer
        $newParentId = array_key_exists('parent_id', $body)
            ? ($body['parent_id'] === null ? null : (int) $body['parent_id'])
            : -1; // sentinel: field missing

        if ($newParentId === -1) {
            return $this->responseFactory->create(['error' => 'parent_id field is required (use null for root)'], 422);
        }

        try {
            $category = $this->repository->move($id, $newParentId, date('c'));
        } catch (CategoryNotFoundException $e) {
            return $this->responseFactory->create(['error' => $e->getMessage()], 404);
        } catch (CategoryCircularException $e) {
            return $this->responseFactory->create(['error' => $e->getMessage()], 422);
        } catch (CategoryDepthException $e) {
            return $this->responseFactory->create(['error' => $e->getMessage()], 422);
        }

        return $this->responseFactory->create($category->toArray());
    }

    // ── DELETE /categories/{id} ──────────────────────────────────────────
    private function handleDelete(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->intRouteParam($request, 'id');

        try {
            $deleted = $this->repository->delete($id);
        } catch (CategoryHasChildrenException $e) {
            return $this->responseFactory->create(['error' => $e->getMessage()], 409);
        }

        if (!$deleted) {
            return $this->responseFactory->create(['error' => "Category #{$id} not found."], 404);
        }

        return $this->responseFactory->create(['deleted' => true]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

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
}
