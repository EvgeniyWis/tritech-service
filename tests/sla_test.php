<?php
// Модульные проверки расчёта SLA и слотов:  php tests/sla_test.php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Services\Sla;

$fail = 0;
function check(string $name, bool $cond, string $info = ''): void
{
    global $fail;
    echo ($cond ? '  ok   ' : '  FAIL ') . $name . ($cond ? '' : " $info") . "\n";
    if (!$cond) {
        $fail++;
    }
}
function d(string $s): DateTimeImmutable { return new DateTimeImmutable($s); }

// рабочее время (пн–пт, 09–18)
$r = Sla::deadline(d('2026-10-23 17:30:00'), 240, false); // пт 17:30 + 4 ч
check('пт 17:30 + 4 ч рабочих → пн 12:30', $r->format('Y-m-d H:i') === '2026-10-26 12:30', $r->format('c'));
$r = Sla::deadline(d('2026-10-24 10:00:00'), 60, false); // суббота
check('сб 10:00 + 1 ч → пн 10:00', $r->format('Y-m-d H:i') === '2026-10-26 10:00', $r->format('c'));
$r = Sla::deadline(d('2026-10-22 08:00:00'), 30, false); // до начала дня
check('чт 08:00 + 30 мин → чт 09:30', $r->format('Y-m-d H:i') === '2026-10-22 09:30', $r->format('c'));
$r = Sla::deadline(d('2026-10-22 17:50:00'), 20, false);
check('чт 17:50 + 20 мин → пт 09:10', $r->format('Y-m-d H:i') === '2026-10-23 09:10', $r->format('c'));
// календарное время
$r = Sla::deadline(d('2026-10-23 17:30:00'), 15, true);
check('календарное: +15 мин', $r->format('Y-m-d H:i') === '2026-10-23 17:45', $r->format('c'));
$r = Sla::deadline(d('2026-10-23 22:00:00'), 8 * 60, true);
check('календарное: +8 ч через полночь', $r->format('Y-m-d H:i') === '2026-10-24 06:00', $r->format('c'));

// согласованность deadline ↔ minutesBetween
foreach ([['2026-10-19 09:00:00', 7200], ['2026-10-23 16:00:00', 4320], ['2026-10-24 12:00:00', 480], ['2026-10-22 13:13:00', 240 * 7]] as [$s, $m]) {
    $start = d($s);
    $end = Sla::deadline($start, $m, false);
    $back = Sla::minutesBetween($start, $end, false);
    check("minutesBetween(deadline) = $m ($s)", $back === $m, "got $back, end " . $end->format('c'));
}
check('minutesBetween со знаком', Sla::minutesBetween(d('2026-10-22 12:00:00'), d('2026-10-22 11:00:00'), true) === -60);
check('рабочее время: ночь/выходные не считаются', Sla::minutesBetween(d('2026-10-23 17:00:00'), d('2026-10-26 10:00:00'), false) === 120);

echo $fail ? "\nПровалено: $fail\n" : "\nВсе проверки пройдены\n";
exit($fail ? 1 : 0);
