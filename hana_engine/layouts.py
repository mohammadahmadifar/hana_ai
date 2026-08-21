"""
نقشهٔ چیدمان فیلدها روی قالب هر نوع مدرک.

مقادیر مختصات و اندازهٔ فونت دقیقاً از dataset/image_writer.py برداشته شده‌اند
تا تصویر تولیدشده توسط این پکیج با خروجی پایپ‌لاین فعلی یکسان باشد.
هدف از داده‌محور کردن این مقادیر، به‌دست‌آوردن «کادر دور هر فیلد» برای
ابزار تگ‌گذاری و آموزش مدل است.
"""

from __future__ import annotations

from . import ENGINE_ROOT, EngineError

# فونت مشترک همهٔ قالب‌ها
DEFAULT_FONT = "fonts/Vazir-Medium.ttf"

LAYOUTS = {
    # ---------------------------------------------------------------
    # کارت ملی — image_writer.create_national_card ، فونت ۳۲
    # ---------------------------------------------------------------
    "national_card": {
        "label_fa": "کارت ملی",
        "template": "dataset/templates/national_card.png",
        "font": DEFAULT_FONT,
        "font_size": 32,
        "fields": {
            "national_id": {
                "x": 790, "y": 142, "align": "right",
                "label_fa": "کد ملی",
            },
            "first_name": {
                "x": 790, "y": 208, "align": "right",
                "label_fa": "نام",
            },
            "last_name": {
                "x": 790, "y": 270, "align": "right",
                "label_fa": "نام خانوادگی",
            },
            "birth_date": {
                "x": 790, "y": 332, "align": "right",
                "label_fa": "تاریخ تولد",
            },
            "father_name": {
                "x": 790, "y": 390, "align": "right",
                "label_fa": "نام پدر",
            },
            "national_card_expire": {
                "x": 790, "y": 450, "align": "right",
                "label_fa": "تاریخ انقضای کارت",
            },
        },
    },

    # ---------------------------------------------------------------
    # گواهینامه رانندگی — image_writer.create_driving_license ، فونت ۳۸
    # ---------------------------------------------------------------
    "driving_license": {
        "label_fa": "گواهینامه رانندگی",
        "template": "dataset/templates/driving_license.png",
        "font": DEFAULT_FONT,
        "font_size": 38,
        "fields": {
            "national_id": {
                "x": 915, "y": 263, "align": "right",
                "label_fa": "کد ملی",
            },
            "full_name": {
                "x": 1100, "y": 480, "align": "right",
                "label_fa": "نام و نام خانوادگی",
                "compose": ["first_name", "last_name"],
            },
            "birth_date": {
                "x": 915, "y": 615, "align": "right",
                "label_fa": "تاریخ تولد",
            },
            "license_issue_date": {
                "x": 885, "y": 730, "align": "right",
                "label_fa": "تاریخ صدور",
            },
            "license_number": {
                "x": 800, "y": 820, "align": "right",
                "label_fa": "شماره گواهینامه",
            },
        },
    },

    # ---------------------------------------------------------------
    # کارت مالکیت خودرو — image_writer.create_vehicle_card
    # فونت متن ۳۶ ، فونت پلاک ۹۰ ، شماره شاسی (VIN) چپ‌چین
    # ---------------------------------------------------------------
    "vehicle_card": {
        "label_fa": "کارت مالکیت خودرو",
        "template": "dataset/templates/vehicle_card.png",
        "font": DEFAULT_FONT,
        "font_size": 36,
        "fields": {
            "full_name": {
                "x": 980, "y": 242, "align": "right",
                "label_fa": "نام و نام خانوادگی مالک",
                "compose": ["first_name", "last_name"],
            },
            "national_id": {
                "x": 1050, "y": 360, "align": "right",
                "label_fa": "کد ملی مالک",
            },
            "father_name": {
                "x": 1080, "y": 485, "align": "right",
                "label_fa": "نام پدر",
            },
            "vin": {
                "x": 270, "y": 625, "align": "left",
                "label_fa": "شماره شاسی",
            },
            "plate_number": {
                # تسک ۶۳۷: پلاک با قالب رسمی «۱۲ ب ۳۴۵ ایران ۶۷» چاپ می‌شود.
                # این رشته بلندتر از قالب قبلی است و با فونت ۹۰ از کادر بیرون
                # می‌زد؛ مختصات و اندازه عیناً همان چیزی است که
                # dataset/image_writer.py:create_vehicle_card می‌نویسد.
                "x": 1186, "y": 843, "align": "right",
                "font_size": 48,
                "label_fa": "شماره پلاک",
            },
        },
    },
}


def layout_keys():
    return list(LAYOUTS.keys())


def get_layout(document_type):
    """دریافت چیدمان یک نوع مدرک؛ در صورت ناشناخته‌بودن خطای فارسی."""
    key = (document_type or "").strip()

    if key not in LAYOUTS:
        raise EngineError(
            "نوع مدرک برای تولید تصویر پشتیبانی نمی‌شود.",
            f"document_type={document_type!r} available={layout_keys()}",
        )

    return LAYOUTS[key]


def template_path(document_type):
    layout = get_layout(document_type)
    return ENGINE_ROOT / layout["template"]


def font_path(document_type):
    layout = get_layout(document_type)
    return ENGINE_ROOT / layout.get("font", DEFAULT_FONT)


def field_font_size(layout, field):
    return int(field.get("font_size", layout["font_size"]))


def resolve_value(field_key, field, payload):
    """
    مقدار متنی یک فیلد را از payload بیرون می‌کشد.
    اگر فیلد ترکیبی باشد (مثل full_name) و مقدار مستقیم نداشته باشیم،
    از اجزای آن (first_name + last_name) ساخته می‌شود.
    """
    if payload and field_key in payload and payload[field_key] not in (None, ""):
        return str(payload[field_key])

    parts_keys = field.get("compose") or []

    parts = [
        str(payload[k]).strip()
        for k in parts_keys
        if payload and k in payload and payload[k] not in (None, "")
    ]

    if parts and len(parts) == len(parts_keys):
        return " ".join(parts)

    return None


def describe():
    """
    نقشهٔ کامل فیلدها به‌همراه ابعاد قالب — خروجی دستور document_layouts.
    ابعاد قالب برای ابزار تگ‌گذاری لازم است تا بتواند کادرها را
    روی هر بزرگ‌نمایی درست رسم کند.
    """
    from PIL import Image

    result = {}

    for key, layout in LAYOUTS.items():
        template = ENGINE_ROOT / layout["template"]

        width = height = None
        exists = template.is_file()

        if exists:
            with Image.open(template) as image:
                width, height = image.size

        fields = {}

        for field_key, field in layout["fields"].items():
            fields[field_key] = {
                "x": field["x"],
                "y": field["y"],
                "align": field["align"],
                "font_size": field_font_size(layout, field),
                "label_fa": field.get("label_fa", field_key),
                "compose": field.get("compose"),
            }

        result[key] = {
            "key": key,
            "label_fa": layout["label_fa"],
            "template": layout["template"],
            "template_path": str(template),
            "template_exists": exists,
            "template_width": width,
            "template_height": height,
            "font": layout.get("font", DEFAULT_FONT),
            "font_path": str(ENGINE_ROOT / layout.get("font", DEFAULT_FONT)),
            "font_size": layout["font_size"],
            "fields": fields,
            "field_order": list(layout["fields"].keys()),
        }

    return result
