<?php
/** @var array<string,mixed> $rma */
/** @var array<int,array<string,mixed>> $items, $photos, $history, $comments, $managers, $notifications */
/** @var bool $smsEnabled */
/** @var array<string,array{label:string,text:string}> $smsTemplates */
/** @var bool $expired */
/** @var int|null $days */
$id     = (int)$rma['id'];
$status = (string)$rma['status'];
$token  = App\Csrf::field();

/** Кнопка зміни статусу */
$btn = function (string $status, string $label, string $class = 'btn--ghost') use ($id, $token) {
    return '<form method="post" action="' . e(url('/admin/rma/' . $id . '/status')) . '" style="display:inline">'
         . $token
         . '<input type="hidden" name="status" value="' . e($status) . '">'
         . '<button class="btn btn--sm ' . $class . '" type="submit">' . e($label) . '</button>'
         . '</form>';
};
?>

<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
    <div>
        <h1 class="mb0">
            Заявка <span class="mono"><?= e($rma['rma_number']) ?></span>
            <span class="badge badge--<?= e(App\Dict::statusColor($status)) ?>"><?= e(App\Dict::status($status)) ?></span>
        </h1>
        <p class="small muted" style="margin:6px 0 0">
            Створено <?= dt((string)$rma['created_at']) ?> · Оновлено <?= dt((string)$rma['updated_at']) ?>
        </p>
    </div>
    <a class="btn btn--ghost btn--sm" href="<?= e(url('/admin')) ?>">← До списку</a>
</div>

<?php if (!empty($rma['needs_manual_check'])): ?>
    <div class="alert alert--warn" style="margin-top:16px">
        ⚠️ Замовлення не знайдено автоматично в SalesDrive — перевірте дані вручну.
    </div>
<?php endif; ?>

<?php if ($expired): ?>
    <div class="alert alert--error" style="margin-top:16px">
        ⏰ Від дати замовлення минуло <?= (int)$days ?> днів — це більше за термін повернення
        (<?= App\Env::int('RETURN_DAYS', 14) ?> днів). Перевірте підстави для повернення.
    </div>
<?php endif; ?>

<?php if (!empty($rma['np_cost_alert'])): ?>
    <div class="alert alert--error" style="margin-top:16px">
        💸 <strong>Оплата на зворотній ТТН, хоча доставку мав оплатити клієнт.</strong>
        <?= e((string)$rma['np_cost_note']) ?>
        Перевірте перед отриманням посилки — можливо, доведеться відмовитись від отримання
        або узгодити оплату з клієнтом.
    </div>
<?php endif; ?>

<!-- ==================== Дії ==================== -->
<?php
$steps  = App\Workflow::nextSteps($rma);
$hint   = App\Workflow::hint($status);
$isFinal = App\Workflow::isFinal($status);
$canReject = App\Workflow::canReject($status);
?>
<div class="card">
    <div class="wf-head">
        <div class="card__title" style="margin:0">Дії менеджера</div>
        <form method="post" action="<?= e(url('/admin/rma/' . $id . '/status')) ?>" class="wf-set">
            <?= $token ?>
            <span class="wf-set__lbl">статус вручну:</span>
            <select class="wf-set__sel" name="status"
                    onchange="if (this.value !== '<?= e($status) ?>' && confirm('Змінити статус на «' + this.options[this.selectedIndex].text + '»?')) { this.form.submit(); } else { this.value = '<?= e($status) ?>'; }">
                <?php foreach (App\Dict::statuses() as $code => $label): ?>
                    <option value="<?= e($code) ?>" <?= $code === $status ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="wf">
        <div class="wf__now">
            <span class="wf__label">Зараз:</span>
            <span class="badge badge--<?= e(App\Dict::statusColor($status)) ?>"><?= e(App\Dict::status($status)) ?></span>
        </div>
        <?php if ($hint !== ''): ?>
            <p class="wf__hint"><?= e($hint) ?></p>
        <?php endif; ?>
    </div>

    <?php if ($steps !== []): ?>
        <div class="wf__next">
            <div class="wf__next-label">Наступний крок</div>
            <div class="btn-row">
                <?php foreach ($steps as $st): ?>
                    <?= $btn($st['status'], $st['label'], $st['primary'] ? 'btn--green' : 'btn--ghost') ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php elseif ($isFinal): ?>
        <p class="muted small">Заявку завершено. Змінити статус можна вручну нижче.</p>
    <?php endif; ?>

    <details class="wf__more">
        <summary>Інші дії</summary>
        <div class="wf__more-body">
            <div class="grid2">
                <?php if ($canReject): ?>
                <!-- Запросити додаткові дані -->
                <form method="post" action="<?= e(url('/admin/rma/' . $id . '/status')) ?>">
                    <?= $token ?>
                    <input type="hidden" name="status" value="need_more_info">
                    <label class="label">Запросити додаткові дані у клієнта</label>
                    <textarea class="textarea" name="comment" rows="2"
                              placeholder="Що саме потрібно? Текст побачить клієнт на сторінці статусу та в листі."></textarea>
                    <button class="btn btn--sm btn--ghost mt16" type="submit">Запросити дані / фото</button>
                </form>

                <!-- Відмова -->
                <form method="post" action="<?= e(url('/admin/rma/' . $id . '/status')) ?>">
                    <?= $token ?>
                    <input type="hidden" name="status" value="rejected">
                    <label class="label">Відмовити у поверненні</label>
                    <select class="select" name="reject_reason" style="margin-bottom:8px">
                        <option value="">— Причина відмови —</option>
                        <?php foreach (App\Dict::rejectReasons() as $code => $label): ?>
                            <option value="<?= e($code) ?>" <?= (string)$rma['reject_reason'] === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <textarea class="textarea" name="comment" rows="2" placeholder="Коментар для клієнта (обов’язково)"></textarea>
                    <button class="btn btn--sm btn--red mt16" type="submit">Відмовити</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </details>
