<?php

declare(strict_types=1);

namespace App\Domain\Budget;

use App\Domain\Exception\DomainException;
use App\Domain\Exception\InvalidBudgetTransitionException;

/**
 * O orçamento de uma ordem de serviço, com a cobrança dentro dele: um por OS.
 *
 * Cada transição é um método nomeado (ADR-003): não existe setStatus. Toda mudança que mexe no
 * estoque registra um StockMovement, e toda mudança que o serviço de OS precisa saber registra
 * um evento (docs/fase-4/CONTRATOS.md §5.3, no oficina-api). Os dois são gravados pelo
 * repositório na mesma transação do orçamento (ADR-004 e ADR-017).
 */
final class Budget
{
    /** Diferença tolerada entre o valor pago e o total, em reais (arredondamento). */
    private const AMOUNT_TOLERANCE = 0.01;

    /** @var list<StockMovement> */
    private array $movements = [];

    /** @var list<array{type: string, payload: array<string, mixed>}> */
    private array $pendingEvents = [];

    /** @param list<BudgetItem> $items */
    private function __construct(
        private readonly string $id,
        private readonly string $osId,
        private readonly string $sagaId,
        private readonly string $correlationId,
        private readonly Customer $customer,
        private readonly array $items,
        private BudgetStatus $status,
        private ?Payment $payment,
        private ?string $failureReason,
        private readonly \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $updatedAt,
        private readonly int $version,
    ) {
    }

    /**
     * O orçamento nasce aguardando a aprovação do cliente, com as peças reservadas.
     *
     * @param list<BudgetItem> $items
     */
    public static function generate(
        string $id,
        string $osId,
        string $sagaId,
        string $correlationId,
        Customer $customer,
        array $items,
        \DateTimeImmutable $now,
    ): self {
        if ($items === []) {
            throw new DomainException('O orçamento precisa de pelo menos um item.');
        }

        $budget = new self($id, $osId, $sagaId, $correlationId, $customer, $items, BudgetStatus::AwaitingApproval, null, null, $now, $now, 0);
        foreach ($budget->parts() as $item) {
            $budget->movements[] = new StockMovement(StockMovement::RESERVE, $item->itemId, $item->quantity);
        }
        $budget->emit('budget.generated', ['budget_id' => $id, 'total_amount' => $budget->total()]);

        return $budget;
    }

    /**
     * O orçamento não pôde ser gerado. Fica registrado (para um comando repetido não tentar de
     * novo) e o serviço de OS é avisado com o motivo do contrato.
     */
    public static function failed(
        string $id,
        string $osId,
        string $sagaId,
        string $correlationId,
        Customer $customer,
        string $reason,
        \DateTimeImmutable $now,
    ): self {
        $budget = new self($id, $osId, $sagaId, $correlationId, $customer, [], BudgetStatus::Failed, null, $reason, $now, $now, 0);
        $budget->emit('budget.failed', ['reason' => $reason]);

        return $budget;
    }

    /**
     * Hidratação a partir do banco (ADR-005): não valida transição nem gera evento ou movimento.
     *
     * @param list<BudgetItem> $items
     */
    public static function reconstitute(
        string $id,
        string $osId,
        string $sagaId,
        string $correlationId,
        Customer $customer,
        array $items,
        BudgetStatus $status,
        ?Payment $payment,
        ?string $failureReason,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
        int $version,
    ): self {
        return new self($id, $osId, $sagaId, $correlationId, $customer, $items, $status, $payment, $failureReason, $createdAt, $updatedAt, $version);
    }

    public function approve(\DateTimeImmutable $now): void
    {
        $this->transition(BudgetStatus::AwaitingApproval, BudgetStatus::Approved, 'aprovar', $now);
        $this->emit('budget.approved', ['budget_id' => $this->id]);
    }

    /** O cliente recusou: as peças voltam a ficar disponíveis na hora. */
    public function reject(\DateTimeImmutable $now): void
    {
        $this->transition(BudgetStatus::AwaitingApproval, BudgetStatus::Rejected, 'rejeitar', $now);
        $this->releaseParts(StockMovement::RELEASE);
        $this->emit('budget.rejected', ['budget_id' => $this->id]);
    }

