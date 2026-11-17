<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Exception\DomainException;

/**
 * Peça do estoque. O saldo disponível é o estoque menos o que está reservado para orçamentos
 * em aberto. A reserva, a liberação e a baixa acontecem pelo orçamento (StockMovement), na
 * mesma transação que o grava; aqui só ficam os dados da peça e o ajuste manual de estoque.
 */
final class Part
{
    public function __construct(
        private readonly string $id,
        private readonly string $description,
        private readonly float $price,
        private int $stockQuantity,
        private readonly int $reservedQuantity = 0,
    ) {
        if (trim($description) === '') {
            throw new DomainException('Informe a descrição da peça.');
        }
        if ($price < 0) {
            throw new DomainException('O preço da peça não pode ser negativo.');
        }
        if ($stockQuantity < 0) {
            throw new DomainException('O estoque não pode ser negativo.');
        }
    }

    /** Ajuste manual do estoque (entrada de mercadoria, inventário). Nunca abaixo do reservado. */
    public function setStock(int $quantity): void
    {
        if ($quantity < $this->reservedQuantity) {
            throw new DomainException(
                "O estoque não pode ficar abaixo do reservado para orçamentos em aberto ({$this->reservedQuantity})."
            );
        }
        $this->stockQuantity = $quantity;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function getStockQuantity(): int
    {
        return $this->stockQuantity;
    }

    public function getReservedQuantity(): int
    {
        return $this->reservedQuantity;
    }

    public function getAvailableQuantity(): int
    {
        return $this->stockQuantity - $this->reservedQuantity;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'                 => $this->id,
            'description'        => $this->description,
            'price'              => $this->price,
            'stock_quantity'     => $this->stockQuantity,
            'reserved_quantity'  => $this->reservedQuantity,
            'available_quantity' => $this->getAvailableQuantity(),
        ];
    }
}
