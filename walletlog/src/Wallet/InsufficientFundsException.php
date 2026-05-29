<?php

declare(strict_types=1);

namespace WalletLog\Wallet;

/** Thrown inside a transfer transaction to trigger rollback on insufficient funds. */
final class InsufficientFundsException extends \RuntimeException
{
}
