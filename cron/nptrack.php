<?php
/**
 * Автоматичний трекінг зворотних посилок Нової пошти.
 *
 * Оновлює статус заявок за рухом посилки: коли клієнт здав товар — in_transit,
 * коли магазин отримав — received (з автоматичним листом клієнту).
 *
 * Запуск за розкладом (кожні 30-60 хв):
 *   CLI:  php /path/to/cron/nptrack.php
 *   HTTP: https://returns.example.com/cron/nptrack.php?key=CRON_KEY
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Db;
use App\Env;
use App\NovaPoshta;
use App\Rma;

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $key = (string)($_GET['key'] ?? '');
    if ($key === '' || !hash_equals(Env::str('CRON_KEY'), $key)) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

if (!NovaPoshta::enabled()) {
    echo "Нова пошта вимкнена — трекінг пропущено.\n";
    exit;
}

// заявки з ТТН НП, які ще в дорозі (не отримані й не завершені)
$rows = Db::all(
    "SELECT id FROM rma
     WHERE carrier = 'novaposhta'
       AND return_ttn IS NOT NULL AND return_ttn <> ''
       AND status IN ('approved','waiting_customer_shipment','in_transit')
     ORDER BY id
     LIMIT 100"
);

$checked = 0;
$moved   = 0;
foreach ($rows as $row) {
    $rmaId  = (int)$row['id'];
    $before = (string)Db::value('SELECT status FROM rma WHERE id = ?', [$rmaId]);
    $r = Rma::refreshNpTracking($rmaId);
    $checked++;
    if ($r['ok']) {
        $after = (string)Db::value('SELECT status FROM rma WHERE id = ?', [$rmaId]);
        if ($after !== $before) {
            $moved++;
        }
    }
    usleep(200000);
}

// --- Легке повернення: клієнт міг оформити його сам у застосунку НП ---
// Перевіряємо заявки, де ще немає ТТН повернення, але є оригінальна ТТН
// замовлення, і які ще на етапі очікування відправки.
$lightRows = Db::all(
    "SELECT id FROM rma
     WHERE np_original_ttn IS NOT NULL AND np_original_ttn <> ''
       AND (return_ttn IS NULL OR return_ttn = '')
       AND status IN ('new','manager_review','approved','waiting_customer_shipment','need_more_info')
     ORDER BY id
     LIMIT 100"
);
$lightDetected = 0;
foreach ($lightRows as $row) {
    $res = Rma::checkLightReturn((int)$row['id']);
    if (!empty($res['detected'])) {
        $lightDetected++;
    }
    usleep(200000);
}

$msg = sprintf(
    '[%s] Трекінг НП: перевірено %d, оновлено статусів %d. Легке повернення: перевірено %d, виявлено %d.',
    date('Y-m-d H:i:s'), $checked, $moved, count($lightRows), $lightDetected
);
@file_put_contents(BASE_PATH . '/storage/logs/cron.log', $msg . PHP_EOL, FILE_APPEND);
echo $msg . PHP_EOL;
