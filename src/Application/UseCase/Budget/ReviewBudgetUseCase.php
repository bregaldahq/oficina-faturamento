<?php

declare(strict_types=1);

namespace App\Application\UseCase\Budget;

use App\Application\Messaging\MessageFactory;
use App\Application\Messaging\OutboxRelay;
use App\Application\Port\BudgetRepositoryInterface;
use App\Application\Port\ClockInterface;
use App\Domain\Budget\Budget;
use App\Domain\Budget\BudgetStatus;
use App\Domain\Exception\NotFoundException;

/**
 * A resposta do cliente ao orçamento, que chega pelo webhook de aprovação. Repetir a mesma
 * resposta não muda nada; mudar de ideia depois de responder é recusado.
 */
final class ReviewBudgetUseCase
{
    public function __construct(
        private readonly BudgetRepositoryInterface $budgets,
        private readonly MessageFactory $messages,
        private readonly ClockInterface $clock,
        private readonly ?OutboxRelay $relay = null,
    ) {
    }

    public function execute(string $budgetId, bool $approved): Budget
    {
        $budget = $this->budgets->find($budgetId);
        if ($budget === null) {
            throw new NotFoundException('Orçamento não encontrado.');
        }

        $already = $approved
            ? in_array($budget->getStatus(), [BudgetStatus::Approved, BudgetStatus::Paid], true)
            : $budget->getStatus() === BudgetStatus::Rejected;
        if ($already) {
            return $budget;
        }

        $approved ? $budget->approve($this->clock->now()) : $budget->reject($this->clock->now());
        $this->budgets->save($budget, $this->messages->fromBudget($budget));
        $this->relay?->relay();

        return $budget;
    }
}
