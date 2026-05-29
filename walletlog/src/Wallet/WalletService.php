<?php

declare(strict_types=1);

namespace WalletLog\Wallet;

use Nene2\Database\DatabaseTransactionManagerInterface;

/**
 * Atomic transfer: debit the sender and credit the recipient in one
 * transaction. If the sender has insufficient funds the whole transfer rolls
 * back, leaving no partial ledger entries.
 */
final class WalletService
{
    public function __construct(
        private readonly DatabaseTransactionManagerInterface $tx,
    ) {
    }

    /**
     * @return 'ok'|'insufficient'
     */
    public function transfer(int $fromUserId, int $toUserId, string $currency, int $amount, string $now): string
    {
        try {
            $this->tx->transactional(function ($executor) use ($fromUserId, $toUserId, $currency, $amount, $now): void {
                // Repository instantiated with the transaction-scoped executor.
                $repo = new WalletRepository($executor);

                if (!$repo->debit($fromUserId, $currency, $amount, $now, 'transfer_out', $toUserId)) {
                    throw new InsufficientFundsException();
                }
                $repo->credit($toUserId, $currency, $amount, $now, 'transfer_in', $fromUserId);
            });
        } catch (InsufficientFundsException) {
            return 'insufficient';
        }

        return 'ok';
    }
}
