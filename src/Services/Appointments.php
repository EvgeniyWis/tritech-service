<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Log;
use App\Core\Notifier;
use App\Core\Settings;

final class Appointments
{
    /** SQL-выражение номера записи вида 2026-0352 */
    public const NUM = "CONCAT(YEAR(a.created_at), '-', LPAD(a.id, 4, '0'))";

    public static function statusId(string $code): int
    {
        static $map = null;
        $map ??= array_column(Db::all('SELECT code, id FROM appointment_statuses'), 'id', 'code');
        return (int)$map[$code];
    }

    /** Базовый SELECT с присоединёнными справочниками. */
    public static function baseSelect(): string
    {
        return "SELECT a.*, " . self::NUM . " AS number, s.code AS status, s.name AS status_name,
                  sv.name AS service_name, sv.duration_min, sv.specialization_id,
                  cu.id AS consultant_user_id, CONCAT(cu.last_name, ' ', cu.first_name) AS consultant_name, c.position AS consultant_position,
                  CONCAT(u.last_name, ' ', u.first_name) AS client_name, u.company AS client_company, u.email AS client_email, u.phone AS client_phone
                FROM appointments a
                JOIN appointment_statuses s ON s.id = a.status_id
                JOIN services sv ON sv.id = a.service_id
                JOIN consultants c ON c.id = a.consultant_id
                JOIN users cu ON cu.id = c.user_id
                JOIN users u ON u.id = a.client_id";
    }

    public static function find(int $id): array
    {
        $a = Db::one(self::baseSelect() . ' WHERE a.id = ?', [$id]);
        if (!$a) {
            throw HttpException::notFound('Запись не найдена');
        }
        return $a;
    }

    /** Проверка доступа текущего пользователя к записи. */
    public static function assertAccess(array $a, bool $write = false): void
    {
        $u = Auth::requireLogin();
        if (Auth::hasAnyRole(['manager', 'admin', 'tech_director'])) {
            if ($write && !Auth::hasAnyRole(['manager', 'admin'])) {
                throw HttpException::forbidden();
            }
            return;
        }
        if ((int)$a['client_id'] === $u['id']) {
            return;
        }
        if ($u['consultant_id'] && (int)$a['consultant_id'] === (int)$u['consultant_id']) {
            return;
        }
        throw HttpException::forbidden('Нет доступа к записи');
    }

    private static function history(int $appId, ?int $old, int $new, ?int $by, ?string $comment = null): void
    {
        Db::insert('status_history', [
            'appointment_id' => $appId, 'old_status_id' => $old, 'new_status_id' => $new,
            'changed_by' => $by, 'changed_at' => now(), 'comment' => $comment,
        ]);
    }

    private static function fmt(string $dt): string
    {
        return date('d.m.Y H:i', strtotime($dt));
    }

