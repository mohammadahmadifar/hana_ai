"""
خواندن شماره شاسی (VIN) و شماره پلاک از تصویر کارت مالکیت خودرو.

قالب پلاک — قرارداد مشترک با ژنراتور
------------------------------------
هم لیبل ژنراتور (app/person/person_generator.py → generate_plate_number)
و هم خروجی این ماژول قالب رسمی ایران را می‌دهند:

    ۱۲ ب ۳۴۵ ایران ۶۷
    └┬┘ │  └┬┘  └┬┘ └┬┘
     │  │   │    │   └── دو رقم کد استان
     │  │   │    └────── واژهٔ ثابت «ایران»
     │  │   └─────────── سه رقم
     │  └─────────────── حرف پلاک
     └────────────────── دو رقم

پیش‌تر ژنراتور «۱۰ ۶۴۳ ج ۷۶» می‌ساخت و format_plate «۱۰ ج ۶۴۳ ۷۶» می‌داد؛
دو ترتیب متفاوت، پس دقت فیلد پلاک ساختاراً صفر بود. حالا هر دو طرف یکی‌اند.

نکتهٔ چیدمان: رشتهٔ پلاک راست‌به‌چپ چاپ می‌شود، پس ترتیب دیداریِ چپ‌به‌راستِ
همان رشته وارونه است: «۶۷ ایران ۳۴۵ ب ۱۲». سگمنتیشن به همین ترتیب دیداری
می‌خواند و format_plate آن را به قالب رسمی برمی‌گرداند.

روش سگمنتیشن (چرا شمار تکه‌ها دیگر نوسان ندارد)
-------------------------------------------------
۱. کادر خودِ پلاک روی کارت پیدا می‌شود (مستطیل با نسبت ≈۳٫۷۷) و با تبدیل
   پرسپکتیو به اندازهٔ ثابت ۷۲۷×۱۹۳ صاف می‌شود. این کار چرخش کارت را هم
   خنثی می‌کند، پس مختصات و مقیاس همهٔ اجزا تکرارپذیر می‌شود.
۲. فقط کادر سفید اصلی بریده می‌شود؛ کادر کوچک «ایران» و خط جداکنندهٔ
   تمام‌قدش بیرون می‌ماند (image_writer هم متن را داخل همان کادر اصلی
   چاپ می‌کند). آن خط قبلاً از روی یک رقم رد می‌شد و خرابش می‌کرد.
۳. سایه و روشنایی ناهموار با تقسیم بر زمینه خنثی می‌شود، وگرنه Otsu
   نیمی از ارقام را می‌خورد.
۴. تکه‌های خیلی پهن یا خیلی بلند (لبهٔ سایه، خط کادر) کنار گذاشته می‌شوند
   و نقطهٔ حروف («ب»، «ج»، «ی»، …) به بدنهٔ حرف ادغام می‌شود — ولی «۰»
   فارسی که خودش کوچک است دست‌نخورده می‌ماند.
۵. تکه‌ها با چهار فاصلهٔ افقیِ بزرگ‌تر به پنج توکن تقسیم می‌شوند و چون
   شمار رقم هر توکن از قالب پلاک معلوم است، تکهٔ اضافه حذف می‌شود.

نتیجه روی ۶۰ نمونهٔ تازه: ۱۲ تکه (۱۳ با حرف «الف») در همهٔ تصویرها.
"""

from pathlib import Path

import cv2
import numpy as np
import pytesseract
from PIL import Image, ImageDraw, ImageFont

from app.config.settings import FONT_PATH, TESSERACT_CMD

PROJECT_ROOT = Path(__file__).resolve().parents[2]

image_path = (
        PROJECT_ROOT /
        "dataset" /
        "preprocessed" /
        "vehicle_card" /
        "016_brightness.png"
)

pytesseract.pytesseract.tesseract_cmd = (
    TESSERACT_CMD
)


# ---------------------------------------------------
# ثابت‌های قالب پلاک
# ---------------------------------------------------

