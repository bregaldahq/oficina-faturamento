<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Messaging\MessageFactory;
use App\Application\Messaging\OutboxRelay;
use App\Application\UseCase\Budget\BudgetQueries;
use App\Application\UseCase\Budget\ReviewBudgetUseCase;
use App\Application\UseCase\HandleIncomingMessageUseCase;
use App\Application\UseCase\HandlePaymentNotificationUseCase;
use App\Application\Worker\Worker;
use App\Domain\Budget\Budget;
use App\Domain\Catalog\Part;
use App\Domain\Catalog\ServiceItem;
use App\Domain\Messaging\Message;
use App\Infrastructure\InMemory\InMemoryBus;
use App\Infrastructure\Payment\FakePaymentGateway;
use App\Infrastructure\Payment\WebhookSignature;
use App\Infrastructure\Repository\PdoBudgetRepository;
use App\Infrastructure\Repository\PdoCatalogRepository;
use App\Presentation\Controller\BudgetController;
use App\Presentation\Controller\CatalogController;
use App\Presentation\Controller\PaymentWebhookController;
use PDO;

/**
 * O serviço inteiro, com o repositório PDO de produção sobre SQLite em memória, o barramento
 * em memória e o meio de pagamento falso. É o que os testes de aplicação e o BDD usam.
 */
final class Harness
{
    public const WEBHOOK_TOKEN = 'token-do-webhook';
    public const MP_SECRET     = 'segredo-do-mercado-pago';
    public const SERVICE       = 'svc-oleo';
    public const PART          = 'part-pastilha';
    public const SERVICE_PRICE = 180.0;
    public const PART_PRICE    = 189.0;
    public const PART_STOCK    = 5;

    public PDO $pdo;
    public PdoBudgetRepository $budgets;
    public PdoCatalogRepository $catalog;
    public InMemoryBus $bus;
    public FakePaymentGateway $gateway;
    public WebhookSignature $signature;
    public RecordingObservers $observers;
    public FixedClock $clock;
    public SequenceUuid $uuid;
    public MessageFactory $messages;
    public OutboxRelay $relay;
    public HandleIncomingMessageUseCase $handler;
    public HandlePaymentNotificationUseCase $notifications;
    public Worker $worker;

    private int $sequence = 0;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec((string)file_get_contents(__DIR__ . '/schema.sqlite.sql'));

        $this->uuid      = new SequenceUuid();
        $uuid            = $this->uuid;
        $this->budgets   = new PdoBudgetRepository($this->pdo, static fn (): string => $uuid->generate());
        $this->catalog   = new PdoCatalogRepository($this->pdo);
        $this->bus       = new InMemoryBus();
        $this->signature = new WebhookSignature(self::MP_SECRET);
        $this->gateway   = new FakePaymentGateway($this->signature);
        $this->observers = new RecordingObservers();
        $this->clock     = new FixedClock();
        $this->messages  = new MessageFactory($this->uuid, $this->clock);
        $this->relay     = new OutboxRelay($this->budgets, $this->bus, $this->observers, $this->observers);
        $this->handler   = new HandleIncomingMessageUseCase(
            $this->budgets,
            $this->catalog,
            $this->gateway,
            $this->observers,
            $this->messages,
            $this->uuid,
            $this->clock,
        );
        $this->notifications = new HandlePaymentNotificationUseCase($this->budgets, $this->gateway, $this->messages, $this->clock, $this->relay, $this->observers);
        $this->worker        = new Worker($this->relay, $this->bus, $this->handler, $this->observers, $this->observers);

        $this->catalog->saveServiceItem(new ServiceItem(self::SERVICE, 'Troca de oleo e filtro', self::SERVICE_PRICE, 40));
        $this->catalog->savePart(new Part(self::PART, 'Pastilha de freio dianteira (par)', self::PART_PRICE, self::PART_STOCK));
    }

    /** @param array<string, mixed> $payload */
    public function command(string $type, string $osId, array $payload = [], ?string $id = null): Message
    {
        return new Message(
            $id ?? 'cmd-' . ++$this->sequence,
            $type,
            'os',
            'saga-' . $osId,
            $osId,
            '2026-11-16T10:00:00.000Z',
            'corr-' . $osId,
            $payload,
        );
    }

    /** O comando do orquestrador, pela fila, tratado por uma volta do worker. */
    public function send(Message $message): void
    {
        $this->bus->deliver($message);
        $this->worker->tick(0);
    }

    /** @return array<string, mixed> */
    public function generatePayload(int $parts = 2, string $partId = self::PART): array
    {
        return [
            'customer' => ['id' => 'cli-1', 'name' => 'Maria Silva', 'email' => 'maria@example.com'],
            'items'    => [
                ['kind' => 'service', 'id' => self::SERVICE, 'quantity' => 1],
                ['kind' => 'part', 'id' => $partId, 'quantity' => $parts],
            ],
        ];
    }

    public function budgetFor(string $osId): Budget
    {
        $budget = $this->budgets->findByOsId($osId);
        if ($budget === null) {
            throw new \RuntimeException("sem orçamento para {$osId}");
        }

        return $budget;
    }

    /** Leva a OS até o orçamento aprovado e cobrado. */
    public function approvedAndCharged(string $osId): Budget
    {
        $this->send($this->command('billing.generate-budget', $osId, $this->generatePayload()));
        $this->review($this->budgetFor($osId)->getId(), true);
        $this->send($this->command('billing.charge', $osId, ['budget_id' => $this->budgetFor($osId)->getId()]));

        return $this->budgetFor($osId);
    }

    public function review(string $budgetId, bool $approved): Budget
    {
        return (new ReviewBudgetUseCase($this->budgets, $this->messages, $this->clock, $this->relay))->execute($budgetId, $approved);
    }

    /** O cliente paga (ou tem o pagamento recusado) e o Mercado Pago avisa. */
    public function pay(string $osId, string $status = 'approved', ?float $amount = null): string
    {
        $budget = $this->budgetFor($osId);

        return $this->notifications->execute(FakePaymentGateway::paymentId($status, $budget->getId(), $amount ?? $budget->total()));
    }

    /** @return list<string> */
    public function published(): array
    {
        return $this->bus->publishedTypes();
    }

    /** @return array{stock: int, reserved: int} */
    public function stock(): array
    {
        $part = $this->catalog->findPart(self::PART);
        if ($part === null) {
            throw new \RuntimeException('peça sumiu');
        }

        return ['stock' => $part->getStockQuantity(), 'reserved' => $part->getReservedQuantity()];
    }

    public function catalogController(): CatalogController
    {
        return new CatalogController($this->catalog, $this->uuid);
    }

    public function budgetController(): BudgetController
    {
        return new BudgetController(
            new BudgetQueries($this->budgets),
            new ReviewBudgetUseCase($this->budgets, $this->messages, $this->clock, $this->relay),
            self::WEBHOOK_TOKEN,
        );
    }

    public function webhookController(): PaymentWebhookController
    {
        return new PaymentWebhookController($this->gateway, $this->notifications, $this->observers);
    }

    /**
     * Chama um controller como o roteador chamaria e devolve [status HTTP, corpo].
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function call(object $controller, string $action, mixed ...$args): array
    {
        http_response_code(200);
        ob_start();
        $controller->{$action}(...$args);
        $body = json_decode((string)ob_get_clean(), true);

        return [(int)http_response_code(), is_array($body) ? $body : []];
    }
}
