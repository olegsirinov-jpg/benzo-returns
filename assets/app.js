/* Система обміну та повернення — фронтенд форми заявки */
(function () {
  'use strict';

  var form = document.getElementById('rma-form');
  if (!form) { return; }

  var $  = function (s, ctx) { return (ctx || document).querySelector(s); };
  var $$ = function (s, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(s)); };

  var token = $('input[name="_token"]').value;

  // ---------------------------------------------------------------- крок 1
  var lookupBtn   = $('#lookup-btn');
  var skipBtn     = $('#skip-lookup');
  var resultBox   = $('#lookup-result');
  var rest        = $('#rest');
  var orderItems  = $('#order-items');
  var manualItems = $('#manual-items');
  var manualRows  = $('#manual-rows');

  function alertBox(type, html) {
    return '<div class="alert alert--' + type + '">' + html + '</div>';
  }

  function normalizePhone(raw) {
    var d = String(raw).replace(/\D+/g, '');
    if (d.indexOf('00380') === 0) { d = d.slice(2); }
    if (d.length === 12 && d.indexOf('380') === 0) { return d; }
    if (d.length === 10 && d[0] === '0') { return '38' + d; }
    if (d.length === 9) { return '380' + d; }
    return null;
  }

  function openRest() {
    rest.classList.remove('hidden');
    rest.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  // --- автопідставлені поля ---
  // Значення, взяті із замовлення, помічаємо data-auto. Це дозволяє прибрати
  // їх при новому пошуку й не тягти дані попереднього замовлення у форму.
  // Те, що клієнт ввів руками, не чіпаємо ніколи.

  function setAuto(sel, value) {
    var el = $(sel);
    if (!el || !value) { return; }
    if (el.value && !el.dataset.auto) { return; }  // введене клієнтом має пріоритет
    el.value = value;
    el.dataset.auto = '1';

    var hint = el.parentNode.querySelector('.hint');
    if (hint) {
      if (!hint.dataset.orig) { hint.dataset.orig = hint.textContent; }
      hint.textContent = 'Підтягнуто із замовлення. Можете змінити, якщо потрібно інше.';
    }
  }

  function clearAuto() {
    $$('[data-auto="1"]').forEach(function (el) {
      el.value = '';
      delete el.dataset.auto;
      var hint = el.parentNode.querySelector('.hint');
      if (hint && hint.dataset.orig) { hint.textContent = hint.dataset.orig; }
    });
  }

  // щойно клієнт торкнувся поля — воно більше не «автоматичне»
  ['#customer_name', '#email'].forEach(function (sel) {
    var el = $(sel);
    if (!el) { return; }
    el.addEventListener('input', function () { delete el.dataset.auto; });
  });

  // змінили номер чи телефон — старі дані замовлення втрачають силу
  ['#order_number', '#phone'].forEach(function (sel) {
    var el = $(sel);
    if (!el) { return; }
    el.addEventListener('input', function () {
      clearAuto();
      orderItems.classList.add('hidden');
      orderItems.innerHTML = '';
    });
  });

  lookupBtn.addEventListener('click', function () {
    var order = $('#order_number').value.trim();
    var phone = $('#phone').value.trim();

    if (!order) { resultBox.innerHTML = alertBox('error', 'Вкажіть номер замовлення.'); return; }
    if (!normalizePhone(phone)) {
      resultBox.innerHTML = alertBox('error', 'Вкажіть коректний телефон, наприклад 067 123 45 67.');
      return;
    }

    // Новий пошук — жодних слідів попереднього замовлення у формі
    clearAuto();

    lookupBtn.disabled = true;
    lookupBtn.innerHTML = '<span class="spinner"></span> Шукаємо…';
    resultBox.innerHTML = '';

    var data = new FormData();
    data.append('_token', token);
    data.append('order_number', order);
    data.append('phone', phone);

    fetch(form.dataset.lookupUrl || window.location.origin + '/returns/lookup', {
      method: 'POST',
      body: data,
      headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Помилка сервера.' }; }); })
      .then(function (res) {
        if (!res.ok) {
          resultBox.innerHTML = alertBox('error', res.error || 'Не вдалося виконати пошук.');
          return;
        }
        if (res.found) {
          renderOrder(res.order);
          resultBox.innerHTML = alertBox('success', 'Замовлення знайдено. Оберіть товари для повернення нижче.');
        } else {
          resultBox.innerHTML = alertBox('warn', res.message);
          useManual();
        }
        openRest();
      })
      .catch(function () {
        resultBox.innerHTML = alertBox('warn',
          'Не вдалося звʼязатися із сервером. Ви можете заповнити дані вручну — менеджер перевірить їх.');
        useManual();
        openRest();
      })
      .finally(function () {
        lookupBtn.disabled = false;
        lookupBtn.textContent = 'Знайти замовлення';
      });
  });

  skipBtn.addEventListener('click', function () {
    useManual();
    openRest();
  });

  function useManual() {
    orderItems.classList.add('hidden');
    orderItems.innerHTML = '';
    manualItems.classList.remove('hidden');
    if (!manualRows.children.length) { addManualRow(); }
  }

  // ---------------------------------------------------------------- крок 2
  function renderOrder(order) {
    manualItems.classList.add('hidden');
    manualRows.innerHTML = '';
    orderItems.classList.remove('hidden');

    var html = '<p class="small muted">Замовлення №' + esc(order.number) +
      (order.date ? ' від ' + fmtDate(order.date) : '') +
      (order.customer ? ', ' + esc(order.customer) : '') +
      '. Позначте товари, які повертаєте:</p>';

    (order.items || []).forEach(function (it, i) {
      var ordered = parseInt(it.qty, 10) || 1;

      // Кількість до повернення. Якщо в замовленні 1 шт — вибирати нічого.
      // Якщо більше — степер, за замовчуванням уся куплена кількість.
      var qtyField = ordered > 1
        ? '<span class="product__qty">' +
            '<label for="q' + i + '">Повертаю</label>' +
            '<span class="stepper">' +
              '<button type="button" class="stepper__btn" data-step="-1" tabindex="-1" aria-label="Менше">−</button>' +
              '<input type="number" id="q' + i + '" name="item_qty[]" value="' + ordered + '" ' +
                     'min="1" max="' + ordered + '" data-ordered="' + ordered + '" inputmode="numeric">' +
              '<button type="button" class="stepper__btn" data-step="1" tabindex="-1" aria-label="Більше">+</button>' +
            '</span>' +
            '<span class="product__qty-of">з ' + ordered + ' шт.</span>' +
          '</span>'
        : '<input type="hidden" name="item_qty[]" value="1">';

      // Не обгортаємо все в <label>: клік по лічильнику всередині label
      // перемикав би чекбокс. Тому label лише навколо назви.
      html += '<div class="product">' +
        '<input type="checkbox" id="it' + i + '" name="item_selected[]" value="' + i + '">' +
        '<span class="product__body">' +
          '<label class="product__name" for="it' + i + '">' + esc(it.name) + '</label>' +
          '<span class="product__meta">' +
            (it.sku ? 'Артикул: ' + esc(it.sku) : '') +
            (ordered === 1 ? (it.sku ? ' · ' : '') + '1 шт.' : '') +
            (it.price ? ' · ' + fmtPrice(it.price) + ' грн' : '') +
          '</span>' +
          qtyField +
        '</span>' +
        '<input type="hidden" name="item_name[]" value="' + esc(it.name) + '">' +
        '<input type="hidden" name="item_sku[]" value="' + esc(it.sku || '') + '">' +
        '<input type="hidden" name="item_price[]" value="' + esc(it.price || 0) + '">' +
        '<input type="hidden" name="item_url[]" value="' + esc(it.url || '') + '">' +
        '</div>';
    });

    if (!(order.items || []).length) {
      html += alertBox('warn', 'У замовленні не знайдено товарів. Заповніть дані вручну.');
      orderItems.innerHTML = html;
      useManual();
      return;
    }

    html += '<p class="small mt16"><a href="#" id="to-manual">Потрібного товару немає у списку — ввести вручну</a></p>';
    orderItems.innerHTML = html;

    var link = $('#to-manual');
    if (link) {
      link.addEventListener('click', function (ev) { ev.preventDefault(); useManual(); });
    }

    // Степер кількості. Вішаємо один делегований обробник на контейнер,
    // бо рядки товарів перемальовуються.
    $$('.stepper__btn', orderItems).forEach(function (btn) {
      btn.addEventListener('click', function () {
        var input = btn.parentNode.querySelector('input');
        var max   = parseInt(input.dataset.ordered, 10) || 1;
        var v     = (parseInt(input.value, 10) || 1) + parseInt(btn.dataset.step, 10);
        input.value = Math.min(max, Math.max(1, v));
        // відмічаємо товар — раз змінюють кількість, то збираються повертати
        var box = btn.closest('.product').querySelector('input[type="checkbox"]');
        if (box) { box.checked = true; }
      });
    });

    // Ручне введення: не даємо вийти за межі купленого
    $$('.product__qty input', orderItems).forEach(function (inp) {
      inp.addEventListener('input', function () {
        var max = parseInt(inp.dataset.ordered, 10) || 1;
        var v   = parseInt(inp.value, 10);
        if (!isNaN(v) && v > max) { inp.value = max; }
        if (!isNaN(v) && v < 1)   { inp.value = 1; }
      });
    });

    // підставляємо дані із замовлення, не затираючи введене клієнтом
    setAuto('#customer_name', order.customer);
    setAuto('#email', order.email);
  }

  var addItemBtn = $('#add-item');
  addItemBtn.addEventListener('click', function () { addManualRow(); });

  function addManualRow() {
    var tpl = $('#item-row-tpl');
    var node = tpl.content.cloneNode(true);
    manualRows.appendChild(node);
    var rows = manualRows.children;
    var last = rows[rows.length - 1];
    $('.js-remove-item', last).addEventListener('click', function () {
      if (manualRows.children.length > 1) { last.remove(); }
    });
  }

  // ---------------------------------------------------------------- крок 3
  var reasonSel   = $('#reason_code');
  var detailsReq  = $('#details-req');
  var detailsTa   = $('#reason_details');
  var defectHint  = $('#defect-hint');
  var needDetails = JSON.parse(reasonSel.dataset.needDetails || '[]');
  var needDefect  = JSON.parse(reasonSel.dataset.needDefect || '[]');

  function onReasonChange() {
    var v = reasonSel.value;
    var req = needDetails.indexOf(v) !== -1;
    detailsReq.classList.toggle('hidden', !req);
    detailsTa.placeholder = req ? 'Обов’язково опишіть ситуацію детальніше' : 'Що саме не так із товаром?';

    // Для причин «брак», «пошкодження», «не той товар» фото обовʼязкове —
    // заголовок кроку має це показувати, а не обманювати «(необовʼязково)».
    var photoRequired = needDefect.indexOf(v) !== -1;
    defectHint.classList.toggle('hidden', !photoRequired);
    toggle('#photo-hint-optional', !photoRequired);
    toggle('#photo-optional', !photoRequired);
    toggle('#photo-required', photoRequired);

    refreshPhotoSelects();
  }

  function toggle(sel, show) {
    var el = $(sel);
    if (el) { el.classList.toggle('hidden', !show); }
  }
  reasonSel.addEventListener('change', onReasonChange);

  // ---------------------------------------------------------------- крок 4
  var actionSel = $('#desired_action');
  var exchange  = $('#exchange-block');
  var refund    = $('#refund-block');

  function onActionChange() {
    exchange.classList.toggle('hidden', actionSel.value !== 'exchange');
    refund.classList.toggle('hidden', actionSel.value !== 'refund');
  }
  actionSel.addEventListener('change', onActionChange);

  // ---------------------------------------------------------------- крок 6: фото
  var input    = $('#photo-input');
  var uploader = $('#uploader');
  var list     = $('#upload-list');
  var files    = [];

  var PHOTO_TYPES = [
    ['general', 'Загальне фото товару'],
    ['packaging', 'Фото упаковки'],
    ['marking', 'Фото артикула'],
    ['defect', 'Фото дефекту'],
    ['other', 'Інше']
  ];

  input.addEventListener('change', function () { addFiles(input.files); });

  ['dragenter', 'dragover'].forEach(function (ev) {
    uploader.addEventListener(ev, function (e) { e.preventDefault(); uploader.classList.add('is-over'); });
  });
  ['dragleave', 'drop'].forEach(function (ev) {
    uploader.addEventListener(ev, function (e) { e.preventDefault(); uploader.classList.remove('is-over'); });
  });
  uploader.addEventListener('drop', function (e) {
    if (e.dataTransfer && e.dataTransfer.files) { addFiles(e.dataTransfer.files); }
  });

  function addFiles(fileList) {
    Array.prototype.forEach.call(fileList, function (f) {
      if (files.length >= 20) { return; }
      if (!/^image\/(jpeg|png|webp)$/.test(f.type)) {
        alert('Файл «' + f.name + '» має недопустимий формат. Дозволені: jpg, png, webp.');
        return;
      }
      if (f.size > 10 * 1024 * 1024) {
        alert('Файл «' + f.name + '» більший за 10 MB.');
        return;
      }
      files.push({ file: f, type: guessType(files.length) });
    });
    render();
  }

  function guessType(index) {
    if (index === 0) { return 'general'; }
    if (needDefect.indexOf(reasonSel.value) !== -1) { return 'defect'; }
    if (index === 1) { return 'packaging'; }
    return 'other';
  }

  function render() {
    list.innerHTML = '';
    files.forEach(function (item, i) {
      var row = document.createElement('div');
      row.className = 'upload-item';

      var img = document.createElement('img');
      img.src = URL.createObjectURL(item.file);
      img.onload = function () { URL.revokeObjectURL(img.src); };

      var name = document.createElement('div');
      name.className = 'upload-item__name';
      name.textContent = item.file.name + ' · ' + (item.file.size / 1048576).toFixed(1) + ' MB';

      var sel = document.createElement('select');
      sel.name = 'photo_types[]';
      PHOTO_TYPES.forEach(function (t) {
        var o = document.createElement('option');
        o.value = t[0];
        o.textContent = t[1];
        if (t[0] === item.type) { o.selected = true; }
        sel.appendChild(o);
      });
      sel.addEventListener('change', function () { files[i].type = sel.value; });

      var del = document.createElement('button');
      del.type = 'button';
      del.className = 'upload-item__del';
      del.innerHTML = '&times;';
      del.title = 'Прибрати';
      del.addEventListener('click', function () { files.splice(i, 1); render(); syncInput(); });

      row.appendChild(img);
      row.appendChild(name);
      row.appendChild(sel);
      row.appendChild(del);
      list.appendChild(row);
    });
    syncInput();
  }

  // синхронізуємо FileList інпута з нашим масивом, щоб порядок збігався з photo_types[]
  function syncInput() {
    if (typeof DataTransfer === 'undefined') { return; }
    var dt = new DataTransfer();
    files.forEach(function (item) { dt.items.add(item.file); });
    input.files = dt.files;
  }

  function refreshPhotoSelects() {
    if (needDefect.indexOf(reasonSel.value) === -1 || !files.length) { return; }
    var hasDefect = files.some(function (f) { return f.type === 'defect'; });
    if (!hasDefect && files.length > 1) {
      files[files.length - 1].type = 'defect';
      render();
    }
  }

  // ---------------------------------------------------------------- IBAN
  var iban = $('#refund_iban');
  if (iban) {
    iban.addEventListener('input', function () {
      var v = iban.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 29);
      iban.value = v.replace(/(.{4})/g, '$1 ').trim();
    });
  }

  // ---------------------------------------------------------------- сабміт
  form.addEventListener('submit', function (e) {
    var problems = [];

    if (!$('#order_number').value.trim()) { problems.push('Вкажіть номер замовлення.'); }
    if (!normalizePhone($('#phone').value)) { problems.push('Вкажіть коректний телефон.'); }

    var picked = $$('input[name="item_selected[]"]:checked').length;
    var typed  = $$('#manual-rows input[name="item_name[]"]').filter(function (i) { return i.value.trim(); }).length;
    if (!picked && !typed) { problems.push('Оберіть або вкажіть щонайменше один товар.'); }

    $$('.product__qty input').forEach(function (inp) {
      var max = parseInt(inp.dataset.ordered, 10) || 1;
      var v   = parseInt(inp.value, 10);
      if (isNaN(v) || v < 1) {
        problems.push('Кількість до повернення має бути не менше 1.');
      } else if (v > max) {
        problems.push('Не можна повернути більше, ніж у замовленні (' + max + ' шт.).');
      }
    });

    if (!reasonSel.value) { problems.push('Оберіть причину повернення.'); }
    if (needDetails.indexOf(reasonSel.value) !== -1 && !detailsTa.value.trim()) {
      problems.push('Для обраної причини потрібен детальний опис.');
    }
    if (!actionSel.value) { problems.push('Оберіть бажану дію.'); }
    if (actionSel.value === 'exchange' && !$('#exchange_wish').value.trim()) {
      problems.push('Вкажіть, на який товар хочете обміняти.');
    }

    $$('#step-5 input[type="checkbox"]').forEach(function (cb) {
      if (!cb.checked) { problems.push('Підтвердіть усі пункти про стан товару.'); }
    });

    if (!files.length && needDefect.indexOf(reasonSel.value) !== -1) {
      problems.push('Для обраної причини фото обовʼязкове — додайте хоча б одне.');
    }

    if (actionSel.value === 'refund') {
      if (!$('#refund_name').value.trim()) { problems.push('Вкажіть ПІБ отримувача коштів.'); }
      var ib = $('#refund_iban').value.replace(/\s/g, '');
      if (!/^UA\d{27}$/.test(ib)) { problems.push('IBAN має починатися з UA і містити 29 символів.'); }
      var tax = $('#refund_tax_id').value.replace(/\D/g, '');
      if (tax.length !== 10 && tax.length !== 8) { problems.push('ІПН/РНОКПП — 10 цифр, ЄДРПОУ — 8.'); }
    }

    if (!$('input[name="confirm_rules"]').checked) {
      problems.push('Потрібно погодитись з умовами обміну та повернення.');
    }

    problems = problems.filter(function (v, i, a) { return a.indexOf(v) === i; });

    if (problems.length) {
      e.preventDefault();
      alert('Перевірте, будь ласка:\n\n• ' + problems.join('\n• '));
      return;
    }

    // Заявка пішла — чернетка більше не потрібна. Якщо сервер відхилить,
    // поля повернуться через old() на боці PHP.
    draftClear();

    var btn = $('#submit-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Відправляємо…';
  });

  // ---------------------------------------------------------------- утиліти
  function esc(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function fmtDate(iso) {
    var p = String(iso).split('-');
    return p.length === 3 ? p[2] + '.' + p[1] + '.' + p[0] : iso;
  }

  // 456 -> "456", 456.5 -> "456,50", 1234.5 -> "1 234,50"
  function fmtPrice(v) {
    var n = parseFloat(v);
    if (isNaN(n)) { return esc(v); }
    var s = Number.isInteger(n) ? String(n) : n.toFixed(2).replace('.', ',');
    return s.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  }

  // ---------------------------------------------------------------- чернетка
  // Зберігаємо заповнене у sessionStorage, щоб перехід на «Умови» й назад
  // не змушував вводити все заново. Реквізити та фото НЕ зберігаємо:
  // банківські дані не мають лежати у сховищі браузера.
  var DRAFT_KEY = 'rma_draft';
  var DRAFT_TEXT = ['customer_name', 'email', 'reason_code', 'reason_details',
                    'desired_action', 'exchange_wish', 'customer_comment'];
  var DRAFT_CHECK = ['confirm_not_installed', 'confirm_no_traces', 'confirm_packaging',
                     'confirm_understand', 'confirm_rules'];

  function draftSave() {
    try {
      var data = {};
      DRAFT_TEXT.forEach(function (n) {
        var el = form.elements[n];
        if (el && el.value) { data[n] = el.value; }
      });
      DRAFT_CHECK.forEach(function (n) {
        var el = form.elements[n];
        if (el && el.checked) { data[n] = 1; }
      });
      sessionStorage.setItem(DRAFT_KEY, JSON.stringify(data));
    } catch (e) { /* приватний режим — не критично */ }
  }

  function draftRestore() {
    try {
      var raw = sessionStorage.getItem(DRAFT_KEY);
      if (!raw) { return; }
      var data = JSON.parse(raw);
      DRAFT_TEXT.forEach(function (n) {
        var el = form.elements[n];
        if (el && !el.value && data[n]) { el.value = data[n]; }
      });
      DRAFT_CHECK.forEach(function (n) {
        var el = form.elements[n];
        if (el && data[n]) { el.checked = true; }
      });
    } catch (e) { /* нема чого відновлювати */ }
  }

  function draftClear() {
    try { sessionStorage.removeItem(DRAFT_KEY); } catch (e) {}
  }

  form.addEventListener('input', draftSave);
  form.addEventListener('change', draftSave);

  draftRestore();
  onReasonChange();
  onActionChange();

  // ---------------------------------------------------------------- відновлення замовлення
  // Замовлення знайдене раніше і лежить у сесії — показуємо його одразу,
  // не роблячи повторний запит до SalesDrive (ліміт 10 запитів/хв).
  var orderRestored = false;
  if (form.dataset.restore) {
    try {
      var restored = JSON.parse(form.dataset.restore);
      renderOrder(restored.order);
      rest.classList.remove('hidden');
      resultBox.innerHTML = alertBox('info',
        'Замовлення №' + esc(restored.order.number) + ' уже знайдено раніше. ' +
        'Якщо потрібне інше — змініть номер і натисніть «Знайти замовлення».');
      orderRestored = true;
    } catch (e) { /* некоректні дані — просто показуємо порожню форму */ }
  }

  // прив'язуємо кнопки видалення до рядків, відрендерених сервером
  $$('#manual-rows .js-remove-item').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (manualRows.children.length > 1) { btn.closest('.card').remove(); }
    });
  });

  if (!manualRows.children.length) { addManualRow(); }

  // якщо сторінка перезавантажилась з помилками — одразу показуємо решту кроків
  if (document.querySelector('.error-text') || document.querySelector('.alert--error')) {
    rest.classList.remove('hidden');
    // ручне введення — лише коли товари не підтягнулися із замовлення,
    // інакше клієнт побачить одночасно список товарів і порожні поля
    if (!orderRestored) { manualItems.classList.remove('hidden'); }
  }
})();
