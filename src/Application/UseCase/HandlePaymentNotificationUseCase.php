<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Application\Messaging\MessageFactory;
use App\Application\Messaging\OutboxRelay;
use App\Application\Payment\GatewayPayment;
use App\Application\Port\BudgetRepositoryInterface;
use App\Application\Port\ClockInterface;
use App\Application\Port\LoggerInterface;
use App\Application\Port\PaymentGatewayInterface;
use App\Domain\Budget\Budget;
use App\Domain\Budget\PaymentStatus;
use App\Domain\Exception\DuplicateMessageException;

/**
 * O que fazer quando o meio de pagamento avisa que um pagamento mudou (ADR-015).
 *
 * O aviso só diz qual pagamento olhar: o estado vem da consulta ao meio de pagamento, nunca do
 * corpo do webhook. A mesma notificação chega várias vezes; o par (pagamento, estado) na inbox
 * garante um efeito só.
 *
 * A regra do dinheiro: **todo pagamento aprovado ou faz a OS avançar, ou é estornado.** Nunca
 * fica parado. Um orçamento pode receber vários pagamentos (o cliente tenta de novo depois de
 * uma recusa, paga duas vezes, paga depois do prazo); só o primeiro que serve é aceito, e cada
 * outro volta com uma chave de estorno própria (`late-<id do pagamento>`).
 */
final class HandlePaymentNotificationUseCase
{
    public const REASON_AMOUNT_MISMATCH = 'AMOUNT_MISMATCH';

    public function __construct(
        private readonly BudgetRepositoryInterface $budgets,
        private readonly PaymentGatewayInterface $gateway,
        private readonly MessageFactory $messages,
        private readonly ClockInterface $clock,
        private readonly ?OutboxRelay $relay = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function execute(string $externalPaymentId): string
    {
        $payment = $this->gateway->getPayment($externalPaymentId);
        $budget  = $this->budgets->find($payment->externalReference);
        if ($budget === null) {
            return HandleIncomingMessageUseCase::OUTCOME_IGNORED;
        }

        $inbox = "mp:{$payment->id}:{$payment->status}";

        try {
            $outcome = match (true) {
                $payment->isApproved() => $this->approved($budget, $payment, $inbox),
                $payment->isFailed()   => $this->failed($budget, $payment, $inbox),
                $payment->isReversed() => $this->reversed($budget, $payment, $inbox),
                // pendente, em análise: o próximo aviso decide
                default => HandleIncomingMessageUseCase::OUTCOME_IGNORED,
            };
        } catch (DuplicateMessageException) {
            return HandleIncomingMessageUseCase::OUTCOME_DUPLICATE;
        }

        $this->relay?->relay();

        return $outcome;
    }

    private function approved(Budget $budget, GatewayPayment $payment, string $inbox): string
    {
        $now     = $this->clock->now();
        $current = $budget->getPayment();

        if ($current?->externalPaymentId === $payment->id && in_array($current->status, [PaymentStatus::Confirmed, PaymentStatus::Refunded], true)) {
            return HandleIncomingMessageUseCase::OUTCOME_DUPLICATE;
        }

        if ($budget->canReceivePayment() && $budget->matchesTotal($payment->amount)) {
            $budget->confirmPayment($payment->id, $payment->amount, $now);
            $this->budgets->save($budget, $this->messages->fromBudget($budget), $inbox);

            return HandleIncomingMessageUseCase::OUTCOME_OK;
        }

        // Daqui para baixo o pagamento não serve: o dinheiro volta. A chave é do pagamento, então
        // o mesmo estorno pedido duas vezes (aviso repetido, save que falhou) é um estorno só.
        $this->gateway->refund($payment->id, 'late-' . $payment->id);

        if ($budget->canReceivePayment()) {
            // Valor diferente do total: a cobrança fica recusada e a saga decide (§6.2).
            $budget->failPayment(self::REASON_AMOUNT_MISMATCH, $payment->id, $now);
            $this->budgets->save($budget, $this->messages->fromBudget($budget), $inbox);
        } elseif ($budget->needsRefundOnLatePayment()) {
            // Pagou depois do cancelamento: registra como o pagamento desta cobrança.
            $budget->recordLateRefund($payment->id, $now);
            $this->budgets->save($budget, $this->messages->fromBudget($budget), $inbox);
        } else {
            // Pagamento a mais (o orçamento já foi pago, ou o dinheiro já voltou): o serviço de OS
            // não precisa saber; fica na inbox e no log.
            $this->budgets->record([], $inbox);
            $this->logger?->info('payment.extra_refunded', [
                'budget_id'           => $budget->getId(),
                'os_id'               => $budget->getOsId(),
                'external_payment_id' => $payment->id,
            ]);
        }

        return HandleIncomingMessageUseCase::OUTCOME_OK;
    }

    private function failed(Budget $budget, GatewayPayment $payment, string $inbox): string
    {
        if ($budget->getPayment()?->status !== PaymentStatus::Pending || !$budget->canReceivePayment()) {
            // Já recusado, já pago ou encerrado: outra recusa não muda nada.
            return HandleIncomingMessageUseCase::OUTCOME_DUPLICATE;
        }

        $budget->failPayment($payment->statusDetail !== '' ? $payment->statusDetail : $payment->status, $payment->id, $this->clock->now());
        $this->budgets->save($budget, $this->messages->fromBudget($budget), $inbox);

        return HandleIncomingMessageUseCase::OUTCOME_OK;
    }

    /** Estorno ou chargeback feito por fora do serviço, sobre o pagamento que ele aceitou. */
    private function reversed(Budget $budget, GatewayPayment $payment, string $inbox): string
    {
        $current = $budget->getPayment();
        if ($current?->externalPaymentId !== $payment->id || $current->status !== PaymentStatus::Confirmed) {
            // O estorno que o próprio serviço fez, ou um pagamento que ele não aceitou.
            return HandleIncomingMessageUseCase::OUTCOME_DUPLICATE;
        }

        $budget->recordExternalRefund($this->clock->now());
        $this->budgets->save($budget, $this->messages->fromBudget($budget), $inbox);

        return HandleIncomingMessageUseCase::OUTCOME_OK;
    }
}
