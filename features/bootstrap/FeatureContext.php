<?php

declare(strict_types=1);

use App\Domain\Catalog\Part;
use App\Domain\Messaging\Message;
use App\Infrastructure\Payment\FakePaymentGateway;
use Behat\Behat\Context\Context;
use PHPUnit\Framework\Assert;
use Tests\Support\Harness;

/**
 * Os passos falam com o serviço pelas portas que ele tem de verdade: a fila (comandos do
 * orquestrador, pelo worker), o webhook de aprovação e o webhook assinado do Mercado Pago (pelos
 * controllers). O banco é o repositório de produção sobre SQLite; o Mercado Pago é o falso.
 */
final class FeatureContext implements Context
{
    private Harness $service;

    private int $parts = 2;

    private ?string $lastPayment = null;

    public function __construct()
    {
        $this->service = new Harness();
    }

    private function budgetId(string $osId): string
    {
        return $this->service->budgetFor($osId)->getId();
    }

    /** @param array<string, mixed> $payload */
    private function command(string $type, string $osId, array $payload = []): void
    {
        $this->service->send($this->service->command($type, $osId, $payload));
    }

    /**
     * @Given que o estoque tem :quantity pastilhas de freio
     */
    public function estoque(int $quantity): void
    {
        $part = $this->service->catalog->findPart(Harness::PART);
        Assert::assertInstanceOf(Part::class, $part);
        $part->setStock($quantity);
        $this->service->catalog->savePart($part);
    }

    /**
     * @Given que o diagnóstico da OS :osId pediu 1 troca de óleo e :parts pastilhas
     */
    public function diagnostico(string $osId, int $parts): void
    {
        $this->parts = $parts;
    }

    /**
     * @When o orquestrador pede o orçamento da OS :osId
     */
    public function pedeOrcamento(string $osId): void
    {
        $this->command('billing.generate-budget', $osId, $this->service->generatePayload($this->parts));
    }

    /**
     * @When o cliente aprova o orçamento da OS :osId
     */
    public function clienteAprova(string $osId): void
    {
        $this->approval($osId, true);
    }

    /**
     * @When o cliente recusa o orçamento da OS :osId
     */
    public function clienteRecusa(string $osId): void
    {
        $this->approval($osId, false);
    }

    private function approval(string $osId, bool $approved): void
    {
        [$status] = Harness::call(
            $this->service->budgetController(),
            'approval',
            ['id' => $this->budgetId($osId)],
            ['approved' => $approved],
            Harness::WEBHOOK_TOKEN,
        );
        Assert::assertSame(200, $status);
    }

    /**
     * @When o orquestrador pede a cobrança da OS :osId
     */
    public function pedeCobranca(string $osId): void
    {
        $this->command('billing.charge', $osId, ['budget_id' => $this->budgetId($osId)]);
    }

    /**
     * @When o cliente paga pelo Mercado Pago
     */
    public function clientePaga(): void
    {
        $budget            = $this->service->budgetFor('os-200');
        $this->lastPayment = FakePaymentGateway::paymentId('approved', $budget->getId(), $budget->total());
        $this->notify($this->lastPayment);
    }

    /**
     * @When o Mercado Pago repete o aviso do pagamento
     */
    public function avisoRepetido(): void
    {
        Assert::assertNotNull($this->lastPayment);
        $this->notify($this->lastPayment);
    }

    private function notify(string $paymentId): void
    {
        [$status] = Harness::call(
            $this->service->webhookController(),
            'mercadoPago',
            ['data_id' => $paymentId, 'type' => 'payment'],
            [],
            ['x-request-id' => 'req', 'x-signature' => $this->service->signature->header($paymentId, 'req', (string)time())],
        );
        Assert::assertSame(200, $status);
    }

    /**
     * @Given que o cliente já pagou o orçamento da OS :osId
     */
    public function jaPagou(string $osId): void
    {
        $this->pedeOrcamento($osId);
        $this->clienteAprova($osId);
        $this->pedeCobranca($osId);
        $this->clientePaga();
    }

    /**
     * @When o orquestrador pede o estorno do pagamento da OS :osId
     */
    public function pedeEstorno(string $osId): void
    {
        $this->command('billing.refund-payment', $osId, ['budget_id' => $this->budgetId($osId), 'reason' => 'reparo falhou']);
    }

    /**
     * @When o orquestrador pede o cancelamento do orçamento da OS :osId
     */
    public function pedeCancelamento(string $osId): void
    {
        $this->command('billing.cancel-budget', $osId, ['budget_id' => $this->budgetId($osId), 'reason' => 'compensação']);
    }

    /**
     * @Then o orçamento da OS :osId fica :status com total de :total
     */
    public function orcamento(string $osId, string $status, float $total): void
    {
        $budget = $this->service->budgetFor($osId);
        Assert::assertSame($status, $budget->getStatus()->value);
        Assert::assertEqualsWithDelta($total, $budget->total(), 0.001);
    }

    /**
     * @Then o estoque fica com :stock pastilhas, :reserved reservadas
     */
    public function estoqueFica(int $stock, int $reserved): void
    {
        Assert::assertSame(['stock' => $stock, 'reserved' => $reserved], $this->service->stock());
    }

    /**
     * @Then o cliente recebe um link de pagamento
     */
    public function link(): void
    {
        foreach ($this->service->bus->published() as $message) {
            if ($message->type === 'payment.created') {
                Assert::assertStringStartsWith('https://', (string)$message->payload['payment_url']);

                return;
            }
        }
        Assert::fail('payment.created não foi publicado');
    }

    /**
     * @Then o serviço de OS recebe os eventos :types
     */
    public function eventos(string $types): void
    {
        Assert::assertSame(array_map('trim', explode(',', $types)), $this->service->published());
    }

    /**
     * @Then os últimos eventos publicados são :types
     */
    public function ultimosEventos(string $types): void
    {
        $expected = array_map('trim', explode(',', $types));
        Assert::assertSame($expected, array_slice($this->service->published(), -count($expected)));
    }

    /**
     * @Then o último evento publicado traz o motivo :reason
     */
    public function motivo(string $reason): void
    {
        $published = $this->service->bus->published();
        $last      = end($published);
        Assert::assertInstanceOf(Message::class, $last);
        Assert::assertSame($reason, $last->payload['reason']);
    }

    /**
     * @Then o evento :type foi publicado :count vez
     */
    public function publicadoVezes(string $type, int $count): void
    {
        Assert::assertSame($count, count(array_filter($this->service->published(), static fn (string $t): bool => $t === $type)));
    }

    /**
     * @Then o Mercado Pago estornou :count pagamento
     */
    public function estornos(int $count): void
    {
        Assert::assertCount($count, $this->service->gateway->refunds);
    }

    /**
     * @Then todo evento publicado leva a saga e a OS de origem
     */
    public function eventosComOrigem(): void
    {
        foreach ($this->service->bus->published() as $message) {
            Assert::assertSame('saga-' . $message->osId, $message->sagaId);
            Assert::assertSame('corr-' . $message->osId, $message->correlationId);
            Assert::assertSame('faturamento', $message->producer);
        }
    }
}
