import cv2
import numpy as np

from pathlib import Path


# -------------------------------------


def convert_to_gray(image):

    return cv2.cvtColor(

        image,

        cv2.COLOR_BGR2GRAY

    )


# -------------------------------------


def remove_noise(image):

    return cv2.fastNlMeansDenoising(

        image,

        None,

        15,

        7,

        21

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
                     output_path):

    image = cv2.imread(

        str(image_path)

    )

    # تبدیل به سیاه و سفید
    image = convert_to_gray(

        image

    )

    # حذف نویز
    image = remove_noise(

        image

    )

    # شارپ کردن متن‌ها
    image = sharpen_image(

        image

    )

    # ذخیره خروجی
    save_preprocessed_image(

        image,

        output_path

    )


# -------------------------------------


def preprocess_folder(input_folder,
                      output_folder):

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

            output_path

        )


