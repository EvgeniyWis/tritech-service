<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Log;
use App\Core\Mailer;
use App\Core\Request;
use App\Core\V;

final class AuthController
{
    private static function link(string $route, string $token): string
    {
        return rtrim(cfg('app.url'), '/') . '/#/' . $route . '?token=' . $token;
    }

    private static function issueVerification(int $uid, string $email): ?string
    {
        $token = bin2hex(random_bytes(32));
        Db::update('users', ['verify_token_hash' => hash('sha256', $token)], 'id = ?', [$uid]);
        $link = self::link('verify', $token);
        Mailer::send($email, '[' . cfg('app.name') . '] Подтверждение e-mail',
            "Здравствуйте!\n\nДля завершения регистрации подтвердите адрес электронной почты:\n$link\n\nЕсли вы не регистрировались — проигнорируйте письмо.");
        return cfg('app.dev_links') ? $link : null;
    }

    public static function me(Request $r): array
    {
        $u = Auth::user();
        $out = ['csrf' => Auth::csrf(), 'user' => null, 'demo' => (bool)cfg('app.demo')];
        if ($u) {
            \App\Services\Cron::tick();
            $u['name'] = Auth::fullName($u);
            $u['initials'] = mb_strtoupper(mb_substr($u['first_name'], 0, 1) . mb_substr($u['last_name'], 0, 1));
            $u['unread'] = (int)Db::val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$u['id']]);
            $out['user'] = $u;
        }
        return $out;
    }

    public static function register(Request $r): array
    {
        $v = V::of($r)->required('last_name', 'Фамилия', 50)->required('first_name', 'Имя', 50)->email()->password()->phone();
        $v->check();
        if (!$r->input('consent')) {
            throw HttpException::bad('Необходимо согласие на обработку персональных данных (152-ФЗ)', ['consent' => 'Необходимо согласие']);
        }
        if ($r->str('password') !== (string)$r->input('password_confirm', $r->str('password'))) {
            throw HttpException::bad('Пароли не совпадают', ['password_confirm' => 'Пароли не совпадают']);
        }
        $email = mb_strtolower($r->str('email'));
        if (Db::val('SELECT 1 FROM users WHERE email = ?', [$email])) {
            throw HttpException::conflict('Пользователь с таким e-mail уже зарегистрирован');
        }
        $uid = Db::tx(function () use ($r, $email) {
            $uid = Db::insert('users', [
                'email' => $email, 'password_hash' => Auth::hashPassword((string)$r->input('password')),
                'last_name' => $r->str('last_name'), 'first_name' => $r->str('first_name'),
                'middle_name' => $r->str('middle_name') ?: null, 'phone' => $r->str('phone') ?: null,
                'company' => $r->str('company') ?: null, 'pd_consent_at' => now(), 'created_at' => now(),
            ]);
            Db::insert('user_roles', ['user_id' => $uid, 'role_id' => (int)Db::val("SELECT id FROM roles WHERE code = 'client'")]);
            return $uid;
        });
        $link = self::issueVerification($uid, $email);
        Log::act('auth.register', 'user', $uid, $email, $uid);
        return ['message' => 'Регистрация выполнена. Мы отправили письмо со ссылкой для подтверждения e-mail.', 'dev_link' => $link];
    }

    public static function verify(Request $r): array
    {
        $token = (string)$r->input('token', '');
        if (strlen($token) < 20) {
            throw HttpException::bad('Некорректная ссылка подтверждения');
        }
        $u = Db::one('SELECT id, email_verified_at FROM users WHERE verify_token_hash = ?', [hash('sha256', $token)]);
        if (!$u) {
            throw HttpException::bad('Ссылка недействительна или уже использована');
        }
        Db::update('users', ['email_verified_at' => now(), 'verify_token_hash' => null], 'id = ?', [$u['id']]);
        Log::act('auth.verify', 'user', (int)$u['id'], null, (int)$u['id']);
        return ['message' => 'E-mail подтверждён. Теперь вы можете войти.'];
    }

    public static function resend(Request $r): array
    {
        $email = mb_strtolower((string)$r->str('email'));
        $u = Db::one('SELECT id, email, email_verified_at FROM users WHERE email = ?', [$email]);
        $link = null;
        if ($u && !$u['email_verified_at']) {
            $link = self::issueVerification((int)$u['id'], $u['email']);
        }
        return ['message' => 'Если адрес зарегистрирован и не подтверждён, письмо отправлено повторно.', 'dev_link' => $link];
    }

    public static function login(Request $r): array
    {
        $email = mb_strtolower((string)$r->str('email'));
        $pass = (string)$r->input('password', '');
        if ($email === '' || $pass === '') {
            throw HttpException::bad('Введите e-mail и пароль');
        }
        $u = Db::one('SELECT * FROM users WHERE email = ?', [$email]);
        $generic = 'Неверный e-mail или пароль';
        if (!$u) {
            password_verify($pass, '$2y$10$abcdefghijklmnopqrstuuYhrzK6QvXbP2OuYOpSzD7FZKQ1y8F0a'); // выравнивание времени ответа
            throw new HttpException(401, $generic);
        }
        if ($u['locked_until'] && strtotime($u['locked_until']) > time()) {
            throw new HttpException(423, 'Вход временно заблокирован из-за множества неудачных попыток. Повторите после ' . date('H:i', strtotime($u['locked_until'])));
        }
        if (!password_verify($pass, $u['password_hash'])) {
            $n = (int)$u['failed_attempts'] + 1;
            $upd = ['failed_attempts' => $n];
            $msg = $generic;
            if ($n >= (int)cfg('security.max_failed_logins', 5)) {
                $mins = (int)cfg('security.lock_minutes', 15);
                $upd = ['failed_attempts' => 0, 'locked_until' => date('Y-m-d H:i:s', time() + $mins * 60)];
                $msg = "Слишком много попыток. Вход заблокирован на $mins минут";
                Log::act('auth.lock', 'user', (int)$u['id'], null, (int)$u['id']);
            }
            Db::update('users', $upd, 'id = ?', [$u['id']]);
            throw new HttpException(401, $msg);
        }
        if (!$u['is_active']) {
            throw new HttpException(403, 'Учётная запись заблокирована. Обратитесь к администратору');
        }
        if (!$u['email_verified_at']) {
            throw new HttpException(403, 'Подтвердите e-mail: перейдите по ссылке из письма');
        }
        Db::update('users', ['failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => now()], 'id = ?', [$u['id']]);
        Auth::login((int)$u['id']);
        Log::act('auth.login', 'user', (int)$u['id'], null, (int)$u['id']);
        return self::me($r);
    }

    public static function logout(Request $r): array
    {
        Log::act('auth.logout', 'user', Auth::id() ?: null);
        Auth::logout();
        return ['csrf' => Auth::csrf(), 'user' => null];
    }

    public static function forgot(Request $r): array
    {
        $email = mb_strtolower((string)$r->str('email'));
        V::of($r)->email()->check();
        $u = Db::one('SELECT id, email FROM users WHERE email = ? AND is_active = 1', [$email]);
        $link = null;
        if ($u) {
            $token = bin2hex(random_bytes(32));
            Db::update('users', ['reset_token_hash' => hash('sha256', $token), 'reset_expires_at' => date('Y-m-d H:i:s', time() + 3600)], 'id = ?', [$u['id']]);
            $link = self::link('reset', $token);
            Mailer::send($u['email'], '[' . cfg('app.name') . '] Восстановление пароля',
                "Для смены пароля перейдите по ссылке (действует 1 час):\n$link\n\nЕсли вы не запрашивали смену пароля — проигнорируйте письмо.");
            Log::act('auth.forgot', 'user', (int)$u['id'], null, (int)$u['id']);
        }
        return ['message' => 'Если такой адрес зарегистрирован, мы отправили на него письмо со ссылкой для смены пароля.', 'dev_link' => $u && cfg('app.dev_links') ? $link : null];
    }

    public static function reset(Request $r): array
    {
        V::of($r)->password()->check();
        $token = (string)$r->input('token', '');
        $u = Db::one('SELECT id FROM users WHERE reset_token_hash = ? AND reset_expires_at > ?', [hash('sha256', $token), now()]);
        if (!$u) {
            throw HttpException::bad('Ссылка недействительна или срок её действия истёк');
        }
        Db::update('users', [
            'password_hash' => Auth::hashPassword((string)$r->input('password')), 'reset_token_hash' => null, 'reset_expires_at' => null,
            'failed_attempts' => 0, 'locked_until' => null, 'updated_at' => now(),
        ], 'id = ?', [$u['id']]);
        Log::act('auth.reset', 'user', (int)$u['id'], null, (int)$u['id']);
        return ['message' => 'Пароль изменён. Войдите с новым паролем.'];
    }

    public static function updateProfile(Request $r): array
    {
        $u = Auth::requireLogin();
        V::of($r)->required('last_name', 'Фамилия', 50)->required('first_name', 'Имя', 50)->phone()->check();
        Db::update('users', [
            'last_name' => $r->str('last_name'), 'first_name' => $r->str('first_name'),
            'middle_name' => $r->str('middle_name') ?: null, 'phone' => $r->str('phone') ?: null,
            'company' => $r->str('company') ?: null, 'updated_at' => now(),
        ], 'id = ?', [$u['id']]);
        Log::act('profile.update', 'user', $u['id']);
        return self::me($r);
    }

    public static function changePassword(Request $r): array
    {
        $u = Auth::requireLogin();
        $hash = Db::val('SELECT password_hash FROM users WHERE id = ?', [$u['id']]);
        if (!password_verify((string)$r->input('current_password', ''), $hash)) {
            throw HttpException::bad('Текущий пароль указан неверно', ['current_password' => 'Неверный пароль']);
        }
        V::of($r)->password('new_password')->check();
        Db::update('users', ['password_hash' => Auth::hashPassword((string)$r->input('new_password')), 'updated_at' => now()], 'id = ?', [$u['id']]);
        Log::act('profile.password', 'user', $u['id']);
        return ['message' => 'Пароль изменён'];
    }
}
