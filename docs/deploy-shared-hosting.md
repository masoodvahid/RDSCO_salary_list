# انتشار روی هاست اشتراکی (DirectAdmin)

این راهنما برای ساختاری است که کد برنامه در پوشه‌ی `core` کنار `public_html` قرار می‌گیرد و فقط فایل‌های عمومی داخل `public_html` هستند.

```
/home/USER/domains/DOMAIN/
├── core/             ← کل برنامه (بسته‌ی Release)
│   ├── .env
│   ├── storage/
│   └── ...
└── public_html/      ← محتوای core/public
    ├── index.php     (بدون ویرایش؛ خودش ../core را پیدا می‌کند)
    ├── .htaccess
    ├── build/
    ├── js/
    └── vendor/livewire/
```

## ۱. پیش‌نیازهای هاست

- **PHP 8.4:** از «Select PHP version» در DirectAdmin انتخاب کنید. Laravel 13 و OpenSpout 5 روی نسخه‌های پایین‌تر اجرا نمی‌شوند.
- **افزونه‌های PHP:**
  - **لازم:** `pdo_mysql`، `mbstring`، `openssl`، `fileinfo`، `zip`، `zlib`، `dom`، `xmlreader`
  - **پیشنهادی:** `curl` و `bcmath`
- **دیتابیس:** MySQL 8 یا MariaDB 10.6 به بالا.
- **دسترسی به اینترنت (فقط برای به‌روزرسانی از داخل سامانه):** هاست باید به `api.github.com` و `github.com` دسترسی داشته باشد.

## ۲. نصب اول

### الف) بسته‌ی آماده را بگیرید

از صفحه‌ی Releases مخزن، فایل `tukahr-X.Y.Z.zip` را دانلود کنید. فایل «Source code (zip)» را نگیرید.

این بسته همه‌چیز را دارد، پس روی هاست به composer یا npm نیازی نیست:
- `vendor` (بدون پکیج‌های توسعه)
- CSS و JS ساخته‌شده
- اسکریپت‌های publish‌شده‌ی Livewire

### ب) فایل‌ها را قرار دهید

1. پوشه‌ی `core` را کنار `public_html` بسازید، zip را داخل آن آپلود و از File Manager استخراج (Extract) کنید.
2. محتوای `core/public` را به `public_html` منتقل کنید. فایل مخفی `.htaccess` را هم منتقل کنید.
   - اگر `public_html` فایل پیش‌فرض `index.html` دایرکت‌ادمین را دارد، حذفش کنید؛ وگرنه به جای برنامه نمایش داده می‌شود.
   - بعد از انتقال، پوشه‌ی `core/public` لازم نیست.
3. **`index.php` را ویرایش نکنید.** این فایل خودش تشخیص می‌دهد که برنامه در `../core` است و `public_html` را به‌عنوان پوشه‌ی public به لاراول معرفی می‌کند.
4. پوشه‌های `core/storage` و `core/bootstrap/cache` باید قابل نوشتن باشند. در دایرکت‌ادمین که PHP با کاربر خود هاست اجرا می‌شود، دسترسی پیش‌فرض ۷۵۵ کافی است.

### ج) فایل `.env`

فایل `core/.env` را بسازید:

```dotenv
APP_NAME=TukaHR
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://hr.example.com
APP_TIMEZONE=Asia/Tehran
APP_LOCALE=fa

LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=user_tukahr
DB_USERNAME=user_tukahr
DB_PASSWORD=

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=sync

KAVENEGAR_API_KEY=
KAVENEGAR_OTP_TEMPLATE=tukahr-login
KAVENEGAR_APPROVAL_TEMPLATE=tukahr-approval
KAVENEGAR_SENDER=

TUKA_TEST_ADMIN_ENABLED=false
TUKA_UPDATE_REPO=masoodvahid/tukahr
TUKA_UPDATE_TOKEN=
```

- **`APP_KEY`:** روی سیستم خودتان بسازید و اینجا بگذارید:
  ```bash
  php artisan key:generate --show
  ```
- **`KAVENEGAR_API_KEY`:** در production الزامی است. بدون آن ورود با کد پیامکی کار نمی‌کند و برنامه خطا می‌دهد.
- **`SESSION_SECURE_COOKIE=true`:** فقط وقتی دامنه SSL دارد. گزینه‌ی «Force SSL with https redirect» را در تنظیمات دامنه‌ی دایرکت‌ادمین روشن کنید.

