# سامانهٔ هوشمند پیش‌اعتبارسنجی و پایش مجوزهای حمل‌ونقل

**Design and Implementation of an Intelligent System for Pre-Validation and Monitoring of Transportation Permits**

نویسنده: Hananeh Kalateh

---

## این پروژه چه کار می‌کند؟

متقاضی مجوز حمل‌ونقل، مدارکش را در پنل بارگذاری می‌کند: کارت ملی، گواهینامه،
کارت مالکیت خودرو و — برای تمدید — مجوز قبلی. سامانه به‌جای اینکه کارشناس
تک‌تک را چشمی بخواند، این کارها را خودکار انجام می‌دهد:

```
بارگذاری مدرک → بررسی کیفیت فایل (تاری، روشنایی، ابعاد)
              → OCR فارسی → استخراج فیلدها (کد ملی، نام، تاریخ‌ها، پلاک…)
              → اعتبارسنجی (تاریخ منقضی؟ فیلد اجباری خالی؟ کد ملی روی هر سه مدرک یکی است؟)
              → امتیاز اطمینان → تصمیم: تایید | رد | نیاز به بررسی انسانی
```

پرونده‌ای که سامانه مطمئن نیست، به صف کارشناس می‌رود؛ کارشناس مقدارها را
کنار تصویر مدرک می‌بیند و اصلاح می‌کند، و همان اصلاح به دیتاست آموزشی
برمی‌گردد.

پروژه دو نیمه دارد که عمداً از هم جدا نگه داشته می‌شوند:

| نیمه | پوشه | نقش |
|---|---|---|
| **موتور** (پایتون) | `app/`، `dataset/`، `hana_engine/` | ساخت دادهٔ مصنوعی، چاپ روی قالب مدرک، اعوجاج عمدی، پیش‌پردازش، OCR، ارزیابی دقت |
| **پنل** (لاراول) | `panel/` | ورود، فرایند مجوز، بررسی انسانی، دیتاست و تگ‌گذاری، داشبورد و گزارش |

پنل هرگز مستقیم به کد پایتون دست نمی‌زند؛ تنها پل، کلاس
`panel/app/Services/HanaEngine.php` است که موتور را با JSON صدا می‌زند.

> 🔒 **نکتهٔ مهم دربارهٔ داده:** این سامانه با کارت ملی و گواهینامه سروکار
> دارد، ولی **هیچ مدرک هویتی واقعی در این مخزن نیست**. همهٔ تصویرها را
> ژنراتور خود پروژه می‌سازد و کد ملی‌های نمونه هم ساختگی‌اند (فقط رقم
> کنترلشان درست است). موقع کار روی پروژه هم هرگز اسکن مدرک واقعی وارد
> نکنید — حتی «برای یک تست سریع».

---

## پیش‌نیازها

