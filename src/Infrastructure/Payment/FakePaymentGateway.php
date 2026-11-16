<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment;

use App\Application\Payment\Charge;
use App\Application\Payment\GatewayPayment;
use App\Application\Port\PaymentGatewayInterface;
use App\Domain\Budget\Budget;

/**
 * Meio de pagamento falso, para testes, BDD e o ponta a ponta (PAYMENT_GATEWAY=fake). Não
 * guarda estado: o "pagamento" é descrito no próprio id, então a API e o worker, em processos
 * diferentes, concordam sobre ele.
 *
 *   fake-<approved|rejected|cancelled|refunded|charged_back>[.<tentativa>]-<id do orçamento>-<centavos>
 *
 * A assinatura do webhook é verificada de verdade, com o mesmo algoritmo do real.
 */
final class FakePaymentGateway implements PaymentGatewayInterface
{
    /** @var list<array{payment: string, key: string}> */
    public array $refunds = [];

    public function __construct(
        private readonly WebhookSignature $signature,
        private readonly string $baseUrl = 'https://pagamento.local/fake',
    ) {
    }

    /** `$attempt` distingue duas tentativas do mesmo cliente na mesma cobrança. */
    public static function paymentId(string $status, string $budgetId, float $amount, int $attempt = 1): string
    {
        $suffix = $attempt > 1 ? ".{$attempt}" : '';

        return sprintf('fake-%s%s-%s-%d', $status, $suffix, $budgetId, (int)round($amount * 100));
    }

    public function createCharge(Budget $budget, \DateTimeImmutable $expiresAt): Charge
    {
        return new Charge('fake-pref-' . $budget->getId(), $this->baseUrl . '/' . $budget->getId());
    }

    public function getPayment(string $externalPaymentId): GatewayPayment
    {
        if (preg_match('/^fake-(approved|rejected|cancelled|refunded|charged_back)(?:\.\d+)?-(.+)-(\d+)$/', $externalPaymentId, $m) !== 1) {
            throw new \RuntimeException("Pagamento falso desconhecido: {$externalPaymentId}");
        }

        return new GatewayPayment($externalPaymentId, $m[1], $m[2], ((int)$m[3]) / 100, $m[1] === 'rejected' ? 'cc_rejected_insufficient_amount' : '');
    }

    public function refund(string $externalPaymentId, string $idempotencyKey): void
    {
        foreach ($this->refunds as $refund) {
            if ($refund['key'] === $idempotencyKey) {
                return;
            }
        }
        $this->refunds[] = ['payment' => $externalPaymentId, 'key' => $idempotencyKey];
    }

    public function isAuthentic(string $dataId, string $requestId, string $signature): bool
    {
        return $this->signature->isValid($dataId, $requestId, $signature);
    }
}
