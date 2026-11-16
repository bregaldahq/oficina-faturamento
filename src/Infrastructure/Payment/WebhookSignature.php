<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment;

/**
 * A assinatura das notificações do Mercado Pago. O cabeçalho `x-signature` traz `ts` e `v1`;
 * `v1` é o HMAC-SHA256, em hexadecimal, do manifesto `id:<data.id>;request-id:<x-request-id>;ts:<ts>;`
 * com o segredo do webhook. Um par ausente fica fora do manifesto.
 *
 * O formato não estava na documentação consultada em 2026-11-16, só em integrações de terceiros
 * que o seguem: se o Mercado Pago recusar, é aqui que se ajusta.
 */
final class WebhookSignature
{
    public function __construct(private readonly string $secret)
    {
    }

    public function isValid(string $dataId, string $requestId, string $header): bool
    {
        if ($this->secret === '' || $header === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_map('trim', explode('=', $pair, 2) + [1 => '']);
            $parts[$key]   = $value;
        }
        $ts = $parts['ts'] ?? '';
        $v1 = $parts['v1'] ?? '';
        if ($ts === '' || $v1 === '') {
            return false;
        }

        return hash_equals($this->sign($dataId, $requestId, $ts), $v1);
    }

    /** Monta o cabeçalho como o Mercado Pago o mandaria. Usado pelo adaptador falso e pelos testes. */
    public function header(string $dataId, string $requestId, string $ts): string
    {
        return "ts={$ts},v1=" . $this->sign($dataId, $requestId, $ts);
    }

    private function sign(string $dataId, string $requestId, string $ts): string
    {
        $manifest = '';
        if ($dataId !== '') {
            // ids alfanuméricos são assinados em minúsculas
            $manifest .= 'id:' . strtolower($dataId) . ';';
        }
        if ($requestId !== '') {
            $manifest .= 'request-id:' . $requestId . ';';
        }
        $manifest .= 'ts:' . $ts . ';';

        return hash_hmac('sha256', $manifest, $this->secret);
    }
}
