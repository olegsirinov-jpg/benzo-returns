<?php
declare(strict_types=1);

namespace App;

class Csrf
{
    public static function token(): string
    {
        $t = Session::get('_csrf');
        if (!is_string($t) || $t === '') {
            $t = bin2hex(random_bytes(32));
            Session::set('_csrf', $t);
        }
        return $t;
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(self::token()) . '">';
    }

    public static function check(): bool
    {
        $sent = $_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $real = Session::get('_csrf');
        return is_string($real) && is_string($sent) && $sent !== '' && hash_equals($real, $sent);
    }

    /**
     * Перевірити або зупинити виконання.
     */
    public static function verify(): void
    {
        if (self::check()) {
            return;
        }
        Response::status(419);
        if (strpos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false) {
            Response::json(['ok' => false, 'error' => 'Сесія застаріла. Оновіть сторінку.'], 419);
        }
        View::render('errors/419', ['title' => 'Сесія застаріла']);
        exit;
    }
}
