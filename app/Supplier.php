<?php
declare(strict_types=1);

namespace App;

/**
 * Визначення постачальника за артикулом (п.19.1 ТЗ).
 *
 * ТАТА      — артикул закінчується на T
 * Мотоцентр — літера, дефіс, далі цифри (напр. A-1234)
 * Pride     — починається з P
 * СКС       — тільки цифри
 */
class Supplier
{
    const UNKNOWN = 'unknown';

    /** @return array<string,string> */
    public static function all(): array
    {
        return [
            'tata'       => 'ТАТА',
            'mototsentr' => 'Мотоцентр',
            'pride'      => 'Pride',
            'sks'        => 'СКС',
            self::UNKNOWN => 'Не визначено',
        ];
    }

    public static function name(?string $code): string
    {
        $all = self::all();
        return $all[(string)$code] ?? 'Не визначено';
    }

    /**
     * Порядок перевірки важливий: більш специфічні правила спершу.
     */
    public static function detect(?string $sku): string
    {
        $s = strtoupper(trim((string)$sku));
        if ($s === '') {
            return self::UNKNOWN;
        }

        // Мотоцентр: літера(и) + дефіс + цифри
        if (preg_match('/^[A-ZА-ЯІЇЄҐ]+\-\d+/u', $s)) {
            return 'mototsentr';
        }

        // ТАТА: закінчується на T
        if (substr($s, -1) === 'T') {
            return 'tata';
        }

        // Pride: починається з P
        if ($s[0] === 'P') {
            return 'pride';
        }

        // СКС: тільки цифри
        if (preg_match('/^\d+$/', $s)) {
            return 'sks';
        }

        return self::UNKNOWN;
    }
}
