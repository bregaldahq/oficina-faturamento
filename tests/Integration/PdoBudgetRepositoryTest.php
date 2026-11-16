<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Budget\Budget;
use App\Domain\Budget\BudgetItem;
use App\Domain\Budget\Customer;
use App\Domain\Exception\ConcurrencyException;
use App\Domain\Exception\DuplicateMessageException;
use App\Domain\Exception\InsufficientStockException;
use App\Domain\Messaging\Message;
use PHPUnit\Framework\TestCase;
use Tests\Support\Harness;

/** O repositório de produção contra SQLite: a transação, a inbox, a trava otimista e a outbox. */
class PdoBudgetRepositoryTest extends TestCase
{
    private Harness $h;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->h   = new Harness();
        $this->now = new \DateTimeImmutable('2026-11-16T10:00:00Z');
    }

    private function budget(string $id = 'b-1', string $osId = 'os-1', int $parts = 2): Budget
    {
        return Budget::generate($id, $osId, 'saga', 'corr', new Customer('c-1', 'Maria', 'm@x.com'), [
            new BudgetItem(BudgetItem::KIND_PART, Harness::PART, 'Pastilha', Harness::PART_PRICE, $parts),
        ], $this->now);
    }

    private function message(string $id): Message
    {
        return new Message($id, 'budget.generated', 'faturamento', 'saga', 'os-1', '2026-11-16T10:00:00.000Z', 'corr', ['budget_id' => 'b-1']);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $out = [];
        foreach (['budgets', 'budget_items', 'payments', 'outbox', 'inbox'] as $table) {
            $stmt = $this->h->pdo->prepare("SELECT COUNT(*) FROM {$table}");
            $stmt->execute();
            $out[$table] = (int)$stmt->fetchColumn();
        }

        return $out;
    }

    public function testGravaTudoNumaTransacaoERecarrega(): void
    {
        $this->h->budgets->save($this->budget(), [$this->message('m-1')], 'in-1');

        $loaded = $this->h->budgets->find('b-1');
        $this->assertNotNull($loaded);
        $this->assertSame(1, $loaded->getVersion());
        $this->assertSame(378.0, $loaded->total());
        $this->assertSame('Pastilha', $loaded->getItems()[0]->description);
        $this->assertSame(['budgets' => 1, 'budget_items' => 1, 'payments' => 0, 'outbox' => 1, 'inbox' => 1], $this->counts());
        $this->assertSame(['stock' => 5, 'reserved' => 2], $this->h->stock());
        $this->assertSame('b-1', $this->h->budgets->findByOsId('os-1')?->getId());
        $this->assertCount(1, $this->h->budgets->list());
    }

    public function testEstoqueInsuficienteDesfazATransacaoInteira(): void
    {
        try {
            $this->h->budgets->save($this->budget(parts: 6), [$this->message('m-1')], 'in-1');
            $this->fail('esperava InsufficientStockException');
        } catch (InsufficientStockException) {
        }

        $this->assertSame(['budgets' => 0, 'budget_items' => 0, 'payments' => 0, 'outbox' => 0, 'inbox' => 0], $this->counts());
        $this->assertSame(['stock' => 5, 'reserved' => 0], $this->h->stock());
    }

    public function testMensagemRepetidaNaoGravaNada(): void
    {
        $this->h->budgets->save($this->budget(), [], 'in-1');

        $this->expectException(DuplicateMessageException::class);
        $this->h->budgets->save($this->budget('b-2', 'os-2'), [], 'in-1');
    }

    public function testDuasEscritasSobreAMesmaLeituraASegundaFalha(): void
    {
        $this->h->budgets->save($this->budget());
        $a = $this->h->budgets->find('b-1');
        $b = $this->h->budgets->find('b-1');
        $this->assertNotNull($a);
        $this->assertNotNull($b);

        $a->approve($this->now);
        $this->h->budgets->save($a);

        $b->reject($this->now);
        try {
            $this->h->budgets->save($b);
            $this->fail('esperava ConcurrencyException');
        } catch (ConcurrencyException) {
        }
        $this->assertSame(['stock' => 5, 'reserved' => 2], $this->h->stock(), 'a liberação da segunda escrita voltou');
    }

    public function testSegundoOrcamentoParaAMesmaOsEConcorrencia(): void
    {
        $this->h->budgets->save($this->budget());

        $this->expectException(ConcurrencyException::class);
        $this->h->budgets->save($this->budget('b-2', 'os-1', 1));
    }

    public function testOutboxPendenteEPublicada(): void
    {
        $this->h->budgets->record([$this->message('m-1'), $this->message('m-2')], 'in-1');
        $this->h->pdo->exec("INSERT INTO outbox (id, type, body, occurred_at) VALUES ('ruim', 'x', 'isto não é json', '2026-11-16T09:00:00.000Z')");

        $pending = $this->h->budgets->pending();
        $this->assertSame(['m-1', 'm-2'], array_map(static fn (Message $m) => $m->id, $pending), 'o item ilegível não trava os outros');

        $this->h->budgets->markPublished($pending[0]);
        $this->assertSame(['m-2'], array_map(static fn (Message $m) => $m->id, $this->h->budgets->pending()));
    }

    public function testRegistroSemOrcamentoTambemPassaPelaInbox(): void
    {
        $this->h->budgets->record([], 'in-1');

        $this->expectException(DuplicateMessageException::class);
        $this->h->budgets->record([], 'in-1');
    }

    public function testBuscaPeloPagamento(): void
    {
        $budget  = $this->h->approvedAndCharged('os-9');
        $payment = $budget->getPayment();
        $this->assertNotNull($payment);

        $this->assertSame($budget->getId(), $this->h->budgets->findByPaymentId($payment->id)?->getId());
        $this->assertNull($this->h->budgets->findByPaymentId('nao-existe'));
    }

    public function testCatalogoNaoDeixaOEstoqueFicarAbaixoDoReservadoNemApagarPecaReservada(): void
    {
        $this->h->budgets->save($this->budget());
        $part = $this->h->catalog->findPart(Harness::PART);
        $this->assertNotNull($part);

        try {
            $this->h->catalog->savePart(new \App\Domain\Catalog\Part(Harness::PART, 'x', 1, 1));
            $this->fail('o estoque ficou abaixo do reservado');
        } catch (\App\Domain\Exception\DomainException $e) {
            $this->assertStringContainsString('(2)', $e->getMessage());
        }

        $this->expectException(\App\Domain\Exception\DomainException::class);
        $this->h->catalog->deletePart(Harness::PART);
    }
}
