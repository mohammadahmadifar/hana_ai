"""
سنجش کیفیت تصویر آپلودی — پیش از اجرای OCR.

blur_score = واریانس لاپلاسین تصویر خاکستری؛ هر چه کمتر، تصویر تارتر.
این فقط یک «راهنما» است و تصمیم نهایی با کارشناس/قوانین اعتبارسنجی است.
"""

from __future__ import annotations

import cv2
import numpy as np

from . import EngineError, assert_readable

# آستانهٔ راهنمای تاری
BLUR_THRESHOLD = 100.0

# آستانه‌های راهنمای روشنایی
DARK_THRESHOLD = 60.0
BRIGHT_THRESHOLD = 200.0


def image_quality(path):
    target = assert_readable(path)

    image = cv2.imread(str(target))

    if image is None:
        raise EngineError(
            "فایل تصویر قابل خواندن نیست یا فرمت آن پشتیبانی نمی‌شود.",
            f"path={target}",
        )

    height, width = image.shape[:2]

    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)

    blur_score = float(cv2.Laplacian(gray, cv2.CV_64F).var())

    hsv = cv2.cvtColor(image, cv2.COLOR_BGR2HSV)
    brightness = float(np.mean(hsv[:, :, 2]))

    mean = float(np.mean(gray))

    return {
        "path": str(target),
        "width": int(width),
        "height": int(height),
        "megapixels": round((width * height) / 1_000_000, 3),
        "blur_score": round(blur_score, 3),
        "brightness": round(brightness, 3),
        "mean": round(mean, 3),
        "std": round(float(np.std(gray)), 3),
        "is_blurry_hint": bool(blur_score < BLUR_THRESHOLD),
        "is_dark_hint": bool(brightness < DARK_THRESHOLD),
        "is_bright_hint": bool(brightness > BRIGHT_THRESHOLD),
        "blur_threshold": BLUR_THRESHOLD,
    }
