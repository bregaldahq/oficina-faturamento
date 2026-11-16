<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\UseCase\Budget\BudgetQueries;
use App\Application\UseCase\Budget\ReviewBudgetUseCase;
use App\Domain\Exception\DomainException;

class BudgetController
{
    use JsonResponder;

    public function __construct(
        private readonly BudgetQueries $queries,
        private readonly ReviewBudgetUseCase $review,
        private readonly string $webhookToken,
    ) {
    }

    public function index(): void
    {
        $this->respond(fn (): array => ['data' => $this->queries->list()]);
    }

    /**
     * @param array<string, string> $params
     * @param array<string, mixed>  $claims
     */
    public function show(array $params, array $claims): void
    {
        $this->respond(fn (): array => $this->queries->byId($params['id'], $claims));
    }

    /**
     * @param array<string, string> $params
     * @param array<string, mixed>  $claims
     */
    public function byOrder(array $params, array $claims): void
    {
        $this->respond(fn (): array => $this->queries->byOrder($params['orderId'], $claims));
    }

    /**
     * @param array<string, string> $params
     * @param array<string, mixed>  $claims
     */
    public function payment(array $params, array $claims): void
    {
        $this->respond(fn (): array => $this->queries->payment($params['id'], $claims));
    }

    /**
     * Webhook de aprovação: rota sem JWT, protegida pelo X-Webhook-Token
     * (docs/fase-4/CONTRATOS.md §4, no oficina-api).
     *
     * @param array<string, string> $params
     * @param array<string, mixed>  $body
     */
    public function approval(array $params, array $body, string $token): void
    {
        if ($this->webhookToken === '' || !hash_equals($this->webhookToken, $token)) {
            $this->error(401, 'Token do webhook inválido.');

            return;
        }

        $this->respond(function () use ($params, $body): array {
            if (!is_bool($body['approved'] ?? null)) {
                throw new DomainException("Informe 'approved' como true ou false.");
            }

            return $this->review->execute($params['id'], $body['approved'])->toArray();
        });
    }
}
