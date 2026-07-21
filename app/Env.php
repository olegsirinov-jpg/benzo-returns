<?php
declare(strict_types=1);

namespace App;

/**
 * Мінімальний .env-парсер (без залежностей).
 */
class Env
{
    /** @var array<string,string> */
    private static $vars = [];
    /** @var bool */
    private static $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        if (!is_readable($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            list($key, $value) = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);
            $len   = strlen($value);
            if ($len >= 2) {
                $first = $value[0];
                $last  = $value[$len - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }
            self::$vars[$key] = $value;
        }
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        if (array_key_exists($key, self::$vars)) {
            return self::$vars[$key];
        }
        $env = getenv($key);
        return $env === false ? $default : $env;
    }

    public static function str(string $key, string $default = ''): string
    {
        $v = self::get($key, $default);
        return is_string($v) ? $v : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key, null);
        return ($v === null || $v === '') ? $default : (int)$v;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key, null);
        if ($v === null || $v === '') {
            return $default;
        }
        return in_array(strtolower((string)$v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function isConfigured(): bool
    {
        return self::$vars !== [];
    }
}
