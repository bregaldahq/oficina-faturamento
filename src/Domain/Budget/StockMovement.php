<?php

declare(strict_types=1);

namespace App\Domain\Budget;

/**
 * Efeito do orçamento sobre o estoque de uma peça, aplicado pelo repositório na mesma transação
 * que grava o orçamento:
 *
 *   reserve  reservado += q          (só se o disponível cobrir; senão a transação toda falha)
 *   release  reservado -= q          (orçamento rejeitado ou cancelado antes de pago)
 *   consume  estoque -= q, reservado -= q   (pago: a peça sai do estoque)
 *   restock  estoque += q            (cancelado depois de pago: a peça volta)
 */
final class StockMovement
{
    public const RESERVE = 'reserve';
    public const RELEASE = 'release';
    public const CONSUME = 'consume';
    public const RESTOCK = 'restock';

    public function __construct(
        public readonly string $type,
        public readonly string $partId,
        public readonly int $quantity,
    ) {
    }
}
