<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Budget\Budget;
use App\Domain\Budget\BudgetItem;
use App\Domain\Budget\BudgetStatus;
use App\Domain\Budget\Customer;
use App\Domain\Budget\Payment;
use App\Domain\Budget\PaymentStatus;
use App\Domain\Budget\StockMovement;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\InvalidBudgetTransitionException;
use PHPUnit\Framework\TestCase;

class BudgetTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-16T10:00:00Z');
    }

    private function budget(): Budget
    {
        return Budget::generate('b-1', 'os-1', 'saga-1', 'corr-1', new Customer('c-1', 'Maria', 'm@x.com'), [
            new BudgetItem(BudgetItem::KIND_SERVICE, 'svc', 'Troca de oleo', 180.0, 1),
            new BudgetItem(BudgetItem::KIND_PART, 'part', 'Pastilha', 189.5, 2),
        ], $this->now);
    }

    private function payment(): Payment
    {
        return new Payment('pay-1', 'pref-1', 'https://pagar', 559.0, PaymentStatus::Pending, null, null, $this->now->modify('+1 day'), $this->now);
    }

    private function charged(): Budget
    {
        $budget = $this->budget();
        $budget->approve($this->now);
        $budget->attachPayment($this->payment(), $this->now);
        $budget->releaseEvents();
        $budget->releaseMovements();

        return $budget;
    }

    /** @return list<string> */
    private function moves(Budget $budget): array
    {
        return array_map(static fn (StockMovement $m): string => "{$m->type}:{$m->partId}:{$m->quantity}", $budget->releaseMovements());
    }

    public function testGeradoReservaAsPecasEAvisaOTotal(): void
    {
        $budget = $this->budget();

        $this->assertSame(BudgetStatus::AwaitingApproval, $budget->getStatus());
        $this->assertSame(559.0, $budget->total());
        $this->assertSame(['reserve:part:2'], $this->moves($budget));
        $this->assertSame([['type' => 'budget.generated', 'payload' => ['budget_id' => 'b-1', 'total_amount' => 559.0]]], $budget->releaseEvents());
        $this->assertSame([], $budget->releaseEvents());
    }

    public function testOrcamentoSemItensERecusado(): void
    {
        $this->expectException(DomainException::class);

        Budget::generate('b', 'os', 's', 'c', new Customer('c', 'n', 'e'), [], $this->now);
    }

    public function testFalhaFicaRegistradaComOMotivo(): void
    {
        $budget = Budget::failed('b', 'os', 's', 'c', new Customer('c', 'n', 'e'), 'ITEM_NOT_FOUND', $this->now);

        $this->assertSame(BudgetStatus::Failed, $budget->getStatus());
        $this->assertSame('ITEM_NOT_FOUND', $budget->getFailureReason());
        $this->assertSame([['type' => 'budget.failed', 'payload' => ['reason' => 'ITEM_NOT_FOUND']]], $budget->releaseEvents());
        $this->assertSame([], $budget->releaseMovements());
    }

    public function testRejeitadoLiberaAReservaNaHora(): void
    {
        $budget = $this->budget();
        $budget->releaseMovements();

        $budget->reject($this->now);

        $this->assertSame(BudgetStatus::Rejected, $budget->getStatus());
        $this->assertSame(['release:part:2'], $this->moves($budget));
        $this->assertSame('budget.rejected', $budget->releaseEvents()[1]['type']);
    }

    public function testAprovarDuasVezesERecusado(): void
    {
        $budget = $this->budget();
        $budget->approve($this->now);

        $this->expectException(InvalidBudgetTransitionException::class);
        $budget->approve($this->now);
    }

    public function testCobrarExigeAprovacaoEUmaCobrancaSo(): void
    {
        $budget = $this->budget();
        try {
            $budget->attachPayment($this->payment(), $this->now);
            $this->fail('cobrou sem aprovação');
        } catch (InvalidBudgetTransitionException) {
        }

        $budget->approve($this->now);
        $budget->attachPayment($this->payment(), $this->now);
        $this->assertSame(['payment_id' => 'pay-1', 'payment_url' => 'https://pagar'], $budget->releaseEvents()[2]['payload']);

        $this->expectException(DomainException::class);
        $budget->attachPayment($this->payment(), $this->now);
    }

    public function testPagamentoConfirmadoDaBaixaNoEstoque(): void
    {
        $budget = $this->charged();

        $budget->confirmPayment('mp-1', 559.0, $this->now);

        $this->assertSame(BudgetStatus::Paid, $budget->getStatus());
        $this->assertSame(PaymentStatus::Confirmed, $budget->getPayment()?->status);
        $this->assertSame('mp-1', $budget->getPayment()->externalPaymentId);
        $this->assertSame(['consume:part:2'], $this->moves($budget));
        $this->assertSame(['type' => 'payment.confirmed', 'payload' => ['payment_id' => 'pay-1', 'amount' => 559.0]], $budget->releaseEvents()[0]);
    }

    public function testValorPagoDiferenteDoTotalERecusado(): void
    {
        $budget = $this->charged();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('559.00');
        $budget->confirmPayment('mp-1', 100.0, $this->now);
    }

    public function testConfirmarSemCobrancaPendenteERecusado(): void
    {
        $this->expectException(InvalidBudgetTransitionException::class);
        $this->expectExceptionMessage('sem cobrança');

        $this->budget()->confirmPayment('mp', 1.0, $this->now);
    }

    public function testPagamentoRecusadoMantemOOrcamentoAprovado(): void
    {
        $budget = $this->charged();

        $budget->failPayment('cc_rejected_insufficient_amount', 'mp-2', $this->now);

        $this->assertSame(BudgetStatus::Approved, $budget->getStatus());
        $this->assertSame(PaymentStatus::Failed, $budget->getPayment()?->status);
        $this->assertSame('payment.failed', $budget->releaseEvents()[0]['type']);
        $this->assertSame([], $budget->releaseMovements());
    }

    public function testCancelarAntesDePagarLiberaAReservaEInvalidaACobranca(): void
    {
        $budget = $this->charged();

        $budget->cancel('prazo de pagamento vencido', $this->now);

        $this->assertSame(BudgetStatus::Cancelled, $budget->getStatus());
        $this->assertSame(PaymentStatus::Expired, $budget->getPayment()?->status);
        $this->assertSame(['release:part:2'], $this->moves($budget));
        $this->assertSame(['type' => 'budget.cancelled', 'payload' => ['budget_id' => 'b-1']], $budget->releaseEvents()[0]);
        $this->assertTrue($budget->needsRefundOnLatePayment());
    }

    public function testCancelarDepoisDePagoDevolveAsPecasAoEstoque(): void
    {
        $budget = $this->charged();
        $budget->confirmPayment('mp-1', 559.0, $this->now);
        $budget->releaseMovements();

        $budget->cancel('reparo falhou', $this->now);

        $this->assertSame(['restock:part:2'], $this->moves($budget));
        $this->assertFalse($budget->needsRefundOnLatePayment(), 'o estorno de pagamento confirmado é da saga');
    }

    public function testCancelarDeNovoSoConfirma(): void
    {
        $budget = $this->budget();
        $budget->reject($this->now);
        $budget->releaseEvents();
        $budget->releaseMovements();

        $budget->cancel('compensação', $this->now);

        $this->assertSame(BudgetStatus::Rejected, $budget->getStatus());
        $this->assertSame([], $budget->releaseMovements());
        $this->assertSame(['budget.cancelled'], array_column($budget->releaseEvents(), 'type'));
    }

    public function testEstornoDePagamentoConfirmado(): void
    {
        $budget = $this->charged();
        $budget->confirmPayment('mp-1', 559.0, $this->now);
        $budget->releaseEvents();

        $budget->recordRefund($this->now);

        $this->assertSame(PaymentStatus::Refunded, $budget->getPayment()?->status);
        $this->assertSame(['type' => 'payment.refunded', 'payload' => ['payment_id' => 'pay-1']], $budget->releaseEvents()[0]);
    }

    public function testEstornoSemPagamentoConfirmadoSoConfirmaComIdNulo(): void
    {
        $budget = $this->charged();

        $budget->recordRefund($this->now);

        $this->assertSame(PaymentStatus::Pending, $budget->getPayment()?->status);
        $this->assertSame(['type' => 'payment.refunded', 'payload' => ['payment_id' => null]], $budget->releaseEvents()[0]);
    }

    public function testRecusaNaoEncerraACobranca(): void
    {
        $budget = $this->charged();
        $budget->failPayment('cc_rejected', 'mp-1', $this->now);

        $this->assertTrue($budget->canReceivePayment(), 'o cliente tenta de novo no mesmo link');
        $budget->confirmPayment('mp-2', 559.0, $this->now);
        $this->assertSame(BudgetStatus::Paid, $budget->getStatus());
        $this->assertFalse($budget->canReceivePayment());
    }

    public function testEstornoExternoSoDePagamentoConfirmado(): void
    {
        $budget = $this->charged();
        $budget->confirmPayment('mp-1', 559.0, $this->now);
        $budget->releaseEvents();

        $budget->recordExternalRefund($this->now);
        $this->assertSame(PaymentStatus::Refunded, $budget->getPayment()?->status);

        $this->expectException(InvalidBudgetTransitionException::class);
        $budget->recordExternalRefund($this->now);
    }

    public function testEstornoDePagamentoAtrasado(): void
    {
        $budget = $this->charged();
        $budget->cancel('prazo', $this->now);
        $budget->releaseEvents();

        $budget->recordLateRefund('mp-9', $this->now);

        $this->assertSame(PaymentStatus::Refunded, $budget->getPayment()?->status);
        $this->assertSame('mp-9', $budget->getPayment()->externalPaymentId);
        $this->assertSame('payment.refunded', $budget->releaseEvents()[0]['type']);
        $this->assertFalse($budget->needsRefundOnLatePayment());
    }

    public function testEstornoAtrasadoSemCancelamentoERecusado(): void
    {
        $this->expectException(InvalidBudgetTransitionException::class);

        $this->charged()->recordLateRefund('mp', $this->now);
    }

    public function testReconstituteNaoGeraEventoNemMovimento(): void
    {
        $payment = $this->payment();
        $budget  = Budget::reconstitute('b', 'os', 's', 'c', new Customer('c', 'n', 'e'), [], BudgetStatus::Approved, $payment, null, $this->now, $this->now, 4);

        $this->assertSame([], $budget->releaseEvents());
        $this->assertSame([], $budget->releaseMovements());
        $this->assertSame(4, $budget->getVersion());
        $this->assertSame('https://pagar', $budget->toArray()['payment']['payment_url']);
        $this->assertSame('s', $budget->getSagaId());
        $this->assertSame('c', $budget->getCorrelationId());
        $this->assertEquals($this->now, $budget->getCreatedAt());
    }

    public function testLinkDePagamentoSoAparecePendente(): void
    {
        $this->assertNull($this->payment()->with(PaymentStatus::Confirmed)->toArray()['payment_url']);
    }
}
