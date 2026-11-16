<?php

declare(strict_types=1);

namespace App\Domain\Budget;

use App\Domain\Exception\DomainException;

/** Uma linha do orçamento, com o preço congelado no momento da geração. */
final class BudgetItem
{
    public const KIND_SERVICE = 'service';
    public const KIND_PART    = 'part';

    public function __construct(
        public readonly string $kind,
        public readonly string $itemId,
        public readonly string $description,
        public readonly float $unitPrice,
        public readonly int $quantity,
    ) {
        if (!in_array($kind, [self::KIND_SERVICE, self::KIND_PART], true)) {
            throw new DomainException("Tipo de item inválido: {$kind}.");
        }
        if ($quantity < 1) {
            throw new DomainException('A quantidade do item deve ser de pelo menos 1.');
        }
    }

    public function total(): float
    {
        return round($this->unitPrice * $this->quantity, 2);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind'        => $this->kind,
            'id'          => $this->itemId,
            'description' => $this->description,
            'unit_price'  => $this->unitPrice,
            'quantity'    => $this->quantity,
            'total'       => $this->total(),
        ];
    }
}
