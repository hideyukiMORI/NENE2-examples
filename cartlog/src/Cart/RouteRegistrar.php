<?php

declare(strict_types=1);

namespace CartLog\Cart;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    public function __construct(
        private readonly CartRepository $repo,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/cart', $this->handleGetCart(...));
        $router->post('/cart/items', $this->handleAddItem(...));
        $router->put('/cart/items/{productId}', $this->handleUpdateItem(...));
        $router->delete('/cart/items/{productId}', $this->handleRemoveItem(...));
        $router->delete('/cart', $this->handleClearCart(...));
    }

    private function handleGetCart(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->requireUserId($request);
        if ($userId === null) {
            return $this->json->create(['error' => 'X-User-Id header required'], 401);
        }

        if ($this->repo->findUserById($userId) === null) {
            return $this->json->create(['error' => 'User not found'], 404);
        }

        $items = $this->repo->getCart($userId);
        $total = 0;
        $formatted = [];
        foreach ($items as $item) {
            $subtotal = (int) $item['price'] * (int) $item['quantity'];
            $total += $subtotal;
            $formatted[] = $this->formatItem($item, $subtotal);
        }

        return $this->json->create([
            'items' => $formatted,
            'total' => $total,
            'count' => count($formatted),
        ]);
    }

    private function handleAddItem(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->requireUserId($request);
        if ($userId === null) {
            return $this->json->create(['error' => 'X-User-Id header required'], 401);
        }

        if ($this->repo->findUserById($userId) === null) {
            return $this->json->create(['error' => 'User not found'], 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        [$productId, $quantity, $errors] = $this->parseAddBody($body);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $product = $this->repo->findProductById($productId);
        if ($product === null) {
            return $this->json->create(['error' => 'Product not found'], 404);
        }

        $existing = $this->repo->findCartItem($userId, $productId);
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
        $this->repo->addItem($userId, $productId, $quantity, $now);

        $item = $this->repo->findCartItem($userId, $productId);
        assert($item !== null);
        $subtotal = (int) $item['price'] * (int) $item['quantity'];

        $status = $existing === null ? 201 : 200;
        return $this->json->create($this->formatItem($item, $subtotal), $status);
    }

    private function handleUpdateItem(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->requireUserId($request);
        if ($userId === null) {
            return $this->json->create(['error' => 'X-User-Id header required'], 401);
        }

        if ($this->repo->findUserById($userId) === null) {
            return $this->json->create(['error' => 'User not found'], 404);
        }

        $productId = (int) $this->routeParam($request, 'productId');
        if ($productId <= 0) {
            return $this->json->create(['error' => 'Invalid product ID'], 404);
        }

        $existing = $this->repo->findCartItem($userId, $productId);
        if ($existing === null) {
            return $this->json->create(['error' => 'Item not in cart'], 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        [$quantity, $errors] = $this->parseQuantityBody($body);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');

        if ($quantity === 0) {
            $this->repo->removeItem($userId, $productId);
            return $this->json->createEmpty(204);
        }

        $this->repo->updateQuantity($userId, $productId, $quantity, $now);
        $item = $this->repo->findCartItem($userId, $productId);
        assert($item !== null);
        $subtotal = (int) $item['price'] * (int) $item['quantity'];

        return $this->json->create($this->formatItem($item, $subtotal));
    }

    private function handleRemoveItem(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->requireUserId($request);
        if ($userId === null) {
            return $this->json->create(['error' => 'X-User-Id header required'], 401);
        }

        if ($this->repo->findUserById($userId) === null) {
            return $this->json->create(['error' => 'User not found'], 404);
        }

        $productId = (int) $this->routeParam($request, 'productId');
        if ($productId <= 0) {
            return $this->json->create(['error' => 'Invalid product ID'], 404);
        }

        $existing = $this->repo->findCartItem($userId, $productId);
        if ($existing === null) {
            return $this->json->create(['error' => 'Item not in cart'], 404);
        }

        $this->repo->removeItem($userId, $productId);
        return $this->json->createEmpty(204);
    }

    private function handleClearCart(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->requireUserId($request);
        if ($userId === null) {
            return $this->json->create(['error' => 'X-User-Id header required'], 401);
        }

        if ($this->repo->findUserById($userId) === null) {
            return $this->json->create(['error' => 'User not found'], 404);
        }

        $this->repo->clearCart($userId);
        return $this->json->createEmpty(204);
    }

    private function requireUserId(ServerRequestInterface $request): ?int
    {
        $header = $request->getHeaderLine('X-User-Id');
        if ($header === '') {
            return null;
        }
        $id = (int) $header;
        return $id > 0 ? $id : null;
    }

    private function routeParam(ServerRequestInterface $request, string $key): string
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        return (string) ($params[$key] ?? '');
    }

    /**
     * @param array<string, mixed> $body
     * @return array{int, int, list<ValidationError>}
     */
    private function parseAddBody(array $body): array
    {
        $errors = [];

        if (!isset($body['product_id']) || !is_int($body['product_id'])) {
            $errors[] = new ValidationError('product_id', 'product_id must be an integer', 'invalid_type');
        }

        $productId = isset($body['product_id']) && is_int($body['product_id']) ? $body['product_id'] : 0;

        if ($productId <= 0 && $errors === []) {
            $errors[] = new ValidationError('product_id', 'product_id must be positive', 'invalid_value');
        }

        if (!isset($body['quantity']) || !is_int($body['quantity'])) {
            $errors[] = new ValidationError('quantity', 'quantity must be an integer', 'invalid_type');
        }

        $quantity = isset($body['quantity']) && is_int($body['quantity']) ? $body['quantity'] : 0;

        if ($quantity <= 0 && !isset($errors[1])) {
            $errors[] = new ValidationError('quantity', 'quantity must be positive', 'invalid_value');
        }

        return [$productId, $quantity, $errors];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{int, list<ValidationError>}
     */
    private function parseQuantityBody(array $body): array
    {
        $errors = [];

        if (!isset($body['quantity']) || !is_int($body['quantity'])) {
            $errors[] = new ValidationError('quantity', 'quantity must be an integer', 'invalid_type');
            return [0, $errors];
        }

        $quantity = $body['quantity'];

        if ($quantity < 0) {
            $errors[] = new ValidationError('quantity', 'quantity must be 0 or positive', 'invalid_value');
            return [0, $errors];
        }

        return [$quantity, $errors];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function formatItem(array $item, int $subtotal): array
    {
        return [
            'id' => (int) $item['id'],
            'product_id' => (int) $item['product_id'],
            'product_name' => (string) $item['product_name'],
            'price' => (int) $item['price'],
            'quantity' => (int) $item['quantity'],
            'subtotal' => $subtotal,
            'added_at' => (string) $item['added_at'],
            'updated_at' => (string) $item['updated_at'],
        ];
    }
}
