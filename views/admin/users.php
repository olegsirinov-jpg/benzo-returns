<?php /** @var array<int,array<string,mixed>> $users */ ?>

<h1>Користувачі</h1>

<div class="grid2">
    <div class="card">
        <div class="card__title">Список</div>
        <div class="table-scroll" style="border:none">
            <table class="table">
                <thead><tr><th>ПІБ</th><th>Email</th><th>Роль</th><th>Останній вхід</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr style="<?= empty($u['active']) ? 'opacity:.5' : '' ?>">
                        <td><?= e($u['name']) ?></td>
                        <td class="small"><?= e($u['email']) ?></td>
                        <td>
                            <span class="badge badge--<?= $u['role'] === 'admin' ? 'violet' : 'gray' ?>">
                                <?= $u['role'] === 'admin' ? 'Адміністратор' : 'Менеджер' ?>
                            </span>
                        </td>
                        <td class="small muted"><?= dt((string)$u['last_login_at']) ?></td>
                        <td>
                            <form method="post" action="<?= e(url('/admin/users')) ?>" style="display:inline">
                                <?= App\Csrf::field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                <button class="btn btn--sm btn--ghost" type="submit">
                                    <?= empty($u['active']) ? 'Активувати' : 'Деактивувати' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card__title">Новий користувач</div>
            <form method="post" action="<?= e(url('/admin/users')) ?>">
                <?= App\Csrf::field() ?>
                <input type="hidden" name="action" value="create">
                <div class="field">
                    <label class="label" for="name">ПІБ</label>
                    <input class="input" type="text" id="name" name="name" required>
                </div>
                <div class="field">
                    <label class="label" for="email">Email</label>
                    <input class="input" type="email" id="email" name="email" required>
                </div>
                <div class="field">
                    <label class="label" for="password">Пароль</label>
                    <input class="input" type="text" id="password" name="password" required minlength="8">
                    <div class="hint">Мінімум 8 символів.</div>
                </div>
                <div class="field">
                    <label class="label" for="role">Роль</label>
                    <select class="select" id="role" name="role">
                        <option value="manager">Менеджер — заявки, статуси, коментарі, ТТН</option>
                        <option value="admin">Адміністратор — повний доступ</option>
                    </select>
                </div>
                <button class="btn" type="submit">Створити</button>
            </form>
        </div>

        <div class="card">
            <div class="card__title">Змінити пароль</div>
            <form method="post" action="<?= e(url('/admin/users')) ?>">
                <?= App\Csrf::field() ?>
                <input type="hidden" name="action" value="password">
                <div class="field">
                    <label class="label" for="user_id">Користувач</label>
                    <select class="select" id="user_id" name="user_id">
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?> — <?= e($u['email']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="label" for="new_password">Новий пароль</label>
                    <input class="input" type="text" id="new_password" name="password" required minlength="8">
                </div>
                <button class="btn btn--ghost" type="submit">Оновити пароль</button>
            </form>
        </div>
    </div>
</div>
