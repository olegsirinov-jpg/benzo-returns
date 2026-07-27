<?php
/**
 * Щоденний список зворотних посилок, що прибули у відділення й чекають на отримання.
 * Надсилає перелік ТТН у Telegram менеджеру. Якщо таких немає — нічого не шле.
 *
 * Запуск за розкладом (напр. щодня о 9:00):
 *   CLI:  php /path/to/cron/pickup.php
 *   HTTP: https://returns.example.com/cron/pickup.php?key=CRON_KEY
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Env;
use App\Rma;
use App\Telegram;

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $key = (string)($_GET['key'] ?? '');
    if ($key === '' || !hash_equals(Env::str('CRON_KEY'), $key)) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$rows = Rma::arrivedForPickup();

if ($rows === []) {
    $msg = sprintf('[%s] Посилки до отримання: немає — нічого не надіслано.', date('Y-m-d H:i:s'));
    @file_put_contents(BASE_PATH . '/storage/logs/cron.log', $msg . PHP_EOL, FILE_APPEND);
    echo $msg . PHP_EOL;
    exit;
}

$r = Telegram::pickupList($rows);
$msg = sprintf(
    '[%s] Посилки до отримання: %d. Telegram: %s%s',
    date('Y-m-d H:i:s'),
    $r['count'],
    $r['ok'] ? 'надіслано' : 'помилка',
    $r['ok'] ? '' : ' (' . $r['error'] . ')'
);
@file_put_contents(BASE_PATH . '/storage/logs/cron.log', $msg . PHP_EOL, FILE_APPEND);
echo $msg . PHP_EOL;
