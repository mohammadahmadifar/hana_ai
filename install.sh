#!/usr/bin/env bash
# ==============================================================
# نصب و راه‌اندازی «هانا» با داکر
#
# روی ویندوز (Docker Desktop + Git Bash یا WSL)، لینوکس و مک کار می‌کند.
# هرچه لازم است — PHP، پایتون، Tesseract، MariaDB، Redis — داخل ظرف
# دانلود و نصب می‌شود؛ روی خود ویندوز هیچ‌کدام را نصب نمی‌کنید.
#
#   bash install.sh                 نصب کامل و بالا آوردن پنل
#   bash install.sh --port 9000     روی پورت دیگری
#   bash install.sh --rebuild       ساخت ایمیج از صفر و بدون کش
#   bash install.sh --fresh         پاک کردن کامل داده‌ها و نصب از صفر
#   bash install.sh --no-verify     بدون تست پایانی موتور (سریع‌تر)
# ==============================================================
set -euo pipefail

# جلوگیری از دست‌کاری مسیرها توسط Git Bash روی ویندوز
export MSYS_NO_PATHCONV=1
export MSYS2_ARG_CONV_EXCL='*'

cd "$(dirname "$0")"

ENV_FILE=".env"
ENV_TEMPLATE=".env.docker.example"

PORT=""
REBUILD=0
FRESH=0
VERIFY=1

# --------------------------------------------------------------
# چاپ
# --------------------------------------------------------------
if [ -t 1 ]; then
    C_OK=$'\033[32m'; C_INFO=$'\033[36m'; C_WARN=$'\033[33m'; C_ERR=$'\033[31m'; C_DIM=$'\033[2m'; C_OFF=$'\033[0m'
else
    C_OK=""; C_INFO=""; C_WARN=""; C_ERR=""; C_DIM=""; C_OFF=""
fi

step() { printf '%s\n%s==>%s %s\n' "" "$C_INFO" "$C_OFF" "$*"; }
ok()   { printf '%s  ✓%s %s\n' "$C_OK" "$C_OFF" "$*"; }
warn() { printf '%s  !%s %s\n' "$C_WARN" "$C_OFF" "$*" >&2; }
die()  { printf '\n%sخطا:%s %s\n' "$C_ERR" "$C_OFF" "$*" >&2; exit 1; }

usage() {
    # همان بلوک توضیح بالای همین فایل، بدون خط‌های تزئینی
    awk '
        NR == 1 { next }
        /^#/ { line = $0; sub(/^# ?/, "", line); if (line !~ /^=+$/) print line; next }
        { exit }
    ' "$0"
    exit 0
}

# --------------------------------------------------------------
# آرگومان‌ها
# --------------------------------------------------------------
while [ $# -gt 0 ]; do
    case "$1" in
        --port) PORT="${2:-}"; [ -n "$PORT" ] || die "بعد از --port یک شمارهٔ پورت بنویسید."; shift 2 ;;
        --port=*) PORT="${1#*=}"; shift ;;
        --rebuild) REBUILD=1; shift ;;
        --fresh) FRESH=1; REBUILD=1; shift ;;
        --no-verify) VERIFY=0; shift ;;
        -h|--help) usage ;;
        *) die "گزینهٔ ناشناخته: $1  (راهنما: bash install.sh --help)" ;;
    esac
done

if [ -n "$PORT" ] && ! printf '%s' "$PORT" | grep -Eq '^[0-9]{2,5}$'; then
    die "پورت باید عدد باشد: $PORT"
fi

# --------------------------------------------------------------
# ۱) داکر
# --------------------------------------------------------------
step "بررسی داکر"

command -v docker >/dev/null 2>&1 || die "داکر پیدا نشد.
روی ویندوز Docker Desktop را نصب و اجرا کنید: https://www.docker.com/products/docker-desktop
بعد همین اسکریپت را دوباره بزنید."

if docker compose version >/dev/null 2>&1; then
    COMPOSE=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
    COMPOSE=(docker-compose)
else
    die "docker compose پیدا نشد. Docker Desktop را به‌روز کنید."
fi

docker info >/dev/null 2>&1 || die "سرویس داکر بالا نیست.
روی ویندوز، Docker Desktop را باز کنید و منتظر بمانید تا وضعیتش سبز شود (Engine running)."

ok "داکر: $(docker version --format '{{.Server.Version}}' 2>/dev/null || echo '؟')"
ok "کامپوز: $("${COMPOSE[@]}" version --short 2>/dev/null || echo '؟')"

# --------------------------------------------------------------
# ۲) فایل .env
# --------------------------------------------------------------
step "آماده‌سازی فایل تنظیمات (.env)"

