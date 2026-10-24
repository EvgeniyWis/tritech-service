<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Settings;
use DateTimeImmutable;

/**
 * Расчёт сроков SLA. Для приоритетов «Высокий» и «Критический» время календарное (24/7),
 * для остальных — рабочее (пн–пт, 09:00–18:00 по умолчанию).
 */
final class Sla
{
    private static function window(): array
    {
        $s = Settings::get('business_start', '09:00');
        $e = Settings::get('business_end', '18:00');
        [$sh, $sm] = array_map('intval', explode(':', $s));
        [$eh, $em] = array_map('intval', explode(':', $e));
        return [$sh * 60 + $sm, $eh * 60 + $em];
    }

    private static function minuteOfDay(DateTimeImmutable $d): int
    {
        return (int)$d->format('G') * 60 + (int)$d->format('i');
    }

    private static function isWorkday(DateTimeImmutable $d): bool
    {
        return (int)$d->format('N') <= 5;
    }

    private static function atMinute(DateTimeImmutable $d, int $min): DateTimeImmutable
    {
        return $d->setTime(intdiv($min, 60), $min % 60, 0);
    }

    /** Ближайший момент рабочего времени, не раньше $d. */
    private static function normalize(DateTimeImmutable $d): DateTimeImmutable
    {
        [$ws, $we] = self::window();
        while (true) {
            if (!self::isWorkday($d)) {
                $d = self::atMinute($d->modify('+1 day'), $ws);
                continue;
            }
            $m = self::minuteOfDay($d);
            if ($m < $ws) {
                return self::atMinute($d, $ws);
            }
            if ($m >= $we) {
                $d = self::atMinute($d->modify('+1 day'), $ws);
                continue;
            }
            return $d;
        }
    }

    /** Срок: $from + $minutes (календарных или рабочих минут). */
    public static function deadline(DateTimeImmutable $from, int $minutes, bool $calendar): DateTimeImmutable
    {
        if ($calendar) {
            return $from->modify("+$minutes minutes");
        }
        [, $we] = self::window();
        $cur = self::normalize($from);
        $left = $minutes;
        while (true) {
            $avail = $we - self::minuteOfDay($cur);
            if ($left <= $avail) {
                return $cur->modify("+$left minutes");
            }
            $left -= $avail;
            $cur = self::normalize(self::atMinute($cur->modify('+1 day'), 0));
        }
    }

    /** Разница в минутах (со знаком) между $a и $b в календарном или рабочем времени. */
    public static function minutesBetween(DateTimeImmutable $a, DateTimeImmutable $b, bool $calendar): int
    {
        if ($b < $a) {
            return -self::minutesBetween($b, $a, $calendar);
        }
        if ($calendar) {
            return intdiv($b->getTimestamp() - $a->getTimestamp(), 60);
        }
        [$ws, $we] = self::window();
        $total = 0;
        $cur = $a;
        $guard = 0;
        while ($cur < $b && $guard++ < 4000) {
            if (!self::isWorkday($cur)) {
                $cur = self::atMinute($cur->modify('+1 day'), $ws);
                continue;
            }
            $m = self::minuteOfDay($cur);
            if ($m < $ws) {
                $cur = self::atMinute($cur, $ws);
                continue;
            }
            if ($m >= $we) {
                $cur = self::atMinute($cur->modify('+1 day'), $ws);
                continue;
            }
            $dayEnd = self::atMinute($cur, $we);
            $to = $b < $dayEnd ? $b : $dayEnd;
            $total += intdiv($to->getTimestamp() - $cur->getTimestamp(), 60);
            $cur = self::atMinute($cur->modify('+1 day'), $ws);
        }
        return $total;
    }

    /**
     * Состояние SLA заявки для UI.
     * @param array $t строка заявки + priorities.calendar_time (calendar)
     */
    public static function state(array $t): array
    {
        $cal = (bool)$t['calendar_time'];
        $created = new DateTimeImmutable($t['created_at']);
        $due = new DateTimeImmutable($t['due_at']);
        $now = new DateTimeImmutable('now');
        $total = max(1, self::minutesBetween($created, $due, $cal));
        $done = in_array($t['status'], ['resolved', 'closed'], true);

        $resp = ['due' => $t['first_response_due'], 'at' => $t['first_response_at']];
        $respDue = new DateTimeImmutable($t['first_response_due']);
        if ($t['first_response_at']) {
            $resp['ok'] = new DateTimeImmutable($t['first_response_at']) <= $respDue;
            $resp['delta_min'] = self::minutesBetween($created, new DateTimeImmutable($t['first_response_at']), $cal);
        } else {
            $resp['ok'] = $now <= $respDue;
            $resp['delta_min'] = null;
            $resp['remaining_min'] = self::minutesBetween($now, $respDue, $cal);
        }

        if ($done) {
            $end = new DateTimeImmutable($t['resolved_at'] ?: $t['closed_at'] ?: $t['updated_at'] ?: $t['created_at']);
            $ok = $end <= $due;
            return [
                'state' => $ok ? 'done_ok' : 'done_late', 'remaining_min' => null, 'total_min' => $total,
                'elapsed_pct' => min(100, (int)round(self::minutesBetween($created, $end, $cal) * 100 / $total)),
                'response' => $resp, 'calendar' => $cal,
            ];
        }

        $remaining = self::minutesBetween($now, $due, $cal);
        $elapsed = $total - $remaining;
        $warnPct = Settings::int('sla_warn_percent', 25);
        if ($remaining < 0) {
            $state = 'breached';
        } elseif ($remaining * 100 / $total < $warnPct) {
            $state = 'warning';
        } else {
            $state = 'ok';
        }
        return [
            'state' => $state, 'remaining_min' => $remaining, 'total_min' => $total,
            'elapsed_pct' => max(0, min(100, (int)round($elapsed * 100 / $total))),
            'response' => $resp, 'calendar' => $cal,
        ];
    }
}