    /** Создание записи с удержанием слота и платежом. */
    public static function create(int $clientId, int $consultantId, int $serviceId, string $startAt, ?string $comment): array
    {
        self::expireUnpaid();
        $srv = Db::one('SELECT * FROM services WHERE id = ? AND is_active = 1', [$serviceId]);
        if (!$srv) {
            throw HttpException::bad('Услуга недоступна');
        }
        $cons = Db::one('SELECT c.*, u.id AS uid FROM consultants c JOIN users u ON u.id = c.user_id WHERE c.id = ? AND c.is_active = 1 AND u.is_active = 1', [$consultantId]);
        if (!$cons) {
            throw HttpException::bad('Консультант недоступен');
        }
        $has = Db::val('SELECT 1 FROM consultant_specializations WHERE consultant_id = ? AND specialization_id = ?', [$consultantId, $srv['specialization_id']]);
        if (!$has) {
            throw HttpException::bad('Консультант не оказывает эту услугу');
        }
        $ts = strtotime($startAt);
        if (!$ts) {
            throw HttpException::bad('Некорректная дата и время');
        }
        $startAt = date('Y-m-d H:i:s', $ts);
        $endAt = date('Y-m-d H:i:s', $ts + (int)$srv['duration_min'] * 60);

        $id = Db::tx(function () use ($clientId, $consultantId, $srv, $startAt, $endAt, $comment) {
            // блокировка строки консультанта сериализует конкурентные бронирования
            Db::q('SELECT id FROM consultants WHERE id = ? FOR UPDATE', [$consultantId]);
            if (!Slots::isFree($consultantId, (int)$srv['duration_min'], $startAt)) {
                throw HttpException::conflict('Это время уже занято или недоступно. Выберите другой слот.');
            }
            $price = (float)$srv['price'];
            $free = $price <= 0;
            $statusId = self::statusId($free ? 'confirmed' : 'pending_payment');
            try {
                $id = Db::insert('appointments', [
                    'client_id' => $clientId, 'consultant_id' => $consultantId, 'service_id' => $srv['id'],
                    'status_id' => $statusId, 'start_at' => $startAt, 'end_at' => $endAt, 'price' => $price,
                    'payment_deadline' => $free ? null : date('Y-m-d H:i:s', time() + Settings::int('payment_timeout_min', 30) * 60),
                    'client_comment' => $comment ?: null,
                    'meeting_url' => rtrim(cfg('meeting.base_url'), '-') . '-' . bin2hex(random_bytes(5)),
                    'created_at' => now(),
                ]);
            } catch (\PDOException $e) {
                if (strpos($e->getMessage(), 'уже занято') !== false) {
                    throw HttpException::conflict('Это время уже занято. Выберите другой слот.');
                }
                throw $e;
            }
            self::history($id, null, $statusId, $clientId, $free ? 'Запись создана (бесплатная услуга)' : 'Запись создана, ожидается оплата');
            if (!$free) {
                self::createPayment($id, $price, 'Консультация: ' . $srv['name']);
            }
            return $id;
        });

        Log::act('appointment.create', 'appointment', $id, 'Запись на ' . $startAt);
        $a = self::find($id);
        if ($a['status'] === 'confirmed') {
            self::notifyConfirmed($a);
        } else {
            Notifier::send($clientId, 'appointment_created',
                'Запись № ' . $a['number'] . ' создана',
                'Оплатите консультацию до ' . self::fmt($a['payment_deadline']) . ' — после этого слот будет освобождён.',
                ['appointment_id' => $id]);
        }
        return $a;
    }

    private static function createPayment(int $appId, float $amount, string $descr): int
    {
        $idem = YooKassa::uuid();
        $pid = Db::insert('payments', [
            'appointment_id' => $appId, 'type' => 'payment', 'amount' => $amount, 'status' => 'pending',
            'idempotence_key' => $idem, 'created_at' => now(),
        ]);
        $r = YooKassa::createPayment($amount, $descr, $idem, ['appointment_id' => $appId, 'payment_id' => $pid]);
        Db::update('payments', [
            'external_id' => $r['id'],
            'confirmation_url' => $r['confirmation_url'] ?: (rtrim(cfg('app.url'), '/') . '/#/pay/' . $pid),
        ], 'id = ?', [$pid]);
        return $pid;
    }

    /** Новая попытка оплаты (если предыдущая отменена, а слот ещё удерживается). */
    public static function retryPayment(array $a): int
    {
        if ($a['status'] !== 'pending_payment') {
            throw HttpException::conflict('Оплата недоступна для этой записи');
        }
        Db::exec("UPDATE payments SET status = 'canceled' WHERE appointment_id = ? AND type = 'payment' AND status = 'pending'", [$a['id']]);
        return self::createPayment((int)$a['id'], (float)$a['price'], 'Консультация: ' . $a['service_name']);
    }

