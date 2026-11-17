<?php

declare(strict_types=1);

namespace App\Application\Payment;

/** Um pagamento como o meio de pagamento o informa, consultado depois do webhook. */
final class GatewayPayment
{
    public const APPROVED     = 'approved';
    public const REJECTED     = 'rejected';
    public const CANCELLED    = 'cancelled';
    public const REFUNDED     = 'refunded';
    public const CHARGED_BACK = 'charged_back';

    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly string $externalReference,
        public readonly float $amount,
        public readonly string $statusDetail = '',
    ) {
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }

    /** Terminou sem dinheiro: o cliente precisa de uma nova tentativa (ou a saga cancela). */
    /** O dinheiro voltou ao cliente por fora deste serviço (estorno no painel, chargeback). */
    public function isReversed(): bool
    {
        return in_array($this->status, [self::REFUNDED, self::CHARGED_BACK], true);
    }

    public function isFailed(): bool
    {
        return in_array($this->status, [self::REJECTED, self::CANCELLED], true);
    }
}
