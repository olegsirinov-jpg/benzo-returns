<?php
/** @var array<string,string> $reasons, $actions, $errors */
/** @var array<int,string> $needDetails */
$err = function (string $k) use ($errors) {
    return isset($errors[$k]) ? '<div class="error-text">' . e($errors[$k]) . '</div>' : '';
};
?>

<div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
    <h1 class="mb0">Нова заявка на повернення</h1>
    <a class="btn btn--ghost btn--sm" href="<?= e(url('/admin')) ?>">← До списку</a>
</div>

<form method="post" action="<?= e(url('/admin/rma-new')) ?>" data-lookup="<?= e(url('/admin/rma-lookup')) ?>" id="mrma">
    <?= App\Csrf::field() ?>

    <div class="card mt16">
        <div class="card__title">Замовлення та клієнт</div>
        <div class="grid2">
            <div class="field">
                <label class="label">Номер замовлення <span class="req">*</span></label>
                <input class="<?= isset($errors['order_number']) ? 'input input--error' : 'input' ?>" type="text"
                       name="order_number" id="m-order" value="<?= e(old('order_number')) ?>">
                <?= $err('order_number') ?>
            </div>
            <div class="field">
                <label class="label">Телефон клієнта <span class="req">*</span></label>
                <input class="<?= isset($errors['phone']) ? 'input input--error' : 'input' ?>" type="text"
                       name="phone" id="m-phone" value="<?= e(old('phone')) ?>" placeholder="067 123 45 67">
                <?= $err('phone') ?>
            </div>
        </div>
        <div class="btn-row">
            <button type="button" class="btn btn--ghost btn--sm" id="m-lookup">Знайти замовлення в SalesDrive</button>
        </div>
        <div id="m-lookup-result" class="mt16"></div>

        <div class="grid2 mt16">
            <div class="field mb0">
                <label class="label">ПІБ клієнта</label>
                <input class="input" type="text" name="customer_name" id="m-name" value="<?= e(old('customer_name')) ?>">
            </div>
            <div class="field mb0">
                <label class="label">Email клієнта</label>
                <input class="input" type="email" name="email" id="m-email" value="<?= e(old('email')) ?>">
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card__title">Товари</div>
        <?= $err('items') ?>
        <div id="m-items"></div>
        <button type="button" class="btn btn--ghost btn--sm" id="m-add-item">+ Додати товар</button>
    </div>

    <div class="card">
        <div class="card__title">Причина та дія</div>
        <div class="grid2">
            <div class="field">
                <label class="label">Причина повернення <span class="req">*</span></label>
                <select class="<?= isset($errors['reason_code']) ? 'select select--error' : 'select' ?>" name="reason_code" id="m-reason"
                        data-need="<?= e(json_encode($needDetails)) ?>">
                    <option value="">— Оберіть —</option>
                    <?php foreach ($reasons as $c => $l): ?>
                        <option value="<?= e($c) ?>" <?= old('reason_code') === $c ? 'selected' : '' ?>><?= e($l) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('reason_code') ?>
            </div>
            <div class="field">
                <label class="label">Бажана дія <span class="req">*</span></label>
                <select class="<?= isset($errors['desired_action']) ? 'select select--error' : 'select' ?>" name="desired_action" id="m-action">
                    <option value="">— Оберіть —</option>
                    <?php foreach ($actions as $c => $l): ?>
                        <option value="<?= e($c) ?>" <?= old('desired_action') === $c ? 'selected' : '' ?>><?= e($l) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('desired_action') ?>
            </div>
        </div>

        <div class="field">
            <label class="label">Опис / деталі</label>
            <textarea class="textarea" name="reason_details" rows="2"><?= e(old('reason_details')) ?></textarea>
        </div>

        <div class="field hidden" id="m-exchange">
            <label class="label">На який товар обміняти</label>
            <textarea class="textarea" name="exchange_wish" rows="2"><?= e(old('exchange_wish')) ?></textarea>
        </div>

        <div class="field mb0">
            <label class="label">Внутрішній коментар (необовʼязково)</label>
            <textarea class="textarea" name="customer_comment" rows="2"><?= e(old('customer_comment')) ?></textarea>
        </div>
    </div>

    <div class="card hidden" id="m-refund">
        <div class="card__title">Реквізити для повернення коштів</div>
        <div class="grid2">
            <div class="field">
                <label class="label">ПІБ отримувача</label>
                <input class="input" type="text" name="refund_name" value="<?= e(old('refund_name')) ?>">
            </div>
            <div class="field">
                <label class="label">ІПН / РНОКПП</label>
                <input class="input" type="text" name="refund_tax_id" value="<?= e(old('refund_tax_id')) ?>">
            </div>
        </div>
        <div class="grid2">
            <div class="field">
                <label class="label">IBAN</label>
                <input class="<?= isset($errors['refund_iban']) ? 'input input--error mono' : 'input mono' ?>" type="text"
                       name="refund_iban" value="<?= e(old('refund_iban')) ?>" placeholder="UA…">
                <?= $err('refund_iban') ?>
            </div>
            <div class="field">
                <label class="label">Банк</label>
                <input class="input" type="text" name="refund_bank" value="<?= e(old('refund_bank')) ?>">
            </div>
        </div>
    </div>

    <div class="btn-row" style="margin-bottom:24px">
        <button class="btn btn--lg" type="submit">Створити заявку</button>
        <a class="btn btn--ghost btn--lg" href="<?= e(url('/admin')) ?>">Скасувати</a>
    </div>
