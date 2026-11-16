<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Infrastructure\Config\EnvLoader;

EnvLoader::loadFile(__DIR__ . '/../.env');
EnvLoader::require(['JWT_SECRET', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'MESSAGING_TOPIC_ARN']);

const SERVICE = 'oficina-faturamento';
const PREFIX  = '/api/billing';

$startedAt = microtime(true);
$appEnv    = (string)($_ENV['APP_ENV'] ?? 'local');
$appDebug  = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Request-Id, X-Webhook-Token, X-Signature');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

$uuid = new \App\Infrastructure\UuidGenerator();

// O correlation id vem primeiro, para toda linha de log do request carregá-lo.
$logger        = new \App\Infrastructure\Logging\JsonLogger(SERVICE, $appEnv);
$context       = new \App\Infrastructure\Context\RequestContext();
$correlationId = (new \App\Presentation\Middleware\CorrelationIdMiddleware($uuid))->apply($_SERVER);
$context->setCorrelationId($correlationId);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$jwtProvider    = \App\Infrastructure\Security\JwtProvider::fromEnv();
$authMiddleware = new \App\Presentation\Middleware\AuthMiddleware($jwtProvider, $context);

$healthController = new \App\Presentation\Controller\HealthController(
    'database',
    static fn (): bool => \App\Infrastructure\Database\PdoConnection::getInstance()->query('SELECT 1') !== false,
);

$services          = new \App\Infrastructure\ServiceFactory($_ENV + getenv());
$catalogController = $services->catalogController();
$budgetController  = $services->budgetController($logger);
$webhookController = $services->paymentWebhookController($logger);

/** @return array<string, mixed> */
$body = static function (): array {
    $data = json_decode((string)file_get_contents('php://input'), true);

    return is_array($data) ? $data : [];
};
$header = static fn (string $name): string => (string)($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? '');

$router = new \App\Presentation\Router\Router();
$router->setAuthMiddleware($authMiddleware);

// Matriz de autorização: docs/fase-4/CONTRATOS.md §4, no repo oficina-api.
// Toda rota deste serviço fica sob PREFIX. Rota com requireAuth: false precisa de rota
// dedicada, sem authorizer, no apigateway.tf do oficina-gateway-auth.

$router->get(PREFIX . '/health', fn () => $healthController->live(), requireAuth: false);
$router->get(PREFIX . '/ready', fn () => $healthController->ready(), requireAuth: false);

// webhooks: sem JWT, cada um com a própria autenticação
$router->post(
    PREFIX . '/budgets/{id}/approval',
    fn ($p) => $budgetController->approval($p, $body(), $header('X-Webhook-Token')),
    requireAuth: false,
);
$router->post(
    PREFIX . '/payments/webhook/mercadopago',
    fn () => $webhookController->mercadoPago($_GET, $body(), [
        'x-signature'  => $header('X-Signature'),
        'x-request-id' => $header('X-Request-Id'),
    ]),
    requireAuth: false,
);

// orçamentos e pagamentos: o cliente só vê o que é dele
$router->get(PREFIX . '/budgets', fn () => $budgetController->index())->requireRole('admin');
$router->get(PREFIX . '/budgets/by-order/{orderId}', fn ($p, $c) => $budgetController->byOrder($p, $c))
    ->requireRole('customer', 'admin');
$router->get(PREFIX . '/budgets/{id}', fn ($p, $c) => $budgetController->show($p, $c))->requireRole('customer', 'admin');
$router->get(PREFIX . '/payments/{id}', fn ($p, $c) => $budgetController->payment($p, $c))->requireRole('customer', 'admin');

// catálogo e estoque: admin
$router->get(PREFIX . '/service-items', fn () => $catalogController->listServices())->requireRole('admin');
$router->get(PREFIX . '/service-items/{id}', fn ($p) => $catalogController->showService($p))->requireRole('admin');
$router->post(PREFIX . '/service-items', fn () => $catalogController->createService($body()))->requireRole('admin');
$router->put(PREFIX . '/service-items/{id}', fn ($p) => $catalogController->updateService($p, $body()))->requireRole('admin');
$router->delete(PREFIX . '/service-items/{id}', fn ($p) => $catalogController->deleteService($p))->requireRole('admin');
$router->get(PREFIX . '/parts', fn () => $catalogController->listParts())->requireRole('admin');
$router->get(PREFIX . '/parts/{id}', fn ($p) => $catalogController->showPart($p))->requireRole('admin');
$router->post(PREFIX . '/parts', fn () => $catalogController->createPart($body()))->requireRole('admin');
$router->put(PREFIX . '/parts/{id}', fn ($p) => $catalogController->updatePart($p, $body()))->requireRole('admin');
$router->patch(PREFIX . '/parts/{id}/stock', fn ($p) => $catalogController->updateStock($p, $body()))->requireRole('admin');
$router->delete(PREFIX . '/parts/{id}', fn ($p) => $catalogController->deletePart($p))->requireRole('admin');

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uri    = (string)($_SERVER['REQUEST_URI'] ?? '/');

// Uma linha estruturada por request, emitida mesmo quando o request morre num exit() de
// middleware: por isso a função de shutdown.
$requestLogged = false;
$logRequest    = static function (string $level = 'info') use (
    &$requestLogged,
    $logger,
    $context,
    $method,
    $uri,
    $startedAt
): void {
    if ($requestLogged) {
        return;
    }
    $requestLogged = true;

    $logger->log($level, 'request.completed', [
        'correlation_id' => $context->getCorrelationId(),
        'method'         => $method,
        'path'           => (string)(parse_url($uri, PHP_URL_PATH) ?? $uri),
        'status'         => http_response_code() ?: 200,
        'duration_ms'    => round((microtime(true) - $startedAt) * 1000, 1),
        'customer_id'    => $context->getActor(),
        'role'           => $context->getRole(),
    ]);
};

register_shutdown_function(static fn () => $logRequest());

try {
    $router->dispatch($method, $uri);
} catch (\App\Domain\Exception\DomainException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    // Falha não tratada: o detalhe vai para o log, nunca para o cliente, salvo APP_DEBUG=true.
    $logger->error('request.failed', [
        'correlation_id'    => $context->getCorrelationId(),
        'method'            => $method,
        'path'              => (string)(parse_url($uri, PHP_URL_PATH) ?? $uri),
        'exception_class'   => $e::class,
        'exception_message' => $e->getMessage(),
        'file'              => $e->getFile(),
        'line'              => $e->getLine(),
    ]);

    http_response_code(500);
    echo json_encode([
        'error'   => 'Erro interno do servidor.',
        'message' => $appDebug ? $e->getMessage() : null,
    ]);

    $logRequest('error');
}

$logRequest();
