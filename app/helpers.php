<?php
declare(strict_types=1);

if (!function_exists('e')) {
    /**
     * Екранування для HTML.
     * @param mixed $v
     */
    function e($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url')) {
    function url(string $path = '/'): string
    {
        $base = rtrim(App\Env::str('APP_URL', ''), '/');
        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset_version')) {
    /**
     * Версія статичного файлу для обходу кешу браузера.
     * Береться час зміни файлу, тож після кожної правки CSS/JS
     * браузер підтягне свіжу копію сам.
     */
    function asset_version(string $path): string
    {
        $file = BASE_PATH . '/' . ltrim($path, '/');
        $time = is_file($file) ? filemtime($file) : false;
        return (string)($time === false ? 1 : $time);
    }
}

if (!function_exists('old')) {
    /**
     * @param mixed $default
     * @return mixed
     */
    function old(string $key, $default = '')
    {
        $data = App\Session::get('_old', []);
        return is_array($data) && array_key_exists($key, $data) ? $data[$key] : $default;
    }
}

if (!function_exists('dt')) {
    function dt(?string $value, string $format = 'd.m.Y H:i'): string
    {
        if ($value === null || $value === '' || strpos($value, '0000') === 0) {
            return '—';
        }
        $ts = strtotime($value);
        return $ts === false ? '—' : date($format, $ts);
    }
}

if (!function_exists('money')) {
    /** @param mixed $v */
    function money($v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        return number_format((float)$v, 2, ',', ' ') . ' грн';
    }
}

if (!function_exists('str_limit')) {
    function str_limit(?string $s, int $n = 60): string
    {
        $s = (string)$s;
        return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
    }
}
