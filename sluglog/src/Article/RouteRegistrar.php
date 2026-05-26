<?php

declare(strict_types=1);

namespace Sluglog\Article;

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
        $this->router->get('/articles/by-slug/{slug}', $this->handleGetBySlug(...));
        $this->router->get('/articles/{id}', $this->handleGetById(...));
        $this->router->put('/articles/{id}', $this->handleUpdate(...));
        $this->router->get('/articles/{id}/slug-history', $this->handleSlugHistory(...));
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

    // ── GET /articles/by-slug/{slug} ──────────────────────────────────────
    // Checks current slug first; falls back to slug history and returns redirect hint.
    private function handleGetBySlug(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $slug   = (string) ($params['slug'] ?? '');

        $result = $this->repository->findBySlugWithRedirect($slug);

        if ($result === null) {
            return $this->responseFactory->create(['error' => "Slug '{$slug}' not found."], 404);
        }

        if ($result['redirect']) {
            // Signal to the caller that they should use the canonical slug
            return $this->responseFactory->create([
                'redirect'      => true,
                'canonical_slug' => $result['found']->slug,
                'data'          => $result['found']->toArray(),
            ], 301);
        }

        return $this->responseFactory->create($result['found']->toArray());
    }

    // ── GET /articles/{id} ────────────────────────────────────────────────
    private function handleGetById(ServerRequestInterface $request): ResponseInterface
    {
        $id      = $this->intRouteParam($request, 'id');
        $article = $this->repository->findById($id);

        if ($article === null) {
            return $this->responseFactory->create(['error' => "Article #{$id} not found."], 404);
        }

        return $this->responseFactory->create($article->toArray());
    }

    // ── PUT /articles/{id} ────────────────────────────────────────────────
    private function handleUpdate(ServerRequestInterface $request): ResponseInterface
    {
        $id   = $this->intRouteParam($request, 'id');
        $body = $this->parseBody($request);

        $title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';
        $text  = isset($body['body']) && is_string($body['body']) ? trim($body['body']) : '';

        if ($title === '') {
            return $this->responseFactory->create(['error' => 'title is required'], 422);
        }

        if ($text === '') {
            return $this->responseFactory->create(['error' => 'body is required'], 422);
        }

        // Optional explicit slug override
        $explicitSlug = isset($body['slug']) && is_string($body['slug']) && $body['slug'] !== ''
            ? $body['slug']
            : null;

        try {
            $article = $this->repository->update($id, $title, $text, $explicitSlug, date('c'));
        } catch (ArticleNotFoundException $e) {
            return $this->responseFactory->create(['error' => $e->getMessage()], 404);
        }

        return $this->responseFactory->create($article->toArray());
    }

    // ── GET /articles/{id}/slug-history ───────────────────────────────────
    private function handleSlugHistory(ServerRequestInterface $request): ResponseInterface
    {
        $id      = $this->intRouteParam($request, 'id');
        $article = $this->repository->findById($id);

        if ($article === null) {
            return $this->responseFactory->create(['error' => "Article #{$id} not found."], 404);
        }

        $history = $this->repository->slugHistory($id);

        return $this->responseFactory->create([
            'article_id'     => $id,
            'current_slug'   => $article->slug,
            'slug_history'   => $history,
        ]);
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
