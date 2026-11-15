<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Controller;

use App\Presentation\Controller\HealthController;
use PHPUnit\Framework\TestCase;

class HealthControllerTest extends TestCase
{
    protected function setUp(): void
    {
        http_response_code(200);
    }

    /** @return array<string, mixed> */
    private function call(HealthController $controller, string $method): array
    {
        ob_start();
        $controller->{$method}();

        return (array)json_decode((string)ob_get_clean(), true);
    }

    public function testLiveNaoConsultaADependencia(): void
    {
        $called     = false;
        $controller = new HealthController('database', function () use (&$called): bool {
            $called = true;

            return false;
        });

        $body = $this->call($controller, 'live');

        $this->assertSame(200, http_response_code());
        $this->assertSame('ok', $body['status']);
        $this->assertSame('oficina-faturamento', $body['service']);
        $this->assertFalse($called);
    }

    public function testReadyCom200QuandoADependenciaResponde(): void
    {
        $body = $this->call(new HealthController('database', static fn (): bool => true), 'ready');

        $this->assertSame(200, http_response_code());
        $this->assertSame('ready', $body['status']);
        $this->assertSame('connected', $body['database']);
    }

    public function testReadyCom503QuandoADependenciaNega(): void
    {
        $body = $this->call(new HealthController('database', static fn (): bool => false), 'ready');

        $this->assertSame(503, http_response_code());
        $this->assertSame('not_ready', $body['status']);
        $this->assertSame('error', $body['database']);
    }

    public function testReadyCom503QuandoADependenciaLancaExcecao(): void
    {
        $controller = new HealthController('dynamodb', static function (): bool {
            throw new \RuntimeException('fora do ar');
        });

        $body = $this->call($controller, 'ready');

        $this->assertSame(503, http_response_code());
        $this->assertSame('error', $body['dynamodb']);
    }
}
