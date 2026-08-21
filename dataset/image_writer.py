from pathlib import Path
from PIL import Image, ImageDraw, ImageFont


from app.config.settings import FONT_PATH
from app.person.person_generator import generate_person
from app.processing.image_processing import process_image
from app.labels.label_generator import create_national_card_label

from app.labels.label_generator import create_driving_license_label

from app.labels.label_generator import create_vehicle_card_label

def draw_right_text(draw,
    text,
    x,
    y,
    font):
    bbox = draw.textbbox(

        (0, 0),

        text,

        font=font

    )

    width = bbox[2] - bbox[0]

    draw.text(

        (x - width, y),

        text,

        fill="black",

        font=font

    )



def create_national_card(person):

    template_path = (
        "dataset/templates/national_card.png"
    )

    image = Image.open(
        template_path
    )

    draw = ImageDraw.Draw(
        image
    )

    font = ImageFont.truetype(

        str(FONT_PATH),

        32

    )



    draw_right_text(
        draw,
        person["national_id"],
        790,
        142,
        font
    )


    draw_right_text(
        draw,
        person["first_name"],
        790,
        208,
        font
    )


    draw_right_text(
        draw,
        person["last_name"],
        790,
        270,
        font
    )


    draw_right_text(
        draw,
        person["birth_date"],
        790,
        332,
        font
    )

    draw_right_text(
        draw,
        person["father_name"],
        790,
        390,
        font
    )


    draw_right_text(
        draw,
        person["national_card_expire"],
        790,
        450,
        font
    )



    generated_path = Path(

        "dataset/generated/national_card"

    )


    generated_path.mkdir(

        parents=True,

        exist_ok=True

    )


    number_of_images = len(

        list(

            generated_path.glob(

                "*.png"

            )

        )

    ) + 1


    image_name = (

        f"{number_of_images:03}.png"

    )

    create_national_card_label(

        person,

        image_name

    )

    output_path = (

        generated_path /

        image_name

    )


    image.save(

        output_path

    )

    process_image(

        output_path,

        "dataset/processed/national_card"

    )

    image.close()

def create_driving_license(person):

    template_path = (
        "dataset/templates/driving_license.png"
    )


    image = Image.open(
        template_path
    )


    draw = ImageDraw.Draw(
        image
    )


    font = ImageFont.truetype(

        str(FONT_PATH),

        38

    )


    full_name = (

            person["first_name"]

            + " " +

            person["last_name"]

    )


    draw_right_text(

        draw,

        person["national_id"],

        915,

        263,

        font

    )


    draw_right_text(

        draw,

        full_name,

        1100,

        480,

        font

    )


    draw_right_text(

        draw,

        person["birth_date"],

        915,

        615,

        font

    )


    draw_right_text(

        draw,

        person["license_issue_date"],

        885,

        730,

        font

    )


    draw_right_text(

        draw,

        person["license_number"],

        800,

        820,

        font

    )




    generated_path = Path(

        "dataset/generated/driving_license"

    )


    generated_path.mkdir(

        parents=True,

        exist_ok=True

    )


    number_of_images = len(

        list(

            generated_path.glob(

                "*.png"

            )

        )

    ) + 1


    image_name = (

        f"{number_of_images:03}.png"

    )

    create_driving_license_label(

        person,

        image_name

    )

    output_path = (

        generated_path /

        image_name

    )


    image.save(

        output_path

    )

    process_image(

        output_path,

        "dataset/processed/driving_license"

    )

    image.close()

def create_vehicle_card(person):

    template_path = (

        "dataset/templates/vehicle_card.png"

    )


    image = Image.open(
        template_path
    )


    draw = ImageDraw.Draw(
        image
    )


    font = ImageFont.truetype(

        str(FONT_PATH),

        36

    )



    # پلاک با قالب کامل «۱۲ ب ۳۴۵ ایران ۶۷» چاپ می‌شود؛ این رشته از رشتهٔ
    # قبلی بلندتر است و با فونت ۹۰ از کادر پلاک بیرون می‌زد. اندازهٔ ۴۸
    # بدترین حالت (حرف «الف») را در ۴۳۱ پیکسل نگه می‌دارد، یعنی کاملاً
    # داخل کادر سفید اصلی پلاک (x=۷۵۰ تا ۱۱۸۸).
    plate_font = ImageFont.truetype(

        str(FONT_PATH),

        48

    )

    full_name = (

            person["first_name"]

            + " " +

            person["last_name"]

    )


    #----------------------------------


    draw_right_text(

        draw,

        full_name,

        980,

        242,

        font

    )


    draw_right_text(

        draw,

        person["national_id"],

        1050,

        360,

        font

    )

    draw_right_text(
        draw,
        person["father_name"],
        1080,
        485,
        font
    )

    draw.text(

        (270, 625),

        person["vin"],

        fill="black",

        font=font

    )


    # راست‌چین تا لبهٔ راست کادر سفید اصلی (x=۱۱۸۶) و عمودی وسط همان کادر.
    # عمداً وارد کادر کوچک «ایران» نمی‌شود: خط جداکنندهٔ آن دو کادر تمام‌قد
    # است و اگر از روی یک رقم رد شود، سگمنتیشن آن رقم را خراب می‌کند.
    draw_right_text(

        draw,

        person["plate_number"],

        1186,

        843,

        plate_font

    )


    #----------------------------------


    generated_path = Path(

        "dataset/generated/vehicle_card"

    )


    generated_path.mkdir(

        parents=True,

        exist_ok=True

    )


    number_of_images = len(

        list(

            generated_path.glob(

                "*.png"

            )

        )

    ) + 1


    image_name = (

        f"{number_of_images:03}.png"

    )

    create_vehicle_card_label(

        person,

        image_name

    )


    output_path = (

        generated_path /

        image_name

    )


    image.save(

        output_path

    )

    process_image(

        output_path,

        "dataset/processed/vehicle_card"

    )


    image.close()