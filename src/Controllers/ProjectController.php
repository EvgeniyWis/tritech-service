<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Log;
use App\Core\Notifier;
use App\Core\Request;
use App\Services\Projects;
use App\Services\Tasks;

final class ProjectController
{
    public static function list(Request $r): array
    {
        $u = Auth::user();
        $where = ['1=1'];
        $p = [];
        // специалист поддержки видит проекты клиента заявки (для эскалации)
        $supportView = Auth::hasRole('support') && $r->qint('client_id');
        if (!Projects::isManagerial() && !$supportView) {
            $conds = [];
            if (Auth::hasRole('client')) {
                $conds[] = 'p.client_id = ?';
                $p[] = $u['id'];
            }
            if (Auth::hasRole('developer')) {
                $conds[] = 'EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = p.id AND pm.user_id = ?) OR p.owner_id = ?';
                array_push($p, $u['id'], $u['id']);
            }
            if (!$conds) {
                throw HttpException::forbidden();
            }
            $where[] = '(' . implode(' OR ', $conds) . ')';
        }
        if ($st = $r->q('status')) {
            $where[] = 'p.status = ?';
            $p[] = $st;
        }
        if ($cid = $r->qint('client_id')) {
            $where[] = 'p.client_id = ?';
            $p[] = $cid;
        }
        if ($q = $r->q('q')) {
            $where[] = '(p.name LIKE ? OR c.company LIKE ? OR c.last_name LIKE ?)';
            array_push($p, "%$q%", "%$q%", "%$q%");
        }
        $rows = Db::all("SELECT p.*, COALESCE(c.company, CONCAT(c.last_name, ' ', c.first_name)) AS client_name, CONCAT(o.last_name, ' ', o.first_name) AS owner_name,
                           (SELECT COUNT(*) FROM project_members pm WHERE pm.project_id = p.id) AS members_count
                         FROM projects p JOIN users c ON c.id = p.client_id JOIN users o ON o.id = p.owner_id
                         WHERE " . implode(' AND ', $where) . ' ORDER BY (p.status = "closed"), p.created_at DESC', $p);
        foreach ($rows as &$row) {
            $row['progress'] = Projects::progress((int)$row['id']);
        }
        return $rows;
    }

    public static function get(Request $r, array $p): array
    {
        $pr = Projects::find((int)$p['id']);
        Projects::assertRead($pr);
        $pr['progress'] = Projects::progress((int)$pr['id']);
        $pr['members'] = Projects::members((int)$pr['id']);
        $pr['can_work'] = Projects::canWork($pr);
        $pr['can_manage'] = Projects::isManagerial() || (int)$pr['owner_id'] === Auth::id();
        $pr['history'] = Db::all("SELECT h.changed_at, h.new_status, h.comment, CONCAT(u.last_name, ' ', LEFT(u.first_name, 1), '.') AS user_name
                                  FROM entity_status_history h LEFT JOIN users u ON u.id = h.changed_by
                                  WHERE h.entity_type = 'project' AND h.entity_id = ? ORDER BY h.id DESC", [$pr['id']]);
        return $pr;
    }

    public static function create(Request $r): array
    {
        $name = (string)$r->str('name', '');
        if (mb_strlen($name) < 3 || mb_strlen($name) > 200) {
            throw HttpException::bad('Название: от 3 до 200 символов', ['name' => 'Название: от 3 до 200 символов']);
        }
        $appId = $r->int('appointment_id');
        $clientId = $r->int('client_id');
        if ($appId) {
            $a = Db::one('SELECT client_id FROM appointments WHERE id = ?', [$appId]);
            if (!$a) {
                throw HttpException::bad('Консультация не найдена');
            }
            if (Db::val('SELECT 1 FROM projects WHERE appointment_id = ?', [$appId])) {
                throw HttpException::conflict('Проект по этой консультации уже создан');
            }
            $clientId = (int)$a['client_id'];
        }
        if (!$clientId || !Db::val("SELECT 1 FROM user_roles ur JOIN roles ro ON ro.id = ur.role_id WHERE ur.user_id = ? AND ro.code = 'client'", [$clientId])) {
            throw HttpException::bad('Выберите заказчика', ['client_id' => 'Выберите заказчика']);
        }
        $start = (string)$r->str('start_date', date('Y-m-d'));
        $end = $r->str('end_date') ?: null;
        if ($end && $end < $start) {
            throw HttpException::bad('Дата окончания раньше даты начала', ['end_date' => 'Раньше даты начала']);
        }
        $owner = $r->int('owner_id') ?: Auth::id();
        $id = Db::tx(function () use ($clientId, $appId, $owner, $name, $r, $start, $end) {
            $id = Db::insert('projects', [
                'client_id' => $clientId, 'appointment_id' => $appId, 'owner_id' => $owner, 'name' => $name,
                'description' => $r->str('description') ?: null, 'status' => 'planned', 'start_date' => $start, 'end_date' => $end, 'created_at' => now(),
            ]);
            \App\Core\Log::history('project', $id, null, 'planned', Auth::id(), $appId ? 'Создан по итогам консультации' : 'Проект создан');
            if (Db::val("SELECT 1 FROM user_roles ur JOIN roles ro ON ro.id = ur.role_id WHERE ur.user_id = ? AND ro.code = 'developer'", [$owner])) {
                Db::insert('project_members', ['project_id' => $id, 'user_id' => $owner, 'role_in_project' => 'lead', 'allocation_percent' => 50, 'joined_at' => date('Y-m-d')]);
            }
            return $id;
        });
        Log::act('project.create', 'project', $id, $name);
        Notifier::send($clientId, 'project_created', 'Создан проект «' . $name . '»', 'Следить за ходом работ можно в разделе «Мои проекты».', [], true);
        return self::get($r, ['id' => (string)$id]);
    }

    private static function assertManage(array $pr): void
    {
        if (!Projects::isManagerial() && (int)$pr['owner_id'] !== Auth::id()) {
            throw HttpException::forbidden('Управлять проектом может руководитель, менеджер или технический директор');
        }
    }

    public static function update(Request $r, array $p): array
    {
        $pr = Projects::find((int)$p['id']);
        self::assertManage($pr);
        $upd = [];
        if ($r->str('name') !== null) {
            $n = $r->str('name');
            if (mb_strlen($n) < 3 || mb_strlen($n) > 200) {
                throw HttpException::bad('Название: от 3 до 200 символов');
            }
            $upd['name'] = $n;
        }
        foreach (['description', 'start_date', 'end_date'] as $f) {
            if (array_key_exists($f, $r->body)) {
                $upd[$f] = $r->str($f) ?: null;
            }
        }
        if ($r->int('owner_id')) {
            $upd['owner_id'] = $r->int('owner_id');
        }
        $start = $upd['start_date'] ?? $pr['start_date'];
        $end = array_key_exists('end_date', $upd) ? $upd['end_date'] : $pr['end_date'];
        if ($end && $end < $start) {
            throw HttpException::bad('Дата окончания раньше даты начала');
        }
        $upd['updated_at'] = now();
        Db::update('projects', $upd, 'id = ?', [$pr['id']]);
        if (($st = $r->str('status')) && $st !== $pr['status']) {
            if (!isset(Projects::STATUSES[$st])) {
                throw HttpException::bad('Неизвестный статус проекта');
            }
            Db::update('projects', ['status' => $st], 'id = ?', [$pr['id']]);
            Log::history('project', (int)$pr['id'], $pr['status'], $st, Auth::id());
            Notifier::send((int)$pr['client_id'], 'project_status', 'Проект «' . $pr['name'] . '»: ' . Projects::STATUSES[$st], null, [], $st === 'delivered');
        }
        Log::act('project.update', 'project', (int)$pr['id']);
        return self::get($r, $p);
    }

    public static function addMember(Request $r, array $p): array
    {
        $pr = Projects::find((int)$p['id']);
        self::assertManage($pr);
        $uid = (int)$r->int('user_id', 0);
        if (!Db::val("SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles ro ON ro.id = ur.role_id WHERE u.id = ? AND u.is_active = 1 AND ro.code IN ('developer','tech_director')", [$uid])) {
            throw HttpException::bad('Участником может быть только разработчик');
        }
        $role = $r->str('role_in_project', 'developer');
        if (!in_array($role, ['lead', 'developer', 'tester', 'analyst'], true)) {
            throw HttpException::bad('Недопустимая роль в проекте');
        }
        $alloc = max(0, min(100, (int)$r->int('allocation_percent', 100)));
        Db::exec('DELETE FROM project_members WHERE project_id = ? AND user_id = ?', [$pr['id'], $uid]);
        Db::insert('project_members', ['project_id' => $pr['id'], 'user_id' => $uid, 'role_in_project' => $role, 'allocation_percent' => $alloc, 'joined_at' => date('Y-m-d')]);
        Notifier::send($uid, 'project_member', 'Вы добавлены в проект «' . $pr['name'] . '»', 'Роль: ' . $role, [], false);
        Log::act('project.member.add', 'project', (int)$pr['id'], "user=$uid");
        return Projects::members((int)$pr['id']);
    }

    public static function removeMember(Request $r, array $p): array
    {
        $pr = Projects::find((int)$p['id']);
        self::assertManage($pr);
        $uid = (int)$p['uid'];
        if (Db::val("SELECT 1 FROM tasks WHERE project_id = ? AND assignee_id = ? AND status <> 'done'", [$pr['id'], $uid])) {
            throw HttpException::conflict('У участника есть незавершённые задачи — сначала переназначьте их');
        }
        Db::exec('DELETE FROM project_members WHERE project_id = ? AND user_id = ?', [$pr['id'], $uid]);
        Log::act('project.member.remove', 'project', (int)$pr['id'], "user=$uid");
        return Projects::members((int)$pr['id']);
    }

    /** Доска проекта: задачи по колонкам. */
    public static function board(Request $r, array $p): array
    {
        $pr = Projects::find((int)$p['id']);
        Projects::assertRead($pr);
        $where = 't.project_id = ?';
        $args = [$pr['id']];
        if ($a = $r->qint('assignee_id')) {
            $where .= ' AND t.assignee_id = ?';
            $args[] = $a;
        }
        if ($ty = $r->q('type')) {
            $where .= ' AND t.type = ?';
            $args[] = $ty;
        }
        $tasks = Db::all(Tasks::baseSelect() . " WHERE $where ORDER BY t.position, t.id", $args);
        $cols = [];
        foreach (Tasks::STATUSES as $code => $name) {
            $items = array_values(array_filter($tasks, fn($t) => $t['status'] === $code));
            $cols[] = ['status' => $code, 'name' => $name, 'count' => count($items), 'hours' => array_sum(array_column($items, 'estimate_hours')), 'tasks' => $items];
        }
        return ['project' => self::get($r, $p), 'columns' => $cols];
    }

    /** Список разработчиков для назначения. */
    public static function developers(Request $r): array
    {
        return Db::all("SELECT u.id, CONCAT(u.last_name, ' ', u.first_name) AS name, CONCAT(LEFT(u.first_name, 1), LEFT(u.last_name, 1)) AS initials
                        FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles ro ON ro.id = ur.role_id AND ro.code = 'developer'
                        WHERE u.is_active = 1 GROUP BY u.id ORDER BY u.last_name");
    }
}
