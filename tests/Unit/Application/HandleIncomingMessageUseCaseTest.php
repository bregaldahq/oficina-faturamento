<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\UseCase\HandleIncomingMessageUseCase as Handler;
use App\Domain\Budget\BudgetStatus;
use App\Domain\Budget\PaymentStatus;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\NotFoundException;
use PHPUnit\Framework\TestCase;
use Tests\Support\Harness;

class HandleIncomingMessageUseCaseTest extends TestCase
{
    private Harness $h;

    protected function setUp(): void
    {
        $this->h = new Harness();
    }

    private function generate(string $osId = 'os-1', int $parts = 2): string
    {
        return $this->h->handler->execute($this->h->command('billing.generate-budget', $osId, $this->h->generatePayload($parts)));
    }

    /** @return list<string> */
    private function outbox(): array
    {
        return array_map(static fn ($m) => $m->type, $this->h->budgets->pending());
    }

    public function testGeraOOrcamentoComPrecoDoCatalogoEReservaAsPecas(): void
    {
        $this->assertSame(Handler::OUTCOME_OK, $this->generate());

        $budget = $this->h->budgetFor('os-1');
        $this->assertSame(BudgetStatus::AwaitingApproval, $budget->getStatus());
        $this->assertSame(180.0 + 2 * 189.0, $budget->total());
        $this->assertSame('maria@example.com', $budget->getCustomer()->email);
        $this->assertSame(['stock' => 5, 'reserved' => 2], $this->h->stock());
        $this->assertSame(['budget.generated'], $this->outbox());
        $this->assertSame('saga-os-1', $this->h->budgets->pending()[0]->sagaId);
        $this->assertSame([$budget->getId()], $this->h->observers->notified, 'o cliente é avisado');
    }

    public function testComandoRepetidoNaoGeraSegundoOrcamentoNemReservaDeNovo(): void
    {
        $command = $this->h->command('billing.generate-budget', 'os-1', $this->h->generatePayload());
        $this->h->handler->execute($command);

        $this->assertSame(Handler::OUTCOME_DUPLICATE, $this->h->handler->execute($command));
        $this->assertSame(Handler::OUTCOME_DUPLICATE, $this->generate());
        $this->assertSame(['stock' => 5, 'reserved' => 2], $this->h->stock());
    }

    public function testItemForaDoCatalogoFalhaComOMotivoDoContrato(): void
    {
        $outcome = $this->h->handler->execute($this->h->command('billing.generate-budget', 'os-1', $this->h->generatePayload(1, 'peca-que-nao-existe')));

        $this->assertSame(Handler::OUTCOME_OK, $outcome);
        $this->assertSame(BudgetStatus::Failed, $this->h->budgetFor('os-1')->getStatus());
        $this->assertSame(['type' => 'budget.failed', 'reason' => 'ITEM_NOT_FOUND'], [
            'type'   => $this->h->budgets->pending()[0]->type,
            'reason' => $this->h->budgets->pending()[0]->payload['reason'],
        ]);
    }

    public function testListaDeItensVaziaFalhaComoItemInexistente(): void
    {
        $this->h->handler->execute($this->h->command('billing.generate-budget', 'os-1', ['customer' => ['id' => 'c-1'], 'items' => []]));

        $this->assertSame('ITEM_NOT_FOUND', $this->h->budgetFor('os-1')->getFailureReason());
    }

    public function testComandoSemClienteVaiParaADlq(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sem o cliente');

        $this->h->handler->execute($this->h->command('billing.generate-budget', 'os-1', ['items' => []]));
    }

    public function testPrazoDaCobrancaSaiDoComandoNaoDoRelogio(): void
    {
        $this->generate();
        $this->h->review($this->h->budgetFor('os-1')->getId(), true);

        // sem expires_at: 24 h depois da emissão do comando (2026-11-16T10:00 no Harness)
        $this->h->handler->execute($this->h->command('billing.charge', 'os-1', []));

        $this->assertSame('2026-11-17 10:00', $this->h->budgetFor('os-1')->getPayment()?->expiresAt->format('Y-m-d H:i'));
    }

