# syntax=docker/dockerfile:1

# ==============================================================
# تصویر داکر پروژهٔ «هانا» — هر دو نیمهٔ پروژه در یک ایمیج
#
#   موتور پایتون : /opt/venv  +  Tesseract 5 با دیتای fas و eng
#   پنل لاراول   : php-fpm + nginx روی /app/panel/public
#
# چرا یک ایمیج و نه دوتا: «قانون طلایی» پروژه می‌گوید پنل موتور را با
# اجرای «python -m hana_engine.cli» روی همان ماشین صدا می‌زند (کلاس
# HanaEngine). پس PHP و پایتون باید کنار هم و در یک فایل‌سیستم باشند.
#
# همین یک ایمیج سه نقش می‌گیرد؛ نقش، آرگومان ENTRYPOINT است:
#   web        → nginx + php-fpm  (زیر نظر supervisord)
#   worker     → کارگر صف (بدون این، OCR و تولید انبوه اجرا نمی‌شود)
#   scheduler  → زمان‌بند لاراول (جای cron گام ۹ راهنمای نصب)
#
# پایهٔ Debian 13 (trixie) انتخاب شده چون دقیقاً همان نسخه‌هایی را دارد
# که عددهای پایان‌نامه رویشان اندازه‌گیری شده: PHP 8.4، Python 3.13،
# Tesseract 5.5.
# ==============================================================

FROM composer:2 AS composer_bin

FROM debian:trixie-slim

ENV DEBIAN_FRONTEND=noninteractive \
    LANG=C.UTF-8 \
    LC_ALL=C.UTF-8 \
    TZ=Asia/Tehran \
    PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONIOENCODING=utf-8 \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

