<?php
declare(strict_types=1);

namespace App;

/**
 * Довідники системи: статуси, причини, дії, відмови, постачальники.
 */
class Dict
{
    /** @return array<string,string> */
    public static function statuses(): array
    {
        return [
            'new'                       => 'Нова заявка',
            'manager_review'            => 'Очікує перевірки менеджером',
            'need_more_info'            => 'Потрібні додаткові дані',
            'approved'                  => 'Повернення погоджено',
            'rejected'                  => 'Відмовлено',
            'waiting_customer_shipment' => 'Очікуємо відправку від клієнта',
            'in_transit'                => 'Товар у дорозі',
            'received'                  => 'Товар отримано',
            'inspection'                => 'На перевірці',
            'refund_approved'           => 'Повернення коштів погоджено',
            'waiting_payment_details'   => 'Очікуємо реквізити',
            'refund_pending'            => 'Очікує виплату',
            'refunded'                  => 'Кошти повернено',
            'exchange_pending'          => 'Очікує обмін',
            'exchange_sent'             => 'Обмін відправлено',
            'closed'                    => 'Закрито',
            'cancelled'                 => 'Скасовано',
        ];
    }

    public static function status(string $code): string
    {
        $all = self::statuses();
        return $all[$code] ?? $code;
    }

    /**
     * Колір бейджа статусу.
     */
    public static function statusColor(string $code): string
    {
        $map = [
            'new'                       => 'blue',
            'manager_review'            => 'blue',
            'need_more_info'            => 'amber',
            'approved'                  => 'green',
            'rejected'                  => 'red',
            'waiting_customer_shipment' => 'amber',
            'in_transit'                => 'violet',
            'received'                  => 'violet',
            'inspection'                => 'violet',
            'refund_approved'           => 'green',
            'waiting_payment_details'   => 'amber',
            'refund_pending'            => 'amber',
            'refunded'                  => 'green',
            'exchange_pending'          => 'amber',
            'exchange_sent'             => 'green',
            'closed'                    => 'gray',
            'cancelled'                 => 'gray',
        ];
        return $map[$code] ?? 'gray';
    }

    /**
     * Статуси, які вважаються "заявка в роботі" (не фінальні).
     * @return array<int,string>
     */
    public static function openStatuses(): array
    {
        $all = array_keys(self::statuses());
        return array_values(array_diff($all, ['closed', 'cancelled', 'rejected', 'refunded']));
    }

    /**
     * Причини, доступні клієнту у формі.
     *
     * @return array<string,string>
     */
    public static function reasons(): array
    {
        return [
            'model_mismatch'  => 'Не підійшов за моделлю техніки',
            'size_mismatch'   => 'Не підійшов за розміром',
            'wrong_order'     => 'Замовив не той товар',
            'changed_mind'    => 'Передумав / товар більше не потрібен',
            'wrong_item_sent' => 'Прийшов не той товар',
            'shipping_damage' => 'Товар пошкоджений при доставці',
            'defective'       => 'Виявлено брак',
            'other'           => 'Інша причина',
        ];
    }

    /**
     * Прибрані з вибору, але можуть лишатися у створених раніше заявках.
     * Потрібні лише для того, щоб в адмінці не світився голий код.
     *
     * @return array<string,string>
     */
    public static function legacyReasons(): array
    {
        return [
            'manager_fault'   => 'Менеджер підібрав неправильно (причину прибрано)',
            'site_info_wrong' => 'Неправильна інформація на сайті (причину прибрано)',
        ];
    }

    public static function reason(string $code): string
    {
        $all = self::reasons() + self::legacyReasons();
        return $all[$code] ?? $code;
    }

    /**
     * Причини, для яких обов'язковий детальний опис.
     * @return array<int,string>
     */
    public static function reasonsRequiringDetails(): array
    {
        return ['wrong_item_sent', 'shipping_damage', 'defective', 'other'];
    }

    /**
     * Причини, для яких обов'язкове фото дефекту.
     * @return array<int,string>
     */
    public static function reasonsRequiringDefectPhoto(): array
    {
        return ['defective', 'shipping_damage', 'wrong_item_sent'];
    }

    /** @return array<string,string> */
    public static function actions(): array
    {
        return [
            'exchange'     => 'Обміняти на інший товар',
            'refund'       => 'Повернути кошти',
            'consultation' => 'Отримати консультацію менеджера',
            'undecided'    => 'Ще не знаю, хочу узгодити з менеджером',
        ];
    }

    public static function action(string $code): string
    {
        $all = self::actions();
        return $all[$code] ?? $code;
    }

    /** @return array<string,string> */
    public static function shippingPayers(): array
    {
        return [
            'customer' => 'Клієнт',
            'shop'     => 'Магазин',
            'agreed'   => 'За домовленістю',
            'decision' => 'Потребує рішення',
        ];
    }

    /**
     * Хто за замовчуванням платить за доставку — залежно від причини (п.17 ТЗ).
     */
    public static function defaultShippingPayer(string $reason): string
    {
        $map = [
            'model_mismatch'  => 'customer',
            'size_mismatch'   => 'customer',
            'wrong_order'     => 'customer',
            'changed_mind'    => 'customer',
            'wrong_item_sent' => 'shop',
            'defective'       => 'decision',
            'shipping_damage' => 'decision',
            'other'           => 'decision',
        ];
        return $map[$reason] ?? 'decision';
    }

    /**
     * Підстави для відмови. Відповідають розділу 2 умов на сайті
     * benzo-pila.in.ua/ua/obmen-i-vozvrat.
     *
     * @return array<string,string>
     */
    public static function rejectReasons(): array
    {
        return [
            'expired'          => 'Минуло більше ' . Env::int('RETURN_DAYS', 14) . ' днів',
            'installed'        => 'Товар має сліди встановлення або монтажу (п.2.1)',
            'used'             => 'Товар був у використанні, подряпаний або забруднений (п.2.2)',
            'packaging'        => 'Пошкоджена, порвана, зім’ята або забруднена фабрична упаковка (п.2.3)',
            'incomplete'       => 'Відсутні елементи комплектації, пакети, наклейки, етикетки або пломби (п.2.4)',
            'return_shipping_damage' => 'Пошкоджено під час зворотної доставки через неналежне пакування (п.2.5)',
            'electro_installed'      => 'Електротовар після встановлення чи підключення (п.2.6, 2.8)',
            'not_returnable_by_law'  => 'Товар не підлягає поверненню згідно з чинним законодавством (п.2.7)',
            'customer_damage'  => 'Товар пошкоджено з вини покупця',
            'not_ours'         => 'Товар не придбаний у нашому магазині',
            'unidentifiable'   => 'Неможливо ідентифікувати товар',
            'other'            => 'Інша причина',
        ];
    }

    /** @return array<string,string> */
    public static function carriers(): array
    {
        return [
            'novaposhta' => 'Нова пошта',
            'ukrposhta'  => 'Укрпошта',
            'justin'     => 'Justin',
            'meest'      => 'Meest',
            'other'      => 'Інший',
        ];
    }

    /** @return array<string,string> */
    public static function photoTypes(): array
    {
        return [
            'general'   => 'Загальне фото товару',
            'packaging' => 'Фото упаковки',
            'marking'   => 'Фото артикула / маркування',
            'defect'    => 'Фото дефекту',
            'other'     => 'Інше',
        ];
    }

    /** @return array<string,string> */
    public static function commentTypes(): array
    {
        return [
            'internal' => 'Внутрішній коментар',
            'client'   => 'Коментар для клієнта',
            'system'   => 'Системний коментар',
        ];
    }
}
