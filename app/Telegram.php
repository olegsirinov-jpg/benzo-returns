<?php
declare(strict_types=1);

namespace App;

class Telegram
{
    public static function enabled(): bool
    {
        return Env::bool('TG_ENABLED', false)
            && Env::str('TG_BOT_TOKEN') !== ''
            && Env::str('TG_CHAT_ID') !== '';
    }

    /**
     * Повідомлення про нову заявку (п.13.1 ТЗ).
     * @param array<string,mixed> $rma
     */
    public static function newRma(array $rma): bool
    {
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

        $url = 'https://api.telegram.org/bot' . Env::str('TG_BOT_TOKEN') . '/sendMessage';
        $payload = [
            'chat_id'                  => $chatId ?? Env::str('TG_CHAT_ID'),
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
            self::log('Помилка ' . $code . ': ' . ($err !== '' ? $err : substr((string)$body, 0, 300)));
            return false;
        }
        return true;
    }

    private static function log(string $message): void
    {
        $file = BASE_PATH . '/storage/logs/telegram.log';
        @file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
    }
}
