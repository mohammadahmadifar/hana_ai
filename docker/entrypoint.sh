#!/usr/bin/env bash
# ------------------------------------------------------------------
# ورودی ظرف «هانا»
#
# هر سه نقش (web، worker، scheduler) از همین فایل بالا می‌آیند. کاری که
# اینجا انجام می‌شود همان گام‌های ۵ تا ۸ راهنمای نصب است، فقط خودکار:
#
#   ۱) ساخت پوشه‌های ناموجود و درست کردن مالکیت
#   ۲) نوشتن panel/.env از روی متغیرهای محیطی ظرف
#   ۳) صبر تا بالا آمدن MariaDB و Redis
#   ۴) migrate و (فقط بار اول) db:seed
#   ۵) اجرای فرمان نقش
#
# panel/.env در هر بالا آمدن دوباره نوشته می‌شود؛ منبع حقیقت، فایل .env
# کنار docker-compose.yml است. ویرایش .env داخل ظرف بی‌فایده است.
# ------------------------------------------------------------------
set -euo pipefail

PANEL_DIR=/app/panel
ARTISAN="$PANEL_DIR/artisan"

log()  { printf '\033[36m[hana]\033[0m %s\n' "$*"; }
warn() { printf '\033[33m[hana]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[31m[hana] %s\033[0m\n' "$*" >&2; exit 1; }

# اجرای دستور با کاربر www-data: فایلی که کارگر صف یا موتور می‌سازد باید
# برای وب‌سرور هم قابل نوشتن باشد (تلهٔ «آپلود بی‌صدا می‌شکند»).
if command -v setpriv >/dev/null 2>&1; then
    AS_WWW=(setpriv --reuid=www-data --regid=www-data --init-groups)
else
    AS_WWW=(runuser -u www-data --)
fi

artisan_www() { "${AS_WWW[@]}" php "$ARTISAN" "$@"; }

# ------------------------------------------------------------------
# ۱) پوشه‌ها و مالکیت
#
# ولوم تازه خالی است و پوشه‌های storage/framework در گیت نیستند؛ بدون
# این بخش، لاراول سر نشست و کش می‌ترکد.
# ------------------------------------------------------------------
prepare_dirs() {
    mkdir -p \
        "$PANEL_DIR/storage/app/public" "$PANEL_DIR/storage/app/private" \
        "$PANEL_DIR/storage/framework/cache/data" "$PANEL_DIR/storage/framework/sessions" \
        "$PANEL_DIR/storage/framework/testing" "$PANEL_DIR/storage/framework/views" \
        "$PANEL_DIR/storage/logs" "$PANEL_DIR/bootstrap/cache" \
        /app/dataset/generated /app/dataset/processed /app/dataset/preprocessed \
        /app/dataset/ocr_results /app/dataset/labels /app/dataset/benchmark

    chown -R www-data:www-data \
        "$PANEL_DIR/storage" "$PANEL_DIR/bootstrap/cache" /app/dataset
    chmod -R 775 "$PANEL_DIR/storage" "$PANEL_DIR/bootstrap/cache"
}

# ------------------------------------------------------------------
# ۲) کلید برنامه
#
# اگر APP_KEY در .env بیرونی خالی باشد، یک کلید می‌سازیم و در ولوم
# storage نگه می‌داریم. اگر هر بار کلید تازه بسازیم، نشست‌های باز و هر
# مقدار رمزنگاری‌شده‌ای بی‌اعتبار می‌شود.
# ------------------------------------------------------------------
resolve_app_key() {
    local key_file="$PANEL_DIR/storage/app/.appkey"

    if [ -n "${APP_KEY:-}" ]; then
        return
    fi

    if [ -s "$key_file" ]; then
        APP_KEY="$(cat "$key_file")"
    else
        APP_KEY="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
        printf '%s' "$APP_KEY" > "$key_file"
        chown www-data:www-data "$key_file"
        chmod 600 "$key_file"
        log "کلید برنامه ساخته شد (storage/app/.appkey)"
    fi

    export APP_KEY
}

