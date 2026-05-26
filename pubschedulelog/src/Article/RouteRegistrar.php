<?php

declare(strict_types=1);

namespace Pubschedulelog\Article;

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
        $this->router->get('/articles', $this->handleList(...));
        $this->router->get('/articles/{id}', $this->handleGet(...));
        $this->router->put('/articles/{id}', $this->handleUpdate(...));
        $this->router->post('/articles/{id}/schedule', $this->handleSchedule(...));
        $this->router->post('/articles/{id}/unschedule', $this->handleUnschedule(...));
        $this->router->post('/articles/{id}/publish', $this->handlePublish(...));
        $this->router->post('/articles/{id}/archive', $this->handleArchive(...));
        $this->router->post('/articles/publish-due', $this->handlePublishDue(...));
    }

    // ── POST /articles ────────────────────────────────────────────────────
    private function handleCreate(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireActorId($request);
        if ($actorId === null) {
            return $this->responseFactory->create(['error' => 'X-User-Id header required'], 401);
        }

        $body  = $this->parseBody($request);
        $title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';
        $text  = isset($body['body']) && is_string($body['body']) ? trim($body['body']) : '';

        if ($title === '') {
            return $this->responseFactory->create(['error' => 'title is required'], 422);
        }

        if ($text === '') {
            return $this->responseFactory->create(['error' => 'body is required'], 422);
        }

        $article = $this->repository->create($actorId, $title, $text, date('c'));

        return $this->responseFactory->create($article->toArray(), 201);
    }

    // ── GET /articles?status=published&author_id=N ────────────────────────
    private function handleList(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireActorId($request);
        $params  = $request->getQueryParams();

        $statusParam = isset($params['status']) && is_string($params['status'])
            ? ArticleStatus::tryFrom($params['status'])
            : null;

        // author_id filter: only own articles for non-published queries
        $authorFilter = null;
        if ($statusParam !== ArticleStatus::Published) {
            // Non-published list: must be authenticated, only own articles
            if ($actorId === null) {
                return $this->responseFactory->create(['error' => 'X-User-Id header required for non-published listing'], 401);
            }

            $authorFilter = $actorId;
        }

        $articles = $this->repository->list($statusParam, $authorFilter);

        return $this->responseFactory->create([
            'data'   => array_map(static fn (Article $a): array => $a->toArray(), $articles),
            'status' => $statusParam?->value,
        ]);
    }

    // ── GET /articles/{id} ────────────────────────────────────────────────
    private function handleGet(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireActorId($request) ?? 0;
        $id      = $this->intRouteParam($request, 'id');
        $article = $this->repository->findById($id);

        if ($article === null || !$article->isVisibleTo($actorId)) {
            return $this->responseFactory->create(['error' => "Article #{$id} not found."], 404);
        }

        return $this->responseFactory->create($article->toArray());
    }

    // ── PUT /articles/{id} ────────────────────────────────────────────────
    private function handleUpdate(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireActorId($request);
        if ($actorId === null) {
            return $this->responseFactory->create(['error' => 'X-User-Id header required'], 401);
        }

        $id      = $this->intRouteParam($request, 'id');
        $article = $this->repository->findById($id);

        if ($article === null || $article->authorId !== $actorId) {
            return $this->responseFactory->create(['error' => "Article #{$id} not found."], 404);
        }

        if (!in_array($article->status, [ArticleStatus::Draft, ArticleStatus::Scheduled], true)) {
            return $this->responseFactory->create(
                ['error' => "Cannot edit an article with status '{$article->status->value}'."],
                422,
            );
        }

        $body  = $this->parseBody($request);
        $title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';
        $text  = isset($body['body']) && is_string($body['body']) ? trim($body['body']) : '';

        if ($title === '') {
            return $this->responseFactory->create(['error' => 'title is required'], 422);
        }

        if ($text === '') {
            return $this->responseFactory->create(['error' => 'body is required'], 422);
        }

        $this->repository->update($id, $title, $text, date('c'));
        $updated = $this->repository->findById($id);
        assert($updated !== null);

        return $this->responseFactory->create($updated->toArray());
    }

    // ── POST /articles/{id}/schedule ─────────────────────────────────────
    private function handleSchedule(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireActorId($request);
        if ($actorId === null) {
            return $this->responseFactory->create(['error' => 'X-User-Id header required'], 401);
        }

        $id   = $this->intRouteParam($request, 'id');
        $body = $this->parseBody($request);

        $publishAt = isset($body['publish_at']) && is_string($body['publish_at'])
            ? trim($body['publish_at'])
            : '';

        if ($publishAt === '') {
            return $this->responseFactory->create(['error' => 'publish_at is required'], 422);
        }

        try {
            $article = $this->repository->schedule($id, $actorId, $publishAt, date('c'));
        } catch (ArticleNotFoundException $e) {
            return $this->responseFactory->create(['error' => $e->getMessage()], 404);
        } catch (ArticleScheduleException $e) {
            return $this->responseFactory->create(['error' => $e->getMessage()], 422);
        } catch (ArticleTransitionException $e) {
            return $this->responseFactory->create(['error' => $e->getMessage()], 409);
        }

        return $this->responseFactory->create($article->toArray());
    }

    // ── POST /articles/{id}/unschedule ───────────────────────────────────
    private function handleUnschedule(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireActorId($request);
        if ($actorId === null) {
            return $this->responseFactory->create(['error' => 'X-User-Id header required'], 401);
        }

        $id = $this->intRouteParam($request, 'id');

        try {
            $article = $this->repository->unschedule($id, $actorId, date('c'));
        } catch (ArticleNotFoundException $e) {
            return $this->responseFactory->create(['error' => $e->getMessage()], 404);
        } catch (ArticleTransitionException $e) {
            return $this->responseFactory->create(['error' => $e->getMessage()], 409);
        }

        return $this->responseFactory->create($article->toArray());
    }

    // ── POST /articles/{id}/publish ──────────────────────────────────────
    private function handlePublish(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireActorId($request);
        if ($actorId === null) {
            return $this->responseFactory->create(['error' => 'X-User-Id header required'], 401);
        }

        $id = $this->intRouteParam($request, 'id');

        try {
            $article = $this->repository->publish($id, $actorId, date('c'));
        } catch (ArticleNotFoundException $e) {
            return $this->responseFactory->create(['error' => $e->getMessage()], 404);
        } catch (ArticleTransitionException $e) {
            return $this->responseFactory->create(['error' => $e->getMessage()], 409);
        }

        return $this->responseFactory->create($article->toArray());
    }

    // ── POST /articles/{id}/archive ──────────────────────────────────────
    private function handleArchive(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = $this->requireActorId($request);
        if ($actorId === null) {
            return $this->responseFactory->create(['error' => 'X-User-Id header required'], 401);
        }

        $id = $this->intRouteParam($request, 'id');

        try {
            $article = $this->repository->archive($id, $actorId, date('c'));
        } catch (ArticleNotFoundException $e) {
            return $this->responseFactory->create(['error' => $e->getMessage()], 404);
        } catch (ArticleTransitionException $e) {
            return $this->responseFactory->create(['error' => $e->getMessage()], 409);
        }

        return $this->responseFactory->create($article->toArray());
    }

    // ── POST /articles/publish-due (machine/admin) ───────────────────────
    private function handlePublishDue(ServerRequestInterface $request): ResponseInterface
    {
        // Machine endpoint: require API key header (hash_equals prevents timing attacks)
        $apiKey   = $request->getHeaderLine('X-Admin-Key');
        $expected = 'admin-secret';
        if ($apiKey === '' || !hash_equals($expected, $apiKey)) {
            return $this->responseFactory->create(['error' => 'unauthorized'], 401);
        }

        $now       = date('c');
        $published = $this->repository->publishDue($now);

        return $this->responseFactory->create([
            'published_count' => count($published),
            'published_ids'   => $published,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function requireActorId(ServerRequestInterface $request): ?int
    {
        $val = $request->getHeaderLine('X-User-Id');

        return $val !== '' ? (int) $val : null;
    }

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
