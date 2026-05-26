<?php

declare(strict_types=1);

namespace Etag\Article;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\ConditionalGetHelper;
use Nene2\Http\ConditionalWriteHelper;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly ArticleRepository $repo,
        private readonly JsonResponseFactory $json,
        private readonly ProblemDetailsResponseFactory $problems,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/articles', $this->create(...));
        $router->get('/articles/{id}', $this->get(...));
        $router->patch('/articles/{id}', $this->update(...));
    }

    private function create(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        if (
            !is_array($body) ||
            !isset($body['title'], $body['body']) ||
            !is_string($body['title']) ||
            !is_string($body['body'])
        ) {
            return $this->problems->create($request, 'invalid-body', 'title and body (string) are required.', 400);
        }

        $article = $this->repo->create(trim($body['title']), trim($body['body']));
        $etag    = $article->etag();

        return $this->json->create($this->serialize($article), 201)
            ->withHeader('ETag', $etag)
            ->withHeader('Last-Modified', $article->updatedAt);
    }

    private function get(ServerRequestInterface $request): ResponseInterface
    {
        $id      = (int) Router::param($request, 'id');
        $article = $this->repo->findById($id);

        if ($article === null) {
            return $this->problems->create($request, 'not-found', 'Article not found.', 404);
        }

        $etag = $article->etag();

        // Returns 304 if If-None-Match matches current ETag or If-Modified-Since >= updatedAt
        $notModified = ConditionalGetHelper::check($request, $this->responseFactory, $etag, $article->updatedAt);
        if ($notModified !== null) {
            return $notModified;
        }

        return $this->json->create($this->serialize($article))
            ->withHeader('ETag', $etag)
            ->withHeader('Last-Modified', $article->updatedAt);
    }

    private function update(ServerRequestInterface $request): ResponseInterface
    {
        $id      = (int) Router::param($request, 'id');
        $article = $this->repo->findById($id);

        if ($article === null) {
            return $this->problems->create($request, 'not-found', 'Article not found.', 404);
        }

        // Returns 412 if If-Match doesn't match, 428 if If-Match is absent
        $preconditionFailed = ConditionalWriteHelper::check($request, $this->problems, $article->etag());
        if ($preconditionFailed !== null) {
            return $preconditionFailed;
        }

        $body = json_decode((string) $request->getBody(), true);
        if (
            !is_array($body) ||
            !isset($body['title'], $body['body']) ||
            !is_string($body['title']) ||
            !is_string($body['body'])
        ) {
            return $this->problems->create($request, 'invalid-body', 'title and body (string) are required.', 400);
        }

        try {
            $updated = $this->repo->update($id, trim($body['title']), trim($body['body']));
        } catch (\RuntimeException) {
            return $this->problems->create($request, 'not-found', 'Article not found.', 404);
        }

        $newEtag = $updated->etag();

        return $this->json->create($this->serialize($updated))
            ->withHeader('ETag', $newEtag)
            ->withHeader('Last-Modified', $updated->updatedAt);
    }

    /** @return array<string, mixed> */
    private function serialize(Article $article): array
    {
        return [
            'id'         => $article->id,
            'title'      => $article->title,
            'body'       => $article->body,
            'updated_at' => $article->updatedAt,
        ];
    }
}
