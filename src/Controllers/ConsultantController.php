<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Log;
use App\Core\Notifier;
use App\Core\Request;

final class ConsultantController
{
    /** Проверка: свой профиль консультанта или менеджер/админ. */
    private static function access(int $consultantId): void
    {
        $u = Auth::requireLogin();
        if (Auth::hasAnyRole(['manager', 'admin'])) {
            return;
        }
        if ($u['consultant_id'] && (int)$u['consultant_id'] === $consultantId) {
            return;
        }
        throw HttpException::forbidden();
    }

    private static function resolveId(array $p): int
    {
        $id = $p['id'] === 'me' ? (int)Auth::user()['consultant_id'] : (int)$p['id'];
        if (!$id || !Db::val('SELECT 1 FROM consultants WHERE id = ?', [$id])) {
            throw HttpException::notFound('Консультант не найден');
        }
        return $id;
    }

    public static function schedule(Request $r, array $p): array
    {
        $id = self::resolveId($p);
        self::access($id);
        return [
            'consultant_id' => $id,
            'weekly' => Db::all('SELECT id, weekday, TIME_FORMAT(start_time, "%H:%i") AS start_time, TIME_FORMAT(end_time, "%H:%i") AS end_time FROM work_schedules WHERE consultant_id = ? ORDER BY weekday, start_time', [$id]),
            'exceptions' => Db::all('SELECT id, date, is_working, TIME_FORMAT(start_time, "%H:%i") AS start_time, TIME_FORMAT(end_time, "%H:%i") AS end_time, reason FROM schedule_exceptions WHERE consultant_id = ? AND date >= CURDATE() ORDER BY date', [$id]),
        ];
    }

