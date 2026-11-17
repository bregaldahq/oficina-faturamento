<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Tests\Support\Harness;

class WorkerTest extends TestCase
{
    public function testConsomeOComandoPublicaOEventoEConfirmaAMensagem(): void
    {
        $h = new Harness();
        $h->bus->deliver($h->command('billing.generate-budget', 'os-1', $h->generatePayload()));

        $result = $h->worker->tick(0);

        $this->assertSame(['published' => 1, 'processed' => 1, 'failed' => 0], $result);
        $this->assertSame(['budget.generated'], $h->published());
        $this->assertSame(0, $h->bus->queued());
        $this->assertSame(['start:billing.generate-budget', 'end:ok'], $h->observers->traces);
        $this->assertSame('ok', $h->observers->logs[0]['context']['outcome']);
    }

    public function testComandoQueNaoPodeSerAtendidoFicaNaFila(): void
    {
        $h = new Harness();
        $h->bus->deliver($h->command('billing.charge', 'os-1', ['budget_id' => 'nao-existe']));

        $result = $h->worker->tick(0);

        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $h->bus->queued(), 'volta para a fila e, se persistir, vai para a DLQ');
    }
}
