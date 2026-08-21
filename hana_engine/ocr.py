"""
اجرای OCR روی یک تصویر مدرک، با استفاده از همان پایپ‌لاین فعلی پروژه:
پیش‌پردازش (خاکستری + حذف نویز + شارپ) سپس Tesseract فارسی.

برای کارت خودرو، علاوه بر متن عمومی، شماره شاسی (VIN) و پلاک هم با
منطق app/ocr/vehicle_card_ocr.py استخراج می‌شود. آن ماژول هنگام خواندن
پلاک print می‌زند؛ اینجا stdout به stderr منحرف می‌شود تا JSON خروجی
پل خراب نشود (بدون هیچ تغییری در آن فایل).
"""

from __future__ import annotations

import contextlib
import sys
import time

from . import ENGINE_ROOT, EngineError, ensure_allowed_dir, ensure_allowed_file, safe_basename

DEFAULT_PREPROCESSED_DIR = ENGINE_ROOT / "dataset" / "preprocessed" / "_engine"


def _run_preprocess(source, target_dir, basename):
    from app.preprocessing.image_preprocessing import preprocess_image

    import cv2

    if cv2.imread(str(source)) is None:
        raise EngineError(
            "فایل تصویر قابل خواندن نیست یا فرمت آن پشتیبانی نمی‌شود.",
            f"path={source}",
        )

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

    source = ensure_allowed_file(path)

    preprocessed_path = None
    target = source

    if preprocess:
        target_dir = ensure_allowed_dir(out_dir or DEFAULT_PREPROCESSED_DIR)

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
