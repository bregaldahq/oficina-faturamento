<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Exception\DomainException;

/** Serviço do catálogo, com o preço de referência do orçamento. */
final class ServiceItem
{
    public function __construct(
        private readonly string $id,
        private readonly string $description,
        private readonly float $basePrice,
        private readonly int $estimatedTimeMinutes,
    ) {
        if (trim($description) === '') {
            throw new DomainException('Informe a descrição do serviço.');
        }
        if ($basePrice < 0) {
            throw new DomainException('O preço do serviço não pode ser negativo.');
        }
        if ($estimatedTimeMinutes < 0) {
            throw new DomainException('O tempo estimado não pode ser negativo.');
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getBasePrice(): float
    {
        return $this->basePrice;
    }

    public function getEstimatedTimeMinutes(): int
    {
        return $this->estimatedTimeMinutes;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'                     => $this->id,
            'description'            => $this->description,
            'base_price'             => $this->basePrice,
            'estimated_time_minutes' => $this->estimatedTimeMinutes,
        ];
    }
}
