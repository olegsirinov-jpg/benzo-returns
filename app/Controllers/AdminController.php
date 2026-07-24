<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Db;
use App\Dict;
use App\Response;
use App\Rma;
use App\Session;
use App\Supplier;
use App\Upload;
use App\Validate;
use App\View;

class AdminController
{
    const PER_PAGE = 30;

    public function __construct()
    {
        Auth::requireLogin();
        // одноразово докочуємо схему для наявної бази (нові таблиці/колонки)
        \App\Schema::ensure();
    }

    // ------------------------------------------------------------ список

    public function index(): void
    {
        list($where, $params) = $this->buildFilters($_GET);

        $page  = max(1, (int)($_GET['page'] ?? 1));
        $total = (int)Db::value('SELECT COUNT(*) FROM rma r WHERE ' . $where, $params);
        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        $page  = min($page, $pages);

        $sql = 'SELECT r.*, u.name AS manager_name,
                       (SELECT GROUP_CONCAT(i.name SEPARATOR ", ") FROM rma_items i WHERE i.rma_id = r.id) AS items_names,
                       (SELECT GROUP_CONCAT(i.sku SEPARATOR ", ") FROM rma_items i WHERE i.rma_id = r.id) AS items_skus
                FROM rma r
                LEFT JOIN users u ON u.id = r.manager_id
                WHERE ' . $where . '
                ORDER BY r.created_at DESC
                LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE);

