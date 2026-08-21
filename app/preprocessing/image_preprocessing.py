"""
پیش‌پردازش تصویر مدرک، پیش از OCR.

هر مرحله یک تابع مستقل است که «تصویر» می‌گیرد و «تصویر» برمی‌گرداند؛
ترتیب اجرا فقط در PREPROCESS_STEPS تعریف می‌شود. برای افزودن مرحلهٔ جدید
(مثلاً آستانه‌گذاری تطبیقی یا بزرگ‌نمایی) کافی است تابعش نوشته و نامش به
همان فهرست اضافه شود؛ نیازی به دست‌زدن به preprocess_image نیست.

مرحلهٔ deskew: زاویهٔ چرخش تصویر تخمین زده و معکوس آن اعمال می‌شود، چون
Tesseract روی متن کج دقت را از دست می‌دهد. اندازهٔ خروجی عمداً با ورودی
یکی می‌ماند تا برش‌های نسبی (VIN و پلاک در app/ocr/vehicle_card_ocr.py و
کادرهای نسبی hana_engine/layouts.py) نشکنند.

مرحلهٔ flatten_illumination: روشناییِ ناهموار (سایه، گرادیان زمینهٔ قالب،
اعوجاج brightness) را برمی‌دارد تا شدتِ مرکب همهٔ نقاط تصویر یکسان شود.
بدون آن، متنِ روی ناحیهٔ تیره‌تر بعد از خاکستری‌سازی کم‌کنتراست می‌ماند و
Tesseract سطرش را نمی‌بیند. اندازهٔ تصویر عوض نمی‌شود، پس مثل deskew هیچ
برش نسبی‌ای را نمی‌شکند.
"""

import cv2
import numpy as np

from pathlib import Path


# -------------------------------------
# پارامترهای deskew
# -------------------------------------

# بیشترین زاویه‌ای که «چرخش تصادفی مدرک» فرض می‌شود.
# بیرون این بازه، تخمین را نتیجهٔ نویز می‌دانیم و تصویر را دست‌نخورده رها می‌کنیم
# تا deskew روی تصویر صاف خرابی نسازد.
MAX_DESKEW_ANGLE = 15.0

# زیر این زاویه چرخش نمی‌کنیم؛ سود آن از زیان درون‌یابی دوبارهٔ پیکسل‌ها کمتر است.
MIN_DESKEW_ANGLE = 0.75

# گام جست‌وجوی زاویه: اول درشت، بعد ریز دور بهترین نتیجهٔ مرحلهٔ اول.
DESKEW_COARSE_STEP = 1.0
DESKEW_FINE_STEP = 0.1

# عرض تصویرِ کوچک‌شده برای تخمین زاویه (تخمین را ارزان نگه می‌دارد).
DESKEW_ESTIMATE_WIDTH = 600

# سهم مجاز پیکسل‌های متن از کل تصویر. روی مدارک واقعی این عدد ۰.۰۳ تا ۰.۱۸ است؛
# بیرون این بازه یعنی تصویر یکدست (کاملاً سفید یا کاملاً سیاه) است و آستانهٔ اوتسو
# متن و زمینه را از هم جدا نکرده — آن‌جا هر زاویه‌ای که تخمین بزنیم حدس است.
MIN_TEXT_COVERAGE = 0.005
MAX_TEXT_COVERAGE = 0.5


# -------------------------------------
# پارامترهای یکنواخت‌سازی روشنایی
# -------------------------------------

# قطر کرنل تخمین زمینه. باید از ضخامت هر حرفی بزرگ‌تر باشد وگرنه خودِ متن
# جزو «زمینه» تخمین زده می‌شود و پاک می‌شود؛ و باید از پهنای سایه کوچک‌تر
# بماند وگرنه سایه در تخمین گم می‌شود. ۳۱ روی هر سه قالب فعلی جواب می‌دهد.
FLATTEN_KERNEL = 31

