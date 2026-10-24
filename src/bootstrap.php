<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('STORAGE', APP_ROOT . '/storage');

spl_autoload_register(function (string $class): void {
    if (strpos($class, 'App\\') !== 0) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, 4));
    $file = __DIR__ . '/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$GLOBALS['config'] = require APP_ROOT . '/config/config.php';
date_default_timezone_set($GLOBALS['config']['app']['timezone']);
mb_internal_encoding('UTF-8');

function cfg(string $path, $default = null)
{
    $v = $GLOBALS['config'];
    foreach (explode('.', $path) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) {
            return $default;
        }
        $v = $v[$k];
    }
    return $v;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}
