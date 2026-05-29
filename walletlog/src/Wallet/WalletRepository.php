<?php

declare(strict_types=1);

namespace WalletLog\Wallet;

use Nene2\Database\DatabaseQueryExecutorInterface;

class WalletRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    public function ensureWallet(int $userId, string $currency, string $now): void
    {
        $this->db->execute(
            'INSERT OR IGNORE INTO wallets (user_id, currency, balance, created_at, updated_at)
             VALUES (?, ?, 0, ?, ?)',
            [$userId, $currency, $now, $now],
        );
    }

    /** Credit a wallet (creating it if needed) and append a ledger row. */
    public function credit(int $userId, string $currency, int $amount, string $now, string $type, ?int $counterparty = null): void
    {
        $this->ensureWallet($userId, $currency, $now);
        $this->db->execute(
            'UPDATE wallets SET balance = balance + ?, updated_at = ? WHERE user_id = ? AND currency = ?',
            [$amount, $now, $userId, $currency],
        );
        $this->log($userId, $currency, $amount, $type, $counterparty, $now);
    }

    /**
     * Atomically debit a wallet only if it has sufficient funds.
     * Returns false (no change, no log) when the balance is insufficient.
     */
    public function debit(int $userId, string $currency, int $amount, string $now, string $type, ?int $counterparty = null): bool
    {
        $affected = $this->db->execute(
            'UPDATE wallets SET balance = balance - ?, updated_at = ?
             WHERE user_id = ? AND currency = ? AND balance >= ?',
            [$amount, $now, $userId, $currency, $amount],
        );
        if ($affected === 0) {
            return false;
        }
        $this->log($userId, $currency, -$amount, $type, $counterparty, $now);
        return true;
    }

    /** @return list<array{currency: string, balance: int}> */
    public function balances(int $userId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->fetchAll(
            'SELECT currency, balance FROM wallets WHERE user_id = ? ORDER BY currency ASC',
            [$userId],
        );
        return array_map(
            static fn (array $r): array => ['currency' => (string) $r['currency'], 'balance' => (int) $r['balance']],
            $rows,
        );
    }

    /** @return list<array<string, mixed>> own ledger only (IDOR-safe) */
    public function transactions(int $userId): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, currency, amount, type, counterparty_id, created_at
             FROM wallet_transactions WHERE user_id = ? ORDER BY id ASC',
            [$userId],
        );
    }

    private function log(int $userId, string $currency, int $amount, string $type, ?int $counterparty, string $now): void
    {
        $this->db->execute(
            'INSERT INTO wallet_transactions (user_id, currency, amount, type, counterparty_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $currency, $amount, $type, $counterparty, $now],
        );
    }
}
