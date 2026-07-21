<?php
/** @var array<string,string> $reasons */
/** @var array<string,string> $actions */
/** @var array<int,string> $needDetails */
/** @var array<int,string> $needDefect */
/** @var array<string,string> $errors */
/** @var int $returnDays */
$err = function (string $key) use ($errors) {
    return isset($errors[$key]) ? '<div class="error-text">' . e($errors[$key]) . '</div>' : '';
};
$cls = function (string $key, string $base) use ($errors) {
    return $errors[$key] ?? false ? $base . ' ' . $base . '--error' : $base;
};
?>

<h1>Заявка на обмін або повернення</h1>

<div class="notice">
    <strong>Не відправляйте товар без погодження заявки.</strong>
    Спершу оформіть заявку — менеджер перевірить інформацію та надішле інструкцію для відправки.
</div>

<form id="rma-form" method="post" action="<?= e(url('/returns/submit')) ?>"
      data-lookup-url="<?= e(url('/returns/lookup')) ?>"
      <?php if (!empty($restore)): ?>data-restore="<?= e(json_encode($restore, JSON_UNESCAPED_UNICODE)) ?>"<?php endif; ?>
      enctype="multipart/form-data" novalidate>
<?= App\Csrf::field() ?>

<!-- ============ Крок 1 ============ -->
<section class="step" id="step-1">
    <div class="step__head"><span class="step__num">1</span><span class="step__title">Знайдемо ваше замовлення</span></div>
    <div class="step__body">
        <div class="grid2">
            <div class="field">
                <label class="label" for="order_number">Номер замовлення <span class="req">*</span></label>
                <input class="<?= $cls('order_number', 'input') ?>" type="text" id="order_number" name="order_number"
                       value="<?= e(old('order_number', $restore['order_number'] ?? '')) ?>" autocomplete="off" inputmode="numeric">
                <div class="hint">Номер вказаний у СМС або листі про підтвердження замовлення.</div>
                <?= $err('order_number') ?>
            </div>
            <div class="field">
                <label class="label" for="phone">Телефон, вказаний у замовленні <span class="req">*</span></label>
                <input class="<?= $cls('phone', 'input') ?>" type="tel" id="phone" name="phone"
                       value="<?= e(old('phone', $restore['phone'] ?? '')) ?>" placeholder="067 123 45 67" autocomplete="tel">
                <?= $err('phone') ?>
            </div>
        </div>

        <div class="btn-row">
            <button type="button" class="btn" id="lookup-btn">Знайти замовлення</button>
            <button type="button" class="btn btn--ghost" id="skip-lookup">Заповнити вручну</button>
        </div>

        <div id="lookup-result" class="mt16"></div>
    </div>
</section>

<div id="rest" class="hidden">

<!-- ============ Крок 2 ============ -->
<section class="step" id="step-2">
    <div class="step__head"><span class="step__num">2</span><span class="step__title">Оберіть товар</span></div>
    <div class="step__body">
        <?= $err('items') ?>

        <div id="order-items" class="hidden"></div>

        <div id="manual-items">
            <p class="small muted">Вкажіть товар, який хочете повернути або обміняти.</p>
            <div id="manual-rows"><?php
                // відновлення введених позицій після помилки валідації
                $oldNames  = old('item_name', []);
                $oldSkus   = old('item_sku', []);
                $oldQtys   = old('item_qty', []);
                $oldPrices = old('item_price', []);
                if (is_array($oldNames)) {
                    foreach ($oldNames as $i => $oldName) {
                        if (trim((string)$oldName) === '') {
                            continue;
                        }
                        echo App\View::partial('public/_item_row', [
                            'name'  => (string)$oldName,
                            'sku'   => (string)($oldSkus[$i] ?? ''),
                            'qty'   => (string)($oldQtys[$i] ?? '1'),
                            'price' => (string)($oldPrices[$i] ?? ''),
                        ]);
                    }
                }
            ?></div>
            <button type="button" class="btn btn--ghost btn--sm" id="add-item">+ Додати ще товар</button>
        </div>

        <div class="field mt16">
            <label class="label" for="customer_name">Ваше ПІБ</label>
            <input class="input" type="text" id="customer_name" name="customer_name" value="<?= e(old('customer_name')) ?>">
        </div>
        <div class="field">
            <label class="label" for="email">Email (необов’язково)</label>
            <input class="input" type="email" id="email" name="email" value="<?= e(old('email')) ?>">
            <div class="hint">Вкажіть, якщо хочете отримувати повідомлення про заявку на пошту.</div>
        </div>
    </div>
</section>

