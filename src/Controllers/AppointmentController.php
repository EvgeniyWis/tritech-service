<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Log;
use App\Core\Notifier;
use App\Core\Request;
use App\Services\Appointments;
use App\Services\YooKassa;

final class AppointmentController
{
    public static function create(Request $r): array
    {
        $u = Auth::user();
        // записать клиента может сам клиент или менеджер от его имени
        $clientId = $u['id'];
        if (Auth::hasAnyRole(['manager', 'admin']) && $r->int('client_id')) {
            $clientId = $r->int('client_id');
            if (!Db::val("SELECT 1 FROM user_roles ur JOIN roles ro ON ro.id = ur.role_id WHERE ur.user_id = ? AND ro.code = 'client'", [$clientId])) {
                throw HttpException::bad('Указанный пользователь не является клиентом');
            }
        } elseif (!Auth::hasRole('client')) {
            throw HttpException::forbidden('Запись доступна клиентам');
        }
        $a = Appointments::create(
            $clientId, (int)$r->int('consultant_id', 0), (int)$r->int('service_id', 0),
            (string)$r->str('start_at', ''), mb_substr((string)$r->str('comment', ''), 0, 2000)
        );
        return self::decorate($a);
    }

    private static function decorate(array $a): array
    {
        $pay = Db::one("SELECT id, status, amount, confirmation_url FROM payments WHERE appointment_id = ? AND type = 'payment' ORDER BY id DESC LIMIT 1", [$a['id']]);
        $a['payment'] = $pay;
        $a['can_pay'] = $pay && $pay['status'] === 'pending' && $a['status'] === 'pending_payment';
        return $a;
    }

