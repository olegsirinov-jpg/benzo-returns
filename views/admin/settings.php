<?php
use App\Config;
$adminEmail = (string)(App\Auth::user()['email'] ?? '');
?>

<div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
    <h1 class="mb0">Налаштування сповіщень</h1>
    <a class="btn btn--ghost btn--sm" href="<?= e(url('/admin')) ?>">← До заявок</a>
</div>

<form method="post" action="<?= e(url('/admin/settings')) ?>">
    <?= App\Csrf::field() ?>

    <!-- ==================== Email ==================== -->
    <div class="card mt16">
        <div class="card__title">Email (SMTP)</div>
        <p class="small muted">
            Email надсилається клієнту <strong>автоматично</strong> на всі помітні події заявки.
        </p>

        <label class="check">
            <input type="checkbox" name="mail_enabled" value="1" <?= Config::bool('mail_enabled') ? 'checked' : '' ?>>
            <span>Надсилати email клієнту</span>
        </label>

        <div class="grid2 mt16">
            <div class="field">
                <label class="label" for="mail_host">SMTP-сервер</label>
                <input class="input" type="text" id="mail_host" name="mail_host"
                       value="<?= e(Config::str('mail_host')) ?>" placeholder="smtp.example.com">
            </div>
            <div class="field">
                <label class="label" for="mail_port">Порт</label>
                <input class="input" type="number" id="mail_port" name="mail_port"
                       value="<?= e((string)Config::int('mail_port', 587)) ?>" placeholder="587">
            </div>
        </div>

        <div class="grid2">
            <div class="field">
                <label class="label" for="mail_secure">Шифрування</label>
                <select class="select" id="mail_secure" name="mail_secure">
                    <option value="tls" <?= Config::str('mail_secure', 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS (порт 587)</option>
                    <option value="ssl" <?= Config::str('mail_secure') === 'ssl' ? 'selected' : '' ?>>SSL (порт 465)</option>
                </select>
            </div>
            <div class="field">
                <label class="label" for="mail_from">Адреса відправника</label>
                <input class="input" type="email" id="mail_from" name="mail_from"
                       value="<?= e(Config::str('mail_from')) ?>" placeholder="no-reply@example.com">
            </div>
        </div>

        <div class="grid2">
            <div class="field">
                <label class="label" for="mail_user">Логін SMTP</label>
                <input class="input" type="text" id="mail_user" name="mail_user"
                       value="<?= e(Config::str('mail_user')) ?>" autocomplete="off">
            </div>
            <div class="field">
                <label class="label" for="mail_pass">Пароль SMTP</label>
                <input class="input" type="password" id="mail_pass" name="mail_pass"
                       placeholder="<?= Config::isSet('mail_pass') ? '•••••••• (збережено)' : 'не задано' ?>"
                       autocomplete="new-password">
                <div class="hint">Лишіть порожнім, щоб не змінювати. Для Gmail — «пароль застосунку».</div>
            </div>
        </div>

        <div class="field mb0">
            <label class="label" for="mail_from_name">Імʼя відправника</label>
            <input class="input" type="text" id="mail_from_name" name="mail_from_name"
                   value="<?= e(Config::str('mail_from_name', App\Env::str('APP_NAME', ''))) ?>">
        </div>
    </div>

    <!-- ==================== TurboSMS ==================== -->
    <div class="card">
        <div class="card__title">SMS / Viber (TurboSMS)</div>
        <p class="small muted">
            SMS/Viber менеджер надсилає <strong>вручну</strong> з картки заявки, коли потрібно.
            Автоматично вони не йдуть.
        </p>

        <label class="check">
            <input type="checkbox" name="sms_enabled" value="1" <?= Config::bool('sms_enabled') ? 'checked' : '' ?>>
            <span>Дозволити надсилання SMS / Viber</span>
        </label>

        <div class="field mt16">
            <label class="label" for="sms_token">API-токен TurboSMS</label>
            <input class="input" type="password" id="sms_token" name="sms_token"
                   placeholder="<?= Config::isSet('sms_token') ? '•••••••• (збережено)' : 'не задано' ?>"
                   autocomplete="new-password">
            <div class="hint">Кабінет TurboSMS → Розробникам / API. Лишіть порожнім, щоб не змінювати.</div>
        </div>

        <div class="grid2">
            <div class="field">
                <label class="label" for="sms_sender">Відправник SMS (альфа-імʼя)</label>
                <input class="input" type="text" id="sms_sender" name="sms_sender"
                       value="<?= e(Config::str('sms_sender')) ?>" placeholder="BenzoPila">
                <div class="hint">Має бути активований у вашому акаунті TurboSMS.</div>
            </div>
            <div class="field">
                <label class="label" for="sms_viber_sender">Відправник Viber</label>
                <input class="input" type="text" id="sms_viber_sender" name="sms_viber_sender"
                       value="<?= e(Config::str('sms_viber_sender')) ?>" placeholder="BenzoPila">
                <div class="hint">Якщо заданий — спершу Viber, за недоставки SMS. Порожньо = лише SMS.</div>
            </div>
        </div>
    </div>

    <!-- ==================== SalesDrive ==================== -->
    <div class="card">
        <div class="card__title">SalesDrive</div>
        <p class="small muted">
            Пошук замовлення за номером і телефоном + автоматичний коментар до замовлення при створенні заявки.
        </p>

        <label class="check">
            <input type="checkbox" name="sd_enabled" value="1" <?= Config::bool('sd_enabled') ? 'checked' : '' ?>>
            <span>Увімкнути інтеграцію з SalesDrive</span>
        </label>

        <div class="field mt16">
            <label class="label" for="sd_url">Адреса кабінету SalesDrive</label>
            <input class="input" type="text" id="sd_url" name="sd_url"
                   value="<?= e(Config::str('sd_url')) ?>" placeholder="https://вашпіддомен.salesdrive.me">
            <div class="hint">Той самий домен, під яким ви заходите в CRM.</div>
        </div>

        <div class="grid2">
            <div class="field">
                <label class="label" for="sd_api_key">API-ключ (читання заявок)</label>
                <input class="input" type="password" id="sd_api_key" name="sd_api_key"
                       placeholder="<?= Config::isSet('sd_api_key') ? '•••••••• (збережено)' : 'не задано' ?>"
                       autocomplete="new-password">
                <div class="hint">Установки → Інші сервіси → API → API-ключі.</div>
            </div>
            <div class="field">
                <label class="label" for="sd_form_key">Ключ бази заявок (для коментарів)</label>
                <input class="input" type="password" id="sd_form_key" name="sd_form_key"
                       placeholder="<?= Config::isSet('sd_form_key') ? '•••••••• (збережено)' : 'не задано' ?>"
                       autocomplete="new-password">
                <div class="hint">Окремий ключ бази заявок. Потрібен лише для дозапису коментарів у замовлення.</div>
            </div>
        </div>

        <div class="field mb0">
            <label class="label" for="sd_search_days">За скільки днів шукати замовлення</label>
            <input class="input" type="number" id="sd_search_days" name="sd_search_days"
                   value="<?= e((string)Config::int('sd_search_days', 120)) ?>" style="max-width:140px">
        </div>

        <p class="small mb0" style="margin-top:12px">
            <a href="<?= e(url('/admin/diag')) ?>">Перевірити зʼєднання й пошук замовлення →</a>
        </p>
    </div>

    <!-- ==================== Нова пошта ==================== -->
    <div class="card">
        <div class="card__title">Нова пошта — зворотні накладні</div>
        <p class="small muted">
            Після погодження менеджер зможе створити зворотну ТТН для повернення.
            Накладна створюється по оригінальній ТТН замовлення. Підтримуються два ключі
            (для двох відправників) — система сама визначить, який підходить.
        </p>

        <label class="check">
            <input type="checkbox" name="np_enabled" value="1" <?= Config::bool('np_enabled') ? 'checked' : '' ?>>
            <span>Дозволити створення зворотних накладних НП</span>
        </label>

        <div class="grid2 mt16">
            <div class="field">
                <label class="label" for="np_key1">API-ключ №1 (основний відправник)</label>
                <input class="input" type="password" id="np_key1" name="np_key1"
                       placeholder="<?= Config::isSet('np_key1') ? '•••••••• (збережено)' : 'не задано' ?>"
                       autocomplete="new-password">
            </div>
            <div class="field">
                <label class="label" for="np_key2">API-ключ №2 (другий відправник)</label>
                <input class="input" type="password" id="np_key2" name="np_key2"
                       placeholder="<?= Config::isSet('np_key2') ? '•••••••• (збережено)' : 'не задано' ?>"
                       autocomplete="new-password">
                <div class="hint">Необов’язково. Лишіть порожнім, якщо відправник один.</div>
            </div>
        </div>

        <div class="grid2">
            <div class="field">
                <label class="label" for="np_weight">Вага за замовчуванням, кг</label>
                <input class="input" type="text" id="np_weight" name="np_weight"
                       value="<?= e(Config::str('np_weight', '0.5')) ?>" placeholder="0.5">
                <div class="hint">Габарити за замовчуванням 10×10×10 см.</div>
            </div>
            <div class="field">
                <label class="label" for="np_service_type">Тип доставки</label>
                <select class="select" id="np_service_type" name="np_service_type">
                    <?php $st = Config::str('np_service_type', 'WarehouseWarehouse'); ?>
                    <option value="WarehouseWarehouse" <?= $st === 'WarehouseWarehouse' ? 'selected' : '' ?>>Відділення → Відділення</option>
                    <option value="WarehouseDoors" <?= $st === 'WarehouseDoors' ? 'selected' : '' ?>>Відділення → Адреса</option>
                </select>
            </div>
        </div>

        <div class="grid2">
            <div class="field">
                <label class="label" for="np_recipient_key">Отримувач повернень (ФОП)</label>
                <select class="select" id="np_recipient_key" name="np_recipient_key">
                    <?php $rk = Config::str('np_recipient_key', '1'); ?>
                    <option value="1" <?= $rk === '1' ? 'selected' : '' ?>>Ключ №1</option>
                    <option value="2" <?= $rk === '2' ? 'selected' : '' ?>>Ключ №2</option>
                </select>
                <div class="hint">Під цим ФОП оформлятиметься зворотна накладна.</div>
            </div>
        </div>

        <div class="field">
            <label class="label">Точка прийому повернень</label>
            <?php $curWh = Config::str('np_recipient_wh_name'); $curCity = Config::str('np_recipient_city_name'); ?>
            <?php if ($curWh !== ''): ?>
                <div class="small" style="margin-bottom:6px">
                    Обрано: <strong><?= e($curCity) ?></strong> — <?= e($curWh) ?>
                </div>
            <?php endif; ?>
            <input class="input" type="text" id="np_city_search" placeholder="Почніть вводити місто, напр. Запоріжжя" autocomplete="off">
            <div id="np_city_list" class="np-search-list"></div>
            <select class="select mt16 hidden" id="np_wh_select"></select>

            <input type="hidden" name="np_recipient_city_ref"  id="np_city_ref"  value="<?= e(Config::str('np_recipient_city_ref')) ?>">
            <input type="hidden" name="np_recipient_city_name" id="np_city_name" value="<?= e($curCity) ?>">
            <input type="hidden" name="np_recipient_wh_ref"    id="np_wh_ref"    value="<?= e(Config::str('np_recipient_wh_ref')) ?>">
            <input type="hidden" name="np_recipient_wh_name"   id="np_wh_name"   value="<?= e($curWh) ?>">
            <div class="hint">Введіть місто, оберіть його зі списку, потім оберіть відділення.</div>
        </div>

        <p class="small mb0">
            <a href="<?= e(url('/admin/np-diag')) ?>">Діагностика ключів →</a>
        </p>
    </div>

    <div class="btn-row" style="margin-bottom:20px">
        <button class="btn" type="submit">Зберегти налаштування</button>
    </div>
</form>

<script>
(function () {
    var search = document.getElementById('np_city_search');
    if (!search) { return; }
    var list = document.getElementById('np_city_list');
    var whSel = document.getElementById('np_wh_select');
    var cityRef = document.getElementById('np_city_ref');
    var cityName = document.getElementById('np_city_name');
    var whRef = document.getElementById('np_wh_ref');
    var whName = document.getElementById('np_wh_name');
    var timer = null, cache = {};

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
                    list.innerHTML = '<div class="np-search-item muted">Нічого не знайдено</div>';
                    return;
                }
                cache = {};
                list.innerHTML = '';
                res.cities.forEach(function (c) {
                    cache[c.ref] = c;
                    var d = document.createElement('div');
                    d.className = 'np-search-item';
                    d.textContent = c.name;
                    d.addEventListener('click', function () { pickCity(c); });
                    list.appendChild(d);
                });
            })
            .catch(function () { list.innerHTML = '<div class="np-search-item muted">Помилка запиту</div>'; });
    }

    function pickCity(c) {
        cityRef.value = c.ref;
        cityName.value = c.name;
        search.value = c.name;
        list.innerHTML = '';
        whSel.innerHTML = '<option value="">— Оберіть відділення —</option>';
        (c.warehouses || []).forEach(function (w) {
            var o = document.createElement('option');
            o.value = w.ref;
            o.textContent = w.name;
            o.dataset.name = w.name;
            if (w.ref === whRef.value) { o.selected = true; }
            whSel.appendChild(o);
        });
        whSel.classList.remove('hidden');
    }

    whSel.addEventListener('change', function () {
        var o = whSel.options[whSel.selectedIndex];
        whRef.value = whSel.value;
        whName.value = o ? (o.dataset.name || o.textContent) : '';
    });
})();
</script>

<!-- ==================== Тестування ==================== -->
<div class="grid2">
    <div class="card">
        <div class="card__title">Перевірити email</div>
        <form method="post" action="<?= e(url('/admin/settings/test')) ?>">
            <?= App\Csrf::field() ?>
            <input type="hidden" name="type" value="email">
            <div class="field">
                <label class="label" for="test_email">Кому надіслати тест</label>
                <input class="input" type="email" id="test_email" name="to" value="<?= e($adminEmail) ?>">
            </div>
            <button class="btn btn--ghost btn--sm" type="submit">Надіслати тестовий лист</button>
        </form>
    </div>

    <div class="card">
        <div class="card__title">Перевірити SMS / Viber</div>
        <form method="post" action="<?= e(url('/admin/settings/test')) ?>">
            <?= App\Csrf::field() ?>
            <input type="hidden" name="type" value="sms">
            <div class="field">
                <label class="label" for="test_sms">Номер телефону</label>
                <input class="input" type="tel" id="test_sms" name="to" placeholder="067 123 45 67">
            </div>
            <button class="btn btn--ghost btn--sm" type="submit">Надіслати тестове повідомлення</button>
        </form>
    </div>
</div>
