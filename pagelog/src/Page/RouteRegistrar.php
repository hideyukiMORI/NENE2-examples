<?php

declare(strict_types=1);

namespace Page;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\QueryStringParser;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly SqliteArticleRepository $repo,
        private readonly JsonResponseFactory $json,
        private readonly ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/articles', $this->createArticle(...));
        $router->get('/articles/offset', $this->listByOffset(...));
        $router->get('/articles/cursor', $this->listByCursor(...));
        $router->get('/articles/count', $this->getCount(...));
    }

    private function createArticle(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return $this->problems->create($request, 'invalid-json', 'Request body must be valid JSON.', 400);
        }

        $title  = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';
        $author = isset($body['author']) && is_string($body['author']) ? trim($body['author']) : '';

        $errors = [];
        if ($title === '') {
            $errors[] = ['field' => 'title', 'code' => 'required', 'message' => 'Title is required.'];
        }
        if ($author === '') {
            $errors[] = ['field' => 'author', 'code' => 'required', 'message' => 'Author is required.'];
        }
        if ($errors !== []) {
            return $this->problems->create($request, 'validation-failed', 'Validation failed.', 422, null, ['errors' => $errors]);
        }

        $category = isset($body['category']) && is_string($body['category']) ? trim($body['category']) : 'general';
        $article  = $this->repo->create($title, $author, $category);
        return $this->json->create($article->toArray(), 201);
    }

    private function listByOffset(ServerRequestInterface $request): ResponseInterface
    {
        $limit  = max(1, min(100, QueryStringParser::int($request, 'limit', 20) ?? 20));
        $offset = max(0, QueryStringParser::int($request, 'offset', 0) ?? 0);

        $items = $this->repo->listByOffset($limit, $offset);
        $total = $this->repo->count();

        return $this->json->create([
            'items'       => array_map(static fn (Article $a) => $a->toArray(), $items),
            'limit'       => $limit,
            'offset'      => $offset,
            'total'       => $total,
            'has_more'    => ($offset + $limit) < $total,
            'next_offset' => ($offset + $limit) < $total ? $offset + $limit : null,
        ]);
    }

    private function listByCursor(ServerRequestInterface $request): ResponseInterface
    {
        $limit   = max(1, min(100, QueryStringParser::int($request, 'limit', 20) ?? 20));
        $afterId = QueryStringParser::int($request, 'after');

        // Fetch one extra to detect has_more without a COUNT query
        $fetch   = $limit + 1;
        $items   = $this->repo->listByCursor($fetch, $afterId);
        $hasMore = count($items) > $limit;
        if ($hasMore) {
            array_pop($items);
        }

        $nextCursor = $hasMore && $items !== [] ? end($items)->id : null;

        return $this->json->create([
            'items'       => array_map(static fn (Article $a) => $a->toArray(), $items),
            'limit'       => $limit,
            'has_more'    => $hasMore,
            'next_cursor' => $nextCursor,
        ]);
    }

    private function getCount(ServerRequestInterface $request): ResponseInterface
    {
        return $this->json->create(['count' => $this->repo->count()]);
    }
}