# ------------------------------------------------------------------
# ۳) نوشتن panel/.env
#
# REDIS_PASSWORD عمداً فقط اینجاست و متغیر محیطی نمی‌شود: لاراول رشتهٔ
# «null» را داخل فایل .env به null تبدیل می‌کند، ولی متغیر محیطی واقعی
# را رشته می‌بیند و با رمزِ «null» به ردیس AUTH می‌زند.
# ------------------------------------------------------------------
write_env_file() {
    local tmp="$PANEL_DIR/.env.tmp.$$"

    cat > "$tmp" <<ENVFILE
# ==============================================================
# این فایل را دست نزنید — ورودی ظرف در هر بالا آمدن بازنویسی‌اش می‌کند.
# برای تغییر تنظیمات، فایل .env کنار docker-compose.yml را عوض کنید و
# بعد: docker compose up -d
# ==============================================================

APP_NAME="${APP_NAME:-سامانه پیش‌اعتبارسنجی مجوز}"
APP_ENV=${APP_ENV:-production}
APP_KEY=${APP_KEY}
APP_DEBUG=${APP_DEBUG:-false}
APP_TIMEZONE=${APP_TIMEZONE:-Asia/Tehran}
APP_URL=${APP_URL:-http://localhost:8101}

APP_LOCALE=fa
APP_FALLBACK_LOCALE=fa
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=${BCRYPT_ROUNDS:-12}

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=${LOG_LEVEL:-info}

DB_CONNECTION=mysql
DB_HOST=${DB_HOST:-db}
DB_PORT=${DB_PORT:-3306}
DB_DATABASE=${DB_DATABASE:-hana}
DB_USERNAME=${DB_USERNAME:-hana}
DB_PASSWORD=${DB_PASSWORD:-}

QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
CACHE_STORE=redis

SESSION_LIFETIME=${SESSION_LIFETIME:-120}
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

REDIS_CLIENT=phpredis
REDIS_HOST=${REDIS_HOST:-redis}
REDIS_PASSWORD=null
REDIS_PORT=${REDIS_PORT:-6379}

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local

HANA_ENGINE_ROOT=${HANA_ENGINE_ROOT:-/app}
HANA_ENGINE_PYTHON=${HANA_ENGINE_PYTHON:-/opt/venv/bin/python}
HANA_ENGINE_TIMEOUT=${HANA_ENGINE_TIMEOUT:-300}
HANA_EVALUATE_WORKERS=${HANA_EVALUATE_WORKERS:-8}
HANA_EVALUATE_CHUNK=${HANA_EVALUATE_CHUNK:-40}
HANA_PRUNE_DAYS=${HANA_PRUNE_DAYS:-7}

SEED_PASSWORD=${SEED_PASSWORD:-}

MAIL_MAILER=log
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="\${APP_NAME}"
ENVFILE

    chown www-data:www-data "$tmp"
    chmod 640 "$tmp"
    mv "$tmp" "$PANEL_DIR/.env"
}

# ------------------------------------------------------------------
# ۴) صبر تا آماده شدن سرویس‌ها
# ------------------------------------------------------------------
wait_for_db() {
    local tries=${HANA_WAIT_TRIES:-60}

    log "در انتظار پایگاه داده (${DB_HOST:-db}:${DB_PORT:-3306}) …"

    for _ in $(seq 1 "$tries"); do
        if php -r '
            $dsn = sprintf("mysql:host=%s;port=%s;dbname=%s",
                getenv("DB_HOST") ?: "db",
                getenv("DB_PORT") ?: "3306",
                getenv("DB_DATABASE") ?: "hana");
            try { new PDO($dsn, getenv("DB_USERNAME") ?: "hana", getenv("DB_PASSWORD") ?: "",
                [PDO::ATTR_TIMEOUT => 3]); exit(0); }
            catch (Throwable $e) { exit(1); }
        ' 2>/dev/null; then
            return 0
        fi
        sleep 2
    done

    die "پایگاه داده بالا نیامد. لاگ سرویس db را ببینید: docker compose logs db"
}

wait_for_redis() {
    local tries=${HANA_WAIT_TRIES:-60}

    log "در انتظار Redis (${REDIS_HOST:-redis}:${REDIS_PORT:-6379}) …"

    for _ in $(seq 1 "$tries"); do
        if php -r '
            $fp = @fsockopen(getenv("REDIS_HOST") ?: "redis", (int) (getenv("REDIS_PORT") ?: 6379), $e, $s, 3);
            if (! $fp) { exit(1); }
            fwrite($fp, "PING\r\n");
            $line = fgets($fp);
            fclose($fp);
            exit(str_starts_with((string) $line, "+PONG") ? 0 : 1);
        ' 2>/dev/null; then
            return 0
        fi
        sleep 2
    done

    die "Redis بالا نیامد. لاگ سرویس redis را ببینید: docker compose logs redis"
}

# کارگر و زمان‌بند نباید قبل از مهاجرت‌های نقش web کار کنند
wait_for_migrations() {
    local tries=${HANA_WAIT_TRIES:-60}

    for _ in $(seq 1 "$tries"); do
        if php -r '
            $dsn = sprintf("mysql:host=%s;port=%s;dbname=%s",
                getenv("DB_HOST") ?: "db",
                getenv("DB_PORT") ?: "3306",
                getenv("DB_DATABASE") ?: "hana");
            try {
                $pdo = new PDO($dsn, getenv("DB_USERNAME") ?: "hana", getenv("DB_PASSWORD") ?: "",
                    [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $pdo->query("SELECT 1 FROM users LIMIT 1");
                exit(0);
            } catch (Throwable $e) { exit(1); }
        ' 2>/dev/null; then
            return 0
        fi
        sleep 2
    done

    warn "جدول‌ها هنوز ساخته نشده‌اند؛ به هر حال ادامه می‌دهیم."
}

# ------------------------------------------------------------------
# ۵) مهاجرت و دادهٔ اولیه
#
# دانه‌کاری فقط بار اول: سیدر رمز حساب‌های نمونه را دوباره می‌نویسد، پس
# اجرای هر بارهٔ آن یعنی رمزی که کاربر عوض کرده در هر ری‌استارت برگردد.
# HANA_SEED=always یا never این رفتار را عوض می‌کند.
# ------------------------------------------------------------------
migrate_and_seed() {
    log "اجرای مهاجرت‌ها …"
    artisan_www migrate --force

    local marker="$PANEL_DIR/storage/app/.seeded"
    local mode="${HANA_SEED:-auto}"

    case "$mode" in
        never) return ;;
        always) ;;
        *) [ -f "$marker" ] && return ;;
    esac

    log "وارد کردن دادهٔ مرجع و حساب‌های نمونه …"
    artisan_www db:seed --force
    : > "$marker"
    chown www-data:www-data "$marker"
}

# ------------------------------------------------------------------

main() {
    local role="${1:-${HANA_ROLE:-web}}"

    prepare_dirs
    resolve_app_key
    write_env_file
    wait_for_db
    wait_for_redis

    case "$role" in
        web)
            migrate_and_seed
            artisan_www storage:link >/dev/null 2>&1 || true
            artisan_www optimize:clear >/dev/null || warn "پاک‌سازی کش لاراول انجام نشد"
            log "پنل آماده است — nginx و php-fpm بالا می‌آیند"
            exec /usr/bin/supervisord -c /etc/supervisor/hana.conf
            ;;
        worker)
            wait_for_migrations
            log "کارگر صف (ocr، generate، default)"
            exec "${AS_WWW[@]}" bash "$PANEL_DIR/scripts/queue-worker.sh"
            ;;
        scheduler)
            wait_for_migrations
            log "زمان‌بند لاراول"
            exec "${AS_WWW[@]}" php "$ARTISAN" schedule:work
            ;;
        *)
            # هر چیز دیگری یعنی «همین دستور را اجرا کن»، مثل
            #   docker compose run --rm app php artisan hana:engine-check
            exec "$@"
            ;;
    esac
}

main "$@"