</form>

<template id="m-item-tpl">
    <div class="m-item">
        <input class="input m-item__name" type="text" name="item_name[]" placeholder="Назва товару">
        <input class="input m-item__sku" type="text" name="item_sku[]" placeholder="Артикул">
        <input class="input m-item__qty" type="number" name="item_qty[]" value="1" min="1" title="Кількість">
        <input class="input m-item__price" type="text" name="item_price[]" placeholder="грн" title="Ціна">
        <button type="button" class="btn btn--sm btn--ghost m-item-del" title="Прибрати">×</button>
    </div>
</template>

<script>
(function () {
    var form = document.getElementById('mrma');
    var itemsBox = document.getElementById('m-items');
    var tpl = document.getElementById('m-item-tpl');
    var token = form.querySelector('input[name="_token"]').value;

    function addItem(data) {
        var node = tpl.content.cloneNode(true);
        var row = node.querySelector('.m-item');
        if (data) {
            row.querySelector('[name="item_name[]"]').value = data.name || '';
            row.querySelector('[name="item_sku[]"]').value = data.sku || '';
            row.querySelector('[name="item_qty[]"]').value = data.qty || 1;
            row.querySelector('[name="item_price[]"]').value = data.price || '';
        }
        row.querySelector('.m-item-del').addEventListener('click', function () { row.remove(); });
        itemsBox.appendChild(row);
    }

    document.getElementById('m-add-item').addEventListener('click', function () { addItem(); });

    // хоча б один рядок від початку
    addItem();

    // ---- пошук замовлення ----
    document.getElementById('m-lookup').addEventListener('click', function () {
        var order = document.getElementById('m-order').value.trim();
        var phone = document.getElementById('m-phone').value.trim();
        var box = document.getElementById('m-lookup-result');
        if (!order || !phone) { box.innerHTML = alert2('error', 'Вкажіть номер і телефон.'); return; }
        box.innerHTML = '<div class="muted small">Шукаємо…</div>';

        var d = new FormData();
        d.append('_token', token); d.append('order_number', order); d.append('phone', phone);
        fetch(form.dataset.lookup, { method: 'POST', body: d, headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.ok) { box.innerHTML = alert2('error', res.error || 'Помилка.'); return; }
                if (res.found) {
                    box.innerHTML = alert2('success', 'Замовлення №' + esc(res.order.number) + ' знайдено — товари підставлено.');
                    if (res.order.customer && !document.getElementById('m-name').value) document.getElementById('m-name').value = res.order.customer;
                    if (res.order.email && !document.getElementById('m-email').value) document.getElementById('m-email').value = res.order.email;
                    itemsBox.innerHTML = '';
                    (res.order.items || []).forEach(function (it) { addItem(it); });
                    if (!itemsBox.children.length) addItem();
                } else {
                    box.innerHTML = alert2('warn', res.message || 'Не знайдено.');
                }
            })
            .catch(function () { box.innerHTML = alert2('warn', 'Помилка запиту. Заповніть вручну.'); });
    });

    // ---- дія: обмін / реквізити ----
    var actionSel = document.getElementById('m-action');
    actionSel.addEventListener('change', function () {
        document.getElementById('m-exchange').classList.toggle('hidden', actionSel.value !== 'exchange');
        document.getElementById('m-refund').classList.toggle('hidden', actionSel.value !== 'refund');
    });
    actionSel.dispatchEvent(new Event('change'));

    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
    function alert2(t, m) { return '<div class="alert alert--' + t + '">' + esc(m) + '</div>'; }
})();
</script>
