<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Application\Port\BudgetRepositoryInterface;
use App\Application\Port\OutboxInterface;
use App\Domain\Budget\Budget;
use App\Domain\Budget\BudgetItem;
use App\Domain\Budget\BudgetStatus;
use App\Domain\Budget\Customer;
use App\Domain\Budget\Payment;
use App\Domain\Budget\PaymentStatus;
use App\Domain\Budget\StockMovement;
use App\Domain\Exception\ConcurrencyException;
use App\Domain\Exception\DuplicateMessageException;
use App\Domain\Exception\InsufficientStockException;
use App\Domain\Exception\InvalidMessageException;
use App\Domain\Messaging\Message;
use PDO;
use PDOException;

/**
 * Orçamento, cobrança, estoque, outbox e inbox numa transação só (ADR-004 e ADR-017). Escrito
 * em SQL comum a MySQL e SQLite: os testes rodam o mesmo código contra SQLite em memória.
 */
final class PdoBudgetRepository implements BudgetRepositoryInterface, OutboxInterface
{
    private const DATE = 'Y-m-d H:i:s.v';

    /** SQLSTATE de violação de restrição (chave duplicada), igual em MySQL e SQLite. */
    private const INTEGRITY = '23000';

    public function __construct(
        private readonly PDO $pdo,
        private readonly \Closure $idGenerator,
    ) {
    }

    public function find(string $id): ?Budget
    {
        return $this->one('SELECT * FROM budgets WHERE id = :v', $id);
    }

    public function findByOsId(string $osId): ?Budget
    {
        return $this->one('SELECT * FROM budgets WHERE os_id = :v', $osId);
    }

    public function findByPaymentId(string $paymentId): ?Budget
    {
        return $this->one('SELECT b.* FROM budgets b JOIN payments p ON p.budget_id = b.id WHERE p.id = :v', $paymentId);
    }

    public function list(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM budgets ORDER BY created_at DESC, id LIMIT :limit');
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_values(array_map(fn (array $row): Budget => $this->hydrate($row), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []));
    }

    public function save(Budget $budget, array $outbox = [], ?string $inboxMessageId = null): void
    {
        $this->transaction(function () use ($budget, $outbox, $inboxMessageId): void {
            // A inbox primeiro: a mensagem repetida para aqui, antes de qualquer efeito.
            if ($inboxMessageId !== null) {
                $this->insertInbox($inboxMessageId);
            }

            $budget->getVersion() === 0 ? $this->insert($budget) : $this->update($budget);
            $this->savePayment($budget);
            // Sempre na mesma ordem de peça: dois orçamentos com as mesmas peças não se travam.
            $movements = $budget->releaseMovements();
            usort($movements, static fn (StockMovement $a, StockMovement $b): int => strcmp($a->partId, $b->partId));
            foreach ($movements as $movement) {
                $this->move($movement);
            }
            $this->insertOutbox($outbox);
        });
    }

    public function record(array $outbox, string $inboxMessageId): void
    {
        $this->transaction(function () use ($outbox, $inboxMessageId): void {
            $this->insertInbox($inboxMessageId);
            $this->insertOutbox($outbox);
        });
    }

    public function pending(int $limit = 25): array
    {
        $stmt = $this->pdo->prepare('SELECT id, body FROM outbox WHERE published_at IS NULL ORDER BY occurred_at, id LIMIT :limit');
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $messages = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            try {
                $messages[] = Message::fromJson((string)$row['body']);
            } catch (InvalidMessageException) {
                // Um item ilegível não trava os outros; fica pendente, à vista do alerta.
            }
        }

