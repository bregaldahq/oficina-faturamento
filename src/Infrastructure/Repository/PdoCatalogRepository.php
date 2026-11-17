<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Application\Port\CatalogRepositoryInterface;
use App\Domain\Catalog\Part;
use App\Domain\Catalog\ServiceItem;
use App\Domain\Exception\DomainException;
use PDO;

final class PdoCatalogRepository implements CatalogRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findServiceItem(string $id): ?ServiceItem
    {
        $stmt = $this->pdo->prepare('SELECT * FROM service_catalog WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->serviceItem($row) : null;
    }

    public function listServiceItems(): array
    {
        $rows = $this->all('SELECT * FROM service_catalog ORDER BY description');

        return array_values(array_map(fn (array $row): ServiceItem => $this->serviceItem($row), $rows));
    }

    public function saveServiceItem(ServiceItem $item): void
    {
        $params = [
            'id'      => $item->getId(),
            'd'       => $item->getDescription(),
            'p'       => $item->getBasePrice(),
            'minutes' => $item->getEstimatedTimeMinutes(),
        ];
        $update = $this->pdo->prepare(
            'UPDATE service_catalog SET description = :d, base_price = :p, estimated_time_minutes = :minutes WHERE id = :id'
        );
        $update->execute($params);
        if ($update->rowCount() === 0 && $this->findServiceItem($item->getId()) === null) {
            $this->pdo->prepare(
                'INSERT INTO service_catalog (id, description, base_price, estimated_time_minutes) VALUES (:id, :d, :p, :minutes)'
            )->execute($params);
        }
    }

    public function deleteServiceItem(string $id): void
    {
        $this->pdo->prepare('DELETE FROM service_catalog WHERE id = :id')->execute(['id' => $id]);
    }

    public function findPart(string $id): ?Part
    {
        $stmt = $this->pdo->prepare('SELECT * FROM parts_inventory WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->part($row) : null;
    }

    public function listParts(): array
    {
        $rows = $this->all('SELECT * FROM parts_inventory ORDER BY description');

        return array_values(array_map(fn (array $row): Part => $this->part($row), $rows));
    }

    public function savePart(Part $part): void
    {
        $params = ['id' => $part->getId(), 'd' => $part->getDescription(), 'p' => $part->getPrice(), 's' => $part->getStockQuantity()];

        // O estoque nunca fica abaixo do que está reservado, mesmo que uma reserva tenha entrado
        // entre a leitura e esta gravação.
        $update = $this->pdo->prepare(
            'UPDATE parts_inventory SET description = :d, price = :p, stock_quantity = :s, version = version + 1
             WHERE id = :id AND reserved_quantity <= :floor'
        );
        $update->execute($params + ['floor' => $part->getStockQuantity()]);
        if ($update->rowCount() > 0) {
            return;
        }

        $current = $this->findPart($part->getId());
        if ($current !== null) {
            throw new DomainException(
                "O estoque não pode ficar abaixo do reservado para orçamentos em aberto ({$current->getReservedQuantity()})."
            );
        }

        $this->pdo->prepare(
            'INSERT INTO parts_inventory (id, description, price, stock_quantity, reserved_quantity, version) VALUES (:id, :d, :p, :s, 0, 0)'
        )->execute($params);
    }

    public function deletePart(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM parts_inventory WHERE id = :id AND reserved_quantity = 0');
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() === 0 && $this->findPart($id) !== null) {
            throw new DomainException('A peça está reservada para orçamentos em aberto e não pode ser removida.');
        }
    }

    /** @return list<array<string, mixed>> */
    private function all(string $sql): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @param array<string, mixed> $row */
    private function serviceItem(array $row): ServiceItem
    {
        return new ServiceItem((string)$row['id'], (string)$row['description'], (float)$row['base_price'], (int)$row['estimated_time_minutes']);
    }

    /** @param array<string, mixed> $row */
    private function part(array $row): Part
    {
        return new Part(
            (string)$row['id'],
            (string)$row['description'],
            (float)$row['price'],
            (int)$row['stock_quantity'],
            (int)$row['reserved_quantity'],
        );
    }
}
