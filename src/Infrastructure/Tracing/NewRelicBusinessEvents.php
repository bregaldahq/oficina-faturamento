<?php

declare(strict_types=1);

namespace App\Infrastructure\Tracing;

use App\Application\Port\BusinessEventsInterface;

/**
 * Custom events do New Relic (docs/fase-4/CONTRATOS.md §8, no oficina-api), com o `env` de
 * todos os eventos do sistema. Sem a extensão carregada, é no-op.
 */
final class NewRelicBusinessEvents implements BusinessEventsInterface
{
    /** @var \Closure(string, array<string, scalar|null>): void */
    private readonly \Closure $recorder;

    /** @param (\Closure(string, array<string, scalar|null>): void)|null $recorder */
    public function __construct(private readonly string $env, ?\Closure $recorder = null)
    {
        $this->recorder = $recorder ?? static function (string $event, array $attributes): void {
            if (function_exists('newrelic_record_custom_event')) {
                newrelic_record_custom_event($event, $attributes);
            }
        };
    }

    public function record(string $event, array $attributes): void
    {
        ($this->recorder)($event, [...$attributes, 'env' => $this->env]);
    }
}
