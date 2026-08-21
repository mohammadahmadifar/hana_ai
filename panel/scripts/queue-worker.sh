#!/usr/bin/env bash
# کارگر صف پنل hana — کارهای سنگین (OCR، تولید انبوه) اینجا اجرا می‌شوند.
# اجرا:  nohup bash scripts/queue-worker.sh > storage/logs/queue.log 2>&1 &
# توقف:  pkill -f "artisan queue:work.*hana"
set -euo pipefail
cd "$(dirname "$0")/.."

while true; do
    php artisan queue:work redis \
        --queue=ocr,generate,default \
        --sleep=2 \
        --tries=2 \
        --timeout=600 \
        --max-jobs=200 \
        --max-time=3600 \
        --name=hana || true
    sleep 3
done
