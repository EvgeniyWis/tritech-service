<?php
// Установка БД: php database/install.php [--demo] [--force]
//   --force  пересоздать базу (удаляет все данные!)
//   --demo   наполнить демонстрационными данными
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

$args = $argv ?? [];
$demo = in_array('--demo', $args, true);
$force = in_array('--force', $args, true);
$c = cfg('db');

$pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], $c['port']), $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$exists = (bool)$pdo->query("SHOW DATABASES LIKE '" . $c['name'] . "'")->fetchColumn();
if ($exists && !$force) {
    fwrite(STDERR, "База {$c['name']} уже существует. Запустите с --force, чтобы пересоздать.\n");
    exit(1);
}
if ($exists) {
    $pdo->exec('DROP DATABASE `' . $c['name'] . '`');
}
$pdo->exec('CREATE DATABASE `' . $c['name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE `' . $c['name'] . '`');
$pdo->exec("SET NAMES utf8mb4, time_zone = '+03:00'");

function run_script(PDO $pdo, string $file, string $sep): void
{
    $sql = file_get_contents($file);
    if ($sep === ';') {
        // обычные скрипты: оператор заканчивается ';' в конце строки
        $parts = preg_split('/;\s*\n/', $sql);
    } else {
        $parts = preg_split('/^--#\s*$/m', $sql);
    }
    foreach ($parts as $p) {
        // убрать комментарии-строки
        $p = trim(preg_replace('/^\s*--.*$/m', '', $p));
        if ($p !== '') {
            $pdo->exec($p);
        }
    }
}

run_script($pdo, __DIR__ . '/schema.sql', ';');
echo "Схема создана\n";
run_script($pdo, __DIR__ . '/triggers.sql', '--#');
echo "Триггеры созданы\n";
run_script($pdo, __DIR__ . '/seed.sql', ';');
echo "Справочники заполнены\n";

// Администратор по умолчанию
$adminEmail = 'admin@tritech-service.ru';
$adminPass = 'Admin12345';
$now = date('Y-m-d H:i:s');
Db::insert('users', [
    'email' => $adminEmail, 'password_hash' => Auth::hashPassword($adminPass),
    'last_name' => 'Кириллов', 'first_name' => 'Артём',
    'pd_consent_at' => $now, 'email_verified_at' => $now, 'created_at' => $now,
]);
$uid = (int)Db::pdo()->lastInsertId();
$rid = (int)Db::val("SELECT id FROM roles WHERE code = 'admin'");
Db::insert('user_roles', ['user_id' => $uid, 'role_id' => $rid]);
echo "Администратор: $adminEmail / $adminPass (смените пароль после первого входа)\n";

if ($demo) {
    require __DIR__ . '/demo.php';
}
echo "Готово\n";