# نرم‌کردن زمینهٔ تخمینی؛ بدون آن، لبهٔ کرنل روی خروجی هالهٔ پله‌ای می‌سازد.
FLATTEN_BLUR = 15


# -------------------------------------
# پارامترهای حذف نویز
# -------------------------------------

# قطر همسایگی فیلتر دوطرفه. بزرگ‌ترش ارقام چسبیده را در هم می‌برد؛
# ۹ با sigma ۷۵ روی همین دیتاست دقت کارت ملی را از ۶۸٪ به ۴۱٪ آورد.
DENOISE_DIAMETER = 5

# هرچه بزرگ‌تر، اختلاف رنگ بیشتری «نویز» حساب می‌شود. روی ۵۰ متن سیاه
# روی زمینهٔ روشن دست‌نخورده می‌ماند و فقط دانه‌های زمینه صاف می‌شوند.
DENOISE_SIGMA_COLOR = 50
DENOISE_SIGMA_SPACE = 50


# -------------------------------------


def convert_to_gray(image):

    if image.ndim == 2:

        return image

    return cv2.cvtColor(

        image,

        cv2.COLOR_BGR2GRAY

    )


# -------------------------------------


def build_text_mask(gray_image):
    """
    ماسک دودویی متن: پیکسل‌های تیره روی زمینهٔ روشن سفید می‌شوند.
    مبنای تخمین زاویه است، نه خروجی پایپ‌لاین.
    """

    blurred = cv2.GaussianBlur(

        gray_image,

        (3, 3),

        0

    )

    mask = cv2.threshold(

        blurred,

        0,

        255,

        cv2.THRESH_BINARY_INV + cv2.THRESH_OTSU

    )[1]

    return mask


# -------------------------------------


def normalize_angle(angle):
    """
    زاویه را به بازهٔ (‎-۴۵‎, ‎+۴۵‎] می‌آورد؛ چرخش ۹۰ درجه‌ای برای یک مدرک
    افقی معنا ندارد و minAreaRect زاویه را به‌دلخواه در ۰..۹۰ گزارش می‌کند.
    """

    while angle <= -45.0:
        angle += 90.0

    while angle > 45.0:
        angle -= 90.0

    return angle


# -------------------------------------


def line_separation_score(mask, angle):
    """
    معیار «چقدر سطرهای متن از هم جدا شده‌اند»: ماسک را به اندازهٔ angle
    می‌چرخاند، پیکسل‌های هر ردیف را جمع می‌زند و پرش‌های نمودار حاصل را
    اندازه می‌گیرد. وقتی متن افقی شود، سطرها و فاصله‌هایشان بیشترین
    تضاد را دارند و این عدد بیشینه می‌شود.
    """

    height, width = mask.shape[:2]

    matrix = cv2.getRotationMatrix2D(

        (width / 2.0, height / 2.0),

        angle,

        1.0

    )

    rotated = cv2.warpAffine(

        mask,

        matrix,

        (width, height),

        flags=cv2.INTER_NEAREST,

        borderMode=cv2.BORDER_CONSTANT,

        borderValue=0

    )

    projection = rotated.sum(axis=1)

    difference = projection[1:] - projection[:-1]

    return float(

        (difference ** 2).sum()

    )


# -------------------------------------


def angle_from_min_area_rect(mask):
    """
    تخمین سریع زاویه با کادر کمینهٔ دور همهٔ پیکسل‌های متن.
    به‌تنهایی روی مدرکِ پر از عکس و لوگو لغزش دارد، برای همین فقط یکی از
    گزینه‌های estimate_skew_angle است و داور نهایی، امتیاز جداییِ سطرهاست.
    """

    points = cv2.findNonZero(mask)

    if points is None:

        return 0.0

    angle = cv2.minAreaRect(points)[-1]

    return normalize_angle(angle)


# -------------------------------------