# باید با PLATE_COUNTRY_WORD در app/person/person_generator.py یکی بماند.
PLATE_COUNTRY_WORD = "ایران"

PERSIAN_DIGITS = "۰۱۲۳۴۵۶۷۸۹"

# همان فهرست حروف مجاز ژنراتور
PLATE_LETTERS = [
    "الف", "ب", "ج", "د", "س", "ص", "ط",
    "ق", "ل", "م", "ن", "و", "ه", "ی",
]

# نویسه‌های تشکیل‌دهندهٔ حروف بالا — برای whitelist تسرکت
PLATE_LETTER_CHARS = "".join(
    sorted(set("".join(PLATE_LETTERS)))
)

# تعداد توکن‌های پلاک به ترتیب دیداری: کد استان، «ایران»، سه‌رقمی، حرف، دو‌رقمی
PLATE_TOKEN_COUNT = 5
COUNTRY_TOKEN_INDEX = 1
LETTER_TOKEN_INDEX = 3

# شمار رقم هر توکن رقمی، باز هم به ترتیب دیداری
DIGIT_TOKEN_LENGTHS = {0: 2, 2: 3, 4: 2}


# ---------------------------------------------------
# هندسهٔ کادر پلاک روی قالب dataset/templates/vehicle_card.png
# (کل قالب ۱۶۰۶×۹۷۹ ؛ کادر پلاک x=۶۵۸..۱۳۸۵ و y=۷۸۶..۹۷۹)
# ---------------------------------------------------

PLATE_WIDTH = 727
PLATE_HEIGHT = 193

# نسبت عرض به ارتفاع کادر پلاک ≈ ۳٫۷۷
PLATE_ASPECT_MIN = 3.0
PLATE_ASPECT_MAX = 4.8

# نوار متن = فقط داخل کادر سفید اصلی پلاک (همان جایی که image_writer
# رشتهٔ پلاک را چاپ می‌کند). کادر کوچک «ایران» و خط جداکنندهٔ تمام‌قدش
# عمداً بیرون می‌مانند، چون آن خط سگمنتیشن رقم‌ها را خراب می‌کرد.
# مختصات نسبت به کادر صاف‌شدهٔ ۷۲۷×۱۹۳ است.
BAND_TOP = 15
BAND_BOTTOM = 178
BAND_LEFT = 92
BAND_RIGHT = 532


# ---------------------------------------------------

def crop_vin(image):
    height, width = image.shape[:2]

    x1 = int(width * 0.12)
    x2 = int(width * 0.55)

    y1 = int(height * 0.58)
    y2 = int(height * 0.75)

    return image[y1:y2, x1:x2]


# ---------------------------------------------------

def to_gray(image):
    if image is None:
        return None

    if len(image.shape) == 3:
        return cv2.cvtColor(
            image,
            cv2.COLOR_BGR2GRAY
        )

    return image


# ---------------------------------------------------

def normalize_illumination(gray, kernel_size):
    """
    سایه و روشناییِ ناهموار را برمی‌دارد تا آستانه‌گذاری Otsu محلی نشکند.

    زمینه با یک closing درشت تخمین زده می‌شود (جزئیات تیره و باریک مثل
    متن و خط کادر داخلش نمی‌مانند) و تصویر بر آن تقسیم می‌شود. بدون این
    کار، سایهٔ روی پلاک یا خودِ کادر را از بین می‌برد یا نیمی از ارقام را.
    """
    if kernel_size % 2 == 0:
        kernel_size += 1

    background = cv2.morphologyEx(
        gray,
        cv2.MORPH_CLOSE,
        cv2.getStructuringElement(
            cv2.MORPH_ELLIPSE,
            (kernel_size, kernel_size)
        ),
    )

    return cv2.divide(
        gray,
        background,
        scale=255
    )


# ---------------------------------------------------

