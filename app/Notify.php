<?php
declare(strict_types=1);

namespace App;

/**
 * Сповіщення клієнту про перебіг заявки.
 *
 * Email надсилається АВТОМАТИЧНО на всі помітні події (безкоштовно,
 * вміщає деталі й посилання).
 *
 * SMS/Viber через TurboSMS менеджер надсилає ВРУЧНУ з картки заявки —
 * бо це платно й потрібне не завжди. Шаблони готуються тут (smsTemplates),
 * а сама відправка йде через sendSms().
 *
 * Кожне надсилання логується в notifications; повторне те саме авто-email
 * не дублюється.
 */
class Notify
{
    /**
     * Події, на які автоматично йде email.
     *
     * @return array<int,string>
     */
    private static function emailEvents(): array
    {
        return ['created', 'need_more_info', 'approved', 'received', 'refunded', 'rejected'];
    }

    /**
     * Викликається при зміні статусу. event == код статусу.
     *
     * @param array<string,mixed> $rma
     */
    public static function statusChanged(array $rma, string $status): void
    {
        if (!in_array($status, self::emailEvents(), true)) {
            return;
        }
        self::dispatch($rma, $status);
    }

    /**
     * Викликається одразу після створення заявки клієнтом.
     *
     * Email іде як звичайно. Плюс — АВТОМАТИЧНА SMS/Viber із номером заявки
     * й посиланням на статус (лише ця подія; статуси далі шле менеджер вручну).
     *
     * @param array<string,mixed> $rma
     */
    public static function created(array $rma, bool $withSms = true): void
    {
        self::dispatch($rma, 'created');

        // авто-підтвердження в SMS/Viber
        if ($withSms && !empty($rma['phone']) && TurboSms::enabled()) {
            $tpl = self::template($rma, 'created');
            self::once((int)$rma['id'], 'created', 'sms', (string)$rma['phone'], function () use ($rma, $tpl) {
                $r = TurboSms::send((string)$rma['phone'], $tpl['sms']);
                return [$r['ok'], $r['error']];
            });
        }
    }

    /**
     * Автоматичний email по події.
     *
     * @param array<string,mixed> $rma
     */
    private static function dispatch(array $rma, string $event): void
    {
        if (empty($rma['email'])) {
            return;
        }
        $rmaId = (int)$rma['id'];
        $tpl   = self::template($rma, $event);

        self::once($rmaId, $event, 'email', (string)$rma['email'], function () use ($rma, $tpl) {
            $r = Mailer::send(
                (string)$rma['email'],
                (string)($rma['customer_name'] ?? ''),
                $tpl['subject'],
                $tpl['html']
            );
            return [$r['ok'], $r['error']];
        });
    }

    /**
     * Готові тексти SMS для ручної відправки менеджером.
     * Ключ підбирається за поточним статусом, але менеджер може змінити текст.
     *
     * @param array<string,mixed> $rma
     * @return array<string,array{label:string,text:string}>
     */
    public static function smsTemplates(array $rma): array
    {
        $out = [];

        // Якщо ТТН повернення вже є — окремий шаблон для її надсилання (першим).
        if (!empty($rma['return_ttn'])) {
            $number = (string)$rma['rma_number'];
            $ttn    = (string)$rma['return_ttn'];
            $link   = Rma::publicUrl($rma);
            if (($rma['carrier'] ?? '') === 'novaposhta') {
                $text = 'Повернення ' . $number . ' погоджено. Ми оформили накладну Нової пошти '
                      . $ttn . '. Прийдіть на будь-яке відділення НП і назвіть цей номер — товар відправлять. '
                      . 'Деталі: ' . $link;
            } else {
                $text = 'Повернення ' . $number . ' погоджено. Відправте товар за накладною ' . $ttn
                      . '. Деталі: ' . $link;
            }
            $out['ttn'] = ['label' => 'Надіслати ТТН повернення', 'text' => $text];
        }

        // Якщо повернення коштів, а реквізитів ще немає — шаблон із проханням їх вказати.
        if (($rma['desired_action'] ?? '') === 'refund' && empty($rma['refund_iban'])) {
            $out['refund_details'] = [
                'label' => 'Запросити реквізити для повернення коштів',
                'text'  => 'Повернення ' . (string)$rma['rma_number'] . ': кошти повернемо на ваш рахунок. '
                         . 'Будь ласка, вкажіть реквізити (IBAN, ІПН, ПІБ) за посиланням — це займе хвилину: '
                         . Rma::publicUrl($rma),
            ];
        }

        foreach (['need_more_info', 'approved', 'received', 'refunded', 'rejected', 'created'] as $event) {
            $tpl = self::template($rma, $event);
            $out[$event] = ['label' => $tpl['subject'], 'text' => $tpl['sms']];
        }
        return $out;
    }

