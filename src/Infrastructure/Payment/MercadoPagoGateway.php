<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment;

use App\Application\Payment\Charge;
use App\Application\Payment\GatewayPayment;
use App\Application\Port\PaymentGatewayInterface;
use App\Domain\Budget\Budget;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;

/**
 * Checkout Pro do Mercado Pago (ADR-015). Chamadas conferidas na documentação oficial em
 * 2026-11-16:
 *
 *   POST /checkout/preferences        cria a cobrança; external_reference = id do orçamento,
 *                                     notification_url = webhook deste serviço, expira no prazo
 *   GET  /v1/payments/{id}            o pagamento de verdade, depois do aviso do webhook
 *   POST /v1/payments/{id}/refunds    estorno total
 *
 * Toda escrita leva `X-Idempotency-Key`. A credencial vem do segredo
 * oficina/<env>/faturamento/mercadopago e nunca aparece em log.
 */
final class MercadoPagoGateway implements PaymentGatewayInterface
{
    public const BASE_URL = 'https://api.mercadopago.com';

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $accessToken,
        private readonly string $notificationUrl,
        private readonly WebhookSignature $signature,
        private readonly bool $useSandboxUrl = false,
    ) {
    }

    public function createCharge(Budget $budget, \DateTimeImmutable $expiresAt): Charge
    {
        $body = [
            'items' => [[
                'id'          => $budget->getId(),
                'title'       => 'Orçamento da ordem de serviço ' . $budget->getOsId(),
                'quantity'    => 1,
                'currency_id' => 'BRL',
                'unit_price'  => $budget->total(),
            ]],
            'payer'                => ['name' => $budget->getCustomer()->name, 'email' => $budget->getCustomer()->email],
            'external_reference'   => $budget->getId(),
            'notification_url'     => $this->notificationUrl,
            'expires'              => true,
            'expiration_date_from' => $budget->getUpdatedAt()->format('Y-m-d\TH:i:s.vP'),
            'expiration_date_to'   => $expiresAt->format('Y-m-d\TH:i:s.vP'),
        ];

        $data = $this->call('POST', '/checkout/preferences', $body, 'charge-' . $budget->getId());
        $url  = (string)($data[$this->useSandboxUrl ? 'sandbox_init_point' : 'init_point'] ?? '');
        if (!isset($data['id']) || $url === '') {
            throw new \RuntimeException('Resposta do Mercado Pago sem id ou link de pagamento.');
        }

        return new Charge((string)$data['id'], $url);
    }

    public function getPayment(string $externalPaymentId): GatewayPayment
    {
        $data = $this->call('GET', '/v1/payments/' . rawurlencode($externalPaymentId));

        return new GatewayPayment(
            (string)($data['id'] ?? $externalPaymentId),
            (string)($data['status'] ?? ''),
            (string)($data['external_reference'] ?? ''),
            (float)($data['transaction_amount'] ?? 0),
            (string)($data['status_detail'] ?? ''),
        );
    }

    public function refund(string $externalPaymentId, string $idempotencyKey): void
    {
        $this->call('POST', '/v1/payments/' . rawurlencode($externalPaymentId) . '/refunds', new \stdClass(), $idempotencyKey);
    }

    public function isAuthentic(string $dataId, string $requestId, string $signature): bool
    {
        return $this->signature->isValid($dataId, $requestId, $signature);
    }

    /**
     * @param array<string, mixed>|\stdClass|null $body
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array|\stdClass|null $body = null, ?string $idempotencyKey = null): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->accessToken, 'Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($idempotencyKey !== null) {
            $headers['X-Idempotency-Key'] = $idempotencyKey;
        }

        $response = $this->http->send(
            new Request($method, self::BASE_URL . $path, $headers, $body === null ? null : (string)json_encode($body)),
            ['http_errors' => false, 'timeout' => 10],
        );

        $status = $response->getStatusCode();
        $data   = json_decode((string)$response->getBody(), true);
        if ($status >= 300) {
            $message = is_array($data) ? (string)($data['message'] ?? '') : '';
            throw new \RuntimeException("Mercado Pago respondeu {$status} em {$method} {$path}" . ($message !== '' ? ": {$message}" : '.'));
        }

        return is_array($data) ? $data : [];
    }
}
