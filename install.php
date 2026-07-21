<?php
/**
 * Інсталятор: створює базу (якщо потрібно), таблиці та першого адміністратора.
 *
 * ПІСЛЯ ВСТАНОВЛЕННЯ ВИДАЛІТЬ ЦЕЙ ФАЙЛ.
 */
declare(strict_types=1);

define('BASE_PATH', __DIR__);
require BASE_PATH . '/app/bootstrap.php';

use App\Csrf;
use App\Db;
use App\Env;

$errors = [];
$done   = false;
$dbCreated = false;

if (!Env::isConfigured()) {
    $errors[] = 'Файл .env не знайдено. Скопіюйте .env.example у .env і заповніть налаштування БД.';
}

/**
 * Створює базу, якщо її ще немає.
 */
function ensureDatabase(bool &$created): ?string
{
    $name = Env::str('DB_NAME', 'povirnenya');
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
        return 'Некоректне імʼя бази у DB_NAME: дозволені лише літери, цифри та підкреслення.';
    }
    try {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', Env::str('DB_HOST', '127.0.0.1'), Env::int('DB_PORT', 3306));
        $pdo = new PDO($dsn, Env::str('DB_USER', 'root'), Env::str('DB_PASS', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $exists = (int)$pdo->query('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ' . $pdo->quote($name))->fetchColumn() > 0;
        if (!$exists) {
            $pdo->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $created = true;
        }
        return null;
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
}

if ($errors === []) {
    $dbError = ensureDatabase($dbCreated);
    if ($dbError !== null) {
        $errors[] = 'Не вдалося підготувати базу: ' . $dbError
                  . ' — перевірте DB_HOST / DB_NAME / DB_USER / DB_PASS у .env. '
                  . 'На хостингу базу зазвичай треба створити вручну в панелі, а користувача додати з правами.';
    }
}

$hasUsers = false;
$dbOk     = false;
if ($errors === []) {
    try {
        Db::pdo();
        $dbOk = true;
        $hasUsers = (int)Db::value(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = "users"',
            [Env::str('DB_NAME')]
        ) > 0;
        if ($hasUsers) {
            $hasUsers = (int)Db::value('SELECT COUNT(*) FROM users') > 0;
        }
    } catch (\Throwable $e) {
        $errors[] = 'БД: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors === []) {
    if (!Csrf::check()) {
        $errors[] = 'Сесія застаріла, оновіть сторінку.';
    } else {
        $name     = trim((string)($_POST['name'] ?? ''));
        $email    = filter_var((string)($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $password = (string)($_POST['password'] ?? '');

        if ($name === '')             { $errors[] = 'Вкажіть ПІБ адміністратора.'; }
        if ($email === false)         { $errors[] = 'Вкажіть коректний email.'; }
        if (mb_strlen($password) < 8) { $errors[] = 'Пароль має бути щонайменше 8 символів.'; }

        if ($errors === []) {
            try {
                $sql = (string)file_get_contents(BASE_PATH . '/install/schema.sql');
                // Прибираємо рядки-коментарі ПЕРЕД розбиттям на команди,
                // інакше коментар перед CREATE «зʼїдав» усю команду.
                $lines = preg_split('/\r?\n/', $sql) ?: [];
                $clean = [];
                foreach ($lines as $line) {
                    if (preg_match('/^\s*--/', $line)) {
                        continue;
                    }
                    $clean[] = $line;
                }
                $sql = implode("\n", $clean);
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                    if ($statement !== '') {
                        Db::pdo()->exec($statement);
                    }
                }

                if ((int)Db::value('SELECT COUNT(*) FROM users') === 0) {
                    Db::insert('users', [
                        'name'          => $name,
                        'email'         => mb_strtolower((string)$email),
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'role'          => 'admin',
                        'active'        => 1,
                        'created_at'    => date('Y-m-d H:i:s'),
                    ]);
                }

                // позначаємо схему як актуальну, щоб мігратор не повторював кроки
                Db::run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)',
                    ['schema_version', '4']);

                foreach ([BASE_PATH . '/uploads', BASE_PATH . '/storage/logs'] as $dir) {
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0775, true);
                    }
                }
                if (!is_file(BASE_PATH . '/uploads/.htaccess')) {
                    @file_put_contents(BASE_PATH . '/uploads/.htaccess', implode("\n", [
                        'Options -Indexes -ExecCGI',
                        'AddType text/plain .php .php3 .phtml .pht .phar',
                        '<FilesMatch "\.(php|php3|phtml|pht|phar|cgi|pl|py|sh|html?)$">',
                        '    <IfModule mod_authz_core.c>',
                        '        Require all denied',
                        '    </IfModule>',
                        '</FilesMatch>',
                    ]) . "\n");
                }

                $done = true;
            } catch (\Throwable $e) {
                $errors[] = 'Помилка встановлення: ' . $e->getMessage();
            }
        }
    }
}