    /**
     * Ручна відправка SMS/Viber менеджером. Логує результат.
     *
     * @param array<string,mixed> $rma
     * @return array{ok:bool,error:string}
     */
    public static function sendSms(array $rma, string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['ok' => false, 'error' => 'Порожній текст'];
        }
        if (empty($rma['phone'])) {
            return ['ok' => false, 'error' => 'У заявці немає телефону'];
        }

        $r = TurboSms::send((string)$rma['phone'], $text);

        Db::insert('notifications', [
            'rma_id'     => (int)$rma['id'],
            'event'      => 'manual_sms',
            'channel'    => 'sms',
            'recipient'  => (string)$rma['phone'],
            'status'     => $r['ok'] ? 'sent' : 'failed',
            'detail'     => $r['ok'] ? mb_substr($text, 0, 255) : mb_substr($r['error'], 0, 255),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return ['ok' => $r['ok'], 'error' => $r['error']];
    }

    /**
     * Виконує відправку один раз на (заявка, подія, канал) і логує результат.
     *
     * @param callable():array{0:bool,1:string} $sender
     */
    private static function once(int $rmaId, string $event, string $channel, string $recipient, callable $sender): void
    {
        // уже успішно слали — не повторюємо
        $already = (int)Db::value(
            'SELECT COUNT(*) FROM notifications WHERE rma_id = ? AND event = ? AND channel = ? AND status = "sent"',
            [$rmaId, $event, $channel]
        );
        if ($already > 0) {
            return;
        }

        try {
            list($ok, $error) = $sender();
        } catch (\Throwable $e) {
            $ok = false;
            $error = $e->getMessage();
        }

        Db::insert('notifications', [
            'rma_id'     => $rmaId,
            'event'      => $event,
            'channel'    => $channel,
            'recipient'  => mb_substr($recipient, 0, 190),
            'status'     => $ok ? 'sent' : 'failed',
            'detail'     => $error !== '' ? mb_substr($error, 0, 255) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Шаблони повідомлень (п.15.1 ТЗ).
     *
     * @param array<string,mixed> $rma
     * @return array{subject:string,html:string,sms:string}
     */
    private static function template(array $rma, string $event): array
    {
        $number = (string)$rma['rma_number'];
        $link   = Rma::publicUrl($rma);
        $msg    = trim((string)($rma['client_message'] ?? ''));

        switch ($event) {
            case 'created':
                return self::pack(
                    'Заявку на повернення прийнято — ' . $number,
                    'Вашу заявку на повернення прийнято.',
                    'Номер заявки: <b>' . e($number) . '</b>.<br>'
                    . 'Очікуйте перевірки менеджером. '
                    . '<b>Не відправляйте товар без погодження заявки.</b>',
                    $link,
                    'Заявку на повернення ' . $number . ' прийнято. '
                    . 'Не відправляйте товар без погодження менеджера. Статус: ' . $link
                );

            case 'need_more_info':
                return self::pack(
                    'Потрібні додаткові дані — ' . $number,
                    'Для перевірки заявки потрібні додаткові дані.',
                    ($msg !== '' ? nl2br(e($msg)) . '<br><br>' : '')
                    . 'Будь ласка, додайте фото товару або упаковки чи звʼяжіться з менеджером.',
                    $link,
                    'Заявка ' . $number . ': потрібні додаткові дані/фото. Деталі: ' . $link
                );

            case 'approved':
                return self::pack(
                    'Повернення погоджено — ' . $number,
                    'Ваше повернення погоджено.',
                    ($msg !== '' ? nl2br(e($msg)) . '<br><br>' : '')
                    . 'Інструкцію для відправки товару дивіться за посиланням нижче. '
                    . 'Якщо ми оформили накладну Нової пошти — там буде її номер.',
                    $link,
                    'Повернення ' . $number . ' погоджено. Інструкція для відправки: ' . $link
                );

            case 'received':
                return self::pack(
                    'Товар отримано — ' . $number,
                    'Ми отримали товар за заявкою ' . $number . '.',
                    'Зараз він проходить перевірку. Ми повідомимо про результат.',
                    $link,
                    'Ми отримали товар за заявкою ' . $number . '. Триває перевірка.'
                );

            case 'refunded':
                return self::pack(
                    'Кошти повернено — ' . $number,
                    'Кошти за заявкою ' . $number . ' повернено.',
                    'Термін зарахування залежить від вашого банку.',
                    $link,
                    'Кошти за заявкою ' . $number . ' повернено. Термін зарахування залежить від банку.'
                );

            case 'rejected':
                return self::pack(
                    'Рішення за заявкою — ' . $number,
                    'На жаль, у поверненні за заявкою ' . $number . ' відмовлено.',
                    ($msg !== '' ? nl2br(e($msg)) : 'Деталі уточніть у менеджера.'),
                    $link,
                    'Заявка ' . $number . ': у поверненні відмовлено. Деталі: ' . $link
                );
        }

        return self::pack('Оновлення заявки ' . $number, 'Статус заявки оновлено.', '', $link, 'Оновлення заявки ' . $number . ': ' . $link);
    }

    /**
     * Складає subject + HTML-лист із простим фірмовим оформленням.
     *
     * @return array{subject:string,html:string,sms:string}
     */
    private static function pack(string $subject, string $heading, string $body, string $link, string $sms): array
    {
        $appName = e(Env::str('APP_NAME', 'Обмін та повернення'));
        $html = '<!DOCTYPE html><html lang="uk"><body style="margin:0;background:#f5f6f8;font-family:Arial,Helvetica,sans-serif;color:#1c1f26">'
            . '<div style="max-width:520px;margin:0 auto;padding:24px 16px">'
            . '<div style="background:#fff;border:1px solid #e3e6ec;border-radius:10px;overflow:hidden">'
            . '<div style="background:#1f6feb;color:#fff;padding:16px 20px;font-weight:bold;font-size:16px">' . $appName . '</div>'
            . '<div style="padding:20px">'
            . '<h1 style="font-size:19px;margin:0 0 12px">' . e($heading) . '</h1>'
            . ($body !== '' ? '<p style="font-size:15px;line-height:1.55;margin:0 0 18px;color:#414753">' . $body . '</p>' : '')
            . '<a href="' . e($link) . '" style="display:inline-block;background:#1f6feb;color:#fff;text-decoration:none;padding:11px 20px;border-radius:8px;font-weight:bold;font-size:15px">Переглянути статус заявки</a>'
            . '</div></div>'
            . '<p style="font-size:12px;color:#6b7280;margin:14px 4px 0">Цей лист надіслано автоматично сервісом обміну та повернення. Відповідати на нього не потрібно.</p>'
            . '</div></body></html>';

        return ['subject' => $subject, 'html' => $html, 'sms' => $sms];
    }
}
