<?php
declare(strict_types=1);

namespace App;

/**
 * Нова пошта — зворотні накладні (повернення).
 *
 * Повернення створюється на основі ОРИГІНАЛЬНОЇ ТТН, якою товар їхав до
 * клієнта (номер лежить у замовленні SalesDrive). Створити його може лише
 * контрагент, який був відправником цього замовлення — тому підтримуємо
 * два API-ключі й самі визначаємо, який із них «свій» для конкретної ТТН.
 *
 * API 2.0: POST https://api.novaposhta.ua/v2.0/json/
 * Тіло: {apiKey, modelName, calledMethod, methodProperties:{...}}
 */
class NovaPoshta
{
    const ENDPOINT = 'https://api.novaposhta.ua/v2.0/json/';

    public static function enabled(): bool
    {
        return Config::bool('np_enabled', false)
            && (Config::str('np_key1') !== '' || Config::str('np_key2') !== '');
    }

    /**
     * Ключі у порядку спроби. Порожні відкидаємо.
     *
     * @return array<int,array{idx:int,key:string}>
     */
    public static function keys(): array
    {
        $out = [];
        foreach ([1, 2] as $i) {
            $k = Config::str('np_key' . $i);
            if ($k !== '') {
                $out[] = ['idx' => $i, 'key' => $k];
            }
        }
        return $out;
    }

    /**
     * Базовий запит до API.
     *
     * @param array<string,mixed> $properties
     * @return array{ok:bool,success:bool,data:array<int,mixed>,errors:array<int,string>,warnings:array<int,string>,raw:array<string,mixed>|null,http:int,error:string}
     */
    public static function request(string $apiKey, string $model, string $method, array $properties = []): array
    {
        $payload = [
            'apiKey'         => $apiKey,
            'modelName'      => $model,
            'calledMethod'   => $method,
            'methodProperties' => (object)$properties,
        ];

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $base = [
            'ok' => false, 'success' => false, 'data' => [],
            'errors' => [], 'warnings' => [], 'raw' => null, 'http' => $http, 'error' => '',
        ];

        if ($body === false) {
            self::log('cURL: ' . $err . ' (' . $method . ')');
            $base['error'] = $err;
            return $base;
        }

        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            $base['error'] = 'Некоректний JSON (HTTP ' . $http . ')';
            return $base;
        }

