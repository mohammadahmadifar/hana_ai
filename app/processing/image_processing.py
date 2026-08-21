import random

from pathlib import Path

import cv2
import numpy as np


# -------------------------------------


def rotate_image(image):

    angle = random.randint(-7, 7)

    height, width = image.shape[:2]

    center = (width // 2, height // 2)

    matrix = cv2.getRotationMatrix2D(

        center,

        angle,

        1

    )

    return cv2.warpAffine(

        image,

        matrix,

        (width, height),

        borderValue=(255, 255, 255)

    )


# -------------------------------------


def change_brightness(image):

    value = random.randint(-35, 35)

    hsv = cv2.cvtColor(

        image,

        cv2.COLOR_BGR2HSV

    )

    hsv = hsv.astype(np.int16)

    hsv[:, :, 2] += value

    hsv[:, :, 2] = np.clip(

        hsv[:, :, 2],

        0,

        255

    )

    hsv = hsv.astype(np.uint8)

    return cv2.cvtColor(

        hsv,

        cv2.COLOR_HSV2BGR

    )


# -------------------------------------


def blur_image(image):

    kernels = [

        (3, 3),

        (3, 3),

        (3, 3)

    ]

    kernel = random.choice(

        kernels

    )

    return cv2.GaussianBlur(

        image,

        kernel,

        0

    )


# -------------------------------------


def add_noise(image):

    std = random.randint(

        5,

        12

    )

    noise = np.random.normal(

        0,

        std,

        image.shape

    )

    noisy_image = image + noise

    noisy_image = np.clip(

        noisy_image,

        0,

        255

    )

    return noisy_image.astype(

        np.uint8

    )


# -------------------------------------


def add_shadow(image):

    shadow = image.copy()

    height, width = shadow.shape[:2]

    # ایجاد ماسک سفید
    mask = np.ones((height, width), dtype=np.float32)

    # مرکز سایه

    positions = [

        (0.1, 0.1),
        (0.9, 0.1),
        (0.1, 0.9),
        (0.9, 0.9),
        (0.5, 0.1),
        (0.5, 0.9)

    ]

    x_ratio, y_ratio = random.choice(positions)

    center_x = int(width * x_ratio)
    center_y = int(height * y_ratio)

    # اندازه سایه
    axis_x = random.randint(int(width * 0.08),
                            int(width * 0.18))

    axis_y = random.randint(int(height * 0.08),
                            int(height * 0.18))

    # شدت سایه
    alpha = random.uniform(0.65, 0.85)

    # ساخت ماسک بیضی
    ellipse_mask = np.zeros((height, width),
                            dtype=np.uint8)

    cv2.ellipse(

        ellipse_mask,

        (center_x, center_y),

        (axis_x, axis_y),

        random.randint(0, 180),

        0,

        360,

        255,

        -1

    )

    # محو کردن لبه های سایه
    ellipse_mask = cv2.GaussianBlur(

        ellipse_mask,

        (51, 51),

        0

    )

    # نرمال سازی
    ellipse_mask = ellipse_mask.astype(np.float32) / 255.0

    # ایجاد شدت سایه
    mask = 1 - ((1 - alpha) * ellipse_mask)

    # اعمال سایه
    shadow = (

        shadow * mask[:, :, np.newaxis]

    ).astype(np.uint8)

    return shadow


# -------------------------------------


def apply_random_processing(image):

    processing_functions = {

        "rotation": rotate_image,

        "brightness": change_brightness,

        "blur": blur_image,

        "noise": add_noise,

        "shadow": add_shadow

    }

    process_name = random.choice(

        list(

            processing_functions.keys()

        )

    )

    processed_image = (

        processing_functions[

            process_name

        ](image)

    )

    return processed_image, process_name


# -------------------------------------


def process_image(

        input_path,

        output_folder

):

    image = cv2.imread(

        str(input_path)

    )

    processed_image, process_name = (

        apply_random_processing(

            image

        )

    )

    output_folder = Path(

        output_folder

    )

    output_folder.mkdir(

        parents=True,

        exist_ok=True

    )

    image_name = Path(

        input_path

    ).stem

    output_name = (

        f"{image_name}_{process_name}.png"

    )

    output_path = (

        output_folder /

        output_name

    )

    cv2.imwrite(

        str(output_path),

        processed_image

    )