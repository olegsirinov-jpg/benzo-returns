<?php
declare(strict_types=1);

namespace App;

/**
 * Логіка руху заявки: що робити далі й що зараз відбувається.
 * Замість стіни з усіх статусів менеджеру показуємо 1-2 логічні наступні кроки.
 */
class Workflow
{
    /**
     * Підказка «що зараз» для поточного статусу.
     */
    public static function hint(string $status): string
    {
        $map = [
            'new'                       => 'Перевірте замовлення, товар і фото. Погодьте повернення або відмовте.',
            'manager_review'            => 'Перевірте замовлення, товар і фото. Погодьте повернення або відмовте.',
            'need_more_info'            => 'Очікуємо додаткові дані або фото від клієнта.',
            'approved'                  => 'Погоджено. Очікуємо, поки клієнт відправить товар і внесе ТТН.',
            'waiting_customer_shipment' => 'Очікуємо відправку від клієнта та номер ТТН.',
            'in_transit'                => 'Товар у дорозі. Позначте, коли отримаєте посилку.',
            'received'                  => 'Посилку отримано. Перевірте товар.',
            'inspection'                => 'Товар на перевірці. Ухваліть рішення — виплата, обмін або відмова.',
            'refund_approved'           => 'Виплату погоджено. Далі — очікування та повернення коштів.',
            'waiting_payment_details'   => 'Очікуємо реквізити для повернення коштів.',
            'refund_pending'            => 'Виплатіть кошти клієнту та позначте як повернені.',
            'refunded'                  => 'Кошти повернено. Заявку можна закрити.',
            'exchange_pending'          => 'Підготуйте й відправте товар на обмін.',
            'exchange_sent'             => 'Обмін відправлено. Заявку можна закрити.',
            'rejected'                  => 'У поверненні відмовлено. Заявку можна закрити.',
            'closed'                    => 'Заявку закрито.',
            'cancelled'                 => 'Заявку скасовано.',
        ];
        return $map[$status] ?? '';
    }

    /**
     * Логічні наступні кроки для поточного статусу.
     *
     * @param array<string,mixed> $rma
     * @return array<int,array{status:string,label:string,primary:bool}>
     */
    public static function nextSteps(array $rma): array
    {
        $status = (string)$rma['status'];
        $action = (string)$rma['desired_action'];

        switch ($status) {
            case 'new':
            case 'manager_review':
            case 'need_more_info':
                return [
                    ['status' => 'approved', 'label' => 'Погодити повернення', 'primary' => true],
                ];

            case 'approved':
            case 'waiting_customer_shipment':
            case 'in_transit':
                return [
                    ['status' => 'received', 'label' => 'Товар отримано', 'primary' => true],
                ];

            case 'received':
                return [
                    ['status' => 'inspection', 'label' => 'Почати перевірку', 'primary' => true],
                ];

            case 'inspection':
                if ($action === 'exchange') {
                    return [['status' => 'exchange_pending', 'label' => 'Підтвердити обмін', 'primary' => true]];
                }
                return [['status' => 'refund_pending', 'label' => 'Погодити виплату коштів', 'primary' => true]];

            case 'refund_approved':
                return [['status' => 'refund_pending', 'label' => 'Очікувати виплату', 'primary' => true]];

            case 'refund_pending':
                return [['status' => 'refunded', 'label' => 'Кошти повернено', 'primary' => true]];

            case 'exchange_pending':
                return [['status' => 'exchange_sent', 'label' => 'Обмін відправлено', 'primary' => true]];

            case 'refunded':
            case 'exchange_sent':
            case 'rejected':
                return [['status' => 'closed', 'label' => 'Закрити заявку', 'primary' => true]];
        }

        return [];
    }

    /**
     * Чи заявка вже завершена (немає сенсу в кнопках руху).
     */
    public static function isFinal(string $status): bool
    {
        return in_array($status, ['closed', 'cancelled'], true);
    }

    /**
     * Чи доречно зараз пропонувати відмову / запит даних.
     */
    public static function canReject(string $status): bool
    {
        return !in_array($status, ['closed', 'cancelled', 'rejected', 'refunded'], true);
    }
}
