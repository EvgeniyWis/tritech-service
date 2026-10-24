<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Log;
use App\Core\Request;
use App\Services\Sla;
use App\Services\Tickets;

final class TicketController
{
    private const OPEN_SQL = "t.status IN ('new','accepted','in_progress','waiting_client')";

    /** Условия видимости заявок для текущего пользователя. */
    private static function scope(): array
    {
        if (Tickets::isSupportStaff()) {
            return ['1=1', []];
        }
        $u = Auth::user();
        $conds = [];
        $p = [];
        if (Auth::hasRole('client')) {
            $conds[] = 't.client_id = ?';
            $p[] = $u['id'];
        }
        if (Auth::hasRole('developer')) {
            $conds[] = 'EXISTS (SELECT 1 FROM tasks tk WHERE tk.ticket_id = t.id AND (tk.assignee_id = ? OR EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = tk.project_id AND pm.user_id = ?)))';
            array_push($p, $u['id'], $u['id']);
        }
        if (!$conds) {
            throw HttpException::forbidden();
        }
        return ['(' . implode(' OR ', $conds) . ')', $p];
    }

    public static function list(Request $r): array
    {
        [$scope, $sp] = self::scope();
        $where = [$scope];
        $p = $sp;
        $u = Auth::user();

        $filters = [];
        if ($v = $r->qint('priority_id')) { $filters[] = ['t.priority_id = ?', $v]; }
        if ($v = $r->qint('category_id')) { $filters[] = ['t.category_id = ?', $v]; }
        if ($v = $r->q('channel')) { $filters[] = ['t.channel = ?', $v]; }
        if ($v = $r->qint('assignee_id')) { $filters[] = ['t.assignee_id = ?', $v]; }
        if ($v = $r->qint('client_id')) { $filters[] = ['t.client_id = ?', $v]; }
        if ($v = $r->q('period')) { // YYYY-MM
            if (preg_match('/^\d{4}-\d{2}$/', $v)) { $filters[] = ["DATE_FORMAT(t.created_at, '%Y-%m') = ?", $v]; }
        }
        foreach ($filters as [$c, $v]) {
            $where[] = $c;
            $p[] = $v;
        }
        if ($q = $r->q('q')) {
            $where[] = "(t.subject LIKE ? OR " . Tickets::NUM . " LIKE ? OR cl.company LIKE ? OR cl.last_name LIKE ?)";
            array_push($p, "%$q%", "%$q%", "%$q%", "%$q%");
        }

        // вкладки
        $tab = $r->q('tab', 'all');
        $tabSql = match ($tab) {
            'mine' => ['t.assignee_id = ? AND ' . self::OPEN_SQL, [$u['id']]],
            'new' => ["t.status = 'new'", []],
            'breached' => [self::OPEN_SQL . ' AND t.due_at < NOW()', []],
            'waiting' => ["t.status = 'waiting_client'", []],
            'escalated' => ['EXISTS (SELECT 1 FROM tasks tk WHERE tk.ticket_id = t.id)', []],
            'open' => [self::OPEN_SQL, []],
            'resolved' => ["t.status IN ('resolved','closed')", []],
            default => ['1=1', []],
        };
        $st = $r->q('status');
        $base = Tickets::baseSelect();
        $whereBase = implode(' AND ', $where);

        // счётчики вкладок считаются без учёта вкладки
        $counts = Db::one("SELECT COUNT(*) AS `all`,
                    SUM(t.assignee_id = ? AND " . self::OPEN_SQL . ") AS mine,
                    SUM(t.status = 'new') AS new_,
                    SUM(" . self::OPEN_SQL . " AND t.due_at < NOW()) AS breached,
                    SUM(t.status = 'waiting_client') AS waiting,
                    SUM(EXISTS (SELECT 1 FROM tasks tk WHERE tk.ticket_id = t.id)) AS escalated
                  FROM tickets t JOIN users cl ON cl.id = t.client_id WHERE $whereBase", array_merge([$u['id']], $p));

        $where2 = $whereBase . ' AND ' . $tabSql[0];
        $p2 = array_merge($p, $tabSql[1]);
        if ($st) {
            $where2 .= ' AND t.status = ?';
            $p2[] = $st;
        }
        $limit = min(100, $r->qint('limit', 20));
        $page = max(1, $r->qint('page', 1));
        $total = (int)Db::val("SELECT COUNT(*) FROM tickets t JOIN users cl ON cl.id = t.client_id WHERE $where2", $p2);
        $rows = Db::all($base . " WHERE $where2 ORDER BY (" . self::OPEN_SQL . ") DESC,
                          CASE WHEN " . self::OPEN_SQL . " THEN t.due_at END ASC, t.created_at DESC
                          LIMIT $limit OFFSET " . (($page - 1) * $limit), $p2);
        foreach ($rows as &$t) {
            $t['sla'] = Sla::state($t);
            unset($t['description']);
        }
        return [
            'items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit,
            'counts' => [
                'all' => (int)$counts['all'], 'mine' => (int)$counts['mine'], 'new' => (int)$counts['new_'],
                'breached' => (int)$counts['breached'], 'waiting' => (int)$counts['waiting'], 'escalated' => (int)$counts['escalated'],
            ],
        ];
    }

    public static function get(Request $r, array $p): array
    {
        $t = Tickets::find((int)$p['id']);
        Tickets::assertAccess($t);
        $u = Auth::user();
        $staffView = Tickets::isSupportStaff() || (Auth::hasRole('developer') && (int)$t['client_id'] !== $u['id']);
        $t['sla'] = Sla::state($t);
        $t['comments'] = Db::all("SELECT c.id, c.text, c.is_internal, c.created_at, c.author_id,
                                    CONCAT(a.last_name, ' ', a.first_name) AS author_name, CONCAT(LEFT(a.first_name, 1), LEFT(a.last_name, 1)) AS author_initials,
                                    (a.id = ?) AS is_client_author,
                                    (SELECT GROUP_CONCAT(r.code) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = a.id) AS author_roles
                                  FROM ticket_comments c JOIN users a ON a.id = c.author_id
                                  WHERE c.ticket_id = ? " . ($staffView ? '' : 'AND c.is_internal = 0') . ' ORDER BY c.created_at, c.id', [$t['client_id'], $t['id']]);
        $t['attachments'] = Db::all('SELECT id, file_name, file_size, created_at FROM attachments WHERE ticket_id = ? ORDER BY id', [$t['id']]);
        $t['history'] = Db::all("SELECT h.changed_at, h.old_status, h.new_status, h.comment, CONCAT(u.last_name, ' ', LEFT(u.first_name, 1), '.') AS user_name
                                 FROM entity_status_history h LEFT JOIN users u ON u.id = h.changed_by
                                 WHERE h.entity_type = 'ticket' AND h.entity_id = ? ORDER BY h.changed_at DESC, h.id DESC", [$t['id']]);
        $t['task'] = Db::one("SELECT id, CONCAT('TS-', id) AS number, status, title FROM tasks WHERE ticket_id = ? ORDER BY id DESC LIMIT 1", [$t['id']]);
        $t['client'] = Db::one('SELECT id, email, phone, company FROM users WHERE id = ?', [$t['client_id']]);
        $t['assignee_load'] = $t['assignee_id'] ? Tickets::load((int)$t['assignee_id']) : null;
        $t['staff_view'] = $staffView;
        $t['can_manage'] = Tickets::isSupportStaff();
        $t['transitions'] = Tickets::isSupportStaff() ? self::allowed($t['status']) : [];
        $t['client_actions'] = (!Tickets::isSupportStaff() && (int)$t['client_id'] === $u['id'])
            ? ($t['status'] === 'resolved' ? ['closed', 'in_progress'] : (in_array($t['status'], Tickets::OPEN, true) ? ['closed'] : [])) : [];
        return $t;
    }

    private static function allowed(string $from): array
    {
        $m = [
            'new' => ['accepted', 'in_progress', 'waiting_client', 'resolved'], 'accepted' => ['in_progress', 'waiting_client', 'resolved'],
            'in_progress' => ['waiting_client', 'resolved'], 'waiting_client' => ['in_progress', 'resolved'],
            'resolved' => ['closed', 'in_progress'], 'closed' => ['in_progress'],
        ];
        return $m[$from] ?? [];
    }

    public static function create(Request $r): array
    {
        $t = Tickets::create($r->body);
        // вложения можно загрузить следующим запросом по id заявки
        return $t;
    }

    public static function comment(Request $r, array $p): array
    {
        return Tickets::addComment((int)$p['id'], (string)$r->str('text', ''), (bool)$r->input('is_internal'));
    }

    public static function status(Request $r, array $p): array
    {
        return Tickets::changeStatus((int)$p['id'], (string)$r->str('status', ''), $r->str('comment') ?: null);
    }

    public static function assign(Request $r, array $p): array
    {
        return Tickets::assign((int)$p['id'], $r->int('assignee_id'));
    }

    public static function priority(Request $r, array $p): array
    {
        return Tickets::changePriority((int)$p['id'], (int)$r->int('priority_id', 0));
    }

    public static function escalate(Request $r, array $p): array
    {
        return Tickets::escalate((int)$p['id'], $r->int('project_id'), $r->int('assignee_id'), $r->str('note') ?: null);
    }

    /** Справочники для форм заявки. */
    public static function meta(Request $r): array
    {
        $staff = Tickets::isSupportStaff();
        $u = Auth::user();
        return [
            'categories' => Db::all('SELECT id, name FROM ticket_categories WHERE is_active = 1 ORDER BY id'),
            'priorities' => Db::all('SELECT id, code, name, level, weight, response_minutes, resolve_hours, calendar_time FROM priorities ORDER BY level'),
            'specialists' => $staff ? Db::all("SELECT u.id, CONCAT(u.last_name, ' ', u.first_name) AS name FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id AND r.code IN ('support') WHERE u.is_active = 1 GROUP BY u.id ORDER BY u.last_name") : [],
            'projects' => Auth::hasRole('client') ? Db::all('SELECT id, name FROM projects WHERE client_id = ? ORDER BY name', [$u['id']]) : [],
            'statuses' => Tickets::STATUSES,
        ];
    }

    // ---------- Шаблоны ответов ----------

    public static function templates(Request $r): array
    {
        $sql = 'SELECT t.id, t.category_id, t.title, t.body, t.is_active, c.name AS category_name FROM response_templates t LEFT JOIN ticket_categories c ON c.id = t.category_id';
        $p = [];
        if (!Auth::hasAnyRole(['manager', 'admin', 'tech_director']) || !$r->q('all')) {
            $sql .= ' WHERE t.is_active = 1';
        }
        if ($cid = $r->qint('category_id')) {
            $sql .= (strpos($sql, 'WHERE') ? ' AND' : ' WHERE') . ' (t.category_id = ? OR t.category_id IS NULL)';
            $p[] = $cid;
        }
        return Db::all($sql . ' ORDER BY t.title', $p);
    }

    public static function saveTemplate(Request $r, array $p): array
    {
        $title = (string)$r->str('title', '');
        $body = (string)$r->str('body', '');
        if (mb_strlen($title) < 3 || mb_strlen($title) > 150 || mb_strlen($body) < 5) {
            throw HttpException::bad('Укажите название (3–150 символов) и текст шаблона');
        }
        $data = [
            'title' => $title, 'body' => $body, 'category_id' => $r->int('category_id') ?: null,
            'is_active' => $r->input('is_active', 1) ? 1 : 0,
        ];
        if (isset($p['id'])) {
            Db::update('response_templates', $data, 'id = ?', [(int)$p['id']]);
            $id = (int)$p['id'];
        } else {
            $id = Db::insert('response_templates', $data + ['created_by' => Auth::id(), 'created_at' => now()]);
        }
        Log::act('template.save', 'template', $id);
        return Db::one('SELECT * FROM response_templates WHERE id = ?', [$id]);
    }

    public static function deleteTemplate(Request $r, array $p): array
    {
        Db::exec('DELETE FROM response_templates WHERE id = ?', [(int)$p['id']]);
        Log::act('template.delete', 'template', (int)$p['id']);
        return ['ok' => true];
    }
}
