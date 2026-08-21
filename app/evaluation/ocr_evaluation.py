"""ارزیابی خروجی OCR در برابر برچسب‌های دیتاست.

دو معیار گزارش می‌شود:

1. «تطابق کامل» (exact match) — آیا مقدار برچسب دقیقاً در متن OCR پیدا می‌شود؟
   معیار سخت‌گیرانه‌ای است: یک کاراکتر غلط کل فیلد را صفر می‌کند.
2. «نرخ خطای کاراکتری» (CER) بر پایهٔ فاصلهٔ لونشتاین — چند درصد از کاراکترهای
   برچسب باید تغییر کند تا به بهترین زیررشتهٔ متن OCR برسیم. معیار استاندارد
   ارزیابی OCR و واقع‌بینانه‌تر از تطابق کامل.

نرمال‌سازی بر پایهٔ «نوع فیلد» انجام می‌شود (تاریخ / نام / کد / متن آزاد) نه با
شرط‌های پراکنده؛ برای هر سه نوع تاریخ، نام و کد فاصله‌ها حذف می‌شوند چون OCR
مرتب فاصلهٔ اضافه داخل اعداد جا می‌اندازد و یک فاصله نباید مقدار درست را رد کند.
"""

import json
from pathlib import Path


# ------------------------------------------
# ورودی/خروجی

def load_label_file(label_path):

    with open(
            label_path,
            "r",
            encoding="utf-8"
    ) as file:

        return json.load(file)


def load_ocr_text(ocr_path):

    with open(
            ocr_path,
            "r",
            encoding="utf-8"
    ) as file:

        return file.read()


# ------------------------------------------
# نرمال‌سازی پایه

def english_to_persian_numbers(text):

    english = "0123456789"
    persian = "۰۱۲۳۴۵۶۷۸۹"

    for e, p in zip(english, persian):

        text = text.replace(e, p)

    return text


# کاراکترهایی که «فاصله» حساب می‌شوند و باید حذف شوند
SPACE_CHARACTERS = (

    " ",        # فاصله معمولی
    "‌",   # نیم‌فاصله
    "‏",   # علامت راست‌به‌چپ
    "‎",   # علامت چپ‌به‌راست
    " ",   # فاصله بدون شکست
    "\t",
    "\n",
    "\r",
)


def remove_spaces(text):
    """حذف هر شکلی از فاصله. مقایسه را در برابر فاصلهٔ اضافهٔ OCR مقاوم می‌کند."""

    for space in SPACE_CHARACTERS:

        text = text.replace(space, "")

    return text


def normalize_text(text):
    """نرمال‌سازی عمومی: ارقام فارسی، یکسان‌سازی ک/ی، فشرده‌سازی فاصله‌ها."""

    text = str(text)

    text = english_to_persian_numbers(text)

    text = text.strip()

    text = text.replace("\n", " ")
    text = text.replace("\t", " ")
    text = text.replace("\r", " ")

    text = text.replace("ك", "ک")
    text = text.replace("ي", "ی")
    text = text.replace("‌", "")

    while "  " in text:

        text = text.replace(
            "  ",
            " "
        )

    return text


def normalize_date(text):
    """تاریخ: ارقام فارسی + حذف کامل فاصله (۱۳۶۵ / ۱۱ / ۰۶ == ۱۳۶۵/۱۱/۰۶)."""

    text = english_to_persian_numbers(str(text))

    return remove_spaces(text)


def normalize_name(text):
    """نام: حذف فاصله و نیم‌فاصله + یکسان‌سازی ک/ی."""

    text = str(text)

    text = text.replace("ك", "ک")
    text = text.replace("ي", "ی")

    return remove_spaces(text)


def normalize_code(text):
    """کد (کد ملی، VIN، شماره گواهینامه، پلاک): ارقام فارسی + حذف کامل فاصله."""

    text = english_to_persian_numbers(str(text))

    text = text.replace("ك", "ک")
    text = text.replace("ي", "ی")

    return remove_spaces(text)


# ------------------------------------------
# نوع فیلد

DATE_FIELDS = (

    "birth_date",
    "license_issue_date",
    "national_card_expire",
    "expire_date",
    "issue_date",
)

NAME_FIELDS = (

    "full_name",
    "first_name",
    "last_name",
    "father_name",
)

# فیلدهای رقمی/الفبایی‌عددی — فاصله در این‌ها بی‌معناست و باید حذف شود
CODE_FIELDS = (

    "national_id",
    "license_number",
    "vin",
    "plate_number",
    "permit_number",
    "chassis_number",
    "engine_number",
    "mobile",
    "phone",
    "postal_code",
)

PERSIAN_DIGITS = "۰۱۲۳۴۵۶۷۸۹"


def looks_like_code(value):
    """حدس برای فیلدهای ناشناخته: اگر بیشتر مقدار رقم/حرف لاتین است، کد است."""

    text = remove_spaces(str(value))

    if not text:

        return False

    code_like = sum(

        1

        for character in text

        if character.isdigit()
        or character in PERSIAN_DIGITS
        or ("a" <= character.lower() <= "z")
    )

    return code_like >= (len(text) / 2)


