<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Budget\Budget;
use App\Domain\Exception\ConcurrencyException;
use App\Domain\Exception\DuplicateMessageException;
use App\Domain\Exception\InsufficientStockException;
use App\Domain\Messaging\Message;

interface BudgetRepositoryInterface
{
    public function find(string $id): ?Budget;

    public function findByOsId(string $osId): ?Budget;

    public function findByPaymentId(string $paymentId): ?Budget;

    /** @return list<Budget> os mais recentes primeiro */
    public function list(int $limit = 50): array;

    /**
     * Grava numa transação (ADR-004 e ADR-017): o orçamento, a cobrança, os movimentos de
     * estoque que ele registrou, as mensagens de saída na outbox e o id da mensagem de entrada
     * na inbox. Se qualquer parte falha, nada é gravado.
     *
     * @param list<Message> $outbox
     *
     * @throws InsufficientStockException uma reserva não coube no disponível
     * @throws DuplicateMessageException  a mensagem de entrada já tinha sido processada
     * @throws ConcurrencyException       o orçamento mudou desde a leitura
     */
    public function save(Budget $budget, array $outbox = [], ?string $inboxMessageId = null): void;

    /**
     * Só a outbox e a inbox, para a confirmação de uma compensação sobre algo que não existe.
     *
     * @param list<Message> $outbox
     *
     * @throws DuplicateMessageException
     */
    public function record(array $outbox, string $inboxMessageId): void;
}