random_secret() {
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -hex 16
    elif [ -r /dev/urandom ]; then
        LC_ALL=C tr -dc 'a-zA-Z0-9' < /dev/urandom 2>/dev/null | head -c 24 || true
    else
        die "برای ساخت رمز تصادفی، openssl یا /dev/urandom لازم است."
    fi
}

random_app_key() {
    local raw
    if command -v openssl >/dev/null 2>&1; then
        raw="$(openssl rand -base64 32)"
    else
        raw="$(head -c 32 /dev/urandom | base64)"
    fi
    printf 'base64:%s' "$(printf '%s' "$raw" | tr -d '\r\n')"
}

# نوشتن یک کلید در .env بدون sed -i (رفتارش روی مک و ویندوز یکی نیست)
set_env_var() {
    local key="$1" value="$2" tmp="${ENV_FILE}.tmp.$$"

    if grep -q "^${key}=" "$ENV_FILE" 2>/dev/null; then
        awk -v k="$key" -v v="$value" '
            index($0, k "=") == 1 { print k "=" v; next }
            { print }
        ' "$ENV_FILE" > "$tmp"
        mv "$tmp" "$ENV_FILE"
    else
        printf '%s=%s\n' "$key" "$value" >> "$ENV_FILE"
    fi
}

get_env_var() {
    grep "^$1=" "$ENV_FILE" 2>/dev/null | tail -n 1 | cut -d= -f2- || true
}

# اگر خالی بود پرش کن، اگر پر بود دست نزن — دومین اجرای اسکریپت نباید
# رمزها را عوض کند وگرنه پنل به پایگاه دادهٔ موجود وصل نمی‌شود.
fill_if_empty() {
    local key="$1" value="$2"
    if [ -z "$(get_env_var "$key")" ]; then
        set_env_var "$key" "$value"
        return 0
    fi
    return 1
}

if [ ! -f "$ENV_FILE" ]; then
    [ -f "$ENV_TEMPLATE" ] || die "فایل $ENV_TEMPLATE پیدا نشد. مخزن ناقص کلون شده است."
    cp "$ENV_TEMPLATE" "$ENV_FILE"
    ok "$ENV_FILE از روی نمونه ساخته شد"
else
    ok "$ENV_FILE از قبل وجود دارد — مقدارهای پرشده دست نمی‌خورند"
fi

fill_if_empty APP_KEY          "$(random_app_key)"  && ok "کلید برنامه ساخته شد"
fill_if_empty DB_PASSWORD      "$(random_secret)"   && ok "رمز پایگاه داده ساخته شد"
fill_if_empty DB_ROOT_PASSWORD "$(random_secret)"   && ok "رمز root پایگاه داده ساخته شد"
fill_if_empty SEED_PASSWORD    "$(random_secret)"   && ok "رمز حساب‌های نمونه ساخته شد"

if [ -n "$PORT" ]; then
    set_env_var HANA_PORT "$PORT"
fi

APP_PORT="$(get_env_var HANA_PORT)"
APP_PORT="${APP_PORT:-8101}"
SEED_PASSWORD_VALUE="$(get_env_var SEED_PASSWORD)"

# --------------------------------------------------------------
# ۳) پاک‌سازی کامل (--fresh)
# --------------------------------------------------------------
if [ "$FRESH" -eq 1 ]; then
    step "پاک کردن ظرف‌ها و داده‌های قبلی"
    printf '%sهمهٔ داده‌های پنل (پرونده‌ها، مدارک، دیتاست تولیدشده) پاک می‌شود.%s\n' "$C_WARN" "$C_OFF"
    printf 'برای ادامه «yes» بنویسید: '
    read -r answer
    [ "$answer" = "yes" ] || die "لغو شد."
    "${COMPOSE[@]}" down --volumes --remove-orphans || true
    ok "پاک شد"
fi

# --------------------------------------------------------------
# ۴) ساخت ایمیج
#
# اولین اجرا طول می‌کشد (چند صد مگابایت دانلود: دبیان، PHP، پایتون،
# Tesseract، کتابخانه‌های موتور). اجراهای بعدی از کش استفاده می‌کنند.
# --------------------------------------------------------------
step "ساخت ایمیج (بار اول چند دقیقه طول می‌کشد)"

BUILD_ARGS=()
[ "$REBUILD" -eq 1 ] && BUILD_ARGS+=(--no-cache)

