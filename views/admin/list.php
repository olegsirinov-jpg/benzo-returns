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
        <a class="btn btn--ghost btn--sm" href="<?= e(url('/admin/export' . $qs([]))) ?>">Експорт CSV</a>
        <a class="btn btn--ghost btn--sm" href="<?= e(url('/admin/stats')) ?>">Статистика</a>
    </div>
</div>

<?php
// скільки додаткових фільтрів активні (щоб підсвітити й розкрити)
$advKeys = ['status', 'reason', 'action', 'manager', 'supplier', 'from', 'to', 'manual', 'no_ttn'];
$advActive = 0;
foreach ($advKeys as $k) {
    if ($f($k) !== '') {
        $advActive++;
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

        <details class="fold filters-adv" <?= $advActive > 0 ? 'open' : '' ?>>
            <summary>
                Додаткові фільтри<?= $advActive > 0 ? ' <span class="badge badge--blue">' . $advActive . '</span>' : '' ?>
            </summary>
            <div class="filters" style="margin-top:12px">
                <div>
                    <label class="label" for="status">Статус</label>
                    <select class="select" id="status" name="status">
                        <option value="">Усі</option>
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
