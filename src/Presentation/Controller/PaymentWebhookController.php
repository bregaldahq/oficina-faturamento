<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\Port\LoggerInterface;
use App\Application\Port\PaymentGatewayInterface;
use App\Application\UseCase\HandlePaymentNotificationUseCase;

/**
 * Notificações do Mercado Pago. A rota é pública no gateway; a autenticidade vem da assinatura,
 * e o estado do pagamento, da consulta à API (ADR-015).
 *
 * Responde 200 a tudo o que foi tratado ou não interessa, para o Mercado Pago não reenviar; e
 * 500 quando o tratamento falhou, para ele reenviar.
 */
class PaymentWebhookController
{
    use JsonResponder;

    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly HandlePaymentNotificationUseCase $handler,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed>  $query   o PHP troca o ponto de `data.id` por `_`
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers x-signature e x-request-id
     */
    public function mercadoPago(array $query, array $body, array $headers): void
    {
        $data   = is_array($body['data'] ?? null) ? $body['data'] : [];
        $dataId = (string)($query['data_id'] ?? $query['id'] ?? $data['id'] ?? '');
        $type   = (string)($query['type'] ?? $query['topic'] ?? $body['type'] ?? '');

        if (!$this->gateway->isAuthentic($dataId, $headers['x-request-id'] ?? '', $headers['x-signature'] ?? '')) {
            $this->logger->error('payment.webhook_rejected', ['data_id' => $dataId, 'type' => $type]);
            $this->error(401, 'Assinatura inválida.');

            return;
        }

        if ($type !== 'payment' || $dataId === '') {
            http_response_code(200);
            echo json_encode(['outcome' => 'ignored']);

            return;
        }

        try {
            $outcome = $this->handler->execute($dataId);
            $this->logger->info('payment.webhook', ['data_id' => $dataId, 'outcome' => $outcome]);
            http_response_code(200);
            echo json_encode(['outcome' => $outcome]);
        } catch (\Throwable $e) {
            $this->logger->error('payment.webhook_failed', [
                'data_id'           => $dataId,
                'exception_class'   => $e::class,
                'exception_message' => $e->getMessage(),
            ]);
            $this->error(500, 'Falha ao processar a notificação; reenvie.');
        }
    }
}
