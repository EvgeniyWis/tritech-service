<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\Appointments;
use App\Services\Slots;

final class CatalogController
{
    public static function specializations(Request $r): array
    {
        return Db::all('SELECT s.id, s.name, s.description,
                          (SELECT COUNT(*) FROM services sv WHERE sv.specialization_id = s.id AND sv.is_active = 1) AS services_count,
                          (SELECT COUNT(*) FROM consultant_specializations cs JOIN consultants c ON c.id = cs.consultant_id AND c.is_active = 1 WHERE cs.specialization_id = s.id) AS consultants_count
                        FROM specializations s WHERE s.is_active = 1 ORDER BY s.name');
    }

    public static function services(Request $r): array
    {
        $sql = 'SELECT sv.id, sv.specialization_id, sv.name, sv.description, sv.duration_min, sv.price, sp.name AS specialization_name
                FROM services sv JOIN specializations sp ON sp.id = sv.specialization_id WHERE sv.is_active = 1';
        $p = [];
        if ($sid = $r->qint('specialization_id')) {
            $sql .= ' AND sv.specialization_id = ?';
            $p[] = $sid;
        }
        return Db::all($sql . ' ORDER BY sp.name, sv.price', $p);
    }

    private static function consultantRows(string $where, array $p): array
    {
        $rows = Db::all("SELECT c.id, c.user_id, c.position, c.bio, c.experience_years, c.is_active,
                           CONCAT(u.last_name, ' ', u.first_name) AS name, CONCAT(LEFT(u.first_name, 1), LEFT(u.last_name, 1)) AS initials,
                           COALESCE(v.avg_rating, 0) AS rating, COALESCE(v.reviews_count, 0) AS reviews_count
                         FROM consultants c JOIN users u ON u.id = c.user_id JOIN v_consultant_rating v ON v.consultant_id = c.id
                         WHERE u.is_active = 1 AND $where ORDER BY rating DESC, u.last_name", $p);
        if ($rows) {
            $ids = array_column($rows, 'id');
            $sp = Db::all('SELECT cs.consultant_id, s.id, s.name FROM consultant_specializations cs JOIN specializations s ON s.id = cs.specialization_id WHERE cs.consultant_id IN (' . Db::in($ids) . ')', $ids);
            $by = [];
            foreach ($sp as $s) {
                $by[$s['consultant_id']][] = ['id' => (int)$s['id'], 'name' => $s['name']];
            }
            foreach ($rows as &$row) {
                $row['specializations'] = $by[$row['id']] ?? [];
                $row['rating'] = (float)$row['rating'];
            }
        }
        return $rows;
    }

    public static function consultants(Request $r): array
    {
        $where = 'c.is_active = 1';
        $p = [];
        if ($sid = $r->qint('specialization_id')) {
            $where .= ' AND EXISTS (SELECT 1 FROM consultant_specializations cs WHERE cs.consultant_id = c.id AND cs.specialization_id = ?)';
            $p[] = $sid;
        }
        if ($svc = $r->qint('service_id')) {
            $where .= ' AND EXISTS (SELECT 1 FROM consultant_specializations cs JOIN services sv ON sv.specialization_id = cs.specialization_id WHERE cs.consultant_id = c.id AND sv.id = ?)';
            $p[] = $svc;
        }
        return self::consultantRows($where, $p);
    }

    public static function consultant(Request $r, array $p): array
    {
        $rows = self::consultantRows('c.id = ?', [(int)$p['id']]);
        if (!$rows) {
            throw HttpException::notFound('Консультант не найден');
        }
        $c = $rows[0];
        $c['reviews'] = Db::all("SELECT rv.rating, rv.text, rv.created_at, CONCAT(u.first_name, ' ', LEFT(u.last_name, 1), '.') AS author
                                 FROM reviews rv JOIN users u ON u.id = rv.client_id
                                 WHERE rv.consultant_id = ? AND rv.status = 'published' ORDER BY rv.created_at DESC LIMIT 20", [(int)$p['id']]);
        return $c;
    }

    /** Свободные слоты: /api/slots?consultant_id=&service_id=&from=&to= */
    public static function slots(Request $r): array
    {
        $cid = $r->qint('consultant_id');
        $svc = Db::one('SELECT duration_min, specialization_id FROM services WHERE id = ? AND is_active = 1', [$r->qint('service_id', 0)]);
        if (!$cid || !$svc) {
            throw HttpException::bad('Укажите консультанта и услугу');
        }
        $from = $r->q('from', date('Y-m-d'));
        $to = $r->q('to', date('Y-m-d', strtotime('+31 days')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) {
            throw HttpException::bad('Некорректный период');
        }
        if ((strtotime($to) - strtotime($from)) / 86400 > 92) {
            throw HttpException::bad('Период не более 92 дней');
        }
        Appointments::expireUnpaid();
        $days = Slots::range($cid, (int)$svc['duration_min'], $from, $to);
        $out = [];
        foreach ($days as $d => $slots) {
            $out[$d] = ['free' => count(array_filter($slots, fn($s) => $s['available'])), 'total' => count($slots), 'slots' => $slots];
        }
        return ['duration_min' => (int)$svc['duration_min'], 'days' => $out];
    }
}
