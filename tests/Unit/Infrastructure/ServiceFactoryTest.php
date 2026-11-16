<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Application\Worker\Worker;
use App\Infrastructure\Notification\LogBudgetNotifier;
use App\Infrastructure\Payment\FakePaymentGateway;
use App\Infrastructure\Payment\MercadoPagoGateway;
use App\Infrastructure\ServiceFactory;
use App\Presentation\Controller\BudgetController;
use App\Presentation\Controller\CatalogController;
use App\Presentation\Controller\PaymentWebhookController;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\Harness;
use Tests\Support\RecordingObservers;

class ServiceFactoryTest extends TestCase
{
    private const ENV = [
        'AWS_ENDPOINT_URL'    => 'http://localstack:4566',
        'MESSAGING_TOPIC_ARN' => 'arn:t',
        'MESSAGING_QUEUE_URL' => 'http://q',
    ];

    public function testMontaTudoComOMeioDePagamentoFalsoQuandoPedido(): void
    {
        $factory = new ServiceFactory(self::ENV + ['PAYMENT_GATEWAY' => 'fake'], new PDO('sqlite::memory:'));
        $logger  = new RecordingObservers();

        $this->assertInstanceOf(FakePaymentGateway::class, $factory->gateway());
        $this->assertSame($factory->gateway(), $factory->gateway());
        $this->assertInstanceOf(CatalogController::class, $factory->catalogController());
        $this->assertInstanceOf(BudgetController::class, $factory->budgetController($logger));
        $this->assertInstanceOf(PaymentWebhookController::class, $factory->paymentWebhookController($logger));
        $this->assertInstanceOf(Worker::class, $factory->worker($logger));
        $this->assertSame($factory->budgets(), $factory->budgets());
    }

    public function testMercadoPagoPorPadrao(): void
    {
        $factory = new ServiceFactory(self::ENV + ['MP_ACCESS_TOKEN' => 'x'], new PDO('sqlite::memory:'));

        $this->assertInstanceOf(MercadoPagoGateway::class, $factory->gateway());
    }

    public function testAvisoDeOrcamentoVaiParaOLogSemDadoPessoal(): void
    {
        $h = new Harness();
        $h->send($h->command('billing.generate-budget', 'os-1', $h->generatePayload()));
        $logger = new RecordingObservers();

        (new LogBudgetNotifier($logger))->budgetReady($h->budgetFor('os-1'));

        $this->assertSame('budget.sent', $logger->logs[0]['message']);
        $this->assertSame('os-1', $logger->logs[0]['context']['os_id']);
        $this->assertStringNotContainsString('maria@example.com', (string)json_encode($logger->logs));
    }
}
