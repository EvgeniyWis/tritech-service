<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Log;
use App\Core\Raw;
use App\Core\Request;
use App\Services\Xlsx;

final class ReportController
{
    public const TYPES = [
        'summary' => 'Сводка директора',
        'revenue' => 'Выручка консалтинга',
        'consultations' => 'Консультации по консультантам',
        'sla' => 'SLA техподдержки',
        'support_load' => 'Нагрузка специалистов ТП',
        'dev_load' => 'Загрузка разработчиков',
        'timelogs' => 'Трудозатраты по проектам',
        'projects' => 'Ход проектов',
    ];

    public static function types(Request $r): array
    {
        $out = [];
        foreach (self::TYPES as $k => $v) {
            $out[] = ['type' => $k, 'title' => $v];
        }
        return $out;
    }

    public static function get(Request $r, array $p): mixed
    {
        $type = $p['type'];
        if (!isset(self::TYPES[$type])) {
            throw HttpException::notFound('Неизвестный отчёт');
        }
        $from = $r->q('from', date('Y-m-01'));
        $to = $r->q('to', date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) {
            throw HttpException::bad('Некорректный период');
        }
        $rep = self::{'r_' . $type}($from, $to . ' 23:59:59', $from . ' 00:00:00');
        $rep['title'] = self::TYPES[$type];
        $rep['period'] = ['from' => $from, 'to' => $to];
        $format = $r->q('format', 'json');
        if ($format === 'json') {
            return $rep;
        }
        Log::act('report.export', 'report', null, "$type $format $from..$to");
        $headers = array_column($rep['columns'], 'label');
        $keys = array_column($rep['columns'], 'key');
        $rows = array_map(fn($row) => array_map(fn($k) => $row[$k] ?? '', $keys), $rep['rows']);
        $base = 'report_' . $type . '_' . $from . '_' . $to;
        if ($format === 'xlsx') {
            return new Raw('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', Xlsx::build($rep['title'], $headers, $rows), $base . '.xlsx');
        }
        // CSV для Excel: разделитель «;», BOM UTF-8
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $headers, ';');
        foreach ($rows as $row) {
            fputcsv($fh, $row, ';');
        }
        rewind($fh);
        return new Raw('text/csv; charset=utf-8', "\xEF\xBB\xBF" . stream_get_contents($fh), $base . '.csv');
    }

    private static function r_summary(string $from, string $toDt, string $fromDt): array
    {
        $to = substr($toDt, 0, 10);
        $rows = [
            ['metric' => 'Консультаций проведено', 'value' => (int)Db::val("SELECT COUNT(*) FROM appointments a JOIN appointment_statuses s ON s.id = a.status_id WHERE s.code = 'completed' AND a.start_at BETWEEN ? AND ?", [$fromDt, $toDt])],
            ['metric' => 'Неявок', 'value' => (int)Db::val("SELECT COUNT(*) FROM appointments a JOIN appointment_statuses s ON s.id = a.status_id WHERE s.code = 'no_show' AND a.start_at BETWEEN ? AND ?", [$fromDt, $toDt])],
            ['metric' => 'Выручка консалтинга, ₽', 'value' => (float)Db::val("SELECT COALESCE(SUM(CASE WHEN type = 'payment' THEN amount ELSE -amount END), 0) FROM payments WHERE status = 'succeeded' AND COALESCE(paid_at, created_at) BETWEEN ? AND ?", [$fromDt, $toDt])],
            ['metric' => 'Проектов создано по итогам консультаций', 'value' => (int)Db::val('SELECT COUNT(*) FROM projects WHERE appointment_id IS NOT NULL AND created_at BETWEEN ? AND ?', [$fromDt, $toDt])],
            ['metric' => 'Задач разработки закрыто', 'value' => (int)Db::val("SELECT COUNT(*) FROM tasks WHERE status = 'done' AND completed_at BETWEEN ? AND ?", [$fromDt, $toDt])],
            ['metric' => 'Часов списано', 'value' => (float)Db::val('SELECT COALESCE(SUM(hours), 0) FROM time_logs WHERE work_date BETWEEN ? AND ?', [$from, $to])],
            ['metric' => 'Заявок в поддержку создано', 'value' => (int)Db::val('SELECT COUNT(*) FROM tickets WHERE created_at BETWEEN ? AND ?', [$fromDt, $toDt])],
            ['metric' => 'Эскалаций в разработку', 'value' => (int)Db::val('SELECT COUNT(*) FROM tasks WHERE ticket_id IS NOT NULL AND created_at BETWEEN ? AND ?', [$fromDt, $toDt])],
            ['metric' => 'Выполнение SLA, %', 'value' => DashboardController::slaPercent($from, date('Y-m-d', strtotime($to . ' +1 day')))],
        ];
        return ['columns' => [['key' => 'metric', 'label' => 'Показатель'], ['key' => 'value', 'label' => 'Значение']], 'rows' => $rows, 'summary' => []];
    }

    private static function r_revenue(string $from, string $toDt, string $fromDt): array
    {
        $rows = Db::all("SELECT CONCAT(cu.last_name, ' ', cu.first_name) AS consultant, sv.name AS service,
                           SUM(p.type = 'payment') AS payments, SUM(p.type = 'refund') AS refunds,
                           SUM(CASE WHEN p.type = 'payment' THEN p.amount ELSE 0 END) AS paid,
                           SUM(CASE WHEN p.type = 'refund' THEN p.amount ELSE 0 END) AS refunded,
                           SUM(CASE WHEN p.type = 'payment' THEN p.amount ELSE -p.amount END) AS revenue
                         FROM payments p JOIN appointments a ON a.id = p.appointment_id JOIN services sv ON sv.id = a.service_id
                         JOIN consultants c ON c.id = a.consultant_id JOIN users cu ON cu.id = c.user_id
                         WHERE p.status = 'succeeded' AND COALESCE(p.paid_at, p.created_at) BETWEEN ? AND ?
                         GROUP BY c.id, cu.last_name, cu.first_name, sv.id, sv.name ORDER BY revenue DESC", [$fromDt, $toDt]);
        $monthly = Db::all('SELECT month, revenue, payments_count, refunds_count FROM v_monthly_revenue ORDER BY month DESC LIMIT 12');
        return [
            'columns' => [['key' => 'consultant', 'label' => 'Консультант'], ['key' => 'service', 'label' => 'Услуга'], ['key' => 'payments', 'label' => 'Платежей'],
                ['key' => 'paid', 'label' => 'Оплачено, ₽'], ['key' => 'refunds', 'label' => 'Возвратов'], ['key' => 'refunded', 'label' => 'Возвращено, ₽'], ['key' => 'revenue', 'label' => 'Выручка, ₽']],
            'rows' => $rows,
            'summary' => ['Выручка за период, ₽' => array_sum(array_column($rows, 'revenue')), 'Платежей' => array_sum(array_column($rows, 'payments')), 'Возвратов' => array_sum(array_column($rows, 'refunds'))],
            'chart' => array_reverse($monthly),
        ];
    }

    private static function r_consultations(string $from, string $toDt, string $fromDt): array
    {
        $rows = Db::all("SELECT CONCAT(cu.last_name, ' ', cu.first_name) AS consultant,
                           COUNT(a.id) AS total, SUM(s.code = 'completed') AS completed, SUM(s.code = 'confirmed') AS planned,
                           SUM(s.code = 'cancelled') AS cancelled, SUM(s.code = 'no_show') AS no_show,
                           ROUND(COALESCE(AVG(CASE WHEN rv.status = 'published' THEN rv.rating END), 0), 2) AS rating
                         FROM consultants c JOIN users cu ON cu.id = c.user_id
                         LEFT JOIN appointments a ON a.consultant_id = c.id AND a.start_at BETWEEN ? AND ?
                         LEFT JOIN appointment_statuses s ON s.id = a.status_id LEFT JOIN reviews rv ON rv.appointment_id = a.id
                         GROUP BY c.id, cu.last_name, cu.first_name ORDER BY completed DESC", [$fromDt, $toDt]);
        return [
            'columns' => [['key' => 'consultant', 'label' => 'Консультант'], ['key' => 'total', 'label' => 'Всего записей'], ['key' => 'completed', 'label' => 'Проведено'],
                ['key' => 'planned', 'label' => 'Запланировано'], ['key' => 'cancelled', 'label' => 'Отменено'], ['key' => 'no_show', 'label' => 'Неявки'], ['key' => 'rating', 'label' => 'Рейтинг']],
            'rows' => $rows,
            'summary' => ['Записей' => array_sum(array_column($rows, 'total')), 'Проведено' => array_sum(array_column($rows, 'completed'))],
        ];
    }

    private static function r_sla(string $from, string $toDt, string $fromDt): array
    {
        $rows = Db::all("SELECT pr.name AS priority, c.name AS category, COUNT(*) AS total,
                           SUM(v.first_response_at IS NOT NULL AND v.first_response_at <= v.first_response_due) AS response_ok,
                           SUM(v.resolved_at IS NOT NULL AND v.resolved_at <= v.due_at) AS resolve_ok,
                           SUM((v.resolved_at IS NOT NULL AND v.resolved_at > v.due_at) OR (v.resolved_at IS NULL AND v.due_at < NOW())) AS violated,
                           ROUND(AVG(CASE WHEN v.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, v.created_at, v.resolved_at) END) / 60, 1) AS avg_hours
                         FROM v_sla_report v JOIN priorities pr ON pr.id = v.priority_id JOIN ticket_categories c ON c.id = v.category_id
                         WHERE v.created_at BETWEEN ? AND ? GROUP BY pr.id, pr.name, c.id, c.name ORDER BY pr.level DESC, c.name", [$fromDt, $toDt]);
        foreach ($rows as &$r) {
            $done = (int)$r['resolve_ok'] + (int)$r['violated'];
            $r['percent'] = $done ? round((int)$r['resolve_ok'] * 100 / $done, 1) : 100;
        }
        $ok = array_sum(array_column($rows, 'resolve_ok'));
        $bad = array_sum(array_column($rows, 'violated'));
        return [
            'columns' => [['key' => 'priority', 'label' => 'Приоритет'], ['key' => 'category', 'label' => 'Категория'], ['key' => 'total', 'label' => 'Заявок'],
                ['key' => 'response_ok', 'label' => 'Реакция в срок'], ['key' => 'resolve_ok', 'label' => 'Решено в срок'], ['key' => 'violated', 'label' => 'Нарушений'],
                ['key' => 'percent', 'label' => 'SLA, %'], ['key' => 'avg_hours', 'label' => 'Ср. время решения, ч']],
            'rows' => $rows,
            'summary' => ['Заявок' => array_sum(array_column($rows, 'total')), 'SLA, %' => ($ok + $bad) ? round($ok * 100 / ($ok + $bad), 1) : 100],
        ];
    }

    private static function r_support_load(string $from, string $toDt, string $fromDt): array
    {
        $rows = Db::all("SELECT CONCAT(u.last_name, ' ', u.first_name) AS specialist,
                           SUM(t.status IN ('new','accepted','in_progress','waiting_client')) AS open_tickets,
                           COALESCE(SUM(CASE WHEN t.status IN ('new','accepted','in_progress','waiting_client') THEN pr.weight END), 0) AS load_l,
                           SUM(t.status IN ('resolved','closed') AND t.resolved_at BETWEEN ? AND ?) AS resolved,
                           SUM(t.due_at < NOW() AND t.status IN ('new','accepted','in_progress','waiting_client')) AS breached
                         FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles ro ON ro.id = ur.role_id AND ro.code = 'support'
                         LEFT JOIN tickets t ON t.assignee_id = u.id LEFT JOIN priorities pr ON pr.id = t.priority_id
                         WHERE u.is_active = 1 GROUP BY u.id, u.last_name, u.first_name ORDER BY load_l DESC", [$fromDt, $toDt]);
        return [
            'columns' => [['key' => 'specialist', 'label' => 'Специалист'], ['key' => 'open_tickets', 'label' => 'Открытых заявок'], ['key' => 'load_l', 'label' => 'Нагрузка L'],
                ['key' => 'resolved', 'label' => 'Решено за период'], ['key' => 'breached', 'label' => 'Просрочено']],
            'rows' => $rows, 'summary' => ['Открытых заявок' => array_sum(array_column($rows, 'open_tickets'))],
        ];
    }

    private static function r_dev_load(string $from, string $toDt, string $fromDt): array
    {
        $rows = Db::all("SELECT w.name AS developer, w.open_tasks, w.estimate_hours,
                           (SELECT COALESCE(SUM(l.hours), 0) FROM time_logs l WHERE l.user_id = w.user_id AND l.work_date BETWEEN ? AND ?) AS logged,
                           (SELECT COUNT(*) FROM tasks t WHERE t.assignee_id = w.user_id AND t.status <> 'done' AND t.due_date < CURDATE()) AS overdue
                         FROM v_dev_workload w ORDER BY w.estimate_hours DESC", [$from, substr($toDt, 0, 10)]);
        return [
            'columns' => [['key' => 'developer', 'label' => 'Разработчик'], ['key' => 'open_tasks', 'label' => 'Открытых задач'], ['key' => 'estimate_hours', 'label' => 'Оценка, ч'],
                ['key' => 'logged', 'label' => 'Списано за период, ч'], ['key' => 'overdue', 'label' => 'Просрочено']],
            'rows' => $rows, 'summary' => ['Списано, ч' => array_sum(array_column($rows, 'logged'))],
        ];
    }

    private static function r_timelogs(string $from, string $toDt, string $fromDt): array
    {
        $rows = Db::all("SELECT pr.name AS project, COALESCE(c.company, CONCAT(c.last_name, ' ', c.first_name)) AS client,
                           CONCAT(u.last_name, ' ', u.first_name) AS developer, ROUND(SUM(l.hours), 2) AS hours, COUNT(DISTINCT l.task_id) AS tasks
                         FROM time_logs l JOIN tasks t ON t.id = l.task_id JOIN projects pr ON pr.id = t.project_id JOIN users c ON c.id = pr.client_id JOIN users u ON u.id = l.user_id
                         WHERE l.work_date BETWEEN ? AND ? GROUP BY pr.id, pr.name, c.id, u.id ORDER BY pr.name, hours DESC", [$from, substr($toDt, 0, 10)]);
        return [
            'columns' => [['key' => 'project', 'label' => 'Проект'], ['key' => 'client', 'label' => 'Заказчик'], ['key' => 'developer', 'label' => 'Разработчик'], ['key' => 'tasks', 'label' => 'Задач'], ['key' => 'hours', 'label' => 'Часов']],
            'rows' => $rows, 'summary' => ['Всего часов' => round(array_sum(array_column($rows, 'hours')), 2)],
        ];
    }

    private static function r_projects(string $from, string $toDt, string $fromDt): array
    {
        $rows = Db::all("SELECT p.name AS project, COALESCE(c.company, CONCAT(c.last_name, ' ', c.first_name)) AS client, p.status,
                           CONCAT(o.last_name, ' ', o.first_name) AS owner, p.start_date, p.end_date,
                           COUNT(t.id) AS tasks_total, COALESCE(SUM(t.status = 'done'), 0) AS tasks_done,
                           COALESCE(SUM(t.estimate_hours), 0) AS est_total, COALESCE(SUM(CASE WHEN t.status = 'done' THEN t.estimate_hours END), 0) AS est_done
                         FROM projects p JOIN users c ON c.id = p.client_id JOIN users o ON o.id = p.owner_id LEFT JOIN tasks t ON t.project_id = p.id
                         GROUP BY p.id, c.id, o.id ORDER BY p.start_date DESC");
        $names = \App\Services\Projects::STATUSES;
        foreach ($rows as &$r) {
            $r['status'] = $names[$r['status']] ?? $r['status'];
            $r['percent'] = (float)$r['est_total'] > 0 ? round((float)$r['est_done'] * 100 / (float)$r['est_total']) : 0;
        }
        return [
            'columns' => [['key' => 'project', 'label' => 'Проект'], ['key' => 'client', 'label' => 'Заказчик'], ['key' => 'status', 'label' => 'Статус'], ['key' => 'owner', 'label' => 'Руководитель'],
                ['key' => 'start_date', 'label' => 'Начало'], ['key' => 'end_date', 'label' => 'Окончание'], ['key' => 'tasks_total', 'label' => 'Задач'], ['key' => 'tasks_done', 'label' => 'Выполнено'],
                ['key' => 'est_total', 'label' => 'Оценка, ч'], ['key' => 'percent', 'label' => 'Прогресс, %']],
            'rows' => $rows, 'summary' => ['Проектов' => count($rows)],
        ];
    }
}
