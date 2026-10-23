<?php
/** @var array<int,array<string,mixed>> $rows */
/** @var array<string,mixed> $filters */
/** @var array<int,array<string,mixed>> $managers */
/** @var array<string,int> $counts */
/** @var int $total, $page, $pages */
$f = function (string $key, string $default = '') use ($filters) {
    return (string)($filters[$key] ?? $default);
};
$qs = function (array $extra) use ($filters) {
    return '?' . http_build_query(array_merge($filters, $extra));
};
$newCount = ($counts['new'] ?? 0);
?>

<div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
    <h1 class="mb0">
        Заявки на повернення
        <span class="muted" style="font-weight:400;font-size:16px">— <?= (int)$total ?></span>
        <?php if ($newCount > 0): ?>
            <span class="badge badge--blue"><?= (int)$newCount ?> нових</span>
        <?php endif; ?>
    </h1>
    <div class="btn-row">
        <a class="btn btn--sm" href="<?= e(url('/admin/rma-new')) ?>">+ Нова заявка</a>
        <?php $arrivedCount = (int)($counts['arrived'] ?? 0); ?>
        <form method="post" action="<?= e(url('/admin/pickup-list')) ?>" style="display:inline"
              title="Надіслати менеджеру в Telegram ТТН посилок, що прибули у відділення">
            <?= App\Csrf::field() ?>
            <button class="btn btn--sm <?= $arrivedCount ? '' : 'btn--ghost' ?>" type="submit">
                📥 До отримання<?= $arrivedCount ? ' (' . $arrivedCount . ')' : '' ?>
            </button>
        </form>
        <a class="btn btn--ghost btn--sm" href="<?= e(url('/admin/export' . $qs([]))) ?>">Експорт CSV</a>
        <a class="btn btn--ghost btn--sm" href="<?= e(url('/admin/stats')) ?>">Статистика</a>
    </div>
</div>

<?php
// ---- Чіпи швидких фільтрів (один клік) ----
$chipCounts = $chipCounts ?? ['problem' => 0, 'manual' => 0];
$curStatus  = $f('status');
$curFlag    = $f('flag');
$curManual  = $f('manual');
$noOther    = $f('q') === '' && $curFlag === '' && $curManual === '';
$chips = [
    ['label' => 'Нові',           'q' => ['status' => 'new'],            'n' => (int)($counts['new'] ?? 0),            'color' => 'blue',   'active' => $curStatus === 'new' && $noOther],
    ['label' => 'Погоджено',      'q' => ['status' => 'approved'],       'n' => (int)($counts['approved'] ?? 0),       'color' => 'green',  'active' => $curStatus === 'approved' && $noOther],
    ['label' => 'У дорозі',       'q' => ['status' => 'in_transit'],     'n' => (int)($counts['in_transit'] ?? 0),     'color' => 'violet', 'active' => $curStatus === 'in_transit' && $noOther],
    ['label' => 'До отримання',   'q' => ['status' => 'arrived'],        'n' => (int)($counts['arrived'] ?? 0),        'color' => 'amber',  'active' => $curStatus === 'arrived' && $noOther],
    ['label' => 'Отримано',       'q' => ['status' => 'received'],       'n' => (int)($counts['received'] ?? 0),       'color' => 'violet', 'active' => $curStatus === 'received' && $noOther],
    ['label' => 'На перевірці',   'q' => ['status' => 'inspection'],     'n' => (int)($counts['inspection'] ?? 0),     'color' => 'violet', 'active' => $curStatus === 'inspection' && $noOther],
    ['label' => 'До виплати',     'q' => ['status' => 'refund_pending'], 'n' => (int)($counts['refund_pending'] ?? 0), 'color' => 'amber',  'active' => $curStatus === 'refund_pending' && $noOther],
    ['label' => '⚠ Проблеми',     'q' => ['flag' => 'problem'],          'n' => (int)$chipCounts['problem'],           'color' => 'red',    'active' => $curFlag === 'problem' && $curStatus === '' && $f('q') === ''],
    ['label' => 'Ручна перевірка','q' => ['manual' => '1'],              'n' => (int)$chipCounts['manual'],            'color' => 'amber',  'active' => $curManual !== '' && $curStatus === '' && $f('q') === '' && $curFlag === ''],
];
$allActive = $curStatus === '' && $noOther
    && $f('reason') === '' && $f('action') === '' && $f('manager') === '' && $f('supplier') === ''
    && $f('from') === '' && $f('to') === '' && $f('no_ttn') === '';
?>
<div class="chips">
    <a class="chip<?= $allActive ? ' is-active' : '' ?>" href="<?= e(url('/admin')) ?>">Усі активні</a>
    <?php foreach ($chips as $c): ?>
        <a class="chip chip--<?= e($c['color']) ?><?= $c['active'] ? ' is-active' : '' ?><?= $c['n'] === 0 ? ' chip--zero' : '' ?>"
           href="<?= e(url('/admin?' . http_build_query($c['q']))) ?>">
            <?= e($c['label']) ?> <span class="chip__n"><?= (int)$c['n'] ?></span>
        </a>
    <?php endforeach; ?>
