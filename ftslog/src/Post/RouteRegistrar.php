<?php

declare(strict_types=1);

namespace FtsLog\Post;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\V;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const int MAX_QUERY = 200;

    public function __construct(
        private readonly PostRepository $repo,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/posts', $this->create(...));
        $router->get('/posts', $this->list(...));
        // '/posts/search' before '/posts/{id}' so 'search' is not captured as an id.
        $router->get('/posts/search', $this->search(...));
        $router->get('/posts/{id}', $this->show(...));
    }

    private function create(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $title = V::str($body['title'] ?? null, 200);
        if ($title === null || $title === '') {
            throw new ValidationException([new ValidationError('title', 'title must be a non-empty string', 'invalid_value')]);
        }
        $text = V::str($body['body'] ?? '', 50000) ?? '';
        $tags = V::str($body['tags'] ?? '', 500) ?? '';
        $id = $this->repo->create($title, $text, $tags, $this->now());
        return $this->json->create($this->view((array) $this->repo->find($id)), 201);
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $limit = V::queryInt($params, 'limit', 1, 100, 20);
        $offset = V::queryInt($params, 'offset', 0, 1000000, 0);
        if ($limit === null || $offset === null) {
            throw new ValidationException([new ValidationError('limit', 'limit (1..100) / offset (>=0) must be valid', 'invalid_value')]);
        }
        $items = array_map($this->view(...), $this->repo->listAll($limit, $offset));
        return $this->json->create(['posts' => $items, 'count' => count($items)]);
    }

    private function search(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $q = isset($params['q']) && is_string($params['q']) ? trim($params['q']) : '';
        if ($q === '') {
            throw new ValidationException([new ValidationError('q', 'q query parameter is required', 'invalid_value')]);
        }
        if (strlen($q) > self::MAX_QUERY) {
            throw new ValidationException([new ValidationError('q', 'q too long (max 200)', 'invalid_value')]);
        }
        $limit = V::queryInt($params, 'limit', 1, 100, 20);
        $offset = V::queryInt($params, 'offset', 0, 1000000, 0);
        if ($limit === null || $offset === null) {
            throw new ValidationException([new ValidationError('limit', 'limit (1..100) / offset (>=0) must be valid', 'invalid_value')]);
        }

        try {
            $rows = $this->repo->search($q, $limit, $offset);
        } catch (\Throwable) {
            // FTS5 raises on malformed query syntax (e.g. an unclosed quote).
            // Map it to 400 so a parser error never surfaces as a 500.
            return $this->json->create(['error' => 'invalid search query'], 400);
        }

        $items = array_map(function (array $row): array {
            $view = $this->view($row);
            $view['rank'] = (float) $row['rank'];
            return $view;
        }, $rows);
        return $this->json->create(['query' => $q, 'total' => count($items), 'items' => $items]);
    }

    private function show(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        $post = $id === 0 ? null : $this->repo->find($id);
        if ($post === null) {
            return $this->notFound();
        }
        return $this->json->create($this->view($post));
    }

    /**
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function view(array $p): array
    {
        return [
            'id' => (int) $p['id'],
            'title' => (string) $p['title'],
            'body' => (string) $p['body'],
            'tags' => (string) $p['tags'],
            'created_at' => (string) $p['created_at'],
        ];
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM);
    }

    private function idParam(ServerRequestInterface $request): int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $raw = (string) ($params['id'] ?? '');
        return ctype_digit($raw) && strlen($raw) <= 18 ? (int) $raw : 0;
    }

    private function notFound(): ResponseInterface
    {
        return $this->json->create(['error' => 'post not found'], 404);
    }
}
