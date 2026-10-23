<?php
declare(strict_types=1);

namespace App;

/**
 * «Пульс» cron-завдань: кожен скрипт після успішного запуску записує час,
 * а адмінка/Telegram попереджають, якщо трекінг НП давно не запускався.
 *
 * Без робочого cron тихо перестають працювати статуси посилок, маячки оплати,
 * виявлення «Легкого повернення», «До отримання» та нагадування.
 */
class CronBeat
{
    /** Скільки хвилин тиші трекінгу вважати проблемою. */
    const TRACK_MAX_MINUTES = 120;

    /** Як часто (год) повторювати попередження в Telegram. */
    const ALERT_EVERY_HOURS = 6;

    /**
     * Опис завдань: [назва, допустима пауза в хвилинах].
     *
     * @return array<string,array{label:string,max:int,every:string}>
     */
    public static function jobs(): array
    {
        return [
            'nptrack' => ['label' => 'Трекінг НП + Легке повернення', 'max' => 120,     'every' => 'кожні 30 хв'],
            'stale'   => ['label' => 'Нагадування про «завислі» нові заявки', 'max' => 180, 'every' => 'щогодини'],
            'pickup'  => ['label' => 'Список «До отримання» в Telegram', 'max' => 26 * 60, 'every' => 'щодня'],
            'remind'  => ['label' => 'Нагадування клієнтам про ТТН', 'max' => 26 * 60,   'every' => 'щодня'],
        ];
    }

    /** Позначити успішний запуск завдання. Ніколи не кидає винятків. */
    public static function mark(string $job): void
    {
        self::put('cron_last_' . $job, date('Y-m-d H:i:s'));
    }

    /** Час останнього запуску або null. */
    public static function last(string $job): ?string
    {
        return self::get('cron_last_' . $job);
    }

    /** Скільки хвилин минуло від дати (null — не було). */
    public static function minutesSince(?string $dt): ?int
    {
        if ($dt === null || $dt === '') {
            return null;
        }
        $ts = strtotime($dt);
        return $ts === false ? null : (int)floor((time() - $ts) / 60);
    }

    /** «2 год 15 хв», «3 дн» тощо. */
    public static function human(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes . ' хв';
        }
        if ($minutes < 48 * 60) {
            $h = intdiv($minutes, 60);
            $m = $minutes % 60;
            return $h . ' год' . ($m > 0 ? ' ' . $m . ' хв' : '');
        }
        return intdiv($minutes, 1440) . ' дн';
    }

    /**
     * Опис проблеми з трекінгом ('' — усе гаразд).
     * Перевіряємо лише коли Нова пошта увімкнена (інакше трекінг не потрібен).
     */
    public static function trackingProblem(): string
    {
        try {
            if (!NovaPoshta::enabled()) {
                return '';
            }
        } catch (\Throwable $e) {
            return '';
        }

        $last = self::last('nptrack');
        if ($last === null) {
            // Ще жодного запуску. Не лякаємо одразу після деплою —
            // фіксуємо, відколи стежимо, і тривожимо лише через TRACK_MAX_MINUTES.
            $since = self::get('cron_watch_since');
            if ($since === null) {
                self::put('cron_watch_since', date('Y-m-d H:i:s'));
                return '';
            }
            $m = self::minutesSince($since);
            if ($m !== null && $m > self::TRACK_MAX_MINUTES) {
                return 'Трекінг Нової пошти (cron nptrack.php) ще жодного разу не запускався.';
            }
            return '';
        }

        $m = self::minutesSince($last);
        if ($m !== null && $m > self::TRACK_MAX_MINUTES) {
            return 'Трекінг Нової пошти (cron nptrack.php) не запускався вже ' . self::human($m)
                . ' (останній запуск: ' . date('d.m.Y H:i', (int)strtotime($last)) . ').';
        }
        return '';
    }

    /**
     * Якщо трекінг мовчить — повідомити в Telegram (не частіше ніж раз на ALERT_EVERY_HOURS).
     * Викликається з адмінки та з інших cron-скриптів. Ніколи не кидає винятків.
     */
    public static function alertIfNeeded(): void
    {
        try {
            $problem = self::trackingProblem();
            if ($problem === '' || !Telegram::enabled()) {
                return;
            }
            $lastAlert = self::get('cron_alert_at');
            if ($lastAlert !== null && (time() - (int)strtotime($lastAlert)) < self::ALERT_EVERY_HOURS * 3600) {
                return;
            }
            $text = "⚠️ <b>Cron сервісу повернень не працює</b>\n\n"
                  . e($problem) . "\n\n"
                  . 'Без нього не оновлюються статуси посилок, маячки оплати, «Легке повернення» '
                  . 'й список «До отримання». Перевірте cPanel → Cron Jobs.';
            if (Telegram::send($text)) {
                self::put('cron_alert_at', date('Y-m-d H:i:s'));
            }
        } catch (\Throwable $e) {
            error_log('CronBeat: ' . $e->getMessage());
        }
    }

    private static function get(string $key): ?string
    {
        try {
            $v = Db::value('SELECT v FROM settings WHERE k = ?', [$key]);
            return ($v === null || $v === '') ? null : (string)$v;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function put(string $key, string $value): void
    {
        try {
            Db::run(
                'INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)',
                [$key, $value]
            );
        } catch (\Throwable $e) {
            error_log('CronBeat: ' . $e->getMessage());
        }
    }
}
