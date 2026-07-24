<?php
declare(strict_types=1);

namespace App;

class Telegram
{
    public static function enabled(): bool
    {
        return Config::bool('tg_enabled', false)
            && Config::str('tg_bot_token') !== ''
            && Config::str('tg_chat_id') !== '';
    }

    /** Чи ввімкнено конкретну подію (за замовчуванням — так). */
    public static function event(string $key): bool
    {
        return self::enabled() && Config::bool('tg_ev_' . $key, true);
    }

    /**
     * Повідомлення про нову заявку (п.13.1 ТЗ).
     * @param array<string,mixed> $rma
     */
    public static function newRma(array $rma): bool
    {
        if (!self::event('new')) { return false; }
        $items = Rma::items((int)$rma['id']);
        $first = $items[0] ?? null;

        $lines = [
            '🔁 <b>Нова заявка на повернення</b>',
            '',
            'Номер заявки: <b>' . e($rma['rma_number']) . '</b>',
            'Замовлення: №' . e($rma['order_number']) . (empty($rma['order_found']) ? ' ⚠️ <i>не знайдено в CRM</i>' : ''),
            'Клієнт: ' . e($rma['customer_name'] ?: '—'),
            'Телефон: ' . e(Validate::phoneFormat((string)$rma['phone'])),
            'Товар: ' . e($first !== null ? $first['name'] : '—'),
            'Артикул: ' . e($first !== null ? ($first['sku'] ?: '—') : '—'),
            'Причина: ' . e(Dict::reason((string)$rma['reason_code'])),
            'Дія: ' . e(Dict::action((string)$rma['desired_action'])),
        ];

        if (!empty($rma['needs_manual_check'])) {
            $lines[] = '';
            $lines[] = '⚠️ <b>Потребує ручної перевірки</b>';
        }

        $lines[] = '';
        $lines[] = 'Відкрити заявку:';
        $lines[] = Rma::adminUrl((int)$rma['id']);

        return self::send(implode("\n", $lines));
    }

    /**
     * Повідомлення про ТТН (п.13.2 ТЗ).
     * @param array<string,mixed> $rma
     */
    public static function ttnAdded(array $rma, string $ttn): bool
    {
        if (!self::event('ttn')) { return false; }
        $items = Rma::items((int)$rma['id']);
        $first = $items[0] ?? null;

        $lines = [
            '📦 <b>Клієнт додав ТТН по поверненню</b>',
            '',
            'Заявка: <b>' . e($rma['rma_number']) . '</b>',
            'ТТН: <code>' . e($ttn) . '</code>',
            'Товар: ' . e($first !== null ? $first['name'] : '—'),
            '',
            'Відкрити заявку:',
            Rma::adminUrl((int)$rma['id']),
        ];

        return self::send(implode("\n", $lines));
    }

    /**
     * Маячок: на зворотній ТТН є оплата, хоча платити мав клієнт.
     * @param array<string,mixed> $rma
     */
    public static function costAlert(array $rma, string $note): bool
    {
        if (!self::event('cost')) { return false; }
        $lines = [
            '⚠️ <b>Оплата на зворотній ТТН — платити мав клієнт</b>',
            '',
            'Заявка: <b>' . e($rma['rma_number']) . '</b>',
            'ТТН: <code>' . e((string)$rma['return_ttn']) . '</code>',
            e($note),
            '',
            'Перевірте перед отриманням посилки:',
            Rma::adminUrl((int)$rma['id']),
        ];

        return self::send(implode("\n", $lines));
    }

    /**
     * Нагадування про завислу заявку (п.13.3 ТЗ).
     * @param array<string,mixed> $rma
     */
    public static function stale(array $rma, int $hours): bool
    {
        if (!self::event('stale')) { return false; }
        $lines = [
            '⏰ <b>Заявка на повернення очікує обробки понад ' . $hours . ' годин</b>',
            '',
            'Заявка: <b>' . e($rma['rma_number']) . '</b>',
            'Статус: ' . e(Dict::status((string)$rma['status'])),
            'Створено: ' . dt((string)$rma['created_at']),
            '',
            Rma::adminUrl((int)$rma['id']),
        ];

        return self::send(implode("\n", $lines));
    }

    public static function send(string $text, ?string $chatId = null): bool
    {
        if (!self::enabled()) {
            self::log('Пропущено (вимкнено): ' . mb_substr(strip_tags($text), 0, 120));
            return false;
        }
        return self::deliver($text, $chatId ?? Config::str('tg_chat_id')) === '';
    }

    /**
     * Тестова відправка — не залежить від прапорця «увімкнено»,
     * але потрібні збережені токен і Chat ID.
     *
     * @return array{ok:bool,error:string}
     */
    public static function sendTest(): array
    {
        if (Config::str('tg_bot_token') === '' || Config::str('tg_chat_id') === '') {
            return ['ok' => false, 'error' => 'Спершу збережіть токен бота та Chat ID.'];
        }
        $err = self::deliver(
            '✅ <b>Тестове повідомлення</b>' . "\n" .
            'Сервіс повернень підключено до цього чату. Сюди приходитимуть сповіщення.',
            Config::str('tg_chat_id')
        );
        return ['ok' => $err === '', 'error' => $err];
    }

    /**
     * Власне доставка в Telegram. Повертає '' при успіху або текст помилки.
     */
    private static function deliver(string $text, string $chatId): string
    {
        $url = 'https://api.telegram.org/bot' . Config::str('tg_bot_token') . '/sendMessage';
        $payload = [
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $code !== 200) {
            $detail = $err !== '' ? $err : substr((string)$body, 0, 300);
            self::log('Помилка ' . $code . ': ' . $detail);
            // дістаємо людський опис із відповіді Telegram
            $desc = '';
            if (is_string($body)) {
                $j = json_decode($body, true);
                if (is_array($j) && !empty($j['description'])) {
                    $desc = (string)$j['description'];
                }
            }
            return 'Telegram: ' . ($desc !== '' ? $desc : ('код ' . $code . ($detail !== '' ? ', ' . $detail : '')));
        }
        return '';
    }

    private static function log(string $message): void
    {
        $file = BASE_PATH . '/storage/logs/telegram.log';
        @file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
    }
}
