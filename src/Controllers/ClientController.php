<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\Appointments;
use App\Services\Tickets;

final class ClientController
{
    /** Список клиентов для сотрудников. */
    public static function list(Request $r): array
    {
        $where = "ro.code = 'client' AND u.is_active = 1";
        $p = [];
        if ($q = $r->q('q')) {
            $where .= ' AND (u.last_name LIKE ? OR u.first_name LIKE ? OR u.company LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
            array_push($p, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%");
        }
        $limit = min(100, $r->qint('limit', 30));
        $page = max(1, $r->qint('page', 1));
        $total = (int)Db::val("SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles ro ON ro.id = ur.role_id WHERE $where", $p);
        $rows = Db::all("SELECT u.id, CONCAT(u.last_name, ' ', u.first_name) AS name, u.email, u.phone, u.company, u.created_at,
                           (SELECT COUNT(*) FROM appointments a WHERE a.client_id = u.id) AS appointments_count,
                           (SELECT COUNT(*) FROM tickets t WHERE t.client_id = u.id) AS tickets_count,
                           (SELECT COUNT(*) FROM tickets t WHERE t.client_id = u.id AND t.status IN ('new','accepted','in_progress','waiting_client')) AS open_tickets,
                           (SELECT COUNT(*) FROM projects pr WHERE pr.client_id = u.id) AS projects_count
                         FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles ro ON ro.id = ur.role_id
                         WHERE $where GROUP BY u.id ORDER BY u.company, u.last_name LIMIT $limit OFFSET " . (($page - 1) * $limit), $p);
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    public static function get(Request $r, array $p): array
    {
        $c = Db::one("SELECT u.id, u.last_name, u.first_name, u.middle_name, u.email, u.phone, u.company, u.created_at, u.last_login_at
                      FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles ro ON ro.id = ur.role_id AND ro.code = 'client' WHERE u.id = ?", [(int)$p['id']]);
        if (!$c) {
            throw HttpException::notFound('Клиент не найден');
        }
        $c['appointments'] = Db::all(Appointments::baseSelect() . ' WHERE a.client_id = ? ORDER BY a.start_at DESC LIMIT 20', [$c['id']]);
        $c['tickets'] = Db::all(Tickets::baseSelect() . ' WHERE t.client_id = ? ORDER BY t.created_at DESC LIMIT 20', [$c['id']]);
        $c['projects'] = Db::all('SELECT id, name, status, start_date, end_date FROM projects WHERE client_id = ? ORDER BY created_at DESC', [$c['id']]);
        return $c;
    }
}