def estimate_skew_angle(gray_image,
                        max_angle=MAX_DESKEW_ANGLE):
    """
    زاویهٔ اصلاح را برمی‌گرداند: عددی که اگر تصویر به‌اندازهٔ آن چرخانده شود،
    متن افقی می‌شود. صفر یعنی «نچرخان».
    """

    height, width = gray_image.shape[:2]

    scale = 1.0

    if width > DESKEW_ESTIMATE_WIDTH:

        scale = DESKEW_ESTIMATE_WIDTH / float(width)

    small = cv2.resize(

        gray_image,

        None,

        fx=scale,

        fy=scale,

        interpolation=cv2.INTER_AREA

    )

    mask = build_text_mask(small)

    coverage = cv2.countNonZero(mask) / float(mask.size)

    if coverage < MIN_TEXT_COVERAGE or coverage > MAX_TEXT_COVERAGE:

        return 0.0

    mask = mask.astype(np.float32) / 255.0

    # جست‌وجوی درشت روی کل بازهٔ مجاز
    coarse = max(

        np.arange(-max_angle, max_angle + 1e-9, DESKEW_COARSE_STEP),

        key=lambda angle: line_separation_score(mask, angle)

    )

    # جست‌وجوی ریز دور بهترین زاویهٔ مرحلهٔ قبل
    candidate = max(

        np.arange(

            coarse - DESKEW_COARSE_STEP,

            coarse + DESKEW_COARSE_STEP + 1e-9,

            DESKEW_FINE_STEP

        ),

        key=lambda angle: line_separation_score(mask, angle)

    )

    candidates = [

        0.0,

        float(candidate),

        angle_from_min_area_rect(

            (mask * 255).astype(np.uint8)

        )

    ]

    # زاویهٔ خارج از بازهٔ منطقی اصلاً نامزد نیست
    candidates = [

        angle for angle in candidates

        if abs(angle) <= max_angle

    ]

    best = max(

        candidates,

        key=lambda angle: line_separation_score(mask, angle)

    )

    if abs(best) < MIN_DESKEW_ANGLE:

        return 0.0

    return float(best)


# -------------------------------------


def rotate_image(image, angle):
    """
    چرخش حول مرکز، با همان ابعاد ورودی.
    حاشیه با BORDER_REPLICATE پر می‌شود تا گوشه‌ها نه سیاه شوند و نه یک
    مثلث سفیدِ پرکنتراست بسازند؛ هر دو حالت برای Tesseract متنِ جعلی می‌سازد.
    """

    height, width = image.shape[:2]

    matrix = cv2.getRotationMatrix2D(

        (width / 2.0, height / 2.0),

        angle,

        1.0

    )

    return cv2.warpAffine(

        image,

        matrix,

        (width, height),

        flags=cv2.INTER_CUBIC,

        borderMode=cv2.BORDER_REPLICATE

    )


# -------------------------------------


def deskew_image(image):
    """
    مرحلهٔ پایپ‌لاین: تخمین زاویه و چرخش معکوس.
    اگر زاویه ناچیز یا نامعتبر بود، همان تصویر ورودی برمی‌گردد.
    """

    gray = convert_to_gray(image)

    angle = estimate_skew_angle(gray)

    if angle == 0.0:

        return image

    return rotate_image(image, angle)


# -------------------------------------


def estimate_background(gray_image,
                        kernel_size=FLATTEN_KERNEL,
                        blur_sigma=FLATTEN_BLUR):
    """
    تخمین «زمینه» تصویر: تصویر با یک closing درشت پر می‌شود تا جزئیات تیره و
    باریک (متن) داخلش نمانند و فقط روشناییِ کلی هر ناحیه باقی بماند.
    """

    if kernel_size % 2 == 0:

        kernel_size += 1

    background = cv2.morphologyEx(

        gray_image,

        cv2.MORPH_CLOSE,

        cv2.getStructuringElement(

            cv2.MORPH_ELLIPSE,

            (kernel_size, kernel_size)

        )

    )

    return cv2.GaussianBlur(

        background,

        (0, 0),

        blur_sigma

    )


