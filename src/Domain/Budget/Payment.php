<?php

declare(strict_types=1);

namespace App\Domain\Budget;

/** A cobrança de um orçamento. Faz parte do agregado Budget e só muda por ele. */
final class Payment
{
    public function __construct(
        public readonly string $id,
        public readonly string $preferenceId,
        public readonly string $paymentUrl,
        public readonly float $amount,
        public readonly PaymentStatus $status,
        public readonly ?string $externalPaymentId,
        public readonly ?string $failureReason,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }

    public function with(PaymentStatus $status, ?string $externalPaymentId = null, ?string $failureReason = null): self
    {
        return new self(
            $this->id,
            $this->preferenceId,
            $this->paymentUrl,
            $this->amount,
            $status,
            $externalPaymentId ?? $this->externalPaymentId,
            $failureReason     ?? $this->failureReason,
            $this->expiresAt,
            $this->createdAt,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'payment_id'          => $this->id,
            'status'              => $this->status->value,
            'payment_url'         => $this->status === PaymentStatus::Pending ? $this->paymentUrl : null,
            'amount'              => $this->amount,
            'external_payment_id' => $this->externalPaymentId,
            'failure_reason'      => $this->failureReason,
            'expires_at'          => $this->expiresAt->format('Y-m-d\TH:i:s.v\Z'),
            'created_at'          => $this->createdAt->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }
}
