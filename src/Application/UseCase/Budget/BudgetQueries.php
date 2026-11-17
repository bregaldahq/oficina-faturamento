<?php

declare(strict_types=1);

namespace App\Application\UseCase\Budget;

use App\Application\Port\BudgetRepositoryInterface;
use App\Domain\Budget\Budget;
use App\Domain\Exception\NotFoundException;

/**
 * Leituras do orçamento e do pagamento, com a regra de quem pode ver: o admin vê tudo, o cliente
 * só o que é dele. Para o cliente, o que não é dele simplesmente não existe (404, não 403).
 */
final class BudgetQueries
{
    public function __construct(private readonly BudgetRepositoryInterface $budgets)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return array_map(static fn (Budget $b): array => $b->toArray(), $this->budgets->list());
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @return array<string, mixed>
     */
    public function byId(string $id, array $claims): array
    {
        return $this->visible($this->budgets->find($id), $claims)->toArray();
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @return array<string, mixed>
     */
    public function byOrder(string $osId, array $claims): array
    {
        return $this->visible($this->budgets->findByOsId($osId), $claims)->toArray();
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @return array<string, mixed>
     */
    public function payment(string $paymentId, array $claims): array
    {
        $budget  = $this->visible($this->budgets->findByPaymentId($paymentId), $claims, 'Pagamento não encontrado.');
        $payment = $budget->getPayment();
        if ($payment === null) {
            throw new NotFoundException('Pagamento não encontrado.');
        }

        return $payment->toArray() + ['budget_id' => $budget->getId(), 'os_id' => $budget->getOsId()];
    }

    /** @param array<string, mixed> $claims */
    private function visible(?Budget $budget, array $claims, string $notFound = 'Orçamento não encontrado.'): Budget
    {
        $isAdmin = ($claims['role'] ?? null) === 'admin';
        if ($budget === null || (!$isAdmin && $budget->getCustomer()->id !== ($claims['sub'] ?? null))) {
            throw new NotFoundException($notFound);
        }

        return $budget;
    }
}
