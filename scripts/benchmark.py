"""
اندازه‌گیری عدد پایهٔ موتور — همیشه روی نمونه‌های تازه.

چرا این اسکریپت جدا از main.py است
-----------------------------------
`main.py` گزارشش را روی **همهٔ** فایل‌های dataset می‌گیرد. اشکالش این است که
قالب‌ها و ژنراتور عوض می‌شوند (تسک ۶۳۷ قالب پلاک را عوض کرد، تسک ۶۴۰ اندازهٔ
فونت‌ها را) ولی نمونه‌های قدیمیِ روی دیسک با لیبل‌های همان زمان می‌مانند. آن
نمونه‌ها با موتور امروز هرگز درست خوانده نمی‌شوند و عدد را بی‌دلیل پایین
می‌کشند: اندازه‌گیری ۱۴۰۵/۰۵/۳۱ روی ۵۰ نمونه ۷۸٫۶٪ داد، ولی همان موتور روی
۲۵ نمونهٔ تازه ۸۶٫۸٪ — یعنی ۸ واحد اختلاف، صرفاً از کهنگی فایل‌ها.

پس قاعده: **عدد پایه فقط روی بازه‌ای از نمونه‌ها معنا دارد که با ژنراتور
امروز ساخته شده باشند.** این اسکریپت آن بازه را صریح می‌گیرد یا خودش می‌سازد.

اجرا (همیشه از ریشهٔ پروژه)
---------------------------
    .venv/bin/python scripts/benchmark.py --number 25   # ۲۵ نمونهٔ تازه بساز و فقط همان‌ها را بسنج
    .venv/bin/python scripts/benchmark.py --last 25     # بدون تولید؛ ۲۵ نمونهٔ آخر
    .venv/bin/python scripts/benchmark.py --from 26 --to 50
    .venv/bin/python scripts/benchmark.py --last 25 --variants   # مسیر پنل: OCR چندمقیاسی

دو مسیر، دو عدد
---------------
بدون سوییچ، همان چیزی سنجیده می‌شود که `main.py` می‌سازد: یک OCR روی
`dataset/preprocessed`. این «عدد پایهٔ پایان‌نامه» است.

با `--variants` همان تصویرها از راهی می‌روند که **پنل** می‌رود:
`hana_engine.ocr.ocr_document` که مدرک را در چند بزرگ‌نمایی می‌خواند (تسک ۶۶۲)
و متن همهٔ نسخه‌ها پشت سر هم سنجیده می‌شود. کندتر است و همان معیار را
روی مسیر واقعی پرونده می‌دهد.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

# main.py و dataset/image_writer.py مسیرهایشان نسبی است؛ بدون این، اجرای
# اسکریپت از هر جای دیگری نمونه‌ها را جای اشتباه می‌نویسد.
os.chdir(ROOT)

from app.evaluation.ocr_evaluation import compare_fields_detailed  # noqa: E402

DATASET = ROOT / "dataset"

DOCUMENT_TYPES = (
    "national_card",
    "driving_license",
    "vehicle_card",
)


# -------------------------------------
# انتخاب بازهٔ نمونه‌ها


def sample_numbers(document_type):
    """شماره‌های نمونهٔ موجود برای یک نوع مدرک (از نام فایل لیبل)."""

    folder = DATASET / "labels" / document_type

    numbers = []

    for label in folder.glob("*.json"):

        if label.stem.isdigit():

            numbers.append(int(label.stem))

    return sorted(numbers)


def highest_sample_number():
    """بزرگ‌ترین شمارهٔ نمونه در همهٔ انواع مدرک (صفر یعنی دیتاست خالی)."""

    highest = 0

    for document_type in DOCUMENT_TYPES:

        numbers = sample_numbers(document_type)

        if numbers:

            highest = max(highest, numbers[-1])

    return highest


# -------------------------------------
# تولید نمونهٔ تازه


def generate(number):
    """`number` شخص تازه با هر سه مدرکش؛ همان مسیر main.py."""

    from app.person.person_generator import generate_person
    from dataset.image_writer import (
        create_driving_license,
        create_national_card,
        create_vehicle_card,
    )

    for _ in range(number):

        person = generate_person()

        create_national_card(person)

        create_driving_license(person)

        create_vehicle_card(person)


def process_pending():
    """پیش‌پردازش و OCR کارِ عقب‌افتاده — همان توابع main.py، بدون تولید تازه."""

    import main

    for document_type in DOCUMENT_TYPES:

        main.preprocess_new(document_type, force=False)

    for document_type in DOCUMENT_TYPES:

        main.ocr_new(document_type, force=False)


# -------------------------------------
# اندازه‌گیری


def evaluate_range(document_type, first, last, use_variants=False):
    """
    خلاصهٔ یک نوع مدرک روی بازهٔ [first, last].

    `use_variants` یعنی متن از مسیر چندمقیاسی موتور گرفته شود، نه از
    `dataset/ocr_results` که main.py نوشته.
    """

    labels_folder = DATASET / "labels" / document_type
    ocr_folder = DATASET / "ocr_results" / document_type

    total = exact = 0
    cer_sum = 0.0
    fields = {}
    images = 0

    for number in sample_numbers(document_type):

        if not (first <= number <= last):
            continue

        label_file = labels_folder / f"{number:03d}.json"

        if not label_file.is_file():
            continue

        if use_variants:
            text = variant_text(document_type, number)
        else:
            ocr_file = ocr_folder / f"{number:03d}.txt"
            text = ocr_file.read_text(encoding="utf-8") if ocr_file.is_file() else None

        if text is None:
            continue

        images += 1

        detail = compare_fields_detailed(
            json.loads(label_file.read_text(encoding="utf-8")),
            text,
        )

        for field_name, result in detail.items():

            total += 1
            cer_sum += result["cer"]

            row = fields.setdefault(field_name, {"n": 0, "exact": 0, "cer": 0.0})
            row["n"] += 1
            row["cer"] += result["cer"]

            if result["exact"]:
                exact += 1
                row["exact"] += 1

    return {
        "document_type": document_type,
        "images": images,
        "total": total,
        "exact": exact,
        "cer": cer_sum,
        "fields": fields,
    }


def variant_text(document_type, number):
    """
    متن همهٔ نسخه‌های چندمقیاسی یک نمونه، پشت سر هم.

    معیارِ «تطابق کامل» زیررشته‌ای است، پس چسباندن متن نسخه‌ها دقیقاً همان
    چیزی را می‌سنجد که استخراج‌گر پنل در اختیار دارد: مقدار درست کافی است در
    **یکی** از نسخه‌ها آمده باشد. انتخاب بین نسخه‌ها کار FieldExtractor است و
    این معیار سقفِ آن انتخاب را نشان می‌دهد.
    """
    from hana_engine import ENGINE_ROOT
    from hana_engine.ocr import ocr_document

    sources = sorted((DATASET / "processed" / document_type).glob(f"{number:03d}_*.png"))

    if not sources:
        return None

    result = ocr_document(
        str(sources[0]),
        document_type=document_type,
        out_dir=str(ENGINE_ROOT / "dataset" / "benchmark"),
    )

    pieces = []

    for variant in result["variants"]:

        pieces.append(variant["raw_text"])

        # همان کاری که app/ocr/ocr_engine.ocr_folder برای کارت خودرو می‌کند:
        # VIN و پلاک از مسیر ویژه می‌آیند نه از متن صفحه، پس اگر این‌جا
        # اضافه نشوند معیارِ زیررشته‌ای آن‌ها را «خوانده‌نشده» می‌بیند و دو
        # حالت اسکریپت با هم قابل مقایسه نمی‌مانند.
        extra = variant.get("extra") or {}

        if extra.get("vin"):
            pieces.append(f"VIN : {extra['vin']}")

        if extra.get("plate"):
            pieces.append(f"PLATE : {extra['plate']}")

    return "\n".join(pieces)


def percent(part, whole):
    return 100.0 * part / whole if whole else 0.0


def print_report(summaries, first, last):

    print()
    print("=" * 72)
    print(f"عدد پایهٔ موتور — نمونه‌های {first:03d} تا {last:03d}")
    print("=" * 72)
    print(f"{'مدرک':<20}{'نمونه':>8}{'تطابق کامل':>14}{'CER':>10}")
    print("-" * 72)

    total = exact = 0
    cer_sum = 0.0

    for summary in summaries:

        if summary["total"] == 0:
            print(f"{summary['document_type']:<20}{'—':>8}{'بدون نمونه':>14}")
            continue

        print(
            f"{summary['document_type']:<20}"
            f"{summary['images']:>8}"
            f"{percent(summary['exact'], summary['total']):>13.1f}٪"
            f"{percent(summary['cer'], summary['total']):>9.2f}٪"
        )

        total += summary["total"]
        exact += summary["exact"]
        cer_sum += summary["cer"]

    print("-" * 72)
    print(
        f"{'میانگین':<20}{'':>8}"
        f"{percent(exact, total):>13.1f}٪"
        f"{percent(cer_sum, total):>9.2f}٪"
    )
    print("=" * 72)

    for summary in summaries:

        if summary["total"] == 0:
            continue

        print(f"\n### {summary['document_type']}")

        for field_name in sorted(summary["fields"]):

            row = summary["fields"][field_name]

            print(
                f"    {field_name:<24}"
                f"{percent(row['exact'], row['n']):>8.1f}٪"
                f"{percent(row['cer'], row['n']):>9.2f}٪ CER"
            )

    print()

    return percent(exact, total), percent(cer_sum, total)


# -------------------------------------


def parse_args():
    parser = argparse.ArgumentParser(
        description="اندازه‌گیری عدد پایهٔ موتور روی نمونه‌های تازه."
    )

    parser.add_argument(
        "-n", "--number",
        type=int,
        default=0,
        help="چند شخص تازه ساخته شود؛ فقط همان‌ها سنجیده می‌شوند",
    )

    parser.add_argument(
        "--last",
        type=int,
        default=0,
        help="بدون تولید: فقط این تعداد از آخرین نمونه‌ها سنجیده شود",
    )

    parser.add_argument(
        "--from",
        dest="first",
        type=int,
        default=0,
        help="شمارهٔ شروع بازه",
    )

    parser.add_argument(
        "--to",
        dest="last_number",
        type=int,
        default=0,
        help="شمارهٔ پایان بازه",
    )

    parser.add_argument(
        "--variants",
        action="store_true",
        help="متن را از مسیر چندمقیاسی موتور بگیر (همان راهی که پنل می‌رود)",
    )

    return parser.parse_args()


def resolve_range(args):
    """بازهٔ نمونه‌ها؛ در حالت --number اول تولید انجام می‌شود."""

    if args.number > 0:

        first = highest_sample_number() + 1

        generate(args.number)

        return first, highest_sample_number()

    if args.first > 0 or args.last_number > 0:

        return (
            args.first or 1,
            args.last_number or highest_sample_number(),
        )

    highest = highest_sample_number()

    if args.last > 0:

        return max(1, highest - args.last + 1), highest

    return 1, highest


def main():
    args = parse_args()

    if args.number < 0:
        raise SystemExit("مقدار --number نمی‌تواند منفی باشد.")

    first, last = resolve_range(args)

    if last < first:
        raise SystemExit("بازهٔ نمونه‌ها خالی است؛ اول با --number چند نمونه بسازید.")

    process_pending()

    if args.variants:
        print("مسیر چندمقیاسی موتور — کندتر، ولی همان راهی که پنل می‌رود.")
        print(
            "توجه: این عدد «سقف» است، نه نتیجهٔ نهایی — معیار زیررشته‌ای فقط"
            " می‌پرسد مقدار درست در یکی از نسخه‌ها آمده یا نه. اینکه استخراج‌گر"
            " واقعاً کدام را برمی‌دارد را با"
            " «php artisan hana:evaluate-extraction --last=25 --variants» ببینید."
        )

    print_report(
        [
            evaluate_range(document_type, first, last, args.variants)
            for document_type in DOCUMENT_TYPES
        ],
        first,
        last,
    )


if __name__ == "__main__":
    main()
