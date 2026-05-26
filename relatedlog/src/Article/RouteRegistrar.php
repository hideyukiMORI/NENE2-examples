<?php

declare(strict_types=1);

namespace Relatedlog\Article;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly Router $router,
        private readonly ArticleRepository $repository,
        private readonly JsonResponseFactory $responseFactory,
    ) {
    }

    public function register(): void
    {
        $this->router->post('/articles', $this->handleCreate(...));
        $this->router->get('/articles/{id}', $this->handleGet(...));
        $this->router->post('/articles/{id}/relations', $this->handleAddRelation(...));
        $this->router->get('/articles/{id}/relations', $this->handleListRelations(...));
        $this->router->delete('/articles/{id}/relations/{relatedId}', $this->handleRemoveRelation(...));
    }

    // ── POST /articles ────────────────────────────────────────────────────
    private function handleCreate(ServerRequestInterface $request): ResponseInterface
    {
        $body  = $this->parseBody($request);
        $title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';
        $text  = isset($body['body']) && is_string($body['body']) ? trim($body['body']) : '';

        if ($title === '') {
            return $this->responseFactory->create(['error' => 'title is required'], 422);
        }

        if ($text === '') {
            return $this->responseFactory->create(['error' => 'body is required'], 422);
        }

        $article = $this->repository->create($title, $text, date('c'));

        return $this->responseFactory->create($article->toArray(), 201);
    }

    // ── GET /articles/{id} ────────────────────────────────────────────────
    private function handleGet(ServerRequestInterface $request): ResponseInterface
    {
        $id     = $this->intRouteParam($request, 'id');
        $result = $this->repository->findWithRelations($id);

        if ($result === null) {
            return $this->responseFactory->create(['error' => "Article #{$id} not found."], 404);
        }

        return $this->responseFactory->create([
            'data'      => $result['article']->toArray(),
            'relations' => array_map(
                static fn (array $r): array => [
                    'relation' => $r['relation']->toArray(),
                    'related'  => $r['related']->toArray(),
                ],
                $result['relations'],
            ),
        ]);
    }

    // ── POST /articles/{id}/relations ─────────────────────────────────────
    private function handleAddRelation(ServerRequestInterface $request): ResponseInterface
    {
        $id   = $this->intRouteParam($request, 'id');
        $body = $this->parseBody($request);

        $relatedId    = isset($body['related_id']) && is_int($body['related_id']) ? $body['related_id'] : 0;
        $typeRaw      = isset($body['relation_type']) && is_string($body['relation_type'])
            ? $body['relation_type']
            : '';
        $relationType = RelationType::tryFrom($typeRaw);

        if ($relatedId <= 0) {
            return $this->responseFactory->create(['error' => 'related_id must be a positive integer'], 422);
        }

        if ($relationType === null) {
            $valid = implode(', ', array_map(static fn (RelationType $t): string => $t->value, RelationType::cases()));

            return $this->responseFactory->create(['error' => "relation_type must be one of: {$valid}"], 422);
        }

        if ($id === $relatedId) {
            return $this->responseFactory->create(['error' => 'An article cannot be related to itself'], 422);
        }

        try {
            $relation = $this->repository->addRelation($id, $relatedId, $relationType, date('c'));
        } catch (ArticleNotFoundException $e) {
            return $this->responseFactory->create(['error' => $e->getMessage()], 404);
        } catch (RelationAlreadyExistsException $e) {
            return $this->responseFactory->create(['error' => $e->getMessage()], 409);
        }

        return $this->responseFactory->create($relation->toArray(), 201);
    }

    // ── GET /articles/{id}/relations?type=sequel ──────────────────────────
    private function handleListRelations(ServerRequestInterface $request): ResponseInterface
    {
        $id       = $this->intRouteParam($request, 'id');
        $params   = $request->getQueryParams();
        $typeRaw  = isset($params['type']) && is_string($params['type']) ? $params['type'] : '';
        $type     = $typeRaw !== '' ? RelationType::tryFrom($typeRaw) : null;

        if ($typeRaw !== '' && $type === null) {
            $valid = implode(', ', array_map(static fn (RelationType $t): string => $t->value, RelationType::cases()));

            return $this->responseFactory->create(['error' => "relation_type must be one of: {$valid}"], 422);
        }

        if ($this->repository->findById($id) === null) {
            return $this->responseFactory->create(['error' => "Article #{$id} not found."], 404);
        }

        $relations = $this->repository->listRelations($id, $type);

        return $this->responseFactory->create([
            'article_id' => $id,
            'type_filter' => $type?->value,
            'data'       => array_map(static fn (ArticleRelation $r): array => $r->toArray(), $relations),
        ]);
    }

    // ── DELETE /articles/{id}/relations/{relatedId}?type=related ─────────
    private function handleRemoveRelation(ServerRequestInterface $request): ResponseInterface
    {
        $id        = $this->intRouteParam($request, 'id');
        $relatedId = $this->intRouteParam($request, 'relatedId');
        $params    = $request->getQueryParams();
        $typeRaw   = isset($params['type']) && is_string($params['type']) ? $params['type'] : '';
        $type      = RelationType::tryFrom($typeRaw);

        if ($type === null) {
            $valid = implode(', ', array_map(static fn (RelationType $t): string => $t->value, RelationType::cases()));

            return $this->responseFactory->create(['error' => "type query param required; must be one of: {$valid}"], 422);
        }

        $deleted = $this->repository->removeRelation($id, $relatedId, $type);

        if (!$deleted) {
            return $this->responseFactory->create(['error' => 'Relation not found.'], 404);
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