def field_kind(field_name, value=""):
    """نوع فیلد را بر می‌گرداند: date | name | code | text."""

    if field_name in DATE_FIELDS:

        return "date"

    if field_name in NAME_FIELDS:

        return "name"

    if field_name in CODE_FIELDS:

        return "code"

    if field_name.endswith("_date"):

        return "date"

    if field_name.endswith("_name"):

        return "name"

    if looks_like_code(value):

        return "code"

    return "text"


def normalize_for_kind(text, kind):
    """نرمال‌سازی متن مطابق نوع فیلد. روی برچسب و متن OCR یکسان اعمال می‌شود."""

    text = normalize_text(text)

    if kind == "date":

        return normalize_date(text)

    if kind == "name":

        return normalize_name(text)

    if kind == "code":

        return normalize_code(text)

    return text


# ------------------------------------------
# فاصلهٔ لونشتاین

def levenshtein_distance(source, target):
    """فاصلهٔ ویرایشی کلاسیک بین دو رشته (درج/حذف/جایگزینی)."""

    if source == target:

        return 0

    if not source:

        return len(target)

    if not target:

        return len(source)

    previous_row = list(

        range(len(target) + 1)
    )

    for row, source_character in enumerate(source, start=1):

        current_row = [row]

        for column, target_character in enumerate(target, start=1):

            cost = 0 if source_character == target_character else 1

            current_row.append(

                min(
                    previous_row[column] + 1,        # حذف
                    current_row[column - 1] + 1,     # درج
                    previous_row[column - 1] + cost  # جایگزینی
                )
            )

        previous_row = current_row

    return previous_row[-1]


def substring_levenshtein_distance(needle, haystack):
    """کمترین فاصلهٔ لونشتاین بین needle و *هر زیررشتهٔ* haystack.

    متن OCR کل صفحه است نه فقط یک فیلد؛ پس نمی‌توان فاصله را با کل متن گرفت.
    با صفر گذاشتن سطر اول، شروع تطبیق در هر نقطه از متن آزاد است و با گرفتن
    کمینهٔ سطر آخر، پایان آن هم آزاد می‌شود.
    """

    if not needle:

        return 0

    if not haystack:

        return len(needle)

    previous_row = [0] * (len(haystack) + 1)

    for row, needle_character in enumerate(needle, start=1):

        current_row = [row]

        for column, haystack_character in enumerate(haystack, start=1):

            cost = 0 if needle_character == haystack_character else 1

            current_row.append(

                min(
                    previous_row[column] + 1,
                    current_row[column - 1] + 1,
                    previous_row[column - 1] + cost
                )
            )

        previous_row = current_row

    return min(previous_row)


def character_error_rate(label_value, ocr_text):
    """CER = levenshtein(label, بهترین زیررشتهٔ ocr) / len(label) — بین ۰ و ۱."""

    if not label_value:

        return 0.0

    distance = substring_levenshtein_distance(
        label_value,
        ocr_text
    )

    return min(

        distance / len(label_value),
        1.0
    )


# ------------------------------------------
# مقایسه یک نمونه

def compare_fields_detailed(label_data,
                            ocr_text):
    """برای هر فیلد: تطابق کامل + فاصلهٔ ویرایشی + CER."""

    results = {}

    base_ocr = normalize_text(ocr_text)

    # متن OCR برای هر نوع فیلد یک بار نرمال می‌شود، نه به ازای هر فیلد
    ocr_by_kind = {}

    for field_name, raw_value in label_data.items():

        kind = field_kind(
            field_name,
            raw_value
        )

        if kind not in ocr_by_kind:

            ocr_by_kind[kind] = normalize_for_kind(
                base_ocr,
                kind
            )

        normalized_ocr = ocr_by_kind[kind]

        normalized_value = normalize_for_kind(
            raw_value,
            kind
        )

        distance = substring_levenshtein_distance(
            normalized_value,
            normalized_ocr
        )

        length = len(normalized_value)

        cer = (

            min(distance / length, 1.0)

            if length

            else 0.0
        )

        results[field_name] = {

            "kind": kind,
            "label": normalized_value,
            "exact": distance == 0,
            "distance": distance,
            "length": length,
            "cer": cer,
        }

    return results


def compare_fields(label_data,
                   ocr_text):
    """سازگاری با قبل: فقط تطابق کامل هر فیلد به‌صورت True/False."""

    detailed = compare_fields_detailed(
        label_data,
        ocr_text
    )

    return {

        field_name: detail["exact"]

        for field_name, detail in detailed.items()
    }


# ------------------------------------------
# تجمیع

def _exact_flag(value):

    if isinstance(value, dict):

        return bool(value.get("exact"))

    return bool(value)


def calculate_accuracy(results):
    """درصد فیلدهایی که تطابق کامل دارند. هر دو شکل خروجی مقایسه را می‌پذیرد."""

    total_fields = len(results)

    if total_fields == 0:

        return 0

    correct_fields = sum(

        1

        for value in results.values()

        if _exact_flag(value)
    )

    accuracy = (
            correct_fields /
            total_fields
    ) * 100

    return round(
        accuracy,
        2
    )