### د) دیتابیس

1. در DirectAdmin یک دیتابیس و کاربر بسازید.
2. **قبل از گرفتن خروجی:** روی سیستم خودتان کد را به همان نسخه‌ای برسانید که روی هاست نصب می‌کنید و migrationها را اجرا کنید، تا ساختار دیتابیس با کد یکی باشد:
   ```bash
   git pull origin main
   php artisan migrate
   ```
3. خروجی بگیرید و در phpMyAdmin هاست Import کنید:
   ```bash
   mysqldump -u root -p --single-transaction --default-character-set=utf8mb4 tukahr > tukahr.sql
   ```
4. **⚠️ کاربران نمونه را حذف کنید.** اگر دیتابیس محلی با `TUKA_TEST_ADMIN_ENABLED=true` seed شده باشد، کاربران نمونه با شماره‌های `09120000000` تا `09120000004` در آن هستند. این شماره‌ها مال افراد واقعی‌اند و هر کسی که یکی از آن‌ها را داشته باشد می‌تواند با کد پیامکی وارد شود.
   1. مطمئن شوید شماره‌ی خودتان با نقش مدیر وجود دارد.
   2. از صفحه‌ی «اعضا و دسترسی» دسترسی کاربران نمونه را قطع کنید.

### هـ) بررسی

1. سایت را باز کنید و با شماره‌ی مدیر وارد شوید.
2. صفحه‌ی «به‌روزرسانی» را باز کنید و «بررسی نسخه‌ی جدید» را بزنید تا مطمئن شوید هاست به GitHub دسترسی دارد.

## ۳. درباره‌ی publish کردن Livewire

**روی هاست اشتراکی لازم است و در بسته‌ی Release انجام شده است.**

- **مشکل:** Livewire به‌طور پیش‌فرض فایل `livewire.js` را از یک route لاراول می‌دهد. در بسیاری از هاست‌های دایرکت‌ادمین nginx جلوی Apache قرار دارد و درخواست فایل‌های `.js` را خودش جواب می‌دهد. چنین فایلی روی دیسک وجود ندارد، پس nginx خطای 404 برمی‌گرداند و Livewire کار نمی‌کند.
- **راه‌حل:** با publish، فایل‌ها در `public/vendor/livewire` کپی می‌شوند. Livewire وقتی `vendor/livewire/manifest.json` را در پوشه‌ی public ببیند، خودش از همان فایل‌های ثابت استفاده می‌کند. چون `index.php` پوشه‌ی public را `public_html` معرفی می‌کند، فایل‌ها باید در `public_html/vendor/livewire` باشند. با انتقال محتوای `core/public` این کار انجام می‌شود.
- **بعد از ارتقای Livewire:** این فایل‌ها باید دوباره publish شوند. بسته‌ی هر Release این کار را خودکار انجام می‌دهد و به‌روزرسانی داخل سامانه آن‌ها را جایگزین می‌کند.

اگر روزی دستی و از روی سورس نصب کردید:

```bash
php artisan vendor:publish --tag=livewire:assets --force
```

## ۴. به‌روزرسانی

### انتشار نسخه‌ی جدید (توسعه‌دهنده)

1. تغییرات را در `main` ادغام کنید.
2. یک Release بسازید و آن را Publish کنید:
   - **از GitHub:** بخش Releases، گزینه‌ی «Draft a new release». برچسبی مثل `v1.1.0` بسازید.
   - **یا از خط فرمان:**
     ```bash
     gh release create v1.1.0 --generate-notes
     ```
3. workflow به نام «Release package» در تب Actions بسته‌ی `tukahr-1.1.0.zip` و checksum آن را می‌سازد و به Release اضافه می‌کند. این کار چند دقیقه طول می‌کشد.

**قواعد نسخه‌گذاری:**
- شماره‌ی نسخه باید بزرگ‌تر از نسخه‌ی قبل باشد.
- migrationهای قدیمی را ویرایش نکنید. همیشه migration تازه بسازید و مراقب داده‌های موجود باشید.

### نصب نسخه‌ی جدید (مدیر سامانه)

از منوی «به‌روزرسانی»، «بررسی نسخه‌ی جدید» و سپس «نصب این نسخه» را بزنید. این مراحل خودکار و پشت سر هم انجام می‌شوند:

