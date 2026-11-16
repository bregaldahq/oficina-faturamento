<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Tracing;

use App\Infrastructure\Tracing\NewRelicBusinessEvents;
use PHPUnit\Framework\TestCase;

final class NewRelicBusinessEventsTest extends TestCase
{
    public function testAcrescentaOAmbienteAosAtributos(): void
    {
        $recorded = [];
        $events   = new NewRelicBusinessEvents('homologacao', static function (string $event, array $attributes) use (&$recorded): void {
            $recorded[] = [$event, $attributes];
        });

        $events->record('PaymentConfirmed', ['paymentId' => 'mp-1', 'orderId' => 'os-1', 'amount' => 10.5]);

        $this->assertSame([['PaymentConfirmed', ['paymentId' => 'mp-1', 'orderId' => 'os-1', 'amount' => 10.5, 'env' => 'homologacao']]], $recorded);
    }

    public function testSemAgenteNaoFazNada(): void
    {
        (new NewRelicBusinessEvents('local'))->record('PaymentConfirmed', ['paymentId' => 'mp-1']);

        $this->addToAssertionCount(1);
    }
}
