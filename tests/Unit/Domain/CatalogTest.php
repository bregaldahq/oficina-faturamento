<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Budget\BudgetItem;
use App\Domain\Catalog\Part;
use App\Domain\Catalog\ServiceItem;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\InvalidBudgetTransitionException;
use PHPUnit\Framework\TestCase;

class CatalogTest extends TestCase
{
    public function testServicoEPeca(): void
    {
        $service = new ServiceItem('s', 'Alinhamento', 150.0, 60);
        $part    = new Part('p', 'Filtro', 38.9, 10, 3);

        $this->assertSame(['id' => 's', 'description' => 'Alinhamento', 'base_price' => 150.0, 'estimated_time_minutes' => 60], $service->toArray());
        $this->assertSame(7, $part->getAvailableQuantity());
        $this->assertSame(3, $part->toArray()['reserved_quantity']);
    }

    public function testEstoqueNaoFicaAbaixoDoReservado(): void
    {
        $part = new Part('p', 'Filtro', 38.9, 10, 3);
        $part->setStock(3);
        $this->assertSame(3, $part->getStockQuantity());

        $this->expectException(DomainException::class);
        $part->setStock(2);
    }

    /** @return array<string, array{0: callable(): mixed}> */
    public static function invalidos(): array
    {
        return [
            'serviço sem descrição'  => [fn () => new ServiceItem('s', ' ', 1, 1)],
            'serviço preço negativo' => [fn () => new ServiceItem('s', 'x', -1, 1)],
            'serviço tempo negativo' => [fn () => new ServiceItem('s', 'x', 1, -1)],
            'peça sem descrição'     => [fn () => new Part('p', '', 1, 1)],
            'peça preço negativo'    => [fn () => new Part('p', 'x', -1, 1)],
            'peça estoque negativo'  => [fn () => new Part('p', 'x', 1, -1)],
            'item tipo inválido'     => [fn () => new BudgetItem('outro', 'x', 'd', 1, 1)],
            'item quantidade zero'   => [fn () => new BudgetItem('part', 'x', 'd', 1, 0)],
        ];
    }

    /** @param callable(): mixed $build */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidos')]
    public function testDadosInvalidosSaoRecusados(callable $build): void
    {
        $this->expectException(DomainException::class);

        $build();
    }

    public function testItemCalculaOTotalArredondado(): void
    {
        $this->assertSame(['kind' => 'part', 'id' => 'p', 'description' => 'd', 'unit_price' => 33.335, 'quantity' => 3, 'total' => 100.01], (new BudgetItem('part', 'p', 'd', 33.335, 3))->toArray());
    }

    public function testMensagemDeTransicaoInvalida(): void
    {
        $this->assertStringContainsString('PAID', InvalidBudgetTransitionException::from('PAID', 'aprovar')->getMessage());
    }
}
