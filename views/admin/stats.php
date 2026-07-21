<?php
/** @var string $from, $to */
/** @var int $total, $approved, $rejected, $exchanges, $stale */
/** @var float $refundSum, $avgHours */
/** @var array<int,array<string,mixed>> $topReasons, $topItems, $topSkus, $bySupplier, $byManager */
/** @var array<string,int> $byStatus */

$bar = function (int $value, int $max) {
    $pct = $max > 0 ? round($value / $max * 100) : 0;
    return '<div class="bar"><div class="bar__fill" style="width:' . $pct . '%"></div></div>';
};
?>

<div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
    <h1 class="mb0">Статистика повернень</h1>
    <a class="btn btn--ghost btn--sm" href="<?= e(url('/admin')) ?>">← До заявок</a>
</div>

<div class="card mt16">
    <form method="get" action="<?= e(url('/admin/stats')) ?>" class="filters">
        <div>
            <label class="label" for="from">Період з</label>
            <input class="input" type="date" id="from" name="from" value="<?= e($from) ?>">
        </div>
        <div>
            <label class="label" for="to">по</label>
            <input class="input" type="date" id="to" name="to" value="<?= e($to) ?>">
        </div>
        <div><button class="btn btn--block" type="submit">Показати</button></div>
        <div>
            <a class="btn btn--ghost btn--block" href="<?= e(url('/admin/export?from=' . $from . '&to=' . $to)) ?>">Експорт CSV</a>
        </div>
    </form>
</div>

<div class="stats-grid">
    <div class="stat">
        <div class="stat__value"><?= (int)$total ?></div>
        <div class="stat__label">Заявок за період</div>
    </div>
    <div class="stat">
        <div class="stat__value" style="color:var(--green)"><?= (int)$approved ?></div>
        <div class="stat__label">Погоджених повернень</div>
    </div>
    <div class="stat">
        <div class="stat__value" style="color:var(--red)"><?= (int)$rejected ?></div>
        <div class="stat__label">Відмов<?= $total > 0 ? ' · ' . round($rejected / $total * 100) . '%' : '' ?></div>
    </div>
    <div class="stat">
        <div class="stat__value"><?= (int)$exchanges ?></div>
        <div class="stat__label">Обмінів</div>
    </div>
    <div class="stat">
        <div class="stat__value"><?= number_format($refundSum, 0, ',', ' ') ?></div>
        <div class="stat__label">Сума повернень, грн</div>
    </div>
    <div class="stat">
        <div class="stat__value"><?= $avgHours > 0 ? round($avgHours, 1) : '—' ?></div>
        <div class="stat__label">Середній час до обробки, год</div>
    </div>
    <div class="stat">
        <div class="stat__value" style="color:<?= $stale > 0 ? 'var(--amber)' : 'inherit' ?>"><?= (int)$stale ?></div>
        <div class="stat__label">Зависли без руху &gt; 48 год</div>
    </div>
</div>

<div class="grid2">
    <div class="card">
        <div class="card__title">Топ причин повернень</div>
        <?php if ($topReasons === []): ?><p class="muted small mb0">Немає даних.</p><?php else: ?>
            <?php $max = (int)$topReasons[0]['c']; ?>
            <?php foreach ($topReasons as $r): ?>
                <div style="margin-bottom:11px">
                    <div style="display:flex;justify-content:space-between;font-size:13.5px">
                        <span><?= e(App\Dict::reason((string)$r['reason_code'])) ?></span>
                        <strong><?= (int)$r['c'] ?><?= $total > 0 ? ' <span class="muted" style="font-weight:400">· ' . round((int)$r['c'] / $total * 100) . '%</span>' : '' ?></strong>
                    </div>
                    <?= $bar((int)$r['c'], $max) ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card__title">Повернення по постачальниках</div>
        <?php if ($bySupplier === []): ?><p class="muted small mb0">Немає даних.</p><?php else: ?>
            <?php $max = (int)$bySupplier[0]['c']; ?>
            <?php foreach ($bySupplier as $s): ?>
                <div style="margin-bottom:11px">
                    <div style="display:flex;justify-content:space-between;font-size:13.5px">
                        <span><?= e(App\Supplier::name((string)$s['supplier'])) ?></span>
                        <strong><?= (int)$s['c'] ?></strong>
                    </div>
                    <?= $bar((int)$s['c'], $max) ?>
                </div>
            <?php endforeach; ?>
            <p class="hint mb0">Постачальник визначається автоматично за артикулом.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card__title">Топ товарів, які повертають</div>
        <?php if ($topItems === []): ?><p class="muted small mb0">Немає даних.</p><?php else: ?>
            <table class="table">
                <tbody>
                <?php foreach ($topItems as $i): ?>
                    <tr><td><?= e(str_limit((string)$i['name'], 48)) ?></td><td style="text-align:right"><strong><?= (int)$i['c'] ?></strong></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card__title">Топ артикулів</div>
        <?php if ($topSkus === []): ?><p class="muted small mb0">Немає даних.</p><?php else: ?>
            <table class="table">
                <tbody>
                <?php foreach ($topSkus as $i): ?>
                    <tr>
                        <td class="mono"><?= e($i['sku']) ?></td>
                        <td class="small muted"><?= e(str_limit((string)$i['name'], 30)) ?></td>
                        <td style="text-align:right"><strong><?= (int)$i['c'] ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card__title">Повернення по менеджерах</div>
        <?php if ($byManager === []): ?><p class="muted small mb0">Немає даних.</p><?php else: ?>
            <table class="table">
                <tbody>
                <?php foreach ($byManager as $m): ?>
                    <tr><td><?= e($m['name']) ?></td><td style="text-align:right"><strong><?= (int)$m['c'] ?></strong></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card__title">Розподіл за статусами</div>
        <?php if ($byStatus === []): ?><p class="muted small mb0">Немає даних.</p><?php else: ?>
            <table class="table">
                <tbody>
                <?php arsort($byStatus); ?>
                <?php foreach ($byStatus as $code => $c): ?>
                    <tr>
                        <td><span class="badge badge--<?= e(App\Dict::statusColor((string)$code)) ?>"><?= e(App\Dict::status((string)$code)) ?></span></td>
                        <td style="text-align:right"><strong><?= (int)$c ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