    /** Обработка успешной оплаты (webhook payment.succeeded). */
    public static function onPaymentSucceeded(string $externalId): void
    {
        $pay = Db::one("SELECT * FROM payments WHERE external_id = ? AND type = 'payment'", [$externalId]);
        if (!$pay || $pay['status'] === 'succeeded') {
            return; // идемпотентность: повторный webhook игнорируется
        }
        Db::update('payments', ['status' => 'succeeded', 'paid_at' => now(), 'method' => 'bank_card'], 'id = ?', [$pay['id']]);
        $a = self::find((int)$pay['appointment_id']);
        if ($a['status'] === 'pending_payment') {
            Db::update('appointments', ['status_id' => self::statusId('confirmed'), 'payment_deadline' => null, 'updated_at' => now()], 'id = ?', [$a['id']]);
            self::history((int)$a['id'], (int)$a['status_id'], self::statusId('confirmed'), null, 'Оплата получена');
            self::notifyConfirmed(self::find((int)$a['id']));
        } else {
            // слот уже освобождён (оплата пришла после срока) — автоматический полный возврат
            self::refundPayment($pay, (float)$pay['amount'], 'Оплата получена после освобождения слота');
            Notifier::send((int)$a['client_id'], 'refund', 'Оплата возвращена',
                'Запись № ' . $a['number'] . ' была отменена до поступления оплаты, сумма возвращена полностью.', ['appointment_id' => (int)$a['id']]);
        }
    }

    public static function onPaymentCanceled(string $externalId): void
    {
        Db::exec("UPDATE payments SET status = 'canceled' WHERE external_id = ? AND type = 'payment' AND status = 'pending'", [$externalId]);
    }

    private static function notifyConfirmed(array $a): void
    {
        $when = self::fmt($a['start_at']);
        Notifier::send((int)$a['client_id'], 'appointment_confirmed', 'Запись № ' . $a['number'] . ' подтверждена',
            "$when · {$a['service_name']} · {$a['consultant_name']}. Ссылка на видеовстречу: {$a['meeting_url']}", ['appointment_id' => (int)$a['id']]);
        Notifier::send((int)$a['consultant_user_id'], 'appointment_new', 'Новая консультация ' . $when,
            "{$a['client_name']} ({$a['client_company']}) · {$a['service_name']}", ['appointment_id' => (int)$a['id']]);
    }

    /** Освобождение слотов с истёкшим сроком оплаты. Возвращает число отменённых записей. */
    public static function expireUnpaid(): int
    {
        $rows = Db::all(self::baseSelect() . ' WHERE s.code = ? AND a.payment_deadline IS NOT NULL AND a.payment_deadline < ?', ['pending_payment', now()]);
        foreach ($rows as $a) {
            Db::tx(function () use ($a) {
                Db::update('appointments', [
                    'status_id' => self::statusId('cancelled'), 'cancelled_by' => 'system',
                    'cancel_reason' => 'Не оплачено в срок', 'cancelled_at' => now(), 'updated_at' => now(),
                ], 'id = ?', [$a['id']]);
                Db::exec("UPDATE payments SET status = 'canceled' WHERE appointment_id = ? AND type = 'payment' AND status = 'pending'", [$a['id']]);
                self::history((int)$a['id'], (int)$a['status_id'], self::statusId('cancelled'), null, 'Слот освобождён: не оплачено в срок');
            });
            Notifier::send((int)$a['client_id'], 'appointment_expired', 'Запись № ' . $a['number'] . ' отменена',
                'Оплата не поступила в течение ' . Settings::int('payment_timeout_min', 30) . ' минут, слот освобождён.', ['appointment_id' => (int)$a['id']]);
        }
        return count($rows);
    }

    /** Процент возврата по правилам отмены. */
    public static function refundPercent(array $a, string $by): int
    {
        if ($by !== 'client') {
            return 100; // отмена по инициативе компании — полный возврат
        }
        $hours = (strtotime($a['start_at']) - time()) / 3600;
        if ($hours >= Settings::int('free_cancel_hours', 24)) {
            return 100;
        }
        if ($hours >= Settings::int('no_refund_hours', 2)) {
            return Settings::int('partial_refund_percent', 50);
        }
        return 0;
    }

