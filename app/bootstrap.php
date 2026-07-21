<?php
declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Kiev');

// ---- автозавантаження класів App\* ----
spl_autoload_register(function (string $class): void {
    if (strpos($class, 'App\\') !== 0) {
        return;
    }
    $rel  = str_replace('\\', '/', substr($class, 4));
    $file = BASE_PATH . '/app/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require BASE_PATH . '/app/helpers.php';

App\Env::load(BASE_PATH . '/.env');

$debug = App\Env::bool('APP_DEBUG', false);
ini_set('display_errors', $debug ? '1' : '0');
error_reporting($debug ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');

foreach ([BASE_PATH . '/storage/logs', BASE_PATH . '/uploads'] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

if (PHP_SAPI !== 'cli') {
    App\Session::start();
}
