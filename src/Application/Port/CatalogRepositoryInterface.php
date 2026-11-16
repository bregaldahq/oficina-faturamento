<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Catalog\Part;
use App\Domain\Catalog\ServiceItem;

interface CatalogRepositoryInterface
{
    public function findServiceItem(string $id): ?ServiceItem;

    /** @return list<ServiceItem> */
    public function listServiceItems(): array;

    public function saveServiceItem(ServiceItem $item): void;

    public function deleteServiceItem(string $id): void;

    public function findPart(string $id): ?Part;

    /** @return list<Part> */
    public function listParts(): array;

    /** Grava descrição, preço e estoque. O reservado só muda pelo orçamento. */
    public function savePart(Part $part): void;

    public function deletePart(string $id): void;
}
