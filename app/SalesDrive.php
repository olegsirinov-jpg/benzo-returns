<?php
declare(strict_types=1);

namespace App;

/**
 * Інтеграція з SalesDrive API.
 *
 * Документація:
 *   GET  {domain}/api/order/list/   — список заявок (ліміт: 10/хв, 100/год, 1000/добу)
 *   POST {domain}/api/order/update/ — оновлення заявки
 * Авторизація: хедер Form-Api-Key.
 *
 * Через жорсткий ліміт на order/list результати кешуються в таблиці sd_cache,
 * а кількість викликів контролюється через sd_calls.
 */
class SalesDrive
{
    const CACHE_TTL      = 900; // 15 хв
    const RATE_PER_MIN   = 8;   // залишаємо запас від ліміту 10
    const RATE_PER_HOUR  = 90;

    public static function enabled(): bool
    {
        return Config::bool('sd_enabled', false)
            && Config::str('sd_api_key') !== ''
            && Config::str('sd_url') !== '';
    }

    /**
     * Пошук замовлення за номером і телефоном.
     *
     * @return array{found:bool,reason?:string,order?:array<string,mixed>}
     */
    public static function findOrder(string $orderNumber, string $phone): array
    {
        if (!self::enabled()) {
            return ['found' => false, 'reason' => 'disabled'];
        }

        $cacheKey = 'order_' . md5($orderNumber . '|' . $phone);
        $cached   = self::cacheGet($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $result   = ['found' => false, 'reason' => 'not_found'];
        $anyReply = false;

        foreach (self::searchStrategies($orderNumber) as $strategy) {
            if (!self::rateOk()) {
                // ліміт вичерпано — не кешуємо, щоб спробувати пізніше
                return ['found' => false, 'reason' => 'rate_limit'];
            }

            $resp = self::request('GET', '/api/order/list/', [
                'page'   => 1,
                'limit'  => $strategy['limit'],
                'filter' => $strategy['filter'],
            ]);

            // Фільтр може не підтримуватись цим акаунтом — це не привід
            // припиняти пошук, пробуємо наступну стратегію.
            if ($resp === null) {
                continue;
            }
            $anyReply = true;

            if (!isset($resp['data']) || !is_array($resp['data']) || $resp['data'] === []) {
                continue;
            }

            foreach ($resp['data'] as $order) {
                if (!is_array($order) || !self::orderNumberMatches($order, $orderNumber)) {
                    continue;
                }
                if (!self::phoneMatches($order, $phone)) {
                    // номер той, телефон інший — запамʼятовуємо, але шукаємо далі
                    $result = ['found' => false, 'reason' => 'phone_mismatch'];
                    continue;
                }
                $result = ['found' => true, 'order' => self::mapOrder($order)];
                break 2;
            }
        }

        // жодна стратегія не отримала відповіді — проблема з API, а не з номером.
        // Не кешуємо, щоб не «залипнути» на 15 хвилин через тимчасовий збій.
        if (!$anyReply) {
            return ['found' => false, 'reason' => 'api_error'];
        }

        self::cachePut($cacheKey, $result, self::CACHE_TTL);
        return $result;
    }

    /**
     * Стратегії пошуку — від найточнішої до найширшої.
     *
     * statusId => '__ALL__' обовʼязковий: без нього SalesDrive повертає
     * лише заявки в статусах за замовчуванням, а замовлення, яке повертають,
     * зазвичай уже в кінцевому статусі («Продаж») і у вибірку не потрапляє.
     *
     * @return array<int,array{filter:array<string,mixed>,limit:int}>
     */
    private static function searchStrategies(string $orderNumber): array
    {
        $needle = self::normalizeNumber($orderNumber);
        $out    = [];

        // Точковий запит по полях, які SalesDrive реально фільтрує.
        // Решту він МОВЧКИ ІГНОРУЄ, віддаючи останні N замовлень — це гірше
        // за відсутність запиту: марно витрачається слот ліміту 10/хв.
        foreach (self::orderFields() as $field) {
            if (!in_array($field, self::filterableFields(), true)) {
                continue;
            }
            // числові поля не мають сенсу для нечислового номера — не смітимо запитом
            if (in_array($field, self::rangeFields(), true) && !ctype_digit($needle)) {
                continue;
            }
            $out[] = [
                'filter' => array_merge(self::fieldFilter($field, $needle), ['statusId' => '__ALL__']),
                'limit'  => 20,
            ];
        }

        return $out;
    }

    /**
     * Будує фільтр для одного поля.
     *
     * Числові поля SalesDrive фільтрує ДІАПАЗОНОМ — так само, як orderTime:
     *   filter[id][from]=283732&filter[id][to]=283732
     * Простий filter[id]=283732 мовчки ігнорується.
     * Рядкові поля (externalId) фільтруються звичайним порівнянням.
     *
     * @return array<string,mixed>
     */
    private static function fieldFilter(string $field, string $value): array
    {
        if (in_array($field, self::rangeFields(), true)) {
            return [$field => ['from' => $value, 'to' => $value]];
        }
        return [$field => $value];
    }

    /**
     * Числові поля, які вимагають синтаксису [from]/[to].
     *
     * @return array<int,string>
     */
    private static function rangeFields(): array
    {
        $raw = Env::str('SD_RANGE_FIELDS', 'id');
        return array_values(array_filter(array_map('trim', explode(',', $raw)), function ($v) {
            return $v !== '';
        }));
    }

    /**
     * Поля, які SalesDrive справді вміє фільтрувати в order/list.
     * Решту він мовчки ігнорує, віддаючи останні N замовлень.
     * Перевірено контрольними запитами через /admin/diag.
     *
     * @return array<int,string>
     */
    private static function filterableFields(): array
    {
        // externalId — рядкове порівняння, id — діапазоном [from]/[to] (див. rangeFields).
        // Обидва перевірено контрольними запитами й вони працюють, тож у дефолті — обидва.
        $raw = Env::str('SD_FILTERABLE_FIELDS', 'externalId,id');
        return array_values(array_filter(array_map('trim', explode(',', $raw)), function ($v) {
            return $v !== '';
        }));
    }

    /**
     * Поля, у яких може лежати номер замовлення.
     *
     * Замовлення потрапляють у SalesDrive з Хорошопа, тому номерів два:
     *   id         — внутрішній номер SalesDrive (створюється при оформленні в CRM)
     *   externalId — номер, що прийшов із сайту (Хорошоп)
     * Якщо інтеграція кладе номер магазину в кастомне поле — допишіть його
     * в SD_ORDER_FIELDS через кому, код чіпати не треба.
     *
     * @return array<int,string>
     */
    private static function orderFields(): array
    {
        $raw  = Env::str('SD_ORDER_FIELDS', 'externalId,id');
        $list = array_values(array_filter(array_map('trim', explode(',', $raw)), function ($v) {
            return $v !== '';
        }));
        return $list === [] ? ['externalId', 'id'] : $list;
    }

    /**
     * Які поля реально використовує пошук (для діагностики).
     *
     * @return array{order:array<int,string>,filterable:array<int,string>,range:array<int,string>}
     */
    public static function debugFields(): array
    {
        return [
            'order'      => self::orderFields(),
            'filterable' => self::filterableFields(),
            'range'      => self::rangeFields(),
        ];
    }

    /**
     * @param array<string,mixed> $order
     */
    private static function orderNumberMatches(array $order, string $needle): bool
    {
        $needle = self::normalizeNumber($needle);
        if ($needle === '') {
            return false;
        }
        foreach (self::orderFields() as $field) {
            if (!isset($order[$field]) || is_array($order[$field])) {
                continue;
            }
            if (self::normalizeNumber((string)$order[$field]) === $needle) {
                return true;
            }
        }
        return false;
    }

    /**
     * «№ 45678», "#45678", " 45678 " -> "45678"
     */
    private static function normalizeNumber(string $v): string
    {
        return trim(ltrim(trim($v), '#№ '));
    }

    /**
     * @param array<string,mixed> $order
     */
    private static function phoneMatches(array $order, string $phone): bool
    {
        $target = Validate::phone($phone);
        if ($target === null) {
            return false;
        }
        foreach (self::extractPhones($order) as $candidate) {
            $n = Validate::phone($candidate);
            if ($n !== null && $n === $target) {
                return true;
            }
        }
        return false;
    }

    /**
     * Усі контакти заявки: primaryContact + contacts[].
     * У SalesDrive контактні дані лежать саме тут, а не на верхньому рівні.
     *
     * @param array<string,mixed> $order
     * @return array<int,array<string,mixed>>
     */
    private static function contactsOf(array $order): array
    {
        $out = [];
        if (isset($order['primaryContact']) && is_array($order['primaryContact'])) {
            $out[] = $order['primaryContact'];
        }
        if (isset($order['contacts']) && is_array($order['contacts'])) {
            foreach ($order['contacts'] as $contact) {
                if (is_array($contact)) {
                    $out[] = $contact;
                }
            }
        }
        return $out;
    }

    /**
     * Значення поля може бути рядком або масивом (phone/email у SalesDrive — масиви).
     *
     * @param mixed $value
     * @return array<int,string>
     */
    private static function flatten($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_array($value)) {
            return [(string)$value];
        }
        $out = [];
        foreach ($value as $v) {
            if ($v !== null && $v !== '' && !is_array($v)) {
                $out[] = (string)$v;
            }
        }
        return $out;
    }

