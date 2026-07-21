<?php
declare(strict_types=1);

namespace App;

/**
 * Легкі ідемпотентні міграції.
 *
 * Викликається один раз при вході в адмінку. Крок виконується, тільки якщо
 * потрібної таблиці/колонки ще немає — тож повторний запуск безпечний,
 * а версія фіксується в settings, щоб не перевіряти щоразу.
 */
class Schema
{
    const TARGET_VERSION = 4;

    public static function ensure(): void
    {
        try {
            $current = (int)Db::value('SELECT v FROM settings WHERE k = ?', ['schema_version']);
        } catch (\Throwable $e) {
            return; // база ще не встановлена — цим займеться install.php
        }

        if ($current >= self::TARGET_VERSION) {
            return;
        }

        if ($current < 2) {
            self::migrateV2();
        }
        if ($current < 3) {
            self::migrateV3();
        }
        if ($current < 4) {
            self::migrateV4();
        }

        Db::run(
            'INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)',
            ['schema_version', (string)self::TARGET_VERSION]
        );
    }

    /**
     * v2: сповіщення клієнту (email/SMS) + токен прямого доступу до статусу.
     */
    private static function migrateV2(): void
    {
        $db = Env::str('DB_NAME');

        if (!self::columnExists($db, 'rma', 'public_token')) {
            Db::run('ALTER TABLE `rma` ADD COLUMN `public_token` CHAR(32) NULL AFTER `sd_synced`');
        }

        Db::run(
            'CREATE TABLE IF NOT EXISTS `notifications` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `rma_id` INT UNSIGNED NOT NULL,
                `event` VARCHAR(40) NOT NULL,
                `channel` ENUM("email","sms") NOT NULL,
                `recipient` VARCHAR(190) NOT NULL,
                `status` ENUM("sent","failed","skipped") NOT NULL,
                `detail` VARCHAR(255) NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_notif_rma` (`rma_id`),
                KEY `idx_notif_dedupe` (`rma_id`, `event`, `channel`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // видаємо токени заявкам, які створені до появи цієї колонки
        $rows = Db::all('SELECT id FROM rma WHERE public_token IS NULL OR public_token = ""');
        foreach ($rows as $r) {
            Db::update('rma', ['public_token' => bin2hex(random_bytes(16))], 'id = ?', [(int)$r['id']]);
        }
    }

    /**
     * v3: зворотна накладна Нової пошти (ref документа для друку/видалення).
     */
    private static function migrateV3(): void
    {
        $db = Env::str('DB_NAME');
        if (!self::columnExists($db, 'rma', 'np_doc_ref')) {
            Db::run('ALTER TABLE `rma` ADD COLUMN `np_doc_ref` VARCHAR(64) NULL AFTER `return_ttn`');
        }
    }

    /**
     * v4: трекінг Нової пошти (останній статус посилки).
     */
    private static function migrateV4(): void
    {
        $db = Env::str('DB_NAME');
        if (!self::columnExists($db, 'rma', 'np_track_status')) {
            Db::run('ALTER TABLE `rma` ADD COLUMN `np_track_status` VARCHAR(160) NULL AFTER `np_doc_ref`');
        }
        if (!self::columnExists($db, 'rma', 'np_tracked_at')) {
            Db::run('ALTER TABLE `rma` ADD COLUMN `np_tracked_at` DATETIME NULL AFTER `np_track_status`');
        }
    }

    private static function columnExists(string $db, string $table, string $column): bool
    {
        return (int)Db::value(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = ? AND table_name = ? AND column_name = ?',
            [$db, $table, $column]
        ) > 0;
    }
}
