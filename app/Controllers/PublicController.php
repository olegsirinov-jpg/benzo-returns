<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Csrf;
use App\Db;
use App\Dict;
use App\Env;
use App\Notify;
use App\Response;
use App\Rma;
use App\SalesDrive;
use App\Session;
use App\Supplier;
use App\Telegram;
use App\Upload;
use App\Validate;
use App\View;

class PublicController
{
    public function index(): void
    {
        View::render('public/index', [
            'title'       => 'Обмін та повернення товару',
            'returnDays'  => Env::int('RETURN_DAYS', 14),
        ]);
    }

    public function rules(): void
    {
        View::render('public/rules', [
            'title'      => 'Умови обміну та повернення',
            'returnDays' => Env::int('RETURN_DAYS', 14),
        ]);
    }

    public function form(): void
    {
        View::render('public/form', [
            'title'      => 'Заявка на повернення',
            'reasons'    => Dict::reasons(),
            'actions'    => Dict::actions(),
            'needDetails' => Dict::reasonsRequiringDetails(),
            'needDefect'  => Dict::reasonsRequiringDefectPhoto(),
            'returnDays'  => Env::int('RETURN_DAYS', 14),
            'errors'      => Session::pull('_errors', []),
            'restore'     => $this->restorableLookup(),
        ]);
        // старі значення живуть рівно один показ форми
        Session::forget('_old');
    }

    /**
     * Раніше знайдене замовлення — щоб не шукати його заново після переходу
     * на умови й назад. Заразом не витрачаємо слот ліміту SalesDrive.
     *
     * @return array<string,mixed>|null
     */
    private function restorableLookup(): ?array
    {
        $lookup = Session::get('_lookup');
        if (!is_array($lookup) || !is_array($lookup['order'] ?? null)) {
            return null;
        }
        // не тягнемо старе замовлення нескінченно
        if (time() - (int)($lookup['at'] ?? 0) > 3600) {
            return null;
        }

        $order = $lookup['order'];
        return [
            'order_number' => (string)$lookup['order_number'],
            'phone'        => (string)$lookup['phone'],
            'order'        => [
                'number'   => $order['order_number'],
                'date'     => $order['order_date'],
                'customer' => $order['customer_name'],
                'email'    => $order['email'],
                'items'    => $order['items'],
            ],
        ];
    }

