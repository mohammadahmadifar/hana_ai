"""
اجرای OCR روی یک تصویر مدرک، با پایپ‌لاین فعلی پروژه:
پیش‌پردازش (خاکستری + deskew + یکنواخت‌سازی روشنایی + حذف نویز + شارپ) سپس
Tesseract فارسی.

برای کارت خودرو، علاوه بر متن عمومی، شماره شاسی (VIN) و پلاک هم با
منطق app/ocr/vehicle_card_ocr.py استخراج می‌شود. آن ماژول از تسک ۶۴۱ به
بعد دیگر چیزی چاپ نمی‌کند، ولی stdout همچنان به stderr منحرف می‌شود تا
هیچ print باقی‌مانده‌ای در ماژول‌های قدیمی JSON خروجی پل را خراب نکند.

چندمقیاسی بودن (تسک ۶۶۲)
-------------------------
هر مدرک **چند بار** خوانده می‌شود، هر بار در یک بزرگ‌نمایی متفاوت نسبت به
عرض قالب مرجع همان نوع مدرک (`layouts.template_size`). دلیلش دو چیز است که
با اندازه‌گیری روشن شد:

۱) تصویری که کاربر آپلود می‌کند لزوماً رزولوشن مرجع را ندارد. در پروندهٔ ۲۷۲
   هر سه فایل از تلگرام آمده بودند و تلگرام گواهینامه و کارت خودرو را به
   نصف اندازه کوچک کرده بود؛ OCR روی نصف رزولوشن، کد ملی و تاریخ تولد و
   شماره گواهینامه را غلط خواند. همان فایل‌ها روی عرض قالب هر سه را درست
   دادند. پس «مقیاس ۱.۰» یعنی رساندن مدرک به رزولوشن مرجع، نه رها کردنش
   روی هر اندازه‌ای که آپلود شده.

۲) هیچ بزرگ‌نمایی ثابتی برای همهٔ فیلدها بهترین نیست. اندازه‌گیری روی ۷۵
   نمونهٔ تازه: مقیاس ۱.۲۵ به‌تنهایی کارت ملی را بهتر و گواهینامه را بدتر
   می‌کند؛ ولی **اجتماع** مقیاس‌ها همهٔ فیلدها را بالا می‌برد
   (تطابق کامل ۷۴.۸٪ → ۸۳.۵٪ ، CER ۸.۹۶ → ۵.۹۳).

پس موتور همهٔ متن‌ها را برمی‌گرداند و **انتخاب** با استخراج‌گر پنل است:
`FieldExtractor` هر متن را جدا می‌خواند و برای هر فیلد مقداری را نگه می‌دارد
که بیشترین اطمینان را دارد. موتور قضاوت نمی‌کند کدام متن بهتر است، چون
«بهتر» به ازای هر فیلد فرق می‌کند.

`raw_text` سطح بالا همان متنِ مقیاس ۱.۰ است تا مصرف‌کننده‌های قدیمی
(صفحهٔ نتیجه، dataset) دست‌نخورده کار کنند.
"""

from __future__ import annotations

import contextlib
import sys
import time

from . import EngineError, assert_readable, assert_writable_dir, default_out_dir, safe_basename
from .layouts import template_size

# بزرگ‌نمایی‌های پیش‌فرض، نسبت به عرض قالب مرجع.
#
# چرا همین سه‌تا: روی ۷۵ نمونهٔ تازه، «۱.۰» به‌تنهایی ۷۴.۸٪ می‌دهد،
# «۱.۰+۱.۲۵» ۸۰.۲٪ ، «۱.۰+۱.۵» ۸۲.۲٪ و «۱.۰+۱.۲۵+۱.۵» ۸۳.۵٪. افزودن
# ۰.۷۵ حدود ۱.۷ واحد دیگر می‌دهد ولی یک‌چهارم زمان بیشتر می‌خواهد و سودش
# تقریباً همه‌اش روی کارت ملی است؛ اگر لازم شد با پارامتر scales صدا زده شود.
DEFAULT_SCALES = (1.0, 1.25, 1.5)

# بیشترین بزرگ‌نماییِ مجاز نسبت به **خودِ تصویر آپلودشده**.
# تصویر ۲۰۰ پیکسلی را تا ۲۳۰۰ پیکسل کش‌آوردن فقط درون‌یابی است، نه اطلاعات:
# هزینه را می‌برد بالا و چیزی به متن اضافه نمی‌کند.
MAX_UPSCALE = 4.0