<!-- ============ Крок 3 ============ -->
<section class="step" id="step-3">
    <div class="step__head"><span class="step__num">3</span><span class="step__title">Причина повернення</span></div>
    <div class="step__body">
        <div class="field">
            <label class="label" for="reason_code">Причина повернення <span class="req">*</span></label>
            <select class="<?= $cls('reason_code', 'select') ?>" id="reason_code" name="reason_code"
                    data-need-details="<?= e(json_encode($needDetails)) ?>"
                    data-need-defect="<?= e(json_encode($needDefect)) ?>">
                <option value="">— Оберіть причину —</option>
                <?php foreach ($reasons as $code => $label): ?>
                    <option value="<?= e($code) ?>" <?= old('reason_code') === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $err('reason_code') ?>
        </div>

        <div class="field">
            <label class="label" for="reason_details">
                Опишіть детальніше ситуацію <span class="req hidden" id="details-req">*</span>
            </label>
            <textarea class="<?= $cls('reason_details', 'textarea') ?>" id="reason_details" name="reason_details"
                      placeholder="Що саме не так із товаром?"><?= e(old('reason_details')) ?></textarea>
            <?= $err('reason_details') ?>
        </div>
    </div>
</section>

<!-- ============ Крок 4 ============ -->
<section class="step" id="step-4">
    <div class="step__head"><span class="step__num">4</span><span class="step__title">Що ви хочете зробити?</span></div>
    <div class="step__body">
        <div class="field">
            <label class="label" for="desired_action">Бажана дія <span class="req">*</span></label>
            <select class="<?= $cls('desired_action', 'select') ?>" id="desired_action" name="desired_action">
                <option value="">— Оберіть дію —</option>
                <?php foreach ($actions as $code => $label): ?>
                    <option value="<?= e($code) ?>" <?= old('desired_action') === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $err('desired_action') ?>
        </div>

        <div class="field hidden" id="exchange-block">
            <label class="label" for="exchange_wish">На який товар хочете обміняти? <span class="req">*</span></label>
            <textarea class="<?= $cls('exchange_wish', 'textarea') ?>" id="exchange_wish" name="exchange_wish"
                      placeholder="Назва, артикул або посилання на товар"><?= e(old('exchange_wish')) ?></textarea>
            <?= $err('exchange_wish') ?>
        </div>

        <!-- Реквізити: показуються тут же, одразу після вибору «Повернути кошти» -->
        <div class="subblock hidden" id="refund-block">
            <div class="subblock__title">Реквізити для повернення коштів</div>

            <details class="help">
                <summary>Де знайти ці реквізити?</summary>
                <div class="help__body">
                    <p>Для повернення коштів потрібні реквізити <strong>рахунку</strong>:</p>
                    <ul>
                        <li><strong>IBAN</strong> — номер рахунку у форматі UA…</li>
                        <li><strong>ІПН / РНОКПП</strong> отримувача коштів</li>
                        <li><strong>ПІБ</strong> отримувача коштів</li>
                    </ul>
                    <p class="mb0">
                        Ці дані можна знайти у вашому банківському додатку в розділі
                        «Реквізити картки», «Реквізити рахунку» або «Довідка з реквізитами».
                    </p>
                </div>
            </details>

            <div class="grid2">
                <div class="field">
                    <label class="label" for="refund_name">ПІБ отримувача <span class="req">*</span></label>
                    <input class="<?= $cls('refund_name', 'input') ?>" type="text" id="refund_name" name="refund_name" value="<?= e(old('refund_name')) ?>">
                    <?= $err('refund_name') ?>
                </div>
                <div class="field">
                    <label class="label" for="refund_tax_id">ІПН / РНОКПП або ЄДРПОУ <span class="req">*</span></label>
                    <input class="<?= $cls('refund_tax_id', 'input') ?>" type="text" id="refund_tax_id" name="refund_tax_id"
                           value="<?= e(old('refund_tax_id')) ?>" inputmode="numeric">
                    <?= $err('refund_tax_id') ?>
                </div>
            </div>

            <div class="field">
                <label class="label" for="refund_iban">IBAN <span class="req">*</span></label>
                <input class="<?= $cls('refund_iban', 'input') ?> mono" type="text" id="refund_iban" name="refund_iban"
                       value="<?= e(old('refund_iban')) ?>" placeholder="UA00 0000 0000 0000 0000 0000 00000" spellcheck="false">
                <div class="hint">Це <strong>не номер картки</strong>, а рахунок у форматі UA…</div>
                <?= $err('refund_iban') ?>
            </div>

            <div class="field mb0">
                <label class="label" for="refund_bank">Назва банку <span class="muted">(необов’язково)</span></label>
                <input class="input" type="text" id="refund_bank" name="refund_bank" value="<?= e(old('refund_bank')) ?>">
            </div>
        </div>

        <div class="field">
            <label class="label" for="customer_comment">Коментар (необов’язково)</label>
            <textarea class="textarea" id="customer_comment" name="customer_comment"><?= e(old('customer_comment')) ?></textarea>
        </div>
    </div>
</section>