def calculate_cer(results):
    """میانگین CER فیلدهای یک نمونه، بر حسب درصد."""

    total_fields = len(results)

    if total_fields == 0:

        return 0

    total_cer = sum(

        detail["cer"]

        for detail in results.values()

        if isinstance(detail, dict)
    )

    return round(

        (total_cer / total_fields) * 100,
        2
    )


# ------------------------------------------
# ارزیابی یک پوشه

def evaluate_folder(labels_folder,
                    ocr_folder,
                    document_type=None,
                    verbose=True):
    """ارزیابی همهٔ برچسب‌های یک پوشه در برابر متن OCR متناظر.

    امضای قبلی (labels_folder, ocr_folder) دست‌نخورده است.
    خروجی: دیکشنری خلاصه (قبلاً None برمی‌گرداند).
    """

    labels_folder = Path(
        labels_folder
    )

    ocr_folder = Path(
        ocr_folder
    )

    if document_type is None:

        document_type = labels_folder.name

    number_of_images = 0

    total_accuracy = 0.0

    total_cer = 0.0

    field_exact_counter = {}

    field_cer_total = {}

    field_sample_counter = {}

    for label_file in sorted(labels_folder.glob("*.json")):

        ocr_file = (

                ocr_folder /

                f"{label_file.stem}.txt"

        )

        if not ocr_file.exists():

            if verbose:

                print(
                    f"{ocr_file.name} not found!"
                )

            continue

        number_of_images += 1

        label_data = load_label_file(
            label_file
        )

        ocr_text = load_ocr_text(
            ocr_file
        )

        results = compare_fields_detailed(
            label_data,
            ocr_text
        )

        total_accuracy += calculate_accuracy(results)

        total_cer += calculate_cer(results)

        for field_name, detail in results.items():

            field_exact_counter.setdefault(field_name, 0)
            field_cer_total.setdefault(field_name, 0.0)
            field_sample_counter.setdefault(field_name, 0)

            field_sample_counter[field_name] += 1

            field_cer_total[field_name] += detail["cer"]

            if detail["exact"]:

                field_exact_counter[field_name] += 1

    summary = {

        "document_type": document_type,
        "number_of_images": number_of_images,
        "exact_match_accuracy": 0.0,
        "cer": 0.0,
        "fields": {},
    }

    if number_of_images == 0:

        if verbose:

            print("\n")
            print("=" * 72)
            print(f"FINAL REPORT — {document_type}")
            print("=" * 72)
            print("\nNo Images Found!\n")

        return summary

    summary["exact_match_accuracy"] = round(

        total_accuracy / number_of_images,
        2
    )

    summary["cer"] = round(

        total_cer / number_of_images,
        2
    )

    for field_name, samples in field_sample_counter.items():

        summary["fields"][field_name] = {

            "samples": samples,

            "exact_match_accuracy": round(

                (field_exact_counter[field_name] / samples) * 100,
                2
            ),

            "cer": round(

                (field_cer_total[field_name] / samples) * 100,
                2
            ),
        }

    if verbose:

        print_report(summary)

    return summary


# ------------------------------------------
# چاپ گزارش

def print_report(summary):

    print("\n")
    print("=" * 72)
    print(
        f"FINAL REPORT — {summary['document_type']}"
    )
    print("=" * 72)

    print(
        f"Number Of Images : "
        f"{summary['number_of_images']}"
    )

    print(
        f"Exact Match      : "
        f"{summary['exact_match_accuracy']} %"
    )

    print(
        f"CER (mean)       : "
        f"{summary['cer']} %"
    )

    print("\n")

    print(
        f"{'FIELD':<24}{'EXACT %':>12}{'CER %':>12}"
    )

    print("-" * 72)

    for field_name, stats in summary["fields"].items():

        print(
            f"{field_name:<24}"
            f"{stats['exact_match_accuracy']:>12}"
            f"{stats['cer']:>12}"
        )

    print("=" * 72)


def print_overall_report(summaries):
    """جمع‌بندی چند نوع مدرک در یک جدول."""

    summaries = [

        summary

        for summary in summaries

        if summary and summary.get("number_of_images")
    ]

    if not summaries:

        return

    print("\n")
    print("=" * 72)
    print("OVERALL")
    print("=" * 72)

    print(
        f"{'DOCUMENT TYPE':<24}{'IMAGES':>10}{'EXACT %':>12}{'CER %':>12}"
    )

    print("-" * 72)

    for summary in summaries:

        print(
            f"{summary['document_type']:<24}"
            f"{summary['number_of_images']:>10}"
            f"{summary['exact_match_accuracy']:>12}"
            f"{summary['cer']:>12}"
        )

    print("-" * 72)

    mean_exact = round(

        sum(s["exact_match_accuracy"] for s in summaries) / len(summaries),
        2
    )

    mean_cer = round(

        sum(s["cer"] for s in summaries) / len(summaries),
        2
    )

    print(
        f"{'MEAN':<24}{'':>10}{mean_exact:>12}{mean_cer:>12}"
    )

    print("=" * 72)
