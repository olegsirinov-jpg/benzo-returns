<?php
declare(strict_types=1);

namespace App;

/**
 * Робота із заявками на повернення.
 */
class Rma
{
    /**
     * Генерує наступний номер: RMA-000123.
     *
     * Лічильник наскрізний, а не по роках: без року в номері річний лічильник
     * почав би видавати ті самі номери наступного року.
     * Використовує блокування рядка settings, щоб два одночасні запити
     * не отримали однаковий номер.
     */
    public static function nextNumber(): string
    {
        $key = 'rma_counter';

        $pdo   = Db::pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }

        try {
            // При першому запуску лічильник стартує з найбільшого вже наявного
            // номера — щоб не зіткнутися зі старими заявками (у т.ч. у форматі
            // RMA-2026-000007, де останній сегмент теж числовий).
            Db::run(
                "INSERT IGNORE INTO settings (k, v)
                 SELECT ?, COALESCE(MAX(CAST(SUBSTRING_INDEX(rma_number, '-', -1) AS UNSIGNED)), 0) FROM rma",
                [$key]
            );

            $current = (int)Db::value('SELECT v FROM settings WHERE k = ? FOR UPDATE', [$key]);
            $next    = $current + 1;
            Db::run('UPDATE settings SET v = ? WHERE k = ?', [(string)$next, $key]);

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return sprintf('RMA-%06d', $next);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM rma WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public static function findByNumber(string $number): ?array
    {
        return Db::one('SELECT * FROM rma WHERE rma_number = ?', [trim($number)]);
    }

    /**
     * Пошук заявки клієнтом: номер заявки + телефон.
     * @return array<string,mixed>|null
     */
    public static function findForCustomer(string $number, string $phone): ?array
    {
        $normalized = Validate::phone($phone);
        if ($normalized === null) {
            return null;
        }
        return Db::one(
            'SELECT * FROM rma WHERE rma_number = ? AND phone = ?',
            [trim($number), $normalized]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function items(int $rmaId): array
    {
        return Db::all('SELECT * FROM rma_items WHERE rma_id = ? ORDER BY id', [$rmaId]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function photos(int $rmaId): array
    {
        return Db::all('SELECT * FROM rma_photos WHERE rma_id = ? ORDER BY id', [$rmaId]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function history(int $rmaId): array
    {
        return Db::all('SELECT * FROM rma_history WHERE rma_id = ? ORDER BY id DESC', [$rmaId]);
    }

    /**
     * @param string $type internal|client|system
     * @return array<int,array<string,mixed>>
     */
    public static function comments(int $rmaId, ?string $type = null): array
    {
        if ($type === null) {
            return Db::all('SELECT * FROM rma_comments WHERE rma_id = ? ORDER BY id DESC', [$rmaId]);
        }
        return Db::all('SELECT * FROM rma_comments WHERE rma_id = ? AND type = ? ORDER BY id DESC', [$rmaId, $type]);
    }

    /**
     * Короткий опис товарів заявки одним рядком.
     */
    public static function itemsSummary(int $rmaId, int $limit = 2): string
    {
        $items = self::items($rmaId);
        if ($items === []) {
            return '—';
        }
        $names = [];
        foreach (array_slice($items, 0, $limit) as $it) {
            $names[] = (string)$it['name'];
        }
        $s = implode(', ', $names);
        $rest = count($items) - $limit;
        if ($rest > 0) {
            $s .= ' + ще ' . $rest;
        }
        return $s;
    }

    /**
     * Запис у журнал змін.
     */
    public static function log(
        int $rmaId,
        string $field,
        ?string $old,
        ?string $new,
        ?string $comment = null,
        ?int $userId = null,
        ?string $userName = null
    ): void {
        Db::insert('rma_history', [
            'rma_id'     => $rmaId,
            'user_id'    => $userId ?? Auth::id(),
            'user_name'  => $userName ?? Auth::name(),
            'field'      => $field,
            'old_value'  => $old,
            'new_value'  => $new,
            'comment'    => $comment,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Додати коментар.
     */
    public static function comment(int $rmaId, string $text, string $type = 'internal', ?string $author = null): int
    {
        return Db::insert('rma_comments', [
            'rma_id'     => $rmaId,
            'user_id'    => Auth::id(),
            'author'     => $author ?? Auth::name(),
            'type'       => $type,
            'text'       => $text,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Зміна статусу з журналюванням, синхронізацією в SalesDrive і повідомленням.
     */
    public static function setStatus(int $rmaId, string $newStatus, ?string $comment = null): bool
    {
        $rma = self::find($rmaId);
        if ($rma === null || !isset(Dict::statuses()[$newStatus])) {
            return false;
        }
        $old = (string)$rma['status'];
        if ($old === $newStatus) {
            return true;
        }

        Db::update('rma', [
            'status'     => $newStatus,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$rmaId]);

        self::log($rmaId, 'status', Dict::status($old), Dict::status($newStatus), $comment);

        if ($comment !== null && $comment !== '') {
            self::comment($rmaId, $comment, 'client');
            Db::update('rma', ['client_message' => $comment], 'id = ?', [$rmaId]);
        }

        // Коментар у SalesDrive
        if ($rma['order_id_sd'] !== null && $rma['order_id_sd'] !== '') {
            SalesDrive::appendStatusComment($rma, $newStatus, $comment);
        }

        // Сповіщення клієнту (email завжди, SMS на критичні події).
        // Зовнішні сервіси не мають ламати зміну статусу.
        try {
            $fresh = self::find($rmaId);
            if ($fresh !== null) {
                Notify::statusChanged($fresh, $newStatus);
            }
        } catch (\Throwable $e) {
            error_log('Notify: ' . $e->getMessage());
        }

        return true;
    }

    /**
     * Чи минув термін повернення (14 днів від дати замовлення).
     * @param array<string,mixed> $rma
     */
    public static function daysSinceOrder(array $rma): ?int
    {
        if (empty($rma['order_date'])) {
            return null;
        }
        $ts = strtotime((string)$rma['order_date']);
        if ($ts === false) {
            return null;
        }
        return (int)floor((time() - $ts) / 86400);
    }

    /**
     * @param array<string,mixed> $rma
     */
    public static function isExpired(array $rma): bool
    {
        $days = self::daysSinceOrder($rma);
        return $days !== null && $days > Env::int('RETURN_DAYS', 14);
    }

    public static function adminUrl(int $id): string
    {
        return url('/admin/rma/' . $id);
    }

    /**
     * Оновити трекінг Нової пошти для заявки й, за потреби, посунути статус
     * (тільки вперед: approved -> waiting -> in_transit -> received).
     *
     * @return array{ok:bool,status:string,error:string}
     */
    public static function refreshNpTracking(int $rmaId): array
    {
        $rma = self::find($rmaId);
        if ($rma === null || empty($rma['return_ttn']) || ($rma['carrier'] ?? '') !== 'novaposhta') {
            return ['ok' => false, 'status' => '', 'error' => 'У заявці немає ТТН Нової пошти'];
        }

        $res  = NovaPoshta::track([[
            'ttn'   => (string)$rma['return_ttn'],
            'phone' => Config::str('np_recip_phone'),
        ]]);
        $info = $res[(string)$rma['return_ttn']] ?? null;
        if ($info === null) {
            return ['ok' => false, 'status' => '', 'error' => 'Нова пошта не повернула статус'];
        }

        Db::update('rma', [
            'np_track_status' => $info['status'],
            'np_tracked_at'   => date('Y-m-d H:i:s'),
        ], 'id = ?', [$rmaId]);

        $target = NovaPoshta::trackingTargetStatus($info['code']);
        if ($target !== null) {
            self::advanceStatus($rmaId, (string)$rma['status'], $target);
        }

        return ['ok' => true, 'status' => $info['status'], 'error' => ''];
    }

    /**
     * Перевірити, чи клієнт оформив «Легке повернення» у застосунку НП.
     * Якщо так — підтягуємо номер накладної як ТТН повернення.
     *
     * @return array{ok:bool,detected:bool,ttn:string}
     */
    public static function checkLightReturn(int $rmaId): array
    {
        $rma = self::find($rmaId);
        if ($rma === null || empty($rma['np_original_ttn'])) {
            return ['ok' => false, 'detected' => false, 'ttn' => ''];
        }
        // якщо ТТН повернення вже є (наша накладна чи ручна) — не чіпаємо
        if (!empty($rma['return_ttn'])) {
            return ['ok' => true, 'detected' => false, 'ttn' => ''];
        }

        $info = NovaPoshta::lightReturnInfo((string)$rma['np_original_ttn']);
        if (!$info['found'] || $info['ttn'] === '') {
            return ['ok' => true, 'detected' => false, 'ttn' => ''];
        }

        // клієнт оформив легке повернення сам
        Db::update('rma', [
            'return_ttn'          => $info['ttn'],
            'ttn_source'          => 'light_return',
            'carrier'             => 'novaposhta',
            'light_return_reason' => $info['reason'] ?: null,
            'updated_at'          => date('Y-m-d H:i:s'),
        ], 'id = ?', [$rmaId]);

        self::log($rmaId, 'ТТН повернення (Легке повернення НП)', null, $info['ttn'],
            'Клієнт оформив Легке повернення' . ($info['reason'] !== '' ? '. Причина НП: ' . $info['reason'] : ''),
            null, 'Система');
        self::comment($rmaId,
            'Клієнт оформив «Легке повернення» Нової пошти. ТТН: ' . $info['ttn']
            . ($info['reason'] !== '' ? '. Причина, вказана в НП: ' . $info['reason'] : ''),
            'system', 'Система');

        // рухаємо статус у «в дорозі», якщо ще на етапі очікування
        $st = (string)$rma['status'];
        if (in_array($st, ['approved', 'waiting_customer_shipment', 'manager_review', 'new'], true)) {
            self::setStatus($rmaId, 'in_transit');
        }

        try {
            $fresh = self::find($rmaId);
            if ($fresh !== null) {
                Telegram::ttnAdded($fresh, $info['ttn']);
            }
        } catch (\Throwable $e) {
            error_log('Telegram: ' . $e->getMessage());
        }

        return ['ok' => true, 'detected' => true, 'ttn' => $info['ttn']];
    }

    /**
     * Просунути статус лише вперед у ланцюгу доставки.
     */
    private static function advanceStatus(int $rmaId, string $current, string $target): void
    {
        $order = [
            'approved'                  => 1,
            'waiting_customer_shipment' => 2,
            'in_transit'                => 3,
            'received'                  => 4,
        ];
        $cur = $order[$current] ?? 0;
        $tgt = $order[$target] ?? 0;
        // рухаємось тільки вперед і тільки в межах етапів доставки
        if ($cur > 0 && $tgt > $cur) {
            self::setStatus($rmaId, $target);
        }
    }

    /**
     * Токен для прямого доступу клієнта до статусу без вводу телефону.
     * Створюється лінитливо, якщо заявка ще без токена.
     *
     * @param array<string,mixed> $rma
     */
    public static function publicToken(array $rma): string
    {
        $token = (string)($rma['public_token'] ?? '');
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            Db::update('rma', ['public_token' => $token], 'id = ?', [(int)$rma['id']]);
        }
        return $token;
    }

    /**
     * Пряме посилання на статус заявки (для email/SMS).
     *
     * @param array<string,mixed> $rma
     */
    public static function publicUrl(array $rma): string
    {
        return url('/returns/status?rma=' . rawurlencode((string)$rma['rma_number'])
            . '&t=' . self::publicToken($rma));
    }
}