    private static function refundPayment(array $pay, float $amount, string $note): void
    {
        $idem = YooKassa::uuid();
        $r = YooKassa::createRefund((string)$pay['external_id'], $amount, $idem);
        Db::insert('payments', [
            'appointment_id' => $pay['appointment_id'], 'parent_payment_id' => $pay['id'], 'type' => 'refund',
            'amount' => $amount, 'status' => $r['status'] === 'succeeded' ? 'succeeded' : 'pending',
            'method' => $pay['method'], 'external_id' => $r['id'], 'idempotence_key' => $idem,
            'created_at' => now(), 'paid_at' => $r['status'] === 'succeeded' ? now() : null,
        ]);
        Log::act('payment.refund', 'appointment', (int)$pay['appointment_id'], "$note: $amount ₽");
    }

    /** Отмена записи с расчётом возврата. */
    public static function cancel(int $id, string $reason): array
    {
        $a = self::find($id);
        self::assertAccess($a);
        $u = Auth::user();
        $by = Auth::hasAnyRole(['manager', 'admin']) ? 'manager'
            : (((int)$a['client_id'] === $u['id']) ? 'client' : 'consultant');
        if (!in_array($a['status'], ['pending_payment', 'confirmed'], true)) {
            throw HttpException::conflict('Запись нельзя отменить в статусе «' . $a['status_name'] . '»');
        }
        if ($by === 'client' && strtotime($a['start_at']) <= time()) {
            throw HttpException::conflict('Консультация уже началась');
        }
        $pct = $a['status'] === 'confirmed' ? self::refundPercent($a, $by) : 0;
        $refund = 0.0;
        Db::tx(function () use ($a, $by, $reason, $pct, &$refund) {
            Db::update('appointments', [
                'status_id' => self::statusId('cancelled'), 'cancelled_by' => $by, 'cancel_reason' => $reason ?: null,
                'cancelled_at' => now(), 'updated_at' => now(),
            ], 'id = ?', [$a['id']]);
            Db::exec("UPDATE payments SET status = 'canceled' WHERE appointment_id = ? AND type = 'payment' AND status = 'pending'", [$a['id']]);
            $note = 'Отменена (' . ['client' => 'клиентом', 'consultant' => 'консультантом', 'manager' => 'менеджером'][$by] . ')';
            if ($a['status'] === 'confirmed') {
                $pay = Db::one("SELECT * FROM payments WHERE appointment_id = ? AND type = 'payment' AND status = 'succeeded' ORDER BY id DESC LIMIT 1", [$a['id']]);
                if ($pay && $pct > 0) {
                    $refund = round((float)$pay['amount'] * $pct / 100, 2);
                    self::refundPayment($pay, $refund, 'Возврат при отмене');
                }
                $note .= $pay ? ", возврат $pct% ($refund ₽)" : '';
            }
            self::history((int)$a['id'], (int)$a['status_id'], self::statusId('cancelled'), Auth::id(), $note);
        });
        Log::act('appointment.cancel', 'appointment', $id, "by=$by refund=$pct%");

        $msgRefund = $a['status'] === 'confirmed'
            ? ($refund > 0 ? " Возврат: $refund ₽ ($pct%)." : ' Возврат не предусмотрен.') : '';
        $title = 'Запись № ' . $a['number'] . ' отменена';
        $body = self::fmt($a['start_at']) . ' · ' . $a['service_name'] . '.' . $msgRefund . ($reason ? " Причина: $reason" : '');
        if ($by !== 'client') {
            Notifier::send((int)$a['client_id'], 'appointment_cancelled', $title, $body, ['appointment_id' => $id]);
        }
        if ($by !== 'consultant') {
            Notifier::send((int)$a['consultant_user_id'], 'appointment_cancelled', $title, $body, ['appointment_id' => $id]);
        }
        $res = self::find($id);
        $res['refund_percent'] = $pct;
        $res['refund_amount'] = $refund;
        return $res;
    }

