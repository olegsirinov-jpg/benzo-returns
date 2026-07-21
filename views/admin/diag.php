<?php
/** @var array<string,mixed>|null $result */
/** @var array<string,mixed>|null $raw */
/** @var array<string,mixed>|null $mapped */
/** @var array<int,array<string,mixed>>|null $probes */

$mask = function (string $s): string {
    $len = strlen($s);
    if ($len === 0) {
        return '(порожньо)';
    }
    return $len <= 12 ? str_repeat('•', $len) : substr($s, 0, 6) . str_repeat('•', 10) . substr($s, -4) . ' (' . $len . ' симв.)';
};

$url      = App\Env::str('SD_URL');
$enabled  = App\Env::bool('SD_ENABLED', false);
$apiKey   = App\Env::str('SD_API_KEY');
$formKey  = App\Env::str('SD_FORM_KEY');
$isPlaceholder = strpos($url, 'yourdomain') !== false;
?>

<div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
    <h1 class="mb0">Діагностика SalesDrive</h1>
    <a class="btn btn--ghost btn--sm" href="<?= e(url('/admin')) ?>">← До заявок</a>
</div>

<div class="card mt16">
    <div class="card__title">Поточні налаштування (.env)</div>
    <table class="kv">
        <tr>
            <td>SD_ENABLED</td>
            <td>
                <?php if ($enabled): ?>
                    <span class="badge badge--green">увімкнено</span>
                <?php else: ?>
                    <span class="badge badge--red">вимкнено</span>
                    — поки тут <code>0</code>, пошук замовлення завжди повертає «не знайдено»
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <td>SD_URL</td>
            <td>
                <code><?= e($url) ?></code>
                <?php if ($isPlaceholder): ?>
                    <span class="badge badge--red">плейсхолдер</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr><td>SD_API_KEY</td><td class="mono small"><?= e($mask($apiKey)) ?></td></tr>
        <tr>
            <td>SD_FORM_KEY</td>
            <td class="mono small">
                <?= e($mask($formKey)) ?>
                <?php if ($formKey !== '' && $formKey === $apiKey): ?>
                    <br><span class="badge badge--amber">збігається з API-ключем</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <td>SD_ORDER_FIELDS</td>
            <td class="mono small">
                <?= e(App\Env::str('SD_ORDER_FIELDS', 'externalId,id')) ?>
                <div class="hint" style="margin-top:4px">
                    Поля, у яких шукається номер. Якщо номер із Хорошопа лежить
                    у кастомному полі — допишіть його сюди через кому.
                </div>
            </td>
        </tr>
    </table>
</div>

<?php if (!$enabled || $isPlaceholder): ?>
<div class="alert alert--warn">
    <strong>Спочатку виправте <code>.env</code>:</strong><br>
    <?php if (!$enabled): ?>• <code>SD_ENABLED=1</code><br><?php endif; ?>
    <?php if ($isPlaceholder): ?>• <code>SD_URL=https://<u>вашпіддомен</u>.salesdrive.me</code> — той самий домен, під яким ви заходите в CRM<br><?php endif; ?>
    Після зміни .env оновіть цю сторінку.
</div>
<?php endif; ?>

<div class="grid2">
    <div class="card">
        <div class="card__title">1. Перевірка звʼязку</div>
        <p class="small muted">Запросить одну останню заявку за 30 днів. Перевіряє домен і API-ключ.</p>
        <form method="post" action="<?= e(url('/admin/diag')) ?>" style="display:inline">
            <?= App\Csrf::field() ?>
            <input type="hidden" name="mode" value="ping">
            <button class="btn" type="submit">Перевірити звʼязок</button>
        </form>
        <form method="post" action="<?= e(url('/admin/diag')) ?>" style="display:inline">
            <?= App\Csrf::field() ?>
            <input type="hidden" name="mode" value="clear">
            <button class="btn btn--ghost" type="submit">Очистити кеш пошуку</button>
        </form>
        <div class="hint">Результати пошуку кешуються на 15 хв — після зміни .env скиньте кеш.</div>
    </div>

    <div class="card">
        <div class="card__title">2. Підбір робочого фільтра</div>
        <p class="small muted">
            Введіть <strong>номер SalesDrive</strong> (той, що НЕ підтягується) —
            перебере 7 варіантів запиту й покаже, який із них працює.
        </p>
        <p class="small muted">
            Перший рядок — «канарка»: запит із неіснуючим полем. Якщо він поверне рядки,
            значить SalesDrive ігнорує невідомі фільтри, і решту таблиці треба читати з поправкою на це.
        </p>
        <form method="post" action="<?= e(url('/admin/diag')) ?>">
            <?= App\Csrf::field() ?>
            <input type="hidden" name="mode" value="probe">
            <div class="field">
                <label class="label" for="order_number">Номер замовлення</label>
                <input class="input" type="text" id="order_number" name="order_number"
                       value="<?= e((string)($_POST['order_number'] ?? '')) ?>" placeholder="283730">
            </div>
            <div class="field">
                <label class="label" for="probe_phone">Телефон <span class="muted">(необовʼязково)</span></label>
                <input class="input" type="text" id="probe_phone" name="phone"
                       value="<?= e((string)($_POST['phone'] ?? '')) ?>" placeholder="0976377676">
                <div class="hint">
                    Якщо заповнити — перевірятиму <strong>пошук за телефоном</strong> замість пошуку за номером.
                    Це найцінніший тест: не залежить від назви поля з номером.
                </div>
            </div>
            <button class="btn" type="submit">Перебрати фільтри</button>
            <div class="hint">Витратить 7 із 10 запитів на хвилину. Двічі поспіль запускати не можна — впреться в ліміт.</div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card__title">3. Перевірити мапінг на готовому JSON</div>
    <p class="small muted">
        Вставте сюди відповідь <code>order/list</code> (або одну заявку) — покаже, як система розпізнає поля.
        Працює без запитів до API, ліміт не витрачається.
    </p>
    <form method="post" action="<?= e(url('/admin/diag')) ?>">
        <?= App\Csrf::field() ?>
        <input type="hidden" name="mode" value="mapjson">
        <div class="field">
            <textarea class="textarea mono small" name="json" rows="6"
                      placeholder='{"status":"success","data":[{ ... }]}'><?= e((string)($_POST['json'] ?? '')) ?></textarea>
        </div>
        <button class="btn btn--ghost" type="submit">Розібрати JSON</button>
    </form>
