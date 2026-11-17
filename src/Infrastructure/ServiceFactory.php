<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Application\Messaging\MessageFactory;
use App\Application\Messaging\OutboxRelay;
use App\Application\Port\LoggerInterface;
use App\Application\Port\PaymentGatewayInterface;
use App\Application\UseCase\Budget\BudgetQueries;
use App\Application\UseCase\Budget\ReviewBudgetUseCase;
use App\Application\UseCase\HandleIncomingMessageUseCase;
use App\Application\UseCase\HandlePaymentNotificationUseCase;
use App\Application\Worker\Worker;
use App\Infrastructure\Aws\AwsClientFactory;
use App\Infrastructure\Aws\SnsMessagePublisher;
use App\Infrastructure\Aws\SqsMessageQueue;
use App\Infrastructure\Database\PdoConnection;
use App\Infrastructure\Notification\LogBudgetNotifier;
use App\Infrastructure\Payment\FakePaymentGateway;
use App\Infrastructure\Payment\MercadoPagoGateway;
use App\Infrastructure\Payment\WebhookSignature;
use App\Infrastructure\Repository\PdoBudgetRepository;
use App\Infrastructure\Repository\PdoCatalogRepository;
use App\Infrastructure\Time\SystemClock;
use App\Infrastructure\Tracing\NewRelicBusinessEvents;
use App\Infrastructure\Tracing\NewRelicTracer;
use App\Presentation\Controller\BudgetController;
use App\Presentation\Controller\CatalogController;
use App\Presentation\Controller\PaymentWebhookController;
use GuzzleHttp\Client;

/**
 * Monta as dependências a partir do ambiente. É a única parte que conhece as classes concretas;
 * public/index.php (API) e bin/worker.php (worker) usam a mesma montagem.
 */
final class ServiceFactory
{
    private ?PdoBudgetRepository $budgets     = null;
    private ?PaymentGatewayInterface $gateway = null;

    /** @param array<string, mixed> $env */
    public function __construct(private readonly array $env, private readonly ?\PDO $pdo = null)
    {
    }

    public function budgets(): PdoBudgetRepository
    {
        $uuid = new UuidGenerator();

        return $this->budgets ??= new PdoBudgetRepository($this->pdo(), static fn (): string => $uuid->generate());
    }

    public function catalog(): PdoCatalogRepository
    {
        return new PdoCatalogRepository($this->pdo());
    }

    /** PAYMENT_GATEWAY=fake troca o Mercado Pago pelo adaptador falso (testes e e2e). */
    public function gateway(): PaymentGatewayInterface
    {
        if ($this->gateway !== null) {
            return $this->gateway;
        }

        $signature = new WebhookSignature($this->string('MP_WEBHOOK_SECRET'));
        if ($this->string('PAYMENT_GATEWAY') === 'fake') {
            return $this->gateway = new FakePaymentGateway($signature);
        }

        return $this->gateway = new MercadoPagoGateway(
            new Client(),
            $this->string('MP_ACCESS_TOKEN'),
            $this->string('MP_NOTIFICATION_URL'),
            $signature,
            $this->string('MP_USE_SANDBOX_URL') === 'true',
        );
    }

    public function relay(LoggerInterface $logger): OutboxRelay
    {
        return new OutboxRelay(
            $this->budgets(),
            new SnsMessagePublisher($this->aws()->sns(), $this->string('MESSAGING_TOPIC_ARN')),
            $this->tracer(),
            $logger,
        );
    }

    public function catalogController(): CatalogController
    {
        return new CatalogController($this->catalog(), new UuidGenerator());
    }

    public function budgetController(LoggerInterface $logger): BudgetController
    {
        return new BudgetController(
            new BudgetQueries($this->budgets()),
            new ReviewBudgetUseCase($this->budgets(), $this->messages(), new SystemClock(), $this->relay($logger)),
            $this->string('WEBHOOK_TOKEN'),
        );
    }

    public function paymentWebhookController(LoggerInterface $logger): PaymentWebhookController
    {
        return new PaymentWebhookController(
            $this->gateway(),
            new HandlePaymentNotificationUseCase(
                $this->budgets(),
                $this->gateway(),
                $this->messages(),
                new SystemClock(),
                $this->relay($logger),
                $logger,
                new NewRelicBusinessEvents($this->string('APP_ENV') ?: 'local'),
            ),
            $logger,
        );
    }

    public function worker(LoggerInterface $logger): Worker
    {
        return new Worker(
            $this->relay($logger),
            new SqsMessageQueue($this->aws()->sqs(), $this->string('MESSAGING_QUEUE_URL')),
            new HandleIncomingMessageUseCase(
                $this->budgets(),
                $this->catalog(),
                $this->gateway(),
                new LogBudgetNotifier($logger),
                $this->messages(),
                new UuidGenerator(),
                new SystemClock(),
            ),
            $this->tracer(),
            $logger,
        );
    }

    private function messages(): MessageFactory
    {
        return new MessageFactory(new UuidGenerator(), new SystemClock());
    }

    private function tracer(): NewRelicTracer
    {
        return new NewRelicTracer($this->string('NEW_RELIC_APP_NAME') ?: 'oficina-faturamento');
    }

    private function aws(): AwsClientFactory
    {
        return new AwsClientFactory($this->env);
    }

    private function pdo(): \PDO
    {
        return $this->pdo ?? PdoConnection::getInstance();
    }

    private function string(string $key): string
    {
        $value = $this->env[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