    /** Перенос записи на другое время (длительность сохраняется). */
    public static function reschedule(int $id, string $newStart): array
    {
        self::expireUnpaid();
        $a = self::find($id);
        self::assertAccess($a);
        $u = Auth::user();
        $isManager = Auth::hasAnyRole(['manager', 'admin']);
        $isClient = !$isManager && (int)$a['client_id'] === $u['id'];
        if ($a['status'] !== 'confirmed') {
            throw HttpException::conflict('Перенести можно только подтверждённую запись');
        }
        if (strtotime($a['start_at']) <= time()) {
            throw HttpException::conflict('Консультация уже началась');
        }
        if ($isClient) {
            $limit = Settings::int('free_cancel_hours', 24);
            if ((strtotime($a['start_at']) - time()) / 3600 < $limit) {
                throw HttpException::conflict("Перенос возможен не позднее чем за $limit ч до начала");
            }
            if ((int)$a['reschedule_count'] >= 1) {
                throw HttpException::conflict('Запись уже переносилась один раз');
            }
        }
        $ts = strtotime($newStart);
        if (!$ts) {
            throw HttpException::bad('Некорректная дата и время');
        }
        $start = date('Y-m-d H:i:s', $ts);
        $end = date('Y-m-d H:i:s', $ts + (int)$a['duration_min'] * 60);
        Db::tx(function () use ($a, $start, $end) {
            Db::q('SELECT id FROM consultants WHERE id = ? FOR UPDATE', [$a['consultant_id']]);
            if (!Slots::isFree((int)$a['consultant_id'], (int)$a['duration_min'], $start, (int)$a['id'])) {
                throw HttpException::conflict('Выбранное время недоступно');
            }
            Db::update('appointments', [
                'start_at' => $start, 'end_at' => $end, 'reschedule_count' => (int)$a['reschedule_count'] + 1,
                'reminder_24_sent' => 0, 'reminder_1_sent' => 0, 'updated_at' => now(),
            ], 'id = ?', [$a['id']]);
            self::history((int)$a['id'], (int)$a['status_id'], (int)$a['status_id'], Auth::id(),
                'Перенос: ' . self::fmt($a['start_at']) . ' → ' . self::fmt($start));
        });
        Log::act('appointment.reschedule', 'appointment', $id, $a['start_at'] . ' -> ' . $start);
        $title = 'Запись № ' . $a['number'] . ' перенесена';
        $body = 'Новое время: ' . self::fmt($start) . ' · ' . $a['service_name'];
        if ((int)$a['client_id'] !== $u['id']) {
            Notifier::send((int)$a['client_id'], 'appointment_rescheduled', $title, $body, ['appointment_id' => $id]);
        }
        if ((int)$a['consultant_user_id'] !== $u['id']) {
            Notifier::send((int)$a['consultant_user_id'], 'appointment_rescheduled', $title, $body, ['appointment_id' => $id]);
        }
        return self::find($id);
    }

    /** Отметка результата: проведена / неявка. */
    public static function mark(int $id, string $to): array
    {
        $a = self::find($id);
        self::assertAccess($a);
        $u = Auth::user();
        $isCons = $u['consultant_id'] && (int)$a['consultant_id'] === (int)$u['consultant_id'];
        if (!$isCons && !Auth::hasAnyRole(['manager', 'admin'])) {
            throw HttpException::forbidden();
        }
        if (!in_array($to, ['completed', 'no_show'], true)) {
            throw HttpException::bad('Недопустимый статус');
        }
        if ($a['status'] !== 'confirmed') {
            throw HttpException::conflict('Отметить можно только подтверждённую запись');
        }
        if (strtotime($a['start_at']) > time()) {
            throw HttpException::conflict('Консультация ещё не началась');
        }
        Db::update('appointments', ['status_id' => self::statusId($to), 'updated_at' => now()], 'id = ?', [$id]);
        self::history($id, (int)$a['status_id'], self::statusId($to), Auth::id(), $to === 'completed' ? 'Консультация проведена' : 'Клиент не явился');
        Log::act('appointment.' . $to, 'appointment', $id);
        if ($to === 'completed') {
            Notifier::send((int)$a['client_id'], 'appointment_completed', 'Консультация проведена',
                'Оставьте отзыв о консультации № ' . $a['number'] . ' — это поможет нам стать лучше.', ['appointment_id' => $id]);
        }
        return self::find($id);
    }

