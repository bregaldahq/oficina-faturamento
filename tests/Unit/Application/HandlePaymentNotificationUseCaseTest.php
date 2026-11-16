<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\UseCase\HandleIncomingMessageUseCase as Handler;
use App\Domain\Budget\BudgetStatus;
use App\Domain\Budget\PaymentStatus;
use App\Infrastructure\Payment\FakePaymentGateway;
use PHPUnit\Framework\TestCase;
use Tests\Support\Harness;

class HandlePaymentNotificationUseCaseTest extends TestCase
{
    private Harness $h;

    protected function setUp(): void
    {
        $this->h = new Harness();
        $this->h->approvedAndCharged('os-1');
    }

    public function testPagamentoAprovadoConfirmaDaBaixaEPublicaNaHora(): void
    {
        $this->assertSame(Handler::OUTCOME_OK, $this->h->pay('os-1'));

        $budget = $this->h->budgetFor('os-1');
        $this->assertSame(BudgetStatus::Paid, $budget->getStatus());
        $this->assertSame(PaymentStatus::Confirmed, $budget->getPayment()?->status);
        $this->assertSame(['stock' => 3, 'reserved' => 0], $this->h->stock());
        $published = $this->h->bus->published();
        $last      = end($published);
        $this->assertSame('payment.confirmed', $last ? $last->type : null);
        // no JSON, 558.0 vira 558: o contrato só exige um número
        $this->assertEquals($budget->total(), $last ? $last->payload['amount'] : null);
    }

    public function testMesmaNotificacaoDuasVezesTemEfeitoUmaVez(): void
    {
        $this->h->pay('os-1');

        $this->assertSame(Handler::OUTCOME_DUPLICATE, $this->h->pay('os-1'));
        $this->assertSame(['stock' => 3, 'reserved' => 0], $this->h->stock());
    }

    public function testPagamentoRecusado(): void
    {
        $this->assertSame(Handler::OUTCOME_OK, $this->h->pay('os-1', 'rejected'));

        $payment = $this->h->budgetFor('os-1')->getPayment();
        $this->assertSame(PaymentStatus::Failed, $payment?->status);
        $this->assertSame('cc_rejected_insufficient_amount', $payment->failureReason);
        $this->assertSame(['stock' => 5, 'reserved' => 2], $this->h->stock(), 'a reserva espera a saga cancelar');
    }

    public function testValorDiferenteDoTotalEstornaERecusaACobranca(): void
    {
        // Lançar faria o webhook responder 500 e o Mercado Pago reenviar para sempre, com o
        // dinheiro cobrado e a OS parada.
        $this->assertSame(Handler::OUTCOME_OK, $this->h->pay('os-1', 'approved', 1.0));

        $budget = $this->h->budgetFor('os-1');
        $this->assertSame(BudgetStatus::Approved, $budget->getStatus());
        $this->assertSame(PaymentStatus::Failed, $budget->getPayment()?->status);
        $this->assertSame('AMOUNT_MISMATCH', $budget->getPayment()->failureReason);
        $this->assertCount(1, $this->h->gateway->refunds);
        $this->assertContains('payment.failed', $this->h->published());
    }

    public function testNovaTentativaDepoisDeUmaRecusaConfirma(): void
    {
        $this->h->pay('os-1', 'rejected');
        $budget = $this->h->budgetFor('os-1');

        $outcome = $this->h->notifications->execute(FakePaymentGateway::paymentId('approved', $budget->getId(), $budget->total(), 2));

        $this->assertSame(Handler::OUTCOME_OK, $outcome);
        $this->assertSame(BudgetStatus::Paid, $this->h->budgetFor('os-1')->getStatus());
        $this->assertSame([], $this->h->gateway->refunds);
    }

    public function testPagamentoEmDobroOSegundoVolta(): void
    {
        $this->h->pay('os-1');
        $budget = $this->h->budgetFor('os-1');
        $second = FakePaymentGateway::paymentId('approved', $budget->getId(), $budget->total(), 2);

        $this->assertSame(Handler::OUTCOME_OK, $this->h->notifications->execute($second));
        $this->assertSame(Handler::OUTCOME_DUPLICATE, $this->h->notifications->execute($second));

        $this->assertSame([['payment' => $second, 'key' => 'late-' . $second]], $this->h->gateway->refunds);
        $this->assertSame(BudgetStatus::Paid, $this->h->budgetFor('os-1')->getStatus(), 'o primeiro continua valendo');
        $this->assertContains('payment.extra_refunded', $this->h->observers->messages());
        $this->assertSame(1, count(array_filter($this->h->published(), static fn ($t) => $t === 'payment.confirmed')));
    }

    public function testRecusaDepoisDoCancelamentoENovaTentativaAprovadaTambemVolta(): void
    {
        $this->h->pay('os-1', 'rejected');
        $budget = $this->h->budgetFor('os-1');
        $this->h->handler->execute($this->h->command('billing.cancel-budget', 'os-1', ['budget_id' => $budget->getId()]));

        $this->h->notifications->execute(FakePaymentGateway::paymentId('approved', $budget->getId(), $budget->total(), 2));

        $this->assertSame(PaymentStatus::Refunded, $this->h->budgetFor('os-1')->getPayment()?->status);
        $this->assertCount(1, $this->h->gateway->refunds);
    }

