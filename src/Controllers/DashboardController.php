<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Request;
use App\Core\Settings;
use App\Services\Sla;
use App\Services\Tickets;
use DateTimeImmutable;

final class DashboardController
{
    /** @return array{0:string,1:string} границы периода [from, to) */
    public static function period(string $p): array
    {
        $today = date('Y-m-d');
        switch ($p) {
            case 'week':
                $from = date('Y-m-d', strtotime('monday this week'));
                return [$from, date('Y-m-d', strtotime($from . ' +7 days'))];
            case 'month':
                $from = date('Y-m-01');
                return [$from, date('Y-m-d', strtotime($from . ' +1 month'))];
            case 'quarter':
                $m = (int)date('n');
                $qm = $m - (($m - 1) % 3);
                $from = date('Y-') . str_pad((string)$qm, 2, '0', STR_PAD_LEFT) . '-01';
                return [$from, date('Y-m-d', strtotime($from . ' +3 months'))];
            default:
                return [$today, date('Y-m-d', strtotime($today . ' +1 day'))];
        }
    }

    public static function get(Request $r): array
    {
        $dept = $r->q('dept', 'all');
        [$from, $to] = self::period($r->q('period', 'today'));
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $now = now();

        // --- KPI ---
        $c = Db::one("SELECT COUNT(*) AS total, SUM(s.code = 'no_show') AS no_show
                      FROM appointments a JOIN appointment_statuses s ON s.id = a.status_id
                      WHERE a.start_at >= ? AND a.start_at < ? AND s.code IN ('confirmed','completed','no_show')", [$today, $tomorrow]);
        $tasks = Db::one("SELECT COUNT(*) AS working, SUM(due_date IS NOT NULL AND due_date < CURDATE()) AS overdue, COUNT(DISTINCT project_id) AS projects
                          FROM tasks WHERE status IN ('in_progress','testing')");
        $overdueAll = (int)Db::val("SELECT COUNT(*) FROM tasks WHERE status <> 'done' AND due_date < CURDATE()");
        $open = Db::one("SELECT COUNT(*) AS total, SUM(created_at >= ?) AS today FROM tickets WHERE status IN ('new','accepted','in_progress','waiting_client')", [$today]);
        $monthFrom = date('Y-m-01');
        $slaMonth = self::slaPercent($monthFrom, date('Y-m-d', strtotime($monthFrom . ' +1 month')));
        $breachToday = (int)Db::val("SELECT COUNT(*) FROM tickets WHERE (status IN ('new','accepted','in_progress','waiting_client') AND due_at < ? AND due_at >= ?) OR (resolved_at > due_at AND resolved_at >= ? AND resolved_at < ?)", [$now, $today, $today, $tomorrow]);
        $openBreached = (int)Db::val("SELECT COUNT(*) FROM tickets WHERE status IN ('new','accepted','in_progress','waiting_client') AND due_at < ?", [$now]);

        // --- Просрочено и под угрозой ---
        $risks = [];
        if (in_array($dept, ['all', 'support'], true)) {
            foreach (Db::all(Tickets::baseSelect() . " WHERE t.status IN ('new','accepted','in_progress','waiting_client') ORDER BY t.due_at LIMIT 40") as $t) {
                $s = Sla::state($t);
                if ($s['state'] === 'ok') {
                    continue;
                }
                $risks[] = ['kind' => 'ticket', 'id' => (int)$t['id'], 'number' => $t['number'], 'module' => 'Техподдержка', 'title' => $t['subject'],
                    'assignee' => $t['assignee_name'], 'remaining_min' => $s['remaining_min'], 'state' => $s['state'], 'status' => $t['status'], 'status_name' => Tickets::STATUSES[$t['status']]];
            }
        }
        if (in_array($dept, ['all', 'dev'], true)) {
            foreach (Db::all("SELECT t.id, CONCAT('TS-', t.id) AS number, t.title, t.status, t.due_date, CONCAT(u.last_name, ' ', LEFT(u.first_name, 1), '.') AS assignee
                              FROM tasks t LEFT JOIN users u ON u.id = t.assignee_id
                              WHERE t.status <> 'done' AND t.due_date IS NOT NULL AND t.due_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY) ORDER BY t.due_date LIMIT 20") as $t) {
                $left = intdiv(strtotime($t['due_date'] . ' 23:59:59') - time(), 60);
                $risks[] = ['kind' => 'task', 'id' => (int)$t['id'], 'number' => $t['number'], 'module' => 'Разработка', 'title' => $t['title'],
                    'assignee' => $t['assignee'], 'remaining_min' => $left, 'state' => $left < 0 ? 'breached' : 'warning', 'status' => $t['status'], 'status_name' => \App\Services\Tasks::STATUSES[$t['status']]];
            }
        }
        if (in_array($dept, ['all', 'consulting'], true)) {
            foreach (Db::all("SELECT a.id, CONCAT(YEAR(a.created_at), '-', LPAD(a.id, 4, '0')) AS number, a.payment_deadline, sv.name AS service,
                                CONCAT(u.last_name, ' ', u.first_name) AS client
                              FROM appointments a JOIN appointment_statuses s ON s.id = a.status_id JOIN services sv ON sv.id = a.service_id JOIN users u ON u.id = a.client_id
                              WHERE s.code = 'pending_payment' AND a.payment_deadline > ? ORDER BY a.payment_deadline LIMIT 10", [$now]) as $a) {
                $risks[] = ['kind' => 'appointment', 'id' => (int)$a['id'], 'number' => '№ ' . $a['number'], 'module' => 'Консалтинг',
                    'title' => 'Запись не оплачена — слот освободится автоматически', 'assignee' => null,
                    'remaining_min' => intdiv(strtotime($a['payment_deadline']) - time(), 60), 'state' => 'warning', 'status' => 'pending_payment', 'status_name' => 'Ожидает оплаты'];
            }
        }
        usort($risks, fn($a, $b) => $a['remaining_min'] <=> $b['remaining_min']);
        $risks = array_slice($risks, 0, 8);

        // --- Загрузка отделов (текущая неделя) ---
        $wFrom = date('Y-m-d', strtotime('monday this week'));
        $wTo = date('Y-m-d', strtotime($wFrom . ' +7 days'));
        $consultants = (int)Db::val('SELECT COUNT(*) FROM consultants WHERE is_active = 1');
        $capMin = 0;
        foreach (Db::all('SELECT ws.weekday, TIME_TO_SEC(TIMEDIFF(ws.end_time, ws.start_time)) / 60 AS m FROM work_schedules ws JOIN consultants c ON c.id = ws.consultant_id AND c.is_active = 1') as $w) {
            $capMin += (float)$w['m'];
        }
        $bookedMin = (float)Db::val("SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, a.start_at, a.end_at)), 0) FROM appointments a JOIN appointment_statuses s ON s.id = a.status_id
                                     WHERE s.code IN ('pending_payment','confirmed','completed') AND a.start_at >= ? AND a.start_at < ?", [$wFrom, $wTo]);
        $devs = (int)Db::val("SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles ro ON ro.id = ur.role_id AND ro.code = 'developer' WHERE u.is_active = 1");
        $devLogged = (float)Db::val('SELECT COALESCE(SUM(hours), 0) FROM time_logs WHERE work_date >= ? AND work_date < ?', [$wFrom, $wTo]);
        $devCap = $devs * 40;
        $specs = count(\App\Core\Notifier::usersWithRole('support'));
        $loadSum = 0;
        foreach (\App\Core\Notifier::usersWithRole('support') as $uid) {
            $loadSum += Tickets::load($uid);
        }
        $supCap = max(1, $specs * 15);
        $load = [
            ['key' => 'consulting', 'name' => 'Консалтинг', 'percent' => $capMin ? (int)min(100, round($bookedMin * 100 / $capMin)) : 0,
             'note' => round($bookedMin / 60) . ' из ' . round($capMin / 60) . ' ч · ' . $consultants . ' консультантов'],
            ['key' => 'dev', 'name' => 'Разработка', 'percent' => $devCap ? (int)min(100, round($devLogged * 100 / $devCap)) : 0,
             'note' => round($devLogged) . ' из ' . $devCap . ' ч · ' . $devs . ' сотрудников'],
            ['key' => 'support', 'name' => 'Техподдержка', 'percent' => (int)min(100, round($loadSum * 100 / $supCap)),
             'note' => (int)$open['total'] . ' открытые заявки · ' . $specs . ' специалистов'],
        ];

        // --- SLA по приоритетам (текущий месяц) ---
        $slaBy = [];
        foreach (Db::all('SELECT id, name, code FROM priorities ORDER BY level DESC') as $pr) {
            $slaBy[] = ['priority' => $pr['name'], 'code' => $pr['code'], 'percent' => self::slaPercent($monthFrom, date('Y-m-d', strtotime($monthFrom . ' +1 month')), (int)$pr['id'])];
        }

        // --- Сквозной процесс за выбранный период ---
        $flow = [
            'consultations' => (int)Db::val("SELECT COUNT(*) FROM appointments a JOIN appointment_statuses s ON s.id = a.status_id WHERE s.code = 'completed' AND a.start_at >= ? AND a.start_at < ?", [$from, $to]),
            'projects' => (int)Db::val('SELECT COUNT(*) FROM projects WHERE appointment_id IS NOT NULL AND created_at >= ? AND created_at < ?', [$from, $to]),
            'tasks_done' => (int)Db::val("SELECT COUNT(*) FROM tasks WHERE status = 'done' AND completed_at >= ? AND completed_at < ?", [$from, $to]),
            'tickets' => (int)Db::val('SELECT COUNT(*) FROM tickets WHERE created_at >= ? AND created_at < ?', [$from, $to]),
            'escalations' => (int)Db::val('SELECT COUNT(*) FROM tasks WHERE ticket_id IS NOT NULL AND created_at >= ? AND created_at < ?', [$from, $to]),
        ];

        $target = Settings::int('sla_target_percent', 92);
        return [
            'period' => ['from' => $from, 'to' => $to],
            'kpi' => [
                'consultations_today' => ['value' => (int)$c['total'], 'no_show' => (int)$c['no_show'], 'planned' => (int)Db::val("SELECT COUNT(*) FROM appointments a JOIN appointment_statuses s ON s.id = a.status_id WHERE a.start_at >= ? AND a.start_at < ? AND s.code IN ('confirmed','completed','no_show','pending_payment')", [$today, $tomorrow])],
                'tasks_in_work' => ['value' => (int)$tasks['working'], 'projects' => (int)$tasks['projects'], 'overdue' => $overdueAll],
                'open_tickets' => ['value' => (int)$open['total'], 'new_today' => (int)$open['today']],
                'sla_breaches' => ['value' => $breachToday, 'open_breached' => $openBreached, 'month_percent' => $slaMonth, 'target' => $target],
            ],
            'risks' => $risks, 'load' => $load, 'sla_by_priority' => $slaBy, 'flow' => $flow,
            'updated_at' => date('H:i'),
        ];
    }

    /** Доля заявок периода, уложившихся в срок решения (открытые просроченные считаются нарушением). */
    public static function slaPercent(string $from, string $to, ?int $priorityId = null): float
    {
        $extra = $priorityId ? ' AND priority_id = ' . (int)$priorityId : '';
        $r = Db::one("SELECT SUM(resolved_at IS NOT NULL AND resolved_at <= due_at) AS ok,
                             SUM((resolved_at IS NOT NULL AND resolved_at > due_at) OR (resolved_at IS NULL AND due_at < ?)) AS bad
                      FROM tickets WHERE created_at >= ? AND created_at < ? $extra", [now(), $from, $to]);
        $ok = (int)$r['ok'];
        $bad = (int)$r['bad'];
        return ($ok + $bad) > 0 ? round($ok * 100 / ($ok + $bad), 1) : 100.0;
    }
}
