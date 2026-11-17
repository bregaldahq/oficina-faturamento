<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Controller;

use PHPUnit\Framework\TestCase;
use Tests\Support\Harness;

class CatalogControllerTest extends TestCase
{
    private Harness $h;

    protected function setUp(): void
    {
        $this->h = new Harness();
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function call(string $action, mixed ...$args): array
    {
        return Harness::call($this->h->catalogController(), $action, ...$args);
    }

    public function testCicloDeVidaDoServico(): void
    {
        [$status, $created] = $this->call('createService', ['description' => 'Alinhamento', 'base_price' => 150, 'estimated_time_minutes' => 60]);
        $this->assertSame(201, $status);
        $id = ['id' => $created['id']];

        $this->assertEquals(150.0, $this->call('showService', $id)[1]['base_price']);
        $this->assertSame(200, $this->call('updateService', $id, ['description' => 'Alinhamento 3D', 'base_price' => 199.9, 'estimated_time_minutes' => 70])[0]);
        $this->assertSame('Alinhamento 3D', $this->h->catalog->findServiceItem($created['id'])?->getDescription());
        $this->assertCount(2, $this->call('listServices')[1]['data']);
        $this->assertSame(204, $this->call('deleteService', $id)[0]);
        $this->assertSame(404, $this->call('showService', $id)[0]);
        $this->assertSame(404, $this->call('deleteService', $id)[0]);
        $this->assertSame(404, $this->call('updateService', $id, [])[0]);
    }

    public function testCicloDeVidaDaPeca(): void
    {
        [$status, $created] = $this->call('createPart', ['description' => 'Filtro de ar', 'price' => 62, 'stock_quantity' => 10]);
        $this->assertSame(201, $status);
        $id = ['id' => $created['id']];

        [$status, $body] = $this->call('updatePart', $id, ['description' => 'Filtro de ar do motor', 'price' => 64.5]);
        $this->assertSame(200, $status);
        $this->assertSame(10, $body['stock_quantity'], 'atualizar a peça não mexe no estoque');

        [$status, $body] = $this->call('updateStock', $id, ['stock_quantity' => 25]);
        $this->assertSame(200, $status);
        $this->assertSame(25, $body['available_quantity']);

        $this->assertCount(2, $this->call('listParts')[1]['data']);
        $this->assertSame(64.5, $this->call('showPart', $id)[1]['price']);
        $this->assertSame(204, $this->call('deletePart', $id)[0]);
        $this->assertSame(404, $this->call('showPart', $id)[0]);
    }

    public function testEstoqueReservadoProtegeAPeca(): void
    {
        $this->h->send($this->h->command('billing.generate-budget', 'os-1', $this->h->generatePayload(3)));
        $id = ['id' => Harness::PART];

        [$status, $body] = $this->call('updateStock', $id, ['stock_quantity' => 2]);
        $this->assertSame(422, $status);
        $this->assertStringContainsString('reservado', $body['error']);
        $this->assertSame(422, $this->call('deletePart', $id)[0]);
    }

    public function testValidacaoDoCorpo(): void
    {
        foreach ([
            ['createService', [[]]],
            ['createService', [['description' => 'x', 'base_price' => '10', 'estimated_time_minutes' => 1]]],
            ['createService', [['description' => 'x', 'base_price' => 10, 'estimated_time_minutes' => 1.5]]],
            ['createService', [['description' => 'x', 'base_price' => -1, 'estimated_time_minutes' => 1]]],
            ['createPart', [['description' => 'x', 'price' => 1, 'stock_quantity' => -1]]],
            ['updateStock', [['id' => Harness::PART], ['stock_quantity' => 'dez']]],
        ] as [$action, $args]) {
            $this->assertSame(422, $this->call($action, ...$args)[0], $action . ' ' . json_encode($args));
        }
    }
}