# -------------------------------------


def flatten_illumination(image):
    """
    مرحلهٔ پایپ‌لاین: تقسیم تصویر بر زمینهٔ تخمینی.

    هر پیکسل به «چقدر از همسایگی خودش تیره‌تر است» تبدیل می‌شود، پس زمینه
    همه‌جا سفید و متن همه‌جا سیاه درمی‌آید — چه روی ناحیهٔ روشن قالب باشد و
    چه روی گرادیان یا سایه. ورودی باید خاکستری باشد (بعد از convert_to_gray).
    """

    gray = convert_to_gray(image)

    return cv2.divide(

        gray,

        estimate_background(gray),

        scale=255

    )


# -------------------------------------


def remove_noise(image):
    """
    حذف نویز با فیلتر دوطرفه (bilateral) به‌جای fastNlMeansDenoising.

    تسک ۶۴۰: fastNlMeansDenoising با h=۱۵ روی متن فارسیِ ریز، قلمِ نازک
    ارقام را هم «نویز» می‌دید و می‌خورد؛ فیلتر دوطرفه لبه را نگه می‌دارد و
    فقط ناهمواری هم‌رنگ را صاف می‌کند. اندازه‌گیری روی ۴۵ نمونه (با همان
    بقیهٔ مراحل): کارت ملی ۸۶.۳٪ → ۹۳.۰٪ ، CER گواهینامه ۶.۳٪ → ۴.۰٪ ،
    و کد ملی گواهینامه که با NLM افت کرده بود برگشت. ضمناً حدود ۲۰٪ سریع‌تر است.
    """

    return cv2.bilateralFilter(

        image,

        DENOISE_DIAMETER,

        DENOISE_SIGMA_COLOR,

        DENOISE_SIGMA_SPACE

    )


# -------------------------------------


def sharpen_image(image):

    kernel = np.array([

        [0, -1, 0],

        [-1, 5, -1],

        [0, -1, 0]

    ])

    return cv2.filter2D(

        image,

        -1,

        kernel

    )


# -------------------------------------
# ترتیب مراحل پیش‌پردازش — تنها جای تعریف پایپ‌لاین
# -------------------------------------

PREPROCESS_STEPS = [

    # تبدیل به سیاه و سفید
    convert_to_gray,

    # صاف کردن چرخش مدرک
    deskew_image,

    # یکنواخت کردن روشنایی (سایه و گرادیان زمینه)
    flatten_illumination,

    # حذف نویز
    remove_noise,

    # شارپ کردن متن‌ها
    sharpen_image

]


# -------------------------------------


def apply_steps(image,
                steps=None):

    if steps is None:

        steps = PREPROCESS_STEPS

    for step in steps:

        image = step(image)

    return image


# -------------------------------------


def save_preprocessed_image(image,
                            output_path):

    output_path = Path(

        output_path

    )

    output_path.parent.mkdir(

        parents=True,

        exist_ok=True

    )

    cv2.imwrite(

        str(output_path),

        image

    )


# -------------------------------------


def preprocess_image(image_path,
                     output_path,
                     steps=None):

    image = cv2.imread(

        str(image_path)

    )

    if image is None:

        raise ValueError(

            "تصویر خوانده نشد؛ مسیر یا فرمت فایل را بررسی کنید: "
            f"{image_path}"

        )

    image = apply_steps(

        image,

        steps

    )

    # ذخیره خروجی
    save_preprocessed_image(

        image,

        output_path

    )


# -------------------------------------


def preprocess_folder(input_folder,
                      output_folder,
                      steps=None):

    input_folder = Path(

        input_folder

    )

    output_folder = Path(

        output_folder

    )

    for image_path in input_folder.glob("*.png"):

        output_path = (

            output_folder /

            image_path.name

        )

        preprocess_image(

            image_path,

            output_path,

            steps

        )
