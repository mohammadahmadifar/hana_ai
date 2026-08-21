"""
اجرای OCR روی یک تصویر مدرک، با استفاده از همان پایپ‌لاین فعلی پروژه:
پیش‌پردازش (خاکستری + حذف نویز + شارپ) سپس Tesseract فارسی.

برای کارت خودرو، علاوه بر متن عمومی، شماره شاسی (VIN) و پلاک هم با
منطق app/ocr/vehicle_card_ocr.py استخراج می‌شود. آن ماژول از تسک ۶۴۱ به
بعد دیگر چیزی چاپ نمی‌کند، ولی stdout همچنان به stderr منحرف می‌شود تا
هیچ print باقی‌مانده‌ای در ماژول‌های قدیمی JSON خروجی پل را خراب نکند.
"""

from __future__ import annotations

import contextlib
import sys
import time

from . import EngineError, assert_readable, assert_writable_dir, default_out_dir, safe_basename


def _assert_readable_image(source):
    """
    بررسی «تصویر قابل خواندن است؟» — پیش از هر مسیری اجرا می‌شود تا در حالت
    preprocess=false هم پیام فارسی بدهد، نه خطای انگلیسی Tesseract/PIL.
    """
    import cv2

    if cv2.imread(str(source)) is None:
        raise EngineError(
            "فایل تصویر قابل خواندن نیست یا فرمت آن پشتیبانی نمی‌شود.",
            f"path={source}",
        )


def _run_preprocess(source, target_dir, basename):
    from app.preprocessing.image_preprocessing import preprocess_image

    output = target_dir / f"{basename}.png"

    preprocess_image(str(source), str(output))

    if not output.is_file():
        raise EngineError(
            "پیش‌پردازش تصویر ناموفق بود.",
            f"output={output}",
        )

    return output


def ocr_document(path, document_type=None, preprocess=True, out_dir=None):
    started = time.perf_counter()

    source = assert_readable(path)

    _assert_readable_image(source)

    preprocessed_path = None
    target = source

    if preprocess:
        target_dir = assert_writable_dir(out_dir or default_out_dir("ocr_document"))

        basename = safe_basename(
            f"{source.stem}_pre",
            f"pre_{int(time.time() * 1000)}",
        )

        preprocessed_path = _run_preprocess(source, target_dir, basename)
        target = preprocessed_path

    # همه پرینت‌های ماژول‌های قدیمی به stderr می‌روند
    with contextlib.redirect_stdout(sys.stderr):
        from app.ocr.ocr_engine import extract_text

        raw_text = extract_text(str(target))

        extra = {"vin": None, "plate": None}

        if document_type == "vehicle_card":
            from app.ocr.vehicle_card_ocr import vehicle_card_ocr

            try:
                vin_text, plate_text = vehicle_card_ocr(str(target))
                extra["vin"] = (vin_text or "").strip() or None
                extra["plate"] = (plate_text or "").strip() or None
            except Exception as exc:  # استخراج ویژه نباید کل OCR را زمین بزند
                extra["error"] = f"{type(exc).__name__}: {exc}"

    raw_text = raw_text or ""

    return {
        "document_type": document_type,
        "source_path": str(source),
        "ocr_path": str(target),
        "preprocessed_path": str(preprocessed_path) if preprocessed_path else None,
        "preprocess": bool(preprocess),
        "lang": "fas",
        "config": "--oem 3 --psm 6",
        "raw_text": raw_text,
        "char_count": len(raw_text),
        "line_count": len([ln for ln in raw_text.splitlines() if ln.strip()]),
        "extra": extra,
        "duration_ms": int((time.perf_counter() - started) * 1000),
    }
