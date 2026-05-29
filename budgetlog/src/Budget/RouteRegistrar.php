<?php

declare(strict_types=1);

namespace BudgetLog\Budget;

use Nene2\Http\JsonResponseFactory;
use Nene2\Http\QueryStringParser;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const int MAX_LIMIT = 100;

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly TransactionRepository $transactions,
        private readonly BudgetService $service,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/accounts', $this->listAccounts(...));
        $router->post('/accounts', $this->createAccount(...));
        $router->get('/accounts/{id}', $this->getAccount(...));
        $router->post('/accounts/{id}/transactions', $this->createTransaction(...));
        $router->get('/accounts/{id}/transactions', $this->listTransactions(...));
        $router->get('/accounts/{id}/summary', $this->summary(...));
        $router->post('/transfers', $this->transfer(...));
    }

    private function listAccounts(ServerRequestInterface $request): ResponseInterface
    {
        $owner = $this->owner($request);
        if ($owner === null) {
            return $this->unauthorized();
        }
        return $this->json->create(['accounts' => array_map($this->accountView(...), $this->accounts->listOwned($owner))]);
    }

    private function createAccount(ServerRequestInterface $request): ResponseInterface
    {
        $owner = $this->owner($request);
        if ($owner === null) {
            return $this->unauthorized();
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $errors = [];

        $name = $body['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            $errors[] = new ValidationError('name', 'name must be a non-empty string', 'invalid_value');
        }
        $initial = $body['initial_balance'] ?? 0;
        if (!is_int($initial) || $initial < 0) {
            $errors[] = new ValidationError('initial_balance', 'initial_balance must be a non-negative integer', 'out_of_range');
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        assert(is_string($name) && is_int($initial));

        $id = $this->accounts->create($owner, trim($name), $initial, $this->now());
        return $this->json->create($this->accountView((array) $this->accounts->findOwned($id, $owner)), 201);
    }

    private function getAccount(ServerRequestInterface $request): ResponseInterface
    {
        $owner = $this->owner($request);
        if ($owner === null) {
            return $this->unauthorized();
        }
        $id = $this->idParam($request, 'id');
        if ($id === null) {
            return $this->notFound();
        }
        $account = $this->accounts->findOwned($id, $owner);
        if ($account === null) {
            return $this->notFound();
        }
        return $this->json->create($this->accountView($account));
    }

    private function createTransaction(ServerRequestInterface $request): ResponseInterface
    {
        $owner = $this->owner($request);
        if ($owner === null) {
            return $this->unauthorized();
        }
        $accountId = $this->idParam($request, 'id');
        if ($accountId === null) {
            return $this->notFound();
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $errors = [];

        $amount = $body['amount'] ?? null;
        if (!is_int($amount) || $amount <= 0) {
            $errors[] = new ValidationError('amount', 'amount must be a positive integer', 'invalid_value');
        }
        $type = $body['type'] ?? null;
        if (!is_string($type) || !in_array($type, ['income', 'expense'], true)) {
            // 'transfer' is internal-only — not acceptable here.
            $errors[] = new ValidationError('type', 'type must be income or expense', 'invalid_value');
        }
        $category = $body['category'] ?? null;
        if (!is_string($category) || trim($category) === '') {
            $errors[] = new ValidationError('category', 'category must be a non-empty string', 'invalid_value');
        }
        $description = $body['description'] ?? '';
        if (!is_string($description)) {
            $errors[] = new ValidationError('description', 'description must be a string', 'invalid_type');
        }
        $recurring = $body['recurring'] ?? false;
        if (!is_bool($recurring)) {
            $errors[] = new ValidationError('recurring', 'recurring must be a boolean', 'invalid_type');
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        assert(is_string($type) && is_string($category) && is_string($description) && is_bool($recurring));

        $result = $this->service->record($owner, $accountId, $amount, $type, trim($category), $description, $recurring, $this->now());
        return match ($result) {
            'not_found' => $this->notFound(),
            'insufficient' => $this->json->create(['error' => 'Insufficient balance'], 422),
            default => $this->json->create($this->accountView((array) $this->accounts->findOwned($accountId, $owner)), 201),
        };
    }

    private function listTransactions(ServerRequestInterface $request): ResponseInterface
    {
        $owner = $this->owner($request);
        if ($owner === null) {
            return $this->unauthorized();
        }
        $accountId = $this->idParam($request, 'id');
        if ($accountId === null || $this->accounts->findOwned($accountId, $owner) === null) {
            return $this->notFound();
        }

        $filters = [
            'category' => QueryStringParser::string($request, 'category'),
            'min' => QueryStringParser::int($request, 'min_amount'),
            'max' => QueryStringParser::int($request, 'max_amount'),
            'recurring' => QueryStringParser::bool($request, 'recurring'),
        ];
        [$limit, $offset] = $this->pagination($request);

        return $this->json->create([
            'items' => array_map($this->txView(...), $this->transactions->listForAccount($accountId, $filters, $limit, $offset)),
            'total' => $this->transactions->countForAccount($accountId, $filters),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    private function summary(ServerRequestInterface $request): ResponseInterface
    {
        $owner = $this->owner($request);
        if ($owner === null) {
            return $this->unauthorized();
        }
        $accountId = $this->idParam($request, 'id');
        if ($accountId === null) {
            return $this->notFound();
        }
        $account = $this->accounts->findOwned($accountId, $owner);
        if ($account === null) {
            return $this->notFound();
        }

        return $this->json->create([
            'balance' => (int) $account['balance'],
            'income_by_category' => $this->totals($accountId, 'income'),
            'expense_by_category' => $this->totals($accountId, 'expense'),
        ]);
    }

    private function transfer(ServerRequestInterface $request): ResponseInterface
    {
        $owner = $this->owner($request);
        if ($owner === null) {
            return $this->unauthorized();
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $errors = [];

        $fromId = $body['from_account_id'] ?? null;
        $toId = $body['to_account_id'] ?? null;
        $amount = $body['amount'] ?? null;
        if (!is_int($fromId) || $fromId <= 0) {
            $errors[] = new ValidationError('from_account_id', 'from_account_id must be a positive integer', 'invalid_value');
        }
        if (!is_int($toId) || $toId <= 0) {
            $errors[] = new ValidationError('to_account_id', 'to_account_id must be a positive integer', 'invalid_value');
        }
        if (!is_int($amount) || $amount <= 0) {
            $errors[] = new ValidationError('amount', 'amount must be a positive integer', 'invalid_value');
        }
        if (is_int($fromId) && $fromId === $toId) {
            $errors[] = new ValidationError('to_account_id', 'cannot transfer to the same account', 'invalid_value');
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        // $fromId / $toId / $amount are already narrowed to int by the guards above.

        $description = is_string($body['description'] ?? null) ? $body['description'] : '';
        $result = $this->service->transfer($owner, $fromId, $toId, $amount, $description, $this->now());
        return match ($result) {
            'not_found' => $this->notFound(),
            'insufficient' => $this->json->create(['error' => 'Insufficient balance'], 422),
            default => $this->json->create([
                'from' => $this->accountView((array) $this->accounts->findOwned($fromId, $owner)),
                'to' => $this->accountView((array) $this->accounts->findOwned($toId, $owner)),
            ]),
        };
    }

    /** @return list<array{category: string, total: int}> */
    private function totals(int $accountId, string $type): array
    {
        return array_map(
            static fn (array $r): array => ['category' => (string) $r['category'], 'total' => (int) $r['total']],
            $this->transactions->categoryTotals($accountId, $type),
        );
    }

    private function owner(ServerRequestInterface $request): ?int
    {
        $raw = $request->getHeaderLine('X-User-Id');
        if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
            return null;
        }
        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }

    /** ReDoS-safe path id: digits only, length-capped. */
    private function idParam(ServerRequestInterface $request, string $key): ?int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $raw = (string) ($params[$key] ?? '');
        if (!ctype_digit($raw) || strlen($raw) > 18) {
            return null;
        }
        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }

    /** @return array{int, int} */
    private function pagination(ServerRequestInterface $request): array
    {
        $limit = QueryStringParser::int($request, 'limit', 20) ?? 20;
        $offset = QueryStringParser::int($request, 'offset', 0) ?? 0;
        return [max(1, min(self::MAX_LIMIT, $limit)), max(0, $offset)];
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function accountView(array $a): array
    {
        return [
            'id' => (int) $a['id'],
            'name' => (string) $a['name'],
            'balance' => (int) $a['balance'],
            'created_at' => (string) $a['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $t
     * @return array<string, mixed>
     */
    private function txView(array $t): array
    {
        return [
            'id' => (int) $t['id'],
            'amount' => (int) $t['amount'],
            'type' => (string) $t['type'],
            'category' => (string) $t['category'],
            'description' => (string) $t['description'],
            'recurring' => ((int) $t['recurring']) === 1,
            'created_at' => (string) $t['created_at'],
        ];
    }

    private function unauthorized(): ResponseInterface
    {
        return $this->json->create(['error' => 'Valid X-User-Id header required'], 401);
    }

    private function notFound(): ResponseInterface
    {
        return $this->json->create(['error' => 'Account not found'], 404);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
