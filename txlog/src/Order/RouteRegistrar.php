<?php

declare(strict_types=1);

namespace Tx\Order;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly OrderService $service,
        private readonly JsonResponseFactory $json,
        private readonly ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/orders', $this->placeOrder(...));
    }

    private function placeOrder(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body) || !isset($body['items']) || !is_array($body['items'])) {
            return $this->problems->create($request, 'invalid-body', 'Request body must contain items array.', 400);
        }

        /** @var list<array{product_id: int, quantity: int}> $items */
        $items = [];
        foreach ($body['items'] as $i => $item) {
            if (
                !is_array($item) ||
                !isset($item['product_id'], $item['quantity']) ||
                !is_int($item['product_id']) ||
                !is_int($item['quantity']) ||
                $item['quantity'] < 1
            ) {
                return $this->problems->create(
                    $request,
                    'validation-failed',
                    'Validation failed.',
                    422,
                    null,
                    ['errors' => [['field' => "items.{$i}", 'code' => 'invalid', 'message' => 'product_id (int) and quantity (int ≥ 1) are required.']]],
                );
            }
            $items[] = ['product_id' => $item['product_id'], 'quantity' => $item['quantity']];
        }

        try {
            $orderId = $this->service->placeOrder($items);
            return $this->json->create(['order_id' => $orderId, 'status' => 'placed'], 201);
        } catch (InsufficientStockException $e) {
            return $this->problems->create(
                $request,
                'insufficient-stock',
                'Insufficient stock.',
                422,
                $e->getMessage(),
            );
        }
    }
}