def find_plate_frame(gray):
    """
    کادر مستطیلی پلاک را در یک‌سومِ پایینی کارت پیدا می‌کند.

    خروجی: minAreaRect همان کادر، یا None اگر پیدا نشد.
    """
    height, width = gray.shape[:2]

    offset_y = int(height * 0.60)

    roi = normalize_illumination(
        gray[offset_y:, :],
        101
    )

    _, mask = cv2.threshold(
        cv2.GaussianBlur(roi, (5, 5), 0),
        0,
        255,
        cv2.THRESH_BINARY_INV + cv2.THRESH_OTSU
    )

    mask = cv2.morphologyEx(
        mask,
        cv2.MORPH_CLOSE,
        np.ones((7, 7), np.uint8)
    )

    contours, _ = cv2.findContours(
        mask,
        cv2.RETR_EXTERNAL,
        cv2.CHAIN_APPROX_SIMPLE
    )

    best = None

    for contour in contours:

        (center_x, center_y), (rect_w, rect_h), angle = (
            cv2.minAreaRect(contour)
        )

        if rect_w < rect_h:
            rect_w, rect_h, angle = rect_h, rect_w, angle + 90

        if rect_h < height * 0.06 or rect_w < width * 0.20:
            continue

        if abs(angle) > 15:
            continue

        aspect = rect_w / rect_h

        if not (PLATE_ASPECT_MIN < aspect < PLATE_ASPECT_MAX):
            continue

        area = rect_w * rect_h

        if best is None or area > best[0]:
            best = (
                area,
                (
                    (center_x, center_y + offset_y),
                    (rect_w, rect_h),
                    angle,
                ),
            )

    if best is None:
        return None

    return best[1]


# ---------------------------------------------------

def locate_plate(image):
    """
    کادر پلاک را پیدا و به اندازهٔ ثابت PLATE_WIDTH×PLATE_HEIGHT صاف می‌کند.

    چون همهٔ کارت‌ها با همین یک قالب ساخته می‌شوند، بعد از این تبدیل
    جای هر جزء پلاک (حتی روی تصویر چرخیده) تکرارپذیر است.
    """
    gray = to_gray(image)

    if gray is None:
        return None

    rect = find_plate_frame(gray)

    if rect is None:
        return None

    corners = cv2.boxPoints(rect).astype(np.float32)

    # مرتب‌سازی گوشه‌ها: بالا-چپ، بالا-راست، پایین-راست، پایین-چپ
    total = corners.sum(axis=1)
    diff = np.diff(corners, axis=1).ravel()

    source = np.array(
        [
            corners[np.argmin(total)],
            corners[np.argmin(diff)],
            corners[np.argmax(total)],
            corners[np.argmax(diff)],
        ],
        dtype=np.float32,
    )

    target = np.array(
        [
            [0, 0],
            [PLATE_WIDTH - 1, 0],
            [PLATE_WIDTH - 1, PLATE_HEIGHT - 1],
            [0, PLATE_HEIGHT - 1],
        ],
        dtype=np.float32,
    )

    matrix = cv2.getPerspectiveTransform(
        source,
        target
    )

    return cv2.warpPerspective(
        gray,
        matrix,
        (PLATE_WIDTH, PLATE_HEIGHT),
        flags=cv2.INTER_CUBIC,
        borderValue=255,
    )


# ---------------------------------------------------

def crop_plate(image):
    """
    نوار متنِ پلاک. اگر کادر پلاک پیدا شود از روی نسخهٔ صاف‌شده بریده
    می‌شود (پایدار در برابر چرخش)، وگرنه برش نسبی قدیمی انجام می‌شود.
    """
    plate = locate_plate(image)

    if plate is not None:
        return plate[
            BAND_TOP:BAND_BOTTOM,
            BAND_LEFT:BAND_RIGHT,
        ]

    gray = to_gray(image)

    height, width = gray.shape[:2]

    # همان کادر سفید اصلی، ولی نسبی نسبت به کل کارت (x=۷۴۲..۱۲۰۰ از ۱۶۰۶
    # و y=۸۰۰..۹۶۵ از ۹۷۹) — بدون خنثی‌سازی چرخش، پس فقط پشتیبان است.
    x1 = int(width * 0.462)
    x2 = int(width * 0.746)

    y1 = int(height * 0.817)
    y2 = int(height * 0.986)

    return gray[y1:y2, x1:x2]