# فقط سرویس app ساخته می‌شود؛ worker و scheduler همین ایمیج را دارند
# (hana-ai:latest) و دوباره ساختنشان فقط وقت تلف کردن است.
# شکل ${ARR[@]+...} برای bash قدیمی مک است که آرایهٔ خالی را با set -u
# «متغیر تعریف‌نشده» می‌بیند.
"${COMPOSE[@]}" build ${BUILD_ARGS[@]+"${BUILD_ARGS[@]}"} app || die "ساخت ایمیج شکست خورد.
اگر خطا دربارهٔ دانلود بسته است، اتصال اینترنت (یا تحریم‌شکن داکر) را بررسی کنید و دوباره بزنید."
ok "ایمیج ساخته شد"

# --------------------------------------------------------------
# ۵) بالا آوردن سرویس‌ها
# --------------------------------------------------------------
step "بالا آوردن سرویس‌ها (db، redis، app، worker، scheduler)"
"${COMPOSE[@]}" up -d --remove-orphans || die "بالا آوردن سرویس‌ها شکست خورد."
ok "سرویس‌ها روشن شدند"

# --------------------------------------------------------------
# ۶) صبر تا آماده شدن پنل
#
# بار اول کمی طول می‌کشد: MariaDB باید پایگاه داده را بسازد و ظرف app
# مهاجرت‌ها و دادهٔ اولیه را وارد کند.
# --------------------------------------------------------------
step "در انتظار آماده شدن پنل"

APP_CID="$("${COMPOSE[@]}" ps -q app 2>/dev/null || true)"
[ -n "$APP_CID" ] || die "ظرف app ساخته نشد. لاگ: ${COMPOSE[*]} logs app"

for attempt in $(seq 1 90); do
    health="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$APP_CID" 2>/dev/null || echo "gone")"
    state="$(docker inspect -f '{{.State.Status}}' "$APP_CID" 2>/dev/null || echo "gone")"

    if [ "$health" = "healthy" ]; then
        ok "پنل بالا آمد"
        break
    fi

    if [ "$state" = "exited" ] || [ "$state" = "dead" ]; then
        "${COMPOSE[@]}" logs --tail 40 app || true
        die "ظرف app خاموش شد. لاگ بالا را ببینید."
    fi

    if [ "$attempt" -eq 90 ]; then
        "${COMPOSE[@]}" logs --tail 40 app || true
        die "پنل در زمان مقرر بالا نیامد. لاگ بالا را ببینید."
    fi

    printf '%s  … %s ثانیه%s\r' "$C_DIM" "$((attempt * 4))" "$C_OFF"
    sleep 4
done

# --------------------------------------------------------------
# ۷) بررسی سلامت موتور — مهم‌ترین تست
#
# زنجیرهٔ کامل را می‌آزماید: PHP → cli.py → app/*.py → Tesseract
# (خواندن نسخه، ساخت یک تصویر واقعی، OCR همان تصویر).
# --------------------------------------------------------------
if [ "$VERIFY" -eq 1 ]; then
    step "بررسی سلامت موتور (ساخت تصویر + OCR واقعی)"

    if "${COMPOSE[@]}" exec -T -u www-data app php /app/panel/artisan hana:engine-check; then
        ok "موتور سالم است"
    else
        warn "بررسی سلامت موتور رد شد. پنل بالاست ولی OCR کار نمی‌کند."
        warn "لاگ: ${COMPOSE[*]} logs app"
        exit 1
    fi
fi

# --------------------------------------------------------------
# پایان
# --------------------------------------------------------------
cat <<SUMMARY

${C_OK}نصب تمام شد.${C_OFF}

  آدرس پنل      http://localhost:${APP_PORT}

  ورود با کد ملی (نه ایمیل) — رمز هر چهار حساب یکی است:

    مدیر سامانه      0011111119
    کارشناس بررسی    0022222227
    کارشناس داده     0033333335
    متقاضی نمونه     0044444443

    رمز              ${SEED_PASSWORD_VALUE}

  ${C_DIM}(همین رمز داخل فایل .env کنار همین اسکریپت هم هست)${C_OFF}

دستورهای روزمره:

  ${COMPOSE[*]} ps                     وضعیت سرویس‌ها
  ${COMPOSE[*]} logs -f app            لاگ پنل
  ${COMPOSE[*]} logs -f worker         لاگ کارگر صف
  ${COMPOSE[*]} stop                   خاموش کردن
  ${COMPOSE[*]} up -d                  روشن کردن دوباره
  bash install.sh                       بعد از تغییر کد (دوباره می‌سازد و بالا می‌آورد)

  ${COMPOSE[*]} exec -u www-data app php /app/panel/artisan hana:engine-check
  ${COMPOSE[*]} exec -u www-data app /opt/venv/bin/python main.py --number 10

SUMMARY
