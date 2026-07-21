<div class="card login-box">
    <h1 style="font-size:20px">Вхід в адмін-панель</h1>

    <form method="post" action="<?= e(url('/admin/login')) ?>">
        <?= App\Csrf::field() ?>
        <div class="field">
            <label class="label" for="email">Email</label>
            <input class="input" type="email" id="email" name="email" value="<?= e(old('email')) ?>" autocomplete="username" autofocus>
        </div>
        <div class="field">
            <label class="label" for="password">Пароль</label>
            <input class="input" type="password" id="password" name="password" autocomplete="current-password">
        </div>
        <button class="btn btn--block" type="submit">Увійти</button>
    </form>
</div>