    /** Заключение консультанта (черновик/финальная версия, с версионированием). */
    public static function saveNote(int $id, string $summary, ?string $recs, string $status): array
    {
        $a = self::find($id);
        $u = Auth::requireLogin();
        if (!$u['consultant_id'] || (int)$a['consultant_id'] !== (int)$u['consultant_id']) {
            throw HttpException::forbidden('Заключение может писать только консультант записи');
        }
        if (!in_array($a['status'], ['completed', 'confirmed'], true) || strtotime($a['start_at']) > time()) {
            throw HttpException::conflict('Заключение доступно после начала консультации');
        }
        if (mb_strlen($summary) < 5) {
            throw HttpException::bad('Заключение слишком короткое');
        }
        $last = Db::one('SELECT * FROM consultation_notes WHERE appointment_id = ? ORDER BY version DESC LIMIT 1', [$id]);
        if ($last && $last['status'] === 'draft') {
            Db::update('consultation_notes', ['summary' => $summary, 'recommendations' => $recs, 'status' => $status], 'id = ?', [$last['id']]);
            $noteId = (int)$last['id'];
        } else {
            $noteId = Db::insert('consultation_notes', [
                'appointment_id' => $id, 'author_id' => $u['id'], 'version' => $last ? (int)$last['version'] + 1 : 1,
                'status' => $status, 'summary' => $summary, 'recommendations' => $recs, 'created_at' => now(),
            ]);
        }
        if ($status === 'final') {
            if ($a['status'] === 'confirmed') {
                Db::update('appointments', ['status_id' => self::statusId('completed'), 'updated_at' => now()], 'id = ?', [$id]);
                self::history($id, (int)$a['status_id'], self::statusId('completed'), $u['id'], 'Заключение опубликовано');
            }
            Notifier::send((int)$a['client_id'], 'note_published', 'Заключение по консультации № ' . $a['number'],
                'Консультант опубликовал заключение и рекомендации.', ['appointment_id' => $id]);
        }
        Log::act('appointment.note', 'appointment', $id, "status=$status");
        return Db::one('SELECT * FROM consultation_notes WHERE id = ?', [$noteId]);
    }

    /** Напоминания о консультациях (cron). */
    public static function sendReminders(): int
    {
        $sent = 0;
        $hours = array_map('intval', array_filter(explode(',', Settings::get('reminder_hours', '24,1'))));
        foreach ($hours as $h) {
            $flag = $h >= 12 ? 'reminder_24_sent' : 'reminder_1_sent';
            $rows = Db::all(self::baseSelect() . " WHERE s.code = 'confirmed' AND a.$flag = 0 AND a.start_at > ? AND a.start_at <= DATE_ADD(?, INTERVAL ? HOUR) AND a.created_at <= DATE_SUB(a.start_at, INTERVAL ? HOUR)", [now(), now(), $h, $h]);
            foreach ($rows as $a) {
                $title = ($h >= 12 ? 'Напоминание: консультация завтра' : 'Консультация начнётся через час') . ' — ' . self::fmt($a['start_at']);
                $body = $a['service_name'] . '. Видеовстреча: ' . $a['meeting_url'];
                Notifier::send((int)$a['client_id'], 'reminder', $title, $body, ['appointment_id' => (int)$a['id']]);
                Notifier::send((int)$a['consultant_user_id'], 'reminder', $title, $a['client_name'] . ' · ' . $body, ['appointment_id' => (int)$a['id']]);
                Db::update('appointments', [$flag => 1], 'id = ?', [$a['id']]);
                $sent++;
            }
        }
        return $sent;
    }
}
