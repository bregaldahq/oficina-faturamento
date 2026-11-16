<?php

declare(strict_types=1);

namespace App\Domain\Budget;

enum PaymentStatus: string
{
    /** Cobrança criada no Mercado Pago, esperando o cliente pagar. */
    case Pending   = 'PENDING';
    case Confirmed = 'CONFIRMED';
    case Failed    = 'FAILED';
    case Refunded  = 'REFUNDED';
    /** O orçamento foi cancelado antes de o cliente pagar: a cobrança não vale mais. */
    case Expired = 'EXPIRED';
}