# ---------------------------------------------------

def binarize_band(band):
    """
    نوار پلاک را دودویی می‌کند: اول سایه را برمی‌دارد، بعد Otsu.

    اگر خط عمودیِ تمام‌قد داخل نوار افتاده باشد (مسیر پشتیبان، وقتی کادر
    پلاک پیدا نشده و برش نسبی انجام شده) ستون‌های آن با «کم‌رنگ‌ترینِ دو
    ستون سالمِ چپ و راست» بازسازی می‌شوند: جایی که قلمِ رقم از خط رد شده
    هر دو طرف جوهر دارند و رقم پیوسته می‌ماند؛ جایی که فقط خط بوده
    دست‌کم یک طرف زمینه است و خط می‌رود.

    خروجی: (نوار نورـیکنواخت‌شده، تصویر دودویی)
    """
    band = normalize_illumination(band, 41)

    _, binary = cv2.threshold(
        cv2.GaussianBlur(band, (3, 3), 0),
        0,
        255,
        cv2.THRESH_BINARY_INV + cv2.THRESH_OTSU
    )

    height, width = binary.shape[:2]

    line_kernel = np.ones(
        (max(9, int(height * 0.80)), 1),
        np.uint8
    )

    vertical = cv2.morphologyEx(
        binary,
        cv2.MORPH_OPEN,
        line_kernel
    )

    columns = np.where(vertical.any(axis=0))[0]

    if columns.size == 0:
        return band, binary

    first = max(int(columns.min()) - 2, 0)
    last = min(int(columns.max()) + 2, width - 1)

    left = first - 1
    right = last + 1

    if left < 0 or right > width - 1:
        return band, binary

    band = band.copy()

    replacement = np.maximum(
        band[:, left],
        band[:, right]
    )

    band[:, first:last + 1] = replacement[:, None]

    _, binary = cv2.threshold(
        cv2.GaussianBlur(band, (3, 3), 0),
        0,
        255,
        cv2.THRESH_BINARY_INV + cv2.THRESH_OTSU
    )

    return band, binary


# ---------------------------------------------------

