<?php

declare(strict_types=1);

namespace CqrsLog\Order;

use CqrsLog\Order\Command\OrderCommandHandler;
use CqrsLog\Order\Command\PlaceOrderCommand;
use CqrsLog\Order\Command\UpdateOrderStatusCommand;
use CqrsLog\Order\Query\GetOrderSummaryQuery;
use CqrsLog\Order\Query\ListOrderSummariesQuery;
use CqrsLog\Order\Query\OrderQueryHandler;
use Nene2\Database\DatabaseTransactionManagerInterface;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const array STATUSES = ['pending', 'paid', 'shipped', 'cancelled'];

    public function __construct(
        private readonly OrderCommandHandler $commands,
        private readonly OrderQueryHandler $queries,
        private readonly DatabaseTransactionManagerInterface $tx,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/orders', $this->placeOrder(...));            // write (command)
        $router->patch('/orders/{id}/status', $this->updateStatus(...)); // write (command)
        $router->get('/orders', $this->listOrders(...));             // read (query)
        $router->get('/orders/{id}', $this->getOrder(...));          // read (query)
    }

    // ── write side ──────────────────────────────────────────────────────────

    private function placeOrder(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $customer = $body['customer'] ?? null;
        if (!is_string($customer) || trim($customer) === '' || strlen($customer) > 200) {
            throw new ValidationException([new ValidationError('customer', 'customer must be a non-empty string', 'invalid_value')]);
        }
        $items = $this->parseItems($body['items'] ?? null);

        $now = $this->now();
        $command = new PlaceOrderCommand(trim($customer), $items);

        // Order + items written atomically; the command handler is rebuilt with the
        // transaction-scoped executor (IMP-18).
        $orderId = 0;
        $this->tx->transactional(function ($executor) use (&$orderId, $command, $now): void {
            $orderId = (new OrderCommandHandler($executor))->place($command, $now);
        });

        // Command then query: re-read via the read model for the response shape.
        $summary = $this->queries->get(new GetOrderSummaryQuery($orderId));
        if ($summary === null) {
            return $this->json->create(['error' => 'order vanished after write'], 500);
        }
        return $this->json->create($summary->toArray(), 201);
    }

    private function updateStatus(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $status = $body['status'] ?? null;
        if (!is_string($status) || !in_array($status, self::STATUSES, true)) {
            throw new ValidationException([new ValidationError('status', 'status must be one of pending|paid|shipped|cancelled', 'invalid_value')]);
        }
        if ($id === 0 || !$this->commands->updateStatus(new UpdateOrderStatusCommand($id, $status), $this->now())) {
            return $this->notFound();
        }
        $summary = $this->queries->get(new GetOrderSummaryQuery($id));
        if ($summary === null) {
            return $this->notFound();
        }
        return $this->json->create($summary->toArray());
    }

    // ── read side ────────────────────────────────────────────────────────────

    private function listOrders(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $status = null;
        if (array_key_exists('status', $params)) {
            $raw = $params['status'];
            if (!is_string($raw) || !in_array($raw, self::STATUSES, true)) {
                throw new ValidationException([new ValidationError('status', 'status filter must be a valid status', 'invalid_value')]);
            }
            $status = $raw;
        }
        $summaries = $this->queries->list(new ListOrderSummariesQuery($status));
        return $this->json->create([
            'data' => array_map(static fn (OrderSummary $s): array => $s->toArray(), $summaries),
            'total' => count($summaries),
        ]);
    }

    private function getOrder(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        $summary = $id === 0 ? null : $this->queries->get(new GetOrderSummaryQuery($id));
        if ($summary === null) {
            return $this->notFound();
        }
        return $this->json->create($summary->toArray());
    }

    /**
     * @return list<array{product: string, quantity: int, unit_price: int}>
     */
    private function parseItems(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
            throw new ValidationException([new ValidationError('items', 'items must be a non-empty JSON array', 'invalid_value')]);
        }
        $items = [];
        foreach ($raw as $idx => $item) {
            if (!is_array($item)) {
                throw new ValidationException([new ValidationError("items[{$idx}]", 'item must be an object', 'invalid_value')]);
            }
            $product = isset($item['product']) && is_string($item['product']) ? trim($item['product']) : '';
            // is_int rejects floats and numeric strings from JSON.
            $quantity = isset($item['quantity']) && is_int($item['quantity']) ? $item['quantity'] : 0;
            $unitPrice = isset($item['unit_price']) && is_int($item['unit_price']) ? $item['unit_price'] : -1;
            if ($product === '' || $quantity <= 0 || $unitPrice < 0) {
                throw new ValidationException([new ValidationError("items[{$idx}]", 'each item needs product, quantity>0, unit_price>=0', 'invalid_value')]);
            }
            $items[] = ['product' => $product, 'quantity' => $quantity, 'unit_price' => $unitPrice];
        }
        return $items;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM);
    }

    private function idParam(ServerRequestInterface $request): int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $raw = (string) ($params['id'] ?? '');
        return ctype_digit($raw) && strlen($raw) <= 18 ? (int) $raw : 0;
    }

    private function notFound(): ResponseInterface
    {
        return $this->json->create(['error' => 'order not found'], 404);
    }
}