$checks = [
    'PHP ≥ 7.4'          => version_compare(PHP_VERSION, '7.4.0', '>=') ? PHP_VERSION : false,
    'PDO MySQL'          => extension_loaded('pdo_mysql'),
    'cURL (SalesDrive, НП, Telegram)' => extension_loaded('curl'),
    'GD (стиснення фото)' => extension_loaded('gd'),
    'mbstring'           => extension_loaded('mbstring'),
    'Каталог доступний для запису' => is_writable(BASE_PATH) || is_writable(BASE_PATH . '/uploads'),
    'База даних «' . Env::str('DB_NAME') . '»' => $dbOk,
];
?><!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Встановлення — Система повернень</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<main class="wrap main" style="max-width:640px">

<h1>Встановлення системи повернень</h1>

<?php if ($dbCreated): ?>
    <div class="alert alert--info">Базу <code><?= e(Env::str('DB_NAME')) ?></code> створено автоматично.</div>
<?php endif; ?>

<?php if ($done): ?>
    <div class="alert alert--success"><strong>Готово!</strong> Таблиці створено, адміністратора додано.</div>
    <div class="card">
        <h2 class="mt0">Наступні кроки</h2>
        <ol>
            <li><strong>Видаліть файл <code>install.php</code></strong> — це важливо для безпеки.</li>
            <li>Увійдіть: <a href="<?= e(url('/admin/login')) ?>"><?= e(url('/admin/login')) ?></a></li>
            <li>У «Налаштуваннях» впишіть ключі SalesDrive / Нової пошти / TurboSMS / SMTP.</li>
            <li>Налаштуйте крони (stale.php, nptrack.php).</li>
        </ol>
    </div>
<?php else: ?>

    <div class="card">
        <div class="card__title">Перевірка середовища</div>
        <table class="kv">
        <?php foreach ($checks as $label => $ok): ?>
            <tr>
                <td><?= e($label) ?></td>
                <td><?= $ok === false ? '<span class="badge badge--red">немає</span>' : '<span class="badge badge--green">✓ ' . (is_string($ok) ? e($ok) : '') . '</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </table>
    </div>

    <?php foreach ($errors as $err): ?>
        <div class="alert alert--error"><?= e($err) ?></div>
    <?php endforeach; ?>

    <?php if ($hasUsers): ?>
        <div class="alert alert--warn">Система вже встановлена. <strong>Видаліть install.php</strong> і <a href="<?= e(url('/admin/login')) ?>">увійдіть</a>.</div>
    <?php elseif ($dbOk): ?>
        <div class="card">
            <div class="card__title">Адміністратор</div>
            <form method="post">
                <?= Csrf::field() ?>
                <div class="field">
                    <label class="label" for="name">ПІБ</label>
                    <input class="input" type="text" id="name" name="name" value="<?= e((string)($_POST['name'] ?? '')) ?>" required>
                </div>
                <div class="field">
                    <label class="label" for="email">Email</label>
                    <input class="input" type="email" id="email" name="email" value="<?= e((string)($_POST['email'] ?? '')) ?>" required>
                </div>
                <div class="field">
                    <label class="label" for="password">Пароль</label>
                    <input class="input" type="text" id="password" name="password" required minlength="8">
                    <div class="hint">Мінімум 8 символів.</div>
                </div>
                <button class="btn btn--block" type="submit">Встановити</button>
            </form>
        </div>
    <?php endif; ?>

<?php endif; ?>

</main>
</body>
</html>
