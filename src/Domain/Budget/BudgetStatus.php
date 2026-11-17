<?php

declare(strict_types=1);

namespace App\Domain\Budget;

/**
 *   AWAITING_APPROVAL → APPROVED → PAID
 *   AWAITING_APPROVAL → REJECTED
 *   AWAITING_APPROVAL, APPROVED, PAID → CANCELLED   (compensação pedida pela saga)
 *   FAILED: o orçamento não pôde ser gerado (item inexistente, estoque insuficiente)
 */
enum BudgetStatus: string
{
    case AwaitingApproval = 'AWAITING_APPROVAL';
    case Approved         = 'APPROVED';
    case Paid             = 'PAID';
    case Rejected         = 'REJECTED';
    case Cancelled        = 'CANCELLED';
    case Failed           = 'FAILED';

    /** Terminou sem ficar com peça nem dinheiro: cancelar de novo não tem o que desfazer. */
    public function isClosed(): bool
    {
        return in_array($this, [self::Rejected, self::Cancelled, self::Failed], true);
    }
}
