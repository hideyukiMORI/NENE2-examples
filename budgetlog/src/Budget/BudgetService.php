<?php

declare(strict_types=1);

namespace BudgetLog\Budget;

use Nene2\Database\DatabaseTransactionManagerInterface;

/**
 * All balance-changing operations run inside a transaction with an atomic,
 * sufficiency-guarded debit — closing the race / negative-balance holes the
 * FT244 howto's ATK-03 / ATK-09 documented.
 */
final class BudgetService
{
    public function __construct(
        private readonly DatabaseTransactionManagerInterface $tx,
    ) {
    }

    /**
     * Record an income/expense against an owned account.
     *
     * @return 'not_found'|'insufficient'|'ok'
     */
    public function record(int $ownerId, int $accountId, int $amount, string $type, string $category, string $description, bool $recurring, string $now): string
    {
        try {
            $this->tx->transactional(function ($executor) use ($ownerId, $accountId, $amount, $type, $category, $description, $recurring, $now): void {
                $accounts = new AccountRepository($executor);
                $transactions = new TransactionRepository($executor);

                if ($accounts->findOwned($accountId, $ownerId) === null) {
                    throw new AccountNotFoundException();
                }
                if ($type === 'expense') {
                    if (!$accounts->debit($accountId, $ownerId, $amount)) {
                        throw new InsufficientBalanceException();
                    }
                } else {
                    $accounts->credit($accountId, $ownerId, $amount);
                }
                $transactions->create($accountId, $amount, $type, $category, $description, $recurring, $now);
            });
        } catch (AccountNotFoundException) {
            return 'not_found';
        } catch (InsufficientBalanceException) {
            return 'insufficient';
        }

        return 'ok';
    }

    /**
     * Transfer between two accounts owned by the same user.
     *
     * @return 'not_found'|'insufficient'|'ok'
     */
    public function transfer(int $ownerId, int $fromId, int $toId, int $amount, string $description, string $now): string
    {
        try {
            $this->tx->transactional(function ($executor) use ($ownerId, $fromId, $toId, $amount, $description, $now): void {
                $accounts = new AccountRepository($executor);
                $transactions = new TransactionRepository($executor);

                if ($accounts->findOwned($fromId, $ownerId) === null || $accounts->findOwned($toId, $ownerId) === null) {
                    throw new AccountNotFoundException();
                }
                if (!$accounts->debit($fromId, $ownerId, $amount)) {
                    throw new InsufficientBalanceException();
                }
                $accounts->credit($toId, $ownerId, $amount);
                $transactions->create($fromId, $amount, 'transfer', 'transfer', $description, false, $now);
                $transactions->create($toId, $amount, 'transfer', 'transfer', $description, false, $now);
            });
        } catch (AccountNotFoundException) {
            return 'not_found';
        } catch (InsufficientBalanceException) {
            return 'insufficient';
        }

        return 'ok';
    }
}