    /** Полная замена недельного расписания. */
    public static function saveSchedule(Request $r, array $p): array
    {
        $id = self::resolveId($p);
        self::access($id);
        $weekly = $r->input('weekly');
        if (!is_array($weekly)) {
            throw HttpException::bad('Некорректное расписание');
        }
        $rows = [];
        foreach ($weekly as $w) {
            $day = (int)($w['weekday'] ?? 0);
            $s = (string)($w['start_time'] ?? '');
            $e = (string)($w['end_time'] ?? '');
            if ($day < 1 || $day > 7 || !preg_match('/^\d{2}:\d{2}$/', $s) || !preg_match('/^\d{2}:\d{2}$/', $e)) {
                throw HttpException::bad('Некорректный интервал работы');
            }
            if ($e <= $s) {
                throw HttpException::bad('Конец интервала должен быть позже начала (' . $s . '–' . $e . ')');
            }
            $rows[] = [$day, $s, $e];
        }
        usort($rows, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        for ($i = 1; $i < count($rows); $i++) {
            if ($rows[$i][0] === $rows[$i - 1][0] && $rows[$i][1] < $rows[$i - 1][2]) {
                throw HttpException::bad('Интервалы одного дня пересекаются');
            }
        }
        Db::tx(function () use ($id, $rows) {
            Db::exec('DELETE FROM work_schedules WHERE consultant_id = ?', [$id]);
            foreach ($rows as [$d, $s, $e]) {
                Db::insert('work_schedules', ['consultant_id' => $id, 'weekday' => $d, 'start_time' => $s, 'end_time' => $e]);
            }
        });
        Log::act('schedule.save', 'consultant', $id);
        return self::schedule($r, ['id' => (string)$id]);
    }

    public static function addException(Request $r, array $p): array
    {
        $id = self::resolveId($p);
        self::access($id);
        $date = (string)$r->str('date', '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < date('Y-m-d')) {
            throw HttpException::bad('Укажите дату не раньше сегодняшней');
        }
        $working = $r->input('is_working') ? 1 : 0;
        $s = $r->str('start_time') ?: null;
        $e = $r->str('end_time') ?: null;
        if ($working && (!$s || !$e || $e <= $s)) {
            throw HttpException::bad('Для особого рабочего дня укажите корректное время');
        }
        // нельзя закрыть день, на который есть записи
        $conflicts = (int)Db::val("SELECT COUNT(*) FROM appointments a JOIN appointment_statuses s ON s.id = a.status_id
                                   WHERE a.consultant_id = ? AND DATE(a.start_at) = ? AND s.code IN ('pending_payment','confirmed')", [$id, $date]);
        if (!$working && $conflicts) {
            throw HttpException::conflict("На эту дату уже есть записей: $conflicts. Сначала перенесите или отмените их.");
        }
        Db::exec('DELETE FROM schedule_exceptions WHERE consultant_id = ? AND date = ?', [$id, $date]);
        Db::insert('schedule_exceptions', [
            'consultant_id' => $id, 'date' => $date, 'is_working' => $working,
            'start_time' => $working ? $s : null, 'end_time' => $working ? $e : null, 'reason' => mb_substr((string)$r->str('reason', ''), 0, 200) ?: null,
        ]);
        Log::act('schedule.exception', 'consultant', $id, $date);
        return self::schedule($r, ['id' => (string)$id]);
    }

    public static function deleteException(Request $r, array $p): array
    {
        $id = self::resolveId($p);
        self::access($id);
        Db::exec('DELETE FROM schedule_exceptions WHERE id = ? AND consultant_id = ?', [(int)$p['eid'], $id]);
        return self::schedule($r, ['id' => (string)$id]);
    }

    /** Календарь консультанта: записи за период. */
    public static function calendar(Request $r, array $p): array
    {
        $id = self::resolveId($p);
        self::access($id);
        $from = $r->q('from', date('Y-m-d'));
        $to = $r->q('to', date('Y-m-d', strtotime('+7 days')));
        $items = Db::all("SELECT a.id, a.start_at, a.end_at, s.code AS status, s.name AS status_name, sv.name AS service_name,
                            CONCAT(u.last_name, ' ', u.first_name) AS client_name, u.company AS client_company,
                            CONCAT(YEAR(a.created_at), '-', LPAD(a.id, 4, '0')) AS number
                          FROM appointments a JOIN appointment_statuses s ON s.id = a.status_id JOIN services sv ON sv.id = a.service_id JOIN users u ON u.id = a.client_id
                          WHERE a.consultant_id = ? AND s.code <> 'cancelled' AND a.start_at >= ? AND a.start_at < DATE_ADD(?, INTERVAL 1 DAY) ORDER BY a.start_at", [$id, $from, $to]);
        $s = self::schedule($r, ['id' => (string)$id]);
        return ['items' => $items, 'weekly' => $s['weekly'], 'exceptions' => $s['exceptions']];
    }

    // ---------- Отзывы ----------

    public static function reviews(Request $r): array
    {
        $u = Auth::user();
        $where = ['1=1'];
        $p = [];
        $manager = Auth::hasAnyRole(['manager', 'admin']);
        if (!$manager) {
            if (!$u['consultant_id']) {
                throw HttpException::forbidden();
            }
            $where[] = "rv.consultant_id = ? AND rv.status = 'published'";
            $p[] = $u['consultant_id'];
        } elseif ($st = $r->q('status')) {
            $where[] = 'rv.status = ?';
            $p[] = $st;
        }
        $rows = Db::all("SELECT rv.id, rv.rating, rv.text, rv.status, rv.created_at, rv.moderated_at, rv.appointment_id,
                           CONCAT(u.last_name, ' ', u.first_name) AS client_name, u.company AS client_company,
                           CONCAT(cu.last_name, ' ', cu.first_name) AS consultant_name, sv.name AS service_name,
                           CONCAT(YEAR(a.created_at), '-', LPAD(a.id, 4, '0')) AS appointment_number
                         FROM reviews rv JOIN users u ON u.id = rv.client_id JOIN consultants c ON c.id = rv.consultant_id JOIN users cu ON cu.id = c.user_id
                         JOIN appointments a ON a.id = rv.appointment_id JOIN services sv ON sv.id = a.service_id
                         WHERE " . implode(' AND ', $where) . ' ORDER BY (rv.status = "pending") DESC, rv.created_at DESC LIMIT 200', $p);
        return ['items' => $rows, 'pending' => (int)Db::val("SELECT COUNT(*) FROM reviews WHERE status = 'pending'")];
    }

    public static function moderate(Request $r, array $p): array
    {
        $rv = Db::one('SELECT * FROM reviews WHERE id = ?', [(int)$p['id']]);
        if (!$rv) {
            throw HttpException::notFound('Отзыв не найден');
        }
        $st = $r->str('status');
        if (!in_array($st, ['published', 'rejected'], true)) {
            throw HttpException::bad('Недопустимое решение');
        }
        Db::update('reviews', ['status' => $st, 'moderated_by' => Auth::id(), 'moderated_at' => now()], 'id = ?', [$rv['id']]);
        Log::act('review.' . $st, 'review', (int)$rv['id']);
        Notifier::send((int)$rv['client_id'], 'review_moderated',
            $st === 'published' ? 'Ваш отзыв опубликован' : 'Отзыв не прошёл модерацию',
            $st === 'published' ? 'Спасибо за обратную связь!' : 'Отзыв нарушает правила публикации.', ['appointment_id' => (int)$rv['appointment_id']], false);
        return ['id' => (int)$rv['id'], 'status' => $st];
    }
}
