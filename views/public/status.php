<?php
/** @var array<string,mixed>|null $rma */
/** @var array<int,array<string,mixed>> $items */
/** @var array<int,array<string,mixed>> $comments */
$items    = $items ?? [];
$comments = $comments ?? [];
?>

<?php if ($rma === null): ?>

    <h1>Статус заявки</h1>

    <div class="card" style="max-width:520px">
        <p class="small muted">Вкажіть номер заявки та телефон, який ви залишали при оформленні.</p>

        <form method="post" action="<?= e(url('/returns/status')) ?>">
            <?= App\Csrf::field() ?>
            <div class="field">
                <label class="label" for="rma_number">Номер заявки</label>
                <input class="input mono" type="text" id="rma_number" name="rma_number"
                       value="<?= e(old('rma_number')) ?>" placeholder="RMA-000123">
            </div>
            <div class="field">
                <label class="label" for="phone">Телефон</label>
                <input class="input" type="tel" id="phone" name="phone" value="<?= e(old('phone')) ?>" placeholder="067 123 45 67">
            </div>
            <button class="btn btn--block" type="submit">Знайти заявку</button>
        </form>
    </div>

<?php else: ?>

    <?php
    $status = (string)$rma['status'];
    $action = (string)$rma['desired_action'];

    // Погоджено, клієнт має відправити товар
    $approved  = in_array($status, ['approved', 'waiting_customer_shipment'], true);
    $inTransit = $status === 'in_transit';

    // Звідки взялася ТТН повернення
    $returnTtn = trim((string)($rma['return_ttn'] ?? ''));
    $ttnSource = (string)($rma['ttn_source'] ?? '');
    $hasTtn    = $returnTtn !== '';
    $isOurNp   = $hasTtn && ($ttnSource === 'our_np' || !empty($rma['np_doc_ref']));
    $isLight   = $hasTtn && $ttnSource === 'light_return';
    $isManual  = $hasTtn && !$isOurNp && !$isLight;

    // Замовлення доставлялося Новою поштою — Легке повернення відстежиться автоматично
    $hasOriginalTtn = !empty($rma['np_original_ttn']);

    // Клієнт ще має відправити (погоджено й ТТН немає)
    $needsShipping = $approved && !$hasTtn;

    // Позначка джерела ТТН для картки
    $ttnSourceLabel = '';
    if ($isOurNp)      { $ttnSourceLabel = 'накладна магазину'; }
    elseif ($isLight)  { $ttnSourceLabel = 'Легке повернення НП'; }
    elseif ($isManual) { $ttnSourceLabel = 'вказано вами'; }

    $payerLabel = App\Dict::shippingPayers()[(string)$rma['shipping_payer']] ?? 'За домовленістю';
    ?>

    <h1>Заявка <span class="mono"><?= e($rma['rma_number']) ?></span></h1>

    <div class="card">
        <table class="kv">
            <tr><td>Номер заявки</td><td class="mono"><strong><?= e($rma['rma_number']) ?></strong></td></tr>
            <tr><td>Статус</td><td>
                <span class="badge badge--<?= e(App\Dict::statusColor($status)) ?>"><?= e(App\Dict::status($status)) ?></span>
            </td></tr>
            <tr><td>Замовлення</td><td>№<?= e($rma['order_number']) ?></td></tr>
            <tr><td>Товар</td><td>
                <?php if ($items === []): ?>—<?php else: ?>
                    <?php foreach ($items as $it): ?>
                        <div><?= e($it['name']) ?><?= $it['sku'] ? ' <span class="muted small">(' . e($it['sku']) . ')</span>' : '' ?> × <?= (int)$it['qty'] ?></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </td></tr>
            <tr><td>Причина</td><td><?= e(App\Dict::reason((string)$rma['reason_code'])) ?></td></tr>
            <tr><td>Бажана дія</td><td><?= e(App\Dict::action($action)) ?></td></tr>
            <tr><td>Дата створення</td><td><?= dt((string)$rma['created_at'], 'd.m.Y') ?></td></tr>
            <?php if ($hasTtn): ?>
                <tr><td>ТТН повернення</td><td class="mono"><?= e($returnTtn) ?><?php if ($ttnSourceLabel !== ''): ?> <span class="muted small">(<?= e($ttnSourceLabel) ?>)</span><?php endif; ?></td></tr>
            <?php endif; ?>
            <?php if ($rma['client_message']): ?>
                <tr><td>Коментар менеджера</td><td><?= nl2br(e($rma['client_message'])) ?></td></tr>
            <?php endif; ?>
        </table>
    </div>

    <?php if ($status === 'need_more_info'): ?>
        <div class="alert alert--warn">
            Для перевірки заявки потрібні додаткові фото товару або упаковки.
            Будь ласка, надішліть їх менеджеру.
        </div>
    <?php endif; ?>

    <?php
    // Реквізити потрібні, якщо обрано повернення коштів, а їх ще немає
    $needRefundDetails = $action === 'refund'
        && empty($rma['refund_iban'])
        && !in_array($status, ['refunded', 'closed', 'cancelled', 'rejected'], true);
    ?>
    <?php if ($needRefundDetails): ?>
        <div class="card">
            <h2 class="mt0">Реквізити для повернення коштів</h2>
            <p>Щоб ми повернули кошти, вкажіть, будь ласка, реквізити вашого <strong>рахунку</strong>.</p>

            <details class="help">
                <summary>Де взяти ці реквізити?</summary>
                <div class="help__body">
                    <ul>
                        <li><strong>IBAN</strong> — номер рахунку у форматі UA…</li>
                        <li><strong>ІПН / РНОКПП</strong> отримувача коштів</li>
                        <li><strong>ПІБ</strong> отримувача коштів</li>
                    </ul>
                    <p class="mb0">
                        Ці дані є у вашому банківському додатку в розділі «Реквізити картки»,
                        «Реквізити рахунку» або «Довідка з реквізитами».
                    </p>
                </div>
            </details>

            <form method="post" action="<?= e(url('/returns/refund-details')) ?>">
                <?= App\Csrf::field() ?>
                <div class="grid2">
                    <div class="field">
                        <label class="label" for="rd_name">ПІБ отримувача <span class="req">*</span></label>
                        <input class="input" type="text" id="rd_name" name="refund_name" value="<?= e(old('refund_name')) ?>">
                    </div>
                    <div class="field">
                        <label class="label" for="rd_tax">ІПН / РНОКПП <span class="req">*</span></label>
                        <input class="input" type="text" id="rd_tax" name="refund_tax_id" value="<?= e(old('refund_tax_id')) ?>" inputmode="numeric">
                    </div>
                </div>
                <div class="field">
                    <label class="label" for="rd_iban">IBAN <span class="req">*</span></label>
                    <input class="input mono" type="text" id="rd_iban" name="refund_iban"
                           value="<?= e(old('refund_iban')) ?>" placeholder="UA00 0000 0000 0000 0000 0000 00000" spellcheck="false">
                    <div class="hint">Це <strong>не номер картки</strong>, а рахунок у форматі UA…</div>
                </div>
                <div class="field">
                    <label class="label" for="rd_bank">Назва банку <span class="muted">(необовʼязково)</span></label>
                    <input class="input" type="text" id="rd_bank" name="refund_bank" value="<?= e(old('refund_bank')) ?>">
                </div>
                <button class="btn" type="submit">Надіслати реквізити</button>
            </form>
        </div>
    <?php endif; ?>

    <?php // ── ВІДПРАВЛЕННЯ ТОВАРУ ─────────────────────────────────── ?>

    <?php if ($isLight): ?>
        <?php // Клієнт оформив «Легке повернення» — ми його виявили ?>
        <div class="card">
            <div class="alert alert--success" style="margin-top:0">
                Ми бачимо, що ви оформили <strong>«Легке повернення»</strong> Нової пошти. Дякуємо!
            </div>
            <table class="kv" style="margin-bottom:0">
                <tr><td>Номер накладної (ТТН)</td><td class="mono"><strong><?= e($returnTtn) ?></strong></td></tr>
                <tr><td>Спосіб</td><td>«Легке повернення» Нової пошти</td></tr>
            </table>
            <p class="small muted mb0" style="margin-top:12px">
                Окрема оплата й додаткова накладна не потрібні. Щойно посилка прибуде до нас,
                ми повідомимо вас і продовжимо обробку заявки.
            </p>
        </div>

    <?php elseif ($isManual && ($approved || $inTransit)): ?>
        <?php // Клієнт відправив сам і вказав ТТН ?>
        <div class="card">
            <div class="alert alert--info" style="margin-top:0">
                Ви вказали номер відправлення — ми очікуємо вашу посилку.
            </div>
            <table class="kv" style="margin-bottom:12px">
                <tr><td>Номер відправлення (ТТН)</td><td class="mono"><strong><?= e($returnTtn) ?></strong></td></tr>
                <tr><td>Перевізник</td><td>Нова пошта</td></tr>
            </table>
            <details class="help">
                <summary>Помилились у номері?</summary>
                <div class="help__body">
                    <form method="post" action="<?= e(url('/returns/ttn')) ?>">
                        <?= App\Csrf::field() ?>
                        <input type="hidden" name="carrier" value="novaposhta">
                        <div class="field">
                            <label class="label" for="ttn">ТТН Нової пошти</label>
                            <input class="input mono" type="text" id="ttn" name="ttn" value="<?= e($returnTtn) ?>">
                        </div>
                        <button class="btn btn--ghost" type="submit">Оновити ТТН</button>
                    </form>
                </div>
            </details>
        </div>

    <?php elseif ($approved): ?>
        <?php // Погоджено. Або магазин оформив накладну (isOurNp), або клієнт ще має відправити ?>
        <div class="card">
            <h2 class="mt0">Ваше повернення погоджено — як відправити товар</h2>

            <?php if ($isOurNp): ?>
                <div class="alert alert--success" style="margin-top:0">
                    Ми вже оформили накладну Нової пошти — вам <strong>не потрібно</strong> нічого створювати чи вводити.
                    Просто здайте товар на будь-якому відділенні.
                </div>
                <table class="kv" style="margin-bottom:16px">
                    <tr><td>Номер накладної (ТТН)</td><td class="mono"><strong><?= e($returnTtn) ?></strong></td></tr>
                    <tr><td>Перевізник</td><td>Нова пошта</td></tr>
                </table>
            <?php endif; ?>

            <h3 class="mt0">Як правильно спакувати</h3>
            <ol>
                <li>
                    <strong>Покладіть товар в оригінальну упаковку.</strong>
                    Не клейте скотч, наклейки Нової пошти чи інші етикетки
                    <strong>безпосередньо на фабричну упаковку</strong> товару.
                </li>
                <li>
                    <strong>Додатково захистіть товар.</strong>
                    Використайте картонну коробку, папір або плівку, щоб товар не пошкодився в дорозі.
                </li>
                <li>
                    <strong>Перевірте комплектацію.</strong>
                    Усі деталі, пакети, кріплення, наклейки та пломби мають бути в посилці.
                </li>
                <li>
                    <strong>Зробіть фото перед відправленням.</strong>
                    Сфотографуйте товар, комплектацію і пакування — це захистить вас у разі
                    пошкодження під час доставки.
                </li>
            </ol>

            <?php if ($isOurNp): ?>
                <div class="notice">
                    Прийдіть на будь-яке відділення Нової пошти й назвіть номер накладної
                    <strong class="mono"><?= e($returnTtn) ?></strong> — оператор прийме та відправить посилку.
                </div>
            <?php else: ?>
                <h3>Оберіть зручний спосіб відправлення</h3>

                <div class="option">
                    <div class="option__title">1. «Легке повернення» Нової пошти <span class="badge badge--green">безкоштовно</span></div>
                    <p class="mb0">
                        Найпростіший спосіб, якщо замовлення привезла Нова пошта. Окрема накладна й оплата
                        не потрібні — доставку повернення оплачує Нова пошта (до 30&nbsp;кг, протягом 14 днів).
                        Оформити послугу можна в мобільному застосунку <strong>Nova Post</strong> або в
                        бізнес-кабінеті на сайті Нової пошти:
                    </p>
                    <ol>
                        <li>Увійдіть у розділ <strong>«Мої відправлення»</strong> та оберіть посилку, яку потрібно повернути.</li>
                        <li>У рядку <strong>«Керувати посилкою»</strong> оберіть послугу <strong>«Легке повернення»</strong>.</li>
                        <li>Із переліку оберіть причину повернення товару.</li>
                        <li>Відправте посилку за створеною накладною.</li>
                    </ol>
                    <p class="small muted mb0">
                        <?php if ($hasOriginalTtn): ?>
                            Щойно ви оформите «Легке повернення», ми <strong>автоматично</strong> побачимо його —
                            вводити ТТН вручну не потрібно.
                        <?php else: ?>
                            Після оформлення вкажіть, будь ласка, отриманий номер ТТН у формі нижче.
                        <?php endif; ?>
                    </p>
                </div>

                <div class="option">
                    <div class="option__title">2. Відправити самостійно Новою поштою</div>
                    <p>
                        Оформіть звичайну накладну <strong>Нової пошти</strong> на відділення нижче та внесіть
                        номер ТТН у формі — щоб ми очікували вашу посилку. Відправлення приймається лише Новою поштою.
                    </p>
                    <table class="kv" style="margin-bottom:12px">
                        <tr><td>Отримувач</td><td><strong>ФОП Шірінов Олег Ігорович</strong></td></tr>
                        <tr><td>Телефон</td><td class="mono">067 817 70 37</td></tr>
                        <tr><td>Адреса</td><td>Київська обл., Софіївська Борщагівка, відділення №3</td></tr>
                    </table>
                    <div class="notice" style="margin:0">
                        <strong>Доставку оплачує: <?= e($payerLabel) ?>.</strong>
                        <?php if ((string)$rma['shipping_payer'] === 'customer'): ?>
                            Оберіть відповідну опцію оплати при оформленні накладної.
                        <?php elseif ((string)$rma['shipping_payer'] === 'store'): ?>
                            Оформлюйте накладну з оплатою отримувачем — доставку сплатимо ми при отриманні.
                        <?php endif; ?>
                    </div>
                </div>

                <form method="post" action="<?= e(url('/returns/ttn')) ?>" style="margin-top:16px">
                    <?= App\Csrf::field() ?>
                    <input type="hidden" name="carrier" value="novaposhta">
                    <div class="field">
                        <label class="label" for="ttn">Номер ТТН Нової пошти</label>
                        <input class="input mono" type="text" id="ttn" name="ttn"
                               value="<?= e($returnTtn) ?>" placeholder="20450000000000">
                    </div>
                    <button class="btn" type="submit">Зберегти ТТН</button>
                </form>
            <?php endif; ?>

            <div class="notice" style="margin-top:16px">
                Якщо товар або упаковка будуть пошкоджені під час зворотної доставки через неналежне
                пакування, магазин має право відмовити у прийнятті повернення.
            </div>
            <p class="small muted mb0">
                <?php if ($isOurNp): ?>
                    Доставку оплачує: <strong><?= e($payerLabel) ?></strong>.
                    <?php if ((string)$rma['shipping_payer'] === 'customer'): ?>
                        Оплата — при здачі посилки на відділенні.
                    <?php endif; ?>
                <?php endif; ?>
                Якщо у вас є питання щодо відправки — зв’яжіться з менеджером.
            </p>
        </div>
    <?php endif; ?>

    <?php // ── СТАТУСНІ ПОВІДОМЛЕННЯ ───────────────────────────────── ?>
    <?php if ($status === 'refunded'): ?>
        <div class="alert alert--success">
            Кошти за заявкою <?= e($rma['rma_number']) ?> повернено. Термін зарахування залежить від банку.
        </div>
    <?php elseif ($status === 'received' || $status === 'inspection'): ?>
        <div class="alert alert--info">
            Ми отримали товар за заявкою <?= e($rma['rma_number']) ?>. Зараз він проходить перевірку.
        </div>
    <?php elseif ($inTransit && !$isLight && !$isManual): ?>
        <div class="alert alert--info">
            Ваша посилка в дорозі. Щойно вона прибуде до нас, ми повідомимо вас.
        </div>
    <?php elseif ($status === 'rejected'): ?>
        <div class="alert alert--error">
            На жаль, у поверненні відмовлено.
            <?= $rma['client_message'] ? e($rma['client_message']) : 'Деталі уточніть у менеджера.' ?>
        </div>
    <?php elseif (in_array($status, ['new', 'manager_review'], true)): ?>
        <div class="alert alert--info">
            Заявка очікує перевірки менеджером. <strong>Не відправляйте товар без погодження.</strong>
        </div>
    <?php endif; ?>

    <?php if ($comments !== []): ?>
        <div class="card">
            <h2 class="mt0">Повідомлення від менеджера</h2>
            <?php foreach ($comments as $c): ?>
                <div class="comment comment--client">
                    <div class="comment__head">
                        <span><?= e($c['author']) ?></span>
                        <span><?= dt((string)$c['created_at']) ?></span>
                    </div>
                    <div class="comment__text"><?= e($c['text']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="btn-row">
        <a class="btn btn--ghost" href="<?= e(url('/returns/status?new=1')) ?>">Перевірити іншу заявку</a>
        <a class="btn btn--ghost" href="<?= e(url('/returns')) ?>">На головну</a>
    </div>

<?php endif; ?>