راهنمای زیر برای **لینوکس (اوبونتو یا دبیان)** نوشته شده و روی
Debian 13 آزمایش شده است. اگر ویندوز دارید، ساده‌ترین راه نصب
[WSL](https://learn.microsoft.com/windows/wsl/install) و اجرای همین
دستورها داخل آن است.

| چیز | نسخهٔ آزمایش‌شده | برای چه |
|---|---|---|
| PHP | ۸.۴ (حداقل ۸.۳) | پنل لاراول |
| Composer | ۲.x | نصب کتابخانه‌های PHP |
| MariaDB یا MySQL | MariaDB 11.8 | پایگاه دادهٔ پنل |
| Redis | ۸.۰ | صف کارهای سنگین، نشست، کش |
| Python | ۳.۱۳ (حداقل ۳.۱۰) | موتور OCR |
| Tesseract OCR | ۵.۵ + دیتای `fas` و `eng` | خواندن متن فارسی از تصویر |

**نیازی به Node.js و npm نیست.** پنل ساخت فرانت ندارد؛ یک فایل CSS
دست‌نویس در `panel/public/assets/app.css` دارد و بس.

---

## نصب گام‌به‌گام

### گام ۱ — نصب بسته‌های سیستمی

```bash
sudo apt update
sudo apt install -y \
  git curl unzip \
  mariadb-server redis-server nginx \
  python3 python3-venv python3-pip \
  tesseract-ocr tesseract-ocr-fas tesseract-ocr-eng
```

حالا PHP. روی اوبونتو/دبیان معمولاً نسخهٔ ۸.۴ در مخزن پیش‌فرض نیست، پس
اول مخزن Sury/Ondřej را اضافه کنید:

```bash
# دبیان
sudo apt install -y ca-certificates lsb-release
curl -fsSL https://packages.sury.org/php/apt.gpg | sudo tee /usr/share/keyrings/sury-php.gpg > /dev/null
echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
  | sudo tee /etc/apt/sources.list.d/sury-php.list

# اوبونتو (به‌جای دو خط بالا)
# sudo add-apt-repository ppa:ondrej/php && sudo apt update

sudo apt update
sudo apt install -y \
  php8.4-cli php8.4-fpm php8.4-mysql php8.4-redis \
  php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip \
  php8.4-gd php8.4-intl php8.4-bcmath php8.4-sqlite3
```

و Composer:

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

**بررسی گام ۱** — هر چهار دستور باید نسخه چاپ کنند:

```bash
php -v && composer --version && python3 --version && tesseract --version
tesseract --list-langs        # باید eng و fas را ببینید
```

اگر `fas` در فهرست نبود، OCR فارسی کار نمی‌کند؛ بسته‌اش را دوباره نصب کنید.

---

### گام ۲ — گرفتن کد

```bash
git clone https://github.com/mohammadahmadifar/hana_ai.git
cd hana_ai
```

از این‌جا به بعد، همهٔ دستورها از داخل همین پوشه اجرا می‌شوند. مسیر کامل
این پوشه را یادداشت کنید — در گام ۵ لازمش دارید:

```bash
pwd     # مثلاً: /home/ali/hana_ai
```

---

### گام ۳ — موتور پایتون

یک محیط مجازی می‌سازیم تا کتابخانه‌های پروژه با پایتون سیستم قاطی نشوند:

```bash
python3 -m venv .venv
.venv/bin/pip install --upgrade pip
.venv/bin/pip install -r requirements.txt
```

> روی سروری که کارت گرافیک و محیط گرافیکی ندارد، اگر نصب `opencv-python`
> خطا داد، به‌جایش نسخهٔ سبک را نصب کنید:
> `.venv/bin/pip install opencv-python-headless`

**بررسی گام ۳** — موتور باید بتواند یک شخص مصنوعی بسازد:

```bash
.venv/bin/python -c "from app.person.person_generator import generate_person; print(generate_person()['national_id'])"
```

اگر یک کد ملی ده‌رقمی چاپ شد، موتور سر جایش است.

---

### گام ۴ — پایگاه داده

سرویس‌ها را روشن کنید و یک پایگاه داده و کاربر برای پنل بسازید:

```bash
sudo systemctl enable --now mariadb redis-server
```

```bash
sudo mariadb <<'SQL'
CREATE DATABASE IF NOT EXISTS hana CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'hana'@'127.0.0.1' IDENTIFIED BY 'CHANGE_ME';
GRANT ALL PRIVILEGES ON hana.* TO 'hana'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
```

`CHANGE_ME` را با یک رمز قوی عوض کنید — بدون فاصله و بدون کاراکتر فارسی —
و همان را در گام بعد در فایل `.env` بنویسید. یادداشتش کنید؛ دوباره لازمش
دارید.

**بررسی گام ۴:**

```bash
mariadb -h 127.0.0.1 -u hana -p hana -e "SELECT 'اتصال برقرار است';"
redis-cli ping        # باید PONG بدهد
```

---

### گام ۵ — پنل لاراول

```bash
cd panel
composer install
cp .env.example .env
php artisan key:generate
```

> ⚠️ دستور `composer setup` را اجرا **نکنید**. آن اسکریپت از قالب پیش‌فرض
> لاراول مانده و `npm` را صدا می‌زند؛ این پروژه ساخت فرانت ندارد.

حالا فایل `panel/.env` را با یک ویرایشگر متن باز کنید و این چند خط را پر
کنید (بقیهٔ خط‌ها را دست نزنید):

```ini
# همان رمزی که در گام ۴ برای کاربر hana گذاشتید
DB_PASSWORD=CHANGE_ME

# مسیر کامل پوشهٔ hana_ai که در گام ۲ یادداشت کردید
HANA_ENGINE_ROOT=/home/ali/hana_ai
HANA_ENGINE_PYTHON=/home/ali/hana_ai/.venv/bin/python

# رمزی که برای حساب‌های نمونه گذاشته می‌شود (با همین وارد پنل می‌شوید)
SEED_PASSWORD=CHANGE_ME_TOO
```

> مقدارها را بدون فاصله بنویسید. اگر رمزتان فاصله دارد، داخل گیومه
> بگذاریدش: `DB_PASSWORD="my secret pass"`.

سپس جدول‌ها و دادهٔ اولیه را بسازید:

```bash
php artisan migrate --force
php artisan db:seed --force
```

`migrate` جدول‌ها را می‌سازد و `db:seed` دادهٔ مرجع (انواع مدرک، انواع
خدمت، آستانه‌های امتیازدهی) به‌علاوهٔ چهار حساب نمونه را وارد می‌کند.

---

### گام ۶ — مجوز پوشه‌ها

مدارک بارگذاری‌شده در `panel/storage` می‌نشینند و وب‌سرور باید بتواند در
آن‌ها بنویسد:

```bash
cd ..                                   # برگردید به ریشهٔ hana_ai
sudo chown -R www-data:www-data panel/storage panel/bootstrap/cache
sudo chmod -R 775 panel/storage panel/bootstrap/cache
```

> 🪤 **تلهٔ رایج:** اگر دستورهای `php artisan` را با `sudo` اجرا کنید،
> پوشه‌های تازه با مالکیت `root` ساخته می‌شوند و بارگذاری فایل از رابط وب
> بی‌صدا می‌شکند. دستورهای artisan را با کاربر عادی اجرا کنید، و اگر یک بار
> با `sudo` اجرا کردید، همین دو دستور بالا را دوباره بزنید.

---

### گام ۷ — بالا آوردن پنل

**راه ساده (برای دیدن و آزمایش):**

```bash
cd panel
php artisan serve --host=127.0.0.1 --port=8101
```

پنل روی <http://127.0.0.1:8101> باز می‌شود. این پنجرهٔ ترمینال باید باز
بماند.

**راه اصلی (nginx — برای استفادهٔ واقعی):**

فایل `/etc/nginx/sites-available/hana-panel` را بسازید:

```nginx
server {
    listen 8101;
    server_name _;

    root /home/ali/hana_ai/panel/public;   # ← مسیر خودتان
    index index.php;
    charset utf-8;

    client_max_body_size 60m;              # مدارک اسکن‌شده بزرگ‌اند

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_read_timeout 300;          # OCR طول می‌کشد
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

و فعالش کنید:

```bash
sudo ln -s /etc/nginx/sites-available/hana-panel /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo systemctl enable --now php8.4-fpm
```

---

### گام ۸ — کارگر صف (این یکی را جا نیندازید)

کارهای سنگین — OCR، تولید انبوه تصویر، ارزیابی دقت — داخل صف اجرا
می‌شوند. **بدون کارگر صف، پرونده برای همیشه در وضعیت «در حال پردازش»
می‌ماند** و هیچ پیام خطایی هم نمی‌بینید.

```bash
cd panel
nohup bash scripts/queue-worker.sh > storage/logs/queue.log 2>&1 &
```

بررسی اینکه روشن است:

```bash
pgrep -af "artisan queue:work" | head
```

برای خاموش کردنش:

```bash
pkill -f "artisan queue:work.*hana"
```

> 🪤 **تلهٔ رایج:** کارگر صف کد را در حافظه نگه می‌دارد. اگر کد PHP را عوض
> کردید، کارگر را دوباره راه بیندازید، وگرنه با خطای عجیبی مثل
> «Call to undefined method» می‌ترکد در حالی که فایل روی دیسک درست است.

---

### گام ۹ — زمان‌بندی روزانه (اختیاری ولی توصیه‌شده)

تصویرهای موقتی موتور اگر پاک نشوند روی دیسک تلنبار می‌شوند — و محتوایشان
تصویر مدرک است، پس ماندنشان فقط مسئلهٔ فضا نیست. یک خط به cron اضافه کنید:

```bash
crontab -e
```

و این خط را بنویسید (مسیر را با مسیر خودتان عوض کنید):

```cron
* * * * * cd /home/ali/hana_ai/panel && php artisan schedule:run >> /dev/null 2>&1
```

---

## اولین ورود

**نام کاربری ورود، کد ملی است — نه ایمیل.**

`php artisan db:seed` چهار حساب نمونه می‌سازد، یکی برای هر نقش. رمز هر
چهار حساب همان چیزی است که در `panel/.env` برای `SEED_PASSWORD` گذاشتید
(و اگر آن خط را خالی گذاشتید: `hana@1405`).

| نقش | کد ملی (نام کاربری) | چه می‌بیند |
|---|---|---|
| مدیر سامانه | `0011111119` | همه‌چیز، به‌علاوهٔ کاربران و تنظیمات امتیازدهی |
| کارشناس بررسی | `0022222227` | ویزارد پرونده، صف بررسی، اصلاح فیلد، تصمیم، گزارش |
| کارشناس داده | `0033333335` | دیتاست، تولید انبوه، تگ‌گذاری، خروجی آموزش |
| متقاضی | `0044444443` | فقط ثبت درخواست خودش و دیدن نتیجهٔ آن |

هنگام ورود می‌توانید ارقام را فارسی یا لاتین بنویسید و خط تیره و فاصله هم
مهم نیست؛ سامانه خودش یکسانشان می‌کند.

> این چهار کد ملی **ساختگی‌اند** و فقط رقم کنترلشان معتبر است. روی هر نصبِ
> غیرِ آزمایشی، بعد از اولین ورود از بخش «کاربران» حساب‌های واقعی بسازید و
> این‌ها را غیرفعال کنید.

---

## بررسی اینکه همه‌چیز درست کار می‌کند

### ۱) سلامت موتور — مهم‌ترین بررسی

```bash
cd panel
php artisan hana:engine-check
```

این دستور کل زنجیره را از این سر تا آن سر می‌آزماید: پنل → پل موتور →
پایتون → Tesseract. یک شخص مصنوعی می‌سازد، تصویر کارت ملی‌اش را چاپ
می‌کند، کیفیتش را می‌سنجد و رویش OCR می‌زند. آخرش باید ببینید:

```
INFO  موتور سالم است؛ زنجیرهٔ پنل ← پل ← موتور ← Tesseract کامل کار کرد.
```

### ۲) تست‌های خودکار

```bash
php artisan test
```

باید همه سبز باشند. این تست‌ها روی پایگاه دادهٔ موقت SQLite اجرا می‌شوند و
به دادهٔ واقعی شما دست نمی‌زنند.

### ۳) دیدن سامانه با دادهٔ نمایشی

اگر می‌خواهید بدون بارگذاری دستی مدرک، صفحهٔ نتیجه و صف بررسی را ببینید:

```bash
php artisan db:seed --class=DemoCasesSeeder
```

بیست پروندهٔ نمایشی می‌سازد. هر بار که سناریوی «بررسی انسانی» را اجرا
می‌کنید یکی از پرونده‌های صف مصرف می‌شود؛ برای پر کردن دوباره همین دستور
را بزنید.

---

## مشکلات رایج

| نشانه | علت و راه‌حل |
|---|---|
| پرونده برای همیشه «در حال پردازش» می‌ماند | کارگر صف روشن نیست. گام ۸. |
| بارگذاری فایل خطا می‌دهد یا فایل ذخیره نمی‌شود | مالکیت `panel/storage` با `www-data` نیست. گام ۶. |
| OCR متن فارسی را نمی‌خواند یا خالی برمی‌گرداند | بستهٔ `tesseract-ocr-fas` نصب نیست. با `tesseract --list-langs` بررسی کنید. |
| «Call to undefined method» بعد از تغییر کد | کارگر صف نسخهٔ قدیمی کد را در حافظه دارد؛ دوباره راه بیندازیدش. |
| خطای اتصال به پایگاه داده | `DB_PASSWORD` در `panel/.env` با رمز گام ۴ یکی نیست، یا MariaDB خاموش است. |
| صفحهٔ سفید یا خطای ۵۰۰ | `php artisan key:generate` را فراموش کرده‌اید، یا `storage/logs/laravel.log` را بخوانید. |
| موتور پیدا نمی‌شود / خطای پایتون | `HANA_ENGINE_ROOT` و `HANA_ENGINE_PYTHON` در `.env` مسیر واقعی نیستند. |
| بعد از عوض‌کردن `.env` چیزی تغییر نکرد | `php artisan config:clear` بزنید. |
| Tesseract در مسیر عجیبی نصب شده | متغیر محیطی `TESSERACT_CMD` را روی مسیر اجرایی آن بگذارید. |

لاگ‌ها این‌جا هستند:

```
panel/storage/logs/laravel.log     خطاهای پنل
panel/storage/logs/queue.log       خروجی کارگر صف
```

---

## اجرای موتور به‌تنهایی (بدون پنل)

پایپ‌لاین پایان‌نامه — ساخت داده، چاپ روی قالب، اعوجاج، OCR و گزارش دقت —
مستقل از پنل هم اجرا می‌شود:

```bash
.venv/bin/python main.py                # یک نمونهٔ تازه بساز و نمونه‌های پردازش‌نشده را پردازش کن
.venv/bin/python main.py --number 10    # ده شخص تازه بساز و پردازش کن
.venv/bin/python main.py --all          # پردازش کامل از نو (وقتی الگوریتم عوض شده)
```

اندازه‌گیری دقت روی نمونه‌های تازه:

```bash
.venv/bin/python scripts/benchmark.py --number 25   # ۲۵ نمونهٔ تازه بساز و همان‌ها را بسنج
.venv/bin/python scripts/benchmark.py --last 25     # بدون تولید؛ ۲۵ نمونهٔ آخر
```

> پوشهٔ `dataset/` در `.gitignore` است، پس روی هر ماشین تازه اول باید نمونه
> ساخته شود. و **همیشه با نمونهٔ تازه بسنجید**: نمونه‌های قدیمیِ روی دیسک با
> لیبل‌های زمان خودشان مانده‌اند و عدد را چند واحد پایین‌تر نشان می‌دهند.

---

## ساختار پوشه‌ها

```
hana_ai/
├── app/                  موتور: تولید شخص، چاپ روی قالب، اعوجاج، پیش‌پردازش، OCR
├── dataset/              قالب مدارک و نویسندهٔ تصویر (خروجی‌ها در gitignore)
├── hana_engine/          پل CLI موتور — تنها چیزی که پنل صدا می‌زند
├── fonts/                فونت وزیر برای چاپ روی تصویر
├── scripts/              ابزارهای اندازه‌گیری (benchmark)
├── docs/scenarios/       سناریوهای ویدیویی تست پنل
├── main.py               نقطهٔ ورود پایپ‌لاین موتور
├── requirements.txt      پیش‌نیازهای پایتون
└── panel/                پنل لاراول
    ├── app/Services/HanaEngine.php    تنها پل به موتور
    ├── app/Services/Cases/            پنج مرحلهٔ فرایند مجوز
    ├── routes/areas/                  روت‌ها، هر بخش فایل خودش
    ├── public/assets/app.css          سیستم طراحی (بدون build)
    └── scripts/queue-worker.sh        کارگر صف
```

---

## مستندات بیشتر

جزئیات فنی، تصمیم‌های معماری، اعداد اندازه‌گیری‌شدهٔ دقت و «تله‌هایی که
شکستنشان ساکت است» در [`CLAUDE.md`](CLAUDE.md) نوشته شده — اگر می‌خواهید
روی کد کار کنید، اول آن را بخوانید.
