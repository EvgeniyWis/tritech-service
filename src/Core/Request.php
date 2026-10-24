<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    public string $method;
    public string $path;
    public array $query;
    public array $body = [];
    public array $files;
    public array $headers = [];

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';
        $this->query = $_GET;
        $this->files = $_FILES;
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $this->headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
            }
        }
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($ct, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            $json = $raw !== '' ? json_decode($raw, true) : [];
            if (!is_array($json)) {
                throw HttpException::bad('Некорректный JSON');
            }
            $this->body = $json;
        } else {
            $this->body = $_POST;
        }
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }

    public function input(string $key, $default = null)
    {
        return $this->body[$key] ?? $default;
    }

    public function str(string $key, ?string $default = null): ?string
    {
        $v = $this->body[$key] ?? $default;
        return $v === null ? null : trim((string)$v);
    }

    public function int(string $key, ?int $default = null): ?int
    {
        $v = $this->body[$key] ?? $default;
        return ($v === null || $v === '') ? $default : (int)$v;
    }

    public function q(string $key, ?string $default = null): ?string
    {
        $v = $this->query[$key] ?? $default;
        return ($v === null || $v === '') ? $default : trim((string)$v);
    }

    public function qint(string $key, ?int $default = null): ?int
    {
        $v = $this->query[$key] ?? null;
        return ($v === null || $v === '') ? $default : (int)$v;
    }
}