# سقف مطلق عرض هر نسخه؛ Tesseract روی تصویر خیلی بزرگ کند می‌شود.
MAX_WIDTH = 4200

# سقف مساحت هر نسخه. سقفِ عرض به‌تنهایی کافی نیست: یک PNG ۶۰۰×۵۰۰۰۰۰ از سقف
# عرض رد می‌شود، بعد ۱.۵ برابر هم می‌شود و سه بار رمزگشایی و OCR می‌گردد —
# چند گیگابایت حافظه در کارگر صف. قالب‌های واقعی زیر ۱.۶ مگاپیکسل‌اند، پس
# ۱۲ مگاپیکسل هم برای عکس گوشی جای فراوان است و هم کار را کراندار می‌کند.
MAX_PIXELS = 12_000_000

# دو نسخه که عرضشان به این اندازه به هم نزدیک است یکی حساب می‌شوند.
WIDTH_TOLERANCE = 8


def _read_image(source):
    """
    تصویر را می‌خواند و اگر نشد پیام فارسی می‌دهد، نه خطای انگلیسی Tesseract/PIL.

    عمداً همین یک بار رمزگشایی می‌شود: پیش‌تر یک imread فقط برای «آیا خواندنی
    است؟» اجرا می‌شد و بعد همان فایل دوباره خوانده می‌شد — یک رمزگشایی کامل
    دورریز به‌ازای هر مدرک.
    """
    import cv2

    image = cv2.imread(str(source))

    if image is None:
        raise EngineError(
            "فایل تصویر قابل خواندن نیست یا فرمت آن پشتیبانی نمی‌شود.",
            f"path={source}",
        )

    return image


def _reference_width(document_type, fallback_width):
    """
    عرض مرجعی که مقیاس‌ها نسبت به آن معنا پیدا می‌کنند.

    برای نوع مدرکِ شناخته‌شده = عرض قالب. برای نوع ناشناخته (یا مدرکی که هنوز
    قالب ندارد، مثل «مجوز قبلی») = عرض خودِ تصویر، یعنی مقیاس ۱.۰ همان
    رفتار قدیمی می‌ماند.
    """
    size = template_size(document_type)

    if size and size[0]:
        return int(size[0])

    return int(fallback_width)


def _width_ceiling(source_width, source_height):
    """بزرگ‌ترین عرضی که با نسبت ابعاد همین تصویر از سقف مساحت رد نمی‌شود."""
    if source_width <= 0 or source_height <= 0:
        return MAX_WIDTH

    aspect = source_height / float(source_width)

    if aspect <= 0:
        return MAX_WIDTH

    return max(1, int((MAX_PIXELS / aspect) ** 0.5))


def _plan_widths(scales, reference_width, source_width, source_height):
    """
    عرض نهایی هر نسخه، بعد از حذف تکراری‌ها و اعمال سقف‌ها.

    خروجی به ترتیب ورودی می‌ماند تا نسخهٔ اول همیشه همان «مقیاس ۱.۰» باشد؛
    پنل رأی مساوی را به نفع نسخهٔ اول می‌شکند.

    **عرضِ خودِ تصویر هم نامزد می‌شود، ولی فقط وقتی از همهٔ مقیاس‌ها بزرگ‌تر
    باشد.** بدون این شرط، اسکنِ ۳۰۰۰ پیکسلیِ یک مدرکِ ۹۶۰ پیکسلی فقط در
    ۹۶۰/۱۲۰۰/۱۴۴۰ خوانده می‌شد، یعنی هر سه نسخه کوچک‌تر از چیزی که کد قبلی
    به Tesseract می‌داد — و آن‌جا افت واقعی بود. برعکسش لازم نیست: وقتی تصویر
    کوچک‌تر است، خواندنش در اندازهٔ کوچکِ خودش اندازه‌گیری‌شده بدتر است
    (پروندهٔ ۲۷۲: کد ملی و تاریخ تولد و شمارهٔ گواهینامه هر سه غلط) و افزودنش
    فقط یک OCR وقت می‌گیرد بی‌آنکه برنده شود.
    """
    ceiling = _width_ceiling(source_width, source_height)
    planned = []

    def add(width, scale):
        # گرد کردن، نه بریدن: «۱.۵ برابرِ ۱۵۳۷» یعنی ۲۳۰۶ نه ۲۳۰۵. یک پیکسل
        # اختلاف بی‌اهمیت به نظر می‌رسد ولی سطربندی Tesseract را جابه‌جا می‌کند
        # و روی ۲۵ نمونهٔ گواهینامه ۸ فیلد را عوض کرد — پس دست‌کم باید عددی
        # باشد که از تعریفِ مقیاس درمی‌آید، نه از نحوهٔ تبدیل نوع.
        width = int(round(min(width, MAX_WIDTH, ceiling, source_width * MAX_UPSCALE)))

        if width < 1:
            return

        if any(abs(width - done) <= WIDTH_TOLERANCE for done, _ in planned):
            return

        planned.append((width, float(scale)))

    for scale in scales:
        if scale > 0:
            add(reference_width * scale, scale)

    if planned and source_width > max(width for width, _ in planned):
        add(source_width, source_width / float(reference_width or source_width))

    if not planned:
        add(source_width, 1.0)

    return planned


