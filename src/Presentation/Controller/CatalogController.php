<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\Port\CatalogRepositoryInterface;
use App\Domain\Catalog\Part;
use App\Domain\Catalog\ServiceItem;
use App\Domain\Exception\NotFoundException;
use App\Domain\UuidGeneratorInterface;

/**
 * Catálogo de serviços e estoque de peças, as mesmas operações que o serviço de OS tinha na
 * Fase 3, agora sob /api/billing (docs/fase-4/CONTRATOS.md §4, no oficina-api). Tudo de admin.
 */
class CatalogController
{
    use JsonResponder;

    public function __construct(
        private readonly CatalogRepositoryInterface $catalog,
        private readonly UuidGeneratorInterface $uuid,
    ) {
    }

    public function listServices(): void
    {
        $this->respond(fn (): array => ['data' => array_map(static fn (ServiceItem $s): array => $s->toArray(), $this->catalog->listServiceItems())]);
    }

    /** @param array<string, string> $params */
    public function showService(array $params): void
    {
        $this->respond(fn (): array => $this->service($params['id'])->toArray());
    }

    /** @param array<string, mixed> $body */
    public function createService(array $body): void
    {
        $this->respond(function () use ($body): array {
            $item = $this->buildService($this->uuid->generate(), $body);
            $this->catalog->saveServiceItem($item);

            return $item->toArray();
        }, 201);
    }

    /**
     * @param array<string, string> $params
     * @param array<string, mixed>  $body
     */
    public function updateService(array $params, array $body): void
    {
        $this->respond(function () use ($params, $body): array {
            $this->service($params['id']);
            $item = $this->buildService($params['id'], $body);
            $this->catalog->saveServiceItem($item);

            return $item->toArray();
        });
    }

    /** @param array<string, string> $params */
    public function deleteService(array $params): void
    {
        $this->respond(function () use ($params): ?array {
            $this->service($params['id']);
            $this->catalog->deleteServiceItem($params['id']);

            return null;
        });
    }

    public function listParts(): void
    {
        $this->respond(fn (): array => ['data' => array_map(static fn (Part $p): array => $p->toArray(), $this->catalog->listParts())]);
    }

    /** @param array<string, string> $params */
    public function showPart(array $params): void
    {
        $this->respond(fn (): array => $this->part($params['id'])->toArray());
    }

    /** @param array<string, mixed> $body */
    public function createPart(array $body): void
    {
        $this->respond(function () use ($body): array {
            $part = new Part(
                $this->uuid->generate(),
                $this->requireString($body, 'description'),
                $this->requireNumber($body, 'price'),
                $this->requireInt($body, 'stock_quantity'),
            );
            $this->catalog->savePart($part);

            return $part->toArray();
        }, 201);
    }

    /**
     * @param array<string, string> $params
     * @param array<string, mixed>  $body
     */
    public function updatePart(array $params, array $body): void
    {
        $this->respond(function () use ($params, $body): array {
            $current = $this->part($params['id']);
            $part    = new Part(
                $current->getId(),
                $this->requireString($body, 'description'),
                $this->requireNumber($body, 'price'),
                $current->getStockQuantity(),
                $current->getReservedQuantity(),
            );
            $this->catalog->savePart($part);

            return $part->toArray();
        });
    }

    /**
     * @param array<string, string> $params
     * @param array<string, mixed>  $body
     */
    public function updateStock(array $params, array $body): void
    {
        $this->respond(function () use ($params, $body): array {
            $part = $this->part($params['id']);
            $part->setStock($this->requireInt($body, 'stock_quantity'));
            $this->catalog->savePart($part);

            return $this->part($params['id'])->toArray();
        });
    }

    /** @param array<string, string> $params */
    public function deletePart(array $params): void
    {
        $this->respond(function () use ($params): ?array {
            $this->part($params['id']);
            $this->catalog->deletePart($params['id']);

            return null;
        });
    }

    private function service(string $id): ServiceItem
    {
        return $this->catalog->findServiceItem($id) ?? throw new NotFoundException('Serviço não encontrado.');
    }

    private function part(string $id): Part
    {
        return $this->catalog->findPart($id) ?? throw new NotFoundException('Peça não encontrada.');
    }

    /** @param array<string, mixed> $body */
    private function buildService(string $id, array $body): ServiceItem
    {
        return new ServiceItem(
            $id,
            $this->requireString($body, 'description'),
            $this->requireNumber($body, 'base_price'),
            $this->requireInt($body, 'estimated_time_minutes'),
        );
    }
}
