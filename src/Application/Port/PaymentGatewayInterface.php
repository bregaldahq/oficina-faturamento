<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\Payment\Charge;
use App\Application\Payment\GatewayPayment;
use App\Domain\Budget\Budget;

/**
 * O meio de pagamento (ADR-015). Toda chamada de escrita leva uma chave de idempotência derivada
 * do orçamento: uma reentrega da mensagem não cria duas cobranças nem dois estornos.
 */
interface PaymentGatewayInterface
{
    public function createCharge(Budget $budget, \DateTimeImmutable $expiresAt): Charge;

    /** O webhook nunca é fonte de verdade: o pagamento é sempre consultado aqui. */
    public function getPayment(string $externalPaymentId): GatewayPayment;

    public function refund(string $externalPaymentId, string $idempotencyKey): void;

    /** A notificação veio mesmo do meio de pagamento? */
    public function isAuthentic(string $dataId, string $requestId, string $signature): bool;
}
