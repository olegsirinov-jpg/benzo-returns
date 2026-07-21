# Розгортання через cPanel Git™ Version Control

Схема: ви правите → **GitHub** (`git push`) → у cPanel тиснете **Update / Deploy**
(або воно тягне саме) → cPanel робить `git pull` і за `.cpanel.yml` розкладає файли
в каталог сайту. FTP не потрібен.

---

## Одноразове налаштування

### 1. Створіть репозиторій на GitHub

1. github.com → **New repository** → назва, напр. `benzo-returns`, тип **Private**.
2. Нічого не додавайте (README/gitignore вже є в проєкті).

### 2. Залийте проєкт (на вашому компʼютері)

Спершу видаліть зламану теку `.git` у папці проєкту (якщо є), потім:

```bash
git init
git add -A
git commit -m "Система повернень — перший коміт"
git branch -M main
git remote add origin https://github.com/ВАШ_ЛОГІН/benzo-returns.git
git push -u origin main
```

> `.env`, `uploads/`, `storage/logs/` у git не потрапляють (вони в `.gitignore`).

### 3. Впишіть свій шлях у `.cpanel.yml`

У cPanel → **File Manager** зайдіть у каталог субдомену `returns` і скопіюйте
повний шлях зверху (напр. `/home/benzopila/returns.benzo-pila.in.ua`).

Відкрийте файл `.cpanel.yml` у проєкті й замініть у ньому `DEPLOYPATH` на цей шлях.
Закомітьте й запуште:

```bash
git add .cpanel.yml
git commit -m "Шлях розгортання"
git push
```

### 4. Підключіть репозиторій у cPanel

1. cPanel → **Git™ Version Control** → **Create**.
2. **Clone URL**: `https://github.com/ВАШ_ЛОГІН/benzo-returns.git`
   - для приватного репо потрібен токен: у URL вставте
     `https://ЛОГІН:PERSONAL_ACCESS_TOKEN@github.com/...`
     (токен: GitHub → Settings → Developer settings → Personal access tokens,
     права `repo`). Або зробіть репозиторій публічним — у коді секретів немає.
3. **Repository Path**: службовий каталог для клону, напр. `/home/ВАШЛОГІН/repos/benzo-returns`
   (НЕ каталог сайту — файли туди розкладе `.cpanel.yml`).
4. Створіть → у списку репозиторію вкладка **Pull or Deploy** → **Update from Remote**,
   потім **Deploy HEAD Commit**.

### 5. Первинне встановлення на хостингу (один раз)

1. Створіть **базу MySQL** у cPanel (MySQL Databases), запишіть імʼя/логін/пароль.
2. У **File Manager** у каталозі сайту створіть файл **`.env`** за зразком `.env.example`:
   - `APP_URL=https://returns.benzo-pila.in.ua`, `APP_DEBUG=0`
   - дані БД (`DB_HOST` зазвичай `localhost`)
   - `CRON_KEY` — новий випадковий рядок
   - ключі SalesDrive/НП/TurboSMS/SMTP можна лишити порожніми — заповните в адмінці
3. Завантажте `install.php` у каталог сайту вручну (у git-деплой він не входить) →
   відкрийте `https://returns.benzo-pila.in.ua/install.php` → створіть адміністратора →
   **видаліть `install.php`**.
4. cPanel → **MultiPHP Manager**: для субдомену `returns` виберіть **PHP 7.4+** (працює до 8.4).
5. Права на запис для `uploads/` і `storage/logs/` (755/775) — зазвичай уже так.

### 6. Крон-завдання (cPanel → Cron Jobs)

```
0 * * * * php /home/ВАШЛОГІН/returns.benzo-pila.in.ua/cron/stale.php
*/30 * * * * php /home/ВАШЛОГІН/returns.benzo-pila.in.ua/cron/nptrack.php
```

---

## Щоденна робота

Коли я вношу правки, ви:

```bash
git add -A
git commit -m "опис змін"
git push
```

Потім у cPanel → Git Version Control → **Update from Remote** → **Deploy HEAD Commit**
(два кліки). Або налаштуйте вебхук, щоб деплой запускався сам (див. нижче).

`.env`, завантажені фото й логи деплой не чіпає — вони під `--exclude` у `.cpanel.yml`.

### Автодеплой без ручних кліків (необовʼязково)

Щоб не тиснути «Deploy» вручну, у GitHub → Settings → Webhooks додайте вебхук на
cPanel-ендпоінт розгортання. Точний URL залежить від хостера — уточніть у підтримки
«як налаштувати автоматичний git deploy по push». Якщо дадуть — допоможу дописати.

---

## Важливо

- Корінь сайту субдомену = корінь проєкту (там `index.php`). Каталоги `app/`, `views/`,
  `storage/`, `cron/`, `install/` і `.env` закриті через `.htaccess`.
- Має бути ввімкнений `mod_rewrite` (на cPanel-хостингах — завжди).
- `.env` заповнюйте бойовими даними; ключі інтеграцій зручніше вносити в адмінці
  (вкладка «Налаштування») — вони лягають у базу, а не у файл.
