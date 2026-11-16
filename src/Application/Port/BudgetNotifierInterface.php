<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Budget\Budget;

/** "Envio do orçamento para aprovação": avisa o cliente de que há um orçamento esperando por ele. */
interface BudgetNotifierInterface
{
    public function budgetReady(Budget $budget): void;
}
