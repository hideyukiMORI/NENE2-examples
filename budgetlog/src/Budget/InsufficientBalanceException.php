<?php

declare(strict_types=1);

namespace BudgetLog\Budget;

/** Internal control-flow exception used to roll back a transaction. */
final class InsufficientBalanceException extends \RuntimeException
{
}
