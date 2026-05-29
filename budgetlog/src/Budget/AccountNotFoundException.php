<?php

declare(strict_types=1);

namespace BudgetLog\Budget;

/** Internal control-flow exceptions used to roll back a transaction. */
final class AccountNotFoundException extends \RuntimeException
{
}
