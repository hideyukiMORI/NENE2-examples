<?php

declare(strict_types=1);

namespace AggLog\Agg;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const int MAX_LIMIT = 100;
    private const array VALID_STATUSES = ['pending', 'completed', 'refunded', 'cancelled'];

    public function __construct(
        private readonly OrderRepository $repo,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/orders', $this->handleCreate(...));
        $router->get('/reports/summary', $this->handleSummary(...));
        $router->get('/reports/daily', $this->handleDaily(...));
        $router->get('/reports/by-status', $this->handleByStatus(...));
        $router->get('/reports/top-items', $this->handleTopItems(...));
    }

    private function handleCreate(ServerRequestInterface $request): ResponseInterface
    {
        $body   = (array) ($request->getParsedBody() ?? []);
        $errors = [];

        $customerId = isset($body['customer_id']) && is_string($body['customer_id'])
            ? trim($body['customer_id']) : '';
        if ($customerId === '') {
            $errors[] = new ValidationError('customer_id', 'customer_id is required', 'required');
        }

        $itemName = isset($body['item_name']) && is_string($body['item_name'])
            ? trim($body['item_name']) : '';
        if ($itemName === '') {
            $errors[] = new ValidationError('item_name', 'item_name is required', 'required');
        }

        $amount = 0;
        if (!isset($body['amount']) || !is_numeric($body['amount'])) {
            $errors[] = new ValidationError('amount', 'amount must be a positive integer', 'invalid');
        } else {
            $amount = (int) $body['amount'];
            if ($amount <= 0) {
                $errors[] = new ValidationError('amount', 'amount must be positive', 'invalid');
            }
        }

        $status = isset($body['status']) && is_string($body['status'])
            ? trim($body['status']) : 'pending';
        if (!in_array($status, self::VALID_STATUSES, true)) {
            $errors[] = new ValidationError('status', 'invalid status', 'invalid');
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $now = $this->now();
        $this->repo->create($customerId, $itemName, $amount, $status, $now);
        return $this->json->create(['status' => 'created'], 201);
    }

    private function handleSummary(ServerRequestInterface $request): ResponseInterface
    {
        [$from, $to, $errors] = $this->parseDateRange($request->getQueryParams());
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $data = $this->repo->summary($from, $to);
        return $this->json->create(array_merge($data, ['from' => $from, 'to' => $to]));
    }

    private function handleDaily(ServerRequestInterface $request): ResponseInterface
    {
        [$from, $to, $errors] = $this->parseDateRange($request->getQueryParams());
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $rows = $this->repo->dailyBreakdown($from, $to);
        return $this->json->create(['days' => $rows, 'count' => count($rows), 'from' => $from, 'to' => $to]);
    }

    private function handleByStatus(ServerRequestInterface $request): ResponseInterface
    {
        [$from, $to, $errors] = $this->parseDateRange($request->getQueryParams());
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $rows = $this->repo->byStatus($from, $to);
        return $this->json->create(['statuses' => $rows, 'count' => count($rows)]);
    }

    private function handleTopItems(ServerRequestInterface $request): ResponseInterface
    {
        $q      = $request->getQueryParams();
        $errors = [];

        [$from, $to, $dateErrors] = $this->parseDateRange($q);
        $errors = array_merge($errors, $dateErrors);

        $limit = self::MAX_LIMIT;
        if (isset($q['limit'])) {
            if (!is_numeric($q['limit'])) {
                $errors[] = new ValidationError('limit', 'limit must be a positive integer', 'invalid');
            } else {
                $limit = min((int) $q['limit'], self::MAX_LIMIT);
                if ($limit <= 0) {
                    $errors[] = new ValidationError('limit', 'limit must be positive', 'invalid');
                }
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $rows = $this->repo->topItems($from, $to, $limit);
        return $this->json->create(['items' => $rows, 'count' => count($rows), 'limit' => $limit]);
    }

    /**
     * @param array<string, mixed> $q
     * @return array{0: string|null, 1: string|null, 2: list<ValidationError>}
     */
    private function parseDateRange(array $q): array
    {
        $errors = [];
        $from   = null;
        $to     = null;

        if (isset($q['from']) && is_string($q['from']) && $q['from'] !== '') {
            if (!$this->isValidDate($q['from'])) {
                $errors[] = new ValidationError('from', 'from must be a valid date (YYYY-MM-DD)', 'invalid');
            } else {
                $from = $q['from'];
            }
        }

        if (isset($q['to']) && is_string($q['to']) && $q['to'] !== '') {
            if (!$this->isValidDate($q['to'])) {
                $errors[] = new ValidationError('to', 'to must be a valid date (YYYY-MM-DD)', 'invalid');
            } else {
                $to = $q['to'];
            }
        }

        if ($from !== null && $to !== null && $from > $to) {
            $errors[] = new ValidationError('from', 'from must be before or equal to to', 'invalid');
        }

        return [$from, $to, $errors];
    }

    private function isValidDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
