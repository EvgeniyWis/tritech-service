<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Settings;
use DateTimeImmutable;

/** Алгоритм расчёта свободных слотов консультанта. */
final class Slots
{
    /**
     * Слоты по дням диапазона.
     * @return array<string, array<int, array{start:string,end:string,available:bool}>>
     */
    public static function range(int $consultantId, int $durationMin, string $from, string $to, ?int $excludeId = null): array
    {
        $step = max(5, Settings::int('slot_step_min', 30));
        $buffer = Settings::int('buffer_min', 10);
        $minH = Settings::int('min_booking_hours', 2);
        $horizon = Settings::int('booking_horizon_days', 60);

        $now = new DateTimeImmutable('now');
        $earliest = $now->modify("+$minH hours");
        $lastDate = $now->modify("+$horizon days")->format('Y-m-d');
        if ($to > $lastDate) {
            $to = $lastDate;
        }

        $sched = [];
        foreach (Db::all('SELECT weekday, start_time, end_time FROM work_schedules WHERE consultant_id = ? ORDER BY start_time', [$consultantId]) as $r) {
            $sched[(int)$r['weekday']][] = [substr($r['start_time'], 0, 5), substr($r['end_time'], 0, 5)];
        }
        $exc = [];
        foreach (Db::all('SELECT date, is_working, start_time, end_time FROM schedule_exceptions WHERE consultant_id = ? AND date BETWEEN ? AND ?', [$consultantId, $from, $to]) as $r) {
            $exc[$r['date']] = $r;
        }
        $busy = Db::all(
            "SELECT a.start_at, a.end_at FROM appointments a JOIN appointment_statuses s ON s.id = a.status_id
             WHERE a.consultant_id = ? AND s.blocks_slot = 1 AND (s.code <> 'pending_payment' OR a.payment_deadline > ?)
               AND a.start_at < DATE_ADD(?, INTERVAL 2 DAY) AND a.end_at > DATE_SUB(?, INTERVAL 1 DAY) AND a.id <> ?",
            [$consultantId, now(), $to, $from, (int)$excludeId]
        );
        $busyTs = array_map(fn($b) => [strtotime($b['start_at']), strtotime($b['end_at'])], $busy);

        $result = [];
        $d = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
        while ($d <= $end) {
            $ds = $d->format('Y-m-d');
            $intervals = $sched[(int)$d->format('N')] ?? [];
            if (isset($exc[$ds])) {
                $e = $exc[$ds];
                $intervals = ($e['is_working'] && $e['start_time'] && $e['end_time'])
                    ? [[substr($e['start_time'], 0, 5), substr($e['end_time'], 0, 5)]] : [];
            }
            $daySlots = [];
            foreach ($intervals as [$is, $ie]) {
                $t = new DateTimeImmutable("$ds $is:00");
                $limit = new DateTimeImmutable("$ds $ie:00");
                while (true) {
                    $slotEnd = $t->modify("+$durationMin minutes");
                    if ($slotEnd > $limit) {
                        break;
                    }
                    $ok = $t >= $earliest;
                    if ($ok) {
                        $a = $t->getTimestamp();
                        $b = $slotEnd->getTimestamp();
                        foreach ($busyTs as [$bs, $be]) {
                            if ($a < $be + $buffer * 60 && $b + $buffer * 60 > $bs) {
                                $ok = false;
                                break;
                            }
                        }
                    }
                    $daySlots[] = ['start' => $t->format('H:i'), 'end' => $slotEnd->format('H:i'), 'available' => $ok];
                    $t = $t->modify("+$step minutes");
                }
            }
            $result[$ds] = $daySlots;
            $d = $d->modify('+1 day');
        }
        return $result;
    }

    /** Свободен ли слот (используется при создании/переносе записи). */
    public static function isFree(int $consultantId, int $durationMin, string $startAt, ?int $excludeId = null): bool
    {
        $day = substr($startAt, 0, 10);
        $slots = self::range($consultantId, $durationMin, $day, $day, $excludeId)[$day] ?? [];
        $hm = substr($startAt, 11, 5);
        foreach ($slots as $s) {
            if ($s['start'] === $hm) {
                return $s['available'];
            }
        }
        return false;
    }
}
