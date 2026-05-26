<?php

declare(strict_types=1);

namespace EtagLog\EtagLog;

use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RouteRegistrar
{
    public function __construct(
        private SqliteArticleRepository $repo,
        private JsonResponseFactory     $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/articles', $this->createArticle(...));
        $router->get('/articles', $this->listArticles(...));
        $router->get('/articles/{id}', $this->getArticle(...));
        $router->put('/articles/{id}', $this->updateArticle(...));
    }

    private function createArticle(ServerRequestInterface $request): ResponseInterface
    {
        $body  = JsonRequestBodyParser::parse($request);
        $title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';

        if ($title === '') {
            return $this->json->create(['error' => 'title is required'], 422);
        }

        $content = isset($body['content']) && is_string($body['content']) ? $body['content'] : '';
        $now     = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
        $article = $this->repo->create($title, $content, $now);

        return $this->json->create($article->toArray(), 201)
            ->withHeader('ETag', $article->etag())
            ->withHeader('Last-Modified', $article->updatedAt);
    }

    private function listArticles(ServerRequestInterface $request): ResponseInterface
    {
        $articles = $this->repo->findAll();

        return $this->json->create([
            'total' => count($articles),
            'items' => array_map(static fn (Article $a): array => $a->toArray(), $articles),
        ]);
    }

    private function getArticle(ServerRequestInterface $request): ResponseInterface
    {
        $params  = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id      = (int) ($params['id'] ?? 0);
        $article = $this->repo->findById($id);

        if ($article === null) {
            return $this->json->create(['error' => 'not found'], 404);
        }

        $etag = $article->etag();

        // ETag conditional GET
        $ifNoneMatch = $request->getHeaderLine('If-None-Match');
        if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
            return $this->json->create([], 304)
                ->withHeader('ETag', $etag)
                ->withHeader('Last-Modified', $article->updatedAt);
        }

        // Last-Modified conditional GET
        $ifModifiedSince = $request->getHeaderLine('If-Modified-Since');
        if ($ifModifiedSince !== '' && $ifModifiedSince >= $article->updatedAt) {
            return $this->json->create([], 304)
                ->withHeader('ETag', $etag)
                ->withHeader('Last-Modified', $article->updatedAt);
        }

        return $this->json->create($article->toArray())
            ->withHeader('ETag', $etag)
            ->withHeader('Last-Modified', $article->updatedAt);
    }

    private function updateArticle(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $id     = (int) ($params['id'] ?? 0);

        $body  = JsonRequestBodyParser::parse($request);
        $title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';

        if ($title === '') {
            return $this->json->create(['error' => 'title is required'], 422);
        }

        $content = isset($body['content']) && is_string($body['content']) ? $body['content'] : '';
        $now     = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
        $article = $this->repo->update($id, $title, $content, $now);

        if ($article === null) {
            return $this->json->create(['error' => 'not found'], 404);
        }

        return $this->json->create($article->toArray())
            ->withHeader('ETag', $article->etag())
            ->withHeader('Last-Modified', $article->updatedAt);
    }
}