        $base['ok']       = true;
        $base['raw']      = $decoded;
        $base['success']  = !empty($decoded['success']);
        $base['data']     = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : [];
        $base['errors']   = self::strList($decoded['errors'] ?? []);
        $base['warnings'] = self::strList($decoded['warnings'] ?? []);
        if (!$base['success'] && $base['error'] === '') {
            $base['error'] = implode('; ', $base['errors']) ?: 'Невідома помилка API';
        }
        return $base;
    }

    /**
     * Перевірка можливості створення повернення по номеру ТТН.
     *
     * @return array{ok:bool,success:bool,data:array<int,mixed>,errors:array<int,string>,warnings:array<int,string>,raw:array<string,mixed>|null,http:int,error:string}
     */
    public static function checkReturn(string $apiKey, string $ttn): array
    {
        return self::request($apiKey, 'AdditionalService', 'CheckPossibilityCreateReturn', [
            'Number' => trim($ttn),
        ]);
    }

    /**
     * Знаходить ключ (відправника), яким можна створити повернення по цій ТТН.
     * Перебирає ключі в порядку налаштувань і зупиняється на першому, який
     * підтвердив можливість.
     *
     * @return array{found:bool,keyIndex:int,apiKey:string,data:array<int,mixed>,reason:string}
     */
    public static function resolveSender(string $ttn): array
    {
        foreach (self::keys() as $k) {
            $r = self::checkReturn($k['key'], $ttn);
            if ($r['success'] && $r['data'] !== []) {
                return [
                    'found'    => true,
                    'keyIndex' => $k['idx'],
                    'apiKey'   => $k['key'],
                    'data'     => $r['data'],
                    'reason'   => '',
                ];
            }
        }
        return ['found' => false, 'keyIndex' => 0, 'apiKey' => '', 'data' => [], 'reason' => 'no_sender_matched'];
    }

    /**
     * Довідник причин повернення НП.
     *
     * @return array{ok:bool,success:bool,data:array<int,mixed>,errors:array<int,string>,warnings:array<int,string>,raw:array<string,mixed>|null,http:int,error:string}
     */
    public static function returnReasons(string $apiKey): array
    {
        return self::request($apiKey, 'AdditionalService', 'getReturnReasons', []);
    }

    /**
     * Контрагент-відправник акаунта (потрібен для створення накладної).
     *
     * @return array{ok:bool,success:bool,data:array<int,mixed>,errors:array<int,string>,warnings:array<int,string>,raw:array<string,mixed>|null,http:int,error:string}
     */
    public static function senderCounterparty(string $apiKey): array
    {
        return self::request($apiKey, 'Counterparty', 'getCounterparties', [
            'CounterpartyProperty' => 'Sender',
            'Page'                 => '1',
        ]);
    }

    /**
     * Контактні особи контрагента.
     *
     * @return array{ok:bool,success:bool,data:array<int,mixed>,errors:array<int,string>,warnings:array<int,string>,raw:array<string,mixed>|null,http:int,error:string}
     */
    public static function contactPersons(string $apiKey, string $counterpartyRef): array
    {
        return self::request($apiKey, 'Counterparty', 'getCounterpartyContactPersons', [
            'Ref'  => $counterpartyRef,
            'Page' => '1',
        ]);
    }

    /**
     * Зведення про акаунт: контрагент (відправник або отримувач) + контакти.
     *
     * @return array{counterparty:array<string,mixed>|null,contact:array<string,mixed>|null,contacts:array<int,mixed>,ref:string,contactRef:string,phone:string,error:string}
     */
    public static function partyInfo(string $apiKey, string $property = 'Sender'): array
    {
        $cp = self::request($apiKey, 'Counterparty', 'getCounterparties', [
            'CounterpartyProperty' => $property,
            'Page'                 => '1',
        ]);
        $empty = ['counterparty' => null, 'contact' => null, 'contacts' => [], 'ref' => '', 'contactRef' => '', 'phone' => '', 'error' => ''];
        if (!$cp['success'] || $cp['data'] === []) {
            $empty['error'] = implode('; ', $cp['errors']) ?: 'контрагента не знайдено';
            return $empty;
        }
        $first = is_array($cp['data'][0] ?? null) ? $cp['data'][0] : null;
        $ref   = is_array($first) ? (string)($first['Ref'] ?? '') : '';

        $contacts = [];
        $contact  = null;
        if ($ref !== '') {
            $cpp = self::contactPersons($apiKey, $ref);
            if ($cpp['success']) {
                $contacts = $cpp['data'];
                $contact  = is_array($contacts[0] ?? null) ? $contacts[0] : null;
            }
        }
        return [
            'counterparty' => $first,
            'contact'      => $contact,
            'contacts'     => $contacts,
            'ref'          => $ref,
            'contactRef'   => $contact !== null ? (string)($contact['Ref'] ?? '') : '',
            'phone'        => $contact !== null ? (string)($contact['Phones'] ?? '') : '',
            'error'        => '',
        ];
    }

    /**
     * Сумісність зі старою діагностикою.
     *
     * @return array{counterparty:array<string,mixed>|null,contacts:array<int,mixed>,error:string}
     */
    public static function senderInfo(string $apiKey): array
    {
        $p = self::partyInfo($apiKey, 'Sender');
        return ['counterparty' => $p['counterparty'], 'contacts' => $p['contacts'], 'error' => $p['error']];
    }

    /**
     * Створити накладну (InternetDocument.save).
     *
     * @param array<string,mixed> $props
     * @return array{ok:bool,success:bool,data:array<int,mixed>,errors:array<int,string>,warnings:array<int,string>,raw:array<string,mixed>|null,http:int,error:string}
     */
    public static function createDocument(string $apiKey, array $props): array
    {
        return self::request($apiKey, 'InternetDocument', 'save', $props);
    }

    /**
     * Видалити накладну.
     *
     * @return array{ok:bool,success:bool,data:array<int,mixed>,errors:array<int,string>,warnings:array<int,string>,raw:array<string,mixed>|null,http:int,error:string}
     */
    public static function deleteDocument(string $apiKey, string $ref): array
    {
        return self::request($apiKey, 'InternetDocument', 'delete', ['DocumentRefs' => $ref]);
    }

    /**
     * Ключ ФОПа, який обслуговує повернення (з налаштувань).
     */
    public static function recipientKey(): string
    {
        $idx = Config::str('np_recipient_key', '1');
        return Config::str('np_key' . ($idx === '2' ? '2' : '1'));
    }

    /**
     * Контрагент-отримувач повернень.
     *
     * ВАЖЛИВО: getCounterparties('Recipient') повертає збережених отримувачів
     * (людей, яким магазин щось відправляв) — брати [0] не можна, там випадкова
     * особа. Тому створюємо власного отримувача з даних ФОПа-відправника
     * (той самий ПІБ і телефон) і кешуємо його ref у налаштуваннях.
     *
     * @return array{ref:string,contactRef:string,phone:string,error:string}
     */
    public static function recipientParty(string $apiKey): array
    {
        $cpRef      = Config::str('np_recip_cp_ref');
        $contactRef = Config::str('np_recip_contact_ref');
        if ($cpRef !== '' && $contactRef !== '') {
            return ['ref' => $cpRef, 'contactRef' => $contactRef, 'phone' => Config::str('np_recip_phone'), 'error' => ''];
        }

        // створюємо отримувача з контакту відправника (ФОП)
        $sender = self::partyInfo($apiKey, 'Sender');
        $c = $sender['contact'];
        if (!is_array($c)) {
            return ['ref' => '', 'contactRef' => '', 'phone' => '', 'error' => 'немає контакту відправника'];
        }

        $phone = (string)($c['Phones'] ?? $sender['phone']);
        $r = self::request($apiKey, 'Counterparty', 'save', [
            'FirstName'            => (string)($c['FirstName'] ?? ''),
            'MiddleName'           => (string)($c['MiddleName'] ?? ''),
            'LastName'             => (string)($c['LastName'] ?? ''),
            'Phone'                => $phone,
            'Email'                => '',
            'CounterpartyType'     => 'PrivatePerson',
            'CounterpartyProperty' => 'Recipient',
        ]);
        $d = is_array($r['data'][0] ?? null) ? $r['data'][0] : null;
        if (!$r['success'] || $d === null) {
            return ['ref' => '', 'contactRef' => '', 'phone' => '', 'error' => implode('; ', $r['errors']) ?: 'не вдалося створити отримувача'];
        }

        $ref  = (string)($d['Ref'] ?? '');
        $cRef = '';
        if (isset($d['ContactPerson']['data'][0]['Ref'])) {
            $cRef = (string)$d['ContactPerson']['data'][0]['Ref'];
        }
        // якщо контакт не прийшов у відповіді — дотягуємо окремо
        if ($cRef === '' && $ref !== '') {
            $cpp = self::contactPersons($apiKey, $ref);
            if ($cpp['success'] && is_array($cpp['data'][0] ?? null)) {
                $cRef = (string)($cpp['data'][0]['Ref'] ?? '');
            }
        }
        if ($ref === '' || $cRef === '') {
            return ['ref' => '', 'contactRef' => '', 'phone' => '', 'error' => 'отримувача створено, але немає контакту'];
        }

        Config::save([
            'np_recip_cp_ref'      => $ref,
            'np_recip_contact_ref' => $cRef,
            'np_recip_phone'       => $phone,
        ]);

        return ['ref' => $ref, 'contactRef' => $cRef, 'phone' => $phone, 'error' => ''];
    }

    /**
     * PayerType за платником доставки заявки.
     * Клієнт платить -> Sender (на касі при здачі), магазин -> Recipient.
     */
    public static function payerType(string $shippingPayer): string
    {
        return $shippingPayer === 'customer' ? 'Sender' : 'Recipient';
    }

    /**
     * Готовність до створення накладних (ключ + точка прийому).
     */
    public static function ready(): bool
    {
        return self::enabled()
            && self::recipientKey() !== ''
            && Config::str('np_recipient_city_ref') !== ''
            && Config::str('np_recipient_wh_ref') !== '';
    }

    /**
     * Ціна зворотної накладної з міста клієнта на точку прийому.
     *
     * @return array{ok:bool,cost:float,error:string}
     */
    public static function priceFor(string $clientCityRef): array
    {
        $key = self::recipientKey();
        if ($key === '' || $clientCityRef === '') {
            return ['ok' => false, 'cost' => 0.0, 'error' => 'Немає ключа або міста'];
        }
        $r = self::documentPrice($key, $clientCityRef, Config::str('np_recipient_city_ref'));
        $cost = is_array($r['data'][0] ?? null) ? (float)($r['data'][0]['Cost'] ?? 0) : 0.0;
        return ['ok' => $r['success'], 'cost' => $cost, 'error' => implode('; ', $r['errors'])];
    }

    /**
     * Створити зворотну накладну для заявки.
     *
     * @param array<string,mixed> $rma
     * @return array{ok:bool,ttn:string,ref:string,cost:float,error:string}
     */
    public static function createReturn(array $rma, string $clientCityRef, string $clientWhRef, string $description, float $cost): array
    {
        $key = self::recipientKey();
        if ($key === '' || !self::ready()) {
            return ['ok' => false, 'ttn' => '', 'ref' => '', 'cost' => 0.0, 'error' => 'НП не налаштовано'];
        }
        if ($clientCityRef === '' || $clientWhRef === '') {
            return ['ok' => false, 'ttn' => '', 'ref' => '', 'cost' => 0.0, 'error' => 'Не вказано місто/відділення клієнта'];
        }

        $sender = self::partyInfo($key, 'Sender');
        $recip  = self::recipientParty($key);
        if ($sender['ref'] === '') {
            return ['ok' => false, 'ttn' => '', 'ref' => '', 'cost' => 0.0, 'error' => 'Не вдалося отримати відправника НП'];
        }
        if ($recip['ref'] === '') {
            return ['ok' => false, 'ttn' => '', 'ref' => '', 'cost' => 0.0, 'error' => 'Отримувач: ' . $recip['error']];
        }

        $props = [
            'PayerType'        => self::payerType((string)($rma['shipping_payer'] ?? '')),
            'PaymentMethod'    => 'Cash',
            'DateTime'         => date('d.m.Y'),
            'CargoType'        => 'Parcel',
            'Weight'           => Config::str('np_weight', '0.5'),
            'ServiceType'      => Config::str('np_service_type', 'WarehouseWarehouse'),
            'SeatsAmount'      => '1',
            'Description'      => $description !== '' ? mb_substr($description, 0, 200) : 'Повернення товару',
            'Cost'             => (string)($cost > 0 ? (int)round($cost) : 300),
            'CitySender'       => $clientCityRef,
            'Sender'           => $sender['ref'],
            'SenderAddress'    => $clientWhRef,
            'ContactSender'    => $sender['contactRef'],
            'SendersPhone'     => $sender['phone'],
            'CityRecipient'    => Config::str('np_recipient_city_ref'),
            'Recipient'        => $recip['ref'],
            'RecipientAddress' => Config::str('np_recipient_wh_ref'),
            'ContactRecipient' => $recip['contactRef'],
            'RecipientsPhone'  => $recip['phone'],
        ];

        $r   = self::createDocument($key, $props);
        $doc = is_array($r['data'][0] ?? null) ? $r['data'][0] : [];

        if (!$r['success']) {
            return ['ok' => false, 'ttn' => '', 'ref' => '', 'cost' => 0.0, 'error' => implode('; ', $r['errors'])];
        }
        return [
            'ok'   => true,
            'ttn'  => (string)($doc['IntDocNumber'] ?? ''),
            'ref'  => (string)($doc['Ref'] ?? ''),
            'cost' => (float)($doc['CostOnSite'] ?? $cost),
            'error'=> '',
        ];
    }

    /**
     * Видалити раніше створену накладну.
     *
     * @return array{ok:bool,error:string}
     */
    public static function cancelReturn(string $ref): array
    {
        $key = self::recipientKey();
        if ($key === '' || $ref === '') {
            return ['ok' => false, 'error' => 'Немає посилання на накладну'];
        }
        $r = self::deleteDocument($key, $ref);
        return ['ok' => $r['success'], 'error' => implode('; ', $r['errors'])];
    }

    /**
     * Пошук міст за назвою.
     *
     * @return array{ok:bool,success:bool,data:array<int,mixed>,errors:array<int,string>,warnings:array<int,string>,raw:array<string,mixed>|null,http:int,error:string}
     */
    public static function cities(string $apiKey, string $find): array
    {
        return self::request($apiKey, 'Address', 'getCities', [
            'FindByString' => trim($find),
            'Limit'        => '20',
            'Page'         => '1',
        ]);
    }

    /**
     * Відділення міста.
     *
     * @return array{ok:bool,success:bool,data:array<int,mixed>,errors:array<int,string>,warnings:array<int,string>,raw:array<string,mixed>|null,http:int,error:string}
     */
    public static function warehouses(string $apiKey, string $cityRef, string $find = ''): array
    {
        $props = ['CityRef' => $cityRef, 'Limit' => '50', 'Page' => '1'];
        if ($find !== '') {
            $props['FindByString'] = $find;
        }
        return self::request($apiKey, 'Address', 'getWarehouses', $props);
    }

    /**
     * Розрахунок вартості доставки (без створення накладної).
     *
     * @param array<string,mixed> $extra
     * @return array{ok:bool,success:bool,data:array<int,mixed>,errors:array<int,string>,warnings:array<int,string>,raw:array<string,mixed>|null,http:int,error:string}
     */
    public static function documentPrice(string $apiKey, string $citySender, string $cityRecipient, array $extra = []): array
    {
        return self::request($apiKey, 'InternetDocument', 'getDocumentPrice', array_merge([
            'CitySender'    => $citySender,
            'CityRecipient' => $cityRecipient,
            'Weight'        => Config::str('np_weight', '0.5'),
            'ServiceType'   => Config::str('np_service_type', 'WarehouseWarehouse'),
            'Cost'          => '300',
            'CargoType'     => 'Parcel',
            'SeatsAmount'   => '1',
        ], $extra));
    }

    /**
     * Перший робочий ключ (для довідкових запитів — міста/відділення).
     */
    public static function anyKey(): string
    {
        $keys = self::keys();
        return $keys === [] ? '' : $keys[0]['key'];
    }

    /**
     * Трекінг посилок за номерами ТТН.
     *
     * @param array<int,array{ttn:string,phone:string}> $docs
     * @return array<string,array{code:int,status:string}>
     */
    public static function track(array $docs): array
    {
        $key = self::anyKey();
        if ($key === '' || $docs === []) {
            return [];
        }
        $documents = [];
        foreach ($docs as $d) {
            $documents[] = ['DocumentNumber' => $d['ttn'], 'Phone' => $d['phone'] ?? ''];
        }
        $r = self::request($key, 'TrackingDocument', 'getStatusDocuments', ['Documents' => $documents]);

        $out = [];
        foreach ($r['data'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $num = (string)($row['Number'] ?? '');
            if ($num === '') {
                continue;
            }
            $out[$num] = [
                'code'   => (int)($row['StatusCode'] ?? 0),
                'status' => (string)($row['Status'] ?? ''),
                // легке повернення (клієнт оформив сам у застосунку НП)
                'light_possible' => !empty($row['PossibilityLightReturn']),
                'light_ttn'      => (string)($row['LightReturnNumber'] ?? ''),
                'light_reason'   => (string)($row['UndeliveryReasonsSubtypeDescription'] ?? ''),
                // хто оплачує доставку та чи є грошові навантаження на ТТН
                'payer_type'    => (string)($row['PayerType'] ?? ''),
                'cod_sum'       => (float)($row['AfterpaymentOnGoodsCost'] ?? 0),
                'backward_sum'  => (float)($row['BackwardDeliverySum'] ?? 0),
                'document_cost' => (float)($row['DocumentCost'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Інфо про легке повернення по оригінальній ТТН замовлення.
     *
     * @return array{possible:bool,ttn:string,reason:string,found:bool}
     */
    public static function lightReturnInfo(string $originalTtn): array
    {
        $originalTtn = trim($originalTtn);
        if ($originalTtn === '') {
            return ['possible' => false, 'ttn' => '', 'reason' => '', 'found' => false];
        }
        $res = self::track([['ttn' => $originalTtn, 'phone' => Config::str('np_recip_phone')]]);
        $row = $res[$originalTtn] ?? null;
        if ($row === null) {
            return ['possible' => false, 'ttn' => '', 'reason' => '', 'found' => false];
        }
        return [
            'possible' => (bool)$row['light_possible'],
            'ttn'      => (string)$row['light_ttn'],
            'reason'   => (string)$row['light_reason'],
            'found'    => true,
        ];
    }

    /**
     * Внутрішній статус заявки за кодом трекінгу НП (або null — без змін).
     *
     * НП StatusCode: 1 створено-не-здано; 4-6,41,101 у дорозі;
     * 7,8 прибуло у відділення; 9,10,11 отримано; 2,3 видалено/не знайдено.
     */
    public static function trackingTargetStatus(int $code): ?string
    {
        if ($code === 1) {
            return 'waiting_customer_shipment';
        }
        if (in_array($code, [9, 10, 11, 106], true)) {
            return 'received';
        }
        if (in_array($code, [4, 5, 6, 7, 8, 41, 101, 102, 103, 104, 105, 111, 112], true)) {
            return 'in_transit';
        }
        return null;
    }

    /**
     * @param mixed $v
     * @return array<int,string>
     */
    private static function strList($v): array
    {
        if (!is_array($v)) {
            return $v === '' || $v === null ? [] : [(string)$v];
        }
        $out = [];
        foreach ($v as $item) {
            if (is_array($item)) {
                foreach ($item as $s) {
                    $out[] = (string)$s;
                }
            } else {
                $out[] = (string)$item;
            }
        }
        return $out;
    }

    private static function log(string $message): void
    {
        @file_put_contents(
            BASE_PATH . '/storage/logs/novaposhta.log',
            '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
            FILE_APPEND
        );
    }
}
