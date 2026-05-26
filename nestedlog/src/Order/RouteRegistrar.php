<?php

declare(strict_types=1);

namespace Nested\Order;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly SqliteOrderRepository $repo,
        private readonly OrderValidator $validator,
        private readonly JsonResponseFactory $json,
        private readonly ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/orders', $this->createOrder(...));
        $router->get('/orders', $this->listOrders(...));
        $router->get('/orders/{id}', $this->getOrder(...));
    }

    private function createOrder(ServerRequestInterface $request): ResponseInterface
    {
        $body   = json_decode((string) $request->getBody(), true);
        $result = $this->validator->validate($body);

        if ($result['errors'] !== []) {
            return $this->problems->create(
                $request,
                'validation-failed',
                'Validation failed.',
                422,
                null,
                ['errors' => $result['errors']],
            );
        }

        /** @var array{customer: string, note: string, items: list<array{product_id: int, quantity: int, unit_price: float}>} $valid */
        $valid = $result;
        $order = $this->repo->create($valid['customer'], $valid['note'], $valid['items']);
        return $this->json->create($order->toArray(), 201);
    }

    private function listOrders(ServerRequestInterface $request): ResponseInterface
    {
        $orders = $this->repo->listAll();
        return $this->json->createList(array_map(static fn (Order $o) => $o->toArray(), $orders));
    }

    private function getOrder(ServerRequestInterface $request): ResponseInterface
    {
        $id    = (int) (Router::param($request, 'id') ?? '0');
        $order = $this->repo->findById($id);

        if ($order === null) {
            return $this->problems->create($request, 'not-found', 'Order not found.', 404);
        }

        return $this->json->create($order->toArray());
    }
}
