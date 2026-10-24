<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Log;
use App\Core\Notifier;
use App\Core\Settings;
use DateTimeImmutable;

final class Tickets
{
    public const NUM = "CONCAT('ТП-', LPAD(t.id, 5, '0'))";
    public const STATUSES = [
        'new' => 'Новая', 'accepted' => 'Принята в работу', 'in_progress' => 'В работе',
        'waiting_client' => 'Ожидание клиента', 'resolved' => 'Решена', 'closed' => 'Закрыта',
    ];
    private const TRANSITIONS = [
        'new' => ['accepted', 'in_progress', 'waiting_client', 'resolved'],
        'accepted' => ['in_progress', 'waiting_client', 'resolved'],
        'in_progress' => ['waiting_client', 'resolved'],
        'waiting_client' => ['in_progress', 'resolved'],
        'resolved' => ['closed', 'in_progress'],
        'closed' => ['in_progress'],
    ];
    public const OPEN = ['new', 'accepted', 'in_progress', 'waiting_client'];

    public static function baseSelect(): string
    {
        return "SELECT t.*, " . self::NUM . " AS number, pr.code AS priority_code, pr.name AS priority_name, pr.level AS priority_level,
                  pr.weight AS priority_weight, pr.calendar_time, pr.response_minutes, pr.resolve_hours,
                  cat.name AS category_name,
                  CONCAT(cl.last_name, ' ', cl.first_name) AS client_name, COALESCE(cl.company, CONCAT(cl.last_name, ' ', cl.first_name)) AS client_company,
                  CONCAT(asg.last_name, ' ', asg.first_name) AS assignee_name, p.name AS project_name,
                  (SELECT tk.id FROM tasks tk WHERE tk.ticket_id = t.id ORDER BY tk.id DESC LIMIT 1) AS task_id,
                  (SELECT tk.status FROM tasks tk WHERE tk.ticket_id = t.id ORDER BY tk.id DESC LIMIT 1) AS task_status
                FROM tickets t
                JOIN priorities pr ON pr.id = t.priority_id
                JOIN ticket_categories cat ON cat.id = t.category_id
                JOIN users cl ON cl.id = t.client_id
                LEFT JOIN users asg ON asg.id = t.assignee_id
                LEFT JOIN projects p ON p.id = t.project_id";
    }

    public static function isSupportStaff(): bool
    {
        return Auth::hasAnyRole(['support', 'manager', 'tech_director', 'admin']);
    }

    public static function find(int $id): array
    {
        $t = Db::one(self::baseSelect() . ' WHERE t.id = ?', [$id]);
        if (!$t) {
            throw HttpException::notFound('Заявка не найдена');
        }
        return $t;
    }

