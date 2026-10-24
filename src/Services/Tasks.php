<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Log;
use App\Core\Notifier;
use App\Core\Settings;

final class Tasks
{
    public const STATUSES = ['backlog' => 'Backlog', 'in_progress' => 'В работе', 'testing' => 'Тестирование', 'done' => 'Выполнена'];
    public const TYPES = ['feature' => 'Функция', 'bug' => 'Ошибка', 'improvement' => 'Улучшение', 'research' => 'Исследование'];

    public static function baseSelect(): string
    {
        return "SELECT t.*, CONCAT('TS-', t.id) AS number, p.name AS project_name,
                  pr.code AS priority_code, pr.name AS priority_name,
                  CONCAT(u.last_name, ' ', u.first_name) AS assignee_name,
                  CONCAT(LEFT(u.first_name, 1), LEFT(u.last_name, 1)) AS assignee_initials,
                  CONCAT('ТП-', LPAD(t.ticket_id, 5, '0')) AS ticket_number,
                  COALESCE((SELECT SUM(l.hours) FROM time_logs l WHERE l.task_id = t.id), 0) AS logged_hours,
                  (t.status <> 'done' AND t.due_date IS NOT NULL AND t.due_date < CURDATE()) AS overdue
                FROM tasks t
                JOIN projects p ON p.id = t.project_id
                JOIN priorities pr ON pr.id = t.priority_id
                LEFT JOIN users u ON u.id = t.assignee_id";
    }

    public static function find(int $id): array
    {
        $t = Db::one(self::baseSelect() . ' WHERE t.id = ?', [$id]);
        if (!$t) {
            throw HttpException::notFound('Задача не найдена');
        }
        return $t;
    }

    /** Исполнитель с минимальной загрузкой: сначала участники проекта, затем все разработчики. */
    public static function pickDeveloper(int $projectId): ?int
    {
        $sql = "SELECT u.id, COALESCE(SUM(t.estimate_hours), 0) AS h, COUNT(t.id) AS n
                FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id AND r.code = 'developer'
                %s LEFT JOIN tasks t ON t.assignee_id = u.id AND t.status <> 'done'
                WHERE u.is_active = 1 GROUP BY u.id ORDER BY h, n, u.id LIMIT 1";
        $id = Db::val(sprintf($sql, 'JOIN project_members pm ON pm.user_id = u.id AND pm.project_id = ' . (int)$projectId));
        return $id ? (int)$id : (($id = Db::val(sprintf($sql, ''))) ? (int)$id : null);
    }

    /** Исполнитель задачи автоматически становится участником проекта. */
    public static function ensureMember(int $projectId, ?int $userId): void
    {
        if (!$userId || Db::val('SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ?', [$projectId, $userId])) {
            return;
        }
        if (Db::val("SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.code = 'developer'", [$userId])) {
            Db::insert('project_members', ['project_id' => $projectId, 'user_id' => $userId, 'role_in_project' => 'developer', 'allocation_percent' => 20, 'joined_at' => date('Y-m-d')]);
        }
    }

