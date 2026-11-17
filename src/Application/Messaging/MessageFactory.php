<?php

declare(strict_types=1);

namespace App\Application\Messaging;

use App\Application\Port\ClockInterface;
use App\Domain\Budget\Budget;
use App\Domain\Messaging\Message;
use App\Domain\UuidGeneratorInterface;

/** Transforma os eventos registrados pelo orçamento nos envelopes que vão para a outbox. */
final class MessageFactory
{
    public const PRODUCER = 'faturamento';

    public function __construct(
        private readonly UuidGeneratorInterface $uuid,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return list<Message> */
    public function fromBudget(Budget $budget): array
    {
        $messages = [];
        foreach ($budget->releaseEvents() as $event) {
            $messages[] = $this->make($event['type'], $budget->getOsId(), $budget->getSagaId(), $budget->getCorrelationId(), $event['payload']);
        }

        return $messages;
    }

    /**
     * Uma mensagem em resposta a outra, sem orçamento por trás (a confirmação de uma
     * compensação sobre algo que não existe).
     *
     * @param array<string, mixed> $payload
     */
    public function replyTo(Message $incoming, string $type, array $payload): Message
    {
        return $this->make($type, $incoming->osId, $incoming->sagaId, $incoming->correlationId, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function make(string $type, string $osId, string $sagaId, string $correlationId, array $payload): Message
    {
        return new Message(
            $this->uuid->generate(),
            $type,
            self::PRODUCER,
            $sagaId,
            $osId,
            $this->clock->now()->format('Y-m-d\TH:i:s.v\Z'),
            $correlationId,
            $payload,
        );
    }
}