1. **دانلود و بررسی:** بسته دانلود و checksum آن بررسی می‌شود. نسخه‌ی PHP و افزونه‌های لازم هم کنترل می‌شوند.
2. **بکاپ دیتابیس:** فایل بکاپ در `core/storage/app/backups` ذخیره می‌شود و ۵ نسخه‌ی آخر نگه داشته می‌شود.
3. **جایگزینی فایل‌ها:** سامانه به حالت تعمیر می‌رود و کد جایگزین می‌شود. نسخه‌ی قبلی کنار گذاشته می‌شود.
4. **راه‌اندازی دوباره:** `migrate --force` اجرا می‌شود، کش‌ها بازسازی می‌شوند و سامانه از حالت تعمیر خارج می‌شود.

**چه چیزهایی دست نمی‌خورند:** `core/.env`، کل `core/storage` (لاگ‌ها، بکاپ‌ها و فایل‌های موقت) و `.htaccess` موجود در `public_html`.

**اگر مرحله‌ای خطا بدهد:** پیام خطا نمایش داده می‌شود و دو دکمه در دسترس است:
- **«ادامه‌ی به‌روزرسانی»:** اجرای دوباره‌ی migration.
- **«بازگرداندن نسخه‌ی قبل»:** دیتابیس از بکاپ و کد از نسخه‌ی کنارگذاشته برمی‌گردد.

صفحه‌ی به‌روزرسانی در حالت تعمیر هم برای مدیر باز است.

**توکن GitHub:** برای مخزن عمومی لازم نیست. اگر مخزن خصوصی شد، یا GitHub به دلیل اشتراک IP هاست درخواست‌ها را محدود کرد، یک توکن fine-grained فقط با دسترسی خواندن Contents بسازید و در `TUKA_UPDATE_TOKEN` بگذارید.

### اگر هاست به GitHub دسترسی ندارد

1. zip نسخه را دستی دانلود کنید.
2. آن را روی `core` استخراج کنید، بدون جایگزین کردن `.env` و `storage`.
3. محتوای `core/public` را به `public_html` کپی کنید.
4. migration را با Cron اجرا کنید (بخش بعد).

## ۵. اجرای دستور artisan بدون SSH

از بخش Cron Jobs دایرکت‌ادمین یک کار موقت با اجرای هر دقیقه بسازید، یک دقیقه صبر کنید و بعد آن را حذف کنید:

```bash
/usr/local/php84/bin/php /home/USER/domains/DOMAIN/core/artisan migrate --force >> /home/USER/domains/DOMAIN/core/storage/logs/cron.log 2>&1
```

مسیر PHP بسته به هاست متفاوت است، مثلاً `/usr/local/bin/php` یا `/usr/local/php84/bin/php`. نسخه‌ی ۸.۴ را انتخاب کنید.

## ۶. عیب‌یابی

| نشانه | علت و راه‌حل |
|---|---|
| خطای 500 یا صفحه‌ی سفید | `core/storage/logs/laravel-YYYY-MM-DD.log` را ببینید. برای چند دقیقه `APP_DEBUG=true` بگذارید و بعد برگردانید. |
| خطای PHP version / platform check | نسخه‌ی PHP دامنه را 8.4 کنید. |
| `Vite manifest not found` | پوشه‌ی `public_html/build` منتقل نشده است. |
| دکمه‌ها کار نمی‌کنند و در کنسول، `livewire.min.js` خطای 404 دارد | پوشه‌ی `public_html/vendor/livewire` وجود ندارد. |
| خطای 419 Page Expired | `APP_URL` با آدرس واقعی (http/https) یکی نیست، یا `SESSION_SECURE_COOKIE=true` بدون SSL تنظیم شده است. |
| پیامک ورود نمی‌رسد | کلید و نام الگوهای کاوه‌نگار را بررسی کنید. اگر در پنل کاوه‌نگار محدودیت IP دارید، IP هاست را اضافه کنید. |
| سامانه بعد از به‌روزرسانی ناموفق در حالت تعمیر مانده | مدیر صفحه‌ی `/system/update` را باز کند و «ادامه» یا «بازگرداندن» را بزند. در اضطرار، فایل‌های `core/storage/framework/down` و `core/storage/framework/maintenance.php` را حذف کنید. |
