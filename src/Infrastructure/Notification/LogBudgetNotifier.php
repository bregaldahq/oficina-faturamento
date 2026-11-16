<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Application\Port\BudgetNotifierInterface;
use App\Application\Port\LoggerInterface;
use App\Domain\Budget\Budget;

/**
 * Aviso de orçamento pronto, registrado no log estruturado (e daí no New Relic). Trocar por
 * e-mail é implementar a mesma porta; o SMTP do serviço de OS serve de modelo.
 */
final class LogBudgetNotifier implements BudgetNotifierInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function budgetReady(Budget $budget): void
    {
        $this->logger->info('budget.sent', [
            'budget_id'      => $budget->getId(),
            'os_id'          => $budget->getOsId(),
            'saga_id'        => $budget->getSagaId(),
            'correlation_id' => $budget->getCorrelationId(),
            'customer_id'    => $budget->getCustomer()->id,
            'total_amount'   => $budget->total(),
            'items'          => count($budget->getItems()),
        ]);
    }
}
