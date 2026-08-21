"""
نقطهٔ ورود پایپ‌لاین پایان‌نامه.

مسیر کار: تولید نمونهٔ مصنوعی ← پیش‌پردازش ← OCR ← ارزیابی.

اجرا (همیشه از ریشهٔ پروژه، چون مسیرهای دیتاست نسبی‌اند):

    .venv/bin/python main.py                # یک شخص تازه + پردازش نمونه‌های جدید
    .venv/bin/python main.py --number 10    # ده شخص تازه
    .venv/bin/python main.py --number 0     # بدون تولید؛ فقط کارِ عقب‌افتاده و گزارش
    .venv/bin/python main.py --all          # همه‌چیز را از نو پردازش کن

چرا «فقط نمونه‌های جدید»
------------------------
پیش‌تر هر اجرا کل پوشه را دوباره پیش‌پردازش و OCR می‌کرد، پس اجرای پنجاهم
پنجاه تصویر را از نو می‌خواند در حالی که فقط یکی‌شان تازه بود. حالا هر
تصویری که خروجی متناظرش از خودش تازه‌تر است رد می‌شود. با `--all` همان
رفتار قدیمی (پردازش کامل) برمی‌گردد — مثلاً وقتی الگوریتم عوض شده و
خروجی‌های قبلی دیگر معتبر نیستند.
"""

import argparse
import tempfile
from contextlib import contextmanager
from pathlib import Path

from app.evaluation.ocr_evaluation import evaluate_folder, print_overall_report
from app.ocr.ocr_engine import ocr_folder
from app.person.person_generator import generate_person
from app.preprocessing.image_preprocessing import preprocess_folder
from dataset.image_writer import (
    create_driving_license,
    create_national_card,
    create_vehicle_card,
)

DATASET = Path("dataset")

DOCUMENT_TYPES = (
    "national_card",
    "driving_license",
    "vehicle_card",
)


# -------------------------------------
# تولید

def generate_people(number):
    """برای هر شخص، هر سه مدرک را می‌سازد (هر مدرک لیبل خودش را هم می‌نویسد)."""

    for _ in range(number):

        person = generate_person()

        create_national_card(person)

        create_driving_license(person)

        create_vehicle_card(person)

    print(f"تولید            : {number} شخص × ۳ مدرک")


# -------------------------------------
# انتخاب کارِ باقی‌مانده

def is_up_to_date(source_path, result_path):
    return (
        result_path.exists()
        and result_path.stat().st_mtime >= source_path.stat().st_mtime
    )


def pick_pending(source_folder, output_folder, output_name, force):
    """تصویرهایی که هنوز خروجی به‌روز ندارند (یا همه، اگر force باشد)."""

    images = sorted(
        Path(source_folder).glob("*.png")
    )

    if force:
        return images

    return [

        image_path

        for image_path in images

        if not is_up_to_date(
            image_path,
            Path(output_folder) / output_name(image_path)
        )
    ]


@contextmanager
def folder_of(images, document_type):
    """پوشهٔ موقتی که فقط به همین تصویرها پیوند نمادین دارد.

    preprocess_folder و ocr_folder کل پوشهٔ ورودی را می‌خوانند و پارامتر
    «فقط این چند تا» ندارند. این پوشهٔ موقت همان فیلتر را می‌سازد، بدون
    کپی‌کردن تصویر و بدون دست‌بردن در آن دو تابع. نام پوشه عمداً همان نوع
    مدرک است، چون ocr_folder کارت خودرو را از روی نام مسیر ورودی می‌شناسد.
    """

    with tempfile.TemporaryDirectory(prefix="hana_pipeline_") as root:

        staging = Path(root) / document_type

        staging.mkdir()

        for image_path in images:

            (staging / image_path.name).symlink_to(
                image_path.resolve()
            )

        yield staging


# -------------------------------------
# مراحل

def preprocess_new(document_type, force):
    source_folder = DATASET / "processed" / document_type
    output_folder = DATASET / "preprocessed" / document_type

    images = pick_pending(
        source_folder,
        output_folder,
        lambda image_path: image_path.name,
        force
    )

    if not images:
        print(f"پیش‌پردازش {document_type}: چیز تازه‌ای نیست")
        return

    output_folder.mkdir(parents=True, exist_ok=True)

    with folder_of(images, document_type) as staging:

        preprocess_folder(

            staging,

            output_folder
        )

    print(f"پیش‌پردازش {document_type}: {len(images)} تصویر")


def ocr_new(document_type, force):
    source_folder = DATASET / "preprocessed" / document_type
    output_folder = DATASET / "ocr_results" / document_type

    # ocr_folder خروجی را با شمارهٔ ابتدای نام تصویر می‌نویسد: 007_blur.png ← 007.txt
    images = pick_pending(
        source_folder,
        output_folder,
        lambda image_path: image_path.stem.split("_")[0] + ".txt",
        force
    )

    if not images:
        print(f"OCR {document_type}: چیز تازه‌ای نیست")
        return

    output_folder.mkdir(parents=True, exist_ok=True)

    with folder_of(images, document_type) as staging:

        ocr_folder(

            staging,

            output_folder
        )

    print(f"OCR {document_type}: {len(images)} تصویر")


def evaluate(document_type):
    return evaluate_folder(

        labels_folder=DATASET / "labels" / document_type,

        ocr_folder=DATASET / "ocr_results" / document_type
    )


# -------------------------------------

def parse_args():
    parser = argparse.ArgumentParser(
        description=(
            "پایپ‌لاین hana_ai: تولید نمونهٔ مصنوعی، پیش‌پردازش، OCR و ارزیابی."
        )
    )

    parser.add_argument(
        "-n", "--number",
        type=int,
        default=1,
        help="چند شخص تازه ساخته شود (پیش‌فرض ۱؛ صفر یعنی فقط پردازش و گزارش)"
    )

    parser.add_argument(
        "--all",
        dest="force",
        action="store_true",
        help="همهٔ تصویرها را دوباره پردازش کن، نه فقط نمونه‌های جدید"
    )

    return parser.parse_args()


def main():
    args = parse_args()

    if args.number < 0:
        raise SystemExit("مقدار --number نمی‌تواند منفی باشد.")

    generate_people(args.number)

    for document_type in DOCUMENT_TYPES:
        preprocess_new(document_type, args.force)

    for document_type in DOCUMENT_TYPES:
        ocr_new(document_type, args.force)

    print_overall_report([

        evaluate(document_type)

        for document_type in DOCUMENT_TYPES
    ])


if __name__ == "__main__":
    main()
