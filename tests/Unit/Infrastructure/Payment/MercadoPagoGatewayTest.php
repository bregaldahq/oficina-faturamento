<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Payment;

use App\Domain\Budget\Budget;
use App\Domain\Budget\BudgetItem;
use App\Domain\Budget\Customer;
use App\Infrastructure\Payment\MercadoPagoGateway;
use App\Infrastructure\Payment\WebhookSignature;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/** O que vai para a API do Mercado Pago, conferido requisição a requisição, sem rede. */
class MercadoPagoGatewayTest extends TestCase
{
    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    private function gateway(Response ...$responses): MercadoPagoGateway
    {
        $stack = HandlerStack::create(new MockHandler(array_values($responses)));
        $stack->push(Middleware::history($this->history));

        return new MercadoPagoGateway(new Client(['handler' => $stack]), 'TEST-token', 'https://api.oficina/webhook', new WebhookSignature('s'));
    }

    private function budget(): Budget
    {
        return Budget::generate('b-1', 'os-1', 'saga', 'corr', new Customer('c', 'Maria', 'maria@example.com'), [
            new BudgetItem('service', 'svc', 'Troca de oleo', 180.0, 1),
            new BudgetItem('part', 'p', 'Filtro', 38.9, 2),
        ], new \DateTimeImmutable('2026-11-16T10:00:00Z'));
    }

    /** @return array<string, mixed> */
    private function sentBody(int $index = 0): array
    {
        return (array)json_decode((string)$this->history[$index]['request']->getBody(), true);
    }

    public function testCriaAPreferenciaComReferenciaPrazoEIdempotencia(): void
    {
        $charge = $this->gateway(new Response(201, [], (string)json_encode(['id' => 'pref-9', 'init_point' => 'https://mp/pagar', 'sandbox_init_point' => 'https://sandbox/pagar'])))
            ->createCharge($this->budget(), new \DateTimeImmutable('2026-11-17T10:00:00Z'));

        $request = $this->history[0]['request'];
        $body    = $this->sentBody();
        $this->assertSame('pref-9', $charge->preferenceId);
        $this->assertSame('https://mp/pagar', $charge->paymentUrl);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.mercadopago.com/checkout/preferences', (string)$request->getUri());
        $this->assertSame('Bearer TEST-token', $request->getHeaderLine('Authorization'));
        $this->assertSame('charge-b-1', $request->getHeaderLine('X-Idempotency-Key'));
        $this->assertSame('b-1', $body['external_reference']);
        $this->assertSame('https://api.oficina/webhook', $body['notification_url']);
        $this->assertTrue($body['expires']);
        $this->assertSame('2026-11-17T10:00:00.000+00:00', $body['expiration_date_to']);
        $this->assertEquals(257.8, $body['items'][0]['unit_price']);
        $this->assertSame('BRL', $body['items'][0]['currency_id']);
        $this->assertSame('maria@example.com', $body['payer']['email']);
    }

    public function testLinkDoSandboxQuandoPedido(): void
    {
        $stack   = HandlerStack::create(new MockHandler([new Response(201, [], (string)json_encode(['id' => 'p', 'init_point' => 'a', 'sandbox_init_point' => 'b']))]));
        $gateway = new MercadoPagoGateway(new Client(['handler' => $stack]), 't', 'u', new WebhookSignature('s'), true);

        $this->assertSame('b', $gateway->createCharge($this->budget(), new \DateTimeImmutable())->paymentUrl);
    }

    public function testRespostaSemLinkEErro(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->gateway(new Response(201, [], '{"id":"p"}'))->createCharge($this->budget(), new \DateTimeImmutable());
    }

    public function testConsultaOPagamento(): void
    {
        $payment = $this->gateway(new Response(200, [], (string)json_encode([
            'id' => 1234567, 'status' => 'approved', 'status_detail' => 'accredited', 'external_reference' => 'b-1', 'transaction_amount' => 257.8,
        ])))->getPayment('1234567');

        $this->assertSame('https://api.mercadopago.com/v1/payments/1234567', (string)$this->history[0]['request']->getUri());
        $this->assertSame('GET', $this->history[0]['request']->getMethod());
        $this->assertSame('', $this->history[0]['request']->getHeaderLine('X-Idempotency-Key'), 'leitura não leva chave');
        $this->assertTrue($payment->isApproved());
        $this->assertSame('b-1', $payment->externalReference);
        $this->assertSame(257.8, $payment->amount);
        $this->assertSame('1234567', $payment->id);
    }

    public function testEstornoComChaveDeIdempotencia(): void
    {
        $this->gateway(new Response(201, [], '{"id": 99}'))->refund('1234567', 'refund-pay-1');

        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.mercadopago.com/v1/payments/1234567/refunds', (string)$request->getUri());
        $this->assertSame('refund-pay-1', $request->getHeaderLine('X-Idempotency-Key'));
        $this->assertSame('{}', (string)$request->getBody(), 'corpo vazio: estorno total');
    }

    public function testErroDaApiViraExcecaoComAMensagemDeLaENuncaComOToken(): void
    {
        try {
            $this->gateway(new Response(400, [], '{"message":"invalid refund amount"}'))->refund('1', 'k');
            $this->fail('esperava erro');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('400', $e->getMessage());
            $this->assertStringContainsString('invalid refund amount', $e->getMessage());
            $this->assertStringNotContainsString('TEST-token', $e->getMessage());
        }
    }

    public function testErroSemCorpoJson(): void
    {
        $this->expectExceptionMessage('Mercado Pago respondeu 502');

        $this->gateway(new Response(502, [], 'Bad Gateway'))->getPayment('1');
    }

    public function testVerificaAssinatura(): void
    {
        $signature = new WebhookSignature('s');
        $gateway   = $this->gateway();

        $this->assertTrue($gateway->isAuthentic('123', 'req', $signature->header('123', 'req', '1')));
        $this->assertFalse($gateway->isAuthentic('124', 'req', $signature->header('123', 'req', '1')));
    }
}
