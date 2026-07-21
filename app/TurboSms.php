<?php
declare(strict_types=1);

namespace App;

/**
 * Відправка Viber + SMS через TurboSMS.
 *
 * Документація: https://turbosms.ua/api.html
 * Гібридна відправка: у запиті одночасно viber і sms — сервіс шле Viber,
 * а якщо клієнт не в Viber або повідомлення не доставлено, автоматично
 * надсилає звичайну SMS. Один запит — один фолбек.
 *
 * Ендпоінт: POST https://api.turbosms.ua/message/send.json
 * Авторизація: Authorization: Bearer TOKEN
 */
class TurboSms
{
    const ENDPOINT = 'https://api.turbosms.ua/message/send.json';

    public static function enabled(): bool
    {
        return Config::bool('sms_enabled', false)
            && Config::str('sms_token') !== '';
    }

    /**
     * @return array{ok:bool,error:string,channel:string}
     */
    public static function send(string $phone, string $text): array
    {
        if (!self::enabled()) {
            self::log('Пропущено (вимкнено): ' . mb_substr($text, 0, 60));
            return ['ok' => false, 'error' => 'sms_disabled', 'channel' => ''];
        }

        $to = Validate::phone($phone);
        if ($to === null) {
            return ['ok' => false, 'error' => 'Некоректний телефон', 'channel' => ''];
        }

        $smsSender   = Config::str('sms_sender');   // зареєстроване альфа-імʼя
        $viberSender = Config::str('sms_viber_sender', $smsSender);

        $payload = [
            'recipients' => [$to],
            'sms'        => ['sender' => $smsSender, 'text' => $text],
        ];
        // Viber додаємо, лише якщо є відправник — інакше піде чиста SMS
        if ($viberSender !== '') {
            $payload['viber'] = ['sender' => $viberSender, 'text' => $text];
        }

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . Config::str('sms_token'),
                'Content-Type: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            // TurboSMS просить не ставити жорсткий таймаут, щоб уникнути дублів;
            // тримаємо запас
            CURLOPT_TIMEOUT        => 40,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return self::fail('cURL: ' . $err);
        }

        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            return self::fail('Некоректна відповідь (HTTP ' . $code . ')');
        }

        $rc = (int)($data['response_code'] ?? -1);
        // 0 = OK; 800..803 = повністю/частково прийнято
        $accepted = ($rc === 0) || ($rc >= 800 && $rc <= 803);

        // Розбираємо результати по каналах/отримувачах.
        // У гібридній відправці Viber і SMS — окремі записи: якщо Viber
        // доставлено, SMS-фолбек не шлеться й повертає NOT_ALLOWED_MESSAGE_DUPLICATE.
        // Це НЕ помилка — повідомлення вже дійшло через Viber.
        $delivered = false;   // хоч один канал прийнято
        $hardError = null;    // справжня відмова (номер, баланс тощо)

        // «мʼякі» статуси — не вважаємо провалом
        $soft = ['NOT_ALLOWED_MESSAGE_DUPLICATE'];

        $results = isset($data['response_result']) && is_array($data['response_result'])
            ? $data['response_result'] : [];
        foreach (self::flattenResults($results) as $item) {
            $itemCode   = (int)($item['response_code'] ?? -1);
            $itemStatus = (string)($item['response_status'] ?? '');
            $hasId      = !empty($item['message_id']);

            if ($itemCode === 0 || $hasId) {
                $delivered = true;
            } elseif (in_array($itemStatus, $soft, true)) {
                // повідомлення вже доставлено іншим каналом — теж успіх
                $delivered = true;
            } elseif ($itemStatus !== '' && $hardError === null) {
                $hardError = $itemStatus;
            }
        }

        if ($delivered || ($accepted && $hardError === null)) {
            self::log('Надіслано на ' . $to);
            return ['ok' => true, 'error' => '', 'channel' => 'viber_sms'];
        }

        if ($hardError !== null) {
            return self::fail('Отримувач: ' . $hardError);
        }
        return self::fail('response_code=' . $rc . ' ' . ($data['response_status'] ?? ''));
    }

    /**
     * TurboSMS повертає результати або плоским списком по отримувачах,
     * або з вкладеними каналами (sms/viber). Зводимо до плоского списку.
     *
     * @param array<int|string,mixed> $results
     * @return array<int,array<string,mixed>>
     */
    private static function flattenResults(array $results): array
    {
        $flat = [];
        foreach ($results as $r) {
            if (!is_array($r)) {
                continue;
            }
            // вкладені канали: {"sms": {...}, "viber": {...}}
            $nested = false;
            foreach (['viber', 'sms'] as $ch) {
                if (isset($r[$ch]) && is_array($r[$ch])) {
                    $flat[] = $r[$ch];
                    $nested = true;
                }
            }
            if (!$nested) {
                $flat[] = $r;
            }
        }
        return $flat;
    }

    /**
     * @return array{ok:bool,error:string,channel:string}
     */
    private static function fail(string $msg): array
    {
        self::log('Помилка: ' . $msg);
        return ['ok' => false, 'error' => $msg, 'channel' => ''];
    }

    private static function log(string $message): void
    {
        @file_put_contents(
            BASE_PATH . '/storage/logs/turbosms.log',
            '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
            FILE_APPEND
        );
    }
}
