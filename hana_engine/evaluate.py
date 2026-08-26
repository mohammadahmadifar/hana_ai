"""
ارزیابی دسته‌ای دقت: «بساز → بخوان» برای چند مدرک، در یک پروسه و چند هسته.

### چرا این دستور جدا لازم شد (تسک ۷۲۶)
صفحهٔ «ارزیابی دقت» پنل باید تا هزار تصویر بسازد، هرکدام را OCR کند و درصد
درستی نهایی را دربیاورد. با دستورهای موجود هر تصویر دو فراخوانی جدا لازم داشت
(`render_document` و بعد `ocr_document`)، یعنی دو پروسهٔ پایتون و دو بار
بارگذاری OpenCV و Tesseract به‌ازای هر تصویر. اندازه‌گیری روی همین سرور:

    سریالی، دو فراخوانی جدا  →  ۳٫۱ ثانیه برای هر تصویر  →  هزار تصویر ≈ ۵۲ دقیقه
    همین‌جا، استخر ۸ کارگره  →  ۰٫۸۶ ثانیه برای هر تصویر  →  هزار تصویر ≈ ۱۴ دقیقه

استخر بزرگ‌تر بهتر نیست: با ۱۲ کارگر روی همین ماشین ۱۶ هسته‌ای عدد به ۲٫۰
ثانیه بدتر شد (رقابت بر سر حافظه و هسته با بقیهٔ سرویس‌ها).

### مرزها
- **قضاوت این‌جا نیست.** این دستور فقط «متن چاپ‌شده» و «متن خوانده‌شده» را
  برمی‌گرداند؛ مقایسه و درصددهی کار `FieldExtractor` و پنل است. موتور نباید
  دربارهٔ درستی فیلد تصمیم بگیرد، وگرنه دو جای متفاوت دو تعریف از «درست» پیدا
  می‌کنند.
- **تصویرها پاک می‌شوند** مگر خودِ آیتم `keep` بخواهد. هزار تصویر یعنی حدود دو
  گیگابایت؛ نگه‌داشتنشان برای عددی که همان لحظه محاسبه می‌شود بی‌معناست.
- خطای یک تصویر فقط همان تصویر را می‌سوزاند (`ok: false` با پیام)، نه کل دسته.
"""

from __future__ import annotations

import contextlib
import os
import sys
import time

from . import EngineError, assert_writable_dir, safe_basename

# سقف اندازهٔ یک فراخوانی. پنل خودش دسته را تکه می‌کند؛ این سقف تور ایمنی است
# تا یک درخواست بدشکل، پروسه را ساعت‌ها مشغول نکند.
MAX_ITEMS = 200

DEFAULT_WORKERS = 8

MAX_WORKERS = 16


def _cleanup(*paths):
    """فایل موقت را می‌برد؛ نبودنش یا نداشتن اجازه نباید ارزیابی را زمین بزند."""
    for path in paths:
        if not path:
            continue

        try:
            os.unlink(str(path))
        except OSError:
            pass


def _sweep(out_dir, basename):
    """
    هر فایلی که با نام این آیتم شروع شود.

    مسیرها فقط **بعد از** بازگشت render_document/ocr_document در دست ما هستند؛
    اگر یکی از آن‌ها وسط نوشتن بترکد، فایلِ نیم‌بند نامش را داریم ولی مسیرش را
    نه. نام هر آیتم یکتاست (run<id>_<index>_<type>)، پس جاروکردن با همان
    پیشوند هیچ فایل دیگری را لمس نمی‌کند.
    """
    try:
        from pathlib import Path

        for leftover in Path(out_dir).glob(f"{basename}*"):
            if leftover.is_file():
                _cleanup(leftover)
    except OSError:
        pass