    public function testCancelarOrcamentoPagoEstornaODinheiro(): void
    {
        // O prazo do pagamento venceu na saga, mas o pagamento foi confirmado antes de o
        // cancelamento chegar: o cliente não pode ficar sem peça e sem dinheiro.
        $this->h->approvedAndCharged('os-1');
        $this->h->pay('os-1');
        $budgetId = $this->h->budgetFor('os-1')->getId();

        $this->h->handler->execute($this->h->command('billing.cancel-budget', 'os-1', ['budget_id' => $budgetId]));

        $budget = $this->h->budgetFor('os-1');
        $this->assertSame(BudgetStatus::Cancelled, $budget->getStatus());
        $this->assertSame(PaymentStatus::Refunded, $budget->getPayment()?->status);
        $this->assertCount(1, $this->h->gateway->refunds);
        $this->assertSame('refund-' . $budget->getPayment()->id, $this->h->gateway->refunds[0]['key']);
        $this->assertSame(['stock' => 5, 'reserved' => 0], $this->h->stock(), 'as peças voltam');
        $this->assertSame(['payment.refunded', 'budget.cancelled'], array_slice($this->outbox(), -2));
    }

    public function testEstornoEDepoisCancelamentoEstornaUmaVezSo(): void
    {
        $this->h->approvedAndCharged('os-1');
        $this->h->pay('os-1');
        $budgetId = $this->h->budgetFor('os-1')->getId();

        $this->h->handler->execute($this->h->command('billing.refund-payment', 'os-1', ['budget_id' => $budgetId]));
        $this->h->handler->execute($this->h->command('billing.cancel-budget', 'os-1', ['budget_id' => $budgetId]));

        $this->assertCount(1, $this->h->gateway->refunds);
        $this->assertSame(['stock' => 5, 'reserved' => 0], $this->h->stock());
    }

    public function testEstoqueInsuficienteFalhaSemReservarNada(): void
    {
        $this->generate('os-1', 6);

        $this->assertSame('INSUFFICIENT_STOCK', $this->h->budgetFor('os-1')->getFailureReason());
        $this->assertSame(['stock' => 5, 'reserved' => 0], $this->h->stock());
        $this->assertSame(['budget.failed'], $this->outbox(), 'só a falha sai; o budget.generated voltou junto com a transação');
    }

    public function testDuasOsDisputandoAMesmaPecaASegundaFalha(): void
    {
        $this->generate('os-1', 3);
        $this->generate('os-2', 3);

        $this->assertSame(BudgetStatus::AwaitingApproval, $this->h->budgetFor('os-1')->getStatus());
        $this->assertSame('INSUFFICIENT_STOCK', $this->h->budgetFor('os-2')->getFailureReason());
    }

    public function testFalhaNoAvisoAoClienteNaoDesfazOOrcamento(): void
    {
        $this->h->observers->notifierFails = true;

        $this->assertSame(Handler::OUTCOME_OK, $this->generate());
        $this->assertSame(['budget.generated'], $this->outbox());
    }

    public function testCobrancaCriaOLinkDePagamentoComPrazo(): void
    {
        $this->generate();
        $budget = $this->h->review($this->h->budgetFor('os-1')->getId(), true);

        $outcome = $this->h->handler->execute($this->h->command('billing.charge', 'os-1', [
            'budget_id'  => $budget->getId(),
            'expires_at' => '2026-11-16T10:10:00.000Z',
        ]));

        $payment = $this->h->budgetFor('os-1')->getPayment();
        $this->assertSame(Handler::OUTCOME_OK, $outcome);
        $this->assertNotNull($payment);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame('https://pagamento.local/fake/' . $budget->getId(), $payment->paymentUrl);
        $this->assertSame('2026-11-16 10:10', $payment->expiresAt->format('Y-m-d H:i'));
        $this->assertSame($budget->total(), $payment->amount);
        $created = $this->h->budgets->pending();
        $this->assertSame('payment.created', end($created)->type ?? null);
    }