    /** Список записей с фильтрами; область видимости зависит от роли. */
    public static function list(Request $r): array
    {
        Appointments::expireUnpaid();
        $u = Auth::user();
        $where = ['1=1'];
        $p = [];
        $all = Auth::hasAnyRole(['manager', 'admin', 'tech_director']);
        $mine = $r->q('scope') === 'mine';
        if (!$all || $mine) {
            $conds = [];
            if (Auth::hasRole('client')) {
                $conds[] = 'a.client_id = ?';
                $p[] = $u['id'];
            }
            if ($u['consultant_id'] && ($r->q('scope') !== 'client' || !Auth::hasRole('client'))) {
                $conds[] = 'a.consultant_id = ?';
                $p[] = $u['consultant_id'];
            }
            if (!$conds) {
                throw HttpException::forbidden();
            }
            $where[] = '(' . implode(' OR ', $conds) . ')';
        }
        if ($st = $r->q('status')) {
            $where[] = 's.code = ?';
            $p[] = $st;
        }
        if ($cid = $r->qint('consultant_id')) {
            $where[] = 'a.consultant_id = ?';
            $p[] = $cid;
        }
        if ($from = $r->q('from')) {
            $where[] = 'a.start_at >= ?';
            $p[] = $from . ' 00:00:00';
        }
        if ($to = $r->q('to')) {
            $where[] = 'a.start_at <= ?';
            $p[] = $to . ' 23:59:59';
        }
        if ($q = $r->q('q')) {
            $where[] = "(CONCAT(u.last_name, ' ', u.first_name) LIKE ? OR u.company LIKE ? OR " . Appointments::NUM . " LIKE ?)";
            array_push($p, "%$q%", "%$q%", "%$q%");
        }
        if ($r->q('upcoming')) {
            $where[] = "a.start_at >= ? AND s.code IN ('pending_payment','confirmed')";
            $p[] = now();
        }
        $limit = min(100, $r->qint('limit', 20));
        $page = max(1, $r->qint('page', 1));
        $sql = Appointments::baseSelect() . ' WHERE ' . implode(' AND ', $where);
        $total = (int)Db::val('SELECT COUNT(*) FROM (' . $sql . ') x', $p);
        $order = $r->q('sort') === 'asc' ? 'ASC' : 'DESC';
        $rows = Db::all($sql . " ORDER BY a.start_at $order LIMIT $limit OFFSET " . (($page - 1) * $limit), $p);
        foreach ($rows as &$row) {
            $row = self::decorate($row);
        }
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    public static function get(Request $r, array $p): array
    {
        Appointments::expireUnpaid();
        $a = Appointments::find((int)$p['id']);
        Appointments::assertAccess($a);
        $u = Auth::user();
        $isClientView = !Auth::hasAnyRole(['manager', 'admin', 'tech_director']) && (int)$a['client_id'] === $u['id'] && !($u['consultant_id'] && (int)$a['consultant_id'] === (int)$u['consultant_id']);
        $a = self::decorate($a);
        $a['history'] = Db::all("SELECT h.changed_at, h.comment, ns.name AS status_name, CONCAT(u.last_name, ' ', LEFT(u.first_name, 1), '.') AS user_name
                                 FROM status_history h JOIN appointment_statuses ns ON ns.id = h.new_status_id LEFT JOIN users u ON u.id = h.changed_by
                                 WHERE h.appointment_id = ? ORDER BY h.id", [$a['id']]);
        $a['payments'] = Db::all('SELECT id, type, amount, status, method, created_at, paid_at, parent_payment_id FROM payments WHERE appointment_id = ? ORDER BY id', [$a['id']]);
        $notes = Db::all('SELECT id, version, status, summary, recommendations, created_at FROM consultation_notes WHERE appointment_id = ? ORDER BY version DESC', [$a['id']]);
        if ($isClientView) {
            $notes = array_values(array_filter($notes, fn($n) => $n['status'] === 'final'));
            $notes = array_slice($notes, 0, 1);
        }
        $a['notes'] = $notes;
        $a['review'] = Db::one('SELECT id, rating, text, status, created_at FROM reviews WHERE appointment_id = ?', [$a['id']]);
        $a['project'] = Db::one('SELECT id, name, status FROM projects WHERE appointment_id = ?', [$a['id']]);
        $past = strtotime($a['start_at']) <= time();
        $a['actions'] = [
            'cancel' => in_array($a['status'], ['pending_payment', 'confirmed'], true) && (!$isClientView || !$past),
            'reschedule' => $a['status'] === 'confirmed' && !$past,
            'mark' => $a['status'] === 'confirmed' && $past && !$isClientView,
            'note' => $u['consultant_id'] && (int)$a['consultant_id'] === (int)$u['consultant_id'] && $past && in_array($a['status'], ['confirmed', 'completed'], true),
            'review' => $a['status'] === 'completed' && (int)$a['client_id'] === $u['id'] && !$a['review'],
            'create_project' => $a['status'] === 'completed' && Auth::hasAnyRole(['manager', 'tech_director', 'admin']) && !$a['project'],
        ];
        $a['refund_preview'] = $a['status'] === 'confirmed'
            ? Appointments::refundPercent($a, $isClientView ? 'client' : 'manager') : null;
        return $a;
    }

    public static function cancel(Request $r, array $p): array
    {
        return self::decorate(Appointments::cancel((int)$p['id'], mb_substr((string)$r->str('reason', ''), 0, 255)));
    }

    public static function reschedule(Request $r, array $p): array
    {
        return self::decorate(Appointments::reschedule((int)$p['id'], (string)$r->str('start_at', '')));
    }

    public static function mark(Request $r, array $p): array
    {
        return self::decorate(Appointments::mark((int)$p['id'], (string)$r->str('status', '')));
    }

    public static function saveNote(Request $r, array $p): array
    {
        $status = $r->str('status', 'draft');
        if (!in_array($status, ['draft', 'final'], true)) {
            throw HttpException::bad('Недопустимый статус заключения');
        }
        return Appointments::saveNote((int)$p['id'], (string)$r->str('summary', ''), $r->str('recommendations') ?: null, $status);
    }

    /** Повторная оплата (новая попытка), если предыдущая отменена. */
    public static function pay(Request $r, array $p): array
    {
        Appointments::expireUnpaid();
        $a = Appointments::find((int)$p['id']);
        if ((int)$a['client_id'] !== Auth::id() && !Auth::hasAnyRole(['manager', 'admin'])) {
            throw HttpException::forbidden();
        }
        $pay = Db::one("SELECT * FROM payments WHERE appointment_id = ? AND type = 'payment' AND status = 'pending' ORDER BY id DESC LIMIT 1", [$a['id']]);
        $pid = $pay ? (int)$pay['id'] : Appointments::retryPayment($a);
        return Db::one('SELECT id, status, amount, confirmation_url FROM payments WHERE id = ?', [$pid]);
    }

    public static function review(Request $r, array $p): array
    {
        $a = Appointments::find((int)$p['id']);
        if ((int)$a['client_id'] !== Auth::id()) {
            throw HttpException::forbidden();
        }
        if ($a['status'] !== 'completed') {
            throw HttpException::conflict('Отзыв можно оставить после проведённой консультации');
        }
        if (Db::val('SELECT 1 FROM reviews WHERE appointment_id = ?', [$a['id']])) {
            throw HttpException::conflict('Отзыв уже оставлен');
        }
        $rating = (int)$r->int('rating', 0);
        if ($rating < 1 || $rating > 5) {
            throw HttpException::bad('Оценка: от 1 до 5', ['rating' => 'Поставьте оценку от 1 до 5']);
        }
        $text = mb_substr((string)$r->str('text', ''), 0, 2000);
        $id = Db::insert('reviews', [
            'appointment_id' => $a['id'], 'client_id' => $a['client_id'], 'consultant_id' => $a['consultant_id'],
            'rating' => $rating, 'text' => $text ?: null, 'created_at' => now(),
        ]);
        Notifier::sendMany(Notifier::usersWithRole('manager'), 'review_new', 'Новый отзыв на модерации',
            $a['consultant_name'] . ' · оценка ' . $rating, ['appointment_id' => (int)$a['id']], false);
        Log::act('review.create', 'review', $id);
        return ['id' => $id, 'status' => 'pending', 'message' => 'Спасибо! Отзыв появится после модерации.'];
    }

    // ---------- Платежи ----------

    public static function payment(Request $r, array $p): array
    {
        $pay = Db::one('SELECT * FROM payments WHERE id = ?', [(int)$p['id']]);
        if (!$pay) {
            throw HttpException::notFound('Платёж не найден');
        }
        $a = Appointments::find((int)$pay['appointment_id']);
        Appointments::assertAccess($a);
        return [
            'payment' => ['id' => (int)$pay['id'], 'status' => $pay['status'], 'amount' => $pay['amount'], 'mock' => YooKassa::isMock()],
            'appointment' => [
                'id' => (int)$a['id'], 'number' => $a['number'], 'service_name' => $a['service_name'], 'consultant_name' => $a['consultant_name'],
                'start_at' => $a['start_at'], 'status' => $a['status'], 'payment_deadline' => $a['payment_deadline'],
            ],
        ];
    }

    /** Имитация платёжной страницы в песочнице: вызывает ту же обработку, что и webhook. */
    public static function mockPay(Request $r, array $p): array
    {
        if (!YooKassa::isMock()) {
            throw HttpException::forbidden('Тестовая оплата отключена');
        }
        $pay = Db::one('SELECT * FROM payments WHERE id = ?', [(int)$p['id']]);
        if (!$pay || $pay['type'] !== 'payment') {
            throw HttpException::notFound('Платёж не найден');
        }
        $a = Appointments::find((int)$pay['appointment_id']);
        if ((int)$a['client_id'] !== Auth::id() && !Auth::hasAnyRole(['manager', 'admin'])) {
            throw HttpException::forbidden();
        }
        if ($pay['status'] !== 'pending') {
            throw HttpException::conflict('Платёж уже обработан');
        }
        if ($r->str('result') === 'cancel') {
            Appointments::onPaymentCanceled((string)$pay['external_id']);
            return ['status' => 'canceled'];
        }
        Appointments::onPaymentSucceeded((string)$pay['external_id']);
        return ['status' => 'succeeded'];
    }

    /** Webhook ЮKassa: payment.succeeded / payment.canceled. */
    public static function webhook(Request $r): array
    {
        $event = (string)$r->input('event', '');
        $obj = (array)$r->input('object', []);
        $extId = (string)($obj['id'] ?? '');
        if ($extId === '') {
            throw HttpException::bad('Нет идентификатора платежа');
        }
        if (YooKassa::isMock()) {
            if (!hash_equals((string)cfg('yookassa.webhook_secret'), (string)$r->header('x-webhook-secret'))) {
                throw HttpException::forbidden('Подпись webhook не подтверждена');
            }
            $status = (string)($obj['status'] ?? '');
        } else {
            // не доверяем телу уведомления: статус запрашивается у ЮKassa
            $status = (string)(YooKassa::fetchPayment($extId)['status'] ?? '');
        }
        if ($event === 'payment.succeeded' && $status === 'succeeded') {
            Appointments::onPaymentSucceeded($extId);
        } elseif ($event === 'payment.canceled' && $status === 'canceled') {
            Appointments::onPaymentCanceled($extId);
        }
        return ['ok' => true];
    }
}
