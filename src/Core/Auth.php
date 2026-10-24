<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name('TSSESS');
            session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
            session_start();
        }
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
    }

    public static function csrf(): string
    {
        self::start();
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(Request $req): void
    {
        $t = $req->header('x-csrf-token');
        if (!$t || !hash_equals(self::csrf(), $t)) {
            throw new HttpException(419, 'Сессия устарела, обновите страницу');
        }
    }

    /** Текущий пользователь с ролями или null. */
    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;
        self::start();
        $id = $_SESSION['uid'] ?? null;
        if (!$id) {
            return self::$user = null;
        }
        $u = Db::one('SELECT id, email, last_name, first_name, middle_name, phone, company, is_active, email_verified_at FROM users WHERE id = ?', [$id]);
        if (!$u || !$u['is_active']) {
            unset($_SESSION['uid']);
            return self::$user = null;
        }
        $u['roles'] = array_column(Db::all('SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?', [$id]), 'code');
        $u['consultant_id'] = in_array('consultant', $u['roles'], true)
            ? (int)Db::val('SELECT id FROM consultants WHERE user_id = ?', [$id]) : null;
        $u['id'] = (int)$u['id'];
        return self::$user = $u;
    }

    public static function id(): int
    {
        return (int)(self::user()['id'] ?? 0);
    }

    public static function requireLogin(): array
    {
        $u = self::user();
        if (!$u) {
            throw HttpException::unauth();
        }
        return $u;
    }

    public static function hasRole(string $role): bool
    {
        $u = self::user();
        return $u && in_array($role, $u['roles'], true);
    }

    public static function hasAnyRole(array $roles): bool
    {
        $u = self::user();
        return $u && (bool)array_intersect($roles, $u['roles']);
    }

    /** Сотрудник (любая роль, кроме клиента). */
    public static function isStaff(): bool
    {
        $u = self::user();
        return $u && (bool)array_diff($u['roles'], ['client']);
    }

    public static function login(int $uid): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['uid'] = $uid;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        self::$loaded = false;
        self::$user = null;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        session_regenerate_id(true);
        self::$loaded = false;
        self::$user = null;
    }

    public static function hashPassword(string $p): string
    {
        return password_hash($p, PASSWORD_BCRYPT, ['cost' => 10]);
    }

    public static function fullName(array $u): string
    {
        return trim(($u['last_name'] ?? '') . ' ' . ($u['first_name'] ?? '') . ' ' . ($u['middle_name'] ?? ''));
    }
}