</div>

<div class="grid2">
    <div>
        <!-- ==================== Блок 1: Товар ==================== -->
        <div class="card">
            <div class="card__title">1. Товар</div>
            <?php if ($items === []): ?>
                <p class="muted mb0">Товарів немає.</p>
            <?php else: ?>
                <div class="table-scroll" style="border:none">
                    <table class="table">
                        <thead><tr><th>Назва</th><th>Артикул</th><th>К-сть</th><th>Ціна</th><th>Постачальник</th></tr></thead>
                        <tbody>
                        <?php foreach ($items as $it): ?>
                            <tr>
                                <td><?= $it['url'] ? '<a href="' . e($it['url']) . '" target="_blank" rel="noopener">' . e($it['name']) . '</a>' : e($it['name']) ?></td>
                                <td class="mono"><?= e($it['sku'] ?: '—') ?></td>
                                <td><?= (int)$it['qty'] ?></td>
                                <td class="nowrap"><?= money($it['price']) ?></td>
                                <td class="small"><?= e(App\Supplier::name((string)$it['supplier'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <h3>Фото від клієнта</h3>
            <?php if ($photos === []): ?>
                <p class="muted small">Фото немає.</p>
            <?php else: ?>
                <div class="photos">
                    <?php foreach ($photos as $p): ?>
                        <div class="photo">
                            <a href="<?= e(url('/uploads/' . $p['file'])) ?>" target="_blank" rel="noopener">
                                <img src="<?= e(url('/uploads/' . $p['file'])) ?>" alt="<?= e($p['orig_name']) ?>" loading="lazy">
                            </a>
                            <div class="photo__cap">
                                <?= e(App\Dict::photoTypes()[(string)$p['type']] ?? 'Фото') ?>
                                <?= $p['uploaded_by'] === 'manager' ? ' <span class="muted">(менеджер)</span>' : '' ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('/admin/rma/' . $id . '/photo')) ?>" enctype="multipart/form-data" class="mt16">
                <?= $token ?>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                    <input class="input" type="file" name="photos[]" accept="image/*" multiple style="max-width:280px;padding:6px">
                    <select class="select" name="type" style="max-width:180px">
                        <?php foreach (App\Dict::photoTypes() as $code => $label): ?>
                            <option value="<?= e($code) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn--sm btn--ghost" type="submit">Додати фото</button>
                </div>
            </form>
        </div>

        <!-- ==================== Блок 2: Причина ==================== -->
        <div class="card">
            <div class="card__title">2. Причина</div>
            <table class="kv">
                <tr><td>Причина повернення</td><td><strong><?= e(App\Dict::reason((string)$rma['reason_code'])) ?></strong></td></tr>
                <tr><td>Бажана дія</td><td><?= e(App\Dict::action((string)$rma['desired_action'])) ?></td></tr>
            </table>
            <?php if ($rma['reason_details']): ?>
                <h3>Опис клієнта</h3>
                <p class="small" style="white-space:pre-wrap;margin:0"><?= e($rma['reason_details']) ?></p>
            <?php endif; ?>
            <?php if ($rma['exchange_wish']): ?>
                <h3>На що обміняти</h3>
                <p class="small" style="white-space:pre-wrap;margin:0"><?= e($rma['exchange_wish']) ?></p>
            <?php endif; ?>
            <?php if ($rma['customer_comment']): ?>
                <h3>Коментар клієнта</h3>
                <p class="small" style="white-space:pre-wrap;margin:0"><?= e($rma['customer_comment']) ?></p>
            <?php endif; ?>
        </div>

        <!-- ==================== Блок 3: Стан товару ==================== -->
        <div class="card">
            <div class="card__title">3. Стан товару — підтвердження клієнта</div>
            <?php
            $confirms = [
                'confirm_not_installed' => 'Товар не встановлювався на техніку',
                'confirm_no_traces'     => 'Немає слідів використання, монтажу, мастила',
                'confirm_packaging'     => 'Збережено упаковку та комплектацію',
                'confirm_understand'    => 'Розуміє, що товар зі слідами може бути не прийнято',
                'confirm_rules'         => 'Погодився з правилами повернення',
            ];
            foreach ($confirms as $key => $label):
                $ok = !empty($rma[$key]);
            ?>
                <div class="small" style="padding:5px 0;border-bottom:1px solid #f1f2f5">
                    <span style="color:<?= $ok ? 'var(--green)' : 'var(--red)' ?>"><?= $ok ? '✓' : '✕' ?></span>
                    <?= e($label) ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- ==================== Блок 4: Основна інформація ==================== -->
        <div class="card">
            <div class="card__title">4. Основна інформація</div>
            <table class="kv">
                <tr><td>Номер заявки</td><td class="mono"><strong><?= e($rma['rma_number']) ?></strong></td></tr>
                <tr><td>Дата створення</td><td><?= dt((string)$rma['created_at']) ?></td></tr>
                <tr><td>Номер замовлення</td><td>№<?= e($rma['order_number']) ?>
                    <?php if (!empty($rma['order_id_sd'])): ?>
                        <span class="badge badge--green" title="ID у SalesDrive: <?= e($rma['order_id_sd']) ?>">SalesDrive ✓</span>
                    <?php endif; ?>
                </td></tr>
                <tr><td>Дата замовлення</td><td>
                    <?= dt((string)$rma['order_date'], 'd.m.Y') ?>
                    <?php if ($days !== null): ?><span class="muted small">(<?= (int)$days ?> дн. тому)</span><?php endif; ?>
                </td></tr>
                <tr><td>ПІБ</td><td><?= e($rma['customer_name'] ?: '—') ?></td></tr>
                <tr><td>Телефон</td><td>
                    <a href="tel:+<?= e($rma['phone']) ?>"><?= e(App\Validate::phoneFormat((string)$rma['phone'])) ?></a>
                </td></tr>
                <tr><td>Email</td><td><?= $rma['email'] ? '<a href="mailto:' . e($rma['email']) . '">' . e($rma['email']) . '</a>' : '—' ?></td></tr>
                <tr><td>Джерело</td><td><?= e($rma['source']) ?><?= $rma['ip'] ? ' <span class="muted small">' . e($rma['ip']) . '</span>' : '' ?></td></tr>
                <tr><td>Синхронізація SalesDrive</td><td><?= !empty($rma['sd_synced']) ? '<span class="badge badge--green">коментар додано</span>' : '<span class="badge badge--gray">ні</span>' ?></td></tr>
            </table>
        </div>
    </div>

    <div>
        <!-- ==================== Блоки 5-6: редагування ==================== -->
        <form method="post" action="<?= e(url('/admin/rma/' . $id . '/save')) ?>">
            <?= $token ?>

            <div class="card">
                <div class="grid2">
                    <div class="field mb0">
                        <label class="label">Відповідальний менеджер</label>
                        <select class="select" name="manager_id">
                            <option value="">— Не призначено —</option>
                            <?php foreach ($managers as $m): ?>
                                <option value="<?= (int)$m['id'] ?>" <?= (int)$rma['manager_id'] === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field mb0">
                        <label class="label">Бажана дія</label>
                        <select class="select" name="desired_action" id="card-action">
                            <?php foreach (App\Dict::actions() as $code => $label): ?>
                                <option value="<?= e($code) ?>" <?= (string)$rma['desired_action'] === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="hint">Уточніть після розмови з клієнтом (напр. «ще не знаю» → «повернути кошти»).</div>
                    </div>
                </div>

                <div class="field mb0 mt16 <?= (string)$rma['desired_action'] === 'exchange' ? '' : 'hidden' ?>" id="card-exchange">
                    <label class="label">На який товар обміняти</label>
                    <textarea class="textarea" name="exchange_wish" rows="2"><?= e((string)$rma['exchange_wish']) ?></textarea>
                </div>
                <div class="hint mt16 hidden" id="card-refund-hint">
                    Обрано «Повернути кошти» — збережіть зміни, і зʼявиться блок реквізитів нижче.
                </div>
            </div>
            <script>
            (function () {
                var sel = document.getElementById('card-action');
                var ex  = document.getElementById('card-exchange');
                var rf  = document.getElementById('card-refund-hint');
                if (!sel) { return; }
                var initial = sel.value;
                sel.addEventListener('change', function () {
                    ex.classList.toggle('hidden', sel.value !== 'exchange');
                    // підказка про реквізити лише якщо refund щойно обрали, а блоку ще нема
                    rf.classList.toggle('hidden', !(sel.value === 'refund' && sel.value !== initial && !document.querySelector('input[name="refund_iban"]')));
                });
            })();
            </script>

            <?php if ((string)$rma['desired_action'] === 'refund' || $rma['refund_iban']): ?>
            <div class="card">
                <div class="card__title">5. Реквізити для повернення коштів</div>
                <?php if (empty($rma['refund_iban'])): ?>
                    <div class="alert alert--info" style="margin-top:0">
                        Клієнт ще не вказав реквізити. Не заповнюйте вручну — надішліть йому посилання:
                        у блоці «SMS / Viber клієнту» нижче оберіть шаблон
                        <strong>«Запросити реквізити для повернення коштів»</strong>.
                        Клієнт впише IBAN сам зручною формою з підказками.
                    </div>
                <?php endif; ?>
                <div class="field">
                    <label class="label">ПІБ отримувача</label>
                    <input class="input" type="text" name="refund_name" value="<?= e((string)$rma['refund_name']) ?>">
                </div>
                <div class="field">
                    <label class="label">IBAN</label>
                    <input class="input mono" type="text" name="refund_iban" value="<?= e((string)$rma['refund_iban']) ?>">
                </div>
                <div class="grid2">
                    <div class="field">
                        <label class="label">ІПН / РНОКПП / ЄДРПОУ</label>
                        <input class="input mono" type="text" name="refund_tax_id" value="<?= e((string)$rma['refund_tax_id']) ?>">
                    </div>
                    <div class="field">
                        <label class="label">Банк</label>
                        <input class="input" type="text" name="refund_bank" value="<?= e((string)$rma['refund_bank']) ?>">
                    </div>
                </div>
                <div class="grid2">
                    <div class="field">
                        <label class="label">Сума до повернення, грн</label>
                        <input class="input" type="text" name="refund_amount" value="<?= $rma['refund_amount'] !== null ? e(number_format((float)$rma['refund_amount'], 2, '.', '')) : '' ?>"
                               placeholder="<?= $rma['total_amount'] !== null ? e(number_format((float)$rma['total_amount'], 2, '.', '')) : '' ?>">
                        <?php if ($rma['total_amount'] !== null): ?>
                            <div class="hint">Сума товарів у заявці: <?= money($rma['total_amount']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="field">
                        <label class="label">Статус виплати</label>
                        <input class="input" type="text" readonly
                               value="<?= $rma['refund_paid_at'] ? 'Виплачено ' . dt((string)$rma['refund_paid_at']) : 'Не виплачено' ?>">
                    </div>
                </div>
                <?php if ($rma['refund_comment']): ?>
                    <p class="hint">Коментар клієнта: <?= e($rma['refund_comment']) ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="card">
                <details class="fold">
                    <summary class="card__title" style="margin:0;cursor:pointer">
                        6. Доставка <span class="muted" style="font-weight:400">— ТТН, дати, оплата (розгорнути)</span>
                    </summary>
                    <div style="margin-top:14px">
                        <p class="small muted">
                            Зазвичай не потрібне — накладну НП створюйте у блоці «Зворотна накладна» нижче.
                            Тут — ручне заповнення для Укрпошти чи нестандартних випадків.
                        </p>
                        <div class="grid2">
                            <div class="field">
                                <label class="label">ТТН повернення</label>
                                <input class="input mono" type="text" name="return_ttn" value="<?= e((string)$rma['return_ttn']) ?>">
                            </div>
                            <div class="field">
                                <label class="label">Перевізник</label>
                                <select class="select" name="carrier">
                                    <option value="">— Не вказано —</option>
                                    <?php foreach (App\Dict::carriers() as $code => $label): ?>
                                        <option value="<?= e($code) ?>" <?= (string)$rma['carrier'] === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="grid2">
                            <div class="field">
                                <label class="label">Дата відправки</label>
                                <input class="input" type="date" name="shipped_at" value="<?= e((string)$rma['shipped_at']) ?>">
                            </div>
                            <div class="field">
                                <label class="label">Дата отримання</label>
                                <input class="input" type="date" name="received_at" value="<?= e((string)$rma['received_at']) ?>">
                            </div>
                        </div>
                        <div class="field">
                            <label class="label">Хто оплачує доставку</label>
                            <select class="select" name="shipping_payer">
                                <?php foreach (App\Dict::shippingPayers() as $code => $label): ?>
                                    <option value="<?= e($code) ?>" <?= (string)$rma['shipping_payer'] === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="hint">За замовчуванням підставлено згідно з причиною повернення.</div>
                        </div>
                        <div class="field mb0">
                            <label class="label">Коментар щодо доставки</label>
                            <textarea class="textarea" name="shipping_comment" rows="2"><?= e((string)$rma['shipping_comment']) ?></textarea>
                        </div>
                    </div>
                </details>
            </div>

            <div class="btn-row" style="margin-bottom:16px">
                <button class="btn" type="submit">Зберегти зміни</button>
            </div>
        </form>

        <!-- ==================== Зворотна накладна НП ==================== -->
        <?php if (!empty($npReady)): ?>
        <div class="card">
            <div class="card__title">Зворотна накладна Нової пошти</div>
            <?php if (!empty($rma['np_doc_ref'])): ?>
                <table class="kv">
                    <tr><td>ТТН повернення</td><td class="mono"><strong><?= e($rma['return_ttn']) ?></strong></td></tr>
                    <tr><td>Перевізник</td><td>Нова пошта</td></tr>
                    <?php if (!empty($rma['np_track_status'])): ?>
                        <tr><td>Статус посилки</td><td>
                            <?= e($rma['np_track_status']) ?>
                            <div class="small muted">оновлено <?= dt((string)$rma['np_tracked_at']) ?></div>
                        </td></tr>
                    <?php endif; ?>
                </table>
                <p class="small muted">
                    Накладну створено. Надішліть номер клієнту (кнопка SMS/Viber нижче) з інструкцією:
                    прийти на будь-яке відділення НП і назвати цей номер.
                </p>
                <div class="btn-row">
                    <form method="post" action="<?= e(url('/admin/rma/' . $id . '/np-track')) ?>" style="display:inline">
                        <?= $token ?>
                        <button class="btn btn--sm btn--ghost" type="submit">Оновити трекінг</button>
                    </form>
                    <form method="post" action="<?= e(url('/admin/rma/' . $id . '/np-cancel')) ?>" style="display:inline"
                          onsubmit="return confirm('Видалити зворотну накладну <?= e($rma['return_ttn']) ?>?')">
                        <?= $token ?>
                        <button class="btn btn--sm btn--red" type="submit">Видалити накладну</button>
                    </form>
                </div>
            <?php elseif (!empty($rma['return_ttn'])): ?>
                <?php
                $src = (string)($rma['ttn_source'] ?? '');
                $isLightSrc = $src === 'light_return';
                ?>
                <div class="alert <?= $isLightSrc ? 'alert--success' : 'alert--info' ?>" style="margin-top:0">
                    <?php if ($isLightSrc): ?>
                        Клієнт оформив <strong>«Легке повернення»</strong> Нової пошти.
                        Створювати накладну магазину <strong>не потрібно</strong>.
                    <?php else: ?>
                        ТТН повернення вже вказано (<?= $src === 'manual' ? 'клієнтом вручну' : 'вручну' ?>).
                        Створювати нову накладну магазину <strong>не потрібно</strong>.
                    <?php endif; ?>
                </div>
                <table class="kv">
                    <tr><td>ТТН повернення</td><td class="mono"><strong><?= e($rma['return_ttn']) ?></strong></td></tr>
                    <tr><td>Перевізник</td><td><?= e(App\Dict::carriers()[(string)$rma['carrier']] ?? (string)$rma['carrier'] ?: 'Нова пошта') ?></td></tr>
                    <?php if ($isLightSrc && !empty($rma['light_return_reason'])): ?>
                        <tr><td>Причина (НП)</td><td><?= e($rma['light_return_reason']) ?></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($rma['np_track_status'])): ?>
                        <tr><td>Статус посилки</td><td>
                            <?= e($rma['np_track_status']) ?>
                            <div class="small muted">оновлено <?= dt((string)$rma['np_tracked_at']) ?></div>
                        </td></tr>
                    <?php endif; ?>
                </table>
                <div class="btn-row" style="margin-bottom:10px">
                    <form method="post" action="<?= e(url('/admin/rma/' . $id . '/np-track')) ?>" style="display:inline">
                        <?= $token ?>
                        <button class="btn btn--sm btn--ghost" type="submit">Оновити трекінг</button>
                    </form>
                </div>
                <p class="small muted mb0">
                    Якщо потрібно все одно оформити накладну магазину — спершу приберіть ТТН у блоці «Доставка».
                </p>
            <?php else: ?>
                <p class="small muted">
                    Створіть зворотну накладну — клієнт віднесе товар на відділення НП за готовим номером.
                    Платник: <strong><?= e(App\Dict::shippingPayers()[(string)$rma['shipping_payer']] ?? '—') ?></strong>
                    (за причиною повернення).
                </p>

                <div class="field">
                    <label class="label">Місто й відділення клієнта (звідки відправляє)</label>
                    <div id="np-addr-current" class="small" style="margin-bottom:6px"></div>
                    <button type="button" class="btn btn--sm btn--ghost" id="np-pull">Підтягнути з замовлення</button>
                    <input class="input mt16" type="text" id="np-city-search" placeholder="або введіть місто вручну" autocomplete="off">
                    <div id="np-city-list" class="np-search-list"></div>
                    <select class="select mt16 hidden" id="np-wh-select"></select>
                </div>

                <form method="post" action="<?= e(url('/admin/rma/' . $id . '/np-create')) ?>" id="np-create-form">
                    <?= $token ?>
                    <input type="hidden" name="city_ref" id="np-city-ref">
                    <input type="hidden" name="wh_ref" id="np-wh-ref">
                    <div class="grid2">
                        <div class="field">
                            <label class="label" for="np-cost">Оголошена вартість, грн</label>
                            <input class="input" type="text" id="np-cost" name="cost"
                                   value="<?= $rma['total_amount'] !== null ? e(number_format((float)$rma['total_amount'], 0, '.', '')) : '300' ?>">
                        </div>
                        <div class="field">
                            <label class="label">Орієнтовна вартість доставки</label>
                            <input class="input" type="text" id="np-price" value="—" readonly>
                        </div>
                    </div>
                    <div class="btn-row">
                        <button type="button" class="btn btn--sm btn--ghost" id="np-calc" disabled>Розрахувати ціну</button>
                        <button type="submit" class="btn btn--sm btn--green" id="np-submit" disabled
                                onclick="return confirm('Створити зворотну накладну НП?')">Створити накладну</button>
                    </div>
                    <div class="hint">Спершу вкажіть відділення клієнта — тоді кнопки стануть активними.</div>
                </form>

                <script>
                (function () {
                    var pull = document.getElementById('np-pull');
                    var cur = document.getElementById('np-addr-current');
                    var search = document.getElementById('np-city-search');
                    var list = document.getElementById('np-city-list');
                    var whSel = document.getElementById('np-wh-select');
                    var cityRef = document.getElementById('np-city-ref');
                    var whRef = document.getElementById('np-wh-ref');
                    var calc = document.getElementById('np-calc');
                    var submit = document.getElementById('np-submit');
                    var priceOut = document.getElementById('np-price');
                    var base = '<?= e(url('/admin/rma/' . $id)) ?>';
                    var timer = null;

                    function refresh() {
                        var ready = cityRef.value && whRef.value;
                        calc.disabled = !cityRef.value;
                        submit.disabled = !ready;
                    }

                    pull.addEventListener('click', function () {
                        cur.textContent = 'Завантаження…';
                        fetch(base + '/np-address', { credentials: 'same-origin' })
                            .then(function (r) { return r.json(); })
                            .then(function (res) {
                                if (!res.ok) { cur.textContent = res.error || 'Не вдалося підтягнути'; return; }
                                cityRef.value = res.point.city_ref;
                                whRef.value = res.point.wh_ref;
                                cur.innerHTML = '<strong>' + esc(res.point.city_name) + '</strong> — ' + esc(res.point.wh_name);
                                whSel.classList.add('hidden'); list.innerHTML = ''; search.value = '';
                                refresh();
                            })
                            .catch(function () { cur.textContent = 'Помилка запиту'; });
                    });

                    search.addEventListener('input', function () {
                        var q = search.value.trim();
                        clearTimeout(timer);
                        if (q.length < 2) { list.innerHTML = ''; return; }
                        timer = setTimeout(function () {
                            fetch('<?= e(url('/admin/np/warehouses')) ?>?city=' + encodeURIComponent(q), { credentials: 'same-origin' })
                                .then(function (r) { return r.json(); })
                                .then(function (res) {
                                    list.innerHTML = '';
                                    if (!res.ok || !res.cities) { return; }
                                    res.cities.forEach(function (c) {
                                        var d = document.createElement('div');
                                        d.className = 'np-search-item'; d.textContent = c.name;
                                        d.addEventListener('click', function () {
                                            cityRef.value = c.ref; search.value = c.name; list.innerHTML = '';
                                            cur.textContent = '';
                                            whSel.innerHTML = '<option value="">— Відділення —</option>';
                                            (c.warehouses || []).forEach(function (w) {
                                                var o = document.createElement('option');
                                                o.value = w.ref; o.textContent = w.name; whSel.appendChild(o);
                                            });
                                            whSel.classList.remove('hidden'); whRef.value = ''; refresh();
                                        });
                                        list.appendChild(d);
                                    });
                                });
                        }, 350);
                    });

                    whSel.addEventListener('change', function () { whRef.value = whSel.value; refresh(); });

                    calc.addEventListener('click', function () {
                        if (!cityRef.value) { return; }
                        priceOut.value = 'рахуємо…';
                        fetch(base + '/np-price?city_ref=' + encodeURIComponent(cityRef.value), { credentials: 'same-origin' })
                            .then(function (r) { return r.json(); })
                            .then(function (res) {
                                priceOut.value = res.ok ? (res.cost + ' грн') : ('помилка: ' + (res.error || ''));
                            })
                            .catch(function () { priceOut.value = 'помилка'; });
                    });

                    function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
                })();
                </script>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ==================== SMS / Viber клієнту (вручну) ==================== -->
        <div class="card">
            <div class="card__title">SMS / Viber клієнту</div>
            <?php if (empty($smsEnabled)): ?>
                <p class="small muted mb0">
                    Надсилання не налаштовано.
                    <?php if (App\Auth::isAdmin()): ?>
                        Заповніть TurboSMS у <a href="<?= e(url('/admin/settings')) ?>">Налаштуваннях</a>.
                    <?php else: ?>
                        Зверніться до адміністратора.
                    <?php endif; ?>
                </p>
            <?php elseif (empty($rma['phone'])): ?>
                <p class="small muted mb0">У заявці немає телефону клієнта.</p>
            <?php else: ?>
                <form method="post" action="<?= e(url('/admin/rma/' . $id . '/sms')) ?>">
                    <?= $token ?>
                    <p class="small muted">
                        Надіслати на <strong><?= e(App\Validate::phoneFormat((string)$rma['phone'])) ?></strong>.
                        Спершу Viber, за недоставки — SMS.
                    </p>
                    <div class="field">
                        <label class="label" for="sms-tpl">Шаблон</label>
                        <select class="select" id="sms-tpl">
                            <option value="">— Обрати готовий текст —</option>
                            <?php foreach ($smsTemplates as $key => $tpl): ?>
                                <option value="<?= e($tpl['text']) ?>"><?= e($tpl['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <textarea class="textarea" id="sms-text" name="text" rows="3"
                                  maxlength="1000" placeholder="Текст повідомлення клієнту…"></textarea>
                        <div class="hint"><span id="sms-count">0</span> символів</div>
                    </div>
                    <button class="btn btn--sm" type="submit">Надіслати SMS / Viber</button>
                </form>
                <script>
                (function () {
                    var sel = document.getElementById('sms-tpl');
                    var txt = document.getElementById('sms-text');
                    var cnt = document.getElementById('sms-count');
                    if (!sel || !txt) { return; }
                    sel.addEventListener('change', function () {
                        if (sel.value) { txt.value = sel.value; cnt.textContent = txt.value.length; }
                    });
                    txt.addEventListener('input', function () { cnt.textContent = txt.value.length; });
                })();
                </script>
            <?php endif; ?>
        </div>

        <!-- ==================== Сповіщення клієнту ==================== -->
        <div class="card">
            <div class="card__title">Історія сповіщень</div>
            <?php if (empty($notifications)): ?>
                <p class="muted small mb0">Сповіщень ще не надсилалось.</p>
            <?php else: ?>
                <?php
                $chLabel = ['email' => 'Email', 'sms' => 'SMS / Viber'];
                $stLabel = ['sent' => 'green', 'failed' => 'red', 'skipped' => 'gray'];
                $evLabel = [
                    'created' => 'Заявку створено', 'need_more_info' => 'Потрібні дані',
                    'approved' => 'Повернення погоджено', 'received' => 'Товар отримано',
                    'refunded' => 'Кошти повернено', 'rejected' => 'Відмову',
                    'manual_sms' => 'Повідомлення менеджера',
                ];
                ?>
                <table class="table">
                    <tbody>
                    <?php foreach ($notifications as $n): ?>
                        <tr>
                            <td class="small nowrap"><?= dt((string)$n['created_at'], 'd.m H:i') ?></td>
                            <td class="small"><?= e($chLabel[(string)$n['channel']] ?? $n['channel']) ?></td>
                            <td class="small"><?= e($evLabel[(string)$n['event']] ?? $n['event']) ?></td>
                            <td>
                                <span class="badge badge--<?= e($stLabel[(string)$n['status']] ?? 'gray') ?>">
                                    <?= $n['status'] === 'sent' ? 'надіслано' : ($n['status'] === 'failed' ? 'помилка' : 'пропущено') ?>
                                </span>
                                <?php if ($n['status'] === 'failed' && $n['detail']): ?>
                                    <div class="small muted"><?= e(str_limit((string)$n['detail'], 60)) ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- ==================== Історія ==================== -->
        <div class="card">
            <div class="card__title">Історія змін</div>
            <?php if ($history === []): ?>
                <p class="muted small mb0">Записів немає.</p>
            <?php else: ?>
                <ul class="timeline">
                    <?php foreach ($history as $h): ?>
                        <li>
                            <div class="timeline__time"><?= dt((string)$h['created_at']) ?></div>
                            <div>
                                <strong><?= e($h['user_name']) ?></strong> —
                                <?php if ($h['field'] === 'created'): ?>
                                    створив(ла) заявку <span class="mono"><?= e($h['new_value']) ?></span>
                                <?php elseif ($h['field'] === 'status'): ?>
                                    змінив(ла) статус з «<?= e($h['old_value']) ?>» на «<strong><?= e($h['new_value']) ?></strong>»
                                <?php else: ?>
                                    <?= e($h['field']) ?>: «<?= e(str_limit((string)$h['old_value'], 40)) ?>» → «<strong><?= e(str_limit((string)$h['new_value'], 40)) ?></strong>»
                                <?php endif; ?>
                            </div>
                            <?php if ($h['comment']): ?>
                                <div class="small muted"><?= e($h['comment']) ?></div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- ==================== Коментарі ==================== -->
        <div class="card">
            <div class="card__title">Коментарі</div>
            <form method="post" action="<?= e(url('/admin/rma/' . $id . '/comment')) ?>">
                <?= $token ?>
                <div class="field">
                    <textarea class="textarea" name="text" rows="3" placeholder="Текст коментаря…"></textarea>
                </div>
                <div style="display:flex;gap:8px">
                    <select class="select" name="type" style="max-width:220px">
                        <?php foreach (App\Dict::commentTypes() as $code => $label): ?>
                            <option value="<?= e($code) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn--sm" type="submit">Додати</button>
                </div>
                <div class="hint">«Коментар для клієнта» буде видно на сторінці статусу заявки.</div>
            </form>

            <div class="mt16">
                <?php if ($comments === []): ?>
                    <p class="muted small mb0">Коментарів немає.</p>
                <?php else: ?>
                    <?php foreach ($comments as $c): ?>
                        <div class="comment comment--<?= e($c['type']) ?>">
                            <div class="comment__head">
                                <span>
                                    <strong><?= e($c['author']) ?></strong>
                                    · <?= e(App\Dict::commentTypes()[(string)$c['type']] ?? '') ?>
                                </span>
                                <span><?= dt((string)$c['created_at']) ?></span>
                            </div>
                            <div class="comment__text"><?= e($c['text']) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if (App\Auth::isAdmin()): ?>
        <div class="card">
            <div class="card__title">Небезпечна зона</div>
            <form method="post" action="<?= e(url('/admin/rma/' . $id . '/delete')) ?>"
                  onsubmit="return confirm('Видалити заявку <?= e($rma['rma_number']) ?> назавжди? Дію неможливо скасувати.')">
                <?= $token ?>
                <button class="btn btn--sm btn--red" type="submit">Видалити заявку</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>
