<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Payment;

use App\Infrastructure\Payment\FakePaymentGateway;
use App\Infrastructure\Payment\WebhookSignature;
use PHPUnit\Framework\TestCase;

class WebhookSignatureTest extends TestCase
{
    public function testManifestoDoMercadoPago(): void
    {
        $expected = hash_hmac('sha256', 'id:abc123;request-id:req-9;ts:1704908010;', 'segredo');

        $this->assertSame("ts=1704908010,v1={$expected}", (new WebhookSignature('segredo'))->header('ABC123', 'req-9', '1704908010'), 'o id vai em minúsculas');
        $this->assertTrue((new WebhookSignature('segredo'))->isValid('ABC123', 'req-9', " ts=1704908010 , v1={$expected}"));
    }

    public function testParAusenteFicaForaDoManifesto(): void
    {
        $expected = hash_hmac('sha256', 'id:1;ts:5;', 'segredo');

        $this->assertTrue((new WebhookSignature('segredo'))->isValid('1', '', "ts=5,v1={$expected}"));
    }

    public function testRecusas(): void
    {
        $signature = new WebhookSignature('segredo');
        $valid     = $signature->header('1', 'r', '5');

        $this->assertFalse($signature->isValid('2', 'r', $valid), 'outro pagamento');
        $this->assertFalse($signature->isValid('1', 'outro', $valid), 'outra requisição');
        $this->assertFalse((new WebhookSignature('outro-segredo'))->isValid('1', 'r', $valid));
        $this->assertFalse((new WebhookSignature(''))->isValid('1', 'r', $valid), 'sem segredo configurado, nada passa');
        $this->assertFalse($signature->isValid('1', 'r', ''));
        $this->assertFalse($signature->isValid('1', 'r', 'v1=abc'));
        $this->assertFalse($signature->isValid('1', 'r', 'ts=5'));
    }

    public function testPagamentoFalsoDescritoNoId(): void
    {
        $gateway = new FakePaymentGateway(new WebhookSignature('s'));
        $payment = $gateway->getPayment(FakePaymentGateway::paymentId('rejected', 'b-1', 12.34));

        $this->assertTrue($payment->isFailed());
        $this->assertSame('b-1', $payment->externalReference);
        $this->assertSame(12.34, $payment->amount);
        $this->assertSame('fake-pref-x', $gateway->createCharge(\App\Domain\Budget\Budget::failed('x', 'o', 's', 'c', new \App\Domain\Budget\Customer('c', 'n', 'e'), 'r', new \DateTimeImmutable()), new \DateTimeImmutable())->preferenceId);
        $this->assertFalse($gateway->getPayment(FakePaymentGateway::paymentId('approved', 'b', 1))->isFailed());

        $this->expectException(\RuntimeException::class);
        $gateway->getPayment('123');
    }
}
