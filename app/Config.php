<?php
declare(strict_types=1);

namespace App;

/**
 * Налаштування, які можна змінювати з адмінки.
 *
 * Значення береться з таблиці settings (ключі з префіксом cfg_).
 * Якщо його там немає — відкат на .env. Тобто .env лишається дефолтом,
 * а адмінка перекриває його без правки файлів.
 */
class Config
{
    /** @var array<string,string>|null */
    private static $cache = null;

    /**
     * key => [env: назва змінної в .env, type: тип, secret: чи це пароль/токен]
     *
     * @return array<string,array{env:string,type:string,secret:bool}>
     */
    public static function schema(): array
    {
        return [
            'mail_enabled'      => ['env' => 'MAIL_ENABLED',      'type' => 'bool', 'secret' => false],
            'mail_host'         => ['env' => 'MAIL_HOST',         'type' => 'str',  'secret' => false],
            'mail_port'         => ['env' => 'MAIL_PORT',         'type' => 'int',  'secret' => false],
            'mail_secure'       => ['env' => 'MAIL_SECURE',       'type' => 'str',  'secret' => false],
            'mail_user'         => ['env' => 'MAIL_USER',         'type' => 'str',  'secret' => false],
            'mail_pass'         => ['env' => 'MAIL_PASS',         'type' => 'str',  'secret' => true],
            'mail_from'         => ['env' => 'MAIL_FROM',         'type' => 'str',  'secret' => false],
            'mail_from_name'    => ['env' => 'MAIL_FROM_NAME',    'type' => 'str',  'secret' => false],

            'sms_enabled'       => ['env' => 'TURBOSMS_ENABLED',       'type' => 'bool', 'secret' => false],
            'sms_token'         => ['env' => 'TURBOSMS_TOKEN',         'type' => 'str',  'secret' => true],
            'sms_sender'        => ['env' => 'TURBOSMS_SMS_SENDER',    'type' => 'str',  'secret' => false],
            'sms_viber_sender'  => ['env' => 'TURBOSMS_VIBER_SENDER',  'type' => 'str',  'secret' => false],

            // SalesDrive
            'sd_enabled'        => ['env' => 'SD_ENABLED',     'type' => 'bool', 'secret' => false],
            'sd_url'            => ['env' => 'SD_URL',         'type' => 'str',  'secret' => false],
            'sd_api_key'        => ['env' => 'SD_API_KEY',     'type' => 'str',  'secret' => true],
            'sd_form_key'       => ['env' => 'SD_FORM_KEY',    'type' => 'str',  'secret' => true],
            'sd_search_days'    => ['env' => 'SD_SEARCH_DAYS', 'type' => 'int',  'secret' => false],

            // Нова пошта — зворотні накладні
            'np_enabled'        => ['env' => 'NP_ENABLED',        'type' => 'bool', 'secret' => false],
            'np_key1'           => ['env' => 'NP_KEY1',           'type' => 'str',  'secret' => true],
            'np_key2'           => ['env' => 'NP_KEY2',           'type' => 'str',  'secret' => true],
            'np_weight'         => ['env' => 'NP_WEIGHT',         'type' => 'str',  'secret' => false],
            'np_service_type'   => ['env' => 'NP_SERVICE_TYPE',   'type' => 'str',  'secret' => false],
            // точка прийому повернень
            'np_recipient_key'      => ['env' => 'NP_RECIPIENT_KEY',      'type' => 'str', 'secret' => false],
            'np_recipient_city_ref' => ['env' => 'NP_RECIPIENT_CITY_REF', 'type' => 'str', 'secret' => false],
            'np_recipient_city_name'=> ['env' => 'NP_RECIPIENT_CITY_NAME','type' => 'str', 'secret' => false],
            'np_recipient_wh_ref'   => ['env' => 'NP_RECIPIENT_WH_REF',   'type' => 'str', 'secret' => false],
            'np_recipient_wh_name'  => ['env' => 'NP_RECIPIENT_WH_NAME',  'type' => 'str', 'secret' => false],
            // контрагент-отримувач повернень (створюється автоматично, кешується)
            'np_recip_cp_ref'       => ['env' => '', 'type' => 'str', 'secret' => false],
            'np_recip_contact_ref'  => ['env' => '', 'type' => 'str', 'secret' => false],
            'np_recip_phone'        => ['env' => '', 'type' => 'str', 'secret' => false],

            // Telegram-сповіщення менеджеру
            'tg_enabled'        => ['env' => 'TG_ENABLED',   'type' => 'bool', 'secret' => false],
            'tg_bot_token'      => ['env' => 'TG_BOT_TOKEN', 'type' => 'str',  'secret' => true],
            'tg_chat_id'        => ['env' => 'TG_CHAT_ID',   'type' => 'str',  'secret' => false],
            // які події надсилати (за замовчуванням усі)
            'tg_ev_new'         => ['env' => '', 'type' => 'bool', 'secret' => false],
            'tg_ev_ttn'         => ['env' => '', 'type' => 'bool', 'secret' => false],
            'tg_ev_cost'        => ['env' => '', 'type' => 'bool', 'secret' => false],
            'tg_ev_stale'       => ['env' => '', 'type' => 'bool', 'secret' => false],
        ];
    }

    private static function load(): void
    {
        if (self::$cache !== null) {
            return;
        }
        self::$cache = [];
        try {
            $rows = Db::all("SELECT k, v FROM settings WHERE k LIKE 'cfg_%'");
            foreach ($rows as $r) {
                self::$cache[substr((string)$r['k'], 4)] = (string)$r['v'];
            }
        } catch (\Throwable $e) {
            // БД недоступна — працюємо лише на .env
        }
    }

    /**
     * Сире значення: БД -> .env -> null.
     */
    private static function raw(string $key): ?string
    {
        self::load();
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        $def = self::schema()[$key] ?? null;
        if ($def === null) {
            return null;
        }
        $env = Env::get($def['env'], null);
        return $env === null ? null : (string)$env;
    }

    public static function str(string $key, string $default = ''): string
    {
        $v = self::raw($key);
        return $v === null ? $default : $v;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::raw($key);
        return ($v === null || $v === '') ? $default : (int)$v;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::raw($key);
        if ($v === null || $v === '') {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Чи є непорожнє значення (для показу «секрет збережено»).
     */
    public static function isSet(string $key): bool
    {
        $v = self::raw($key);
        return $v !== null && $v !== '';
    }

    /**
     * Зберегти набір значень в БД.
     * Секрети з порожнім значенням пропускаємо — щоб не затерти збережене.
     *
     * @param array<string,string> $values
     */
    public static function save(array $values): void
    {
        $schema = self::schema();
        foreach ($schema as $key => $def) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $val = trim((string)$values[$key]);
            if ($def['secret'] && $val === '') {
                continue; // лишаємо попередній секрет
            }
            Db::run(
                'INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)',
                ['cfg_' . $key, $val]
            );
        }
        self::$cache = null;
    }
}
