<?php
/**
 * Автонагадування клієнтам: повернення погоджено, але ТТН досі немає.
 * Через 2 дні після погодження надсилає клієнту SMS/Viber (і email),
 * а адміну — зведення в Telegram. Кожна заявка нагадується один раз.
 *
 * Запуск за розкладом (напр. щодня о 10:00):
 *   CLI:  php /path/to/cron/remind.php
 *   HTTP: https://returns.example.com/cron/remind.php?key=CRON_KEY
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Db;
use App\Env;
use App\Notify;
use App\Telegram;

const REMIND_AFTER_DAYS = 2;

// Запуск із cron може йти через CGI-бінарник PHP (не CLI): орієнтуємось на
// відсутність HTTP-запиту (немає REQUEST_METHOD), а не лише на PHP_SAPI.
$isCli = PHP_SAPI === 'cli' || !isset($_SERVER['REQUEST_METHOD']);
if (!$isCli) {
    $key = (string)($_GET['key'] ?? '');
    if ($key === '' || !hash_equals(Env::str('CRON_KEY'), $key)) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// Погоджені повернення без ТТН і без нашої накладної, старші за N днів,
// яким ще не надсилали нагадування.
$rows = Db::all(
    "SELECT * FROM rma
     WHERE status IN ('approved','waiting_customer_shipment')
       AND (return_ttn IS NULL OR return_ttn = '')
       AND (np_doc_ref IS NULL OR np_doc_ref = '')
       AND approved_at IS NOT NULL
       AND approved_at <= (NOW() - INTERVAL " . REMIND_AFTER_DAYS . " DAY)
       AND ttn_reminded_at IS NULL
     ORDER BY id
     LIMIT 100"
);

$reminded = [];
foreach ($rows as $rma) {
    try {
        Notify::remindTtn($rma);
    } catch (\Throwable $e) {
        error_log('remindTtn: ' . $e->getMessage());
    }
    Db::update('rma', ['ttn_reminded_at' => date('Y-m-d H:i:s')], 'id = ?', [(int)$rma['id']]);
    $reminded[] = [
        'rma_number' => (string)$rma['rma_number'],
        'customer'   => (string)($rma['customer_name'] ?? ''),
        'phone'      => (string)($rma['phone'] ?? ''),
    ];
    usleep(200000);
}

if ($reminded !== []) {
    try {
        Telegram::ttnReminderSummary($reminded);
    } catch (\Throwable $e) {
        error_log('Telegram: ' . $e->getMessage());
    }
}

$msg = sprintf('[%s] Нагадування про ТТН: надіслано %d.', date('Y-m-d H:i:s'), count($reminded));
@file_put_contents(BASE_PATH . '/storage/logs/cron.log', $msg . PHP_EOL, FILE_APPEND);
echo $msg . PHP_EOL;
