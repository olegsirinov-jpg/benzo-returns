<?php
/** @var string $name */
/** @var string $sku */
/** @var string $qty */
/** @var string $price */
?>
<div class="card" style="padding:14px;margin-bottom:10px">
    <div class="grid2">
        <div class="field mb0">
            <label class="label">Назва товару <span class="req">*</span></label>
            <input class="input" type="text" name="item_name[]" value="<?= e($name) ?>" placeholder="Ланцюг 325 72 ланки">
        </div>
        <div class="field mb0">
            <label class="label">Артикул</label>
            <input class="input" type="text" name="item_sku[]" value="<?= e($sku) ?>" placeholder="123456">
        </div>
    </div>
    <div class="grid2 mt16">
        <div class="field mb0">
            <label class="label">Кількість <span class="req">*</span></label>
            <input class="input" type="number" name="item_qty[]" value="<?= e($qty !== '' ? $qty : '1') ?>" min="1" max="999">
        </div>
        <div class="field mb0">
            <label class="label">Ціна, грн</label>
            <input class="input" type="text" name="item_price[]" value="<?= e($price) ?>" inputmode="decimal" placeholder="450">
        </div>
    </div>
    <input type="hidden" name="item_url[]" value="">
    <div class="btn-row mt16">
        <button type="button" class="btn btn--ghost btn--sm js-remove-item">Прибрати</button>
    </div>
</div>