def merge_dots(boxes):
    """
    نقطهٔ حروف را به بدنهٔ همان حرف می‌چسباند تا شمار تکه‌ها پایدار شود.

    مهم: «۰» فارسی هم تکهٔ کوچکی است ولی نقطه نیست. پس علاوه بر کوچک‌بودن،
    شرط می‌گذاریم که تکهٔ کوچک از نظر افقی تقریباً «زیر یا روی» یک تکهٔ
    بزرگ باشد (دست‌کم ۶۰٪ عرضش با آن هم‌پوشانی داشته باشد). «۰» کنار
    رقم بعدی می‌ایستد، نه زیرش، پس دست‌نخورده می‌ماند.
    """
    if not boxes:
        return []

    heights = sorted(box[3] for box in boxes)

    median_height = heights[len(heights) // 2]

    small = []
    big = []

    for box in boxes:

        if box[3] < median_height * 0.45:
            small.append(box)
        else:
            big.append(box)

    if not big:
        return list(boxes)

    merged = []

    used = [False] * len(small)

    for x, y, w, h in big:

        x2 = x + w
        y2 = y + h

        for index, (dx, dy, dw, dh) in enumerate(small):

            if used[index]:
                continue

            overlap = (
                    min(x2, dx + dw) -
                    max(x, dx)
            )

            if overlap < dw * 0.6:
                continue

            # نقطه باید نزدیک همان حرف باشد، نه در سطر دیگر
            if dy > y2 + median_height or dy + dh < y - median_height:
                continue

            used[index] = True

            new_x = min(x, dx)
            new_y = min(y, dy)

            x2 = max(x2, dx + dw)
            y2 = max(y2, dy + dh)

            x = new_x
            y = new_y

        merged.append((x, y, x2 - x, y2 - y))

    for index, box in enumerate(small):

        if not used[index]:
            merged.append(box)

    return sorted(merged, key=lambda box: box[0])


# ---------------------------------------------------

def segment_plate(plate_image):
    """
    نوار پلاک را به تکه‌های نویسه می‌شکند.

    خروجی: (تصویر خاکستری، تصویر دودویی تمیزشده، فهرست کادرها از چپ به راست)
    """
    plate_image = to_gray(plate_image)

    plate_image, binary = binarize_band(plate_image)

    height, width = binary.shape[:2]

    count, labels, stats, _ = cv2.connectedComponentsWithStats(
        binary,
        8
    )

    def is_glyph_sized(x, y, w, h, area):
        # هیچ نویسهٔ پلاک این‌قدر پهن یا بلند نیست؛ چنین تکه‌ای لبهٔ سایه
        # یا کادر است، نه رقم.
        return (
                area >= 20 and
                w <= width * 0.20 and
                h <= height * 0.55
        )

    candidates = [
        tuple(stats[i][:4])
        for i in range(1, count)
        if is_glyph_sized(*stats[i][:5])
    ]

    if not candidates:
        return plate_image, binary, []

    # سطر متن را از روی تکه‌های بلند (ارقام و حروف) تخمین می‌زنیم تا
    # خرده‌های لبهٔ کادر که بالا یا پایین‌تر افتاده‌اند حذف شوند.
    tall = [
        box for box in candidates
        if box[3] >= height * 0.30
    ] or candidates

    text_top = int(np.median([box[1] for box in tall]))
    text_bottom = int(np.median([box[1] + box[3] for box in tall]))

    cleaned = np.zeros_like(binary)

    boxes = []

    for i in range(1, count):

        x, y, w, h, area = stats[i]

        if not is_glyph_sized(x, y, w, h, area):
            continue

        center_y = y + h / 2

        if center_y < text_top - 15 or center_y > text_bottom + 22:
            continue

        cleaned[labels == i] = 255

        boxes.append((x, y, w, h))

    boxes = merge_dots(boxes)

    boxes = sorted(boxes, key=lambda box: box[0])

    return plate_image, cleaned, boxes


# ---------------------------------------------------

def group_boxes(boxes, expected=PLATE_TOKEN_COUNT):
    """
    تکه‌ها را با بزرگ‌ترین فاصله‌های افقی به «expected» توکن تقسیم می‌کند.

    فاصلهٔ بین توکن‌ها (فاصلهٔ تایپی) روی نمونه‌ها ۱۵ تا ۲۹ پیکسل است و
    فاصلهٔ داخل توکن حداکثر ۱۳ پیکسل؛ پس گرفتنِ «۴ فاصلهٔ بزرگ‌تر»
    از آستانهٔ ثابت مطمئن‌تر است.
    """
    boxes = sorted(boxes, key=lambda box: box[0])

    if len(boxes) < expected:
        return [[box] for box in boxes]

    gaps = []

    right_edge = boxes[0][0] + boxes[0][2]

    for index in range(len(boxes) - 1):

        right_edge = max(
            right_edge,
            boxes[index][0] + boxes[index][2]
        )

        gaps.append(
            (boxes[index + 1][0] - right_edge, index)
        )

    gaps.sort(reverse=True)

    cuts = sorted(
        index
        for gap, index in gaps[:expected - 1]
        if gap > 6
    )

    groups = []

    start = 0

    for cut in cuts:
        groups.append(boxes[start:cut + 1])
        start = cut + 1

    groups.append(boxes[start:])

    return [group for group in groups if group]


# ---------------------------------------------------
# شناساییِ نویسه
# ---------------------------------------------------

GLYPH_NORM = 40

_glyph_templates = {}


def _render_glyph(character, size=120):
    canvas = Image.new(
        "L",
        (size * 4, size * 4),
        0
    )

    ImageDraw.Draw(canvas).text(
        (size, size // 2),
        character,
        fill=255,
        font=ImageFont.truetype(str(FONT_PATH), size)
    )

    pixels = np.array(canvas)

    rows, cols = np.where(pixels > 60)

    return pixels[
        rows.min():rows.max() + 1,
        cols.min():cols.max() + 1,
    ]


def _normalize_glyph(mask):
    """ماسک نویسه را با حفظ نسبت ابعاد در قابی مربعی می‌نشاند."""
    height, width = mask.shape

    scale = GLYPH_NORM / max(height, width)

    resized = cv2.resize(
        mask,
        (
            max(1, int(round(width * scale))),
            max(1, int(round(height * scale))),
        ),
        interpolation=cv2.INTER_AREA,
    )

    frame = np.zeros((GLYPH_NORM, GLYPH_NORM), np.uint8)

    new_height, new_width = resized.shape

    top = (GLYPH_NORM - new_height) // 2
    left = (GLYPH_NORM - new_width) // 2

    frame[top:top + new_height, left:left + new_width] = resized

    return (frame > 90).astype(np.uint8)


def glyph_templates(characters):
    """
    الگوی هر نویسه با همان فونتی که روی کارت چاپ می‌شود.

    تسرکت روی نویسه‌های فارسیِ تک‌افتادهٔ پلاک قابل اتکا نیست (روی همین
    نمونه‌ها حدود نیمی از توکن‌های رقمی را غلط می‌خواند و «ج» را «۹»
    می‌بیند)، اما پلاک با فونت و اندازهٔ معلوم چاپ شده و بعد از
    صاف‌کردن کادر مقیاسش هم ثابت است؛ پس تطبیق الگو جواب قطعی‌تری
    می‌دهد. تسرکت به‌عنوان داورِ تکه‌های مشکوک می‌ماند.
    """
    key = tuple(characters)

    if key not in _glyph_templates:

        table = {}

        for character in characters:

            glyph = _render_glyph(character)

            table[character] = (
                _normalize_glyph(glyph),
                glyph.shape[1] / glyph.shape[0],
            )

        _glyph_templates[key] = table

    return _glyph_templates[key]


def match_glyph(mask, characters):
    """نزدیک‌ترین نویسه به یک تکه: (نویسه، امتیاز، شباهت)."""
    normalized = _normalize_glyph(mask)

    aspect = mask.shape[1] / max(mask.shape[0], 1)

    best = None

    for character, (template, template_aspect) in glyph_templates(characters).items():

        intersection = np.logical_and(normalized, template).sum()
        union = np.logical_or(normalized, template).sum()

        similarity = intersection / max(union, 1)

        penalty = abs(
            np.log(max(aspect, 1e-3) / template_aspect)
        )

        score = similarity - 0.35 * penalty

        if best is None or score > best[1]:
            best = (character, score, similarity)

    return best


def match_digit(mask):
    return match_glyph(mask, PERSIAN_DIGITS)


# ---------------------------------------------------

def _crop_boxes(binary, group, pad=8):
    """برش گروهی از تکه‌ها به‌صورت متن سیاه روی زمینهٔ سفید برای تسرکت."""
    x1 = max(min(box[0] for box in group) - pad, 0)
    y1 = max(min(box[1] for box in group) - pad, 0)

    x2 = min(
        max(box[0] + box[2] for box in group) + pad,
        binary.shape[1]
    )

    y2 = min(
        max(box[1] + box[3] for box in group) + pad,
        binary.shape[0]
    )

    crop = binary[y1:y2, x1:x2]

    crop = cv2.copyMakeBorder(
        crop,
        40, 40, 40, 40,
        cv2.BORDER_CONSTANT,
        value=0
    )

    return 255 - crop


def ocr_character(char_image, whitelist=PERSIAN_DIGITS, psm=10):
    """خواندن یک تکه با تسرکت — پشتیبانِ تطبیق الگو."""
    char = cv2.resize(
        char_image,
        None,
        fx=4,
        fy=4,
        interpolation=cv2.INTER_CUBIC
    )

    char = cv2.copyMakeBorder(
        char,
        30, 30, 30, 30,
        cv2.BORDER_CONSTANT,
        value=0
    )

    _, char = cv2.threshold(
        char,
        0,
        255,
        cv2.THRESH_BINARY + cv2.THRESH_OTSU
    )

    config = (
        f"--oem 3 --psm {psm} "
        f"-c tessedit_char_whitelist={whitelist} "
        "-c load_system_dawg=0 -c load_freq_dawg=0"
    )

    text = pytesseract.image_to_string(
        255 - char,
        lang="fas",
        config=config
    )

    return text.strip()


# آستانهٔ شباهت؛ پایین‌تر از این، جواب تطبیق الگو مشکوک است و
# تسرکت داور می‌شود.
DIGIT_MATCH_MIN_SIMILARITY = 0.55


def read_digit_group(binary, group, expected=None):
    """
    یک توکن رقمی را می‌خواند.

    شمار رقم‌های هر توکن از قالب پلاک معلوم است؛ اگر تکه‌های بیشتری
    پیدا شده باشد (خرده‌های سایه)، فقط همان تعداد تکه‌ای می‌ماند که
    بیشترین شباهت را به یک رقم دارند.
    """
    reads = []

    for x, y, w, h in group:

        patch = binary[y:y + h, x:x + w]

        digit, _score, similarity = match_digit(patch)

        if similarity < DIGIT_MATCH_MIN_SIMILARITY:

            guess = ocr_character(patch)

            if len(guess) == 1 and guess in PERSIAN_DIGITS:
                digit = guess

        reads.append((x, digit, similarity))

    if expected and len(reads) > expected:

        reads = sorted(
            sorted(reads, key=lambda item: -item[2])[:expected]
        )

    return "".join(digit for _x, digit, _s in reads)


def clean_letter(text):
    """
    خروجی تسرکت را به یکی از حروف مجاز پلاک می‌رساند.

    تسرکت گاهی یک «ا» اضافه می‌چسباند («اص» به‌جای «ص»)؛ بلندترین
    حرف مجازی که داخل رشته هست برداشته می‌شود («الف» قبل از «ل»).
    """
    text = (text or "").strip().replace(" ", "")

    if text in PLATE_LETTERS:
        return text

    for letter in sorted(PLATE_LETTERS, key=len, reverse=True):

        if letter in text:
            return letter

    return text


def _group_mask(binary, group):
    """ماسک کل یک توکن (همهٔ تکه‌هایش) برای تطبیق الگو."""
    x1 = min(box[0] for box in group)
    y1 = min(box[1] for box in group)

    x2 = max(box[0] + box[2] for box in group)
    y2 = max(box[1] + box[3] for box in group)

    return binary[y1:y2, x1:x2]


def read_letter_group(binary, group):
    guess = pytesseract.image_to_string(
        _crop_boxes(binary, group),
        lang="fas",
        config=(
            "--oem 3 --psm 8 "
            f"-c tessedit_char_whitelist={PLATE_LETTER_CHARS} "
            "-c load_system_dawg=0 -c load_freq_dawg=0"
        ),
    )

    letter = clean_letter(guess)

    if letter in PLATE_LETTERS:
        return letter

    # تسرکت حرف را نشناخت (مثلاً «ج» را «۹» می‌بیند) — تطبیق الگو داور است
    return match_glyph(
        _group_mask(binary, group),
        PLATE_LETTERS
    )[0]


# ---------------------------------------------------

def read_plate(binary, boxes):
    """
    توکن‌های پلاک را به ترتیب دیداری (چپ به راست) برمی‌گرداند؛
    مثلاً «۶۷ ایران ۳۴۵ ب ۱۲». format_plate آن را به قالب رسمی می‌چیند.
    """
    groups = group_boxes(boxes)

    if len(groups) != PLATE_TOKEN_COUNT:

        # ساختار پلاک شناخته نشد: هر تکه را جدا می‌خوانیم تا دست‌کم
        # چیزی برای عیب‌یابی بماند.
        return "".join(
            match_digit(binary[y:y + h, x:x + w])[0]
            for x, y, w, h in boxes
        )

    tokens = []

    for index, group in enumerate(groups):

        if index == COUNTRY_TOKEN_INDEX:
            tokens.append(PLATE_COUNTRY_WORD)

        elif index == LETTER_TOKEN_INDEX:
            tokens.append(read_letter_group(binary, group))

        else:
            tokens.append(
                read_digit_group(
                    binary,
                    group,
                    DIGIT_TOKEN_LENGTHS[index],
                )
            )

    return " ".join(tokens)


# ---------------------------------------------------

def format_plate(text):
    """
    قالب رسمی پلاک: «۱۲ ب ۳۴۵ ایران ۶۷».

    ورودی معمولاً خروجی read_plate است (ترتیب دیداری، وارونهٔ قالب رسمی).
    ورودیِ از قبل درست هم دست‌نخورده برمی‌گردد.
    """
    tokens = (text or "").split()

    if len(tokens) == PLATE_TOKEN_COUNT:

        if tokens[3] == PLATE_COUNTRY_WORD:
            return " ".join(tokens)

        if tokens[COUNTRY_TOKEN_INDEX] == PLATE_COUNTRY_WORD:
            return " ".join(reversed(tokens))

    # حالت پشتیبان: رشتهٔ بی‌فاصله به ترتیب دیداری
    plain = "".join(
        (text or "").split()
    ).replace(PLATE_COUNTRY_WORD, "")

    if len(plain) < 8:
        return plain

    province = plain[:2]
    three = plain[2:5]
    letter = plain[5:-2]
    two = plain[-2:]

    return (
        f"{two} {letter} {three} "
        f"{PLATE_COUNTRY_WORD} {province}"
    )


# ---------------------------------------------------

def extract_vin(vin_image):
    custom_config = (
        r"--oem 3 --psm 7"
    )

    text = pytesseract.image_to_string(
        vin_image,
        lang="eng",
        config=custom_config
    )

    return text.strip()


# ---------------------------------------------------

def save_debug_image(binary, boxes, path="debug_segments.png"):
    debug = cv2.cvtColor(
        binary,
        cv2.COLOR_GRAY2BGR
    )

    for x, y, w, h in boxes:
        pad = 5  # مقدار بزرگ‌تر کردن کادر

        cv2.rectangle(
            debug,
            (x - pad, y - pad),
            (x + w + pad, y + h + pad),
            (0, 255, 0),
            2
        )

    try:
        cv2.imwrite(str(path), debug)
    except (cv2.error, OSError):
        # مسیر جاری قابل نوشتن نیست — عیب‌یابی نباید OCR را زمین بزند
        pass


# ---------------------------------------------------


def vehicle_card_ocr(image_path):
    image = cv2.imread(
        str(image_path)
    )

    if image is None:
        raise FileNotFoundError(
            f"تصویر کارت خودرو خوانده نشد: {image_path}"
        )

    vin_text = extract_vin(
        crop_vin(image)
    )

    plate_gray, binary, boxes = segment_plate(
        crop_plate(image)
    )

    plate_text = format_plate(
        read_plate(binary, boxes)
    )

    print(f"Plate : {plate_text}")

    save_debug_image(binary, boxes)

    return vin_text, plate_text


# ---------------------------------------------------

if __name__ == "__main__":
    vin, plate = vehicle_card_ocr(
        str(image_path)
    )
