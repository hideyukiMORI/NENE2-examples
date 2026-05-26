<?php

declare(strict_types=1);

namespace Order\Order;

use Nene2\Http\JsonResponseFactory;
use Nene2\Http\JsonRequestBodyParser;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly OrderRepository   $repository,
        private readonly JsonResponseFactory $responseFactory,
    ) {}

    public function register(Router $router): void
    {
        $router->post('/users', $this->createUser(...));
        $router->post('/products', $this->createProduct(...));
        $router->post('/cart', $this->addToCart(...));
        $router->get('/cart', $this->getCart(...));
        $router->delete('/cart/{productId}', $this->removeFromCart(...));
        $router->post('/orders', $this->placeOrder(...));
        $router->get('/orders/{orderId}', $this->getOrder(...));
    }

    private function createUser(ServerRequestInterface $request): ResponseInterface
    {
        $body = JsonRequestBodyParser::parse($request);

        if (!isset($body['name']) || !is_string($body['name']) || trim($body['name']) === '') {
            return $this->responseFactory->create(['error' => 'name is required'], 422);
        }

        $id = $this->repository->createUser(trim($body['name']), date('c'));

        return $this->responseFactory->create(['id' => $id], 201);
    }

    private function createProduct(ServerRequestInterface $request): ResponseInterface
    {
        $body = JsonRequestBodyParser::parse($request);

        if (!isset($body['name']) || !is_string($body['name']) || trim($body['name']) === '') {
            return $this->responseFactory->create(['error' => 'name is required'], 422);
        }

        if (!isset($body['price']) || !is_int($body['price']) || $body['price'] < 0) {
            return $this->responseFactory->create(['error' => 'price must be a non-negative integer'], 422);
        }

        $stock = isset($body['stock']) && is_int($body['stock']) ? $body['stock'] : 0;

        if ($stock < 0) {
            return $this->responseFactory->create(['error' => 'stock must be non-negative'], 422);
        }

        $id = $this->repository->createProduct(trim($body['name']), $body['price'], $stock, date('c'));

        return $this->responseFactory->create(['id' => $id], 201);
    }

    private function addToCart(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = (int) $request->getHeaderLine('X-User-Id');

        if ($actorId <= 0) {
            return $this->responseFactory->create(['error' => 'X-User-Id header required'], 400);
        }

        if (!$this->repository->findUserById($actorId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $body = JsonRequestBodyParser::parse($request);

        if (!isset($body['product_id']) || !is_int($body['product_id']) || $body['product_id'] <= 0) {
            return $this->responseFactory->create(['error' => 'product_id is required'], 422);
        }

        $quantity = isset($body['quantity']) && is_int($body['quantity']) ? $body['quantity'] : 1;

        if ($quantity <= 0) {
            return $this->responseFactory->create(['error' => 'quantity must be positive'], 422);
        }

        $product = $this->repository->findProductById($body['product_id']);

        if ($product === null) {
            return $this->responseFactory->create(['error' => 'product not found'], 404);
        }

        $this->repository->addToCart($actorId, $body['product_id'], $quantity, date('c'));

        return $this->responseFactory->create(['ok' => true], 200);
    }

    private function getCart(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = (int) $request->getHeaderLine('X-User-Id');

        if ($actorId <= 0) {
            return $this->responseFactory->create(['error' => 'X-User-Id header required'], 400);
        }

        if (!$this->repository->findUserById($actorId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $items = $this->repository->listCartItems($actorId);
        $total = array_sum(array_map(fn(array $i) => $i['price'] * $i['quantity'], $items));

        return $this->responseFactory->create([
            'items' => $items,
            'count' => count($items),
            'total' => $total,
        ], 200);
    }

    private function removeFromCart(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = (int) $request->getHeaderLine('X-User-Id');

        if ($actorId <= 0) {
            return $this->responseFactory->create(['error' => 'X-User-Id header required'], 400);
        }

        $params    = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
        $productId = isset($params['productId']) ? (int) $params['productId'] : 0;

        if ($productId <= 0) {
            return $this->responseFactory->create(['error' => 'invalid productId'], 404);
        }

        $removed = $this->repository->removeFromCart($actorId, $productId);

        if (!$removed) {
            return $this->responseFactory->create(['error' => 'item not found in cart'], 404);
        }

        return $this->responseFactory->createEmpty(204);
    }

    private function placeOrder(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = (int) $request->getHeaderLine('X-User-Id');

        if ($actorId <= 0) {
            return $this->responseFactory->create(['error' => 'X-User-Id header required'], 400);
        }

        if (!$this->repository->findUserById($actorId)) {
            return $this->responseFactory->create(['error' => 'user not found'], 404);
        }

        $items = $this->repository->listCartItems($actorId);

        if ($items === []) {
            return $this->responseFactory->create(['error' => 'cart is empty'], 422);
        }

        // Check stock for all items before placing order
        foreach ($items as $item) {
            $product = $this->repository->findProductById($item['product_id']);

            if ($product === null || $product['stock'] < $item['quantity']) {
                return $this->responseFactory->create([
                    'error'      => 'insufficient stock',
                    'product_id' => $item['product_id'],
                ], 422);
            }
        }

        $total = array_sum(array_map(fn(array $i) => $i['price'] * $i['quantity'], $items));

        // Decrement stock and create order
        foreach ($items as $item) {
            $this->repository->decrementStock($item['product_id'], $item['quantity']);
        }

        $orderId = $this->repository->createOrder($actorId, $items, $total, date('c'));
        $this->repository->clearCart($actorId);

        return $this->responseFactory->create(['id' => $orderId, 'total' => $total], 201);
    }

    private function getOrder(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = (int) $request->getHeaderLine('X-User-Id');

        if ($actorId <= 0) {
            return $this->responseFactory->create(['error' => 'X-User-Id header required'], 400);
        }

        $params  = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
        $orderId = isset($params['orderId']) ? (int) $params['orderId'] : 0;

        if ($orderId <= 0) {
            return $this->responseFactory->create(['error' => 'invalid orderId'], 404);
        }

        $order = $this->repository->findOrderById($orderId);

        if ($order === null) {
            return $this->responseFactory->create(['error' => 'order not found'], 404);
        }

        if ($order['user_id'] !== $actorId) {
            return $this->responseFactory->create(['error' => 'forbidden'], 403);
        }

        $orderItems = $this->repository->listOrderItems($orderId);

        return $this->responseFactory->create([
            'id'         => $order['id'],
            'user_id'    => $order['user_id'],
            'total'      => $order['total'],
            'created_at' => $order['created_at'],
            'items'      => $orderItems,
        ], 200);
    }
}