    public static function validate(array $d, bool $partial = false): array
    {
        $out = [];
        if (!$partial || array_key_exists('title', $d)) {
            $t = trim((string)($d['title'] ?? ''));
            if (mb_strlen($t) < 3 || mb_strlen($t) > 200) {
                throw HttpException::bad('Название: от 3 до 200 символов', ['title' => 'Название: от 3 до 200 символов']);
            }
            $out['title'] = $t;
        }
        if (array_key_exists('description', $d)) {
            $out['description'] = trim((string)$d['description']) ?: null;
        }
        if (array_key_exists('type', $d)) {
            if (!isset(self::TYPES[$d['type']])) {
                throw HttpException::bad('Недопустимый тип задачи');
            }
            $out['type'] = $d['type'];
        }
        if (array_key_exists('priority_id', $d) && $d['priority_id']) {
            if (!Db::val('SELECT 1 FROM priorities WHERE id = ?', [(int)$d['priority_id']])) {
                throw HttpException::bad('Неизвестный приоритет');
            }
            $out['priority_id'] = (int)$d['priority_id'];
        }
        if (array_key_exists('assignee_id', $d)) {
            $a = $d['assignee_id'] ? (int)$d['assignee_id'] : null;
            if ($a && !Db::val("SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.code IN ('developer','tech_director')", [$a])) {
                throw HttpException::bad('Исполнитель должен быть разработчиком');
            }
            $out['assignee_id'] = $a;
        }
        if (array_key_exists('estimate_hours', $d)) {
            $h = (float)$d['estimate_hours'];
            if ($h < 0 || $h > 999) {
                throw HttpException::bad('Оценка: от 0 до 999 ч', ['estimate_hours' => 'Оценка: от 0 до 999 ч']);
            }
            $out['estimate_hours'] = $h;
        }
        if (array_key_exists('due_date', $d)) {
            $dd = $d['due_date'] ?: null;
            if ($dd && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dd)) {
                throw HttpException::bad('Некорректный срок');
            }
            $out['due_date'] = $dd;
        }
        return $out;
    }

    public static function create(array $d, int $reporterId): array
    {
        $projectId = (int)($d['project_id'] ?? 0);
        $project = Db::one('SELECT * FROM projects WHERE id = ?', [$projectId]);
        if (!$project) {
            throw HttpException::bad('Проект не найден');
        }
        if ($project['status'] === 'closed') {
            throw HttpException::conflict('Проект закрыт');
        }
        $f = self::validate($d);
        $f += [
            'type' => 'feature', 'description' => null, 'assignee_id' => null, 'estimate_hours' => 0, 'due_date' => null,
            'priority_id' => (int)Db::val("SELECT id FROM priorities WHERE code = 'medium'"),
        ];
        $ticketId = !empty($d['ticket_id']) ? (int)$d['ticket_id'] : null;
        $pos = (int)Db::val("SELECT COALESCE(MAX(position), 0) + 1 FROM tasks WHERE project_id = ? AND status = 'backlog'", [$projectId]);
        $id = Db::insert('tasks', $f + [
            'project_id' => $projectId, 'ticket_id' => $ticketId, 'status' => 'backlog', 'reporter_id' => $reporterId,
            'position' => $pos, 'created_at' => now(),
        ]);
        self::ensureMember($projectId, $f['assignee_id']);
        Log::history('task', $id, null, 'backlog', $reporterId, $ticketId ? 'Создана из заявки техподдержки' : 'Задача создана');
        Log::act('task.create', 'task', $id, $f['title']);
        if ($f['assignee_id'] && $f['assignee_id'] !== $reporterId) {
            Notifier::send($f['assignee_id'], 'task_assigned', 'Вам назначена задача TS-' . $id, $f['title'] . ' · проект «' . $project['name'] . '»', ['task_id' => $id]);
        }
        return self::find($id);
    }

    public static function update(int $id, array $d): array
    {
        $t = self::find($id);
        $f = self::validate($d, true);
        if (!$f) {
            return $t;
        }
        $f['updated_at'] = now();
        Db::update('tasks', $f, 'id = ?', [$id]);
        if (isset($f['assignee_id'])) {
            self::ensureMember((int)$t['project_id'], $f['assignee_id']);
        }
        if (array_key_exists('due_date', $f) && $f['due_date'] !== $t['due_date']) {
            Db::update('tasks', ['due_notified' => 0], 'id = ?', [$id]);
        }
        if (isset($f['assignee_id']) && $f['assignee_id'] !== (int)$t['assignee_id'] && $f['assignee_id'] !== Auth::id()) {
            Notifier::send($f['assignee_id'], 'task_assigned', 'Вам назначена задача TS-' . $id, $t['title'], ['task_id' => $id]);
        }
        Log::act('task.update', 'task', $id);
        return self::find($id);
    }

    /** Смена статуса (перетаскивание карточки) + порядок в целевой колонке. */
    public static function move(int $id, string $to, ?array $order = null): array
    {
        $t = self::find($id);
        if (!isset(self::STATUSES[$to])) {
            throw HttpException::bad('Неизвестный статус');
        }
        $by = Auth::id();
        if ($to !== $t['status']) {
            $upd = ['status' => $to, 'updated_at' => now(), 'completed_at' => $to === 'done' ? now() : null];
            Db::update('tasks', $upd, 'id = ?', [$id]);
            Log::history('task', $id, $t['status'], $to, $by);
            Log::act('task.move', 'task', $id, $t['status'] . ' -> ' . $to);
            if ($to === 'done') {
                self::onDone($t);
            }
            if ($t['status'] === 'done' && $t['ticket_id'] === null) {
                // возврат из «Выполнена» — ничего дополнительного
            }
        }
        if ($order) {
            foreach (array_values($order) as $i => $tid) {
                Db::update('tasks', ['position' => $i + 1], 'id = ? AND project_id = ?', [(int)$tid, $t['project_id']]);
            }
        }
        return self::find($id);
    }

    private static function onDone(array $t): void
    {
        if (!$t['ticket_id']) {
            return;
        }
        $ticket = Tickets::find((int)$t['ticket_id']);
        Db::insert('ticket_comments', [
            'ticket_id' => $ticket['id'], 'author_id' => Auth::id() ?: (int)$t['reporter_id'], 'is_internal' => 1, 'created_at' => now(),
            'text' => 'Задача TS-' . $t['id'] . ' выполнена разработчиком. Проверьте результат и сообщите клиенту.',
        ]);
        $rec = array_filter([(int)$ticket['assignee_id']]);
        if (!$rec) {
            $rec = Notifier::usersWithRole('support');
        }
        Notifier::sendMany($rec, 'task_done', 'Эскалация выполнена: ' . $ticket['number'], 'Задача TS-' . $t['id'] . ' закрыта разработкой', ['ticket_id' => (int)$ticket['id'], 'task_id' => (int)$t['id']]);
    }

    public static function logTime(int $taskId, int $userId, string $date, float $hours, ?string $comment): int
    {
        if ($hours <= 0 || $hours > 24) {
            throw HttpException::bad('Часы: больше 0 и не более 24', ['hours' => 'Часы: больше 0 и не более 24']);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) {
            throw HttpException::bad('Дата не может быть в будущем', ['work_date' => 'Укажите корректную дату']);
        }
        $day = (float)Db::val('SELECT COALESCE(SUM(hours), 0) FROM time_logs WHERE user_id = ? AND work_date = ?', [$userId, $date]);
        if ($day + $hours > 24) {
            throw HttpException::bad('Суммарно за день не может быть больше 24 ч (уже списано ' . $day . ')', ['hours' => 'Превышение 24 ч за день']);
        }
        $id = Db::insert('time_logs', [
            'task_id' => $taskId, 'user_id' => $userId, 'work_date' => $date, 'hours' => $hours,
            'comment' => $comment ?: null, 'created_at' => now(),
        ]);
        Log::act('timelog.add', 'task', $taskId, $hours . ' ч');
        return $id;
    }

    /** Напоминания по срокам задач (cron). */
    public static function dueCheck(): array
    {
        $soon = $over = 0;
        $h = Settings::int('task_due_notify_hours', 24);
        $rows = Db::all("SELECT t.id, t.title, t.assignee_id, t.due_date, t.due_notified, p.owner_id FROM tasks t JOIN projects p ON p.id = t.project_id
                         WHERE t.status <> 'done' AND t.due_date IS NOT NULL AND t.assignee_id IS NOT NULL AND t.due_notified < 2");
        foreach ($rows as $t) {
            $dueEnd = strtotime($t['due_date'] . ' 23:59:59');
            if ($dueEnd < time() && (int)$t['due_notified'] < 2) {
                Notifier::sendMany([(int)$t['assignee_id'], (int)$t['owner_id']], 'task_overdue', 'Задача TS-' . $t['id'] . ' просрочена', $t['title'], ['task_id' => (int)$t['id']], false);
                Db::update('tasks', ['due_notified' => 2], 'id = ?', [$t['id']]);
                $over++;
            } elseif ($dueEnd - time() <= $h * 3600 && (int)$t['due_notified'] < 1) {
                Notifier::send((int)$t['assignee_id'], 'task_due', 'Срок задачи TS-' . $t['id'] . ' — ' . date('d.m', strtotime($t['due_date'])), $t['title'], ['task_id' => (int)$t['id']]);
                Db::update('tasks', ['due_notified' => 1], 'id = ?', [$t['id']]);
                $soon++;
            }
        }
        return ['due_soon' => $soon, 'overdue' => $over];
    }
}
