<?php
declare(strict_types=1);

namespace App;

class Auth
{
    /** @var array<string,mixed>|null */
    private static $user = null;

    public static function attempt(string $email, string $password): bool
    {
        $user = Db::one('SELECT * FROM users WHERE email = ? AND active = 1', [mb_strtolower(trim($email))]);
        if ($user === null || !password_verify($password, (string)$user['password_hash'])) {
            return false;
        }
        if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
            Db::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$user['id']]);
        }
        Session::regenerate();
        Session::set('user_id', (int)$user['id']);
        Db::update('users', ['last_login_at' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);
        self::$user = $user;
        return true;
    }

    public static function logout(): void
    {
        self::$user = null;
        Session::destroy();
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $id = Session::get('user_id');
        if (!is_int($id) && !ctype_digit((string)$id)) {
            return null;
        }
        $user = Db::one('SELECT * FROM users WHERE id = ? AND active = 1', [(int)$id]);
        self::$user = $user;
        return $user;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u === null ? null : (int)$u['id'];
    }

    public static function name(): string
    {
        $u = self::user();
        return $u === null ? 'Система' : (string)$u['name'];
    }

    public static function isAdmin(): bool
    {
        $u = self::user();
        return $u !== null && $u['role'] === 'admin';
    }

    /**
     * Вимагати авторизації.
     */
    public static function requireLogin(): void
    {
        if (self::check()) {
            return;
        }
        Session::set('_intended', Router::uri());
        Response::redirect('/admin/login');
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            Response::status(403);
            View::render('errors/403', ['title' => 'Доступ заборонено']);
            exit;
        }
    }
}
