<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Log;
use App\Core\Request;
use App\Services\Projects;
use App\Services\Tasks;

final class TaskController
{
    private static function projectOf(array $task): array
    {
        return Projects::find((int)$task['project_id']);
    }

    public static function list(Request $r): array
    {
        $u = Auth::user();
        $where = ['1=1'];
        $p = [];
        if (!Projects::isManagerial()) {
            if (!Auth::hasRole('developer')) {
                throw HttpException::forbidden();
            }
            $where[] = '(t.assignee_id = ? OR EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = t.project_id AND pm.user_id = ?))';
            array_push($p, $u['id'], $u['id']);
        }
        if ($v = $r->qint('project_id')) { $where[] = 't.project_id = ?'; $p[] = $v; }
        if ($r->q('assignee_id') === 'me') { $where[] = 't.assignee_id = ?'; $p[] = $u['id']; }
        elseif ($v = $r->qint('assignee_id')) { $where[] = 't.assignee_id = ?'; $p[] = $v; }
        if ($v = $r->q('status')) { $where[] = 't.status = ?'; $p[] = $v; }
        if ($v = $r->q('type')) { $where[] = 't.type = ?'; $p[] = $v; }
        if ($r->q('overdue')) { $where[] = "t.status <> 'done' AND t.due_date < CURDATE()"; }
        if ($r->q('from_ticket')) { $where[] = 't.ticket_id IS NOT NULL'; }
        if ($q = $r->q('q')) {
            $where[] = "(t.title LIKE ? OR CONCAT('TS-', t.id) LIKE ?)";
            array_push($p, "%$q%", "%$q%");
        }
        $limit = min(200, $r->qint('limit', 50));
        $page = max(1, $r->qint('page', 1));
        $sql = Tasks::baseSelect() . ' WHERE ' . implode(' AND ', $where);
        $total = (int)Db::val('SELECT COUNT(*) FROM (' . $sql . ') x', $p);
        $rows = Db::all($sql . " ORDER BY (t.status = 'done'), t.due_date IS NULL, t.due_date, t.id DESC LIMIT $limit OFFSET " . (($page - 1) * $limit), $p);
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    public static function get(Request $r, array $p): array
    {
        $t = Tasks::find((int)$p['id']);
        $pr = self::projectOf($t);
        Projects::assertRead($pr);
        $internal = Projects::canWork($pr);
        $t['can_work'] = $internal;
        $t['comments'] = $internal ? Db::all("SELECT c.id, c.text, c.created_at, CONCAT(u.last_name, ' ', u.first_name) AS author_name, CONCAT(LEFT(u.first_name,1), LEFT(u.last_name,1)) AS author_initials
                                              FROM task_comments c JOIN users u ON u.id = c.author_id WHERE c.task_id = ? ORDER BY c.id", [$t['id']]) : [];
        $t['timelogs'] = $internal ? Db::all("SELECT l.id, l.work_date, l.hours, l.comment, l.user_id, CONCAT(u.last_name, ' ', u.first_name) AS user_name
                                              FROM time_logs l JOIN users u ON u.id = l.user_id WHERE l.task_id = ? ORDER BY l.work_date DESC, l.id DESC", [$t['id']]) : [];
        $t['attachments'] = $internal ? Db::all('SELECT id, file_name, file_size, created_at FROM attachments WHERE task_id = ? ORDER BY id', [$t['id']]) : [];
        $t['history'] = Db::all("SELECT h.changed_at, h.old_status, h.new_status, h.comment, CONCAT(u.last_name, ' ', LEFT(u.first_name, 1), '.') AS user_name
                                 FROM entity_status_history h LEFT JOIN users u ON u.id = h.changed_by
                                 WHERE h.entity_type = 'task' AND h.entity_id = ? ORDER BY h.id DESC", [$t['id']]);
        return $t;
    }

    public static function create(Request $r): array
    {
        $pr = Projects::find((int)$r->int('project_id', 0));
        Projects::assertWork($pr);
        return Tasks::create($r->body, Auth::id());
    }

    public static function update(Request $r, array $p): array
    {
        $t = Tasks::find((int)$p['id']);
        Projects::assertWork(self::projectOf($t));
        $d = $r->body;
        unset($d['project_id'], $d['ticket_id'], $d['status']);
        return Tasks::update((int)$t['id'], $d);
    }

    public static function move(Request $r, array $p): array
    {
        $t = Tasks::find((int)$p['id']);
        Projects::assertWork(self::projectOf($t));
        $order = $r->input('order');
        return Tasks::move((int)$t['id'], (string)$r->str('status', ''), is_array($order) ? $order : null);
    }

    public static function comment(Request $r, array $p): array
    {
        $t = Tasks::find((int)$p['id']);
        Projects::assertWork(self::projectOf($t));
        $text = (string)$r->str('text', '');
        if ($text === '' || mb_strlen($text) > 5000) {
            throw HttpException::bad('Комментарий: от 1 до 5000 символов');
        }
        $id = Db::insert('task_comments', ['task_id' => $t['id'], 'author_id' => Auth::id(), 'text' => $text, 'created_at' => now()]);
        if ($t['assignee_id'] && (int)$t['assignee_id'] !== Auth::id()) {
            \App\Core\Notifier::send((int)$t['assignee_id'], 'task_comment', 'Комментарий к задаче TS-' . $t['id'], mb_substr($text, 0, 200), ['task_id' => (int)$t['id']], false);
        }
        return Db::one('SELECT * FROM task_comments WHERE id = ?', [$id]);
    }

    public static function logTime(Request $r, array $p): array
    {
        $t = Tasks::find((int)$p['id']);
        Projects::assertWork(self::projectOf($t));
        $id = Tasks::logTime((int)$t['id'], Auth::id(), (string)$r->str('work_date', date('Y-m-d')), (float)$r->input('hours', 0), $r->str('comment'));
        return Db::one('SELECT * FROM time_logs WHERE id = ?', [$id]);
    }

    public static function deleteTimeLog(Request $r, array $p): array
    {
        $l = Db::one('SELECT * FROM time_logs WHERE id = ?', [(int)$p['id']]);
        if (!$l) {
            throw HttpException::notFound('Запись не найдена');
        }
        if ((int)$l['user_id'] !== Auth::id() && !Projects::isManagerial()) {
            throw HttpException::forbidden();
        }
        if (strtotime($l['work_date']) < strtotime('-14 days') && !Projects::isManagerial()) {
            throw HttpException::conflict('Запись старше 14 дней — удаление недоступно');
        }
        Db::exec('DELETE FROM time_logs WHERE id = ?', [$l['id']]);
        Log::act('timelog.delete', 'task', (int)$l['task_id']);
        return ['ok' => true];
    }

    /** Трудозатраты: по пользователю/проекту/периоду. */
    public static function timelogs(Request $r): array
    {
        $u = Auth::user();
        $where = ['1=1'];
        $p = [];
        if (Projects::isManagerial()) {
            if ($v = $r->qint('user_id')) { $where[] = 'l.user_id = ?'; $p[] = $v; }
        } elseif (Auth::hasRole('developer')) {
            $where[] = 'l.user_id = ?';
            $p[] = $u['id'];
        } else {
            throw HttpException::forbidden();
        }
        if ($v = $r->qint('project_id')) { $where[] = 't.project_id = ?'; $p[] = $v; }
        $from = $r->q('from', date('Y-m-d', strtotime('monday this week')));
        $to = $r->q('to', date('Y-m-d'));
        $where[] = 'l.work_date BETWEEN ? AND ?';
        array_push($p, $from, $to);
        $rows = Db::all("SELECT l.id, l.work_date, l.hours, l.comment, l.task_id, CONCAT('TS-', t.id) AS task_number, t.title AS task_title,
                           pr.name AS project_name, pr.id AS project_id, CONCAT(u.last_name, ' ', u.first_name) AS user_name
                         FROM time_logs l JOIN tasks t ON t.id = l.task_id JOIN projects pr ON pr.id = t.project_id JOIN users u ON u.id = l.user_id
                         WHERE " . implode(' AND ', $where) . ' ORDER BY l.work_date DESC, l.id DESC LIMIT 1000', $p);
        $byDay = [];
        foreach ($rows as $row) {
            $byDay[$row['work_date']] = ($byDay[$row['work_date']] ?? 0) + (float)$row['hours'];
        }
        return ['items' => $rows, 'total_hours' => array_sum(array_column($rows, 'hours')), 'by_day' => $byDay, 'from' => $from, 'to' => $to];
    }
}
