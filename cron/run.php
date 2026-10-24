<?php
// Запуск фоновых заданий:  php cron/run.php <задание|all>
//   cancel_unpaid   — освободить слоты неоплаченных записей     (каждые 5 минут)
//   send_reminders  — напоминания о консультациях               (каждые 15 минут)
//   check_sla       — контроль SLA заявок, автозакрытие         (каждые 5 минут)
//   check_tasks     — контроль сроков задач                     (раз в час)
//   backup          — резервная копия БД                        (ежедневно, 03:00)
//   all             — первые четыре задания подряд
// Пример crontab:  */5 * * * * php /var/www/tritech/cron/run.php cancel_unpaid
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Только для командной строки');
}
require __DIR__ . '/../src/bootstrap.php';

use App\Services\Cron;

$job = $argv[1] ?? 'all';
try {
    $res = $job === 'all' ? Cron::runAll() : Cron::run($job);
    echo '[' . date('Y-m-d H:i:s') . "] $job: " . json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . "\n");
    exit(1);
}
