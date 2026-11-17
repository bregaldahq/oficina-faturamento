<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Port\BudgetNotifierInterface;
use App\Application\Port\LoggerInterface;
use App\Application\Port\TracerInterface;
use App\Domain\Budget\Budget;

/** Logger, rastreador e aviso ao cliente que só anotam, para os testes conferirem. */
final class RecordingObservers implements LoggerInterface, TracerInterface, BudgetNotifierInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $logs = [];

    /** @var list<string> */
    public array $traces = [];

    /** @var list<string> ids dos orçamentos avisados */
    public array $notified = [];

    public bool $notifierFails = false;

    public function info(string $message, array $context = []): void
    {
        $this->logs[] = ['level' => 'info', 'message' => $message, 'context' => $context];
    }

    public function error(string $message, array $context = []): void
    {
        $this->logs[] = ['level' => 'error', 'message' => $message, 'context' => $context];
    }

    public function currentTrace(): ?string
    {
        return 'trace-do-teste';
    }

    public function startMessage(string $type, ?string $trace): void
    {
        $this->traces[] = "start:{$type}";
    }

    public function endMessage(bool $failed): void
    {
        $this->traces[] = 'end:' . ($failed ? 'falha' : 'ok');
    }

    public function budgetReady(Budget $budget): void
    {
        if ($this->notifierFails) {
            throw new \RuntimeException('SMTP fora do ar');
        }
        $this->notified[] = $budget->getId();
    }

    /** @return list<string> */
    public function messages(): array
    {
        return array_map(static fn (array $l): string => $l['message'], $this->logs);
    }
}
