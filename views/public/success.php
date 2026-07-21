<?php /** @var string $number */ ?>

<div class="card center" style="max-width:620px;margin:0 auto">
    <div style="font-size:44px;line-height:1">✅</div>
    <h1 style="margin:12px 0 6px">Заявку прийнято</h1>

    <p class="lead" style="margin:0 0 18px">
        Номер заявки: <strong class="mono"><?= e($number) ?></strong>
    </p>

    <p>Менеджер перевірить інформацію та зв’яжеться з вами.</p>

    <div class="notice" style="text-align:left">
        <strong>Будь ласка, не відправляйте товар без погодження заявки.</strong><br>
        Посилки, відправлені без погодження, можуть бути не прийняті в роботу.
    </div>

    <div class="btn-row" style="justify-content:center">
        <a class="btn" href="<?= e(url('/returns/status')) ?>">Переглянути статус заявки</a>
        <a class="btn btn--ghost" href="<?= e(url('/returns')) ?>">На головну</a>
    </div>

    <p class="hint mt16">Збережіть номер заявки — він знадобиться для перевірки статусу.</p>
</div>