        return $messages;
    }

    public function markPublished(Message $message): void
    {
        $this->pdo->prepare('UPDATE outbox SET published_at = :now WHERE id = :id')
            ->execute(['now' => gmdate('Y-m-d H:i:s'), 'id' => $message->id]);
    }

    /** @param callable(): void $work */
    private function transaction(callable $work): void
    {
        $this->pdo->beginTransaction();
        try {
            $work();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function insertInbox(string $messageId): void
    {
        try {
            $this->pdo->prepare('INSERT INTO inbox (message_id, processed_at) VALUES (:id, :now)')
                ->execute(['id' => $messageId, 'now' => gmdate('Y-m-d H:i:s')]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === self::INTEGRITY) {
                throw new DuplicateMessageException("Mensagem {$messageId} já processada.", 0, $e);
            }
            throw $e;
        }
    }

    /** @param list<Message> $outbox */
    private function insertOutbox(array $outbox): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO outbox (id, type, body, occurred_at) VALUES (:id, :type, :body, :at)');
        foreach ($outbox as $message) {
            $stmt->execute(['id' => $message->id, 'type' => $message->type, 'body' => $message->toJson(), 'at' => $message->occurredAt]);
        }
    }

    private function insert(Budget $budget): void
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO budgets (id, os_id, saga_id, correlation_id, customer_id, customer_name, customer_email, status,
                                      total_amount, failure_reason, version, created_at, updated_at)
                 VALUES (:id, :os, :saga, :corr, :cid, :cname, :cemail, :status, :total, :reason, 1, :created, :updated)'
            )->execute($this->budgetParams($budget) + [
                'os'      => $budget->getOsId(),
                'saga'    => $budget->getSagaId(),
                'corr'    => $budget->getCorrelationId(),
                'cid'     => $budget->getCustomer()->id,
                'cname'   => $budget->getCustomer()->name,
                'cemail'  => $budget->getCustomer()->email,
                'created' => $budget->getCreatedAt()->format(self::DATE),
            ]);
        } catch (PDOException $e) {
            // Outro orçamento para a mesma OS entrou entre a leitura e a gravação.
            if ((string)$e->getCode() === self::INTEGRITY) {
                throw new ConcurrencyException("Já existe orçamento para a OS {$budget->getOsId()}.", 0, $e);
            }
            throw $e;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO budget_items (id, budget_id, position, kind, item_id, description, unit_price, quantity)
             VALUES (:id, :budget, :pos, :kind, :item, :d, :price, :q)'
        );
        foreach ($budget->getItems() as $position => $item) {
            $stmt->execute([
                'id'     => ($this->idGenerator)(),
                'budget' => $budget->getId(),
                'pos'    => $position,
                'kind'   => $item->kind,
                'item'   => $item->itemId,
                'd'      => $item->description,
                'price'  => $item->unitPrice,
                'q'      => $item->quantity,
            ]);
        }
    }

    private function update(Budget $budget): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE budgets SET status = :status, total_amount = :total, failure_reason = :reason, updated_at = :updated,
                                version = version + 1
             WHERE id = :id AND version = :version'
        );
        $stmt->execute($this->budgetParams($budget) + ['version' => $budget->getVersion()]);
        if ($stmt->rowCount() === 0) {
            throw new ConcurrencyException("O orçamento {$budget->getId()} mudou desde a leitura.");
        }
    }

    /** @return array<string, mixed> */
    private function budgetParams(Budget $budget): array
    {
        return [
            'id'      => $budget->getId(),
            'status'  => $budget->getStatus()->value,
            'total'   => $budget->total(),
            'reason'  => $budget->getFailureReason(),
            'updated' => $budget->getUpdatedAt()->format(self::DATE),
        ];
    }

    private function savePayment(Budget $budget): void
    {
        $payment = $budget->getPayment();
        if ($payment === null) {
            return;
        }

        $params = [
            'id'       => $payment->id,
            'status'   => $payment->status->value,
            'external' => $payment->externalPaymentId,
            'reason'   => $payment->failureReason,
        ];
        $update = $this->pdo->prepare(
            'UPDATE payments SET status = :status, external_payment_id = :external, failure_reason = :reason WHERE id = :id'
        );
        $update->execute($params);

        $exists = $this->pdo->prepare('SELECT 1 FROM payments WHERE id = :id');
        $exists->execute(['id' => $payment->id]);
        if ($exists->fetchColumn() !== false) {
            return;
        }

        $this->pdo->prepare(
            'INSERT INTO payments (id, budget_id, preference_id, payment_url, amount, status, external_payment_id, failure_reason,
                                   expires_at, created_at)
             VALUES (:id, :budget, :pref, :url, :amount, :status, :external, :reason, :expires, :created)'
        )->execute($params + [
            'budget'  => $budget->getId(),
            'pref'    => $payment->preferenceId,
            'url'     => $payment->paymentUrl,
            'amount'  => $payment->amount,
            'expires' => $payment->expiresAt->format(self::DATE),
            'created' => $payment->createdAt->format(self::DATE),
        ]);
    }

    /**
     * Cada movimento é um UPDATE condicional: o que não cabe derruba a transação inteira. Cada
     * parâmetro aparece uma vez só no SQL: com prepared statement nativo (PdoConnection), o
     * MySQL não aceita o mesmo nome repetido.
     */
    private function move(StockMovement $movement): void
    {
        [$set, $where, $uses, $failure] = match ($movement->type) {
            StockMovement::RESERVE => [
                'reserved_quantity = reserved_quantity + :q1',
                'stock_quantity - reserved_quantity >= :q2',
                2,
                'Estoque insuficiente para a peça %s.',
            ],
            StockMovement::RELEASE => [
                'reserved_quantity = reserved_quantity - :q1',
                'reserved_quantity >= :q2',
                2,
                'A peça %s não tem a reserva que o orçamento diz ter.',
            ],
            StockMovement::CONSUME => [
                'stock_quantity = stock_quantity - :q1, reserved_quantity = reserved_quantity - :q2',
                'reserved_quantity >= :q3 AND stock_quantity >= :q4',
                4,
                'A peça %s não tem a reserva que o orçamento diz ter.',
            ],
            default => ['stock_quantity = stock_quantity + :q1', '1 = 1', 1, 'A peça %s não existe mais.'],
        };

        $stmt = $this->pdo->prepare("UPDATE parts_inventory SET {$set}, version = version + 1 WHERE id = :id AND {$where}");
        $stmt->bindValue('id', $movement->partId);
        // Inteiro de verdade: no SQLite, a expressão comparada com um texto é sempre falsa.
        for ($i = 1; $i <= $uses; $i++) {
            $stmt->bindValue("q{$i}", $movement->quantity, PDO::PARAM_INT);
        }
        $stmt->execute();
        if ($stmt->rowCount() === 0) {
            throw new InsufficientStockException(sprintf($failure, $movement->partId));
        }
    }

    private function one(string $sql, string $value): ?Budget
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['v' => $value]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Budget
    {
        $items = $this->pdo->prepare('SELECT * FROM budget_items WHERE budget_id = :id ORDER BY position');
        $items->execute(['id' => $row['id']]);

        $payment = $this->pdo->prepare('SELECT * FROM payments WHERE budget_id = :id');
        $payment->execute(['id' => $row['id']]);
        $p = $payment->fetch(PDO::FETCH_ASSOC);

        return Budget::reconstitute(
            (string)$row['id'],
            (string)$row['os_id'],
            (string)$row['saga_id'],
            (string)$row['correlation_id'],
            new Customer((string)$row['customer_id'], (string)$row['customer_name'], (string)$row['customer_email']),
            array_values(array_map(
                static fn (array $i): BudgetItem => new BudgetItem(
                    (string)$i['kind'],
                    (string)$i['item_id'],
                    (string)$i['description'],
                    (float)$i['unit_price'],
                    (int)$i['quantity'],
                ),
                $items->fetchAll(PDO::FETCH_ASSOC) ?: [],
            )),
            BudgetStatus::from((string)$row['status']),
            is_array($p) ? new Payment(
                (string)$p['id'],
                (string)$p['preference_id'],
                (string)$p['payment_url'],
                (float)$p['amount'],
                PaymentStatus::from((string)$p['status']),
                $p['external_payment_id'] !== null ? (string)$p['external_payment_id'] : null,
                $p['failure_reason']      !== null ? (string)$p['failure_reason'] : null,
                $this->date((string)$p['expires_at']),
                $this->date((string)$p['created_at']),
            ) : null,
            $row['failure_reason'] !== null ? (string)$row['failure_reason'] : null,
            $this->date((string)$row['created_at']),
            $this->date((string)$row['updated_at']),
            (int)$row['version'],
        );
    }

    private function date(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
