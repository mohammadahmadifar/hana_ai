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
    # کارت ملی — image_writer.create_national_card ، فونت ۲۲
    #
    # تسک ۶۴۰: فونت از ۳۲ به ۲۲ آمد و همهٔ yها ۶ پیکسل پایین رفتند.
    # دلیل: psm 6 تسرکت صفحه را «یک بلوک یکدست» فرض می‌کند و وقتی مقدار
    # چاپ‌شده درشت‌تر از برچسب‌های خودِ قالب باشد، سطربندی به هم می‌ریزد و
    # ارقام کد ملی تکه‌تکه خوانده می‌شوند. برچسب‌های این قالب ریزند، پس
    # مقدار هم باید ریز باشد. اندازه‌گیری روی ۴۵ نمونه: کد ملی ۵۱٪ → ۹۳٪.
    # ۶ پیکسل جابه‌جایی، مرکز نوری متن را دقیقاً همان‌جای قبل نگه می‌دارد
    # تا مقدار با برچسب قالب هم‌تراز بماند (PIL از بالای کادر متن می‌چیند).
    # ---------------------------------------------------------------
    "national_card": {
        "label_fa": "کارت ملی",
        "template": "dataset/templates/national_card.png",
        "font": DEFAULT_FONT,
        "font_size": 22,
        "fields": {
            "national_id": {
                "x": 790, "y": 148, "align": "right",
                "label_fa": "کد ملی",
            },
            "first_name": {
                "x": 790, "y": 214, "align": "right",
                "label_fa": "نام",
            },
            "last_name": {
                "x": 790, "y": 276, "align": "right",
                "label_fa": "نام خانوادگی",
            },
            "birth_date": {
                "x": 790, "y": 338, "align": "right",
                "label_fa": "تاریخ تولد",
            },
            "father_name": {
                "x": 790, "y": 396, "align": "right",
                "label_fa": "نام پدر",
            },
            "national_card_expire": {
                "x": 790, "y": 456, "align": "right",
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
                # تسک ۶۴۰: تنها فیلد گواهینامه که در سطر خودش تنهاست (بقیه
                # کنار برچسب قالب می‌نشینند). سطرِ تک‌افتاده با فونت ۳۸ در
                # سطربندی psm 6 گم می‌شد و ۰٪ خوانده می‌شد. با ۲۸ و ۸ پیکسل
                # پایین‌تر (برای حفظ مرکز نوری) روی ۴۵ نمونه ۷۸٪ شد.
                "x": 1100, "y": 488, "align": "right",
                "font_size": 28,
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


# اندازهٔ قالب‌ها یک بار خوانده و نگه داشته می‌شود؛ ocr_document برای هر مدرک
# صدایش می‌زند و باز کردن دوبارهٔ فایل PNG در هر تماس بی‌دلیل است.
_TEMPLATE_SIZES = {}


def template_size(document_type):
    """
    (عرض، ارتفاع) قالب مرجع یک نوع مدرک — یا None اگر نوع ناشناخته باشد
    یا فایل قالب سر جایش نباشد.

    این «رزولوشن مرجع» است: اندازه‌ای که مقدارها با آن روی قالب چاپ می‌شوند و
    پایپ‌لاین پیش‌پردازش و OCR برای همان تنظیم شده. تصویری که کاربر آپلود
    می‌کند ممکن است هر اندازه‌ای باشد (تلگرام عکس را نصف می‌کند)، پس
    hana_engine/ocr.py مقیاس‌هایش را نسبت به همین عدد می‌سازد نه نسبت به
    اندازهٔ خودِ آپلود — وگرنه «۱.۲۵ برابر» برای یک عکس ۷۵۰ پیکسلی و یک عکس
    ۳۰۰۰ پیکسلی دو معنای کاملاً متفاوت دارد.

    عمداً استثنا پرتاب نمی‌کند: OCR روی مدرکی که قالب ندارد هم باید کار کند.
    """
    key = (document_type or "").strip()

    if key in _TEMPLATE_SIZES:
        return _TEMPLATE_SIZES[key]

    size = None
    layout = LAYOUTS.get(key)

    if layout is not None:
        template = ENGINE_ROOT / layout["template"]

        if template.is_file():
            try:
                from PIL import Image

                with Image.open(template) as image:
                    size = image.size
            except Exception:
                size = None

    _TEMPLATE_SIZES[key] = size

    return size


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