def _resized(image, target_width):
    """تغییر اندازه با حفظ نسبت؛ بزرگ‌نمایی با CUBIC و کوچک‌نمایی با AREA."""
    import cv2

    height, width = image.shape[:2]

    if abs(width - target_width) <= WIDTH_TOLERANCE:
        return image

    factor = target_width / float(width)

    return cv2.resize(
        image,
        (int(round(width * factor)), max(1, int(round(height * factor)))),
        interpolation=cv2.INTER_CUBIC if factor > 1.0 else cv2.INTER_AREA,
    )


def _prepare_variant(image, target_width, preprocess, target_dir, basename, source):
    """
    یک نسخه از تصویر: تغییر اندازه، پیش‌پردازش، و — در صورت لزوم — نوشتن روی دیسک.

    نوشتن اختیاری نیست وقتی تصویر عوض شده باشد: `extract_text` و
    `vehicle_card_ocr` هر دو **مسیر** می‌گیرند نه آرایه. ولی وقتی نه تغییر
    اندازه‌ای لازم است نه پیش‌پردازشی (حالت `preprocess=false` روی تصویری که
    از قبل اندازهٔ درست را دارد)، همان فایل اصلی خوانده می‌شود و چیزی نوشته
    نمی‌شود — همان کاری که کد پیش از چندمقیاسی‌شدن می‌کرد.

    خروجی: (تصویر، مسیر خوانده‌شده، آیا این مسیر فایل تازه‌ای است که ما ساختیم)
    """
    import cv2

    from app.preprocessing.image_preprocessing import apply_steps

    prepared = _resized(image, target_width)

    if not preprocess and prepared is image:
        return image, source, False

    if preprocess:
        prepared = apply_steps(prepared)

    output = target_dir / f"{basename}.png"

    if not _write(output, prepared):
        # نام‌ها قطعی‌اند تا اجرای دوباره فایل تازه تلنبار نکند، ولی همان
        # قطعی‌بودن یک تله دارد: اگر فایلِ هم‌نام را کاربر دیگری ساخته باشد
        # (مثلاً یک اجرای artisan با root در پوشه‌ای که مالکش www-data است)،
        # کارگر صف دیگر نمی‌تواند رویش بنویسد و کل OCR مدرک می‌ترکد. یک نام
        # یکتا برای همان نسخه، هم مدرک را نجات می‌دهد هم رشد فایل را در حالت
        # عادی صفر نگه می‌دارد.
        output = target_dir / f"{basename}_{int(time.time() * 1000)}.png"

        if not _write(output, prepared):
            raise EngineError(
                "نوشتن تصویر پیش‌پردازش‌شده ناموفق بود.",
                f"output={output}",
            )

    return prepared, output, True


def _write(output, image):
    """نوشتن امن: خطای مجوز هم False برمی‌گرداند، نه استثنای خام OpenCV."""
    import cv2

    try:
        return bool(cv2.imwrite(str(output), image))
    except Exception:
        return False


def _discard(image_path):
    """فایل موقت را می‌برد؛ نبودنش یا نداشتن اجازه نباید OCR را زمین بزند."""
    try:
        image_path.unlink()
    except OSError:
        pass


def _read_vehicle_extra(path):
    """VIN و پلاک کارت خودرو؛ خطای این مسیر ویژه نباید کل OCR را زمین بزند."""
    extra = {"vin": None, "plate": None}

    try:
        from app.ocr.vehicle_card_ocr import vehicle_card_ocr

        vin_text, plate_text = vehicle_card_ocr(str(path))
        extra["vin"] = (vin_text or "").strip() or None
        extra["plate"] = (plate_text or "").strip() or None
    except Exception as exc:
        extra["error"] = f"{type(exc).__name__}: {exc}"

    return extra


