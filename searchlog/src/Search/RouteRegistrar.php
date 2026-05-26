<?php

declare(strict_types=1);

namespace SearchLog\Search;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const int SEARCH_MAX_LIMIT = 50;
    private const int SEARCH_DEFAULT_LIMIT = 10;
    private const int AUTOCOMPLETE_MAX_LIMIT = 10;
    private const int AUTOCOMPLETE_DEFAULT_LIMIT = 5;
    private const int QUERY_MIN_LENGTH = 2;
    private const int QUERY_MAX_LENGTH = 100;

    public function __construct(
        private readonly SearchRepository $repo,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/search', $this->handleSearch(...));
        $router->get('/autocomplete', $this->handleAutocomplete(...));
    }

    private function handleSearch(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();

        $q = isset($params['q']) ? trim((string) $params['q']) : '';
        $errors = $this->validateQuery($q);

        $limit = $this->clamp(
            isset($params['limit']) ? (int) $params['limit'] : self::SEARCH_DEFAULT_LIMIT,
            1,
            self::SEARCH_MAX_LIMIT
        );
        $offset = max(0, isset($params['offset']) ? (int) $params['offset'] : 0);
        $category = isset($params['category']) && trim((string) $params['category']) !== ''
            ? trim((string) $params['category'])
            : null;

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $result = $this->repo->search($q, $category, $limit, $offset);

        return $this->json->create([
            'query' => $q,
            'category' => $category,
            'total' => $result['total'],
            'limit' => $limit,
            'offset' => $offset,
            'items' => array_map($this->formatProduct(...), $result['items']),
        ]);
    }

    private function handleAutocomplete(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();

        $q = isset($params['q']) ? trim((string) $params['q']) : '';
        $errors = $this->validateQuery($q);

        $limit = $this->clamp(
            isset($params['limit']) ? (int) $params['limit'] : self::AUTOCOMPLETE_DEFAULT_LIMIT,
            1,
            self::AUTOCOMPLETE_MAX_LIMIT
        );

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $suggestions = $this->repo->autocomplete($q, $limit);

        return $this->json->create([
            'query' => $q,
            'suggestions' => $suggestions,
        ]);
    }

    /** @return list<ValidationError> */
    private function validateQuery(string $q): array
    {
        $errors = [];
        if ($q === '') {
            $errors[] = new ValidationError('q', 'q is required', 'required');
        } elseif (mb_strlen($q) < self::QUERY_MIN_LENGTH) {
            $errors[] = new ValidationError('q', 'q must be at least ' . self::QUERY_MIN_LENGTH . ' characters', 'too_short');
        } elseif (mb_strlen($q) > self::QUERY_MAX_LENGTH) {
            $errors[] = new ValidationError('q', 'q must be at most ' . self::QUERY_MAX_LENGTH . ' characters', 'too_long');
        }
        return $errors;
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function formatProduct(array $product): array
    {
        return [
            'id' => (int) $product['id'],
            'name' => (string) $product['name'],
            'description' => (string) $product['description'],
            'category' => (string) $product['category'],
            'price_cents' => (int) $product['price_cents'],
            'created_at' => (string) $product['created_at'],
        ];
    }
}
