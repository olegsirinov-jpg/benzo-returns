<?php
declare(strict_types=1);

namespace App;

class Validate
{
    /**
     * Нормалізація телефону до формату 380XXXXXXXXX.
     * Приймає: 0671234567, +380671234567, 380671234567, "067 123 45 67".
     */
    public static function phone(string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', $raw);
        if (!is_string($d) || $d === '') {
            return null;
        }
        // 00380... -> 380...
        if (strpos($d, '00380') === 0) {
            $d = substr($d, 2);
        }
        $len = strlen($d);
        if ($len === 12 && strpos($d, '380') === 0) {
            return $d;
        }
        if ($len === 10 && $d[0] === '0') {
            return '38' . $d;
        }
        if ($len === 9) {
            return '380' . $d;
        }
        return null;
    }

    public static function phoneFormat(string $normalized): string
    {
        if (strlen($normalized) !== 12) {
            return $normalized;
        }
        return sprintf(
            '+%s (%s) %s %s %s',
            substr($normalized, 0, 3),
            substr($normalized, 3, 2),
            substr($normalized, 5, 3),
            substr($normalized, 8, 2),
            substr($normalized, 10, 2)
        );
    }

    /**
     * Нормалізація та перевірка IBAN: UA + 27 символів, разом 29.
     */
    public static function iban(string $raw): ?string
    {
        $v = strtoupper(preg_replace('/\s+/', '', $raw) ?? '');
        if (!preg_match('/^UA\d{27}$/', $v)) {
            return null;
        }
        if (!self::ibanChecksum($v)) {
            return null;
        }
        return $v;
    }

    /**
     * Перевірка контрольної суми IBAN (ISO 13616, mod 97 = 1).
     */
    public static function ibanChecksum(string $iban): bool
    {
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric    = '';
        $len        = strlen($rearranged);
        for ($i = 0; $i < $len; $i++) {
            $ch = $rearranged[$i];
            if ($ch >= '0' && $ch <= '9') {
                $numeric .= $ch;
            } elseif ($ch >= 'A' && $ch <= 'Z') {
                $numeric .= (string)(ord($ch) - 55);
            } else {
                return false;
            }
        }
        // mod 97 частинами (число задовге для int)
        $remainder = 0;
        $chunkLen  = strlen($numeric);
        for ($i = 0; $i < $chunkLen; $i++) {
            $remainder = ($remainder * 10 + (int)$numeric[$i]) % 97;
        }
        return $remainder === 1;
    }

    /**
     * ІПН/РНОКПП (10 цифр) або ЄДРПОУ (8 цифр).
     */
    public static function taxId(string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', $raw);
        if (!is_string($d)) {
            return null;
        }
        $len = strlen($d);
        return ($len === 8 || $len === 10) ? $d : null;
    }

    public static function email(string $raw): ?string
    {
        $v = trim($raw);
        if ($v === '') {
            return null;
        }
        return filter_var($v, FILTER_VALIDATE_EMAIL) === false ? null : mb_strtolower($v);
    }

    /**
     * ТТН: 14 цифр (Нова пошта) або 8-30 символів для інших перевізників.
     */
    public static function ttn(string $raw): ?string
    {
        $v = preg_replace('/\s+/', '', trim($raw)) ?? '';
        if ($v === '') {
            return null;
        }
        if (!preg_match('/^[0-9A-Za-z\-]{8,30}$/', $v)) {
            return null;
        }
        return $v;
    }

    public static function orderNumber(string $raw): ?string
    {
        $v = trim($raw);
        $v = ltrim($v, "#№ \t");
        $v = trim($v);
        if ($v === '' || mb_strlen($v) > 64) {
            return null;
        }
        return $v;
    }

    /** @param array<string,string> $dict */
    public static function inDict(string $value, array $dict): bool
    {
        return isset($dict[$value]);
    }

    public static function text(string $raw, int $max = 5000): string
    {
        $v = trim($raw);
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v) ?? '';
        return mb_substr($v, 0, $max);
    }
}
