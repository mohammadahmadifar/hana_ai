import json
from pathlib import Path


# ------------------------------------------

def load_label_file(label_path):

    with open(
            label_path,
            "r",
            encoding="utf-8"
    ) as file:

        return json.load(file)

def english_to_persian_numbers(text):

    english = "0123456789"
    persian = "۰۱۲۳۴۵۶۷۸۹"

    for e, p in zip(english, persian):

        text = text.replace(e, p)

    return text
def normalize_date(text):

    text = english_to_persian_numbers(text)

    text = text.replace(" ", "")
    text = text.replace(" / ", "/")
    text = text.replace("/ ", "/")
    text = text.replace(" /", "/")

    return text
def normalize_name(text):

    text = text.replace(" ", "")
    text = text.replace("‌", "")      # نیم فاصله
    text = text.replace("ك", "ک")
    text = text.replace("ي", "ی")

    return text
# ------------------------------------------

def load_ocr_text(ocr_path):

    with open(
            ocr_path,
            "r",
            encoding="utf-8"
    ) as file:

        return file.read()


# ------------------------------------------

def normalize_text(text):

    text = str(text)

    text = english_to_persian_numbers(text)

    text = text.strip()

    text = text.replace("\n", " ")
    text = text.replace("\t", " ")

    text = text.replace("ك", "ک")
    text = text.replace("ي", "ی")
    text = text.replace("‌", "")

    while "  " in text:

        text = text.replace(
            "  ",
            " "
        )

    return text


# ------------------------------------------

def compare_fields(label_data,
                   ocr_text):

    results = {}

    normalized_ocr = normalize_text(
        ocr_text
    )

    for field_name, value in label_data.items():

        if field_name in [

            "birth_date",
            "license_issue_date",
            "national_card_expire"

        ]:

            value = normalize_date(value)

            normalized_ocr_field = normalize_date(
                normalized_ocr
            )


        elif field_name in [

            "full_name",
            "first_name",
            "last_name",
            "father_name"

        ]:

            value = normalize_name(value)

            normalized_ocr_field = normalize_name(
                normalized_ocr
            )


        else:

            value = normalize_text(value)

            normalized_ocr_field = normalize_text(
                normalized_ocr
            )

        if value in normalized_ocr_field:

            results[field_name] = True

        else:

            results[field_name] = False


    return results


# ------------------------------------------

def calculate_accuracy(results):

    total_fields = len(results)

    if total_fields == 0:
        return 0

    correct_fields = sum(results.values())

    accuracy = (
            correct_fields /
            total_fields
    ) * 100

    return round(
        accuracy,
        2
    )


# ------------------------------------------

def evaluate_folder(labels_folder,
                    ocr_folder):

    labels_folder = Path(
        labels_folder
    )

    ocr_folder = Path(
        ocr_folder
    )

    number_of_images = 0

    total_accuracy = 0

    field_counter = {}

    for label_file in labels_folder.glob(
            "*.json"
    ):

        ocr_file = (

                ocr_folder /

                f"{label_file.stem}.txt"

        )

        if not ocr_file.exists():

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

        results = compare_fields(
            label_data,
            ocr_text
        )

        accuracy = calculate_accuracy(
            results
        )

        total_accuracy += accuracy

        for field_name, status in results.items():

            if field_name not in field_counter:

                field_counter[
                    field_name
                ] = 0

            if status:

                field_counter[
                    field_name
                ] += 1

    print("\n")
    print("=" * 60)
    print("FINAL REPORT")
    print("=" * 60)

    print(
        f"Number Of Images : "
        f"{number_of_images}"
    )

    if number_of_images == 0:

        print("\nNo Images Found!\n")

        return

    average_accuracy = (

            total_accuracy /

            number_of_images

    )

    print(
        f"Average Accuracy : "
        f"{round(average_accuracy, 2)} %"
    )

    print("\n")

    for field_name, correct_count in field_counter.items():

        field_accuracy = (

                correct_count /

                number_of_images

        ) * 100

        print(
            f"{field_name} Accuracy ---> "
            f"{round(field_accuracy, 2)} %"
        )

    print("\n")
    print("=" * 60)


