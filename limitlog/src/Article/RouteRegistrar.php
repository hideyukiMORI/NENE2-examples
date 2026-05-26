<?php

declare(strict_types=1);

namespace Limitlog\Article;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    // Sentinel used for "no cursor" — start from the beginning (max int)
    private const int NO_CURSOR = PHP_INT_MAX;

    public function __construct(
        private readonly Router              $router,
        private readonly ArticleRepository  $repository,
        private readonly JsonResponseFactory $responseFactory,
    ) {
    }

    public function register(): void
    {
        $this->router->post('/articles', $this->handleCreate(...));
        $this->router->get('/articles', $this->handleList(...));
        $this->router->get('/articles/cursor', $this->handleCursor(...));
        $this->router->get('/articles/by-author', $this->handleByAuthor(...));
    }

    // ── POST /articles ────────────────────────────────────────────────────
    private function handleCreate(ServerRequestInterface $request): ResponseInterface
    {
        $body  = $this->parseBody($request);
        $title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';
        $text  = isset($body['body']) && is_string($body['body']) ? trim($body['body']) : '';

        $authorHeader = $request->getHeaderLine('X-User-Id');

        if ($authorHeader === '' || !ctype_digit($authorHeader) || (int) $authorHeader <= 0) {
            return $this->responseFactory->create(['error' => 'X-User-Id header required.'], 401);
        }

        if ($title === '') {
            return $this->responseFactory->create(['error' => 'title is required.'], 422);
        }

        $article = $this->repository->create((int) $authorHeader, $title, $text, date('c'));

        return $this->responseFactory->create($article->toArray(), 201);
    }

    // ── GET /articles?page=N&limit=N  (offset pagination) ────────────────
    private function handleList(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();

        $page  = $this->clampInt($params, 'page', 1, 1, PHP_INT_MAX);
        $limit = $this->clampInt(
            $params,
            'limit',
            ArticleRepository::DEFAULT_LIMIT,
            ArticleRepository::MIN_LIMIT,
            ArticleRepository::MAX_LIMIT
        );

        if ($page === null) {
            return $this->responseFactory->create(['error' => 'page must be a positive integer.'], 422);
        }

        if ($limit === null) {
            return $this->responseFactory->create(
                ['error' => sprintf('limit must be between %d and %d.', ArticleRepository::MIN_LIMIT, ArticleRepository::MAX_LIMIT)],
                422,
            );
        }

        $result = $this->repository->listByOffset($page, $limit);
        $result['data'] = array_map(static fn (Article $a): array => $a->toArray(), $result['data']);

        return $this->responseFactory->create($result);
    }

    // ── GET /articles/cursor?after=ID&limit=N  (cursor pagination) ────────
    private function handleCursor(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();

        $afterId = $this->clampInt($params, 'after', self::NO_CURSOR, 0, PHP_INT_MAX);
        $limit   = $this->clampInt(
            $params,
            'limit',
            ArticleRepository::DEFAULT_LIMIT,
            ArticleRepository::MIN_LIMIT,
            ArticleRepository::MAX_LIMIT
        );

        if ($afterId === null) {
            return $this->responseFactory->create(['error' => 'after must be a non-negative integer.'], 422);
        }

        if ($limit === null) {
            return $this->responseFactory->create(
                ['error' => sprintf('limit must be between %d and %d.', ArticleRepository::MIN_LIMIT, ArticleRepository::MAX_LIMIT)],
                422,
            );
        }

        $result = $this->repository->listByCursor($afterId, $limit);
        $result['data'] = array_map(static fn (Article $a): array => $a->toArray(), $result['data']);

        return $this->responseFactory->create($result);
    }

    // ── GET /articles/by-author?author_id=N&after=ID&limit=N ─────────────
    private function handleByAuthor(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();

        $authorId = $this->clampInt($params, 'author_id', null, 1, PHP_INT_MAX);
        $afterId  = $this->clampInt($params, 'after', self::NO_CURSOR, 0, PHP_INT_MAX);
        $limit    = $this->clampInt(
            $params,
            'limit',
            ArticleRepository::DEFAULT_LIMIT,
            ArticleRepository::MIN_LIMIT,
            ArticleRepository::MAX_LIMIT
        );

        if ($authorId === null) {
            return $this->responseFactory->create(['error' => 'author_id must be a positive integer.'], 422);
        }

        if ($afterId === null) {
            return $this->responseFactory->create(['error' => 'after must be a non-negative integer.'], 422);
        }

        if ($limit === null) {
            return $this->responseFactory->create(
                ['error' => sprintf('limit must be between %d and %d.', ArticleRepository::MIN_LIMIT, ArticleRepository::MAX_LIMIT)],
                422,
            );
        }

        $result = $this->repository->listByAuthor($authorId, $afterId, $limit);
        $result['data'] = array_map(static fn (Article $a): array => $a->toArray(), $result['data']);

        return $this->responseFactory->create($result);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Parses an integer query parameter with clamping and strict validation.
     *
     * Returns $default when the key is absent.
     * Returns null when the value is present but invalid or out of range.
     *
     * @param array<string, mixed> $params
     */
    private function clampInt(array $params, string $key, ?int $default, int $min, int $max): ?int
    {
        if (!array_key_exists($key, $params)) {
            return $default;
        }

        $raw = $params[$key];

        // Must be a string of digits only (no sign, no whitespace, no float dot).
        // ctype_digit('') returns false, so empty string is already rejected here.
        if (!is_string($raw) || !ctype_digit($raw)) {
            return null;
        }

        // Prevent silent int overflow from e.g. "99999999999999999999"
        if (strlen($raw) > 18) {
            return null;
        }

        $value = (int) $raw;

        if ($value < $min || $value > $max) {
            return null;
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function parseBody(ServerRequestInterface $request): array
    {
        $body = json_decode((string) $request->getBody(), true);

        return is_array($body) ? $body : [];
    }
}
