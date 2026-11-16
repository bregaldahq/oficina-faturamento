<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Eventos de negócio para a observabilidade (docs/fase-4/CONTRATOS.md §8, no oficina-api):
 * `PaymentConfirmed`. Sem agente, é no-op.
 */
interface BusinessEventsInterface
{
    /** @param array<string, scalar|null> $attributes */
    public function record(string $event, array $attributes): void;
}
