<?php

declare(strict_types=1);

namespace ExpenseLog\Expense;

use Nene2\Http\JsonResponseFactory;
use Nene2\Http\QueryStringParser;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const array CATEGORIES = [
        'food', 'transport', 'utilities', 'entertainment',
        'health', 'shopping', 'travel', 'other',
    ];
    private const int MAX_LIMIT = 100;

    public function __construct(
        private readonly ExpenseRepository $repo,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/expenses', $this->listExpenses(...));
        $router->post('/expenses', $this->createExpense(...));
        $router->get('/expenses/summary', $this->summary(...));
        $router->get('/expenses/{id}', $this->getExpense(...));
        $router->patch('/expenses/{id}', $this->patchExpense(...));
        $router->delete('/expenses/{id}', $this->deleteExpense(...));
    }

    private function listExpenses(ServerRequestInterface $request): ResponseInterface
    {
        $from = $this->validatedDate($request, 'from');
        $to = $this->validatedDate($request, 'to');
        $category = $this->validatedCategoryFilter($request);
        [$limit, $offset] = $this->pagination($request);

        return $this->json->create([
            'items' => array_map($this->view(...), $this->repo->findAll($from, $to, $category, $limit, $offset)),
            'total' => $this->repo->count($from, $to, $category),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    private function createExpense(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $errors = [];

        $date = $this->parseDate($body['date'] ?? null, 'date', $errors);
        $amount = $this->parseAmount($body['amount'] ?? null, $errors);
        $category = $this->parseCategory($body['category'] ?? null, $errors);
        $note = $body['note'] ?? '';
        if (!is_string($note)) {
            $errors[] = new ValidationError('note', 'note must be a string', 'invalid_type');
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        assert(is_string($date) && is_int($amount) && is_string($category) && is_string($note));

        $id = $this->repo->create($date, $amount, $category, $note, $this->now());
        return $this->json->create($this->view((array) $this->repo->findById($id)), 201);
    }

    private function summary(ServerRequestInterface $request): ResponseInterface
    {
        $from = $this->validatedDate($request, 'from');
        $to = $this->validatedDate($request, 'to');
        $summary = array_map(
            static fn (array $r): array => [
                'month' => (string) $r['month'],
                'category' => (string) $r['category'],
                'total' => (int) $r['total'],
                'count' => (int) $r['count'],
            ],
            $this->repo->summary($from, $to),
        );
        return $this->json->create(['summary' => $summary]);
    }

    private function getExpense(ServerRequestInterface $request): ResponseInterface
    {
        $expense = $this->repo->findById($this->idParam($request));
        if ($expense === null) {
            return $this->notFound();
        }
        return $this->json->create($this->view($expense));
    }

    private function patchExpense(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        $existing = $this->repo->findById($id);
        if ($existing === null) {
            return $this->notFound();
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $errors = [];

        $date = null;
        if (array_key_exists('date', $body)) {
            $date = $this->parseDate($body['date'], 'date', $errors);
        }
        $amount = null;
        if (array_key_exists('amount', $body)) {
            $amount = $this->parseAmount($body['amount'], $errors);
        }
        $category = null;
        if (array_key_exists('category', $body)) {
            $category = $this->parseCategory($body['category'], $errors);
        }
        $note = null;
        if (array_key_exists('note', $body)) {
            if (!is_string($body['note'])) {
                $errors[] = new ValidationError('note', 'note must be a string', 'invalid_type');
            } else {
                $note = $body['note'];
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->repo->update($id, $existing, $date, $amount, $category, $note, $this->now());
        return $this->json->create($this->view((array) $this->repo->findById($id)));
    }

    private function deleteExpense(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->repo->delete($this->idParam($request))) {
            return $this->notFound();
        }
        return $this->json->createEmpty(204);
    }

    // ── validation helpers ────────────────────────────────────────────────

    /**
     * @param list<ValidationError> $errors
     */
    private function parseDate(mixed $value, string $field, array &$errors): ?string
    {
        if (!is_string($value) || !$this->isCanonicalDate($value)) {
            $errors[] = new ValidationError($field, $field . ' must be a valid YYYY-MM-DD date', 'invalid_value');
            return null;
        }
        return $value;
    }

    /**
     * @param list<ValidationError> $errors
     */
    private function parseAmount(mixed $value, array &$errors): ?int
    {
        if (!is_int($value) || $value <= 0) {
            $errors[] = new ValidationError('amount', 'amount must be a positive integer (cents)', 'invalid_value');
            return null;
        }
        return $value;
    }

    /**
     * @param list<ValidationError> $errors
     */
    private function parseCategory(mixed $value, array &$errors): ?string
    {
        if (!is_string($value) || !in_array($value, self::CATEGORIES, true)) {
            $errors[] = new ValidationError('category', 'category must be one of: ' . implode(', ', self::CATEGORIES), 'invalid_value');
            return null;
        }
        return $value;
    }

    private function validatedDate(ServerRequestInterface $request, string $key): ?string
    {
        $raw = QueryStringParser::string($request, $key);
        if ($raw !== null && !$this->isCanonicalDate($raw)) {
            throw new ValidationException([new ValidationError($key, $key . ' must be YYYY-MM-DD', 'invalid_value')]);
        }
        return $raw;
    }

    private function validatedCategoryFilter(ServerRequestInterface $request): ?string
    {
        $raw = QueryStringParser::string($request, 'category');
        if ($raw !== null && !in_array($raw, self::CATEGORIES, true)) {
            throw new ValidationException([new ValidationError('category', 'invalid category', 'invalid_value')]);
        }
        return $raw;
    }

    /** Round-trip parse guarantees a canonical ISO date string. */
    private function isCanonicalDate(string $value): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value;
    }

    /** @return array{int, int} */
    private function pagination(ServerRequestInterface $request): array
    {
        $limit = QueryStringParser::int($request, 'limit', 20) ?? 20;
        $offset = QueryStringParser::int($request, 'offset', 0) ?? 0;
        return [max(1, min(self::MAX_LIMIT, $limit)), max(0, $offset)];
    }

    /**
     * @param array<string, mixed> $e
     * @return array<string, mixed>
     */
    private function view(array $e): array
    {
        return [
            'id' => (int) $e['id'],
            'date' => (string) $e['date'],
            'amount' => (int) $e['amount'],
            'category' => (string) $e['category'],
            'note' => (string) $e['note'],
            'created_at' => (string) $e['created_at'],
            'updated_at' => (string) $e['updated_at'],
        ];
    }

    private function idParam(ServerRequestInterface $request): int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        return (int) ($params['id'] ?? 0);
    }

    private function notFound(): ResponseInterface
    {
        return $this->json->create(['error' => 'Expense not found'], 404);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
