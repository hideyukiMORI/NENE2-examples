<?php

declare(strict_types=1);

namespace PriceLog\Price;

use Nene2\Database\DatabaseTransactionManagerInterface;

/**
 * Opening a new price tier closes the current open tier and inserts the new
 * one. Both run inside one transaction so two concurrent setPrice calls can't
 * leave two open tiers (the race the FT228 howto flagged as ATK-10).
 */
final class PriceService
{
    public function __construct(
        private readonly DatabaseTransactionManagerInterface $tx,
    ) {
    }

    public function setPrice(int $productId, int $amount, string $currency, string $effectiveFrom, string $now): void
    {
        $this->tx->transactional(function ($executor) use ($productId, $amount, $currency, $effectiveFrom, $now): void {
            // Close any open tier that started at or before the new tier.
            $executor->execute(
                'UPDATE price_tiers SET effective_to = ?
                 WHERE product_id = ? AND effective_to IS NULL AND effective_from <= ?',
                [$effectiveFrom, $productId, $effectiveFrom],
            );
            $executor->execute(
                'INSERT INTO price_tiers (product_id, amount, currency, effective_from, effective_to, created_at)
                 VALUES (?, ?, ?, ?, NULL, ?)',
                [$productId, $amount, $currency, $effectiveFrom, $now],
            );
        });
    }
}