</div>

<?php if ($probes !== null): ?>
    <?php
    $anyOk      = false;
    $anyMatched = false;
    foreach ($probes as $p) {
        if ($p['code'] >= 200 && $p['code'] < 300) { $anyOk = true; }
        if ($p['matched']) { $anyMatched = true; }
    }
    ?>

    <div class="card">
        <div class="card__title">Результат перебору</div>
        <div class="table-scroll" style="border:none">
            <table class="table">
                <thead>
                    <tr><th>Варіант запиту</th><th>HTTP</th><th>Повернуто</th><th>Наш номер</th><th>Примітка</th></tr>
                </thead>
                <tbody>
                <?php foreach ($probes as $p): ?>
                    <tr>
                        <td><?= e($p['name']) ?></td>
                        <td>
                            <?php $c = (int)$p['code']; ?>
                            <span class="badge badge--<?= $c >= 200 && $c < 300 ? 'green' : 'red' ?>"><?= $c ?: '—' ?></span>
                        </td>
                        <td><?= (int)$p['count'] ?></td>
                        <td>
                            <?php if ($p['matched']): ?>
                                <span class="badge badge--green">знайдено ✓</span>
                            <?php else: ?>
                                <span class="badge badge--gray">ні</span>
                            <?php endif; ?>
                        </td>
                        <td class="small muted"><?= e($p['note']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php
        // компактний текстовий звіт — щоб можна було скопіювати одним рухом
        $report  = "=== ДІАГНОСТИКА SALESDRIVE ===\n";
        $report .= 'Запит: ' . (string)($_POST['order_number'] ?? '') . ' / ' . (string)($_POST['phone'] ?? '') . "\n";
        $report .= str_repeat('-', 60) . "\n";
        foreach ($probes as $p) {
            $report .= sprintf(
                "%-52s HTTP=%-4s рядків=%-4s наш=%s\n",
                mb_substr(preg_replace('/^🐤 /u', '', $p['name']), 0, 52),
                $p['code'],
                $p['count'],
                $p['matched'] ? 'ТАК' : 'ні'
            );
            $report .= '    filter: ' . json_encode($p['filter'], JSON_UNESCAPED_UNICODE) . "\n";
            if ($p['note'] !== '') {
                $report .= '    -> ' . preg_replace('/[⚠️✔]/u', '', $p['note']) . "\n";
            }
        }
        ?>
        <h3>Скопіюйте це і надішліть мені</h3>
        <textarea class="textarea mono small" rows="12" readonly onclick="this.select()"
                  style="background:#fafbfc"><?= e($report) ?></textarea>
        <p class="hint">Клікніть у поле — виділиться все. Персональних даних тут немає.</p>

        <?php if (!$anyOk): ?>
            <div class="alert alert--error mt16" style="margin-bottom:0">
                Жоден запит не пройшов — проблема в домені або ключі, а не у фільтрах.
                Дивіться колонку «Примітка».
            </div>
        <?php elseif (!$anyMatched): ?>
            <div class="alert alert--warn mt16" style="margin-bottom:0">
                Звʼязок є, але замовлення <strong><?= e((string)($_POST['order_number'] ?? '')) ?></strong> не знайдено в жодному варіанті.
                Найімовірніше, номер зберігається в іншому полі. Подивіться сирі дані нижче:
                знайдіть у них ваш номер і скажіть мені, у якому він полі — або надішліть цей JSON.
            </div>
        <?php else: ?>
            <div class="alert alert--success mt16" style="margin-bottom:0">
                Замовлення знайдено. Пошук у формі клієнта тепер має працювати —
                перевірте на <a href="<?= e(url('/returns/new')) ?>" target="_blank">/returns/new</a>.
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($result !== null): ?>

    <div class="card">
        <div class="card__title">Відповідь сервера</div>
        <table class="kv">
            <tr><td>HTTP-код</td><td>
                <?php $c = (int)$result['code']; ?>
                <span class="badge badge--<?= $c >= 200 && $c < 300 ? 'green' : 'red' ?>"><?= $c ?: 'немає відповіді' ?></span>
                <?php if ($c === 401 || $c === 403): ?>
                    — ключ невірний або без прав на читання заявок
                <?php elseif ($c === 404): ?>
                    — домен неправильний
                <?php elseif ($c === 429): ?>
                    — перевищено ліміт (10 запитів/хв)
                <?php endif; ?>
            </td></tr>
            <?php if ($result['error'] !== ''): ?>
                <tr><td>Помилка мережі</td><td class="small" style="color:var(--red)"><?= e($result['error']) ?></td></tr>
            <?php endif; ?>
            <tr><td>Запит</td><td class="small mono" style="word-break:break-all"><?= e(preg_replace('/Form-Api-Key[^&]*/', '', (string)$result['url'])) ?></td></tr>
            <tr><td>Знайдено заявок</td><td>
                <?= isset($result['data']['data']) && is_array($result['data']['data']) ? count($result['data']['data']) : 0 ?>
            </td></tr>
        </table>

        <?php if ($result['body'] !== '' && $raw === null): ?>
            <h3>Тіло відповіді</h3>
            <pre class="small mono" style="background:#fafbfc;border:1px solid var(--line);border-radius:8px;padding:12px;overflow:auto;max-height:280px"><?= e(mb_substr((string)$result['body'], 0, 3000)) ?></pre>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php if ($raw !== null): ?>

        <div class="card">
            <div class="card__title">Як система розпізнала поля</div>
            <p class="small muted">
                Назви полів у SalesDrive залежать від налаштувань вашої бази заявок.
                Якщо тут щось «—» або порожнє — надішліть мені цю таблицю і сирі дані нижче, я поправлю мапінг.
            </p>
            <table class="kv">
                <tr><td>ID у SalesDrive</td><td class="mono"><?= e((string)$mapped['sd_id']) ?: '<span style="color:var(--red)">—</span>' ?></td></tr>
                <tr><td>Номер замовлення</td><td class="mono"><?= e((string)$mapped['order_number']) ?: '<span style="color:var(--red)">—</span>' ?></td></tr>
                <tr><td>Дата</td><td><?= e((string)$mapped['order_date']) ?: '<span style="color:var(--red)">—</span>' ?></td></tr>
                <tr><td>ПІБ клієнта</td><td><?= e((string)$mapped['customer_name']) ?: '<span style="color:var(--red)">—</span>' ?></td></tr>
                <tr><td>Телефон</td><td class="mono"><?= e((string)$mapped['phone']) ?: '<span style="color:var(--red)">—</span>' ?></td></tr>
                <tr><td>Email</td><td><?= e((string)$mapped['email']) ?: '—' ?></td></tr>
                <tr><td>Сума</td><td><?= e((string)$mapped['total']) ?></td></tr>
                <tr><td>Товарів розпізнано</td><td>
                    <?php $n = count($mapped['items']); ?>
                    <span class="badge badge--<?= $n > 0 ? 'green' : 'red' ?>"><?= $n ?></span>
                </td></tr>
            </table>

            <?php if ($mapped['items'] !== []): ?>
                <h3>Товари</h3>
                <table class="table">
                    <thead><tr><th>Назва</th><th>Артикул</th><th>К-сть</th><th>Ціна</th></tr></thead>
                    <tbody>
                    <?php foreach ($mapped['items'] as $it): ?>
                        <tr>
                            <td><?= e($it['name']) ?></td>
                            <td class="mono"><?= e($it['sku']) ?: '<span style="color:var(--red)">—</span>' ?></td>
                            <td><?= (int)$it['qty'] ?></td>
                            <td><?= e((string)$it['price']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card__title">Сирі дані заявки з SalesDrive</div>
            <p class="small muted">Список полів верхнього рівня: <code><?= e(implode(', ', array_keys($raw))) ?></code></p>
            <pre class="small mono" style="background:#fafbfc;border:1px solid var(--line);border-radius:8px;padding:12px;overflow:auto;max-height:420px"><?= e(json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
        </div>

<?php endif; ?>

<div class="card">
    <div class="card__title">Де взяти SD_FORM_KEY</div>
    <p class="small mb0">
        Це <strong>не</strong> API-ключ. Ключ бази заявок лежить в SalesDrive:
        <em>Установки → Загальні налаштування і інтеграції → Інші сервіси → API</em> — у блоці конкретної
        <strong>бази заявок</strong> (форми). Він потрібен лише для того, щоб дописувати коментар до замовлення
        через <code>api/order/update/</code>. Пошук замовлень працює й без нього.
    </p>
</div>
