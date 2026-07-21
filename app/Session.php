<?php
declare(strict_types=1);

namespace App;

class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('rma_sess');
        session_start();
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    /** @param mixed $value */
    public static function set(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Дістати і одразу видалити.
     * @param mixed $default
     * @return mixed
     */
    public static function pull(string $key, $default = null)
    {
        $v = self::get($key, $default);
        self::forget($key);
        return $v;
    }

    public static function flash(string $type, string $message): void
    {
        $all   = self::get('_flash', []);
        $all[] = ['type' => $type, 'message' => $message];
        self::set('_flash', $all);
    }

    /** @return array<int,array{type:string,message:string}> */
    public static function flashes(): array
    {
        $v = self::pull('_flash', []);
        return is_array($v) ? $v : [];
    }

    /** @param array<string,mixed> $data */
    public static function keepOld(array $data): void
    {
        unset($data['_token'], $data['password']);
        self::set('_old', $data);
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
        }
        session_destroy();
    }
}
