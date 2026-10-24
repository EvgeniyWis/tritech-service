<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/** Резервное копирование БД средствами PHP (дамп структуры и данных в .sql). */
final class Backup
{
    public static function dir(): string
    {
        $d = STORAGE . '/backups';
        if (!is_dir($d)) {
            mkdir($d, 0775, true);
        }
        return $d;
    }

    public static function create(): string
    {
        $pdo = Db::pdo();
        $name = 'backup_' . date('Ymd_His') . '.sql';
        $path = self::dir() . '/' . $name;
        $fh = fopen($path, 'wb');
        fwrite($fh, "-- ТрайтэкСервис: резервная копия от " . date('d.m.Y H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
        $tables = array_column($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_NUM), 0);
        foreach ($tables as $t) {
            $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(\PDO::FETCH_NUM)[1];
            fwrite($fh, "DROP TABLE IF EXISTS `$t`;\n$create;\n\n");
            $st = $pdo->query("SELECT * FROM `$t`", \PDO::FETCH_NUM);
            $batch = [];
            while ($row = $st->fetch()) {
                $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row);
                $batch[] = '(' . implode(',', $vals) . ')';
                if (count($batch) >= 200) {
                    fwrite($fh, "INSERT INTO `$t` VALUES " . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                fwrite($fh, "INSERT INTO `$t` VALUES " . implode(",\n", $batch) . ";\n");
            }
            fwrite($fh, "\n");
        }
        fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n\n");
        $views = array_column($pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(\PDO::FETCH_NUM), 0);
        foreach ($views as $v) {
            $create = $pdo->query("SHOW CREATE VIEW `$v`")->fetch(\PDO::FETCH_NUM)[1];
            // переносимость между серверами: убираем DEFINER / ALGORITHM / SQL SECURITY
            $create = preg_replace('/^CREATE\s.*?VIEW/is', 'CREATE VIEW', $create);
            fwrite($fh, "DROP VIEW IF EXISTS `$v`;\n$create;\n\n");
        }
        fclose($fh);
        return $name;
    }

    /** Список копий; старые (старше $keepDays) удаляются при $prune. */
    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/backup_*.sql') ?: [] as $f) {
            $out[] = ['name' => basename($f), 'size' => filesize($f), 'created_at' => date('Y-m-d H:i:s', filemtime($f))];
        }
        usort($out, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        return $out;
    }

    public static function prune(int $keepDays = 14): int
    {
        $n = 0;
        foreach (glob(self::dir() . '/backup_*.sql') ?: [] as $f) {
            if (filemtime($f) < time() - $keepDays * 86400) {
                unlink($f);
                $n++;
            }
        }
        return $n;
    }
}
