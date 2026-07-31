<?php
declare(strict_types=1);

namespace App;

class Session
{
    /** Тривалість сесії — 24 години. */
    const LIFETIME = 86400;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        // Власний каталог сесій: на спільному хостингу GC інших сайтів не
        // видалятиме наші файли раніше часу. Каталог закритий у .htaccess.
        if (defined('BASE_PATH')) {
            $dir = BASE_PATH . '/storage/sessions';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (is_dir($dir) && is_writable($dir)) {
                session_save_path($dir);
                ini_set('session.gc_probability', '1');
                ini_set('session.gc_divisor', '100');
            }
        }
        // Скільки сервер тримає дані сесії без активності.
        ini_set('session.gc_maxlifetime', (string)self::LIFETIME);

        session_set_cookie_params([
            'lifetime' => self::LIFETIME,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('rma_sess');
        session_start();

        // Ковзне продовження: кожна активність відсуває термін ще на 24 години.
        // Оновлюємо мітку часу (щоб серверний GC рахував від останньої дії —
        // файл сесії перезаписується) і наново шлемо куку з новим терміном.
        $_SESSION['_last'] = time();
        if (session_id() !== '') {
            setcookie(session_name(), session_id(), [
                'expires'  => time() + self::LIFETIME,
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
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
