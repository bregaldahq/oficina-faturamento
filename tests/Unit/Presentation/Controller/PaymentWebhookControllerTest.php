<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Controller;

use App\Infrastructure\Payment\FakePaymentGateway;
use PHPUnit\Framework\TestCase;
use Tests\Support\Harness;

class PaymentWebhookControllerTest extends TestCase
{
    private Harness $h;

    protected function setUp(): void
    {
        $this->h = new Harness();
        $this->h->approvedAndCharged('os-1');
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function notify(string $dataId, string $type = 'payment', ?string $signature = null): array
    {
        return Harness::call(
            $this->h->webhookController(),
            'mercadoPago',
            ['data_id' => $dataId, 'type' => $type],
            [],
            ['x-request-id' => 'req-1', 'x-signature' => $signature ?? $this->h->signature->header($dataId, 'req-1', '1731751200')],
        );
    }

    private function paymentId(string $status = 'approved'): string
    {
        $budget = $this->h->budgetFor('os-1');

        return FakePaymentGateway::paymentId($status, $budget->getId(), $budget->total());
    }

    public function testNotificacaoAssinadaConfirmaOPagamento(): void
    {
        [$status, $body] = $this->notify($this->paymentId());

        $this->assertSame(200, $status);
        $this->assertSame('ok', $body['outcome']);
        $this->assertSame('PAID', $this->h->budgetFor('os-1')->getStatus()->value);
        $this->assertSame('duplicate', $this->notify($this->paymentId())[1]['outcome'], 'o Mercado Pago reenvia; o efeito é um só');
    }

    public function testAssinaturaInvalidaE401SemEfeito(): void
    {
        $this->assertSame(401, $this->notify($this->paymentId(), signature: 'ts=1,v1=deadbeef')[0]);
        $this->assertSame(401, $this->notify($this->paymentId(), signature: '')[0]);
        $this->assertSame('APPROVED', $this->h->budgetFor('os-1')->getStatus()->value);
        $this->assertContains('payment.webhook_rejected', $this->h->observers->messages());
    }

    public function testTopicoQueNaoEPagamentoEIgnorado(): void
    {
        [$status, $body] = $this->notify('123', 'merchant_order');

        $this->assertSame(200, $status);
        $this->assertSame('ignored', $body['outcome']);
    }

    public function testIdNoCorpoQuandoNaoVemNaQuery(): void
    {
        $id       = $this->paymentId();
        [$status] = Harness::call(
            $this->h->webhookController(),
            'mercadoPago',
            [],
            ['type' => 'payment', 'data' => ['id' => $id]],
            ['x-request-id' => 'req-1', 'x-signature' => $this->h->signature->header($id, 'req-1', '1')],
        );

        $this->assertSame(200, $status);
    }

    public function testFalhaNoTratamentoE500ParaOMercadoPagoReenviar(): void
    {
        [$status, $body] = $this->notify('id-que-o-gateway-nao-conhece');

        $this->assertSame(500, $status);
        $this->assertStringContainsString('reenvie', $body['error']);
        $this->assertContains('payment.webhook_failed', $this->h->observers->messages());
    }
}
