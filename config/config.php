<?php
// Базовая конфигурация. Локальные переопределения — в config/config.local.php (не коммитится).
$config = [
    'app' => [
        'name'     => 'ТрайтэкСервис',
        'url'      => 'http://127.0.0.1:8200',
        'debug'    => true,                  // подробные тексты ошибок сервера
        'dev_links' => true,                 // ссылки подтверждения e-mail/сброса пароля возвращаются в ответе API (если нет SMTP)
        'demo'     => false,                 // показывать на странице входа демо-аккаунты
        'timezone' => 'Europe/Moscow',
        'lazy_cron' => true,                 // если не настроен планировщик, фоновые задания запускаются из запросов раз в 5 минут
    ],
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'tritech_service',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],
    'mail' => [
        'from'      => 'no-reply@tritech-service.ru',
        'from_name' => 'ТрайтэкСервис',
        'smtp_host' => '',                   // пусто — письма пишутся в storage/mails
        'smtp_port' => 587,
        'smtp_user' => '',
        'smtp_pass' => '',
        'smtp_secure' => 'tls',              // tls | ssl | ''
    ],
    'yookassa' => [
        'shop_id'    => '',                  // пусто — режим песочницы (имитация платёжной страницы)
        'secret_key' => '',
        'return_url' => 'http://127.0.0.1:8200/#/my/appointments',
        'webhook_secret' => 'dev-webhook-secret',
    ],
    'meeting' => [
        'base_url' => 'https://meet.jit.si/Tritech-',
    ],
    'uploads' => [
        'max_bytes'  => 10 * 1024 * 1024,
        'extensions' => ['png', 'jpg', 'jpeg', 'gif', 'pdf', 'txt', 'log', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'csv'],
    ],
    'security' => [
        'max_failed_logins' => 5,
        'lock_minutes'      => 15,
        'password_min_len'  => 8,
    ],
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_replace_recursive($config, require $local);
}
return $config;