    /**
     * Телефони заявки. У SalesDrive: primaryContact.phone[] / contacts[].phone[].
     *
     * @param array<string,mixed> $order
     * @return array<int,string>
     */
    private static function extractPhones(array $order): array
    {
        $out = [];

        // контакти — основне джерело
        foreach (self::contactsOf($order) as $contact) {
            foreach (['phone', 'contact_phone', 'telephone'] as $key) {
                if (isset($contact[$key])) {
                    $out = array_merge($out, self::flatten($contact[$key]));
                }
            }
        }

        // верхній рівень — на випадок нестандартних налаштувань форми
        foreach (['phone', 'contact_phone', 'telephone'] as $key) {
            if (isset($order[$key])) {
                $out = array_merge($out, self::flatten($order[$key]));
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Приводить заявку SalesDrive до внутрішнього формату.
     *
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    private static function mapOrder(array $order): array
    {
        // --- Товари ---
        // Реальна структура products[]: text (назва), sku (артикул),
        // parameter (id товару на сайті), amount (к-сть), price, discount.
        $items = [];
        $rows  = $order['products'] ?? ($order['orderProducts'] ?? []);
        if (is_array($rows)) {
            foreach ($rows as $p) {
                if (!is_array($p)) {
                    continue;
                }

                $sku = trim((string)($p['sku'] ?? ''));
                if ($sku === '') {
                    $sku = trim((string)($p['article'] ?? ''));
                }

                $name = trim((string)($p['text'] ?? ''));
                if ($name === '') {
                    $name = trim((string)($p['documentName'] ?? ($p['name'] ?? '')));
                }
                if ($name === '') {
                    $name = 'Товар';
                }

                // ціна за одиницю з урахуванням знижки на позицію
                $price    = (float)($p['price'] ?? 0);
                $discount = (float)($p['discount'] ?? 0);
                if ($discount > 0 && empty($p['percentDiscount'])) {
                    $price = max(0, $price - $discount);
                } elseif ($discount > 0 && !empty($p['percentDiscount'])) {
                    $price = round($price * (1 - $discount / 100), 2);
                }

                $items[] = [
                    'name'  => $name,
                    'sku'   => $sku,
                    'qty'   => (int)($p['amount'] ?? ($p['quantity'] ?? 1)),
                    'price' => $price,
                    'url'   => (string)($p['url'] ?? ''),
                ];
            }
        }

        // --- Контакт: ПІБ та email лежать у primaryContact / contacts[] ---
        $contacts = self::contactsOf($order);
        $contact  = $contacts[0] ?? [];

        $name = trim(implode(' ', array_filter([
            trim((string)($contact['lName'] ?? '')),
            trim((string)($contact['fName'] ?? '')),
            trim((string)($contact['mName'] ?? '')),
        ], function ($v) {
            return $v !== '';
        })));

        if ($name === '') {
            // запасні варіанти для нестандартних налаштувань форми
            $name = trim(implode(' ', array_filter([
                trim((string)($order['lastName'] ?? '')),
                trim((string)($order['firstName'] ?? '')),
                trim((string)($order['middleName'] ?? '')),
            ], function ($v) {
                return $v !== '';
            })));
        }
        if ($name === '') {
            $name = trim((string)($order['contactFullName'] ?? ''));
        }

        $emails = self::flatten($contact['email'] ?? null);
        if ($emails === []) {
            $emails = self::flatten($order['email'] ?? null);
        }

        $phones    = self::extractPhones($order);
        $phone     = '';
        foreach ($phones as $candidate) {
            $n = Validate::phone($candidate);
            if ($n !== null) {
                $phone = $n;
                break;
            }
        }

        $orderTime = (string)($order['orderTime'] ?? ($order['createTime'] ?? ''));

        // Номер, який бачить клієнт, — externalId (номер із сайту).
        // id — внутрішній номер SalesDrive.
        $external = trim((string)($order['externalId'] ?? ''));

        return [
            'sd_id'         => (string)($order['id'] ?? ''),
            'order_number'  => $external !== '' ? $external : (string)($order['id'] ?? ''),
            'order_date'    => $orderTime !== '' ? date('Y-m-d', (int)strtotime($orderTime)) : null,
            'status'        => (string)($order['statusId'] ?? ''),
            'customer_name' => $name,
            'phone'         => $phone,
            'email'         => $emails[0] ?? '',
            'total'         => (float)($order['paymentAmount'] ?? 0),
            'items'         => $items,
        ];
    }

    /**
     * Дописати коментар про створення заявки.
     *
     * ВАЖЛИВО: api/order/update/ перезаписує поле comment, тому
     * спершу читаємо поточний коментар і дописуємо в кінець.
     *
     * @param array<string,mixed> $rma
     */
    public static function appendNewRmaComment(array $rma): bool
    {
        // Коротко: поле comment у SalesDrive спільне з нотатками менеджерів,
        // і кожен наш запис дописується в кінець. Деталі заявки менеджер
        // усе одно дивиться в самій системі повернень.
        $text = '🔁 Повернення ' . $rma['rma_number']
              . ' · Причина: ' . Dict::reason((string)$rma['reason_code']);

        return self::appendComment((string)$rma['order_id_sd'], $text);
    }

    /**
     * @param array<string,mixed> $rma
     */
    public static function appendStatusComment(array $rma, string $newStatus, ?string $comment): bool
    {
        $text = '🔁 Повернення ' . $rma['rma_number']
              . ' · ' . Dict::status($newStatus);

        return self::appendComment((string)$rma['order_id_sd'], $text);
    }

    /**
     * Дописує текст до існуючого коментаря замовлення.
     */
    public static function appendComment(string $orderId, string $text): bool
    {
        if (!self::enabled() || $orderId === '') {
            return false;
        }
        $formKey = Config::str('sd_form_key');
        if ($formKey === '') {
            self::log('SD_FORM_KEY не заданий — коментар не відправлено');
            return false;
        }

        // Поле comment перезаписується цілком, тому спершу читаємо поточне.
        // Якщо прочитати не вдалося — НЕ пишемо взагалі: краще не додати
        // коментар, ніж затерти нотатки менеджерів або вписати чужий текст.
        $existing = self::orderComment($orderId);
        if ($existing === null) {
            self::log('appendComment: не вдалося прочитати коментар замовлення ' . $orderId . ' — запис скасовано');
            return false;
        }

        $merged = $existing !== '' ? $existing . "\n\n" . $text : $text;

        $resp = self::request('POST', '/api/order/update/', [
            'form' => $formKey,
            'id'   => $orderId,
            'data' => ['comment' => $merged],
        ]);

        return $resp !== null && (($resp['status'] ?? '') !== 'error');
    }

    /**
     * Поточний коментар замовлення (щоб не затерти при дозаписі).
     *
     * УВАГА: filter[id] у SalesDrive ігнорується — потрібен діапазон
     * filter[id][from]/[to], інакше повернеться просто НАЙНОВІШЕ замовлення,
     * і ми допишемо коментар чужої заявки в цю.
     * Повертає null, якщо прочитати надійно не вдалося.
     */
    private static function orderComment(string $orderId): ?string
    {
        if (!self::rateOk() || !ctype_digit($orderId)) {
            return null;
        }

        $resp = self::request('GET', '/api/order/list/', [
            'page'   => 1,
            'limit'  => 5,
            'filter' => [
                'id'       => ['from' => $orderId, 'to' => $orderId],
                'statusId' => '__ALL__',
            ],
        ]);

        if ($resp === null || !isset($resp['data']) || !is_array($resp['data'])) {
            return null;
        }

        // Перевіряємо, що це справді наше замовлення, а не «останнє потрапило під руку»
        foreach ($resp['data'] as $order) {
            if (is_array($order) && (string)($order['id'] ?? '') === $orderId) {
                return (string)($order['comment'] ?? '');
            }
        }

        self::log('orderComment: замовлення ' . $orderId . ' не підтверджено у відповіді — коментар не чіпаємо');
        return null;
    }

    /**
     * Точка доставки клієнта з замовлення (для зворотної накладної НП).
     * Бере ord_delivery_data з провайдером novaposhta.
     *
     * @return array{city_ref:string,wh_ref:string,city_name:string,wh_name:string}|null
     */
    public static function deliveryPoint(string $orderId): ?array
    {
        if (!self::enabled() || !ctype_digit($orderId) || !self::rateOk()) {
            return null;
        }

        $resp = self::request('GET', '/api/order/list/', [
            'page'   => 1,
            'limit'  => 5,
            'filter' => [
                'id'       => ['from' => $orderId, 'to' => $orderId],
                'statusId' => '__ALL__',
            ],
        ]);
        if ($resp === null || !isset($resp['data']) || !is_array($resp['data'])) {
            return null;
        }

        foreach ($resp['data'] as $order) {
            if (!is_array($order) || (string)($order['id'] ?? '') !== $orderId) {
                continue;
            }
            $rows = $order['ord_delivery_data'] ?? [];
            if (!is_array($rows)) {
                return null;
            }
            foreach ($rows as $d) {
                if (!is_array($d) || ($d['provider'] ?? '') !== 'novaposhta') {
                    continue;
                }
                $cityRef = (string)($d['cityRef'] ?? '');
                $whRef   = (string)($d['branchRef'] ?? '');
                if ($cityRef === '' || $whRef === '') {
                    return null;
                }
                $whName = (string)($d['address'] ?? '');
                if ($whName === '' && !empty($d['branchNumber'])) {
                    $whName = 'Відділення №' . $d['branchNumber'];
                }
                return [
                    'city_ref'  => $cityRef,
                    'wh_ref'    => $whRef,
                    'city_name' => (string)($d['cityName'] ?? ''),
                    'wh_name'   => $whName,
                ];
            }
        }
        return null;
    }

    /**
     * Виконати HTTP-запит до API.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    private static function request(string $method, string $path, array $payload = []): ?array
    {
        $url = rtrim(Config::str('sd_url'), '/') . $path;

        $ch = curl_init();
        $headers = ['Form-Api-Key: ' . Config::str('sd_api_key')];

        if ($method === 'GET') {
            $url .= '?' . http_build_query($payload);
            self::countCall();
        } else {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            self::log('cURL error: ' . $err . ' (' . $path . ')');
            return null;
        }
        // Перевищення ліміту SalesDrive віддає як HTTP 400 з текстом
        // "API limit reached", а не як 429 — ловимо обидва варіанти.
        if ($code === 429 || stripos((string)$body, 'API limit reached') !== false) {
            self::log('SalesDrive: перевищено ліміт запитів (' . $code . ')');
            return null;
        }
        if ($code < 200 || $code >= 300) {
            self::log('HTTP ' . $code . ' на ' . $path . ': ' . substr((string)$body, 0, 500));
            return null;
        }

        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            self::log('Некоректний JSON з ' . $path);
            return null;
        }
        return $data;
    }

    // ---- Діагностика ----

    /**
     * Сирий запит до order/list для сторінки діагностики.
     * Не використовує кеш і показує повну відповідь як є.
     *
     * @param array<string,mixed> $filter
     * @return array{code:int,error:string,body:string,data:array<string,mixed>|null,url:string}
     */
    public static function diagList(array $filter = [], int $limit = 1): array
    {
        $base = rtrim(Config::str('sd_url'), '/');
        $url  = $base . '/api/order/list/?' . http_build_query([
            'page'   => 1,
            'limit'  => $limit,
            'filter' => $filter,
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Form-Api-Key: ' . Config::str('sd_api_key')]);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        self::countCall();

        $decoded = is_string($body) ? json_decode($body, true) : null;

        return [
            'code'  => $code,
            'error' => $err,
            'body'  => is_string($body) ? $body : '',
            'data'  => is_array($decoded) ? $decoded : null,
            'url'   => $url,
        ];
    }

    /**
     * Як система бачить замовлення після мапінгу полів.
     *
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    public static function diagMap(array $order): array
    {
        return self::mapOrder($order);
    }

    /**
     * Скинути кеш пошуку — щоб перевіряти зміни без очікування 15 хвилин.
     */
    public static function clearCache(): int
    {
        try {
            return Db::run('DELETE FROM sd_cache')->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Перебирає варіанти фільтрів і показує, який з них працює в цьому акаунті.
     *
     * @return array<int,array{name:string,filter:array<string,mixed>,code:int,count:int,matched:bool,sample:array<string,mixed>|null,note:string}>
     */
    public static function diagProbe(string $orderNumber, string $phone = ''): array
    {
        $needle = self::normalizeNumber($orderNumber);
        $from   = date('Y-m-d', strtotime('-' . Config::int('sd_search_days', 120) . ' days'));

        // Пошук за телефоном — найцінніший варіант: не залежить від того,
        // як зветься поле з номером, і одразу дає обидва номери клієнта.
        $tel = Validate::phone($phone);
        if ($tel !== null) {
            return self::probePhone($tel, $from);
        }

        // Вже перевірено й доведено контрольними запитами:
        //   filter[externalId] — справжній (999999999 -> 0 рядків)
        //   filter[id]         — фіктивний, ігнорується
        //   filter[phone]      — фіктивний, ігнорується
        //
        // Лишилась одна гіпотеза: orderTime фільтрується як ДІАПАЗОН
        // (filter[orderTime][from]), тож числові поля можуть вимагати
        // такого ж синтаксису — filter[id][from] / [to].
        return self::runProbes([
            [
                'name'    => 'КОНТРОЛЬ: filter[id][from..to] = 999999999 (немає такого)',
                'filter'  => ['id' => ['from' => '999999999', 'to' => '999999999'], 'statusId' => '__ALL__'],
                'limit'   => 20,
                'control' => true,
            ],
            [
                'name'   => 'filter[id][from..to] = ' . $needle,
                'filter' => ['id' => ['from' => $needle, 'to' => $needle], 'statusId' => '__ALL__'],
                'limit'  => 20,
            ],
            [
                'name'    => 'КОНТРОЛЬ: filter[ord_id] = 999999999',
                'filter'  => ['ord_id' => '999999999', 'statusId' => '__ALL__'],
                'limit'   => 20,
                'control' => true,
            ],
            [
                'name'    => 'КОНТРОЛЬ (перевірка методики): filter[externalId] = 999999999 — має дати 0',
                'filter'  => ['externalId' => '999999999', 'statusId' => '__ALL__'],
                'limit'   => 20,
                'control' => true,
            ],
        ], $needle, '');
    }

    /**
     * Виконує батарею запитів і інтерпретує результат.
     * Перший запит у списку має бути «канаркою».
     *
     * @param array<int,array{name:string,filter:array<string,mixed>,limit:int}> $probes
     * @return array<int,array<string,mixed>>
     */
    private static function runProbes(array $probes, string $needle, string $phone): array
    {
        $out           = [];
        $canaryCount   = null;  // скільки рядків повернув запит із неіснуючим полем
        $canaryIgnored = false; // чи ігнорує SalesDrive невідомі фільтри

        foreach ($probes as $i => $p) {
            // Ліміт 10/хв. Якщо запас вичерпано — не смітимо запитами,
            // інакше решта рядків буде «протестована» помилкою ліміту.
            if (!self::rateOk()) {
                $out[] = [
                    'name'    => $p['name'],
                    'filter'  => $p['filter'],
                    'code'    => 0,
                    'count'   => 0,
                    'matched' => false,
                    'sample'  => null,
                    'note'    => '⏳ НЕ ПЕРЕВІРЕНО — вичерпано ліміт 10 запитів/хв. Зачекайте хвилину і запустіть ще раз.',
                ];
                continue;
            }

            $r    = self::diagList($p['filter'], $p['limit']);
            $rows = (is_array($r['data']) && isset($r['data']['data']) && is_array($r['data']['data']))
                ? $r['data']['data'] : [];
            $count = count($rows);

            // SalesDrive повертає перевищення ліміту як HTTP 400 з текстом,
            // а не як 429 — розпізнаємо за повідомленням.
            $rateHit = stripos($r['body'], 'API limit reached') !== false;

            $matched = false;
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if ($needle !== '' && self::orderNumberMatches($row, $needle)) {
                    $matched = true;
                    break;
                }
                if ($phone !== '' && self::phoneMatches($row, $phone)) {
                    $matched = true;
                    break;
                }
            }

            $isCanary = ($i === 0);
            if ($isCanary) {
                $canaryCount   = $count;
                $canaryIgnored = ($r['code'] >= 200 && $r['code'] < 300 && $count > 0);
            }

            $isControl = !empty($p['control']);

            $note = '';
            if ($rateHit) {
                $note = '⏳ НЕ ПЕРЕВІРЕНО — ліміт 10 запитів/хв. Зачекайте хвилину і повторіть.';
            } elseif ($r['code'] === 0) {
                $note = 'немає звʼязку: ' . $r['error'];
            } elseif ($r['code'] === 401 || $r['code'] === 403) {
                $note = 'ключ без прав на читання заявок';
            } elseif ($r['code'] === 404) {
                $note = 'невірний домен SD_URL';
            } elseif ($r['code'] >= 400) {
                $note = 'фільтр відхилено: ' . mb_substr(strip_tags($r['body']), 0, 140);
            } elseif ($isCanary) {
                $note = $canaryIgnored
                    ? '⚠️ невідомі фільтри ІГНОРУЮТЬСЯ — зеленим позначкам нижче вірити не можна'
                    : '✔ невідомі фільтри відсіваються';
            } elseif ($isControl) {
                // Головний рядок: неіснуюче значення НЕ має нічого повертати
                $note = $count > 0
                    ? '⚠️ ФІЛЬТР ФІКТИВНИЙ: неіснуюче значення повернуло ' . $count . ' рядків — фільтр ігнорується'
                    : '✔ ФІЛЬТР СПРАВЖНІЙ: неіснуюче значення дало 0 рядків';
            } elseif ($count >= $p['limit']) {
                $note = '⚠️ повернуто рівно ' . $count . ' = ліміт вибірки. Схоже на «віддали останні N», а не на фільтрацію';
            } elseif ($matched) {
                $note = '✔ знайдено, і вибірка вузька (' . $count . ') — фільтр справді працює';
            } elseif ($count === 0) {
                $note = 'фільтр працює, але нічого не знайдено';
            }

            $out[] = [
                'name'    => $p['name'],
                'filter'  => $p['filter'],
                'code'    => $r['code'],
                'count'   => $count,
                'matched' => $matched,
                'sample'  => isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null,
                'note'    => $note,
            ];
        }

        return $out;
    }

    /**
     * Перебирає можливі назви фільтра за телефоном.
     * Телефон пробуємо в кількох форматах — SalesDrive може зберігати
     * його як завгодно (380..., +380..., 0...).
     *
     * @return array<int,array<string,mixed>>
     */
    private static function probePhone(string $phone, string $from): array
    {
        // 380667710738 -> 0667710738 (у номері вже є 38 на початку, не «0» + решта)
        $local = substr($phone, 2);

        // Ліміт SalesDrive — 10 запитів/хв, тому лише 4 запити за прогін.
        // Ключовий тут не «реальний», а КОНТРОЛЬНИЙ запит із неіснуючим номером:
        // якщо він поверне рядки — фільтр фіктивний, його ігнорують.
        return self::runProbes([
            [
                'name'   => 'Канарка: filter[zzz_neisnuyuche_pole]',
                'filter' => ['zzz_neisnuyuche_pole' => $phone, 'statusId' => '__ALL__'],
                'limit'  => 5,
            ],
            [
                'name'    => 'КОНТРОЛЬ: filter[phone] = 380000000000 (такого номера немає)',
                'filter'  => ['phone' => '380000000000', 'statusId' => '__ALL__'],
                'limit'   => 20,
                'control' => true,
            ],
            [
                'name'   => 'filter[phone] = ' . $phone,
                'filter' => ['phone' => $phone, 'statusId' => '__ALL__'],
                'limit'  => 20,
            ],
            [
                'name'   => 'filter[phone] = ' . $local,
                'filter' => ['phone' => $local, 'statusId' => '__ALL__'],
                'limit'  => 20,
            ],
        ], '', $phone);
    }

    // ---- Rate limiting ----

    private static function countCall(): void
    {
        try {
            Db::insert('sd_calls', ['called_at' => date('Y-m-d H:i:s')]);
            Db::run('DELETE FROM sd_calls WHERE called_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
        } catch (\Throwable $e) {
            // не блокуємо основний сценарій
        }
    }

    private static function rateOk(): bool
    {
        try {
            $perMin = (int)Db::value(
                'SELECT COUNT(*) FROM sd_calls WHERE called_at > ?',
                [date('Y-m-d H:i:s', time() - 60)]
            );
            if ($perMin >= self::RATE_PER_MIN) {
                return false;
            }
            $perHour = (int)Db::value(
                'SELECT COUNT(*) FROM sd_calls WHERE called_at > ?',
                [date('Y-m-d H:i:s', time() - 3600)]
            );
            return $perHour < self::RATE_PER_HOUR;
        } catch (\Throwable $e) {
            return true;
        }
    }

    // ---- Кеш ----

    /** @return array<string,mixed>|null */
    private static function cacheGet(string $key): ?array
    {
        try {
            $row = Db::one('SELECT payload FROM sd_cache WHERE cache_key = ? AND expires_at > NOW()', [$key]);
            if ($row === null) {
                return null;
            }
            $data = json_decode((string)$row['payload'], true);
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @param array<string,mixed> $value */
    private static function cachePut(string $key, array $value, int $ttl): void
    {
        try {
            Db::run(
                'INSERT INTO sd_cache (cache_key, payload, expires_at) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE payload = VALUES(payload), expires_at = VALUES(expires_at)',
                [$key, json_encode($value, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s', time() + $ttl)]
            );
            Db::run('DELETE FROM sd_cache WHERE expires_at < NOW()');
        } catch (\Throwable $e) {
            // кеш не критичний
        }
    }

    private static function log(string $message): void
    {
        $file = BASE_PATH . '/storage/logs/salesdrive.log';
        @file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
    }
}
