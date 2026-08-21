from pathlib import Path

import cv2
import pytesseract
from app.config.settings import TESSERACT_CMD
from app.ocr.vehicle_card_ocr import vehicle_card_ocr

# -------------------------------------


pytesseract.pytesseract.tesseract_cmd = (

    TESSERACT_CMD

)


# -------------------------------------


def extract_text(image_path):
    image = cv2.imread(

        str(image_path)

    )

    custom_config = (

        r'--oem 3 --psm 6'

    )

    text = pytesseract.image_to_string(

        image,

        lang="fas",

        config=custom_config

    )

    return text


# -------------------------------------


def save_text(text,
              output_path):
    output_path = Path(

        output_path

    )

    output_path.parent.mkdir(

        parents=True,

        exist_ok=True

    )

    with open(

            output_path,

            "w",

            encoding="utf-8"

    ) as file:
        file.write(

            text

        )


# -------------------------------------


def ocr_folder(input_folder,
               output_folder):
    input_folder = Path(

        input_folder

    )

    output_folder = Path(

        output_folder

    )

    for image_path in input_folder.glob("*.png"):

        # OCR عمومی
        text = extract_text(
            image_path
        )

        # اگر کارت ماشین بود
        if "vehicle_card" in str(input_folder):
            vin_text, plate_text = vehicle_card_ocr(
                str(image_path)
            )

            # اضافه کردن VIN و پلاک به متن OCR
            text += f"\nVIN : {vin_text}"
            text += f"\nPLATE : {plate_text}"

        image_name = image_path.stem

        image_number = image_name.split("_")[0]

        output_name = (

                image_number +

                ".txt"

        )

        output_path = (

                output_folder /

                output_name

        )

        save_text(

            text,

            output_path

        )
