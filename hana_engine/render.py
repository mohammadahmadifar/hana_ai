"""
تولید داده‌محور تصویر مدرک + کادر دقیق هر فیلد + اعوجاج کنترل‌شده.

نکته: dataset/image_writer.py دست‌نخورده باقی می‌ماند؛ اینجا همان قالب‌ها،
همان فونت و همان مختصات (از hana_engine/layouts.py) استفاده می‌شود، با این
تفاوت که مختصات به‌جای هاردکد از ساختار داده می‌آید و کادر هر فیلد
(نسبت به ابعاد تصویر، بین ۰ و ۱) برگردانده می‌شود.

اعوجاج‌ها منطق app/processing/image_processing.py را دارند اما با پارامتر
صریح به‌جای random، تا نتیجه تکرارپذیر باشد.
"""

from __future__ import annotations

import time

import cv2
import numpy as np
from PIL import Image, ImageDraw, ImageFont

from . import ENGINE_ROOT, EngineError, ensure_allowed_dir, safe_basename
from .layouts import field_font_size, font_path, get_layout, resolve_value, template_path

AUG_ORDER = ("rotation", "brightness", "blur", "noise", "shadow")


# ---------------------------------------------------------------
# ابزار کمکی اعوجاج
# ---------------------------------------------------------------

def _option(augmentations, name):
    """
    خواندن تنظیمات یک اعوجاج.
    خروجی: (فعال؟، دیکشنری پارامترها)
    هم {"rotation": {"enabled": true, "angle": 7}} پشتیبانی می‌شود
    هم {"rotation": true} و هم {"rotation": {"angle": 7}}.
    """
    if not augmentations or name not in augmentations:
        return False, {}

    value = augmentations[name]

    if value is None or value is False:
        return False, {}

    if value is True:
        return True, {}

    if not isinstance(value, dict):
        raise EngineError(
            "تنظیمات اعوجاج نامعتبر است.",
            f"augmentations[{name}] must be object or boolean",
        )

    return bool(value.get("enabled", True)), value


def _number(params, key, default):
    value = params.get(key, default)

    if value is None:
        return default

    try:
        return float(value)
    except (TypeError, ValueError):
        raise EngineError(
            "مقدار عددی اعوجاج نامعتبر است.",
            f"{key}={value!r}",
        )