    /**
     * AJAX: крок 1 — пошук замовлення в SalesDrive.
     */
    public function lookup(): void
    {
        Csrf::verify();

        $orderNumber = Validate::orderNumber((string)($_POST['order_number'] ?? ''));
        $phoneRaw    = (string)($_POST['phone'] ?? '');
        $phone       = Validate::phone($phoneRaw);

        if ($orderNumber === null) {
            Response::json(['ok' => false, 'error' => 'Вкажіть номер замовлення.'], 422);
        }
        if ($phone === null) {
            Response::json(['ok' => false, 'error' => 'Вкажіть коректний номер телефону, наприклад 067 123 45 67.'], 422);
        }

        // простий захист від перебору
        if (!$this->throttle('lookup', 20, 300)) {
            Response::json(['ok' => false, 'error' => 'Забагато спроб. Спробуйте за кілька хвилин.'], 429);
        }

        $result = SalesDrive::findOrder($orderNumber, $phone);

        if (!empty($result['found'])) {
            $order = $result['order'];
            Session::set('_lookup', [
                'order_number' => $orderNumber,
                'phone'        => $phone,
                'order'        => $order,
                'at'           => time(),
            ]);
            // Віддаємо email лише тому, хто вже підтвердив володіння замовленням
            // (номер + телефон збіглися), тобто самому клієнту.
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

        Session::set('_lookup', [
            'order_number' => $orderNumber,
            'phone'        => $phone,
            'order'        => null,
            'reason'       => $result['reason'] ?? 'not_found',
            'at'           => time(),
        ]);

        // Повідомлення НЕ має розрізняти «немає такого замовлення» і
        // «є, але з іншим телефоном» — інакше перебором номерів можна
        // дізнатися, які замовлення існують. Причину бачить лише менеджер.
        $message = 'Не вдалося знайти замовлення за цим номером і телефоном. '
                 . 'Перевірте, чи телефон той самий, що вказували при замовленні. '
                 . 'Ви можете продовжити оформлення заявки — менеджер перевірить інформацію вручну.';

        Response::json(['ok' => true, 'found' => false, 'message' => $message]);
    }

    /**
     * Крок 8 — приймання заявки.
     */
    public function submit(): void
    {
        Csrf::verify();

        if (!$this->throttle('submit', 5, 600)) {
            Session::flash('error', 'Забагато заявок з вашої адреси. Зверніться до менеджера.');
            Response::redirect('/returns/new');
        }

        $errors = [];
        $post   = $_POST;

        // --- Крок 1: замовлення і телефон ---
        $orderNumber = Validate::orderNumber((string)($post['order_number'] ?? ''));
        if ($orderNumber === null) {
            $errors['order_number'] = 'Вкажіть номер замовлення.';
        }
        $phone = Validate::phone((string)($post['phone'] ?? ''));
        if ($phone === null) {
            $errors['phone'] = 'Вкажіть коректний телефон, наприклад 067 123 45 67.';
        }

        // --- Замовлення з SalesDrive (знайдене на кроці 1) ---
        // Дістаємо ДО збору товарів: саме з цих даних беруться назва,
        // артикул і ціна, а з форми — лише вибір і кількість.
        $lookup  = Session::get('_lookup');
        $sdOrder = null;
        if (is_array($lookup)
            && $orderNumber !== null
            && $lookup['order_number'] === $orderNumber
            && $lookup['phone'] === $phone
            && is_array($lookup['order'] ?? null)
        ) {
            $sdOrder = $lookup['order'];
        }

        // --- Крок 2: товари ---
        $items = $this->collectItems($post, $errors, $sdOrder);

        // --- Крок 3: причина ---
        $reason = (string)($post['reason_code'] ?? '');
        if (!Validate::inDict($reason, Dict::reasons())) {
            $errors['reason_code'] = 'Оберіть причину повернення.';
        }
        $details = Validate::text((string)($post['reason_details'] ?? ''), 3000);
        if (in_array($reason, Dict::reasonsRequiringDetails(), true) && $details === '') {
            $errors['reason_details'] = 'Для цієї причини потрібно описати ситуацію детальніше.';
        }

        // --- Крок 4: бажана дія ---
        $action = (string)($post['desired_action'] ?? '');
        if (!Validate::inDict($action, Dict::actions())) {
            $errors['desired_action'] = 'Оберіть, що ви хочете зробити.';
        }
        $exchangeWish = Validate::text((string)($post['exchange_wish'] ?? ''), 2000);
        if ($action === 'exchange' && $exchangeWish === '') {
            $errors['exchange_wish'] = 'Вкажіть, на який товар хочете обміняти.';
        }

        // --- Крок 5: стан товару ---
        $confirms = [
            'confirm_not_installed' => 'Підтвердіть, що товар не встановлювався.',
            'confirm_no_traces'     => 'Підтвердіть, що товар не має слідів використання.',
            'confirm_packaging'     => 'Підтвердіть збереження упаковки та комплектації.',
            'confirm_understand'    => 'Підтвердіть, що ви ознайомлені з умовами прийому товару.',
        ];
        foreach ($confirms as $key => $message) {
            if (empty($post[$key])) {
                $errors[$key] = $message;
            }
        }

        // --- Крок 8: правила ---
        if (empty($post['confirm_rules'])) {
            $errors['confirm_rules'] = 'Потрібно погодитись з умовами обміну та повернення.';
        }

        // --- Крок 7: реквізити ---
        $refund = ['name' => null, 'iban' => null, 'tax' => null, 'bank' => null, 'comment' => null];
        if ($action === 'refund') {
            $refund['name'] = Validate::text((string)($post['refund_name'] ?? ''), 190);
            if ($refund['name'] === '') {
                $errors['refund_name'] = 'Вкажіть ПІБ отримувача коштів.';
            }
            $refund['iban'] = Validate::iban((string)($post['refund_iban'] ?? ''));
            if ($refund['iban'] === null) {
                $errors['refund_iban'] = 'IBAN має починатися з UA і містити 29 символів. Це не номер картки, а рахунок.';
            }
            $refund['tax'] = Validate::taxId((string)($post['refund_tax_id'] ?? ''));
            if ($refund['tax'] === null) {
                $errors['refund_tax_id'] = 'ІПН/РНОКПП має містити 10 цифр, ЄДРПОУ — 8.';
            }
            $refund['bank']    = Validate::text((string)($post['refund_bank'] ?? ''), 190);
            $refund['comment'] = Validate::text((string)($post['refund_comment'] ?? ''), 1000);
        }

        // --- Крок 6: фото ---
        // За замовчуванням фото необовʼязкове. Але для причин, де рішення
        // ухвалюється саме за виглядом товару (брак, пошкодження, не той товар),
        // без фото менеджер усе одно не зможе нічого вирішити — тому вимагаємо.
        $files = Upload::normalize($_FILES['photos'] ?? []);
        $types = isset($_POST['photo_types']) && is_array($_POST['photo_types']) ? $_POST['photo_types'] : [];

        if (count($files) > 20) {
            $errors['photos'] = 'Максимум 20 фото.';
        } elseif ($files === [] && in_array($reason, Dict::reasonsRequiringDefectPhoto(), true)) {
            $errors['photos'] = 'Для причини «' . Dict::reason($reason) . '» фото обовʼязкове — '
                              . 'без нього менеджер не зможе перевірити заявку.';
        }

        if ($errors !== []) {
            Session::set('_errors', $errors);
            Session::keepOld($post);
            Session::flash('error', 'Перевірте, будь ласка, виділені поля.');
            Response::redirect('/returns/new');
        }

        $now = date('Y-m-d H:i:s');

        // Дані клієнта: те, що ввів клієнт, має пріоритет;
        // порожні поля добираємо із замовлення в SalesDrive.
        $customerName = Validate::text((string)($post['customer_name'] ?? ''), 190);
        if ($customerName === '' && $sdOrder !== null) {
            $customerName = (string)$sdOrder['customer_name'];
        }

        $email = Validate::email((string)($post['email'] ?? ''));
        if ($email === null && $sdOrder !== null) {
            // у SalesDrive email лежить у primaryContact.email[] — mapOrder уже його дістав
            $email = Validate::email((string)$sdOrder['email']);
        }

        Db::begin();
        try {
            $number = Rma::nextNumber();

            $rmaId = Db::insert('rma', [
                'rma_number'         => $number,
                'status'             => 'new',
                'order_number'       => $orderNumber,
                'order_id_sd'        => $sdOrder !== null ? $sdOrder['sd_id'] : null,
                'order_date'         => $sdOrder !== null ? $sdOrder['order_date'] : null,
                'order_found'        => $sdOrder !== null ? 1 : 0,
                'needs_manual_check' => $sdOrder !== null ? 0 : 1,
                'customer_name'      => $customerName ?: null,
                'phone'              => $phone,
                'email'              => $email,
                'reason_code'        => $reason,
                'reason_details'     => $details ?: null,
                'desired_action'     => $action,
                'exchange_wish'      => $exchangeWish ?: null,
                'customer_comment'   => Validate::text((string)($post['customer_comment'] ?? ''), 3000) ?: null,
                'confirm_not_installed' => 1,
                'confirm_no_traces'     => 1,
                'confirm_packaging'     => 1,
                'confirm_understand'    => 1,
                'confirm_rules'         => 1,
                'refund_name'    => $refund['name'],
                'refund_iban'    => $refund['iban'],
                'refund_tax_id'  => $refund['tax'],
                'refund_bank'    => $refund['bank'] ?: null,
                'refund_comment' => $refund['comment'] ?: null,
                'shipping_payer' => Dict::defaultShippingPayer($reason),
                'total_amount'   => $this->itemsTotal($items),
                'source'         => 'web',
                'ip'             => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                'public_token'   => bin2hex(random_bytes(16)),
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);

            foreach ($items as $item) {
                Db::insert('rma_items', [
                    'rma_id'   => $rmaId,
                    'name'     => $item['name'],
                    'sku'      => $item['sku'] ?: null,
                    'qty'      => $item['qty'],
                    'price'    => $item['price'],
                    'url'      => $item['url'] ?: null,
                    'supplier' => Supplier::detect($item['sku']),
                ]);
            }

            foreach ($files as $i => $file) {
                $stored = Upload::saveImage($file);
                $type   = (string)($types[$i] ?? 'general');
                Db::insert('rma_photos', [
                    'rma_id'      => $rmaId,
                    'type'        => isset(Dict::photoTypes()[$type]) ? $type : 'general',
                    'file'        => $stored,
                    'orig_name'   => mb_substr($file['name'], 0, 255),
                    'size'        => $file['size'],
                    'uploaded_by' => 'client',
                    'created_at'  => $now,
                ]);
            }

            Rma::log($rmaId, 'created', null, $number, 'Заявку створено клієнтом', null, 'Клієнт');

            // Клієнту причину не показуємо (щоб не можна було перебором
            // промацати базу), але менеджеру вона потрібна для перевірки.
            if ($sdOrder === null) {
                $reasons = [
                    'phone_mismatch' => 'Замовлення з таким номером існує, але телефон у ньому інший.',
                    'not_found'      => 'Замовлення з таким номером не знайдено в SalesDrive.',
                    'rate_limit'     => 'Не вдалося перевірити: вичерпано ліміт запитів до SalesDrive.',
                    'api_error'      => 'Не вдалося перевірити: SalesDrive не відповів.',
                    'disabled'       => 'Інтеграцію з SalesDrive вимкнено.',
                ];
                $why = is_array($lookup) ? (string)($lookup['reason'] ?? 'not_found') : 'not_found';
                Rma::comment(
                    $rmaId,
                    'Автоперевірка замовлення не пройшла. ' . ($reasons[$why] ?? $why),
                    'system',
                    'Система'
                );
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            error_log('RMA submit error: ' . $e->getMessage());
            Session::keepOld($post);
            Session::flash('error', 'Не вдалося зберегти заявку: ' . $e->getMessage());
            Response::redirect('/returns/new');
            return;
        }

        $rma = Rma::find($rmaId);
        if ($rma !== null) {
            // Сповіщення не повинні валити заявку, якщо зовнішній сервіс недоступний
            try {
                Telegram::newRma($rma);
            } catch (\Throwable $e) {
                error_log('Telegram: ' . $e->getMessage());
            }
            if ($sdOrder !== null) {
                try {
                    if (SalesDrive::appendNewRmaComment($rma)) {
                        Db::update('rma', ['sd_synced' => 1], 'id = ?', [$rmaId]);
                    }
                } catch (\Throwable $e) {
                    error_log('SalesDrive: ' . $e->getMessage());
                }
            }
            try {
                Notify::created($rma);
            } catch (\Throwable $e) {
                error_log('Notify: ' . $e->getMessage());
            }
        }

        Session::forget('_lookup');
        Session::forget('_old');
        Session::set('_last_rma', $number);

        // Щоб «Переглянути статус заявки» відкрило саме щойно створену заявку,
        // а не ту, яку клієнт дивився раніше в цій же сесії.
        Session::set('_status_rma', $rmaId);

        Response::redirect('/returns/success?n=' . urlencode($number));
    }

    public function success(): void
    {
        $number = (string)($_GET['n'] ?? '');
        $last   = Session::get('_last_rma');
        if ($number === '' || $last !== $number) {
            Response::redirect('/returns');
        }
        View::render('public/success', [
            'title'  => 'Заявку прийнято',
            'number' => $number,
        ]);
    }

    public function statusForm(): void
    {
        // «Перевірити іншу заявку» — забуваємо поточну й показуємо порожню форму
        if (isset($_GET['new'])) {
            Session::forget('_status_rma');
            Response::redirect('/returns/status');
            return;
        }

        // Пряме посилання з листа/SMS: ?rma=RMA-000004&t=токен
        $linkNumber = trim((string)($_GET['rma'] ?? ''));
        $linkToken  = trim((string)($_GET['t'] ?? ''));
        if ($linkNumber !== '' && $linkToken !== '') {
            $rma = Rma::findByNumber($linkNumber);
            if ($rma !== null
                && !empty($rma['public_token'])
                && hash_equals((string)$rma['public_token'], $linkToken)
            ) {
                Session::set('_status_rma', (int)$rma['id']);
            }
            Response::redirect('/returns/status');
            return;
        }

        // після створення заявки чи додавання ТТН показуємо її
        // без повторного вводу номера й телефону
        $rmaId = Session::get('_status_rma');
        if (is_int($rmaId)) {
            $rma = Rma::find($rmaId);
            if ($rma !== null) {
                View::render('public/status', [
                    'title'    => 'Заявка ' . $rma['rma_number'],
                    'rma'      => $rma,
                    'items'    => Rma::items($rmaId),
                    'comments' => Rma::comments($rmaId, 'client'),
                ]);
                return;
            }
        }

        View::render('public/status', [
            'title' => 'Статус заявки',
            'rma'   => null,
        ]);
        Session::forget('_old');
    }

    public function statusShow(): void
    {
        Csrf::verify();

        $number = trim((string)($_POST['rma_number'] ?? ''));
        $phone  = (string)($_POST['phone'] ?? '');

        if (!$this->throttle('status', 20, 300)) {
            Session::flash('error', 'Забагато спроб. Спробуйте за кілька хвилин.');
            Response::redirect('/returns/status');
        }

        $rma = Rma::findForCustomer($number, $phone);
        if ($rma === null) {
            Session::flash('error', 'Заявку не знайдено. Перевірте номер заявки та телефон.');
            Session::keepOld($_POST);
            Response::redirect('/returns/status');
        }

        // токен доступу для форми ТТН
        Session::set('_status_rma', (int)$rma['id']);

        View::render('public/status', [
            'title'    => 'Заявка ' . $rma['rma_number'],
            'rma'      => $rma,
            'items'    => Rma::items((int)$rma['id']),
            'comments' => Rma::comments((int)$rma['id'], 'client'),
        ]);
    }

    /**
     * Клієнт сам вписує реквізити для повернення коштів.
     * Потрібно, коли менеджер змінив дію на «повернення коштів» уже після
     * оформлення заявки — щоб не випитувати IBAN у клієнта по телефону.
     */
    public function saveRefundDetails(): void
    {
        Csrf::verify();

        $rmaId = Session::get('_status_rma');
        if (!is_int($rmaId)) {
            Session::flash('error', 'Сесія завершилась. Відкрийте заявку за посиланням ще раз.');
            Response::redirect('/returns/status');
        }
        $rma = Rma::find($rmaId);
        if ($rma === null) {
            Response::redirect('/returns/status');
            return;
        }

        $name = Validate::text((string)($_POST['refund_name'] ?? ''), 190);
        $iban = Validate::iban((string)($_POST['refund_iban'] ?? ''));
        $tax  = Validate::taxId((string)($_POST['refund_tax_id'] ?? ''));
        $bank = Validate::text((string)($_POST['refund_bank'] ?? ''), 190);

        $errors = [];
        if ($name === '') {
            $errors[] = 'Вкажіть ПІБ отримувача.';
        }
        if ($iban === null) {
            $errors[] = 'IBAN має починатися з UA і містити 29 символів. Це не номер картки, а рахунок.';
        }
        if ($tax === null) {
            $errors[] = 'ІПН/РНОКПП — 10 цифр, ЄДРПОУ — 8.';
        }
        if ($errors !== []) {
            Session::flash('error', implode(' ', $errors));
            Session::keepOld($_POST);
            Response::redirect('/returns/status');
        }

        Db::update('rma', [
            'refund_name'   => $name,
            'refund_iban'   => $iban,
            'refund_tax_id' => $tax,
            'refund_bank'   => $bank ?: null,
            'updated_at'    => date('Y-m-d H:i:s'),
        ], 'id = ?', [$rmaId]);

        Rma::log($rmaId, 'Реквізити', null, 'IBAN ' . $iban, 'Клієнт вказав реквізити', null, 'Клієнт');

        // якщо чекали реквізити — рухаємо статус далі
        if ((string)$rma['status'] === 'waiting_payment_details') {
            Rma::setStatus($rmaId, 'refund_pending');
        }

        Session::flash('success', 'Дякуємо! Реквізити збережено, менеджер оформить повернення коштів.');
        Response::redirect('/returns/status');
    }

    /**
     * Клієнт додає ТТН відправлення.
     */
    public function addTtn(): void
    {
        Csrf::verify();

        $rmaId = Session::get('_status_rma');
        if (!is_int($rmaId)) {
            Session::flash('error', 'Сесія завершилась. Знайдіть заявку ще раз.');
            Response::redirect('/returns/status');
        }

        $rma = Rma::find($rmaId);
        if ($rma === null) {
            Response::redirect('/returns/status');
            return;
        }

        $ttn = Validate::ttn((string)($_POST['ttn'] ?? ''));
        if ($ttn === null) {
            Session::flash('error', 'Вкажіть коректний номер ТТН.');
            Response::redirect('/returns/status');
        }

        $carrier = (string)($_POST['carrier'] ?? 'novaposhta');
        if (!isset(Dict::carriers()[$carrier])) {
            $carrier = 'other';
        }

        Db::update('rma', [
            'return_ttn' => $ttn,
            'carrier'    => $carrier,
            'shipped_at' => date('Y-m-d'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$rmaId]);

        Rma::log($rmaId, 'return_ttn', (string)$rma['return_ttn'], $ttn, 'ТТН додано клієнтом', null, 'Клієнт');

        if (in_array((string)$rma['status'], ['approved', 'waiting_customer_shipment'], true)) {
            Rma::setStatus($rmaId, 'in_transit');
        }

        try {
            Telegram::ttnAdded($rma, $ttn);
        } catch (\Throwable $e) {
            error_log('Telegram: ' . $e->getMessage());
        }

        Session::flash('success', 'Дякуємо! ТТН збережено, менеджер очікує посилку.');
        Response::redirect('/returns/status');
    }

    // ---- helpers ----

    /**
     * @param array<string,mixed>      $post
     * @param array<string,string>     $errors
     * @param array<string,mixed>|null $sdOrder замовлення із SalesDrive, якщо знайдене
     * @return array<int,array{name:string,sku:string,qty:int,price:float,url:string}>
     */
    private function collectItems(array $post, array &$errors, ?array $sdOrder = null): array
    {
        $items  = [];
        $names  = isset($post['item_name']) && is_array($post['item_name']) ? $post['item_name'] : [];
        $skus   = isset($post['item_sku']) && is_array($post['item_sku']) ? $post['item_sku'] : [];
        $qtys   = isset($post['item_qty']) && is_array($post['item_qty']) ? $post['item_qty'] : [];
        $prices = isset($post['item_price']) && is_array($post['item_price']) ? $post['item_price'] : [];
        $urls   = isset($post['item_url']) && is_array($post['item_url']) ? $post['item_url'] : [];
        $picked = isset($post['item_selected']) && is_array($post['item_selected']) ? $post['item_selected'] : [];

        // Товари обрані зі списку замовлення: назву, артикул і ціну беремо
        // з даних SalesDrive у сесії, а не з форми — приховані поля браузера
        // можна підмінити. З форми довіряємо лише кількості, та й ту обмежуємо
        // тим, що клієнт справді купив.
        $fromOrder = $picked !== [] && $sdOrder !== null && isset($sdOrder['items']) && is_array($sdOrder['items']);

        if ($fromOrder) {
            foreach ($picked as $rawIndex) {
                $i = (int)$rawIndex;
                if (!isset($sdOrder['items'][$i]) || !is_array($sdOrder['items'][$i])) {
                    continue;
                }
                $src = $sdOrder['items'][$i];

                $ordered = max(1, (int)($src['qty'] ?? 1));

                // За замовчуванням повертається вся куплена кількість;
                // менше — лише якщо клієнт свідомо зменшив у степері.
                $qty = isset($qtys[$i]) && $qtys[$i] !== '' ? (int)$qtys[$i] : $ordered;
                if ($qty < 1) {
                    $qty = 1;
                }
                if ($qty > $ordered) {
                    // клієнт не може повернути більше, ніж купив
                    $qty = $ordered;
                }

                $items[] = [
                    'name'  => Validate::text((string)($src['name'] ?? ''), 255),
                    'sku'   => Validate::text((string)($src['sku'] ?? ''), 64),
                    'qty'   => $qty,
                    'price' => (float)($src['price'] ?? 0),
                    'url'   => mb_substr(trim((string)($src['url'] ?? '')), 0, 500),
                ];
            }
        } else {
            // Товари введені вручну — звіряти немає з чим
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
                    'url'   => mb_substr(trim((string)($urls[$i] ?? '')), 0, 500),
                ];
            }
        }

        if ($items === []) {
            $errors['items'] = 'Вкажіть щонайменше один товар для повернення.';
        }
        if (count($items) > 30) {
            $errors['items'] = 'Забагато позицій у заявці.';
        }

        return $items;
    }

    /**
     * @param array<int,array{price:float,qty:int}> $items
     */
    private function itemsTotal(array $items): ?float
    {
        $sum = 0.0;
        foreach ($items as $i) {
            $sum += $i['price'] * $i['qty'];
        }
        return $sum > 0 ? round($sum, 2) : null;
    }

    /**
     * Просте обмеження частоти по IP через сесію.
     *
     * @param string $bucket назва лічильника
     * @param int    $limit  максимум спроб
     * @param int    $window вікно в секундах
     */
    private function throttle(string $bucket, int $limit, int $window): bool
    {
        $key  = '_throttle_' . $bucket;
        $data = Session::get($key, []);
        if (!is_array($data)) {
            $data = [];
        }
        $now  = time();
        $data = array_values(array_filter($data, function ($ts) use ($now, $window) {
            return ($now - (int)$ts) < $window;
        }));
        if (count($data) >= $limit) {
            return false;
        }
        $data[] = $now;
        Session::set($key, $data);
        return true;
    }
}
