<?php

declare(strict_types=1);

namespace Csrf\Order;

use Nene2\Database\DatabaseConstraintException;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RouteRegistrar
{
    public function __construct(
        private SqliteOrderRepository $repo,
        private JsonResponseFactory $json,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/orders', $this->listOrders(...));
        $router->post('/orders', $this->createOrder(...));
        $router->get('/orders/{id}', $this->getOrder(...));
    }

    private function listOrders(ServerRequestInterface $request): ResponseInterface
    {
        $orders = $this->repo->listAll();

        return $this->json->createList(
            array_map(static fn (Order $o) => $o->toArray(), $orders),
        );
    }

    private function createOrder(ServerRequestInterface $request): ResponseInterface
    {
        // Idempotency-Key header — required for state-changing requests
        $idempotencyKey = trim($request->getHeaderLine('Idempotency-Key'));

        if ($idempotencyKey === '') {
            return $this->problems->create(
                $request,
                'missing-idempotency-key',
                'Missing Idempotency-Key',
                422,
                'The Idempotency-Key header is required for order creation. Use a UUID or unique token to prevent duplicate orders.',
            );
        }

        // Check for duplicate (idempotency replay)
        $existing = $this->repo->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            // Return the original response — idempotent replay
            return $this->json->create($existing->toArray(), 200);
        }

        $body     = JsonRequestBodyParser::parse($request);
        $item     = isset($body['item']) && is_string($body['item']) ? trim($body['item']) : '';
        $quantity = isset($body['quantity']) && is_int($body['quantity']) ? $body['quantity'] : 0;
        $price    = isset($body['price']) && (is_int($body['price']) || is_float($body['price'])) ? (float) $body['price'] : -1.0;

        $errors = [];
        if ($item === '') {
            $errors[] = ['field' => 'item', 'code' => 'required', 'message' => 'Item name is required.'];
        }
        if ($quantity <= 0) {
            $errors[] = ['field' => 'quantity', 'code' => 'invalid', 'message' => 'Quantity must be a positive integer.'];
        }
        if ($price < 0.0) {
            $errors[] = ['field' => 'price', 'code' => 'invalid', 'message' => 'Price must be non-negative.'];
        }

        if ($errors !== []) {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => $errors,
            ]);
        }

        try {
            $order = $this->repo->create($idempotencyKey, $item, $quantity, $price * $quantity);
        } catch (DatabaseConstraintException) {
            // Race condition: another request with the same key was committed first
            $existing = $this->repo->findByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                return $this->json->create($existing->toArray(), 200);
            }

            return $this->problems->create($request, 'conflict', 'Conflict', 409, 'Order could not be created due to a conflict.');
        }

        return $this->json->create($order->toArray(), 201);
    }

    private function getOrder(ServerRequestInterface $request): ResponseInterface
    {
        $id    = (int) (Router::param($request, 'id') ?? '0');
        $order = $id > 0 ? $this->repo->findById($id) : null;

        if ($order === null) {
            return $this->problems->create($request, 'not-found', 'Not Found', 404, 'Order not found.');
        }

        return $this->json->create($order->toArray());
    }
}
