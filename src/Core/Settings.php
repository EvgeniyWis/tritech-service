<?php
declare(strict_types=1);

namespace App\Core;

final class Settings
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = array_column(Db::all('SELECT name, value FROM settings'), 'value', 'name');
        }
        return self::$cache;
    }

    public static function get(string $name, string $default = ''): string
    {
        return self::all()[$name] ?? $default;
    }

    public static function int(string $name, int $default = 0): int
    {
        return (int)self::get($name, (string)$default);
    }

    public static function set(string $name, string $value): void
    {
        Db::exec('UPDATE settings SET value = ? WHERE name = ?', [$value, $name]);
        self::$cache = null;
    }
}
