<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Response;
use App\Session;
use App\View;

class AuthController
{
    public function loginForm(): void
    {
        if (Auth::check()) {
            Response::redirect('/admin');
        }
        View::render('admin/login', ['title' => 'Вхід в адмін-панель']);
    }

    public function login(): void
    {
        Csrf::verify();

        $email    = (string)($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        // захист від перебору паролів
        $attempts = (int)Session::get('_login_attempts', 0);
        $blocked  = (int)Session::get('_login_blocked_until', 0);
        if ($blocked > time()) {
            Session::flash('error', 'Забагато невдалих спроб. Спробуйте через ' . ceil(($blocked - time()) / 60) . ' хв.');
            Response::redirect('/admin/login');
        }

        if (!Auth::attempt($email, $password)) {
            $attempts++;
            Session::set('_login_attempts', $attempts);
            if ($attempts >= 5) {
                Session::set('_login_blocked_until', time() + 600);
                Session::set('_login_attempts', 0);
            }
            Session::flash('error', 'Невірний email або пароль.');
            Session::keepOld(['email' => $email]);
            Response::redirect('/admin/login');
        }

        Session::forget('_login_attempts');
        Session::forget('_login_blocked_until');

        $intended = Session::pull('_intended', '/admin');
        Response::redirect(is_string($intended) ? $intended : '/admin');
    }

    public function logout(): void
    {
        Auth::logout();
        Response::redirect('/admin/login');
    }
}
