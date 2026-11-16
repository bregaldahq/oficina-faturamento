<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Application\Messaging\MessageFactory;
use App\Application\Port\BudgetNotifierInterface;
use App\Application\Port\BudgetRepositoryInterface;
use App\Application\Port\CatalogRepositoryInterface;
use App\Application\Port\ClockInterface;
use App\Application\Port\PaymentGatewayInterface;
use App\Domain\Budget\Budget;
use App\Domain\Budget\BudgetItem;
use App\Domain\Budget\BudgetStatus;
use App\Domain\Budget\Customer;
use App\Domain\Budget\Payment;
use App\Domain\Budget\PaymentStatus;
use App\Domain\Exception\ConcurrencyException;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\DuplicateMessageException;
use App\Domain\Exception\InsufficientStockException;
use App\Domain\Exception\NotFoundException;
use App\Domain\Messaging\Message;
use App\Domain\UuidGeneratorInterface;

/**
 * Os comandos que o orquestrador da saga manda para este serviço
 * (docs/fase-4/CONTRATOS.md §5.2, no oficina-api).
 *
 * Idempotência em duas camadas (ADR-017): o id da mensagem fica na inbox, e o mesmo comando
 * com outro id não repete o efeito (um orçamento por OS, uma cobrança por orçamento, um
 * estorno por pagamento).
 */
final class HandleIncomingMessageUseCase
{
    public const OUTCOME_OK        = 'ok';
    public const OUTCOME_DUPLICATE = 'duplicate';
    public const OUTCOME_IGNORED   = 'ignored';

    public const REASON_INSUFFICIENT_STOCK = 'INSUFFICIENT_STOCK';
    public const REASON_ITEM_NOT_FOUND     = 'ITEM_NOT_FOUND';

    /** Prazo da cobrança quando o comando não traz um. */
    private const DEFAULT_CHARGE_TTL = '+24 hours';

    public function __construct(
        private readonly BudgetRepositoryInterface $budgets,
        private readonly CatalogRepositoryInterface $catalog,
        private readonly PaymentGatewayInterface $gateway,
        private readonly BudgetNotifierInterface $notifier,
        private readonly MessageFactory $messages,
        private readonly UuidGeneratorInterface $uuid,
        private readonly ClockInterface $clock,
    ) {
    }

    public function execute(Message $message): string
    {
        try {
            return match ($message->type) {
                'billing.generate-budget' => $this->generate($message),
                'billing.charge'          => $this->charge($message),
                'billing.cancel-budget'   => $this->cancel($message),
                'billing.refund-payment'  => $this->refund($message),
                default                   => self::OUTCOME_IGNORED,
            };
        } catch (DuplicateMessageException) {
            return self::OUTCOME_DUPLICATE;
        }
    }

    private function generate(Message $message): string
    {
        if ($this->budgets->findByOsId($message->osId) !== null) {
            return self::OUTCOME_DUPLICATE;
        }

        $customer = $this->customer($message->payload['customer'] ?? []);
        if ($customer->id === '') {
            // Sem cliente, o orçamento não seria visível para ninguém: é comando malformado, e
            // a exceção leva a mensagem para a DLQ, que tem alerta.
            throw new DomainException("O comando de orçamento da OS {$message->osId} veio sem o cliente.");
        }
        $items = $this->priceItems($message->payload['items'] ?? []);
        if ($items === null) {
            return $this->fail($message, $customer, self::REASON_ITEM_NOT_FOUND);
        }

        $budget = Budget::generate(
            $this->uuid->generate(),
            $message->osId,
            $message->sagaId,
            $message->correlationId,
            $customer,
            $items,
            $this->clock->now(),
        );

        try {
            $this->budgets->save($budget, $this->messages->fromBudget($budget), $message->id);
        } catch (InsufficientStockException) {
            // A transação voltou inteira; registra a falha com a mesma mensagem na inbox.
            return $this->fail($message, $customer, self::REASON_INSUFFICIENT_STOCK);
        } catch (ConcurrencyException $e) {
            // Outro processo gerou o orçamento desta OS entre a leitura e a gravação: é o mesmo
            // comando, já atendido.
            if ($this->budgetOf($message) instanceof Budget) {
                return self::OUTCOME_DUPLICATE;
            }
            throw $e;
        }

        try {
            $this->notifier->budgetReady($budget);
        } catch (\Throwable) {
            // O aviso ao cliente é cortesia: o orçamento já existe e a OS já sabe dele.
        }

        return self::OUTCOME_OK;
    }

    private function fail(Message $message, Customer $customer, string $reason): string
    {
        $budget = Budget::failed(
            $this->uuid->generate(),
            $message->osId,
            $message->sagaId,
            $message->correlationId,
            $customer,
            $reason,
            $this->clock->now(),
        );
        $this->budgets->save($budget, $this->messages->fromBudget($budget), $message->id);

        return self::OUTCOME_OK;
    }

