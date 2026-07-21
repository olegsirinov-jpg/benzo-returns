<?php
/** @var string $content */
/** @var string $title */
$flashes = App\Session::flashes();
$isAdmin = strpos(App\Router::uri(), '/admin') === 0;
?><!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? 'Обмін та повернення') ?> — <?= e(App\Env::str('APP_NAME', 'Обмін та повернення')) ?></title>
<link rel="stylesheet" href="<?= e(url('/assets/app.css')) ?>?v=<?= e(asset_version('/assets/app.css')) ?>">
</head>
<body class="<?= $isAdmin ? 'is-admin' : 'is-public' ?>">

<header class="topbar">
    <div class="wrap topbar__inner">
        <a class="logo" href="<?= e(url($isAdmin ? '/admin' : '/returns')) ?>">
            <span class="logo__mark">↩</span>
            <span><?= e(App\Env::str('APP_NAME', 'Обмін та повернення')) ?></span>
        </a>
        <nav class="topnav">
        <?php if ($isAdmin && App\Auth::check()): ?>
            <a href="<?= e(url('/admin')) ?>">Заявки</a>
            <a href="<?= e(url('/admin/stats')) ?>">Статистика</a>
            <?php if (App\Auth::isAdmin()): ?>
                <a href="<?= e(url('/admin/settings')) ?>">Налаштування</a>
                <a href="<?= e(url('/admin/users')) ?>">Користувачі</a>
                <a href="<?= e(url('/admin/diag')) ?>">Діагностика</a>
                <a href="<?= e(url('/admin/np-diag')) ?>">НП</a>
            <?php endif; ?>
            <span class="topnav__user"><?= e(App\Auth::name()) ?></span>
            <a class="topnav__exit" href="<?= e(url('/admin/logout')) ?>">Вийти</a>
        <?php elseif (!$isAdmin): ?>
            <a href="<?= e(url('/returns/rules')) ?>">Умови</a>
            <a href="<?= e(url('/returns/status')) ?>">Статус заявки</a>
        <?php endif; ?>
        </nav>
    </div>
</header>

<main class="wrap main">
<?php foreach ($flashes as $f): ?>
    <div class="alert alert--<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
<?php endforeach; ?>
<?= $content ?>
</main>

<footer class="footer">
    <div class="wrap">
        <p>© <?= date('Y') ?> <?= e(App\Env::str('APP_NAME', '')) ?>. Сервіс оформлення обміну та повернення.</p>
    </div>
</footer>

<script src="<?= e(url('/assets/app.js')) ?>?v=<?= e(asset_version('/assets/app.js')) ?>"></script>
</body>
</html>