    public function testEstornoFeitoNoPainelDoMercadoPagoFicaRegistrado(): void
    {
        $this->h->pay('os-1');
        $budget = $this->h->budgetFor('os-1');
        $id     = (string)$budget->getPayment()?->externalPaymentId;
        $refund = str_replace('fake-approved-', 'fake-refunded-', $id);

        // O serviço consulta o mesmo pagamento e o Mercado Pago agora diz "refunded"; o id falso
        // muda de prefixo, então aqui o registro é forçado a ter o id do estorno.
        $this->h->pdo->prepare('UPDATE payments SET external_payment_id = :e')->execute(['e' => $refund]);

        $this->assertSame(Handler::OUTCOME_OK, $this->h->notifications->execute($refund));
        $this->assertSame(PaymentStatus::Refunded, $this->h->budgetFor('os-1')->getPayment()?->status);
        $this->assertSame([], $this->h->gateway->refunds, 'o dinheiro já voltou: o serviço não estorna de novo');
        $this->assertContains('payment.refunded', $this->h->published());
        $this->assertSame(Handler::OUTCOME_DUPLICATE, $this->h->notifications->execute(str_replace('fake-approved-', 'fake-charged_back-', $id)));
    }

    public function testPendenteEIgnorado(): void
    {
        $gateway = new class () implements \App\Application\Port\PaymentGatewayInterface {
            public function createCharge(\App\Domain\Budget\Budget $budget, \DateTimeImmutable $expiresAt): \App\Application\Payment\Charge
            {
                throw new \LogicException();
            }

            public function getPayment(string $externalPaymentId): \App\Application\Payment\GatewayPayment
            {
                return new \App\Application\Payment\GatewayPayment($externalPaymentId, 'in_process', 'qualquer', 1.0);
            }

            public function refund(string $externalPaymentId, string $idempotencyKey): void
            {
            }

            public function isAuthentic(string $dataId, string $requestId, string $signature): bool
            {
                return true;
            }
        };
        $budgets  = $this->h->budgets;
        $budgetId = $this->h->budgetFor('os-1')->getId();
        $repo     = new class ($budgets, $budgetId) implements \App\Application\Port\BudgetRepositoryInterface {
            public function __construct(private readonly \App\Infrastructure\Repository\PdoBudgetRepository $inner, private readonly string $id)
            {
            }

            public function find(string $id): ?\App\Domain\Budget\Budget
            {
                return $this->inner->find($this->id);
            }

            public function findByOsId(string $osId): ?\App\Domain\Budget\Budget
            {
                return null;
            }

            public function findByPaymentId(string $paymentId): ?\App\Domain\Budget\Budget
            {
                return null;
            }

            public function list(int $limit = 50): array
            {
                return [];
            }

            public function save(\App\Domain\Budget\Budget $budget, array $outbox = [], ?string $inboxMessageId = null): void
            {
            }

            public function record(array $outbox, string $inboxMessageId): void
            {
            }
        };

        $outcome = (new \App\Application\UseCase\HandlePaymentNotificationUseCase($repo, $gateway, $this->h->messages, $this->h->clock))->execute('x');

        $this->assertSame(Handler::OUTCOME_IGNORED, $outcome);
    }

    public function testPagamentoDeOrcamentoDesconhecidoEIgnorado(): void
    {
        $this->assertSame(Handler::OUTCOME_IGNORED, $this->h->notifications->execute(FakePaymentGateway::paymentId('approved', 'nao-existe', 10)));
    }

    public function testPagamentoQueChegaDepoisDoCancelamentoEEstornadoSozinho(): void
    {
        $budgetId = $this->h->budgetFor('os-1')->getId();
        $this->h->handler->execute($this->h->command('billing.cancel-budget', 'os-1', ['budget_id' => $budgetId]));

        $this->assertSame(Handler::OUTCOME_OK, $this->h->pay('os-1'));

        $payment = $this->h->budgetFor('os-1')->getPayment();
        $this->assertSame(PaymentStatus::Refunded, $payment?->status);
        $paid = FakePaymentGateway::paymentId('approved', $budgetId, $this->h->budgetFor('os-1')->total());
        $this->assertSame([['payment' => $paid, 'key' => 'late-' . $paid]], $this->h->gateway->refunds);
        $this->assertContains('payment.refunded', $this->h->published());
        $this->assertSame(['stock' => 5, 'reserved' => 0], $this->h->stock(), 'nada sai do estoque');
        $this->assertSame(Handler::OUTCOME_DUPLICATE, $this->h->pay('os-1'));
    }

    public function testRecusaDepoisDoCancelamentoNaoFazNada(): void
    {
        $budgetId = $this->h->budgetFor('os-1')->getId();
        $this->h->handler->execute($this->h->command('billing.cancel-budget', 'os-1', ['budget_id' => $budgetId]));

        $this->assertSame(Handler::OUTCOME_DUPLICATE, $this->h->pay('os-1', 'rejected'));
    }
}