    public function testCobrancaRepetidaECobrancaDeOrcamentoEncerradoNaoCobramDeNovo(): void
    {
        $this->h->approvedAndCharged('os-1');

        $this->assertSame(Handler::OUTCOME_DUPLICATE, $this->h->handler->execute($this->h->command('billing.charge', 'os-1', [])));

        $this->generate('os-2');
        $this->h->review($this->h->budgetFor('os-2')->getId(), false);
        $this->assertSame(Handler::OUTCOME_DUPLICATE, $this->h->handler->execute($this->h->command('billing.charge', 'os-2', [])));
    }

    public function testCobrarAntesDaAprovacaoVoltaParaAFila(): void
    {
        $this->generate();

        $this->expectException(DomainException::class);
        $this->h->handler->execute($this->h->command('billing.charge', 'os-1', ['expires_at' => 'data inválida']));
    }

    public function testCobrarSemOrcamentoVoltaParaAFila(): void
    {
        $this->expectException(NotFoundException::class);

        $this->h->handler->execute($this->h->command('billing.charge', 'os-x', ['budget_id' => 'nao-existe']));
    }

    public function testCancelarAntesDoPagamentoLiberaAsPecas(): void
    {
        $this->generate();
        $budgetId = $this->h->budgetFor('os-1')->getId();

        $outcome = $this->h->handler->execute($this->h->command('billing.cancel-budget', 'os-1', ['budget_id' => $budgetId, 'reason' => 'prazo de aprovação']));

        $this->assertSame(Handler::OUTCOME_OK, $outcome);
        $this->assertSame(BudgetStatus::Cancelled, $this->h->budgetFor('os-1')->getStatus());
        $this->assertSame(['stock' => 5, 'reserved' => 0], $this->h->stock());
        $this->assertContains('budget.cancelled', $this->outbox());
    }

    public function testCancelarOrcamentoQueNaoExisteConfirmaSemEfeito(): void
    {
        $this->h->handler->execute($this->h->command('billing.cancel-budget', 'os-x', ['budget_id' => 'b-x'], 'c-1'));

        $pending = $this->h->budgets->pending();
        $this->assertSame([['budget.cancelled', 'b-x', 'os-x']], [[$pending[0]->type, $pending[0]->payload['budget_id'], $pending[0]->osId]]);
        $this->assertSame(Handler::OUTCOME_DUPLICATE, $this->h->handler->execute($this->h->command('billing.cancel-budget', 'os-x', [], 'c-1')));
    }

    public function testEstornoDePagamentoConfirmadoChamaOMeioDePagamentoUmaVez(): void
    {
        $this->h->approvedAndCharged('os-1');
        $this->h->pay('os-1');
        $budgetId = $this->h->budgetFor('os-1')->getId();

        $this->h->handler->execute($this->h->command('billing.refund-payment', 'os-1', ['budget_id' => $budgetId], 'r-1'));
        $this->h->handler->execute($this->h->command('billing.refund-payment', 'os-1', ['budget_id' => $budgetId], 'r-2'));

        $this->assertCount(1, $this->h->gateway->refunds, 'o mesmo estorno pedido duas vezes é um estorno só');
        $this->assertSame(PaymentStatus::Refunded, $this->h->budgetFor('os-1')->getPayment()?->status);
        $this->assertSame(['payment.refunded', 'payment.refunded'], array_values(array_filter($this->outbox(), static fn ($t) => $t === 'payment.refunded')));
    }

    public function testEstornoSemPagamentoOuSemOrcamentoSoConfirma(): void
    {
        $this->generate();
        $this->h->handler->execute($this->h->command('billing.refund-payment', 'os-1', []));
        $this->h->handler->execute($this->h->command('billing.refund-payment', 'os-x', []));

        $this->assertSame([], $this->h->gateway->refunds);
        $refunded = array_values(array_filter($this->h->budgets->pending(), static fn ($m) => $m->type === 'payment.refunded'));
        $this->assertSame([null, null], array_map(static fn ($m) => $m->payload['payment_id'], $refunded));
        $this->assertSame(['os-1', 'os-x'], array_map(static fn ($m) => $m->osId, $refunded));
    }

    public function testTipoDeOutroServicoEIgnorado(): void
    {
        $this->assertSame(Handler::OUTCOME_IGNORED, $this->h->handler->execute($this->h->command('diagnosis.completed', 'os-1')));
    }
}