    /** A cobrança foi criada no meio de pagamento: o link vai para o serviço de OS. */
    public function attachPayment(Payment $payment, \DateTimeImmutable $now): void
    {
        if ($this->status !== BudgetStatus::Approved) {
            throw InvalidBudgetTransitionException::from($this->status->value, 'cobrar');
        }
        if ($this->payment !== null) {
            throw new DomainException('O orçamento já tem uma cobrança.');
        }

        $this->payment   = $payment;
        $this->updatedAt = $now;
        $this->emit('payment.created', ['payment_id' => $payment->id, 'payment_url' => $payment->paymentUrl]);
    }

    /**
     * Ainda dá para receber: o orçamento está aprovado e a cobrança, pendente ou recusada. Recusa
     * não encerra a cobrança: no Checkout Pro o cliente tenta de novo no mesmo link, e cada
     * tentativa é um pagamento novo no Mercado Pago.
     */
    public function canReceivePayment(): bool
    {
        return $this->status === BudgetStatus::Approved
            && in_array($this->payment?->status, [PaymentStatus::Pending, PaymentStatus::Failed], true);
    }

    public function matchesTotal(float $amount): bool
    {
        return abs($amount - $this->total()) <= self::AMOUNT_TOLERANCE;
    }

    /** O meio de pagamento confirmou: as peças saem do estoque e a OS pode ir para o reparo. */
    public function confirmPayment(string $externalPaymentId, float $amount, \DateTimeImmutable $now): void
    {
        $payment = $this->receivablePayment('confirmar o pagamento');
        if (!$this->matchesTotal($amount)) {
            throw new DomainException(sprintf('Valor pago (%.2f) diferente do total do orçamento (%.2f).', $amount, $this->total()));
        }

        $this->payment   = $payment->with(PaymentStatus::Confirmed, $externalPaymentId);
        $this->status    = BudgetStatus::Paid;
        $this->updatedAt = $now;
        $this->releaseParts(StockMovement::CONSUME);
        $this->emit('payment.confirmed', ['payment_id' => $payment->id, 'amount' => $this->total()]);
    }

    public function failPayment(string $reason, ?string $externalPaymentId, \DateTimeImmutable $now): void
    {
        $payment         = $this->receivablePayment('registrar a recusa do pagamento');
        $this->payment   = $payment->with(PaymentStatus::Failed, $externalPaymentId, $reason);
        $this->updatedAt = $now;
        $this->emit('payment.failed', ['payment_id' => $payment->id, 'reason' => $reason]);
    }

    /**
     * Compensação: o estorno já foi feito no meio de pagamento; aqui ele fica registrado e o
     * serviço de OS é avisado. Sem pagamento confirmado não há o que estornar, e a confirmação
     * sai do mesmo jeito (docs/fase-4/CONTRATOS.md §6.2).
     */
    public function recordRefund(\DateTimeImmutable $now): void
    {
        if ($this->payment?->status !== PaymentStatus::Confirmed) {
            // Não havia o que estornar: confirma com payment_id nulo (§5.3).
            $this->emit('payment.refunded', ['payment_id' => null]);

            return;
        }

        $this->payment   = $this->payment->with(PaymentStatus::Refunded);
        $this->updatedAt = $now;
        $this->emit('payment.refunded', ['payment_id' => $this->payment->id]);
    }

    /** O dinheiro voltou por fora do serviço (estorno no painel do Mercado Pago, chargeback). */
    public function recordExternalRefund(\DateTimeImmutable $now): void
    {
        if ($this->payment?->status !== PaymentStatus::Confirmed) {
            throw InvalidBudgetTransitionException::from($this->status->value, 'registrar estorno externo');
        }
        $this->recordRefund($now);
    }

    public function hasConfirmedPayment(): bool
    {
        return $this->payment?->status === PaymentStatus::Confirmed && $this->payment->externalPaymentId !== null;
    }

    /**
     * O cliente pagou uma cobrança que já não valia (orçamento cancelado). O estorno já foi
     * feito no meio de pagamento; aqui ele fica registrado, com o id do pagamento externo.
     */
    public function recordLateRefund(string $externalPaymentId, \DateTimeImmutable $now): void
    {
        if (!$this->needsRefundOnLatePayment() || $this->payment === null) {
            throw InvalidBudgetTransitionException::from($this->status->value, 'estornar pagamento atrasado');
        }

        $this->payment   = $this->payment->with(PaymentStatus::Refunded, $externalPaymentId);
        $this->updatedAt = $now;
        $this->emit('payment.refunded', ['payment_id' => $this->payment->id]);
    }

