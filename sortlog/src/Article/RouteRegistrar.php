<?php

declare(strict_types=1);

namespace SortLog\Article;

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
        private readonly ArticleRepository $repo,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/articles', $this->create(...));
        $router->get('/articles', $this->list(...));
    }

    private function create(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $title = V::str($body['title'] ?? null, 200);
        if ($title === null || $title === '') {
            throw new ValidationException([new ValidationError('title', 'title must be a non-empty string', 'invalid_value')]);
        }
        $status = V::enum($body['status'] ?? 'draft', ArticleStatus::class);
        if (!$status instanceof ArticleStatus) {
            throw new ValidationException([new ValidationError('status', 'status must be draft|published|archived', 'invalid_value')]);
        }
        $id = $this->repo->create($title, $status->value, $this->now());
        return $this->json->create(['id' => $id, 'title' => $title, 'status' => $status->value], 201);
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();

        $sortCol = $this->validatedSort($params);
        $sortDir = $this->validatedDirection($params);
        $status = $this->validatedStatus($params);

        $limit = V::queryInt($params, 'limit', 1, 100, 20);
        $offset = V::queryInt($params, 'offset', 0, 1000000, 0);
        if ($limit === null || $offset === null) {
            throw new ValidationException([new ValidationError('limit', 'limit (1..100) / offset (>=0) must be valid integers', 'invalid_value')]);
        }

        $rows = $this->repo->list($sortCol, $sortDir, $status, $limit, $offset);
        $items = array_map(
            static fn (array $r): array => [
                'id' => (int) $r['id'],
                'title' => (string) $r['title'],
                'status' => (string) $r['status'],
                'created_at' => (string) $r['created_at'],
            ],
            $rows,
        );
        return $this->json->create(['items' => $items, 'sort' => $sortCol, 'order' => $sortDir, 'count' => count($items)]);
    }

    /**
     * `ORDER BY` columns cannot be bound parameters, so the raw value is
     * checked against an allow-list. PSR-7 has already URL-decoded once — we do
     * NOT decode again (that would open a double-encoding bypass).
     *
     * @param array<string, mixed> $params
     */
    private function validatedSort(array $params): string
    {
        $raw = $params['sort'] ?? null;
        if ($raw === null) {
            return 'created_at';
        }
        if (!is_string($raw)) {                                   // ?sort[]=id → array
            throw $this->invalid('sort', 'sort must be a string');
        }
        if (str_contains($raw, "\0")) {                           // %00 already decoded by PSR-7
            throw $this->invalid('sort', 'sort contains invalid characters');
        }
        if (!in_array($raw, ArticleRepository::SORT_COLUMNS, true)) {
            throw $this->invalid('sort', 'sort must be one of: ' . implode(', ', ArticleRepository::SORT_COLUMNS));
        }
        return $raw;
    }

    /** @param array<string, mixed> $params */
    private function validatedDirection(array $params): string
    {
        $raw = $params['order'] ?? 'asc';
        if (!is_string($raw)) {
            throw $this->invalid('order', 'order must be a string');
        }
        $dir = strtolower(trim($raw));
        if (!in_array($dir, ArticleRepository::SORT_DIRECTIONS, true)) {
            throw $this->invalid('order', 'order must be asc or desc');
        }
        return $dir;
    }

    /** @param array<string, mixed> $params */
    private function validatedStatus(array $params): ?string
    {
        if (!array_key_exists('status', $params)) {
            return null;
        }
        $status = V::enum($params['status'], ArticleStatus::class);   // array / injection → null
        if (!$status instanceof ArticleStatus) {
            throw $this->invalid('status', 'status must be draft|published|archived');
        }
        return $status->value;
    }

    private function invalid(string $field, string $message): ValidationException
    {
        return new ValidationException([new ValidationError($field, $message, 'invalid_value')]);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
