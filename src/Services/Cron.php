<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Log;

/** Фоновые задачи (запускаются планировщиком cron/Task Scheduler или вручную из админ-панели). */
final class Cron
{
    public const JOBS = [
        'cancel_unpaid' => 'Освобождение неоплаченных слотов',
        'send_reminders' => 'Напоминания о консультациях',
        'check_sla' => 'Контроль SLA заявок',
        'check_tasks' => 'Контроль сроков задач',
        'backup' => 'Резервное копирование БД',
    ];

    public static function run(string $job): array
    {
        $res = match ($job) {
            'cancel_unpaid' => ['cancelled' => Appointments::expireUnpaid()],
            'send_reminders' => ['sent' => Appointments::sendReminders()],
            'check_sla' => Tickets::slaCheck(),
            'check_tasks' => Tasks::dueCheck(),
            'backup' => ['file' => Backup::create(), 'pruned' => Backup::prune(14)],
            default => throw new \InvalidArgumentException("Неизвестное задание: $job"),
        };
        Log::act('cron.' . $job, 'cron', null, json_encode($res, JSON_UNESCAPED_UNICODE), null);
        return $res;
    }

    /** «Ленивый» планировщик: запускает задания не чаще раза в 5 минут из обычного запроса (если не настроен cron). */
    public static function tick(): void
    {
        if (!cfg('app.lazy_cron', true)) {
            return;
        }
        $fh = @fopen(STORAGE . '/logs/cron.tick', 'c+');
        if (!$fh) {
            return;
        }
        if (flock($fh, LOCK_EX | LOCK_NB)) {
            $last = (int)stream_get_contents($fh);
            if (time() - $last >= 300) {
                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, (string)time());
                try {
                    self::runAll();
                } catch (\Throwable $e) {
                    Log::file('lazy cron: ' . $e->getMessage());
                }
            }
            flock($fh, LOCK_UN);
        }
        fclose($fh);
    }

    public static function runAll(): array
    {
        $out = [];
        foreach (['cancel_unpaid', 'send_reminders', 'check_sla', 'check_tasks'] as $j) {
            $out[$j] = self::run($j);
        }
        return $out;
    }
}
