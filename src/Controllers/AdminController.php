<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Log;
use App\Core\Mailer;
use App\Core\Raw;
use App\Core\Request;
use App\Core\Settings;
use App\Core\V;
use App\Services\Backup;
use App\Services\Cron;

final class AdminController
{
    // ---------- Пользователи ----------

    public static function roles(Request $r): array
    {
        return Db::all('SELECT id, code, name FROM roles ORDER BY id');
    }

    public static function users(Request $r): array
    {
        $where = ['1=1'];
        $p = [];
        if ($q = $r->q('q')) {
            $where[] = '(u.last_name LIKE ? OR u.first_name LIKE ? OR u.email LIKE ? OR u.company LIKE ?)';
            array_push($p, "%$q%", "%$q%", "%$q%", "%$q%");
        }
        if ($role = $r->q('role')) {
            $where[] = 'EXISTS (SELECT 1 FROM user_roles ur JOIN roles ro ON ro.id = ur.role_id WHERE ur.user_id = u.id AND ro.code = ?)';
            $p[] = $role;
        }
        if (($a = $r->q('active')) !== null) {
            $where[] = 'u.is_active = ?';
            $p[] = (int)$a;
        }
        $limit = min(100, $r->qint('limit', 25));
        $page = max(1, $r->qint('page', 1));
        $w = implode(' AND ', $where);
        $total = (int)Db::val("SELECT COUNT(*) FROM users u WHERE $w", $p);
        $rows = Db::all("SELECT u.id, u.email, u.last_name, u.first_name, u.middle_name, u.phone, u.company, u.is_active, u.email_verified_at, u.last_login_at, u.created_at, u.locked_until,
                           (SELECT GROUP_CONCAT(ro.code) FROM user_roles ur JOIN roles ro ON ro.id = ur.role_id WHERE ur.user_id = u.id) AS roles,
                           (SELECT GROUP_CONCAT(ro.name SEPARATOR ', ') FROM user_roles ur JOIN roles ro ON ro.id = ur.role_id WHERE ur.user_id = u.id) AS role_names
                         FROM users u WHERE $w ORDER BY u.last_name, u.first_name LIMIT $limit OFFSET " . (($page - 1) * $limit), $p);
        foreach ($rows as &$row) {
            $row['roles'] = $row['roles'] ? explode(',', $row['roles']) : [];
        }
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    public static function user(Request $r, array $p): array
    {
        $u = Db::one('SELECT id, email, last_name, first_name, middle_name, phone, company, is_active, email_verified_at, last_login_at, created_at FROM users WHERE id = ?', [(int)$p['id']]);
        if (!$u) {
            throw HttpException::notFound('Пользователь не найден');
        }
        $u['roles'] = array_column(Db::all('SELECT ro.code FROM user_roles ur JOIN roles ro ON ro.id = ur.role_id WHERE ur.user_id = ?', [$u['id']]), 'code');
        $c = Db::one('SELECT id, position, bio, experience_years, is_active FROM consultants WHERE user_id = ?', [$u['id']]);
        if ($c) {
            $c['specialization_ids'] = array_map('intval', array_column(Db::all('SELECT specialization_id FROM consultant_specializations WHERE consultant_id = ?', [$c['id']]), 'specialization_id'));
        }
        $u['consultant'] = $c;
        return $u;
    }

    private static function applyRoles(int $uid, array $codes, Request $r): void
    {
        $valid = array_column(Db::all('SELECT id, code FROM roles'), 'id', 'code');
        $codes = array_values(array_unique(array_filter($codes, fn($c) => isset($valid[$c]))));
        if (!$codes) {
            throw HttpException::bad('Укажите хотя бы одну роль', ['roles' => 'Укажите хотя бы одну роль']);
        }
        if ($uid === Auth::id() && !in_array('admin', $codes, true)) {
            throw HttpException::conflict('Нельзя снять с себя роль администратора');
        }
        Db::exec('DELETE FROM user_roles WHERE user_id = ?', [$uid]);
        foreach ($codes as $c) {
            Db::insert('user_roles', ['user_id' => $uid, 'role_id' => $valid[$c]]);
        }
        // профиль консультанта
        $cons = Db::one('SELECT id FROM consultants WHERE user_id = ?', [$uid]);
        if (in_array('consultant', $codes, true)) {
            $prof = (array)$r->input('consultant', []);
            $data = [
                'position' => mb_substr(trim((string)($prof['position'] ?? 'Консультант')), 0, 150) ?: 'Консультант',
                'bio' => trim((string)($prof['bio'] ?? '')) ?: null,
                'experience_years' => max(0, min(60, (int)($prof['experience_years'] ?? 0))),
                'is_active' => 1,
            ];
            if ($cons) {
                Db::update('consultants', $data, 'id = ?', [$cons['id']]);
                $cid = (int)$cons['id'];
            } else {
                $cid = Db::insert('consultants', $data + ['user_id' => $uid]);
            }
            if (isset($prof['specialization_ids']) && is_array($prof['specialization_ids'])) {
                Db::exec('DELETE FROM consultant_specializations WHERE consultant_id = ?', [$cid]);
                foreach (array_unique(array_map('intval', $prof['specialization_ids'])) as $sid) {
                    if (Db::val('SELECT 1 FROM specializations WHERE id = ?', [$sid])) {
                        Db::insert('consultant_specializations', ['consultant_id' => $cid, 'specialization_id' => $sid]);
                    }
                }
            }
        } elseif ($cons) {
            if (Db::val("SELECT 1 FROM appointments a JOIN appointment_statuses s ON s.id = a.status_id WHERE a.consultant_id = ? AND s.code IN ('pending_payment','confirmed') AND a.start_at > NOW()", [$cons['id']])) {
                throw HttpException::conflict('У консультанта есть будущие записи — сначала перенесите или отмените их');
            }
            Db::update('consultants', ['is_active' => 0], 'id = ?', [$cons['id']]);
        }
    }

    public static function createUser(Request $r): array
    {
        V::of($r)->required('last_name', 'Фамилия', 50)->required('first_name', 'Имя', 50)->email()->phone()->check();
        $email = mb_strtolower((string)$r->str('email'));
        if (Db::val('SELECT 1 FROM users WHERE email = ?', [$email])) {
            throw HttpException::conflict('Пользователь с таким e-mail уже существует');
        }
        $pass = (string)$r->input('password', '');
        $generated = false;
        if ($pass === '') {
            $pass = substr(bin2hex(random_bytes(6)), 0, 10) . 'A1';
            $generated = true;
        } else {
            V::of($r)->password()->check();
        }
        $uid = Db::tx(function () use ($r, $email, $pass) {
            $uid = Db::insert('users', [
                'email' => $email, 'password_hash' => Auth::hashPassword($pass),
                'last_name' => $r->str('last_name'), 'first_name' => $r->str('first_name'), 'middle_name' => $r->str('middle_name') ?: null,
                'phone' => $r->str('phone') ?: null, 'company' => $r->str('company') ?: null,
                'pd_consent_at' => now(), 'email_verified_at' => now(), 'created_at' => now(),
            ]);
            self::applyRoles($uid, (array)$r->input('roles', []), $r);
            return $uid;
        });
        Log::act('user.create', 'user', $uid, $email);
        Mailer::send($email, '[' . cfg('app.name') . '] Учётная запись создана', "Для вас создана учётная запись.\nЛогин: $email\nВременный пароль: $pass\nСмените пароль после первого входа.");
        $res = self::user($r, ['id' => (string)$uid]);
        $res['temp_password'] = $generated ? $pass : null;
        return $res;
    }

    public static function updateUser(Request $r, array $p): array
    {
        $id = (int)$p['id'];
        $cur = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$cur) {
            throw HttpException::notFound('Пользователь не найден');
        }
        V::of($r)->required('last_name', 'Фамилия', 50)->required('first_name', 'Имя', 50)->email()->phone()->check();
        $email = mb_strtolower((string)$r->str('email'));
        if (Db::val('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $id])) {
            throw HttpException::conflict('E-mail уже занят');
        }
        $active = $r->input('is_active', $cur['is_active']) ? 1 : 0;
        if ($id === Auth::id() && !$active) {
            throw HttpException::conflict('Нельзя заблокировать самого себя');
        }
        Db::tx(function () use ($r, $id, $email, $active) {
            Db::update('users', [
                'email' => $email, 'last_name' => $r->str('last_name'), 'first_name' => $r->str('first_name'), 'middle_name' => $r->str('middle_name') ?: null,
                'phone' => $r->str('phone') ?: null, 'company' => $r->str('company') ?: null, 'is_active' => $active, 'updated_at' => now(),
            ], 'id = ?', [$id]);
            if ($r->input('roles') !== null) {
                self::applyRoles($id, (array)$r->input('roles'), $r);
            }
        });
        if (!$active) {
            Db::update('users', ['locked_until' => null], 'id = ?', [$id]);
        }
        Log::act('user.update', 'user', $id, $email . ($active ? '' : ' (заблокирован)'));
        return self::user($r, $p);
    }

    public static function resetPassword(Request $r, array $p): array
    {
        $u = Db::one('SELECT id, email FROM users WHERE id = ?', [(int)$p['id']]);
        if (!$u) {
            throw HttpException::notFound('Пользователь не найден');
        }
        $pass = substr(bin2hex(random_bytes(6)), 0, 10) . 'A1';
        Db::update('users', ['password_hash' => Auth::hashPassword($pass), 'failed_attempts' => 0, 'locked_until' => null, 'updated_at' => now()], 'id = ?', [$u['id']]);
        Mailer::send($u['email'], '[' . cfg('app.name') . '] Пароль сброшен администратором', "Ваш временный пароль: $pass\nСмените его после входа.");
        Log::act('user.reset_password', 'user', (int)$u['id']);
        return ['temp_password' => $pass];
    }

    // ---------- Справочники ----------

    private const DIRS = [
        'specializations' => ['table' => 'specializations', 'fields' => ['name' => 'str', 'description' => 'str?', 'is_active' => 'bool']],
        'services' => ['table' => 'services', 'fields' => ['specialization_id' => 'int', 'name' => 'str', 'description' => 'str?', 'duration_min' => 'int', 'price' => 'num', 'is_active' => 'bool']],
        'categories' => ['table' => 'ticket_categories', 'fields' => ['name' => 'str', 'is_active' => 'bool']],
    ];

    public static function directory(Request $r, array $p): array
    {
        $d = self::DIRS[$p['dir']] ?? null;
        if (!$d) {
            throw HttpException::notFound('Справочник не найден');
        }
        if ($p['dir'] === 'services') {
            return Db::all('SELECT sv.*, sp.name AS specialization_name FROM services sv JOIN specializations sp ON sp.id = sv.specialization_id ORDER BY sp.name, sv.name');
        }
        return Db::all('SELECT * FROM ' . $d['table'] . ' ORDER BY name');
    }

    public static function saveDirectory(Request $r, array $p): array
    {
        $d = self::DIRS[$p['dir']] ?? null;
        if (!$d) {
            throw HttpException::notFound('Справочник не найден');
        }
        $data = [];
        foreach ($d['fields'] as $f => $type) {
            $v = $r->input($f);
            switch ($type) {
                case 'str':
                    $v = trim((string)$v);
                    if ($v === '' || mb_strlen($v) > 150) {
                        throw HttpException::bad("Поле «{$f}» обязательно (до 150 символов)", [$f => 'Обязательное поле']);
                    }
                    break;
                case 'str?':
                    $v = trim((string)$v) ?: null;
                    break;
                case 'bool':
                    $v = $v === null ? 1 : ($v ? 1 : 0);
                    break;
                case 'int':
                    $v = (int)$v;
                    break;
                case 'num':
                    $v = (float)$v;
                    break;
            }
            $data[$f] = $v;
        }
        if ($p['dir'] === 'services') {
            if ($data['duration_min'] < 15 || $data['duration_min'] > 480) {
                throw HttpException::bad('Длительность: от 15 до 480 минут', ['duration_min' => 'От 15 до 480']);
            }
            if ($data['price'] < 0) {
                throw HttpException::bad('Цена не может быть отрицательной', ['price' => 'Неверная цена']);
            }
            if (!Db::val('SELECT 1 FROM specializations WHERE id = ?', [$data['specialization_id']])) {
                throw HttpException::bad('Выберите направление', ['specialization_id' => 'Выберите направление']);
            }
        }
        try {
            if (isset($p['id'])) {
                Db::update($d['table'], $data, 'id = ?', [(int)$p['id']]);
                $id = (int)$p['id'];
            } else {
                $id = Db::insert($d['table'], $data);
            }
        } catch (\PDOException $e) {
            if ((int)$e->getCode() === 23000) {
                throw HttpException::conflict('Запись с таким названием уже существует');
            }
            throw $e;
        }
        Log::act('directory.save', $p['dir'], $id, (string)($data['name'] ?? ''));
        return Db::one('SELECT * FROM ' . $d['table'] . ' WHERE id = ?', [$id]);
    }

    public static function priorities(Request $r): array
    {
        return Db::all('SELECT * FROM priorities ORDER BY level');
    }

    public static function savePriority(Request $r, array $p): array
    {
        $resp = (int)$r->int('response_minutes', 0);
        $res = (int)$r->int('resolve_hours', 0);
        $w = (int)$r->int('weight', 0);
        if ($resp < 1 || $res < 1 || $w < 1 || $resp > 10000 || $res > 1000) {
            throw HttpException::bad('Проверьте значения: нормативы и вес должны быть положительными');
        }
        Db::update('priorities', [
            'name' => mb_substr((string)$r->str('name', ''), 0, 50) ?: Db::val('SELECT name FROM priorities WHERE id = ?', [(int)$p['id']]),
            'weight' => $w, 'response_minutes' => $resp, 'resolve_hours' => $res, 'calendar_time' => $r->input('calendar_time') ? 1 : 0,
        ], 'id = ?', [(int)$p['id']]);
        Log::act('priority.save', 'priority', (int)$p['id']);
        return Db::one('SELECT * FROM priorities WHERE id = ?', [(int)$p['id']]);
    }

    // ---------- Настройки ----------

    public static function settings(Request $r): array
    {
        return Db::all('SELECT name, value, description FROM settings ORDER BY name');
    }

    public static function saveSettings(Request $r): array
    {
        $in = (array)$r->input('settings', []);
        $known = array_column(Db::all('SELECT name FROM settings'), 'name');
        $numeric = ['payment_timeout_min', 'slot_step_min', 'buffer_min', 'min_booking_hours', 'booking_horizon_days', 'free_cancel_hours', 'partial_refund_percent',
            'no_refund_hours', 'overdue_check_min', 'task_due_notify_hours', 'sla_warn_percent', 'sla_target_percent', 'auto_assign'];
        foreach ($in as $k => $v) {
            if (!in_array($k, $known, true)) {
                continue;
            }
            $v = trim((string)$v);
            if (in_array($k, $numeric, true) && (!ctype_digit($v) || (int)$v > 100000)) {
                throw HttpException::bad("Параметр «{$k}» должен быть неотрицательным числом");
            }
            if (in_array($k, ['partial_refund_percent', 'sla_warn_percent', 'sla_target_percent'], true) && (int)$v > 100) {
                throw HttpException::bad("Параметр «{$k}» не может превышать 100");
            }
            if (in_array($k, ['slot_step_min'], true) && ((int)$v < 5 || (int)$v > 240)) {
                throw HttpException::bad('Шаг сетки слотов: от 5 до 240 минут');
            }
            Settings::set($k, $v);
        }
        Log::act('settings.save', 'settings', null, implode(',', array_keys($in)));
        return self::settings($r);
    }

    // ---------- Журнал, резервные копии, cron ----------

    public static function log(Request $r): array
    {
        $where = ['1=1'];
        $p = [];
        if ($v = $r->q('action')) { $where[] = 'l.action LIKE ?'; $p[] = "$v%"; }
        if ($v = $r->qint('user_id')) { $where[] = 'l.user_id = ?'; $p[] = $v; }
        if ($v = $r->q('from')) { $where[] = 'l.created_at >= ?'; $p[] = $v . ' 00:00:00'; }
        if ($v = $r->q('to')) { $where[] = 'l.created_at <= ?'; $p[] = $v . ' 23:59:59'; }
        $limit = min(200, $r->qint('limit', 50));
        $page = max(1, $r->qint('page', 1));
        $w = implode(' AND ', $where);
        $total = (int)Db::val("SELECT COUNT(*) FROM activity_log l WHERE $w", $p);
        $rows = Db::all("SELECT l.id, l.action, l.entity_type, l.entity_id, l.details, l.ip, l.created_at, CONCAT(u.last_name, ' ', u.first_name) AS user_name
                         FROM activity_log l LEFT JOIN users u ON u.id = l.user_id WHERE $w ORDER BY l.id DESC LIMIT $limit OFFSET " . (($page - 1) * $limit), $p);
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    public static function backups(Request $r): array
    {
        return ['items' => Backup::list()];
    }

    public static function createBackup(Request $r): array
    {
        $name = Backup::create();
        Log::act('backup.create', 'backup', null, $name);
        return ['name' => $name, 'items' => Backup::list()];
    }

    public static function downloadBackup(Request $r, array $p): Raw
    {
        $name = basename($p['name']);
        $path = Backup::dir() . '/' . $name;
        if (!preg_match('/^backup_\d{8}_\d{6}\.sql$/', $name) || !is_file($path)) {
            throw HttpException::notFound('Копия не найдена');
        }
        Log::act('backup.download', 'backup', null, $name);
        return new Raw('application/sql', '', $name, $path);
    }

    public static function cronJobs(Request $r): array
    {
        $out = [];
        foreach (Cron::JOBS as $k => $v) {
            $last = Db::one("SELECT created_at, details FROM activity_log WHERE action = ? ORDER BY id DESC LIMIT 1", ['cron.' . $k]);
            $out[] = ['job' => $k, 'title' => $v, 'last_run' => $last['created_at'] ?? null, 'last_result' => $last['details'] ?? null];
        }
        return $out;
    }

    public static function runCron(Request $r, array $p): array
    {
        if (!isset(Cron::JOBS[$p['job']])) {
            throw HttpException::notFound('Неизвестное задание');
        }
        return Cron::run($p['job']);
    }

    /** Файлы писем, отправленных в режиме разработки (без SMTP). */
    public static function mails(Request $r): array
    {
        $files = glob(STORAGE . '/mails/*.txt') ?: [];
        rsort($files);
        $out = [];
        foreach (array_slice($files, 0, 30) as $f) {
            $out[] = ['name' => basename($f), 'content' => mb_substr((string)file_get_contents($f), 0, 3000)];
        }
        return $out;
    }
}