def rotate_image(image, angle):
    height, width = image.shape[:2]
    center = (width // 2, height // 2)

    matrix = cv2.getRotationMatrix2D(center, angle, 1)

    rotated = cv2.warpAffine(
        image,
        matrix,
        (width, height),
        borderValue=(255, 255, 255),
    )

    return rotated, matrix


def change_brightness(image, value):
    hsv = cv2.cvtColor(image, cv2.COLOR_BGR2HSV).astype(np.int16)
    hsv[:, :, 2] = np.clip(hsv[:, :, 2] + int(value), 0, 255)
    return cv2.cvtColor(hsv.astype(np.uint8), cv2.COLOR_HSV2BGR)


def blur_image(image, kernel):
    size = int(kernel)

    if size < 1:
        size = 3

    if size % 2 == 0:
        size += 1

    return cv2.GaussianBlur(image, (size, size), 0)


def add_noise(image, std, seed=None):
    rng = np.random.default_rng(seed)
    noise = rng.normal(0, float(std), image.shape)
    noisy = np.clip(image + noise, 0, 255)
    return noisy.astype(np.uint8)


def add_shadow(image, x_ratio, y_ratio, size_x, size_y, alpha, angle):
    height, width = image.shape[:2]

    center_x = int(width * float(x_ratio))
    center_y = int(height * float(y_ratio))

    axis_x = max(1, int(width * float(size_x)))
    axis_y = max(1, int(height * float(size_y)))

    ellipse_mask = np.zeros((height, width), dtype=np.uint8)

    cv2.ellipse(
        ellipse_mask,
        (center_x, center_y),
        (axis_x, axis_y),
        int(angle),
        0,
        360,
        255,
        -1,
    )

    ellipse_mask = cv2.GaussianBlur(ellipse_mask, (51, 51), 0)
    ellipse_mask = ellipse_mask.astype(np.float32) / 255.0

    mask = 1 - ((1 - float(alpha)) * ellipse_mask)

    return (image * mask[:, :, np.newaxis]).astype(np.uint8)


def _rotate_box(box, matrix):
    """کادر محوری جدید پس از چرخش (چهار گوشه چرخانده و دوباره محاط می‌شود)."""
    x, y, w, h = box["x"], box["y"], box["w"], box["h"]

    corners = np.array(
        [
            [x, y, 1],
            [x + w, y, 1],
            [x, y + h, 1],
            [x + w, y + h, 1],
        ],
        dtype=np.float64,
    )

    moved = corners @ matrix.T

    min_x = float(moved[:, 0].min())
    max_x = float(moved[:, 0].max())
    min_y = float(moved[:, 1].min())
    max_y = float(moved[:, 1].max())

    return {
        "x": int(round(min_x)),
        "y": int(round(min_y)),
        "w": int(round(max_x - min_x)),
        "h": int(round(max_y - min_y)),
    }


def _norm_box(box, width, height):
    return {
        "x": round(box["x"] / width, 6),
        "y": round(box["y"] / height, 6),
        "w": round(box["w"] / width, 6),
        "h": round(box["h"] / height, 6),
    }


# ---------------------------------------------------------------
# رسم فیلدها
# ---------------------------------------------------------------

def font_path_for(layout):
    """مسیر فونت این چیدمان (بدون نیاز به کلید نوع مدرک)."""
    return ENGINE_ROOT / layout.get("font", "fonts/Vazir-Medium.ttf")


def _draw_fields(image, layout, payload):
    draw = ImageDraw.Draw(image)
    width, height = image.size

    fonts = {}
    boxes = {}
    missing = []

    for field_key, field in layout["fields"].items():
        text = resolve_value(field_key, field, payload)

        if text is None:
            missing.append(field_key)
            continue

        size = field_font_size(layout, field)

        if size not in fonts:
            fonts[size] = ImageFont.truetype(str(font_path_for(layout)), size)

        font = fonts[size]

        # عرض متن، دقیقاً مثل draw_right_text در dataset/image_writer.py
        measure = draw.textbbox((0, 0), text, font=font)
        text_width = measure[2] - measure[0]

        if field["align"] == "right":
            draw_x = field["x"] - text_width
        else:
            draw_x = field["x"]

        draw_y = field["y"]

        draw.text((draw_x, draw_y), text, fill="black", font=font)

        real = draw.textbbox((draw_x, draw_y), text, font=font)

        box = {
            "x": int(real[0]),
            "y": int(real[1]),
            "w": int(real[2] - real[0]),
            "h": int(real[3] - real[1]),
        }

        boxes[field_key] = {
            "text": text,
            "align": field["align"],
            "font_size": size,
            "anchor_x": field["x"],
            "anchor_y": field["y"],
            "box": box,
            "norm": _norm_box(box, width, height),
            "label_fa": field.get("label_fa", field_key),
        }

    return boxes, missing


# ---------------------------------------------------------------
# نقطهٔ ورود
# ---------------------------------------------------------------

def render_document(
    document_type,
    payload=None,
    augmentations=None,
    out_dir=None,
    basename=None,
):
    started = time.perf_counter()

    layout = get_layout(document_type)
    template = template_path(document_type)

    if not template.is_file():
        raise EngineError(
            "قالب این نوع مدرک روی سرور موجود نیست.",
            f"template={template}",
        )

    font_file = font_path(document_type)

    if not font_file.is_file():
        raise EngineError(
            "فونت فارسی موتور پیدا نشد.",
            f"font={font_file}",
        )

    if payload is not None and not isinstance(payload, dict):
        raise EngineError("داده‌های مدرک باید یک شیء (object) باشد.", "payload is not object")

    payload = payload or {}

    target_dir = ensure_allowed_dir(
        out_dir or (ENGINE_ROOT / "dataset" / "generated" / "_engine")
    )

    name = safe_basename(basename, f"{document_type}_{int(time.time() * 1000)}")

    with Image.open(template) as source:
        image = source.convert("RGB")

    boxes, missing = _draw_fields(image, layout, payload)

    clean_path = target_dir / f"{name}.png"
    image.save(clean_path)

    width, height = image.size

    # -----------------------------------------------------------
    # اعوجاج کنترل‌شده
    # -----------------------------------------------------------
    applied = []
    augmented_path = None
    boxes_aug = None

    wanted = [n for n in AUG_ORDER if _option(augmentations, n)[0]]

    if wanted:
        frame = cv2.cvtColor(np.array(image), cv2.COLOR_RGB2BGR)
        rotation_matrix = None

        for name_of in wanted:
            _, params = _option(augmentations, name_of)

            if name_of == "rotation":
                angle = _number(params, "angle", 7)
                frame, rotation_matrix = rotate_image(frame, angle)
                applied.append({"name": "rotation", "angle": angle})

            elif name_of == "brightness":
                value = int(_number(params, "value", -20))
                frame = change_brightness(frame, value)
                applied.append({"name": "brightness", "value": value})

            elif name_of == "blur":
                kernel = int(_number(params, "kernel", 3))
                frame = blur_image(frame, kernel)
                applied.append({"name": "blur", "kernel": kernel})

            elif name_of == "noise":
                std = _number(params, "std", 8)
                seed = params.get("seed")
                frame = add_noise(frame, std, seed)
                applied.append({"name": "noise", "std": std, "seed": seed})

            elif name_of == "shadow":
                shadow_params = {
                    "x_ratio": _number(params, "x_ratio", 0.9),
                    "y_ratio": _number(params, "y_ratio", 0.1),
                    "size_x": _number(params, "size_x", 0.13),
                    "size_y": _number(params, "size_y", 0.13),
                    "alpha": _number(params, "alpha", 0.75),
                    "angle": _number(params, "angle", 0),
                }
                frame = add_shadow(frame, **shadow_params)
                applied.append({"name": "shadow", **shadow_params})

        augmented_path = target_dir / f"{name}_aug.png"
        cv2.imwrite(str(augmented_path), frame)

        # کادرها روی تصویر اعوجاج‌یافته (فقط چرخش هندسه را عوض می‌کند)
        boxes_aug = {}

        for field_key, info in boxes.items():
            box = (
                _rotate_box(info["box"], rotation_matrix)
                if rotation_matrix is not None
                else dict(info["box"])
            )

            boxes_aug[field_key] = {
                "box": box,
                "norm": _norm_box(box, width, height),
            }

    image.close()

    return {
        "document_type": document_type,
        "width": width,
        "height": height,
        "clean_path": str(clean_path),
        "augmented_path": str(augmented_path) if augmented_path else None,
        "basename": name,
        "out_dir": str(target_dir),
        "fields": boxes,
        "fields_augmented": boxes_aug,
        "missing_fields": missing,
        "applied": applied,
        "duration_ms": int((time.perf_counter() - started) * 1000),
    }
