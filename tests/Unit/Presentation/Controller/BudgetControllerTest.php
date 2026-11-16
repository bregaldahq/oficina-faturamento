<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Controller;

use App\Infrastructure\Payment\FakePaymentGateway;
use PHPUnit\Framework\TestCase;
use Tests\Support\Harness;

class BudgetControllerTest extends TestCase
{
    private const ADMIN = ['sub' => 'admin', 'role' => 'admin'];
    private const OWNER = ['sub' => 'cli-1', 'role' => 'customer'];
    private const OTHER = ['sub' => 'cli-2', 'role' => 'customer'];

    private Harness $h;
    private string $budgetId;

    protected function setUp(): void
    {
        $this->h = new Harness();
        $this->h->send($this->h->command('billing.generate-budget', 'os-1', $this->h->generatePayload()));
        $this->budgetId = $this->h->budgetFor('os-1')->getId();
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function call(string $action, mixed ...$args): array
    {
        return Harness::call($this->h->budgetController(), $action, ...$args);
    }

    public function testClienteSoVeOQueEDele(): void
    {
        $this->assertSame(200, $this->call('show', ['id' => $this->budgetId], self::OWNER)[0]);
        $this->assertSame(200, $this->call('show', ['id' => $this->budgetId], self::ADMIN)[0]);
        $this->assertSame(404, $this->call('show', ['id' => $this->budgetId], self::OTHER)[0], 'o de outro não existe para ele');
        $this->assertSame(404, $this->call('show', ['id' => 'nao-existe'], self::ADMIN)[0]);

        [$status, $body] = $this->call('byOrder', ['orderId' => 'os-1'], self::OWNER);
        $this->assertSame(200, $status);
        $this->assertSame('AWAITING_APPROVAL', $body['status']);
        $this->assertEquals(558.0, $body['total_amount']);
        $this->assertNull($body['payment']);

        $this->assertCount(1, $this->call('index')[1]['data']);
    }

    public function testAprovacaoPeloWebhook(): void
    {
        [$status, $body] = $this->call('approval', ['id' => $this->budgetId], ['approved' => true], Harness::WEBHOOK_TOKEN);

        $this->assertSame(200, $status);
        $this->assertSame('APPROVED', $body['status']);
        $this->assertSame(['budget.generated', 'budget.approved'], $this->h->published(), 'a API publica na hora');

        [$status] = $this->call('approval', ['id' => $this->budgetId], ['approved' => true], Harness::WEBHOOK_TOKEN);
        $this->assertSame(200, $status, 'repetir a mesma resposta não muda nada');
        $this->assertSame(['budget.generated', 'budget.approved'], $this->h->published());

        [$status] = $this->call('approval', ['id' => $this->budgetId], ['approved' => false], Harness::WEBHOOK_TOKEN);
        $this->assertSame(422, $status, 'mudar de ideia depois de aprovar é recusado');
    }

    public function testRecusaPeloWebhookLiberaAsPecas(): void
    {
        [$status, $body] = $this->call('approval', ['id' => $this->budgetId], ['approved' => false], Harness::WEBHOOK_TOKEN);

        $this->assertSame(200, $status);
        $this->assertSame('REJECTED', $body['status']);
        $this->assertSame(['stock' => 5, 'reserved' => 0], $this->h->stock());
        $this->assertSame(200, $this->call('approval', ['id' => $this->budgetId], ['approved' => false], Harness::WEBHOOK_TOKEN)[0]);
    }

    public function testWebhookSemTokenOuComCorpoInvalido(): void
    {
        $this->assertSame(401, $this->call('approval', ['id' => $this->budgetId], ['approved' => true], 'errado')[0]);
        $this->assertSame(401, $this->call('approval', ['id' => $this->budgetId], ['approved' => true], '')[0]);
        $this->assertSame(422, $this->call('approval', ['id' => $this->budgetId], ['approved' => 'sim'], Harness::WEBHOOK_TOKEN)[0]);
        $this->assertSame(404, $this->call('approval', ['id' => 'nao-existe'], ['approved' => true], Harness::WEBHOOK_TOKEN)[0]);
    }

    public function testPagamentoVisivelParaODonoComOLinkEnquantoPendente(): void
    {
        $this->h->review($this->budgetId, true);
        $this->h->send($this->h->command('billing.charge', 'os-1', ['budget_id' => $this->budgetId]));
        $paymentId = (string)$this->h->budgetFor('os-1')->getPayment()?->id;

        [$status, $body] = $this->call('payment', ['id' => $paymentId], self::OWNER);
        $this->assertSame(200, $status);
        $this->assertSame('PENDING', $body['status']);
        $this->assertSame('https://pagamento.local/fake/' . $this->budgetId, $body['payment_url']);
        $this->assertSame('os-1', $body['os_id']);
        $this->assertSame(404, $this->call('payment', ['id' => $paymentId], self::OTHER)[0]);
        $this->assertSame(404, $this->call('payment', ['id' => 'nao-existe'], self::ADMIN)[0]);

        $this->h->notifications->execute(FakePaymentGateway::paymentId('approved', $this->budgetId, 558.0));
        $this->assertNull($this->call('payment', ['id' => $paymentId], self::OWNER)[1]['payment_url'], 'pago: o link some');
    }

    public function testOrcamentoSemCobrancaNaoTemPagamento(): void
    {
        $budget = new \App\Application\UseCase\Budget\BudgetQueries(new class ($this->h->budgets->find($this->budgetId)) implements \App\Application\Port\BudgetRepositoryInterface {
            public function __construct(private readonly ?\App\Domain\Budget\Budget $budget)
            {
            }

            public function find(string $id): ?\App\Domain\Budget\Budget
            {
                return null;
            }

            public function findByOsId(string $osId): ?\App\Domain\Budget\Budget
            {
                return null;
            }

            public function findByPaymentId(string $paymentId): ?\App\Domain\Budget\Budget
            {
                return $this->budget;
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
        });

        $this->expectException(\App\Domain\Exception\NotFoundException::class);
        $budget->payment('qualquer', self::ADMIN);
    }
}
