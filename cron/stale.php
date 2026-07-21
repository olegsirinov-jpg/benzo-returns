<?php
/**
 * Нагадування про заявки, що зависли в статусі "Нова" понад 24 години (п.13.3 ТЗ).
 *
 * Запуск за розкладом (щогодини):
 *   CLI:  php /path/to/cron/stale.php
 *   HTTP: https://returns.example.com/cron/stale.php?key=CRON_KEY
 *
 * У .htaccess каталог /cron/ закритий від прямого доступу — якщо потрібен
 * HTTP-запуск, приберіть /cron з правила RewriteRule ^(app|views|...)/
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Db;
use App\Dict;
use App\Env;
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

$hours     = 24;
$threshold = date('Y-m-d H:i:s', time() - $hours * 3600);

// заявки в статусі "Нова", створені понад 24 год тому,
// про які ще не нагадували (або нагадували більше доби тому)
$rows = Db::all(
    'SELECT * FROM rma
     WHERE status = "new"
       AND created_at < ?
       AND (notified_stale_at IS NULL OR notified_stale_at < ?)
     ORDER BY created_at
     LIMIT 20',
    [$threshold, $threshold]
);

$sent = 0;
foreach ($rows as $rma) {
    if (Telegram::stale($rma, $hours)) {
        $sent++;
    }
    Db::update('rma', ['notified_stale_at' => date('Y-m-d H:i:s')], 'id = ?', [$rma['id']]);
}

// підсумок по заявках, що взагалі не рухаються (будь-який відкритий статус, > 48 год)
$open   = Dict::openStatuses();
$inList = implode(',', array_fill(0, count($open), '?'));
$frozen = (int)Db::value(
    'SELECT COUNT(*) FROM rma WHERE status IN (' . $inList . ') AND updated_at < ?',
    array_merge($open, [date('Y-m-d H:i:s', time() - 48 * 3600)])
);

$message = sprintf(
    '[%s] Перевірено: %d зависла(их) нових заявок, надіслано сповіщень: %d. Без руху >48 год: %d.',
    date('Y-m-d H:i:s'),
    count($rows),
    $sent,
    $frozen
);

@file_put_contents(BASE_PATH . '/storage/logs/cron.log', $message . PHP_EOL, FILE_APPEND);
echo $message . PHP_EOL;