def _one(task):
    """
    یک تصویر: ساخت، OCR، پاک‌سازی. هرگز استثنا پرتاب نمی‌کند.

    ماژول‌سطح است چون باید picklable باشد (استخر پروسه).
    """
    result = {
        "index": task.get("index"),
        "document_type": task.get("document_type"),
        "ok": False,
        "fields": {},
        "variants": [],
        "image": None,
        "error": None,
        "duration_ms": 0,
    }

    started = time.perf_counter()
    clean_path = augmented_path = ocr_leftover = None

    try:
        # cli.py توصیف‌گر stdout را از ابتدا به stderr برده، ولی این محافظ
        # دوم برای مسیرهای دیگر (تست مستقیم ماژول) هم لازم است: هر print
        # ماژول‌های قدیمی نباید وارد سند JSON شود.
        with contextlib.redirect_stdout(sys.stderr):
            from .ocr import ocr_document
            from .render import render_document

            rendered = render_document(
                document_type=task["document_type"],
                payload=task.get("payload") or {},
                augmentations=task.get("augmentations") or {},
                out_dir=task["out_dir"],
                basename=task["basename"],
            )

            clean_path = rendered.get("clean_path")
            augmented_path = rendered.get("augmented_path")

            # همان تصویری خوانده می‌شود که کاربر آپلود می‌کرد: نسخهٔ اعوجاج‌یافته
            # اگر ساخته شده، وگرنه نسخهٔ تمیز.
            read_path = augmented_path or clean_path

            ocr = ocr_document(
                path=read_path,
                document_type=task["document_type"],
                preprocess=True,
                out_dir=task["out_dir"],
            )

            # ocr_document نسخه‌های کمکی را خودش پاک می‌کند و فقط نسخهٔ اول
            # را جا می‌گذارد؛ همان یکی هم این‌جا لازم نیست.
            ocr_leftover = ocr.get("preprocessed_path")

            result["fields"] = {
                key: (info or {}).get("text", "")
                for key, info in (rendered.get("fields") or {}).items()
            }

            result["variants"] = [
                {
                    "raw_text": variant.get("raw_text") or "",
                    "extra": {
                        "vin": (variant.get("extra") or {}).get("vin"),
                        "plate": (variant.get("extra") or {}).get("plate"),
                    },
                }
                for variant in (ocr.get("variants") or [])
            ]

            result["ok"] = True

            if task.get("keep"):
                # فقط تصویری که واقعاً خوانده شد نگه داشته می‌شود — همان چیزی
                # که کاربر باید در صفحهٔ نتیجه ببیند.
                result["image"] = os.path.basename(str(read_path))
                _cleanup(ocr_leftover, clean_path if read_path != clean_path else None)
            else:
                _cleanup(clean_path, augmented_path, ocr_leftover)
    except EngineError as error:
        result["error"] = str(error)
        _sweep(task["out_dir"], task["basename"])
    except Exception as exc:
        result["error"] = f"{type(exc).__name__}: {exc}"
        _sweep(task["out_dir"], task["basename"])

    result["duration_ms"] = int((time.perf_counter() - started) * 1000)

    return result


def evaluate_batch(items=None, out_dir=None, workers=None):
    """
    یک تکه از دستهٔ ارزیابی.

    ورودی هر آیتم: index، document_type، payload (شخص مصنوعی)، augmentations،
    و keep (تصویرش نگه داشته شود یا نه).
    """
    if not isinstance(items, (list, tuple)) or not items:
        raise EngineError(
            "فهرست تصویرهای این دسته خالی است.",
            "items is empty",
        )

    if len(items) > MAX_ITEMS:
        raise EngineError(
            f"در هر فراخوانی حداکثر {MAX_ITEMS} تصویر می‌شود ارزیابی کرد.",
            f"items={len(items)}",
        )

    target_dir = assert_writable_dir(out_dir)

    try:
        workers = int(workers or DEFAULT_WORKERS)
    except (TypeError, ValueError):
        workers = DEFAULT_WORKERS

    workers = max(1, min(MAX_WORKERS, workers, len(items)))

    tasks = []

    for position, item in enumerate(items):
        if not isinstance(item, dict):
            raise EngineError("آیتم ارزیابی باید یک شیء باشد.", f"position={position}")

        index = item.get("index", position)

        tasks.append({
            "index": index,
            "document_type": item.get("document_type"),
            "payload": item.get("payload") or {},
            "augmentations": item.get("augmentations") or {},
            "keep": bool(item.get("keep")),
            "out_dir": str(target_dir),
            "basename": safe_basename(item.get("basename"), f"eval_{index}"),
        })

    started = time.perf_counter()

    if workers == 1:
        results = [_one(task) for task in tasks]
    else:
        import multiprocessing

        # fork عمداً: کارگرها باید همان مسیر جست‌وجوی ماژول و همان محیطی را
        # داشته باشند که cli.py ساخته (از جمله انتقال stdout به stderr).
        context = multiprocessing.get_context("fork")

        with context.Pool(processes=workers) as pool:
            results = pool.map(_one, tasks)

    return {
        "items": list(results),
        "workers": workers,
        "count": len(results),
        "duration_ms": int((time.perf_counter() - started) * 1000),
    }
