<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];

    /**
     * @param string        $method  GET|POST|PUT|DELETE
     * @param string        $pattern /api/tickets/{id}
     * @param callable      $handler function(Request, array $params): mixed
     * @param string[]|null $roles   null — публично, [] — любой авторизованный, ['admin',..] — любая из ролей
     */
    public function add(string $method, string $pattern, callable $handler, ?array $roles = []): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#u';
        $this->routes[] = [$method, $regex, $handler, $roles];
    }

    public function get(string $p, callable $h, ?array $roles = []): void { $this->add('GET', $p, $h, $roles); }
    public function post(string $p, callable $h, ?array $roles = []): void { $this->add('POST', $p, $h, $roles); }
    public function put(string $p, callable $h, ?array $roles = []): void { $this->add('PUT', $p, $h, $roles); }
    public function delete(string $p, callable $h, ?array $roles = []): void { $this->add('DELETE', $p, $h, $roles); }

    public function dispatch(Request $req)
    {
        $pathMatched = false;
        foreach ($this->routes as [$method, $regex, $handler, $roles]) {
            if (!preg_match($regex, $req->path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($method !== $req->method) {
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            if ($roles !== null) {
                Auth::requireLogin();
                if ($roles && !Auth::hasAnyRole($roles)) {
                    throw HttpException::forbidden();
                }
                // CSRF проверяется для всех изменяющих запросов авторизованных маршрутов;
                // публичные (вход, регистрация, webhook ЮKassa) защищены иначе
                if ($req->method !== 'GET') {
                    Auth::checkCsrf($req);
                }
            }
            return $handler($req, $params);
        }
        if ($pathMatched) {
            throw new HttpException(405, 'Метод не поддерживается');
        }
        throw HttpException::notFound('Маршрут не найден');
    }
}
