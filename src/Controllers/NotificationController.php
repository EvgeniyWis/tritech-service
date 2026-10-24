<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;

final class NotificationController
{
    public static function list(Request $r): array
    {
        $uid = Auth::id();
        $where = 'user_id = ?';
        $p = [$uid];
        if ($r->q('unread')) {
            $where .= ' AND is_read = 0';
        }
        $limit = min(100, $r->qint('limit', 30));
        $items = Db::all("SELECT id, type, title, body, appointment_id, ticket_id, task_id, is_read, created_at FROM notifications WHERE $where ORDER BY id DESC LIMIT $limit", $p);
        return ['items' => $items, 'unread' => (int)Db::val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$uid])];
    }

    public static function read(Request $r, array $p): array
    {
        Db::exec('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?', [(int)$p['id'], Auth::id()]);
        return ['ok' => true];
    }

    public static function readAll(Request $r): array
    {
        Db::exec('UPDATE notifications SET is_read = 1 WHERE user_id = ?', [Auth::id()]);
        return ['ok' => true];
    }
}