    private function charge(Message $message): string
    {
        $budget = $this->budgetOf($message);
        if ($budget === null) {
            // A saga só cobra depois de budget.approved: sem orçamento, a mensagem volta à fila
            // e, se persistir, vai para a DLQ, que tem alerta.
            throw new NotFoundException("Não há orçamento para a OS {$message->osId}.");
        }
        if ($budget->getPayment() !== null || $budget->getStatus()->isClosed()) {
            return self::OUTCOME_DUPLICATE;
        }
        if ($budget->getStatus() !== BudgetStatus::Approved) {
            throw new DomainException("O orçamento {$budget->getId()} está em {$budget->getStatus()->value} e não pode ser cobrado.");
        }

        $now = $this->clock->now();
        // O prazo sai do comando (ou da hora em que ele foi emitido), nunca do relógio: a cobrança
        // repetida depois de uma falha tem de mandar o mesmo corpo com a mesma chave.
        $expiresAt = $this->expiresAt($message->payload['expires_at'] ?? null, $message->occurredAt, $now);
        $charge    = $this->gateway->createCharge($budget, $expiresAt);

        $budget->attachPayment(
            new Payment($this->uuid->generate(), $charge->preferenceId, $charge->paymentUrl, $budget->total(), PaymentStatus::Pending, null, null, $expiresAt, $now),
            $now,
        );
        $this->budgets->save($budget, $this->messages->fromBudget($budget), $message->id);

        return self::OUTCOME_OK;
    }

    private function cancel(Message $message): string
    {
        $budget = $this->budgetOf($message);
        if ($budget === null) {
            // Compensação de algo que não existe: confirmada sem efeito (§6.2).
            $this->budgets->record(
                [$this->messages->replyTo($message, 'budget.cancelled', ['budget_id' => $message->payload['budget_id'] ?? null])],
                $message->id,
            );

            return self::OUTCOME_OK;
        }

        // Cancelar um orçamento pago sem devolver o dinheiro deixaria o cliente sem as peças e sem
        // o pagamento. Se a saga já pediu o estorno, ele já aconteceu e aqui não há nada a fazer.
        $this->refundConfirmed($budget);
        $budget->cancel((string)($message->payload['reason'] ?? 'cancelado pela saga'), $this->clock->now());
        $this->budgets->save($budget, $this->messages->fromBudget($budget), $message->id);

        return self::OUTCOME_OK;
    }

    private function refund(Message $message): string
    {
        $budget = $this->budgetOf($message);
        if ($budget === null) {
            $this->budgets->record([$this->messages->replyTo($message, 'payment.refunded', ['payment_id' => null])], $message->id);

            return self::OUTCOME_OK;
        }

        if (!$this->refundConfirmed($budget)) {
            $budget->recordRefund($this->clock->now());
        }
        $this->budgets->save($budget, $this->messages->fromBudget($budget), $message->id);

        return self::OUTCOME_OK;
    }

    /**
     * Estorna no meio de pagamento o pagamento confirmado do orçamento, se houver, e registra.
     * A chave é do pagamento: o mesmo estorno pedido duas vezes é um estorno só lá.
     */
    private function refundConfirmed(Budget $budget): bool
    {
        $payment = $budget->getPayment();
        if (!$budget->hasConfirmedPayment() || $payment?->externalPaymentId === null) {
            return false;
        }

        $this->gateway->refund($payment->externalPaymentId, 'refund-' . $payment->id);
        $budget->recordRefund($this->clock->now());

        return true;
    }

    private function budgetOf(Message $message): ?Budget
    {
        $id = $message->payload['budget_id'] ?? null;

        return (is_string($id) && $id !== '' ? $this->budgets->find($id) : null) ?? $this->budgets->findByOsId($message->osId);
    }

    private function customer(mixed $data): Customer
    {
        $data = is_array($data) ? $data : [];

        return new Customer((string)($data['id'] ?? ''), (string)($data['name'] ?? ''), (string)($data['email'] ?? ''));
    }

    /**
     * Põe preço em cada item com o catálogo. Item inexistente (ou lista vazia) devolve null.
     *
     * @return list<BudgetItem>|null
     */
    private function priceItems(mixed $raw): ?array
    {
        if (!is_array($raw) || $raw === []) {
            return null;
        }

        $items = [];
        foreach ($raw as $entry) {
            $entry    = is_array($entry) ? $entry : [];
            $kind     = (string)($entry['kind'] ?? '');
            $id       = (string)($entry['id'] ?? '');
            $quantity = max(1, (int)($entry['quantity'] ?? 1));

            if ($kind === BudgetItem::KIND_SERVICE && ($service = $this->catalog->findServiceItem($id)) !== null) {
                $items[] = new BudgetItem($kind, $id, $service->getDescription(), $service->getBasePrice(), $quantity);
            } elseif ($kind === BudgetItem::KIND_PART && ($part = $this->catalog->findPart($id)) !== null) {
                $items[] = new BudgetItem($kind, $id, $part->getDescription(), $part->getPrice(), $quantity);
            } else {
                return null;
            }
        }

        return $items;
    }

    private function expiresAt(mixed $value, string $occurredAt, \DateTimeImmutable $now): \DateTimeImmutable
    {
        foreach ([[$value, ''], [$occurredAt, self::DEFAULT_CHARGE_TTL]] as [$base, $ttl]) {
            if (is_string($base) && $base !== '') {
                try {
                    $date = new \DateTimeImmutable($base);

                    return $ttl === '' ? $date : $date->modify($ttl);
                } catch (\Exception) {
                    // formato inválido: tenta o próximo
                }
            }
        }

        return $now->modify(self::DEFAULT_CHARGE_TTL);
    }
}