def ocr_document(path, document_type=None, preprocess=True, out_dir=None, scales=None):
    started = time.perf_counter()

    source = assert_readable(path)

    original = _read_image(source)

    source_height, source_width = original.shape[:2]

    reference_width = _reference_width(document_type, source_width)

    # حالت خام: بدون پیش‌پردازش، بدون تغییر اندازه، یک نسخه — دریچهٔ اشکال‌زدایی
    # که می‌گذارد ببینیم Tesseract روی همان فایل دست‌نخورده چه می‌خواند.
    if not preprocess:
        widths = [(source_width, 1.0)]
    else:
        widths = _plan_widths(scales or DEFAULT_SCALES, reference_width, source_width, source_height)

    target_dir = assert_writable_dir(out_dir or default_out_dir("ocr_document"))

    variants = []

    # همه پرینت‌های ماژول‌های قدیمی به stderr می‌روند
    with contextlib.redirect_stdout(sys.stderr):
        from app.ocr.ocr_engine import extract_text

        for index, (width, _requested_scale) in enumerate(widths):
            variant_started = time.perf_counter()

            # نام قطعی (بدون مهر زمان) تا اجرای دوباره روی همان مدرک فایل تازه
            # روی هم تلنبار نکند. نوعِ مدرک در نام هست چون نمونه‌های دیتاست در
            # هر سه پوشه 001.png نام دارند و بدون آن به هم می‌خورند.
            basename = safe_basename(
                f"{source.stem}_{document_type or 'doc'}_x{width}",
                f"pre_{index}",
            )

            image, image_path, written = _prepare_variant(
                original,
                width,
                preprocess,
                target_dir,
                basename,
                source,
            )

            raw_text = extract_text(str(image_path)) or ""

            extra = {"vin": None, "plate": None}

            if document_type == "vehicle_card":
                extra = _read_vehicle_extra(image_path)

            actual_width = int(image.shape[1])

            variants.append({
                # نسبت **واقعی** به عرض مرجع، نه مقیاسی که خواسته شده بود:
                # سقف‌های MAX_WIDTH/MAX_PIXELS/MAX_UPSCALE می‌توانند عرض را
                # پایین بیاورند و گزارش‌کردن عدد خواسته‌شده تشخیص را گمراه می‌کند.
                "scale": round(actual_width / float(reference_width or actual_width), 3),
                "width": actual_width,
                "height": int(image.shape[0]),
                "image_path": str(image_path),
                "raw_text": raw_text,
                "char_count": len(raw_text),
                "line_count": len([ln for ln in raw_text.splitlines() if ln.strip()]),
                "extra": extra,
                "duration_ms": int((time.perf_counter() - variant_started) * 1000),
            })

            # نسخه‌های کمکی همین‌جا پاک می‌شوند. تصویرِ پیش‌پردازش‌شده تنها تا
            # لحظهٔ OCR لازم است و نگه‌داشتنش یعنی سه برابر شدن تصاویر مدارک
            # هویتی روی دیسک — پوشه‌ای که هیچ‌چیز پاکش نمی‌کند (تسک ۶۶۸).
            # نسخهٔ اول می‌ماند چون `preprocessed_path` قرارداد بیرونی است و
            # صفحهٔ «تصویر تستی» و EngineCheck رویش حساب می‌کنند.
            if index > 0 and written:
                _discard(image_path)
                variants[-1]["image_path"] = None

    primary = variants[0]

    return {
        "document_type": document_type,
        "source_path": str(source),
        "source_width": int(source_width),
        "source_height": int(source_height),
        "reference_width": reference_width,
        # سازگاری با مصرف‌کننده‌های قدیمی: همان کلیدهای قبلی، از نسخهٔ اول
        "ocr_path": primary["image_path"] or str(source),
        "preprocessed_path": primary["image_path"] if preprocess else None,
        "preprocess": bool(preprocess),
        "lang": "fas",
        "config": "--oem 3 --psm 6",
        "raw_text": primary["raw_text"],
        "char_count": primary["char_count"],
        "line_count": primary["line_count"],
        "extra": primary["extra"],
        # تازه: همهٔ نسخه‌ها، برای انتخاب فیلدبه‌فیلد در پنل
        "variants": variants,
        "duration_ms": int((time.perf_counter() - started) * 1000),
    }