</div>

<!-- ---- Надіслати клієнту персональне посилання на повернення ---- -->
<details class="fold card" style="padding:12px 16px">
    <summary style="cursor:pointer;font-weight:600">📨 Надіслати клієнту посилання на повернення</summary>
    <form method="post" action="<?= e(url('/admin/send-link')) ?>" id="send-link-form"
          data-base="<?= e(url('/returns/new')) ?>" style="margin-top:12px">
        <?= App\Csrf::field() ?>
        <p class="small muted" style="margin-top:0">
            Клієнт отримає SMS/Viber із посиланням, де номер замовлення й телефон уже вказані —
            замовлення підтягнеться одразу, помилитися цифрою неможливо.
        </p>
        <div class="grid2">
            <div class="field mb0">
                <label class="label" for="sl-order">Номер замовлення</label>
                <input class="input mono" type="text" id="sl-order" name="order_number" inputmode="numeric" autocomplete="off" required>
            </div>
            <div class="field mb0">
                <label class="label" for="sl-phone">Телефон клієнта</label>
                <input class="input" type="tel" id="sl-phone" name="phone" placeholder="067 123 45 67" autocomplete="off" required>
            </div>
        </div>
        <div class="btn-row mt16" style="align-items:center">
            <button class="btn btn--sm" type="submit">Надіслати SMS / Viber</button>
            <button class="btn btn--sm btn--ghost" type="button" id="sl-copy">Скопіювати посилання</button>
            <span class="small muted" id="sl-copied"></span>
        </div>
    </form>
</details>
<script>
(function () {
    var form = document.getElementById('send-link-form');
    if (!form) { return; }
    var btn = document.getElementById('sl-copy');
    var out = document.getElementById('sl-copied');
    btn.addEventListener('click', function () {
        var order = document.getElementById('sl-order').value.trim();
        var phone = document.getElementById('sl-phone').value.replace(/\D+/g, '');
        if (!order) { out.textContent = 'Вкажіть номер замовлення.'; return; }
        var link = form.getAttribute('data-base') + '?order=' + encodeURIComponent(order)
                 + (phone ? '&phone=' + encodeURIComponent(phone) : '');
        function done() { out.textContent = 'Скопійовано: ' + link; }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(link).then(done, function () { window.prompt('Скопіюйте посилання:', link); });
        } else {
            window.prompt('Скопіюйте посилання:', link);
        }
    });
})();
</script>

<?php
// скільки додаткових фільтрів активні (щоб підсвітити й розкрити)
$advKeys = ['status', 'reason', 'action', 'manager', 'supplier', 'from', 'to', 'manual', 'no_ttn'];
$advActive = 0;
$advOpen = false;
foreach ($advKeys as $k) {
    if ($f($k) !== '') {
        $advActive++;
        // статус і «ручна перевірка» обираються чіпами — панель через них не розгортаємо
        if ($k !== 'status' && $k !== 'manual') {
            $advOpen = true;
        }
    }
}
?>
<div class="card mt16">
    <form method="get" action="<?= e(url('/admin')) ?>">
        <!-- завжди видимий рядок: пошук + застосувати -->
        <div class="filters-top">
            <input class="input" type="text" name="q" value="<?= e($f('q')) ?>"
                   placeholder="Пошук: заявка / замовлення / телефон / артикул / ТТН / ПІБ">
            <button class="btn" type="submit">Застосувати</button>
            <?php if ($f('q') !== '' || $advActive > 0): ?>
                <a class="btn btn--ghost" href="<?= e(url('/admin')) ?>">Скинути</a>
            <?php endif; ?>
        </div>

        <details class="fold filters-adv" <?= $advOpen ? 'open' : '' ?>>
            <summary>
                Додаткові фільтри<?= $advActive > 0 ? ' <span class="badge badge--blue">' . $advActive . '</span>' : '' ?>
            </summary>
            <div class="filters" style="margin-top:12px">
                <div>
                    <label class="label" for="status">Статус</label>
                    <select class="select" id="status" name="status">
                        <option value="">Усі, крім закритих</option>
                        <option value="open" <?= $f('status') === 'open' ? 'selected' : '' ?>>— Тільки в роботі —</option>
                        <?php foreach (App\Dict::statuses() as $code => $label): ?>
                            <option value="<?= e($code) ?>" <?= $f('status') === $code ? 'selected' : '' ?>>
                                <?= e($label) ?><?= isset($counts[$code]) ? ' (' . (int)$counts[$code] . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="label" for="reason">Причина</label>
                    <select class="select" id="reason" name="reason">
                        <option value="">Усі</option>
                        <?php foreach (App\Dict::reasons() as $code => $label): ?>
                            <option value="<?= e($code) ?>" <?= $f('reason') === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="label" for="action">Бажана дія</label>
                    <select class="select" id="action" name="action">
                        <option value="">Усі</option>
                        <?php foreach (App\Dict::actions() as $code => $label): ?>
                            <option value="<?= e($code) ?>" <?= $f('action') === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="label" for="manager">Менеджер</label>
                    <select class="select" id="manager" name="manager">
                        <option value="">Усі</option>
                        <option value="none" <?= $f('manager') === 'none' ? 'selected' : '' ?>>Без менеджера</option>
                        <?php foreach ($managers as $m): ?>
                            <option value="<?= (int)$m['id'] ?>" <?= $f('manager') === (string)$m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="label" for="supplier">Постачальник</label>
                    <select class="select" id="supplier" name="supplier">
                        <option value="">Усі</option>
                        <?php foreach (App\Supplier::all() as $code => $label): ?>
                            <option value="<?= e($code) ?>" <?= $f('supplier') === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="label" for="from">Дата з</label>
                    <input class="input" type="date" id="from" name="from" value="<?= e($f('from')) ?>">
                </div>
                <div>
                    <label class="label" for="to">Дата по</label>
                    <input class="input" type="date" id="to" name="to" value="<?= e($f('to')) ?>">
                </div>
            </div>

            <div style="display:flex;gap:18px;margin-top:12px;flex-wrap:wrap">
                <label class="check mb0">
                    <input type="checkbox" name="manual" value="1" <?= $f('manual') ? 'checked' : '' ?>>
                    <span>Тільки ті, що потребують ручної перевірки</span>
                </label>
                <label class="check mb0">
                    <input type="checkbox" name="no_ttn" value="1" <?= $f('no_ttn') ? 'checked' : '' ?>>
                    <span>Без ТТН</span>
                </label>
            </div>
        </details>
    </form>