    /** Доступ: сотрудники ТП/руководство — ко всем, клиент — к своим, разработчик — к связанным с его задачами. */
    public static function assertAccess(array $t): void
    {
        $u = Auth::requireLogin();
        if (self::isSupportStaff() || (int)$t['client_id'] === $u['id']) {
            return;
        }
        if (Auth::hasRole('developer') && Db::val(
            'SELECT 1 FROM tasks tk LEFT JOIN project_members pm ON pm.project_id = tk.project_id AND pm.user_id = ?
             WHERE tk.ticket_id = ? AND (tk.assignee_id = ? OR pm.user_id IS NOT NULL) LIMIT 1', [$u['id'], $t['id'], $u['id']])) {
            return;
        }
        throw HttpException::forbidden('Нет доступа к заявке');
    }

    public static function slaFor(int $priorityId, DateTimeImmutable $from): array
    {
        $p = Db::one('SELECT * FROM priorities WHERE id = ?', [$priorityId]);
        $cal = (bool)$p['calendar_time'];
        return [
            Sla::deadline($from, (int)$p['response_minutes'], $cal)->format('Y-m-d H:i:s'),
            Sla::deadline($from, (int)$p['resolve_hours'] * 60, $cal)->format('Y-m-d H:i:s'),
        ];
    }

    /** Нагрузка специалиста L = Σ весов приоритетов открытых заявок. */
    public static function load(int $userId): int
    {
        return (int)Db::val(
            "SELECT COALESCE(SUM(pr.weight), 0) FROM tickets t JOIN priorities pr ON pr.id = t.priority_id
             WHERE t.assignee_id = ? AND t.status IN ('new','accepted','in_progress','waiting_client')", [$userId]);
    }

    /** @return array{0:?int,1:int} [user_id, L] специалист с минимальной нагрузкой */
    public static function pickAssignee(): array
    {
        $best = null;
        foreach (Notifier::usersWithRole('support') as $uid) {
            $l = self::load($uid);
            $cnt = (int)Db::val("SELECT COUNT(*) FROM tickets WHERE assignee_id = ? AND status IN ('new','accepted','in_progress','waiting_client')", [$uid]);
            $key = [$l, $cnt, $uid];
            if ($best === null || $key < $best[0]) {
                $best = [$key, $uid, $l];
            }
        }
        return $best ? [$best[1], $best[2]] : [null, 0];
    }

    public static function create(array $d): array
    {
        $u = Auth::requireLogin();
        $isStaff = self::isSupportStaff();
        $clientId = $isStaff && !empty($d['client_id']) ? (int)$d['client_id'] : $u['id'];
        if (!Db::val("SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE u.id = ? AND r.code = 'client'", [$clientId])) {
            throw HttpException::bad('Заявку можно создать только от имени клиента', ['client_id' => 'Выберите клиента']);
        }
        $prio = (int)($d['priority_id'] ?? 0) ?: (int)Db::val("SELECT id FROM priorities WHERE code = 'medium'");
        $prioRow = Db::one('SELECT * FROM priorities WHERE id = ?', [$prio]);
        if (!$prioRow) {
            throw HttpException::bad('Неизвестный приоритет');
        }
        if (!$isStaff && $prioRow['code'] === 'critical') {
            throw HttpException::bad('Критический приоритет назначает специалист поддержки', ['priority_id' => 'Выберите другой приоритет']);
        }
        $cat = (int)($d['category_id'] ?? 0);
        if (!Db::val('SELECT 1 FROM ticket_categories WHERE id = ? AND is_active = 1', [$cat])) {
            throw HttpException::bad('Выберите категорию', ['category_id' => 'Выберите категорию']);
        }
        $projectId = !empty($d['project_id']) ? (int)$d['project_id'] : null;
        if ($projectId && !Db::val('SELECT 1 FROM projects WHERE id = ? AND client_id = ?', [$projectId, $clientId])) {
            throw HttpException::bad('Проект не принадлежит клиенту');
        }
        $channel = in_array($d['channel'] ?? 'web', ['web', 'email', 'phone'], true) && $isStaff ? ($d['channel'] ?? 'web') : 'web';
        $subject = trim((string)($d['subject'] ?? ''));
        $descr = trim((string)($d['description'] ?? ''));
        if (mb_strlen($subject) < 5 || mb_strlen($subject) > 200) {
            throw HttpException::bad('Тема: от 5 до 200 символов', ['subject' => 'Тема: от 5 до 200 символов']);
        }
        if (mb_strlen($descr) < 10) {
            throw HttpException::bad('Опишите проблему подробнее (не менее 10 символов)', ['description' => 'Опишите проблему подробнее']);
        }
        $created = new DateTimeImmutable('now');
        [$respDue, $due] = self::slaFor($prio, $created);
        $assignee = null;
        $load = null;
        $auto = 0;
        if (Settings::int('auto_assign', 1)) {
            [$assignee, $load] = self::pickAssignee();
            $auto = $assignee ? 1 : 0;
        }
        $id = Db::tx(function () use ($clientId, $projectId, $cat, $prio, $assignee, $subject, $descr, $channel, $respDue, $due, $auto, $load, $u, $created) {
            $id = Db::insert('tickets', [
                'client_id' => $clientId, 'project_id' => $projectId, 'category_id' => $cat, 'priority_id' => $prio,
                'assignee_id' => $assignee, 'subject' => $subject, 'description' => $descr, 'channel' => $channel,
                'status' => 'new', 'first_response_due' => $respDue, 'due_at' => $due, 'auto_assigned' => $auto,
                'assigned_load' => $load, 'created_by' => $u['id'], 'created_at' => $created->format('Y-m-d H:i:s'),
            ]);
            Log::history('ticket', $id, null, 'new', $u['id'], 'Заявка создана (' . ['web' => 'веб-форма', 'email' => 'e-mail', 'phone' => 'телефон'][$channel] . '), рассчитаны сроки SLA');
            if ($assignee) {
                Log::history('ticket', $id, 'new', 'new', null, 'Назначена автоматически (нагрузка L = ' . $load . ')');
            }
            return $id;
        });
        Log::act('ticket.create', 'ticket', $id, $subject);
        $t = self::find($id);
        if ($assignee) {
            Notifier::send($assignee, 'ticket_assigned', 'Вам назначена заявка ' . $t['number'], $t['priority_name'] . ' · ' . $subject, ['ticket_id' => $id]);
        } else {
            Notifier::sendMany(Notifier::usersWithRole('support'), 'ticket_new', 'Новая заявка ' . $t['number'], $subject, ['ticket_id' => $id]);
        }
        return $t;
    }

    public static function setFirstResponse(array $t): void
    {
        if (!$t['first_response_at']) {
            Db::update('tickets', ['first_response_at' => now()], 'id = ?', [$t['id']]);
        }
    }

    public static function addComment(int $id, string $text, bool $internal): array
    {
        $t = self::find($id);
        self::assertAccess($t);
        $u = Auth::user();
        $staff = self::isSupportStaff() || (Auth::hasRole('developer') && (int)$t['client_id'] !== $u['id']);
        if ($internal && !$staff) {
            throw HttpException::forbidden('Внутренние заметки доступны только сотрудникам');
        }
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 5000) {
            throw HttpException::bad('Комментарий: от 1 до 5000 символов');
        }
        if ($t['status'] === 'closed' && !$staff) {
            throw HttpException::conflict('Заявка закрыта');
        }
        $cid = Db::insert('ticket_comments', [
            'ticket_id' => $id, 'author_id' => $u['id'], 'text' => $text, 'is_internal' => $internal ? 1 : 0, 'created_at' => now(),
        ]);
        Db::update('tickets', ['updated_at' => now()], 'id = ?', [$id]);

        if ($staff && !$internal) {
            self::setFirstResponse($t);
            if ($t['status'] === 'new') {
                self::transition($t, 'accepted', null, true);
            }
            Notifier::send((int)$t['client_id'], 'ticket_comment', 'Ответ по заявке ' . $t['number'], mb_substr($text, 0, 200), ['ticket_id' => $id]);
        } elseif (!$staff) {
            // клиент ответил: «Ожидание клиента» → «В работе»
            if ($t['status'] === 'waiting_client') {
                self::transition($t, 'in_progress', 'Клиент ответил', true);
            } elseif (in_array($t['status'], ['resolved', 'closed'], true)) {
                self::transition($t, 'in_progress', 'Клиент возобновил заявку', true);
            }
            if ($t['assignee_id']) {
                Notifier::send((int)$t['assignee_id'], 'ticket_comment', 'Комментарий клиента: ' . $t['number'], mb_substr($text, 0, 200), ['ticket_id' => $id]);
            }
        } elseif ($internal && $t['assignee_id'] && (int)$t['assignee_id'] !== $u['id']) {
            Notifier::send((int)$t['assignee_id'], 'ticket_comment', 'Внутренняя заметка: ' . $t['number'], mb_substr($text, 0, 200), ['ticket_id' => $id], false);
        }
        return Db::one('SELECT * FROM ticket_comments WHERE id = ?', [$cid]);
    }

    private static function transition(array $t, string $to, ?string $comment, bool $system = false): void
    {
        $upd = ['status' => $to, 'updated_at' => now()];
        if ($to === 'resolved') {
            $upd['resolved_at'] = now();
        }
        if ($to === 'closed') {
            $upd['closed_at'] = now();
            $upd['resolved_at'] = $t['resolved_at'] ?: now();
        }
        if ($to === 'in_progress' && in_array($t['status'], ['resolved', 'closed'], true)) {
            $upd['resolved_at'] = null;
            $upd['closed_at'] = null;
        }
        Db::update('tickets', $upd, 'id = ?', [$t['id']]);
        Log::history('ticket', (int)$t['id'], $t['status'], $to, $system && !Auth::id() ? null : Auth::id(), $comment);
    }

    public static function changeStatus(int $id, string $to, ?string $comment = null): array
    {
        $t = self::find($id);
        self::assertAccess($t);
        $u = Auth::user();
        if (!isset(self::STATUSES[$to])) {
            throw HttpException::bad('Неизвестный статус');
        }
        $staff = self::isSupportStaff();
        if (!$staff) {
            // клиент: подтвердить решение (закрыть) или вернуть в работу
            $ok = ((int)$t['client_id'] === $u['id']) && (
                ($t['status'] === 'resolved' && in_array($to, ['closed', 'in_progress'], true))
                || ($to === 'closed' && in_array($t['status'], self::OPEN, true)));
            if (!$ok) {
                throw HttpException::forbidden();
            }
        } elseif (!in_array($to, self::TRANSITIONS[$t['status']] ?? [], true)) {
            throw HttpException::conflict('Переход «' . self::STATUSES[$t['status']] . '» → «' . self::STATUSES[$to] . '» недопустим');
        }
        if ($staff && in_array($to, ['accepted', 'in_progress', 'resolved'], true)) {
            self::setFirstResponse($t);
            if (!$t['assignee_id'] && Auth::hasRole('support')) {
                Db::update('tickets', ['assignee_id' => $u['id']], 'id = ?', [$id]);
            }
        }
        self::transition($t, $to, $comment);
        Log::act('ticket.status', 'ticket', $id, $t['status'] . ' -> ' . $to);
        $label = self::STATUSES[$to];
        if ($staff) {
            Notifier::send((int)$t['client_id'], 'ticket_status', 'Заявка ' . $t['number'] . ': ' . $label, $t['subject'], ['ticket_id' => $id], in_array($to, ['resolved', 'waiting_client'], true));
        } elseif ($t['assignee_id']) {
            Notifier::send((int)$t['assignee_id'], 'ticket_status', 'Заявка ' . $t['number'] . ': ' . $label, 'Изменил клиент', ['ticket_id' => $id], false);
        }
        return self::find($id);
    }

    public static function assign(int $id, ?int $userId): array
    {
        $t = self::find($id);
        $u = Auth::requireLogin();
        if (!Auth::hasAnyRole(['support', 'manager', 'tech_director', 'admin'])) {
            throw HttpException::forbidden();
        }
        if ($userId === null || $userId === 0) {
            [$userId, $load] = self::pickAssignee();
            if (!$userId) {
                throw HttpException::conflict('Нет доступных специалистов поддержки');
            }
            $auto = 1;
        } else {
            if (!Db::val("SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.code IN ('support','manager')", [$userId])) {
                throw HttpException::bad('Исполнитель должен быть сотрудником поддержки');
            }
            $load = self::load($userId);
            $auto = 0;
        }
        Db::update('tickets', ['assignee_id' => $userId, 'auto_assigned' => $auto, 'assigned_load' => $load, 'updated_at' => now()], 'id = ?', [$id]);
        $name = Db::val("SELECT CONCAT(last_name, ' ', first_name) FROM users WHERE id = ?", [$userId]);
        Log::history('ticket', $id, $t['status'], $t['status'], $u['id'], ($auto ? 'Автоназначение' : 'Назначена') . ': ' . $name);
        Log::act('ticket.assign', 'ticket', $id, (string)$name);
        if ($userId !== $u['id']) {
            Notifier::send($userId, 'ticket_assigned', 'Вам назначена заявка ' . $t['number'], $t['subject'], ['ticket_id' => $id]);
        }
        return self::find($id);
    }

    public static function changePriority(int $id, int $priorityId): array
    {
        $t = self::find($id);
        if (!self::isSupportStaff()) {
            throw HttpException::forbidden();
        }
        if (!Db::val('SELECT 1 FROM priorities WHERE id = ?', [$priorityId])) {
            throw HttpException::bad('Неизвестный приоритет');
        }
        [$resp, $due] = self::slaFor($priorityId, new DateTimeImmutable($t['created_at']));
        Db::update('tickets', [
            'priority_id' => $priorityId, 'first_response_due' => $resp, 'due_at' => $due,
            'sla_warned' => 0, 'sla_breach_notified' => 0, 'updated_at' => now(),
        ], 'id = ?', [$id]);
        $new = Db::val('SELECT name FROM priorities WHERE id = ?', [$priorityId]);
        Log::history('ticket', $id, $t['status'], $t['status'], Auth::id(), 'Приоритет: ' . $t['priority_name'] . ' → ' . $new . ', сроки SLA пересчитаны');
        return self::find($id);
    }

    /** Эскалация в разработку: создаёт задачу-ошибку и уведомляет исполнителей. */
    public static function escalate(int $id, ?int $projectId, ?int $assigneeId, ?string $note): array
    {
        $t = self::find($id);
        $u = Auth::requireLogin();
        if (!Auth::hasAnyRole(['support', 'manager', 'admin', 'tech_director'])) {
            throw HttpException::forbidden();
        }
        if (in_array($t['status'], ['resolved', 'closed'], true)) {
            throw HttpException::conflict('Заявка уже решена');
        }
        $existing = Db::one("SELECT id FROM tasks WHERE ticket_id = ? AND status <> 'done'", [$id]);
        if ($existing) {
            throw HttpException::conflict('По этой заявке уже есть активная задача TS-' . $existing['id']);
        }
        $projectId = $projectId ?: ($t['project_id'] ? (int)$t['project_id'] : null);
        if (!$projectId) {
            $projectId = (int)Db::val("SELECT id FROM projects WHERE client_id = ? AND name = 'Сопровождение' ORDER BY id LIMIT 1", [$t['client_id']]);
        }
        if (!$projectId) {
            $owner = Notifier::usersWithRole('tech_director')[0] ?? Notifier::usersWithRole('admin')[0] ?? $u['id'];
            $projectId = Db::insert('projects', [
                'client_id' => $t['client_id'], 'owner_id' => $owner, 'name' => 'Сопровождение',
                'description' => 'Задачи, созданные из заявок технической поддержки', 'status' => 'support',
                'start_date' => date('Y-m-d'), 'created_at' => now(),
            ]);
            Log::history('project', $projectId, null, 'support', $u['id'], 'Создан автоматически при эскалации заявки');
        }
        $project = Db::one('SELECT * FROM projects WHERE id = ?', [$projectId]);
        if (!$project || (int)$project['client_id'] !== (int)$t['client_id']) {
            throw HttpException::bad('Проект не принадлежит клиенту заявки');
        }
        if (!$assigneeId) {
            $assigneeId = Tasks::pickDeveloper($projectId);
        }
        $due = (new DateTimeImmutable($t['due_at']))->format('Y-m-d');
        $taskId = Tasks::create([
            'project_id' => $projectId, 'ticket_id' => $id, 'title' => $t['number'] . ': ' . $t['subject'],
            'description' => $t['description'] . ($note ? "\n\nКомментарий поддержки: $note" : ''),
            'type' => 'bug', 'priority_id' => $t['priority_id'], 'assignee_id' => $assigneeId,
            'estimate_hours' => 0, 'due_date' => $due,
        ], $u['id'])['id'];
        Db::update('tickets', ['project_id' => $t['project_id'] ?: $projectId, 'updated_at' => now()], 'id = ?', [$id]);
        Db::insert('ticket_comments', [
            'ticket_id' => $id, 'author_id' => $u['id'], 'is_internal' => 1, 'created_at' => now(),
            'text' => 'Эскалация в разработку: создана задача TS-' . $taskId . ' в проекте «' . $project['name'] . '».' . ($note ? " $note" : ''),
        ]);
        if ($t['status'] === 'new') {
            self::setFirstResponse($t);
            self::transition($t, 'in_progress', 'Эскалация в разработку');
        } else {
            Log::history('ticket', $id, $t['status'], $t['status'], $u['id'], 'Эскалация в разработку: задача TS-' . $taskId);
        }
        Log::act('ticket.escalate', 'ticket', $id, 'TS-' . $taskId);
        $rec = array_filter(array_merge([$assigneeId], Notifier::usersWithRole('tech_director')));
        Notifier::sendMany($rec, 'ticket_escalated', 'Эскалация: ' . $t['number'], $t['subject'], ['ticket_id' => $id, 'task_id' => $taskId]);
        return Tasks::find($taskId);
    }

    /** Проверка SLA (cron): предупреждения, нарушения, автозакрытие решённых заявок. */
    public static function slaCheck(): array
    {
        $warn = $breach = $closed = 0;
        $rows = Db::all(self::baseSelect() . " WHERE t.status IN ('new','accepted','in_progress','waiting_client')");
        foreach ($rows as $t) {
            $s = Sla::state($t);
            if ($s['state'] === 'breached' && !$t['sla_breach_notified']) {
                $ids = array_filter(array_merge([(int)$t['assignee_id']], Notifier::usersWithRole('tech_director')));
                Notifier::sendMany($ids, 'sla_breach', 'Нарушен SLA: ' . $t['number'], $t['subject'], ['ticket_id' => (int)$t['id']]);
                Db::update('tickets', ['sla_breach_notified' => 1], 'id = ?', [$t['id']]);
                $breach++;
            } elseif ($s['state'] === 'warning' && !$t['sla_warned']) {
                if ($t['assignee_id']) {
                    Notifier::send((int)$t['assignee_id'], 'sla_warning', 'Срок SLA истекает: ' . $t['number'], $t['subject'], ['ticket_id' => (int)$t['id']]);
                }
                Db::update('tickets', ['sla_warned' => 1], 'id = ?', [$t['id']]);
                $warn++;
            }
        }
        foreach (Db::all("SELECT id FROM tickets WHERE status = 'resolved' AND resolved_at < DATE_SUB(?, INTERVAL 72 HOUR)", [now()]) as $r) {
            $t = self::find((int)$r['id']);
            self::transition($t, 'closed', 'Автоматически закрыта через 72 ч после решения', true);
            $closed++;
        }
        return ['warnings' => $warn, 'breaches' => $breach, 'auto_closed' => $closed];
    }
}
