<?php

declare(strict_types=1);

namespace TagFilterLog\Post;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\V;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    public function __construct(
        private readonly PostRepository $repo,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/posts', $this->create(...));
        $router->get('/posts', $this->list(...));
        $router->get('/posts/{id}', $this->show(...));
    }

    private function create(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $title = V::str($body['title'] ?? null, 200);
        if ($title === null || $title === '') {
            throw new ValidationException([new ValidationError('title', 'title must be a non-empty string', 'invalid_value')]);
        }
        $text = V::str($body['body'] ?? '', 20000) ?? '';

        $rawTags = is_array($body['tags'] ?? null) ? $body['tags'] : [];
        $tags = $this->cleanTags($rawTags);

        $post = $this->repo->create($title, $text, $tags, $this->now());
        return $this->json->create($post->toArray(), 201);
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $tags = $this->extractTags($request);
        $params = $request->getQueryParams();
        $mode = isset($params['mode']) && is_string($params['mode']) ? $params['mode'] : 'all';
        // Unknown mode falls through to AND (the safer, narrower default).
        $posts = $mode === 'any'
            ? $this->repo->findByAnyTag($tags)
            : $this->repo->findByAllTags($tags);
        return $this->json->create([
            'mode' => $mode === 'any' ? 'any' : 'all',
            'tags' => $tags,
            'posts' => array_map(static fn (Post $p): array => $p->toArray(), $posts),
            'count' => count($posts),
        ]);
    }

    private function show(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        $post = $id === 0 ? null : $this->repo->find($id);
        if ($post === null) {
            return $this->json->create(['error' => 'post not found'], 404);
        }
        return $this->json->create($post->toArray());
    }

    /**
     * Accepts both `?tags=php,api` (comma-separated) and `?tags[]=php&tags[]=api`
     * (PHP array). PSR-7 getQueryParams() parses the array form natively.
     *
     * @return list<string>
     */
    private function extractTags(ServerRequestInterface $request): array
    {
        $raw = $request->getQueryParams()['tags'] ?? null;
        if (is_string($raw)) {
            return $this->cleanTags(explode(',', $raw));
        }
        if (is_array($raw)) {
            return $this->cleanTags($raw);
        }
        return [];
    }

    /**
     * @param array<int|string, mixed> $raw
     * @return list<string>
     */
    private function cleanTags(array $raw): array
    {
        $trimmed = array_map(static fn (mixed $t): string => is_string($t) ? trim($t) : '', $raw);
        return array_values(array_filter($trimmed, static fn (string $s): bool => $s !== ''));
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
}
