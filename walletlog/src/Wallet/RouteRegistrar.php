<?php

declare(strict_types=1);

namespace WalletLog\Wallet;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    /** Server-side currency allow-list — never trust a user-supplied currency. */
    private const array CURRENCIES = ['USD', 'EUR', 'JPY'];

    public function __construct(
        private readonly WalletRepository $repo,
        private readonly WalletService $service,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/wallet', $this->handleBalances(...));
        $router->get('/wallet/transactions', $this->handleTransactions(...));
        $router->post('/wallet/deposit', $this->handleDeposit(...));
        $router->post('/wallet/withdraw', $this->handleWithdraw(...));
        $router->post('/wallet/transfer', $this->handleTransfer(...));
    }

    private function handleBalances(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->resolveUserId($request);
        if ($userId === null) {
            return $this->unauthorized();
        }
        return $this->json->create(['balances' => $this->repo->balances($userId)]);
    }

    private function handleTransactions(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->resolveUserId($request);
        if ($userId === null) {
            return $this->unauthorized();
        }
        $tx = array_map(
            static fn (array $t): array => [
                'id' => (int) $t['id'],
                'currency' => (string) $t['currency'],
                'amount' => (int) $t['amount'],
                'type' => (string) $t['type'],
                'counterparty_id' => $t['counterparty_id'] === null ? null : (int) $t['counterparty_id'],
                'created_at' => (string) $t['created_at'],
            ],
            $this->repo->transactions($userId),
        );
        return $this->json->create(['transactions' => $tx, 'count' => count($tx)]);
    }

    private function handleDeposit(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->resolveUserId($request);
        if ($userId === null) {
            return $this->unauthorized();
        }
        [$currency, $amount] = $this->parseCurrencyAmount($request);

        $this->repo->credit($userId, $currency, $amount, $this->now(), 'deposit');
        return $this->json->create($this->balanceOf($userId, $currency));
    }

    private function handleWithdraw(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->resolveUserId($request);
        if ($userId === null) {
            return $this->unauthorized();
        }
        [$currency, $amount] = $this->parseCurrencyAmount($request);

        if (!$this->repo->debit($userId, $currency, $amount, $this->now(), 'withdraw')) {
            return $this->json->create(['error' => 'Insufficient funds'], 409);
        }
        return $this->json->create($this->balanceOf($userId, $currency));
    }

    private function handleTransfer(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->resolveUserId($request);
        if ($userId === null) {
            return $this->unauthorized();
        }
        [$currency, $amount] = $this->parseCurrencyAmount($request);

        $body = (array) ($request->getParsedBody() ?? []);
        $to = $body['to_user_id'] ?? null;
        if (!is_int($to) || $to <= 0) {
            throw new ValidationException([new ValidationError('to_user_id', 'to_user_id must be a positive integer', 'invalid_value')]);
        }
        // Reject self-transfer before any DB work.
        if ($to === $userId) {
            throw new ValidationException([new ValidationError('to_user_id', 'cannot transfer to yourself', 'invalid_value')]);
        }

        $result = $this->service->transfer($userId, $to, $currency, $amount, $this->now());
        if ($result === 'insufficient') {
            return $this->json->create(['error' => 'Insufficient funds'], 409);
        }
        return $this->json->create($this->balanceOf($userId, $currency));
    }

    /**
     * Validate and extract currency + amount from the body.
     *
     * @return array{string, int}
     */
    private function parseCurrencyAmount(ServerRequestInterface $request): array
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $currency = $body['currency'] ?? null;
        $amount = $body['amount'] ?? null;

        $errors = [];
        if (!is_string($currency) || !in_array($currency, self::CURRENCIES, true)) {
            $errors[] = new ValidationError('currency', 'currency must be one of: ' . implode(', ', self::CURRENCIES), 'invalid_value');
        }
        if (!is_int($amount) || $amount <= 0) {
            $errors[] = new ValidationError('amount', 'amount must be a positive integer (minor units)', 'invalid_value');
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        assert(is_string($currency) && is_int($amount));

        return [$currency, $amount];
    }

    /** @return array<string, mixed> */
    private function balanceOf(int $userId, string $currency): array
    {
        foreach ($this->repo->balances($userId) as $b) {
            if ($b['currency'] === $currency) {
                return ['currency' => $currency, 'balance' => $b['balance']];
            }
        }
        return ['currency' => $currency, 'balance' => 0];
    }

    private function resolveUserId(ServerRequestInterface $request): ?int
    {
        $raw = $request->getHeaderLine('X-User-Id');
        if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
            return null;
        }
        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }

    private function unauthorized(): ResponseInterface
    {
        return $this->json->create(['error' => 'Valid X-User-Id header required'], 401);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
