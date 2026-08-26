#!/usr/bin/env bash
# اجرای محلی یک سناریوی آزمایشگاه تست، بدون عبور از صف پنل یارپی‌ام.
# چرا لازم است: پنل سناریو را در قالب «engine array» می‌فرستد (baseUrl و id)،
# ولی فایل سناریو اسکیمای مستندشده را دارد (base_url و key). این اسکریپت
# همان تبدیل را انجام می‌دهد تا موتور مستقیم قابل اجرا باشد.
#
#   bash run-local.sh <scenario.json> <out-dir>
set -euo pipefail

SCEN="$1"
OUT="$2"
PM=/home/coder/pmyarcube

mkdir -p "$OUT"
ENGINE="$OUT/scenario.engine.json"

python3 - "$SCEN" "$ENGINE" <<'PY'
import json, sys, os
src, dst = sys.argv[1], sys.argv[2]
s = json.load(open(src, encoding='utf8'))
out = {
    'id': s['key'],
    'title': s['title'],
    'mode': s.get('mode', 'functional'),
    'baseUrl': s['base_url'].rstrip('/'),
    'video': s.get('video', True),
    'viewport': s.get('viewport', {'width': 1920, 'height': 1080}),
    'auth': s.get('auth') or {'type': 'none'},
    'steps': s.get('steps', []),
    'captions': s.get('captions', True),
}
# پیش‌اجرای سریع برای پیدا کردن سلکتور خراب: SCEN_DRY=1 → بدون ویدیو و بدون مکث
if os.environ.get('SCEN_DRY') == '1':
    out['video'] = False
    out['slowMo'] = 0
json.dump(out, open(dst, 'w', encoding='utf8'), ensure_ascii=False, indent=1)
print(f"engine scenario -> {dst}  ({len(out['steps'])} steps)")
PY

# از تسک ۷۴۰ نام کاربری ورود کد ملی است، نه ایمیل — پس سناریوها
# ${HANA_ADMIN_NATIONAL_ID} می‌خواهند و همین‌جا هم همان لازم است.
: "${HANA_ADMIN_NATIONAL_ID:?لازم است}"
: "${HANA_ADMIN_PASSWORD:?لازم است}"

TESTLAB_SECRETS="$(python3 -c 'import json,os;print(json.dumps({"HANA_ADMIN_NATIONAL_ID":os.environ["HANA_ADMIN_NATIONAL_ID"],"HANA_ADMIN_PASSWORD":os.environ["HANA_ADMIN_PASSWORD"]}))')" \
PLAYWRIGHT_BROWSERS_PATH=/ms-playwright \
TESTLAB_FFMPEG="$PM/node_modules/ffmpeg-static/ffmpeg" \
TESTLAB_FONT="$PM/public/fonts/vazirmatn-arabic.woff2" \
TESTLAB_FIXTURES="$PM/storage/app/test-fixtures" \
TESTLAB_SESSIONS="$OUT/_sessions" \
HOME="$PM/storage/app" \
node "$PM/scripts/runner/run-scenario.mjs" --scenario "$ENGINE" --out "$OUT" \
  | tee "$OUT/events.ndjson" \
  | python3 -u -c '
import sys, json
for line in sys.stdin:
    line = line.strip()
    if not line.startswith("{"): continue
    try: e = json.loads(line)
    except Exception: continue
    ev = e.get("event")
    if ev == "step":
        mark = {"ok":"OK ","warn":"WARN","skipped":"SKIP","failed":"FAIL"}.get(e.get("status"), "?")
        err = f'"'"'   << {e.get("error","")[:150]}'"'"' if e.get("error") else ""
        print(f'"'"'{mark} #{e.get("i"):>2} {e.get("action"):<10} {e.get("caption","")[:64]}{err}'"'"', flush=True)
    elif ev in ("start","fatal","done","video","session-saved","trace-suppressed"):
        print(ev.upper(), json.dumps({k:v for k,v in e.items() if k!="event"}, ensure_ascii=False)[:400], flush=True)
'
