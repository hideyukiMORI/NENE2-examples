<?php

declare(strict_types=1);

namespace ProductLog\Product;

use Nene2\Database\DatabaseConstraintException;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const string SKU_PATTERN = '/\A[A-Z0-9\-]{1,32}\z/';
    private const int MAX_KEYWORD = 100;

    public function __construct(
        private readonly ProductRepository $repo,
        private readonly JsonResponseFactory $json,
        private readonly string $adminKey,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/products', $this->create(...));
        $router->get('/products', $this->list(...));
        $router->get('/products/{id}', $this->show(...));
        $router->delete('/products/{id}', $this->delete(...));
    }

    private function create(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $errors = [];

        $sku = $body['sku'] ?? null;
        if (!is_string($sku) || preg_match(self::SKU_PATTERN, $sku) !== 1) {
            $errors[] = new ValidationError('sku', 'sku must match /[A-Z0-9-]{1,32}/', 'invalid_value');
        }
        $name = $body['name'] ?? null;
        if (!is_string($name) || trim($name) === '' || strlen($name) > 200) {
            $errors[] = new ValidationError('name', 'name must be a non-empty string (max 200)', 'invalid_value');
        }
        $description = $body['description'] ?? '';
        if (!is_string($description) || strlen($description) > 2000) {
            $errors[] = new ValidationError('description', 'description must be a string (max 2000)', 'invalid_value');
        }
        $price = $body['price_cents'] ?? null;
        // ATK-05: is_int rejects float prices like 9.99; reject negatives too.
        if (!is_int($price) || $price < 0) {
            $errors[] = new ValidationError('price_cents', 'price_cents must be a non-negative integer', 'invalid_value');
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        assert(is_string($sku) && is_string($name) && is_string($description) && is_int($price));

        try {
            $id = $this->repo->create($sku, trim($name), $description, $price, $this->now());
        } catch (DatabaseConstraintException) {
            return $this->json->create(['error' => 'sku already exists'], 409); // UNIQUE(sku)
        }
        return $this->json->create($this->view((array) $this->repo->findActive($id)), 201);
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $keyword = null;
        if (array_key_exists('q', $params)) {
            $raw = $params['q'];
            if (!is_string($raw)) {
                throw new ValidationException([new ValidationError('q', 'q must be a string', 'invalid_value')]);
            }
            // Keyword length guard — stop multi-MB LIKE patterns.
            if (strlen($raw) > self::MAX_KEYWORD) {
                throw new ValidationException([new ValidationError('q', 'q too long (max 100)', 'invalid_value')]);
            }
            $keyword = $raw;
        }
        $limit = $this->intParam($params, 'limit', 1, 100, 20);
        $offset = $this->intParam($params, 'offset', 0, 1000000, 0);
        if ($limit === null || $offset === null) {
            throw new ValidationException([new ValidationError('limit', 'limit (1..100) / offset (>=0) must be valid', 'invalid_value')]);
        }
        $items = array_map($this->view(...), $this->repo->search($keyword, $limit, $offset));
        return $this->json->create(['products' => $items, 'count' => count($items)]);
    }

    private function show(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        $product = $id === 0 ? null : $this->repo->findActive($id);
        if ($product === null) {
            return $this->notFound();
        }
        return $this->json->create($this->view($product));
    }

    private function delete(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }
        $id = $this->idParam($request);
        // ATK-08: soft delete is idempotent-safe — a second delete affects 0 rows → 404.
        if ($id === 0 || $this->repo->softDelete($id) === 0) {
            return $this->notFound();
        }
        return $this->json->create(['deleted' => true]);
    }

    /** ATK-02 / ATK-10: empty configured key fails closed; comparison is constant-time. */
    private function isAdmin(ServerRequestInterface $request): bool
    {
        if ($this->adminKey === '') {
            return false;
        }
        return hash_equals($this->adminKey, $request->getHeaderLine('X-Admin-Key'));
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function view(array $a): array
    {
        return [
            'id' => (int) $a['id'],
            'sku' => (string) $a['sku'],
            'name' => (string) $a['name'],
            'description' => (string) $a['description'],
            'price_cents' => (int) $a['price_cents'],
            'created_at' => (string) $a['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function intParam(array $params, string $key, int $min, int $max, int $default): ?int
    {
        if (!array_key_exists($key, $params)) {
            return $default;
        }
        $raw = $params[$key];
        if (!is_string($raw) || !ctype_digit($raw)) {
            return null;
        }
        $n = (int) $raw;
        return $n >= $min && $n <= $max ? $n : null;
    }

    /** ATK-03/04: reject >18 digits and non-digits before any int cast. */
    private function idParam(ServerRequestInterface $request): int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $raw = (string) ($params['id'] ?? '');
        return ctype_digit($raw) && strlen($raw) <= 18 ? (int) $raw : 0;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM);
    }

    private function forbidden(): ResponseInterface
    {
        return $this->json->create(['error' => 'admin key required'], 403);
    }

    private function notFound(): ResponseInterface
    {
        return $this->json->create(['error' => 'product not found'], 404);
    }
}