    /**
     * Compensação: desfaz o efeito do orçamento sobre o estoque e invalida a cobrança em aberto.
     * É idempotente: num orçamento já encerrado não muda nada, mas confirma de novo.
     */
    public function cancel(string $reason, \DateTimeImmutable $now): void
    {
        if (!$this->status->isClosed()) {
            $this->releaseParts($this->status === BudgetStatus::Paid ? StockMovement::RESTOCK : StockMovement::RELEASE);
            if ($this->payment?->status === PaymentStatus::Pending) {
                $this->payment = $this->payment->with(PaymentStatus::Expired);
            }
            $this->status        = BudgetStatus::Cancelled;
            $this->failureReason = $reason;
            $this->updatedAt     = $now;
        }

        $this->emit('budget.cancelled', ['budget_id' => $this->id]);
    }

    /**
     * O orçamento foi cancelado sem o dinheiro ter entrado: um pagamento aprovado agora tem de
     * voltar, e fica registrado como o pagamento desta cobrança.
     */
    public function needsRefundOnLatePayment(): bool
    {
        return $this->status === BudgetStatus::Cancelled
            && in_array($this->payment?->status, [PaymentStatus::Expired, PaymentStatus::Pending, PaymentStatus::Failed], true);
    }

    public function total(): float
    {
        return round(array_sum(array_map(static fn (BudgetItem $i): float => $i->total(), $this->items)), 2);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOsId(): string
    {
        return $this->osId;
    }

    public function getSagaId(): string
    {
        return $this->sagaId;
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    /** @return list<BudgetItem> */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getStatus(): BudgetStatus
    {
        return $this->status;
    }

    public function getPayment(): ?Payment
    {
        return $this->payment;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    /** @return list<StockMovement> */
    public function releaseMovements(): array
    {
        $movements       = $this->movements;
        $this->movements = [];

        return $movements;
    }

    /** @return list<array{type: string, payload: array<string, mixed>}> */
    public function releaseEvents(): array
    {
        $events              = $this->pendingEvents;
        $this->pendingEvents = [];

        return $events;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'os_id'          => $this->osId,
            'saga_id'        => $this->sagaId,
            'status'         => $this->status->value,
            'customer'       => $this->customer->toArray(),
            'items'          => array_map(static fn (BudgetItem $i): array => $i->toArray(), $this->items),
            'total_amount'   => $this->total(),
            'payment'        => $this->payment?->toArray(),
            'failure_reason' => $this->failureReason,
            'created_at'     => $this->createdAt->format('Y-m-d\TH:i:s.v\Z'),
            'updated_at'     => $this->updatedAt->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    private function receivablePayment(string $action): Payment
    {
        if (!$this->canReceivePayment() || $this->payment === null) {
            throw InvalidBudgetTransitionException::from(
                $this->status->value . ($this->payment !== null ? ' / pagamento ' . $this->payment->status->value : ' / sem cobrança'),
                $action,
            );
        }

        return $this->payment;
    }

    /** @return list<BudgetItem> */
    private function parts(): array
    {
        return array_values(array_filter($this->items, static fn (BudgetItem $i): bool => $i->kind === BudgetItem::KIND_PART));
    }

    private function releaseParts(string $movement): void
    {
        foreach ($this->parts() as $item) {
            $this->movements[] = new StockMovement($movement, $item->itemId, $item->quantity);
        }
    }

    private function transition(BudgetStatus $from, BudgetStatus $to, string $action, \DateTimeImmutable $now): void
    {
        if ($this->status !== $from) {
            throw InvalidBudgetTransitionException::from($this->status->value, $action);
        }
        $this->status    = $to;
        $this->updatedAt = $now;
    }

    /** @param array<string, mixed> $payload */
    private function emit(string $type, array $payload): void
    {
        $this->pendingEvents[] = ['type' => $type, 'payload' => $payload];
    }
}