# --------------------------------------------------------------
# بسته‌های سیستمی — همان فهرست گام ۱ راهنمای نصب
#
# نام بسته‌های PHP بدون شماره نوشته شده تا به نسخهٔ پیش‌فرض توزیع
# وصل شوند؛ مسیرهای نسخه‌دار پایین‌تر در زمان ساخت کشف می‌شوند.
# --------------------------------------------------------------
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates curl unzip git tzdata supervisor nginx \
        php-cli php-fpm php-mysql php-redis php-mbstring php-xml \
        php-curl php-zip php-gd php-intl php-bcmath php-sqlite3 php-opcache \
        python3 python3-venv \
        tesseract-ocr tesseract-ocr-fas tesseract-ocr-eng \
    ; \
    # چرخ opencv به libglib وصل است. نام بسته در دبیان ۱۳ پسوند t64 گرفته،
    # پس اول نام تازه و بعد نام قدیمی امتحان می‌شود.
    apt-get install -y --no-install-recommends libglib2.0-0t64 \
        || apt-get install -y --no-install-recommends libglib2.0-0; \
    rm -rf /var/lib/apt/lists/*

# --------------------------------------------------------------
# پیکربندی php-fpm و nginx
#
# مسیر تنظیمات PHP نسخه‌دار است (/etc/php/8.4/...) ولی شماره را
# هاردکد نمی‌کنیم؛ از خود php می‌پرسیم تا ارتقای توزیع ایمیج را نشکند.
# --------------------------------------------------------------
COPY docker/php.ini /tmp/hana-php.ini
COPY docker/php-fpm-pool.conf /tmp/hana-pool.conf
COPY docker/nginx.conf /etc/nginx/conf.d/hana.conf
COPY docker/supervisord.conf /etc/supervisor/hana.conf

RUN set -eux; \
    PHPVER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"; \
    cp /tmp/hana-php.ini "/etc/php/${PHPVER}/fpm/conf.d/99-hana.ini"; \
    cp /tmp/hana-php.ini "/etc/php/${PHPVER}/cli/conf.d/99-hana.ini"; \
    rm -f "/etc/php/${PHPVER}/fpm/pool.d/www.conf"; \
    cp /tmp/hana-pool.conf "/etc/php/${PHPVER}/fpm/pool.d/hana.conf"; \
    ln -sf "/usr/sbin/php-fpm${PHPVER}" /usr/local/bin/php-fpm; \
    rm -f /tmp/hana-php.ini /tmp/hana-pool.conf /etc/nginx/sites-enabled/default; \
    mkdir -p /run/php; \
    php-fpm --version

# --------------------------------------------------------------
# موتور پایتون — محیط مجازی بیرون از /app می‌نشیند تا اگر کسی سورس را
# روی /app مانت کرد، کتابخانه‌ها از بین نروند.
#
# روی سرور بی‌کارت‌گرافیک باید نسخهٔ headless نصب شود (همان هشدار
# requirements.txt)، پس همین‌جا جایگزین می‌شود.
# --------------------------------------------------------------
ENV VIRTUAL_ENV=/opt/venv
COPY requirements.txt /tmp/requirements.txt
RUN set -eux; \
    python3 -m venv "$VIRTUAL_ENV"; \
    "$VIRTUAL_ENV/bin/pip" install --no-cache-dir --upgrade pip; \
    sed 's/^opencv-python\b/opencv-python-headless/' /tmp/requirements.txt > /tmp/requirements-docker.txt; \
    "$VIRTUAL_ENV/bin/pip" install --no-cache-dir -r /tmp/requirements-docker.txt; \
    "$VIRTUAL_ENV/bin/python" -c "import cv2, numpy, PIL, pytesseract, faker, jdatetime"; \
    tesseract --list-langs; \
    rm -f /tmp/requirements.txt /tmp/requirements-docker.txt

# --------------------------------------------------------------
# کتابخانه‌های پنل
#
# اول فقط composer.json/lock کپی می‌شود تا لایهٔ vendor با هر تغییر کد
# دوباره ساخته نشود. dev-dependency‌ها هم نصب می‌شوند چون راهنمای نصب
# اجرای «php artisan test» را جزو بررسی سلامت آورده است.
# --------------------------------------------------------------
COPY --from=composer_bin /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

COPY panel/composer.json panel/composer.lock /app/panel/
RUN composer install --working-dir=/app/panel --prefer-dist --no-scripts --no-autoloader

COPY . /app
COPY docker/entrypoint.sh /usr/local/bin/hana-entrypoint

# اسکلت پوشه‌ها باید قبل از composer آماده باشد: آخرین کار نصب،
# «artisan package:discover» است و بدون bootstrap/cache می‌ترکد.
# (این پوشه‌ها در گیت نیستند چون خروجی اجرای برنامه‌اند.)
RUN set -eux; \
    mkdir -p \
        /app/panel/storage/app/public /app/panel/storage/app/private \
        /app/panel/storage/framework/cache/data /app/panel/storage/framework/sessions \
        /app/panel/storage/framework/testing /app/panel/storage/framework/views \
        /app/panel/storage/logs /app/panel/bootstrap/cache \
        /app/dataset/generated /app/dataset/processed /app/dataset/preprocessed \
        /app/dataset/ocr_results /app/dataset/labels /app/dataset/benchmark; \
    # تنظیمات ماشین توسعه هرگز نباید داخل ایمیج بماند؛ ورودی ظرف
    # خودش .env تازه می‌نویسد. (.dockerignore هم همین را می‌گوید.)
    rm -f /app/panel/.env; \
    # اگر مخزن روی ویندوز کلون شده باشد، اسکریپت‌ها CRLF می‌گیرند و
    # کرنل «bad interpreter» می‌دهد. .gitattributes جلویش را می‌گیرد،
    # این خط هم بیمهٔ دوم است.
    sed -i 's/\r$//' /usr/local/bin/hana-entrypoint /app/panel/scripts/queue-worker.sh; \
    chmod +x /usr/local/bin/hana-entrypoint /app/panel/scripts/queue-worker.sh

RUN set -eux; \
    composer install --working-dir=/app/panel --prefer-dist --optimize-autoloader; \
    composer clear-cache; \
    chown -R www-data:www-data /app; \
    chmod -R 775 /app/panel/storage /app/panel/bootstrap/cache

# --------------------------------------------------------------
# مقدارهایی که در ظرف همیشه ثابت‌اند (پل پنل ↔ موتور)
# --------------------------------------------------------------
ENV HANA_ENGINE_PYTHON=/opt/venv/bin/python \
    HANA_ENGINE_ROOT=/app \
    TESSERACT_CMD=/usr/bin/tesseract

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/hana-entrypoint"]
CMD ["web"]
