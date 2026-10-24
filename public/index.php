<?php
declare(strict_types=1);

// Единая точка входа: API (/api/*) и SPA-оболочка.
// Запуск для разработки:  php -S 127.0.0.1:8200 -t public public/index.php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . $path;
    if ($path !== '/' && is_file($file) && substr($file, -4) !== '.php') {
        return false; // статика
    }
}

// заголовки безопасности для страниц и API
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

if (strpos($path, '/api/') !== 0) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    return;
}

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Raw;
use App\Core\Request;
use App\Core\Router;

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function api_error(int $status, string $message, array $fields = []): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message, 'fields' => (object)$fields], JSON_UNESCAPED_UNICODE);
}

try {
    Auth::start();
    $req = new Request();
    $router = new Router();
    (require __DIR__ . '/../src/routes.php')($router);
    $result = $router->dispatch($req);
    if ($result instanceof Raw) {
        $result->send();
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
} catch (HttpException $e) {
    api_error($e->getCode(), $e->getMessage(), $e->fields);
} catch (\PDOException $e) {
    \App\Core\Log::file('PDO: ' . $e->getMessage());
    $msg = cfg('app.debug') ? 'Ошибка БД: ' . $e->getMessage() : 'Внутренняя ошибка сервера';
    api_error(500, $msg);
} catch (\Throwable $e) {
    \App\Core\Log::file(get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    api_error(500, cfg('app.debug') ? $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() : 'Внутренняя ошибка сервера');
}
