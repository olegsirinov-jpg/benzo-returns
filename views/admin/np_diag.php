<?php
/** @var array<string,mixed>|null $result */
/** @var array<int,array<string,mixed>>|null $senders */
/** @var int $keyCount */
?>

<div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
    <h1 class="mb0">Діагностика Нової пошти</h1>
    <a class="btn btn--ghost btn--sm" href="<?= e(url('/admin/settings')) ?>">← Налаштування</a>
</div>

<div class="card mt16">
    <div class="card__title">Що це</div>
    <p class="small muted mb0">
        Перевіряє можливість створити <strong>зворотну накладну</strong> по номеру оригінальної ТТН,
        якою товар їхав до клієнта. Пробує обидва ваші ключі — і показує, чий контрагент був
        відправником (лише він може оформити повернення). Це <strong>лише перевірка</strong>,
        накладна не створюється.
    </p>
    <?php if ($keyCount === 0): ?>
        <div class="alert alert--warn" style="margin:12px 0 0">
            Не задано жодного ключа Нової пошти. Додайте їх у
            <a href="<?= e(url('/admin/settings')) ?>">Налаштуваннях</a>.
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card__title">1. Готовність акаунтів відправляти</div>
    <p class="small muted">
        Підтягує контрагента-відправника й контактну особу для кожного ключа.
        Це підтверджує, що акаунт налаштований, і дає дані для створення накладної.
    </p>
    <form method="post" action="<?= e(url('/admin/np-diag')) ?>">
        <?= App\Csrf::field() ?>
        <input type="hidden" name="mode" value="senders">
        <button class="btn btn--ghost" type="submit" <?= $keyCount === 0 ? 'disabled' : '' ?>>Перевірити відправників</button>
    </form>

    <?php if ($senders !== null): ?>
        <div class="mt16">
            <?php foreach ($senders as $s): ?>
                <?php $info = $s['info']; $cp = $info['counterparty']; ?>
                <div style="border-top:1px solid var(--line);padding-top:12px;margin-top:12px">
                    <strong>Ключ №<?= (int)$s['idx'] ?>:</strong>
                    <?php if ($cp === null): ?>
                        <span class="badge badge--red">відправника не знайдено</span>
                        <?php if ($info['error']): ?><div class="small muted"><?= e($info['error']) ?></div><?php endif; ?>
                    <?php else: ?>
                        <span class="badge badge--green"><?= e((string)($cp['Description'] ?? $cp['FirstName'] ?? 'контрагент')) ?></span>
                        <span class="small muted">контактів: <?= count($info['contacts']) ?></span>
                        <pre class="small mono" style="background:#fafbfc;border:1px solid var(--line);border-radius:8px;padding:10px;overflow:auto;max-height:220px;margin-top:8px"><?= e(json_encode(['counterparty' => $cp, 'contacts' => $info['contacts']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card__title">2. Перевірка ТТН (діагностична)</div>
    <p class="small muted">
        Показує статус методу повернення НП по ТТН. Для нашого сценарію (клієнт уже отримав товар)
        він поверне «impossible» — це нормально, накладну ми створюватимемо іншим методом.
    </p>
    <form method="post" action="<?= e(url('/admin/np-diag')) ?>">
        <?= App\Csrf::field() ?>
        <div class="field">
            <label class="label" for="ttn">Номер оригінальної ТТН</label>
            <input class="input mono" type="text" id="ttn" name="ttn"
                   value="<?= e((string)($_POST['ttn'] ?? '')) ?>" placeholder="20450000000000">
            <div class="hint">Візьміть ТТН з реального замовлення, яке їхало Новою поштою.</div>
        </div>
        <input type="hidden" name="mode" value="ttn">
        <button class="btn btn--ghost" type="submit" <?= $keyCount === 0 ? 'disabled' : '' ?>>Перевірити обома ключами</button>
    </form>
</div>

<div class="card">
    <div class="card__title">3. Тест створення накладної (створить і одразу видалить)</div>
    <p class="small muted">
        Перевіряє весь ланцюг: контрагенти, ціна, створення зворотної накладної й видалення тестової.
        Оберіть будь-яке місто/відділення клієнта — накладна одразу видаляється, тому це безпечно.
    </p>
    <form method="post" action="<?= e(url('/admin/np-diag')) ?>">
        <?= App\Csrf::field() ?>
        <input type="hidden" name="mode" value="testcreate">
        <div class="field">
            <label class="label" for="np_city_search">Тестове місто клієнта</label>
            <input class="input" type="text" id="np_city_search" placeholder="напр. Київ" autocomplete="off">
            <div id="np_city_list" class="np-search-list"></div>
            <select class="select mt16 hidden" id="np_wh_select"></select>
            <input type="hidden" name="client_city_ref" id="np_city_ref">
            <input type="hidden" name="client_wh_ref" id="np_wh_ref">
        </div>
        <button class="btn" type="submit" <?= $keyCount === 0 ? 'disabled' : '' ?>>Тестове створення + видалення</button>
    </form>

    <?php if ($result !== null && isset($result['testcreate'])): ?>
        <?php $tc = $result['testcreate']; ?>
        <div class="mt16">
            <?php if (!empty($tc['ok'])): ?>
                <div class="alert alert--success">Повний ланцюг працює — накладна створюється й видаляється. Можна вбудовувати в картку.</div>
            <?php elseif (!empty($tc['note'])): ?>
                <div class="alert alert--warn"><?= e($tc['note']) ?></div>
            <?php endif; ?>

            <table class="table">
                <tbody>
                <?php foreach ($tc['steps'] as $st): ?>
                    <tr>
                        <td><?= e($st['name']) ?></td>
                        <td><span class="badge badge--<?= !empty($st['ok']) ? 'green' : 'red' ?>"><?= !empty($st['ok']) ? 'ок' : 'помилка' ?></span></td>
                        <td class="small"><?= e((string)($st['note'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h3>Повні дані (надішліть мені)</h3>
            <pre class="small mono" style="background:#fafbfc;border:1px solid var(--line);border-radius:8px;padding:12px;overflow:auto;max-height:420px"><?= e(json_encode($tc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
        </div>
    <?php endif; ?>
</div>

<?php if ($result !== null && !isset($result['testcreate'])): ?>

    <?php if ($result['winner'] > 0): ?>
        <div class="alert alert--success">
            Повернення можна створити ключем <strong>№<?= (int)$result['winner'] ?></strong> —
            саме цей контрагент був відправником замовлення.
        </div>
    <?php else: ?>
        <div class="alert alert--warn">
            Жоден ключ не підтвердив можливість повернення. Причини у таблиці нижче:
            або ТТН не цього акаунта, або замовлення ще не доставлено, або минув термін повернення НП.
        </div>
    <?php endif; ?>

    <?php foreach ($result['keys'] as $k): ?>
        <div class="card">
            <div class="card__title">
                Ключ №<?= (int)$k['idx'] ?>
                <?php if ($k['possible']): ?>
                    <span class="badge badge--green">повернення можливе</span>
                <?php else: ?>
                    <span class="badge badge--gray">ні</span>
                <?php endif; ?>
            </div>
            <table class="kv">
                <tr><td>HTTP</td><td><?= (int)$k['http'] ?></td></tr>
                <tr><td>success</td><td><?= $k['success'] ? 'так' : 'ні' ?></td></tr>
                <?php if ($k['errors'] !== []): ?>
                    <tr><td>Помилки</td><td style="color:var(--red)"><?= e(implode('; ', $k['errors'])) ?></td></tr>
                <?php endif; ?>
            </table>

            <?php if ($k['data'] !== []): ?>
                <h3>Дані для створення повернення (адресні блоки)</h3>
                <p class="small muted">Саме ці поля знадобляться для оформлення накладної — надішліть мені цей JSON.</p>
                <pre class="small mono" style="background:#fafbfc;border:1px solid var(--line);border-radius:8px;padding:12px;overflow:auto;max-height:360px"><?= e(json_encode($k['data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
            <?php elseif ($k['raw'] !== null): ?>
                <h3>Повна відповідь</h3>
                <pre class="small mono" style="background:#fafbfc;border:1px solid var(--line);border-radius:8px;padding:12px;overflow:auto;max-height:280px"><?= e(json_encode($k['raw'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

<?php endif; ?>

<script>
(function () {
    var search = document.getElementById('np_city_search');
    if (!search) { return; }
    var list = document.getElementById('np_city_list');
    var whSel = document.getElementById('np_wh_select');
    var cityRef = document.getElementById('np_city_ref');
    var whRef = document.getElementById('np_wh_ref');
    var timer = null;

    search.addEventListener('input', function () {
        var q = search.value.trim();
        clearTimeout(timer);
        if (q.length < 2) { list.innerHTML = ''; return; }
        timer = setTimeout(function () { lookup(q); }, 350);
    });

    function lookup(q) {
        list.innerHTML = '<div class="np-search-item muted">Пошук…</div>';
        fetch('<?= e(url('/admin/np/warehouses')) ?>?city=' + encodeURIComponent(q), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.ok || !res.cities || !res.cities.length) {
                    list.innerHTML = '<div class="np-search-item muted">Нічого не знайдено</div>'; return;
                }
                list.innerHTML = '';
                res.cities.forEach(function (c) {
                    var d = document.createElement('div');
                    d.className = 'np-search-item';
                    d.textContent = c.name;
                    d.addEventListener('click', function () { pickCity(c); });
                    list.appendChild(d);
                });
            })
            .catch(function () { list.innerHTML = '<div class="np-search-item muted">Помилка</div>'; });
    }

    function pickCity(c) {
        cityRef.value = c.ref;
        search.value = c.name;
        list.innerHTML = '';
        whSel.innerHTML = '<option value="">— Оберіть відділення —</option>';
        (c.warehouses || []).forEach(function (w) {
            var o = document.createElement('option');
            o.value = w.ref; o.textContent = w.name;
            whSel.appendChild(o);
        });
        whSel.classList.remove('hidden');
    }

    whSel.addEventListener('change', function () { whRef.value = whSel.value; });
})();
</script>
