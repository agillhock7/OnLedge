<?php

declare(strict_types=1);

use App\Auth\SessionAuth;
use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\ExportController;
use App\Controllers\ReceiptController;
use App\Controllers\RuleController;
use App\Controllers\SearchController;
use App\Controllers\SupportTicketController;
use App\DB\Database;
use App\Helpers\HttpException;
use App\Helpers\Response;
use App\Helpers\Security;
use App\Router\Router;

$bootstrapCandidates = [
    __DIR__ . '/../src/bootstrap.php', // source tree: /api/public -> /api/src
    __DIR__ . '/src/bootstrap.php',    // deployed tree: /api -> /api/src
];

$bootstrapPath = null;
foreach ($bootstrapCandidates as $candidate) {
    if (is_file($candidate)) {
        $bootstrapPath = $candidate;
        break;
    }
}

if ($bootstrapPath === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'API bootstrap file not found']);
    exit;
}

require_once $bootstrapPath;

Security::applyApiHeaders();

$config = ['app' => ['env' => 'production']];
$debugErrors = false;

try {
    $config = onledge_load_config();
    $debugErrors = ($config['app']['env'] ?? 'production') !== 'production'
        || (bool) ($config['app']['debug_errors'] ?? false);

    $db = Database::connection($config['database']);
    $auth = new SessionAuth();

    $authController = new AuthController($db, $auth);
    $receiptController = new ReceiptController($db, $auth, $config);
    $ruleController = new RuleController($db, $auth);
    $searchController = new SearchController($db, $auth);
    $exportController = new ExportController($db, $auth);
    $adminController = new AdminController($db, $auth);
    $supportTicketController = new SupportTicketController($db, $auth);

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

    $router->post('/support/tickets', function () use ($supportTicketController): void {
        $supportTicketController->create();
    });
    $router->get('/support/tickets/my', function () use ($supportTicketController): void {
        $supportTicketController->mine();
    });

    $router->get('/admin/users', function () use ($adminController): void {
        $adminController->users();
    });
    $router->post('/admin/users', function () use ($adminController): void {
        $adminController->createUser();
    });
    $router->put('/admin/users/{id}', function (array $params) use ($adminController): void {
        $adminController->updateUser($params);
    });

    $router->get('/admin/tickets', function () use ($supportTicketController): void {
        $supportTicketController->adminIndex();
    });
    $router->put('/admin/tickets/{id}', function (array $params) use ($supportTicketController): void {
        $supportTicketController->adminUpdate($params);
    });

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    Security::enforceMutatingRequestGuard($method);
    $router->dispatch($method, $path);
} catch (HttpException $exception) {
    Response::json(['error' => $exception->getMessage()], $exception->getStatusCode());
} catch (PDOException $exception) {
    $errorId = bin2hex(random_bytes(4));
    error_log(sprintf('[OnLedge][PDO][%s] %s | SQLSTATE %s', $errorId, $exception->getMessage(), (string) $exception->getCode()));

    if (!$debugErrors) {
        Response::json(['error' => 'Database error during request.', 'error_id' => $errorId], 500);
        return;
    }

    $sqlState = (string) $exception->getCode();
    $rawMessage = strtolower($exception->getMessage());
    $error = match ($sqlState) {
        '42P01' => 'Database schema is missing. Run migrations before using the API.',
        '3D000' => 'Database does not exist for configured credentials.',
        '28000', '28P01' => 'Database authentication failed. Check DB user/password.',
        '42501' => 'Database permission denied. Grant table and sequence privileges to the app user.',
        default => (str_starts_with($sqlState, '08')
            ? 'Database connection failed. Check host/port/SSL and reachability.'
            : 'Database error during request. Check server logs for details.'),
    };

    if (str_contains($rawMessage, 'could not find driver')) {
        $error = 'PHP PDO PostgreSQL driver is missing on server (pdo_pgsql not enabled).';
    } elseif (str_contains($rawMessage, 'permission denied for sequence')) {
        $error = 'Database permission denied for sequence. Grant USAGE/SELECT on sequence(s) to app user.';
    } elseif (str_contains($rawMessage, 'permission denied for relation')) {
        $error = 'Database permission denied for table. Grant SELECT/INSERT/UPDATE/DELETE to app user.';
    }

    Response::json(['error' => $error, 'error_id' => $errorId], 500);
} catch (RuntimeException $exception) {
    $errorId = bin2hex(random_bytes(4));
    error_log(sprintf('[OnLedge][Runtime][%s] %s', $errorId, $exception->getMessage()));

    if (!$debugErrors) {
        Response::json(['error' => 'Server runtime error.', 'error_id' => $errorId], 500);
        return;
    }

    $message = $exception->getMessage();
    if (str_contains($message, 'Missing /api/config/config.php')) {
        Response::json(['error' => 'Server misconfiguration: /api/config/config.php is missing.', 'error_id' => $errorId], 500);
        return;
    }

    Response::json(['error' => 'Server runtime error. Check server logs for details.', 'error_id' => $errorId], 500);
} catch (Throwable $exception) {
    $errorId = bin2hex(random_bytes(4));
    error_log(sprintf('[OnLedge][Fatal][%s] %s', $errorId, $exception->getMessage()));

    $payload = ['error' => 'Unexpected server error', 'error_id' => $errorId];
    if ($debugErrors) {
        $payload['debug'] = $exception->getMessage();
    }
    Response::json($payload, 500);
}
