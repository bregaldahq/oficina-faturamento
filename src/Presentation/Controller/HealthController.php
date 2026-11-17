<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

/**
 * Liveness e readiness são rotas separadas, como no serviço de OS.
 *
 * live() nunca toca em dependência: uma sonda de liveness presa ao banco transforma uma
 * oscilação do banco em reinício de todos os Pods. A saúde das dependências é assunto da
 * readiness, que só tira o Pod do balanceador.
 */
class HealthController
{
    private const VERSION = '1.0.0';

    /** @var \Closure(): bool */
    private \Closure $dependencyCheck;

    /** @param callable(): bool $dependencyCheck devolve true quando a dependência responde */
    public function __construct(private readonly string $dependencyName, callable $dependencyCheck)
    {
        $this->dependencyCheck = $dependencyCheck(...);
    }

    public function live(): void
    {
        http_response_code(200);

        echo json_encode([
            'status'    => 'ok',
            'service'   => 'oficina-faturamento',
            'version'   => self::VERSION,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }

    public function ready(): void
    {
        try {
            $ready = ($this->dependencyCheck)();
        } catch (\Throwable) {
            $ready = false;
        }

        http_response_code($ready ? 200 : 503);

        echo json_encode([
            'status'              => $ready ? 'ready' : 'not_ready',
            'service'             => 'oficina-faturamento',
            'version'             => self::VERSION,
            $this->dependencyName => $ready ? 'connected' : 'error',
            'timestamp'           => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }
}