<!-- ============ Крок 5 ============ -->
<section class="step" id="step-5">
    <div class="step__head"><span class="step__num">5</span><span class="step__title">Підтвердження стану товару</span></div>
    <div class="step__body">
        <label class="check">
            <input type="checkbox" name="confirm_not_installed" value="1" <?= old('confirm_not_installed') ? 'checked' : '' ?>>
            <span>Я підтверджую, що товар не встановлювався на техніку.</span>
        </label>
        <?= $err('confirm_not_installed') ?>

        <label class="check">
            <input type="checkbox" name="confirm_no_traces" value="1" <?= old('confirm_no_traces') ? 'checked' : '' ?>>
            <span>Я підтверджую, що товар не має слідів використання, монтажу, мастила, пального, герметика або пошкоджень.</span>
        </label>
        <?= $err('confirm_no_traces') ?>

        <label class="check">
            <input type="checkbox" name="confirm_packaging" value="1" <?= old('confirm_packaging') ? 'checked' : '' ?>>
            <span>Я підтверджую, що збережено упаковку та комплектацію, якщо вони були.</span>
        </label>
        <?= $err('confirm_packaging') ?>

        <label class="check">
            <input type="checkbox" name="confirm_understand" value="1" <?= old('confirm_understand') ? 'checked' : '' ?>>
            <span>Я розумію, що товар зі слідами встановлення або використання може бути не прийнятий до повернення.</span>
        </label>
        <?= $err('confirm_understand') ?>

        <div class="notice">
            <strong>Електротовари після встановлення не повертаються.</strong><br>
            Електротовари, електронні компоненти, реле, датчики, котушки, комутатори, регулятори напруги,
            стартери, генератори та інші подібні товари після встановлення, підключення або використання
            обміну та поверненню не підлягають.
        </div>
    </div>
</section>

<!-- ============ Крок 6 ============ -->
<section class="step" id="step-6">
    <div class="step__head">
        <span class="step__num">6</span>
        <span class="step__title">
            Фото товару
            <span class="muted" style="font-weight:400" id="photo-optional">(необов’язково)</span>
            <span class="req hidden" id="photo-required">*</span>
        </span>
    </div>
    <div class="step__body">
        <div class="uploader" id="uploader">
            <p class="small mb0">
                Перетягніть фото сюди або <label for="photo-input" style="color:var(--brand);cursor:pointer;text-decoration:underline">оберіть файли</label>.<br>
                <span class="muted">jpg, png, webp — до 10 MB кожне. Великі фото стискаються автоматично.</span>
            </p>
            <input type="file" id="photo-input" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple class="hidden">
        </div>
        <?= $err('photos') ?>

        <div class="upload-list" id="upload-list"></div>

        <div class="hint mt16">
            <span id="photo-hint-optional">
                Фото не обов’язкове, але з ним заявку розглянуть швидше.
                Найкорисніші — загальне фото товару та фото упаковки.
            </span>
            <span id="defect-hint" class="hidden">
                <strong style="color:var(--red)">Для обраної причини фото обов’язкове.</strong>
                Покажіть на ньому дефект або невідповідність — без цього менеджер не зможе ухвалити рішення.
            </span>
        </div>
    </div>
</section>

<!-- ============ Крок 7 ============ -->
<section class="step" id="step-7">
    <div class="step__head"><span class="step__num">7</span><span class="step__title">Підтвердження</span></div>
    <div class="step__body">
        <label class="check">
            <input type="checkbox" name="confirm_rules" value="1" <?= old('confirm_rules') ? 'checked' : '' ?>>
            <span>
                Я ознайомився/ознайомилась з <a href="<?= e(url('/returns/rules')) ?>" target="_blank">умовами обміну та повернення</a>
                і погоджуюсь з ними.
            </span>
        </label>
        <?= $err('confirm_rules') ?>

        <div class="btn-row mt16">
            <button type="submit" class="btn btn--lg" id="submit-btn">Відправити заявку</button>
        </div>
        <p class="hint">Після відправки ви отримаєте номер заявки. Не відправляйте товар до погодження.</p>
    </div>
</section>

</div><!-- /#rest -->
</form>

<!-- шаблон рядка товару, що заповнюється вручну -->
<template id="item-row-tpl">
    <div class="card" style="padding:14px;margin-bottom:10px">
        <div class="grid2">
            <div class="field mb0">
                <label class="label">Назва товару <span class="req">*</span></label>
                <input class="input" type="text" name="item_name[]" placeholder="Ланцюг 325 72 ланки">
            </div>
            <div class="field mb0">
                <label class="label">Артикул</label>
                <input class="input" type="text" name="item_sku[]" placeholder="123456">
            </div>
        </div>
        <div class="grid2 mt16">
            <div class="field mb0">
                <label class="label">Кількість <span class="req">*</span></label>
                <input class="input" type="number" name="item_qty[]" value="1" min="1" max="999">
            </div>
            <div class="field mb0">
                <label class="label">Ціна, грн</label>
                <input class="input" type="text" name="item_price[]" inputmode="decimal" placeholder="450">
            </div>
        </div>
        <input type="hidden" name="item_url[]" value="">
        <div class="btn-row mt16">
            <button type="button" class="btn btn--ghost btn--sm js-remove-item">Прибрати</button>
        </div>
    </div>
</template>
