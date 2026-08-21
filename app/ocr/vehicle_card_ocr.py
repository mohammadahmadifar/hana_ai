from pathlib import Path
import cv2
import pytesseract

from app.config.settings import TESSERACT_CMD

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

def crop_vin(image):
    height, width = image.shape[:2]

    x1 = int(width * 0.12)
    x2 = int(width * 0.55)

    y1 = int(height * 0.58)
    y2 = int(height * 0.75)

    return image[y1:y2, x1:x2]


# ---------------------------------------------------

def crop_plate(image):
    height, width = image.shape[:2]

    x1 = int(width * 0.46)
    x2 = int(width * 0.82)

    y1 = int(height * 0.86)
    y2 = int(height * 0.97)

    return image[y1:y2, x1:x2]


# ---------------------------------------------------

def merge_dots(boxes):
    big_boxes = []
    dot_boxes = []

    for box in boxes:

        x, y, w, h = box

        area = w * h

        if area < 500:
            dot_boxes.append(box)
        else:
            big_boxes.append(box)

    merged = []

    for bx in big_boxes:

        x, y, w, h = bx

        x2 = x + w
        y2 = y + h

        for dot in dot_boxes:

            dx, dy, dw, dh = dot

            dot_center_x = dx + dw // 2
            dot_center_y = dy + dh // 2

            inside_x = (
                    x - 10 <= dot_center_x <= x2 + 10
            )

            near_y = (
                    y - 40 <= dot_center_y <= y2 + 40
            )

            if inside_x and near_y:
                new_x = min(x, dx)
                new_y = min(y, dy)

                new_x2 = max(x2, dx + dw)
                new_y2 = max(y2, dy + dh)

                x = new_x
                y = new_y

                w = new_x2 - new_x
                h = new_y2 - new_y

        merged.append((x, y, w, h))

    return merged


# ---------------------------------------------------

def segment_plate(plate_image):
    if len(plate_image.shape) == 3:
        plate_image = cv2.cvtColor(
            plate_image,
            cv2.COLOR_BGR2GRAY
        )

    _, thresh = cv2.threshold(
        plate_image,
        150,
        255,
        cv2.THRESH_BINARY_INV
    )

    contours, _ = cv2.findContours(
        thresh,
        cv2.RETR_EXTERNAL,
        cv2.CHAIN_APPROX_SIMPLE
    )

    boxes = []

    for cnt in contours:

        x, y, w, h = cv2.boundingRect(cnt)

        if h > plate_image.shape[0] * 0.9 and w < 10:
            continue

        area = w * h

        if area > 80:
            boxes.append((x, y, w, h))

    boxes = merge_dots(boxes)

    boxes = sorted(boxes, key=lambda b: b[0])

    return plate_image, thresh, boxes


# ---------------------------------------------------

def ocr_character(char_image):
    char = cv2.resize(
        char_image,
        None,
        fx=5,
        fy=5,
        interpolation=cv2.INTER_CUBIC
    )

    char = cv2.copyMakeBorder(
        char,
        20, 20, 20, 20,
        cv2.BORDER_CONSTANT,
        value=0
    )

    char = cv2.GaussianBlur(
        char,
        (3, 3),
        0
    )

    _, char = cv2.threshold(
        char,
        0,
        255,
        cv2.THRESH_BINARY + cv2.THRESH_OTSU
    )

    config = "--oem 3 --psm 10"

    text = pytesseract.image_to_string(
        char,
        lang="fas",
        config=config
    )

    return text.strip()


# ---------------------------------------------------

def read_plate(binary, boxes):
    result = ""

    for i, (x, y, w, h) in enumerate(boxes):
        char = binary[y:y + h, x:x + w]

        text = ocr_character(char)

        print(f"{i} -> {repr(text)}")

        result += text

    return result

def format_plate(text):

    text = text.replace(" ", "")
    text = text.replace("\n", "")

    if len(text) < 8:
        return text

    first = text[:2]
    letter = text[2]
    middle = text[3:6]
    last = text[6:8]


    return f"{first} {letter} {middle} {last}"

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


def vehicle_card_ocr(image_path):
    image = cv2.imread(
        str(image_path)
    )

    vin_image = crop_vin(
        image
    )

    vin_text = extract_vin(
        vin_image
    )

    plate_image = crop_plate(
        image
    )

    plate_gray, binary, boxes = segment_plate(plate_image)

    plate_text = read_plate(
        binary,
        boxes
    )

    plate_text = format_plate(
        plate_text
    )
    print(f"Plate : {plate_text}")

    debug = cv2.cvtColor(
        binary,
        cv2.COLOR_GRAY2BGR
    )

    for x, y, w, h in boxes:
        pad = 5 # مقدار بزرگ‌تر کردن کادر

        cv2.rectangle(
            debug,
            (x - pad, y - pad),
            (x + w + pad, y + h + pad),
            (0, 255, 0),
            2
        )

    cv2.imwrite(
        "debug_segments.png",
        debug
    )

    return vin_text, plate_text


# ---------------------------------------------------

if __name__ == "__main__":
    vin, plate = vehicle_card_ocr(
        str(image_path)
    )
