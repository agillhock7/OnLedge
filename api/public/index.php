<?php

declare(strict_types=1);

use App\Auth\SessionAuth;
use App\Controllers\AuthController;
use App\Controllers\ExportController;
use App\Controllers\ReceiptController;
use App\Controllers\RuleController;
use App\Controllers\SearchController;
use App\DB\Database;
use App\Helpers\HttpException;
use App\Helpers\Response;
use App\Router\Router;

require_once __DIR__ . '/../src/bootstrap.php';

$config = ['app' => ['env' => 'production']];

try {
    $config = onledge_load_config();
    $db = Database::connection($config['database']);
    $auth = new SessionAuth();

    $authController = new AuthController($db, $auth);
    $receiptController = new ReceiptController($db, $auth, $config);
    $ruleController = new RuleController($db, $auth);
    $searchController = new SearchController($db, $auth);
    $exportController = new ExportController($db, $auth);

    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api/index.php')), '/');
    $router = new Router($basePath === '/' ? '' : $basePath);

    $router->get('/health', function (): void {
        Response::json(['ok' => true, 'service' => 'onledge-api']);
    });

    $router->post('/auth/register', function () use ($authController): void {
        $authController->register();
    });
    $router->post('/auth/login', function () use ($authController): void {
        $authController->login();
    });
    $router->post('/auth/logout', function () use ($authController): void {
        $authController->logout();
    });
    $router->post('/auth/forgot-password', function () use ($authController): void {
        $authController->forgotPassword();
    });
    $router->get('/auth/me', function () use ($authController): void {
        $authController->me();
    });

    $router->get('/receipts', function () use ($receiptController): void {
        $receiptController->index();
    });
    $router->post('/receipts', function () use ($receiptController): void {
        $receiptController->create();
    });
    $router->get('/receipts/{id}', function (array $params) use ($receiptController): void {
        $receiptController->show($params);
    });
    $router->put('/receipts/{id}', function (array $params) use ($receiptController): void {
        $receiptController->update($params);
    });
    $router->delete('/receipts/{id}', function (array $params) use ($receiptController): void {
        $receiptController->destroy($params);
    });
    $router->post('/receipts/{id}/process', function (array $params) use ($receiptController): void {
        $receiptController->process($params);
    });

    $router->get('/rules', function () use ($ruleController): void {
        $ruleController->index();
    });
    $router->post('/rules', function () use ($ruleController): void {
        $ruleController->create();
    });
    $router->put('/rules/{id}', function (array $params) use ($ruleController): void {
        $ruleController->update($params);
    });
    $router->delete('/rules/{id}', function (array $params) use ($ruleController): void {
        $ruleController->destroy($params);
    });

    $router->get('/search', function () use ($searchController): void {
        $searchController->query();
    });
    $router->get('/export/csv', function () use ($exportController): void {
        $exportController->csv();
    });

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    $router->dispatch($method, $path);
} catch (HttpException $exception) {
    Response::json(['error' => $exception->getMessage()], $exception->getStatusCode());
} catch (Throwable $exception) {
    $isProduction = ($config['app']['env'] ?? 'production') === 'production';
    $payload = ['error' => 'Unexpected server error'];
    if (!$isProduction) {
        $payload['debug'] = $exception->getMessage();
    }
    Response::json($payload, 500);
}