</div>

<?php if ($rows === []): ?>
    <div class="card center muted">Заявок не знайдено.</div>
<?php else: ?>
<div class="table-scroll">
    <table class="table">
        <thead>
            <tr>
                <th>Заявка</th>
                <th>Створено</th>
                <th>Замовлення</th>
                <th>Клієнт</th>
                <th>Товар / артикул</th>
                <th>Причина</th>
                <th>Дія</th>
                <th>Статус</th>
                <th>Менеджер</th>
                <th>ТТН</th>
                <th>Сума</th>
                <th>Змінено</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="nowrap">
                    <a class="mono" href="<?= e(url('/admin/rma/' . $r['id'])) ?>"><?= e($r['rma_number']) ?></a>
                    <?php if (!empty($r['needs_manual_check'])): ?>
                        <div><span class="badge badge--amber" title="Замовлення не знайдено в SalesDrive">ручна перевірка</span></div>
                    <?php endif; ?>
                    <?php if (!empty($r['inspection_issue'])): ?>
                        <div><span class="badge badge--red" title="<?= e((string)$r['inspection_note']) ?>">🛠 проблема огляду</span></div>
                    <?php endif; ?>
                </td>
                <td class="nowrap small"><?= dt((string)$r['created_at'], 'd.m.Y H:i') ?></td>
                <td class="nowrap">№<?= e($r['order_number']) ?></td>
                <td>
                    <div><?= e($r['customer_name'] ?: '—') ?></div>
                    <div class="small muted nowrap"><?= e(App\Validate::phoneFormat((string)$r['phone'])) ?></div>
                </td>
                <td>
                    <div><?= e(str_limit((string)$r['items_names'], 42)) ?></div>
                    <div class="small muted mono"><?= e(str_limit((string)$r['items_skus'], 30)) ?></div>
                </td>
                <td class="small"><?= e(App\Dict::reason((string)$r['reason_code'])) ?></td>
                <td class="small"><?= e(App\Dict::action((string)$r['desired_action'])) ?></td>
                <td>
                    <span class="badge badge--<?= e(App\Dict::statusColor((string)$r['status'])) ?>">
                        <?= e(App\Dict::status((string)$r['status'])) ?>
                    </span>
                </td>
                <td class="small"><?= e($r['manager_name'] ?: '—') ?></td>
                <td class="small mono">
                    <?= e($r['return_ttn'] ?: '—') ?>
                    <?php if (!empty($r['np_cost_alert'])): ?>
                        <span class="badge badge--red" title="<?= e((string)$r['np_cost_note']) ?>">💸 оплата</span>
                    <?php endif; ?>
                    <?php if (!empty($r['np_track_status'])): ?>
                        <div class="small muted" style="font-family:inherit;white-space:normal;max-width:220px"><?= e((string)$r['np_track_status']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="small nowrap"><?= $r['refund_amount'] !== null ? money($r['refund_amount']) : money($r['total_amount']) ?></td>
                <td class="small muted nowrap"><?= dt((string)$r['updated_at'], 'd.m H:i') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($pages > 1): ?>
<div class="pagination">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
        <?php if ($i === $page): ?>
            <span class="is-active"><?= $i ?></span>
        <?php elseif ($i <= 2 || $i >= $pages - 1 || abs($i - $page) <= 2): ?>
            <a href="<?= e(url('/admin' . $qs(['page' => $i]))) ?>"><?= $i ?></a>
        <?php elseif (abs($i - $page) === 3): ?>
            <span>…</span>
        <?php endif; ?>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php endif; ?>