        View::render('admin/list', [
            'title'    => 'Заявки на повернення',
            'rows'     => Db::all($sql, $params),
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'filters'  => $_GET,
            'managers' => Db::all('SELECT id, name FROM users WHERE active = 1 ORDER BY name'),
            'counts'   => $this->statusCounts(),
        ]);
    }

    /**
     * @param array<string,mixed> $q
     * @return array{0:string,1:array<int,mixed>}
     */
    private function buildFilters(array $q): array
    {
        $where  = ['1=1'];
        $params = [];

        $search = trim((string)($q['q'] ?? ''));
        if ($search !== '') {
            $phone = Validate::phone($search);
            $where[] = '(r.rma_number LIKE ? OR r.order_number LIKE ? OR r.phone LIKE ? OR r.customer_name LIKE ?
                         OR r.return_ttn LIKE ? OR EXISTS (SELECT 1 FROM rma_items i WHERE i.rma_id = r.id AND i.sku LIKE ?))';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $phone !== null ? '%' . $phone . '%' : $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $status = (string)($q['status'] ?? '');
        if ($status === 'open') {
            $open = Dict::openStatuses();
            $where[] = 'r.status IN (' . implode(',', array_fill(0, count($open), '?')) . ')';
            $params  = array_merge($params, $open);
        } elseif ($status !== '' && isset(Dict::statuses()[$status])) {
            $where[]  = 'r.status = ?';
            $params[] = $status;
        }

        $reason = (string)($q['reason'] ?? '');
        if ($reason !== '' && isset(Dict::reasons()[$reason])) {
            $where[]  = 'r.reason_code = ?';
            $params[] = $reason;
        }

        $action = (string)($q['action'] ?? '');
        if ($action !== '' && isset(Dict::actions()[$action])) {
            $where[]  = 'r.desired_action = ?';
            $params[] = $action;
        }

        $manager = (string)($q['manager'] ?? '');
        if ($manager === 'none') {
            $where[] = 'r.manager_id IS NULL';
        } elseif ($manager !== '' && ctype_digit($manager)) {
            $where[]  = 'r.manager_id = ?';
            $params[] = (int)$manager;
        }

        $supplier = (string)($q['supplier'] ?? '');
        if ($supplier !== '' && isset(Supplier::all()[$supplier])) {
            $where[]  = 'EXISTS (SELECT 1 FROM rma_items i WHERE i.rma_id = r.id AND i.supplier = ?)';
            $params[] = $supplier;
        }

        $from = (string)($q['from'] ?? '');
        if ($from !== '' && strtotime($from) !== false) {
            $where[]  = 'r.created_at >= ?';
            $params[] = date('Y-m-d 00:00:00', (int)strtotime($from));
        }
        $to = (string)($q['to'] ?? '');
        if ($to !== '' && strtotime($to) !== false) {
            $where[]  = 'r.created_at <= ?';
            $params[] = date('Y-m-d 23:59:59', (int)strtotime($to));
        }

        if (!empty($q['manual'])) {
            $where[] = 'r.needs_manual_check = 1';
        }
        if (!empty($q['no_ttn'])) {
            $where[] = '(r.return_ttn IS NULL OR r.return_ttn = "")';
        }

        return [implode(' AND ', $where), $params];
    }

    /** @return array<string,int> */
    private function statusCounts(): array
    {
        $rows = Db::all('SELECT status, COUNT(*) AS c FROM rma GROUP BY status');
        $out  = [];
        foreach ($rows as $r) {
            $out[(string)$r['status']] = (int)$r['c'];
        }
        return $out;
    }

    // ------------------------------------------------------------ картка

    public function show(string $id): void
    {
        $rmaId = (int)$id;
        $rma   = Rma::find($rmaId);
        if ($rma === null) {
            Response::status(404);
            View::render('errors/404', ['title' => 'Заявку не знайдено']);
            return;
        }

        // менеджер відкрив нову заявку -> "Очікує перевірки менеджером" (п.10.1 ТЗ)
        if ((string)$rma['status'] === 'new') {
            Rma::setStatus($rmaId, 'manager_review');
            if (empty($rma['manager_id'])) {
                Db::update('rma', ['manager_id' => Auth::id()], 'id = ?', [$rmaId]);
            }
            $rma = Rma::find($rmaId);
        }

        View::render('admin/card', [
            'title'    => 'Заявка ' . $rma['rma_number'],
            'rma'      => $rma,
            'items'    => Rma::items($rmaId),
            'photos'   => Rma::photos($rmaId),
            'history'  => Rma::history($rmaId),
            'comments' => Rma::comments($rmaId),
            'managers' => Db::all('SELECT id, name FROM users WHERE active = 1 ORDER BY name'),
            'expired'  => Rma::isExpired($rma),
            'days'     => Rma::daysSinceOrder($rma),
            'notifications' => Db::all('SELECT * FROM notifications WHERE rma_id = ? ORDER BY id DESC', [$rmaId]),
            'smsEnabled'    => \App\TurboSms::enabled(),
            'smsTemplates'  => \App\Notify::smsTemplates($rma),
            'npReady'       => \App\NovaPoshta::ready(),
        ]);
    }

    /**
     * Збереження полів картки (реквізити, доставка, суми).
     */
    public function save(string $id): void
    {
        Csrf::verify();
        $rmaId = (int)$id;
        $rma   = Rma::find($rmaId);
        if ($rma === null) {
            Response::redirect('/admin');
            return;
        }

        $data = [];

        // доставка
        $ttn = trim((string)($_POST['return_ttn'] ?? ''));
        $data['return_ttn'] = $ttn === '' ? null : Validate::ttn($ttn);

        $carrier = (string)($_POST['carrier'] ?? '');
        $data['carrier'] = isset(Dict::carriers()[$carrier]) ? $carrier : null;

        $payer = (string)($_POST['shipping_payer'] ?? '');
        $data['shipping_payer'] = isset(Dict::shippingPayers()[$payer]) ? $payer : null;

        $data['shipped_at']       = $this->dateOrNull((string)($_POST['shipped_at'] ?? ''));
        $data['received_at']      = $this->dateOrNull((string)($_POST['received_at'] ?? ''));
        $data['shipping_comment'] = Validate::text((string)($_POST['shipping_comment'] ?? ''), 1000) ?: null;

        // реквізити / суми
        $data['refund_name']   = Validate::text((string)($_POST['refund_name'] ?? ''), 190) ?: null;
        $iban                  = trim((string)($_POST['refund_iban'] ?? ''));
        $data['refund_iban']   = $iban === '' ? null : Validate::iban($iban);
        $data['refund_tax_id'] = Validate::taxId((string)($_POST['refund_tax_id'] ?? ''));
        $data['refund_bank']   = Validate::text((string)($_POST['refund_bank'] ?? ''), 190) ?: null;

        $amount = str_replace([' ', ','], ['', '.'], (string)($_POST['refund_amount'] ?? ''));
        $data['refund_amount'] = is_numeric($amount) ? round((float)$amount, 2) : null;

        // менеджер
        $manager = (string)($_POST['manager_id'] ?? '');
        $data['manager_id'] = ctype_digit($manager) && (int)$manager > 0 ? (int)$manager : null;

        // бажана дія — менеджер може уточнити (напр. «ще не знаю» -> «повернути кошти»)
        $action = (string)($_POST['desired_action'] ?? '');
        if (isset(Dict::actions()[$action])) {
            $data['desired_action'] = $action;
        }
        $data['exchange_wish'] = Validate::text((string)($_POST['exchange_wish'] ?? ''), 2000) ?: null;

        if ($iban !== '' && $data['refund_iban'] === null) {
            Session::flash('error', 'IBAN некоректний — поле не збережено. Перевірте формат UA…');
            unset($data['refund_iban']);
        }

        $data['updated_at'] = date('Y-m-d H:i:s');
        Db::update('rma', $data, 'id = ?', [$rmaId]);

        // журналюємо зміни
        $labels = [
            'return_ttn'     => 'ТТН повернення',
            'carrier'        => 'Перевізник',
            'shipping_payer' => 'Хто оплачує доставку',
            'received_at'    => 'Дата отримання',
            'refund_amount'  => 'Сума до повернення',
            'refund_iban'    => 'IBAN',
            'manager_id'     => 'Відповідальний менеджер',
            'desired_action' => 'Бажана дія',
        ];
        // перекладаємо коди у людські назви для журналу
        $fmt = function (string $field, string $val): string {
            if ($val === '') {
                return '—';
            }
            switch ($field) {
                case 'desired_action':
                    return Dict::action($val);
                case 'shipping_payer':
                    return Dict::shippingPayers()[$val] ?? $val;
                case 'carrier':
                    return Dict::carriers()[$val] ?? $val;
                case 'manager_id':
                    $name = Db::value('SELECT name FROM users WHERE id = ?', [(int)$val]);
                    return $name !== null ? (string)$name : $val;
                default:
                    return $val;
            }
        };
        foreach ($labels as $field => $label) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $old = (string)($rma[$field] ?? '');
            $new = (string)($data[$field] ?? '');
            if ($old !== $new) {
                Rma::log($rmaId, $label, $fmt($field, $old), $fmt($field, $new));
            }
        }

        Session::flash('success', 'Зміни збережено.');
        Response::redirect('/admin/rma/' . $rmaId);
    }

    /**
     * Зміна статусу через кнопки дій.
     */
    public function changeStatus(string $id): void
    {
        Csrf::verify();
        $rmaId = (int)$id;
        $rma   = Rma::find($rmaId);
        if ($rma === null) {
            Response::redirect('/admin');
            return;
        }

        $status  = (string)($_POST['status'] ?? '');
        $comment = Validate::text((string)($_POST['comment'] ?? ''), 2000);

        if (!isset(Dict::statuses()[$status])) {
            Session::flash('error', 'Невідомий статус.');
            Response::redirect('/admin/rma/' . $rmaId);
        }

        // відмова вимагає причини й коментаря для клієнта
        if ($status === 'rejected') {
            $reject = (string)($_POST['reject_reason'] ?? '');
            if (!isset(Dict::rejectReasons()[$reject])) {
                Session::flash('error', 'Оберіть причину відмови.');
                Response::redirect('/admin/rma/' . $rmaId);
            }
            if ($comment === '') {
                Session::flash('error', 'Для відмови обов’язковий коментар для клієнта.');
                Response::redirect('/admin/rma/' . $rmaId);
            }
            Db::update('rma', ['reject_reason' => $reject], 'id = ?', [$rmaId]);
            Rma::log($rmaId, 'Причина відмови', null, Dict::rejectReasons()[$reject]);
        }

        // фіксуємо дати
        if ($status === 'received' && empty($rma['received_at'])) {
            Db::update('rma', ['received_at' => date('Y-m-d')], 'id = ?', [$rmaId]);
        }
        if ($status === 'refunded' && empty($rma['refund_paid_at'])) {
            Db::update('rma', ['refund_paid_at' => date('Y-m-d H:i:s')], 'id = ?', [$rmaId]);
        }

        // хто відкрив — той і відповідальний, якщо ще не призначено
        if (empty($rma['manager_id'])) {
            Db::update('rma', ['manager_id' => Auth::id()], 'id = ?', [$rmaId]);
        }

        Rma::setStatus($rmaId, $status, $comment !== '' ? $comment : null);

        Session::flash('success', 'Статус змінено: ' . Dict::status($status));
        Response::redirect('/admin/rma/' . $rmaId);
    }

    public function addComment(string $id): void
    {
        Csrf::verify();
        $rmaId = (int)$id;
        if (Rma::find($rmaId) === null) {
            Response::redirect('/admin');
            return;
        }

        $text = Validate::text((string)($_POST['text'] ?? ''), 3000);
        $type = (string)($_POST['type'] ?? 'internal');
        if (!isset(Dict::commentTypes()[$type])) {
            $type = 'internal';
        }
        if ($text === '') {
            Session::flash('error', 'Коментар порожній.');
            Response::redirect('/admin/rma/' . $rmaId);
        }

        Rma::comment($rmaId, $text, $type);
        if ($type === 'client') {
            Db::update('rma', ['client_message' => $text, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$rmaId]);
        }
        Rma::log($rmaId, 'Коментар', null, Dict::commentTypes()[$type], str_limit($text, 100));

        Session::flash('success', 'Коментар додано.');
        Response::redirect('/admin/rma/' . $rmaId);
    }

    public function addPhoto(string $id): void
    {
        Csrf::verify();
        $rmaId = (int)$id;
        if (Rma::find($rmaId) === null) {
            Response::redirect('/admin');
            return;
        }

        $files = Upload::normalize($_FILES['photos'] ?? []);
        if ($files === []) {
            Session::flash('error', 'Файли не обрано.');
            Response::redirect('/admin/rma/' . $rmaId);
        }

        $type = (string)($_POST['type'] ?? 'other');
        if (!isset(Dict::photoTypes()[$type])) {
            $type = 'other';
        }

        $saved = 0;
        foreach ($files as $file) {
            try {
                $stored = Upload::saveImage($file);
                Db::insert('rma_photos', [
                    'rma_id'      => $rmaId,
                    'type'        => $type,
                    'file'        => $stored,
                    'orig_name'   => mb_substr($file['name'], 0, 255),
                    'size'        => $file['size'],
                    'uploaded_by' => 'manager',
                    'created_at'  => date('Y-m-d H:i:s'),
                ]);
                $saved++;
            } catch (\Throwable $e) {
                Session::flash('error', $e->getMessage());
            }
        }

        if ($saved > 0) {
            Rma::log($rmaId, 'Фото', null, 'Додано ' . $saved . ' шт.');
            Session::flash('success', 'Додано фото: ' . $saved . '.');
        }
        Response::redirect('/admin/rma/' . $rmaId);
    }

    public function delete(string $id): void
    {
        Csrf::verify();
        Auth::requireAdmin();

        $rmaId = (int)$id;
        $rma   = Rma::find($rmaId);
        if ($rma === null) {
            Response::redirect('/admin');
            return;
        }

        foreach (Rma::photos($rmaId) as $p) {
            Upload::delete((string)$p['file']);
        }
        Db::run('DELETE FROM rma WHERE id = ?', [$rmaId]);

        Session::flash('success', 'Заявку ' . $rma['rma_number'] . ' видалено.');
        Response::redirect('/admin');
    }

    // ------------------------------------------------------------ експорт

    public function export(): void
    {
        list($where, $params) = $this->buildFilters($_GET);

        $rows = Db::all(
            'SELECT r.*, u.name AS manager_name
             FROM rma r LEFT JOIN users u ON u.id = r.manager_id
             WHERE ' . $where . ' ORDER BY r.created_at DESC LIMIT 5000',
            $params
        );

        $out = fopen('php://temp', 'r+');
        // BOM, щоб Excel коректно відкрив UTF-8
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            'Номер заявки', 'Дата', 'Номер замовлення', 'Телефон', 'ПІБ', 'Артикул', 'Товар',
            'Кількість', 'Ціна', 'Причина', 'Бажана дія', 'Статус', 'Сума повернення',
            'ТТН', 'Менеджер', 'Постачальник', 'Коментар',
        ], ';');

        foreach ($rows as $r) {
            $items = Rma::items((int)$r['id']);
            if ($items === []) {
                $items = [['name' => '', 'sku' => '', 'qty' => '', 'price' => '', 'supplier' => '']];
            }
            foreach ($items as $it) {
                fputcsv($out, [
                    $r['rma_number'],
                    dt((string)$r['created_at'], 'd.m.Y H:i'),
                    $r['order_number'],
                    '+' . $r['phone'],
                    $r['customer_name'],
                    $it['sku'],
                    $it['name'],
                    $it['qty'],
                    $it['price'],
                    Dict::reason((string)$r['reason_code']),
                    Dict::action((string)$r['desired_action']),
                    Dict::status((string)$r['status']),
                    $r['refund_amount'],
                    $r['return_ttn'],
                    $r['manager_name'],
                    Supplier::name((string)($it['supplier'] ?? '')),
                    $r['reason_details'],
                ], ';');
            }
        }

        rewind($out);
        $csv = (string)stream_get_contents($out);
        fclose($out);

        Response::download('rma-' . date('Y-m-d') . '.csv', $csv);
    }

    // ------------------------------------------------------------ діагностика SalesDrive

    public function diag(): void
    {
        Auth::requireAdmin();

        $result = null;
        $mapped = null;
        $raw    = null;
        $probes = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verify();

            $mode = (string)($_POST['mode'] ?? 'ping');

            if ($mode === 'clear') {
                $n = \App\SalesDrive::clearCache();
                Session::flash('success', 'Кеш пошуку очищено (' . $n . ' записів). Тепер запити йдуть у SalesDrive наживо.');
                Response::redirect('/admin/diag');
                return;
            }

            if ($mode === 'mapjson') {
                $json    = (string)($_POST['json'] ?? '');
                $decoded = json_decode($json, true);

                if (!is_array($decoded)) {
                    Session::flash('error', 'Це не схоже на JSON: ' . json_last_error_msg());
                } else {
                    // приймаємо або повну відповідь {status,data:[...]}, або одну заявку
                    if (isset($decoded['data'][0]) && is_array($decoded['data'][0])) {
                        $raw = $decoded['data'][0];
                    } elseif (isset($decoded['id']) || isset($decoded['products'])) {
                        $raw = $decoded;
                    } else {
                        Session::flash('error', 'У JSON не знайдено заявки (очікую data[0] або обʼєкт заявки).');
                    }
                    if ($raw !== null) {
                        $mapped = \App\SalesDrive::diagMap($raw);
                    }
                }
            }

            if ($mode === 'probe') {
                $probes = \App\SalesDrive::diagProbe(
                    trim((string)($_POST['order_number'] ?? '')),
                    trim((string)($_POST['phone'] ?? ''))
                );
                foreach ($probes as $p) {
                    if ($p['sample'] !== null && $raw === null) {
                        $raw    = $p['sample'];
                        $mapped = \App\SalesDrive::diagMap($raw);
                    }
                }
            } elseif ($mode === 'ping') {
                // останнє замовлення — перевіряємо домен і ключ
                $result = \App\SalesDrive::diagList(
                    [
                        'orderTime' => ['from' => date('Y-m-d', strtotime('-30 days'))],
                        'statusId'  => '__ALL__',
                    ],
                    1
                );
            }

            if ($result !== null
                && is_array($result['data'])
                && isset($result['data']['data'][0])
                && is_array($result['data']['data'][0])
            ) {
                $raw    = $result['data']['data'][0];
                $mapped = \App\SalesDrive::diagMap($raw);
            }
        }

        View::render('admin/diag', [
            'title'  => 'Діагностика SalesDrive',
            'result' => $result,
            'raw'    => $raw,
            'mapped' => $mapped,
            'probes' => $probes,
        ]);
    }

    // ------------------------------------------------------------ діагностика Нової пошти

    public function npDiag(): void
    {
        Auth::requireAdmin();

        $senders = null;
        $result  = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verify();
            $mode = (string)($_POST['mode'] ?? 'ttn');

            if ($mode === 'senders') {
                // перевірка готовності акаунтів відправляти
                $senders = [];
                foreach (\App\NovaPoshta::keys() as $k) {
                    $info = \App\NovaPoshta::senderInfo($k['key']);
                    $senders[] = ['idx' => $k['idx'], 'info' => $info];
                }
            } elseif ($mode === 'testcreate') {
                $result = ['testcreate' => $this->npTestCreate()];
            } else {
                $ttn = trim((string)($_POST['ttn'] ?? ''));
                $result = ['ttn' => $ttn, 'keys' => [], 'winner' => 0];
                foreach (\App\NovaPoshta::keys() as $k) {
                    $r = \App\NovaPoshta::checkReturn($k['key'], $ttn);
                    $possible = $r['success'] && $r['data'] !== [];
                    if ($possible && $result['winner'] === 0) {
                        $result['winner'] = $k['idx'];
                    }
                    $result['keys'][] = [
                        'idx'      => $k['idx'],
                        'http'     => $r['http'],
                        'success'  => $r['success'],
                        'possible' => $possible,
                        'errors'   => $r['errors'],
                        'data'     => $r['data'],
                        'raw'      => $r['raw'],
                    ];
                }
            }
        }

        View::render('admin/np_diag', [
            'title'    => 'Діагностика Нової пошти',
            'result'   => $result,
            'senders'  => $senders,
            'keyCount' => count(\App\NovaPoshta::keys()),
        ]);
    }

    /**
     * Тестове створення зворотної накладної + одразу видалення.
     * Мета — зловити точні поля InternetDocument.save для цього акаунта.
     *
     * @return array<string,mixed>
     */
    private function npTestCreate(): array
    {
        $np  = '\App\NovaPoshta';
        $key = \App\NovaPoshta::recipientKey();
        $out = ['steps' => [], 'ok' => false];

        if ($key === '') {
            $out['steps'][] = ['name' => 'Ключ', 'ok' => false, 'note' => 'Не задано ключ отримувача повернень'];
            return $out;
        }

        $clientCity = trim((string)($_POST['client_city_ref'] ?? ''));
        $clientWh   = trim((string)($_POST['client_wh_ref'] ?? ''));
        $shopCity   = \App\Config::str('np_recipient_city_ref');
        $shopWh     = \App\Config::str('np_recipient_wh_ref');

        if ($clientCity === '' || $clientWh === '') {
            $out['steps'][] = ['name' => 'Адреса клієнта', 'ok' => false, 'note' => 'Оберіть тестове місто й відділення клієнта'];
            return $out;
        }
        if ($shopCity === '' || $shopWh === '') {
            $out['steps'][] = ['name' => 'Точка прийому', 'ok' => false, 'note' => 'Не налаштовано відділення прийому у Налаштуваннях'];
            return $out;
        }

        // 1. контрагенти
        $sender = \App\NovaPoshta::partyInfo($key, 'Sender');
        $recip  = \App\NovaPoshta::recipientParty($key);
        $recip['counterparty'] = ['Description' => 'Отримувач повернень (наш ФОП)'];
        $out['steps'][] = ['name' => 'Відправник (контрагент+контакт)', 'ok' => $sender['ref'] !== '' && $sender['contactRef'] !== '', 'note' => $sender['ref'] === '' ? $sender['error'] : ($sender['counterparty']['Description'] ?? '') . ' / тел ' . $sender['phone'], 'raw' => ['ref' => $sender['ref'], 'contactRef' => $sender['contactRef'], 'phone' => $sender['phone']]];
        $out['steps'][] = ['name' => 'Отримувач (контрагент+контакт)', 'ok' => $recip['ref'] !== '' && $recip['contactRef'] !== '', 'note' => $recip['ref'] === '' ? ('Recipient: ' . $recip['error']) : ($recip['counterparty']['Description'] ?? '') . ' / тел ' . $recip['phone'], 'raw' => ['ref' => $recip['ref'], 'contactRef' => $recip['contactRef'], 'phone' => $recip['phone']]];

        // 2. ціна
        $price = \App\NovaPoshta::documentPrice($key, $clientCity, $shopCity);
        $priceVal = is_array($price['data'][0] ?? null) ? ($price['data'][0]['Cost'] ?? null) : null;
        $out['steps'][] = ['name' => 'Розрахунок ціни (getDocumentPrice)', 'ok' => $price['success'], 'note' => $price['success'] ? ('Вартість: ' . $priceVal . ' грн') : implode('; ', $price['errors']), 'raw' => $price['data']];

        if ($sender['ref'] === '' || $recip['ref'] === '') {
            $out['note'] = 'Бракує контрагентів — накладну не створюємо. Дивіться кроки вище.';
            return $out;
        }

        // 3. створення
        $props = [
            'PayerType'        => 'Recipient',
            'PaymentMethod'    => 'Cash',
            'DateTime'         => date('d.m.Y'),
            'CargoType'        => 'Parcel',
            'Weight'           => \App\Config::str('np_weight', '0.5'),
            'ServiceType'      => \App\Config::str('np_service_type', 'WarehouseWarehouse'),
            'SeatsAmount'      => '1',
            'Description'      => 'Тест повернення (видалити)',
            'Cost'             => '300',
            'CitySender'       => $clientCity,
            'Sender'           => $sender['ref'],
            'SenderAddress'    => $clientWh,
            'ContactSender'    => $sender['contactRef'],
            'SendersPhone'     => $sender['phone'],
            'CityRecipient'    => $shopCity,
            'Recipient'        => $recip['ref'],
            'RecipientAddress' => $shopWh,
            'ContactRecipient' => $recip['contactRef'],
            'RecipientsPhone'  => $recip['phone'],
        ];
        $create = \App\NovaPoshta::createDocument($key, $props);
        $doc = is_array($create['data'][0] ?? null) ? $create['data'][0] : [];
        $ttn = (string)($doc['IntDocNumber'] ?? '');
        $ref = (string)($doc['Ref'] ?? '');

        $out['request'] = $props;
        $out['steps'][] = ['name' => 'Створення накладної (save)', 'ok' => $create['success'], 'note' => $create['success'] ? ('ТТН: ' . $ttn) : implode('; ', $create['errors']), 'raw' => $create['raw']];

        // 4. одразу видаляємо тестову накладну
        if ($create['success'] && $ref !== '') {
            $del = \App\NovaPoshta::deleteDocument($key, $ref);
            $out['steps'][] = ['name' => 'Видалення тестової накладної', 'ok' => $del['success'], 'note' => $del['success'] ? 'видалено' : implode('; ', $del['errors']), 'raw' => $del['raw']];
        }

        $out['ok'] = $create['success'];
        return $out;
    }

    /**
     * AJAX: пошук відділень НП за назвою міста (для налаштувань).
     */
    public function npWarehouses(): void
    {
        Auth::requireAdmin();

        $key = \App\NovaPoshta::anyKey();
        if ($key === '') {
            Response::json(['ok' => false, 'error' => 'Немає ключа НП'], 400);
        }

        $cityQuery = trim((string)($_GET['city'] ?? ''));
        if (mb_strlen($cityQuery) < 2) {
            Response::json(['ok' => true, 'cities' => []]);
        }

        $cr = \App\NovaPoshta::cities($key, $cityQuery);
        $cities = [];
        foreach (array_slice($cr['data'], 0, 8) as $c) {
            if (!is_array($c)) {
                continue;
            }
            $ref  = (string)($c['Ref'] ?? '');
            $name = (string)($c['Description'] ?? '');
            $area = (string)($c['AreaDescription'] ?? '');
            if ($ref === '') {
                continue;
            }
            $whRes = \App\NovaPoshta::warehouses($key, $ref);
            $whs = [];
            foreach ($whRes['data'] as $w) {
                if (!is_array($w)) {
                    continue;
                }
                $whs[] = [
                    'ref'  => (string)($w['Ref'] ?? ''),
                    'name' => (string)($w['Description'] ?? ''),
                ];
            }
            $cities[] = [
                'ref'  => $ref,
                'name' => $name . ($area !== '' ? ' (' . $area . ' обл.)' : ''),
                'warehouses' => $whs,
            ];
        }

        Response::json(['ok' => true, 'cities' => $cities]);
    }

    // ------------------------------------------------------------ статистика

    public function stats(): void
    {
        $from = (string)($_GET['from'] ?? date('Y-m-01'));
        $to   = (string)($_GET['to'] ?? date('Y-m-d'));

        $range  = [date('Y-m-d 00:00:00', (int)strtotime($from)), date('Y-m-d 23:59:59', (int)strtotime($to))];
        $period = 'created_at BETWEEN ? AND ?';

        $total = (int)Db::value('SELECT COUNT(*) FROM rma WHERE ' . $period, $range);

        $byStatus = [];
        foreach (Db::all('SELECT status, COUNT(*) c FROM rma WHERE ' . $period . ' GROUP BY status', $range) as $r) {
            $byStatus[(string)$r['status']] = (int)$r['c'];
        }

        $approved = ($byStatus['approved'] ?? 0) + ($byStatus['refund_approved'] ?? 0)
                  + ($byStatus['refund_pending'] ?? 0) + ($byStatus['refunded'] ?? 0)
                  + ($byStatus['received'] ?? 0) + ($byStatus['inspection'] ?? 0)
                  + ($byStatus['in_transit'] ?? 0) + ($byStatus['waiting_customer_shipment'] ?? 0);
        $rejected  = $byStatus['rejected'] ?? 0;
        $exchanges = ($byStatus['exchange_pending'] ?? 0) + ($byStatus['exchange_sent'] ?? 0);

        $refundSum = (float)Db::value(
            'SELECT COALESCE(SUM(refund_amount), 0) FROM rma WHERE status = "refunded" AND ' . $period,
            $range
        );

        // середній час обробки: від створення до першої зміни статусу
        $avgHours = (float)Db::value(
            'SELECT AVG(TIMESTAMPDIFF(HOUR, r.created_at, h.first_change))
             FROM rma r
             JOIN (SELECT rma_id, MIN(created_at) first_change FROM rma_history WHERE field = "status" GROUP BY rma_id) h
               ON h.rma_id = r.id
             WHERE r.' . $period,
            $range
        );

        $open   = Dict::openStatuses();
        $inList = implode(',', array_fill(0, count($open), '?'));
        $stale  = (int)Db::value(
            'SELECT COUNT(*) FROM rma WHERE status IN (' . $inList . ') AND updated_at < ?',
            array_merge($open, [date('Y-m-d H:i:s', time() - 172800)])
        );

        View::render('admin/stats', [
            'title'     => 'Статистика повернень',
            'from'      => $from,
            'to'        => $to,
            'total'     => $total,
            'approved'  => $approved,
            'rejected'  => $rejected,
            'exchanges' => $exchanges,
            'refundSum' => $refundSum,
            'avgHours'  => $avgHours,
            'stale'     => $stale,
            'byStatus'  => $byStatus,
            'topReasons' => Db::all(
                'SELECT reason_code, COUNT(*) c FROM rma WHERE ' . $period . ' GROUP BY reason_code ORDER BY c DESC',
                $range
            ),
            'topItems' => Db::all(
                'SELECT i.name, COUNT(*) c FROM rma_items i JOIN rma r ON r.id = i.rma_id
                 WHERE r.' . $period . ' GROUP BY i.name ORDER BY c DESC LIMIT 10',
                $range
            ),
            'topSkus' => Db::all(
                'SELECT i.sku, i.name, COUNT(*) c FROM rma_items i JOIN rma r ON r.id = i.rma_id
                 WHERE r.' . $period . ' AND i.sku IS NOT NULL AND i.sku <> ""
                 GROUP BY i.sku, i.name ORDER BY c DESC LIMIT 10',
                $range
            ),
            'bySupplier' => Db::all(
                'SELECT i.supplier, COUNT(*) c FROM rma_items i JOIN rma r ON r.id = i.rma_id
                 WHERE r.' . $period . ' GROUP BY i.supplier ORDER BY c DESC',
                $range
            ),
            'byManager' => Db::all(
                'SELECT u.name, COUNT(*) c FROM rma r JOIN users u ON u.id = r.manager_id
                 WHERE r.' . $period . ' GROUP BY u.name ORDER BY c DESC',
                $range
            ),
        ]);
    }

    // ------------------------------------------------------------ користувачі

    public function users(): void
    {
        Auth::requireAdmin();
        View::render('admin/users', [
            'title' => 'Користувачі',
            'users' => Db::all('SELECT * FROM users ORDER BY role, name'),
        ]);
    }

    public function saveUser(): void
    {
        Csrf::verify();
        Auth::requireAdmin();

        $action = (string)($_POST['action'] ?? 'create');

        if ($action === 'toggle') {
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId === Auth::id()) {
                Session::flash('error', 'Не можна деактивувати власний обліковий запис.');
                Response::redirect('/admin/users');
            }
            Db::run('UPDATE users SET active = 1 - active WHERE id = ?', [$userId]);
            Session::flash('success', 'Статус користувача змінено.');
            Response::redirect('/admin/users');
        }

        if ($action === 'password') {
            $userId   = (int)($_POST['user_id'] ?? 0);
            $password = (string)($_POST['password'] ?? '');
            if (mb_strlen($password) < 8) {
                Session::flash('error', 'Пароль має бути щонайменше 8 символів.');
                Response::redirect('/admin/users');
            }
            Db::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$userId]);
            Session::flash('success', 'Пароль оновлено.');
            Response::redirect('/admin/users');
        }

        $name     = Validate::text((string)($_POST['name'] ?? ''), 100);
        $email    = Validate::email((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $role     = (string)($_POST['role'] ?? 'manager') === 'admin' ? 'admin' : 'manager';

        if ($name === '' || $email === null || mb_strlen($password) < 8) {
            Session::flash('error', 'Заповніть ПІБ, коректний email і пароль від 8 символів.');
            Response::redirect('/admin/users');
        }
        if (Db::one('SELECT id FROM users WHERE email = ?', [$email]) !== null) {
            Session::flash('error', 'Користувач з таким email вже існує.');
            Response::redirect('/admin/users');
        }

        Db::insert('users', [
            'name'          => $name,
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role'          => $role,
            'active'        => 1,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        Session::flash('success', 'Користувача створено.');
        Response::redirect('/admin/users');
    }

    // ------------------------------------------------------------ налаштування сповіщень

    public function settings(): void
    {
        Auth::requireAdmin();
        View::render('admin/settings', [
            'title' => 'Налаштування сповіщень',
        ]);
    }

    public function saveSettings(): void
    {
        Csrf::verify();
        Auth::requireAdmin();

        $values = [];
        foreach (\App\Config::schema() as $key => $def) {
            // булеві — це чекбокси: приходять лише коли ввімкнені,
            // тож завжди виставляємо 1/0 (щоб можна було й вимкнути)
            if (($def['type'] ?? '') === 'bool') {
                $values[$key] = !empty($_POST[$key]) ? '1' : '0';
            } elseif (array_key_exists($key, $_POST)) {
                $values[$key] = (string)$_POST[$key];
            }
        }

        \App\Config::save($values);
        Session::flash('success', 'Налаштування збережено.');
        Response::redirect('/admin/settings');
    }

    /**
     * Тестова відправка — email на пошту адміна або SMS на вказаний номер.
     */
    public function testNotify(): void
    {
        Csrf::verify();
        Auth::requireAdmin();

        $type = (string)($_POST['type'] ?? '');

        if ($type === 'email') {
            $to = \App\Validate::email((string)($_POST['to'] ?? ''));
            if ($to === null) {
                $to = (string)(Auth::user()['email'] ?? '');
            }
            if ($to === '') {
                Session::flash('error', 'Вкажіть адресу для тестового листа.');
                Response::redirect('/admin/settings');
            }
            $r = \App\Mailer::send(
                $to,
                Auth::name(),
                'Тестовий лист — ' . Env::str('APP_NAME', 'повернення'),
                '<p>Це тестовий лист із системи повернень. Якщо ви його бачите — SMTP налаштовано правильно.</p>'
            );
            $r['ok']
                ? Session::flash('success', 'Тестовий лист надіслано на ' . $to . '. Перевірте пошту (і спам).')
                : Session::flash('error', 'Лист не надіслано: ' . $r['error']);
            Response::redirect('/admin/settings');
        }

        if ($type === 'sms') {
            $phone = \App\Validate::phone((string)($_POST['to'] ?? ''));
            if ($phone === null) {
                Session::flash('error', 'Вкажіть коректний номер для тестової SMS.');
                Response::redirect('/admin/settings');
            }
            $r = \App\TurboSms::send($phone, 'Тестове повідомлення із системи повернень. Налаштування працюють.');
            $r['ok']
                ? Session::flash('success', 'Тестове повідомлення надіслано на ' . \App\Validate::phoneFormat($phone) . '.')
                : Session::flash('error', 'SMS не надіслано: ' . $r['error']);
            Response::redirect('/admin/settings');
        }

        if ($type === 'telegram') {
            $r = \App\Telegram::sendTest();
            $r['ok']
                ? Session::flash('success', 'Тестове повідомлення надіслано в Telegram. Перевірте чат.')
                : Session::flash('error', $r['error']);
            Response::redirect('/admin/settings');
        }

        Response::redirect('/admin/settings');
    }

    // ------------------------------------------------------------ створення заявки менеджером

    public function createForm(): void
    {
        Session::forget('_admin_lookup');
        View::render('admin/rma_new', [
            'title'       => 'Нова заявка на повернення',
            'reasons'     => Dict::reasons(),
            'actions'     => Dict::actions(),
            'needDetails' => Dict::reasonsRequiringDetails(),
            'errors'      => Session::pull('_errors', []),
        ]);
        Session::forget('_old');
    }

    /** AJAX: пошук замовлення в SalesDrive для менеджерської форми. */
    public function lookupOrder(): void
    {
        Csrf::verify();

        $orderNumber = Validate::orderNumber((string)($_POST['order_number'] ?? ''));
        $phone       = Validate::phone((string)($_POST['phone'] ?? ''));
        if ($orderNumber === null || $phone === null) {
            Response::json(['ok' => false, 'error' => 'Вкажіть номер замовлення й телефон.'], 422);
        }

        $result = \App\SalesDrive::findOrder($orderNumber, $phone);
        if (!empty($result['found'])) {
            $order = $result['order'];
            Session::set('_admin_lookup', [
                'order_number' => $orderNumber,
                'phone'        => $phone,
                'order'        => $order,
                'at'           => time(),
            ]);
            Response::json([
                'ok'    => true,
                'found' => true,
                'order' => [
                    'number'   => $order['order_number'],
                    'date'     => $order['order_date'],
                    'customer' => $order['customer_name'],
                    'email'    => $order['email'],
                    'items'    => $order['items'],
                ],
            ]);
        }

        Session::set('_admin_lookup', [
            'order_number' => $orderNumber,
            'phone'        => $phone,
            'order'        => null,
            'at'           => time(),
        ]);
        Response::json([
            'ok'    => true,
            'found' => false,
            'message' => 'Замовлення не знайдено в SalesDrive. Можна створити заявку вручну.',
        ]);
    }

    public function createSubmit(): void
    {
        Csrf::verify();

        $errors = [];
        $post   = $_POST;

        $orderNumber = Validate::orderNumber((string)($post['order_number'] ?? ''));
        if ($orderNumber === null) {
            $errors['order_number'] = 'Вкажіть номер замовлення.';
        }
        $phone = Validate::phone((string)($post['phone'] ?? ''));
        if ($phone === null) {
            $errors['phone'] = 'Вкажіть коректний телефон.';
        }

        $reason = (string)($post['reason_code'] ?? '');
        if (!Validate::inDict($reason, Dict::reasons())) {
            $errors['reason_code'] = 'Оберіть причину.';
        }
        $action = (string)($post['desired_action'] ?? '');
        if (!Validate::inDict($action, Dict::actions())) {
            $errors['desired_action'] = 'Оберіть бажану дію.';
        }

        $items = $this->adminCollectItems($post, $errors);

        // реквізити — лише якщо повернення коштів
        $refund = ['name' => null, 'iban' => null, 'tax' => null, 'bank' => null];
        if ($action === 'refund') {
            $refund['name'] = Validate::text((string)($post['refund_name'] ?? ''), 190) ?: null;
            $iban = trim((string)($post['refund_iban'] ?? ''));
            $refund['iban'] = $iban === '' ? null : Validate::iban($iban);
            if ($iban !== '' && $refund['iban'] === null) {
                $errors['refund_iban'] = 'IBAN некоректний.';
            }
            $refund['tax']  = Validate::taxId((string)($post['refund_tax_id'] ?? ''));
            $refund['bank'] = Validate::text((string)($post['refund_bank'] ?? ''), 190) ?: null;
        }

        if ($errors !== []) {
            Session::set('_errors', $errors);
            Session::keepOld($post);
            Session::flash('error', 'Перевірте виділені поля.');
            Response::redirect('/admin/rma-new');
        }

        // дані замовлення з SalesDrive
        $lookup  = Session::get('_admin_lookup');
        $sdOrder = null;
        if (is_array($lookup) && $lookup['order_number'] === $orderNumber
            && $lookup['phone'] === $phone && is_array($lookup['order'] ?? null)) {
            $sdOrder = $lookup['order'];
        }

        $now = date('Y-m-d H:i:s');
        $customerName = Validate::text((string)($post['customer_name'] ?? ''), 190);
        if ($customerName === '' && $sdOrder !== null) {
            $customerName = (string)$sdOrder['customer_name'];
        }
        $email = Validate::email((string)($post['email'] ?? ''));
        if ($email === null && $sdOrder !== null) {
            $email = Validate::email((string)$sdOrder['email']);
        }

        Db::begin();
        try {
            $number = Rma::nextNumber();
            $rmaId = Db::insert('rma', [
                'rma_number'         => $number,
                'status'             => 'manager_review',
                'order_number'       => $orderNumber,
                'order_id_sd'        => $sdOrder !== null ? $sdOrder['sd_id'] : null,
                'order_date'         => $sdOrder !== null ? $sdOrder['order_date'] : null,
                'order_found'        => $sdOrder !== null ? 1 : 0,
                'needs_manual_check' => 0, // менеджер створив свідомо
                'customer_name'      => $customerName ?: null,
                'phone'              => $phone,
                'email'              => $email,
                'reason_code'        => $reason,
                'reason_details'     => Validate::text((string)($post['reason_details'] ?? ''), 3000) ?: null,
                'desired_action'     => $action,
                'exchange_wish'      => Validate::text((string)($post['exchange_wish'] ?? ''), 2000) ?: null,
                'customer_comment'   => Validate::text((string)($post['customer_comment'] ?? ''), 3000) ?: null,
                'confirm_not_installed' => 1,
                'confirm_no_traces'     => 1,
                'confirm_packaging'     => 1,
                'confirm_understand'    => 1,
                'confirm_rules'         => 1,
                'refund_name'    => $refund['name'],
                'refund_iban'    => $refund['iban'],
                'refund_tax_id'  => $refund['tax'],
                'refund_bank'    => $refund['bank'],
                'shipping_payer' => Dict::defaultShippingPayer($reason),
                'total_amount'   => $this->itemsTotalAdmin($items),
                'np_original_ttn'=> $sdOrder !== null ? ($sdOrder['delivery_ttn'] ?? null) : null,
                'manager_id'     => Auth::id(),
                'source'         => 'manager',
                'public_token'   => bin2hex(random_bytes(16)),
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);

            foreach ($items as $it) {
                Db::insert('rma_items', [
                    'rma_id'   => $rmaId,
                    'name'     => $it['name'],
                    'sku'      => $it['sku'] ?: null,
                    'qty'      => $it['qty'],
                    'price'    => $it['price'],
                    'supplier' => \App\Supplier::detect($it['sku']),
                ]);
            }

            Rma::log($rmaId, 'created', null, $number, 'Заявку створив менеджер');
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Session::flash('error', 'Не вдалося створити: ' . $e->getMessage());
            Response::redirect('/admin/rma-new');
            return;
        }

        $rma = Rma::find($rmaId);
        if ($rma !== null) {
            // email клієнту — так; авто-SMS — ні (менеджер сам вирішить)
            try {
                \App\Notify::created($rma, false);
            } catch (\Throwable $e) {
                error_log('Notify: ' . $e->getMessage());
            }
            if ($sdOrder !== null) {
                try {
                    \App\SalesDrive::appendNewRmaComment($rma);
                } catch (\Throwable $e) {
                    error_log('SalesDrive: ' . $e->getMessage());
                }
            }
        }

        Session::forget('_admin_lookup');
        Session::flash('success', 'Заявку ' . $number . ' створено.');
        Response::redirect('/admin/rma/' . $rmaId);
    }

    /**
     * @param array<string,mixed>  $post
     * @param array<string,string> $errors
     * @return array<int,array{name:string,sku:string,qty:int,price:float}>
     */
    private function adminCollectItems(array $post, array &$errors): array
    {
        $items  = [];
        $names  = isset($post['item_name']) && is_array($post['item_name']) ? $post['item_name'] : [];
        $skus   = isset($post['item_sku']) && is_array($post['item_sku']) ? $post['item_sku'] : [];
        $qtys   = isset($post['item_qty']) && is_array($post['item_qty']) ? $post['item_qty'] : [];
        $prices = isset($post['item_price']) && is_array($post['item_price']) ? $post['item_price'] : [];

        foreach ($names as $i => $name) {
            $name = Validate::text((string)$name, 255);
            if ($name === '') {
                continue;
            }
            $qty = (int)($qtys[$i] ?? 1);
            $items[] = [
                'name'  => $name,
                'sku'   => Validate::text((string)($skus[$i] ?? ''), 64),
                'qty'   => $qty > 0 ? min($qty, 999) : 1,
                'price' => (float)str_replace([' ', ','], ['', '.'], (string)($prices[$i] ?? 0)),
            ];
        }
        if ($items === []) {
            $errors['items'] = 'Додайте щонайменше один товар.';
        }
        return $items;
    }

    /** @param array<int,array{price:float,qty:int}> $items */
    private function itemsTotalAdmin(array $items): ?float
    {
        $sum = 0.0;
        foreach ($items as $i) {
            $sum += $i['price'] * $i['qty'];
        }
        return $sum > 0 ? round($sum, 2) : null;
    }

    // ------------------------------------------------------------ Нова пошта: зворотна накладна

    /** AJAX: адреса клієнта з замовлення SalesDrive. */
    public function npClientAddress(string $id): void
    {
        Auth::requireLogin();
        $rma = Rma::find((int)$id);
        if ($rma === null) {
            Response::json(['ok' => false, 'error' => 'Заявку не знайдено'], 404);
        }
        if (empty($rma['order_id_sd'])) {
            Response::json(['ok' => false, 'error' => 'Замовлення не привʼязане до SalesDrive — оберіть відділення вручну.']);
        }
        $point = \App\SalesDrive::deliveryPoint((string)$rma['order_id_sd']);
        if ($point === null) {
            Response::json(['ok' => false, 'error' => 'У замовленні немає адреси Нової пошти — оберіть вручну.']);
        }
        Response::json(['ok' => true, 'point' => $point]);
    }

    /** AJAX: розрахунок вартості зворотної накладної. */
    public function npPrice(string $id): void
    {
        Auth::requireLogin();
        if (Rma::find((int)$id) === null) {
            Response::json(['ok' => false, 'error' => 'Заявку не знайдено'], 404);
        }
        $cityRef = trim((string)($_GET['city_ref'] ?? ''));
        $p = \App\NovaPoshta::priceFor($cityRef);
        Response::json(['ok' => $p['ok'], 'cost' => $p['cost'], 'error' => $p['error']]);
    }

    /** Створити зворотну накладну. */
    public function npCreate(string $id): void
    {
        Csrf::verify();
        $rmaId = (int)$id;
        $rma   = Rma::find($rmaId);
        if ($rma === null) {
            Response::redirect('/admin');
            return;
        }
        if (!\App\NovaPoshta::ready()) {
            Session::flash('error', 'Нова пошта не налаштована (ключ або точка прийому).');
            Response::redirect('/admin/rma/' . $rmaId);
        }
        if (!empty($rma['np_doc_ref'])) {
            Session::flash('error', 'Накладна вже створена. Спершу видаліть поточну.');
            Response::redirect('/admin/rma/' . $rmaId);
        }
        if (!empty($rma['return_ttn'])) {
            $src = (string)($rma['ttn_source'] ?? '');
            $msg = $src === 'light_return'
                ? 'Клієнт уже оформив «Легке повернення» (ТТН ' . $rma['return_ttn'] . '). Накладна магазину не потрібна.'
                : 'ТТН повернення вже вказано (' . $rma['return_ttn'] . '). Спершу приберіть її у блоці «Доставка».';
            Session::flash('error', $msg);
            Response::redirect('/admin/rma/' . $rmaId);
        }

        $cityRef = trim((string)($_POST['city_ref'] ?? ''));
        $whRef   = trim((string)($_POST['wh_ref'] ?? ''));
        $cost    = (float)str_replace([' ', ','], ['', '.'], (string)($_POST['cost'] ?? ''));
        if ($cost <= 0) {
            $cost = $rma['total_amount'] !== null ? (float)$rma['total_amount'] : 0.0;
        }

        $desc = Rma::itemsSummary($rmaId, 3);

        $r = \App\NovaPoshta::createReturn($rma, $cityRef, $whRef, $desc, $cost);
        if (!$r['ok']) {
            Session::flash('error', 'НП: ' . $r['error']);
            Response::redirect('/admin/rma/' . $rmaId);
        }

        Db::update('rma', [
            'return_ttn' => $r['ttn'],
            'np_doc_ref' => $r['ref'],
            'ttn_source' => 'our_np',
            'carrier'    => 'novaposhta',
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$rmaId]);

        Rma::log($rmaId, 'ТТН повернення (НП)', null, $r['ttn'], 'Створено зворотну накладну');
        Session::flash('success', 'Зворотну накладну створено: ' . $r['ttn'] . '. Надішліть її клієнту.');
        Response::redirect('/admin/rma/' . $rmaId);
    }

    /** Оновити трекінг НП вручну. */
    public function npTrack(string $id): void
    {
        Csrf::verify();
        $rmaId = (int)$id;
        if (Rma::find($rmaId) === null) {
            Response::redirect('/admin');
            return;
        }
        $r = Rma::refreshNpTracking($rmaId);
        $r['ok']
            ? Session::flash('success', 'Трекінг оновлено: ' . ($r['status'] ?: '—'))
            : Session::flash('error', $r['error']);
        Response::redirect('/admin/rma/' . $rmaId);
    }

    /** Видалити створену накладну. */
    public function npCancel(string $id): void
    {
        Csrf::verify();
        $rmaId = (int)$id;
        $rma   = Rma::find($rmaId);
        if ($rma === null || empty($rma['np_doc_ref'])) {
            Response::redirect('/admin/rma/' . $rmaId);
            return;
        }
        $r = \App\NovaPoshta::cancelReturn((string)$rma['np_doc_ref']);
        if (!$r['ok']) {
            Session::flash('error', 'Не вдалося видалити накладну: ' . $r['error']);
            Response::redirect('/admin/rma/' . $rmaId);
        }
        Rma::log($rmaId, 'ТТН повернення (НП)', (string)$rma['return_ttn'], null, 'Накладну видалено');
        Db::update('rma', [
            'return_ttn' => null,
            'np_doc_ref' => null,
            'ttn_source' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$rmaId]);
        Session::flash('success', 'Накладну видалено.');
        Response::redirect('/admin/rma/' . $rmaId);
    }

    // ------------------------------------------------------------ ручна SMS клієнту

    public function sendSms(string $id): void
    {
        Csrf::verify();
        $rmaId = (int)$id;
        $rma   = Rma::find($rmaId);
        if ($rma === null) {
            Response::redirect('/admin');
            return;
        }

        if (!\App\TurboSms::enabled()) {
            Session::flash('error', 'SMS не налаштовано. Заповніть TurboSMS у розділі «Налаштування».');
            Response::redirect('/admin/rma/' . $rmaId);
        }

        $text = Validate::text((string)($_POST['text'] ?? ''), 1000);
        $r    = \App\Notify::sendSms($rma, $text);

        if ($r['ok']) {
            Rma::log($rmaId, 'SMS клієнту', null, str_limit($text, 80));
            Session::flash('success', 'Повідомлення надіслано клієнту.');
        } else {
            Session::flash('error', 'Не вдалося надіслати: ' . $r['error']);
        }
        Response::redirect('/admin/rma/' . $rmaId);
    }

    // ------------------------------------------------------------ helpers

    private function dateOrNull(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        $ts = strtotime($v);
        return $ts === false ? null : date('Y-m-d', $ts);
    }
}
