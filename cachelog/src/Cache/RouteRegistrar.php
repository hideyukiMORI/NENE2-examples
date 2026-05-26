<?php

declare(strict_types=1);

namespace CacheLog\Cache;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const string KEY_LIST = 'products:list';

    public function __construct(
        private readonly ProductRepository $repo,
        private readonly CacheInterface $cache,
        private readonly JsonResponseFactory $json,
    ) {}

    public function register(Router $router): void
    {
        $router->get('/products', $this->handleList(...));
        $router->get('/products/{id}', $this->handleGet(...));
        $router->post('/products', $this->handleCreate(...));
        $router->put('/products/{id}', $this->handleUpdate(...));
        $router->delete('/products/{id}', $this->handleDelete(...));
        $router->post('/cache/clear', $this->handleCacheClear(...));
        $router->get('/cache/stats', $this->handleCacheStats(...));
    }

    private function handleList(ServerRequestInterface $request): ResponseInterface
    {
        $cached = $this->cache->get(self::KEY_LIST);
        if ($cached !== null) {
            /** @var list<array<string, mixed>> $cached */
            return $this->json->create(['products' => $cached, 'cached' => true]);
        }

        $products = $this->repo->findAll();
        $this->cache->set(self::KEY_LIST, $products);
        return $this->json->create(['products' => $products, 'cached' => false]);
    }

    private function handleGet(ServerRequestInterface $request): ResponseInterface
    {
        $id  = $this->id($request);
        $key = "product:{$id}";

        $cached = $this->cache->get($key);
        if ($cached !== null) {
            /** @var array<string, mixed> $cached */
            return $this->json->create(array_merge($cached, ['cached' => true]));
        }

        $product = $this->repo->find($id);
        if ($product === null) {
            return $this->json->create(['error' => 'Product not found'], 404);
        }

        $this->cache->set($key, $product);
        return $this->json->create(array_merge($product, ['cached' => false]));
    }

    private function handleCreate(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        [$name, $price, $stock] = $this->parseProductBody($body);

        $now = $this->now();
        $id  = $this->repo->create($name, $price, $stock, $now);

        // Invalidate list cache so next GET /products fetches fresh data
        $this->cache->delete(self::KEY_LIST);

        $product = $this->repo->find($id);
        assert($product !== null);
        return $this->json->create($product, 201);
    }

    private function handleUpdate(ServerRequestInterface $request): ResponseInterface
    {
        $id   = $this->id($request);
        $body = (array) ($request->getParsedBody() ?? []);
        [$name, $price, $stock] = $this->parseProductBody($body);

        if (!$this->repo->update($id, $name, $price, $stock, $this->now())) {
            return $this->json->create(['error' => 'Product not found'], 404);
        }

        // Invalidate both the specific item and the list
        $this->cache->delete("product:{$id}");
        $this->cache->delete(self::KEY_LIST);

        $updated = $this->repo->find($id);
        assert($updated !== null);
        return $this->json->create($updated);
    }

    private function handleDelete(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->id($request);
        if (!$this->repo->delete($id)) {
            return $this->json->create(['error' => 'Product not found'], 404);
        }

        $this->cache->delete("product:{$id}");
        $this->cache->delete(self::KEY_LIST);

        return $this->json->createEmpty(204);
    }

    private function handleCacheClear(ServerRequestInterface $request): ResponseInterface
    {
        $this->cache->flush();
        return $this->json->create(['cleared' => true]);
    }

    private function handleCacheStats(ServerRequestInterface $request): ResponseInterface
    {
        return $this->json->create($this->cache->stats());
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: string, 1: float, 2: int}
     */
    private function parseProductBody(array $body): array
    {
        $errors = [];

        $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
        if ($name === '') {
            $errors[] = new ValidationError('name', 'name is required', 'required');
        }

        $price = 0.0;
        if (!isset($body['price']) || !is_numeric($body['price'])) {
            $errors[] = new ValidationError('price', 'price must be a number', 'invalid');
        } else {
            $price = (float) $body['price'];
            if ($price < 0) {
                $errors[] = new ValidationError('price', 'price must be non-negative', 'invalid');
            }
        }

        $stock = 0;
        if (isset($body['stock'])) {
            if (!is_int($body['stock']) && !ctype_digit((string) $body['stock'])) {
                $errors[] = new ValidationError('stock', 'stock must be a non-negative integer', 'invalid');
            } else {
                $stock = (int) $body['stock'];
                if ($stock < 0) {
                    $errors[] = new ValidationError('stock', 'stock must be non-negative', 'invalid');
                }
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [$name, $price, $stock];
    }

    private function id(ServerRequestInterface $request): int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        return (int) ($params['id'] ?? 0);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
