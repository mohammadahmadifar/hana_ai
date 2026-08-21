from faker import Faker
import random
import jdatetime

fake = Faker("fa_IR")


# -------------------------------------

def to_persian_digits(text):

    english = "0123456789"
    persian = "۰۱۲۳۴۵۶۷۸۹"

    return str(text).translate(
        str.maketrans(
            english,
            persian
        )
    )


# -------------------------------------

def format_date(date):

    return to_persian_digits(

        date.strftime("%Y/%m/%d")

    )


# -------------------------------------

def generate_digits(number):

    return "".join(

        str(random.randint(0, 9))

        for _ in range(number)

    )


# -------------------------------------

def generate_valid_national_id():

    first_nine = [

        random.randint(0, 9)

        for _ in range(9)

    ]

    total = 0

    for i in range(9):

        total += first_nine[i] * (10 - i)

    remainder = total % 11

    if remainder < 2:

        check_digit = remainder

    else:

        check_digit = 11 - remainder

    national_id = (

            "".join(
                map(str, first_nine)
            )

            +

            str(check_digit)

    )

    return national_id


# -------------------------------------

def validate_national_id(national_id):

    national_id = str(national_id)

    if len(national_id) != 10:

        return False

    check_digit = int(national_id[-1])

    digits = list(

        map(
            int,
            national_id[:-1]
        )

    )

    total = sum(

        digit * (10 - i)

        for i, digit in enumerate(digits)

    )

    remainder = total % 11

    if remainder < 2:

        return check_digit == remainder

    return check_digit == (11 - remainder)


# -------------------------------------

def generate_birth_date():

    today = jdatetime.date.today()

    max_year = today.year - 18

    birth_date = jdatetime.date(

        random.randint(
            1360,
            max_year
        ),

        random.randint(1, 12),

        random.randint(1, 28)

    )

    return birth_date


# -------------------------------------

def generate_license_issue_date(
        birth_date
):

    today = jdatetime.date.today()

    min_year = birth_date.year + 18

    issue_year = random.randint(

        min_year,
        today.year

    )

    return jdatetime.date(

        issue_year,

        random.randint(1, 12),

        random.randint(1, 28)

    )


# -------------------------------------

def generate_national_card_expire():

    today = jdatetime.date.today()

    return jdatetime.date(

        today.year + random.randint(5, 10),

        random.randint(1, 12),

        random.randint(1, 28)

    )


# -------------------------------------

def generate_license_expire_date():

    today = jdatetime.date.today()

    return jdatetime.date(

        today.year + random.randint(1, 10),

        random.randint(1, 12),

        random.randint(1, 28)

    )


# -------------------------------------

def generate_license_number():

    return generate_digits(10)


# -------------------------------------

def generate_vin():

    first_part = "NAS"

    second_part = generate_digits(6)

    third_part = "M"

    fourth_part = generate_digits(7)

    return (

            first_part

            +

            second_part

            +

            third_part

            +

            fourth_part

    )


# -------------------------------------

def generate_plate_number():

    letters = [

        "الف",
        "ب",
        "ج",
        "د",
        "س",
        "ص",
        "ط",
        "ق",
        "ل",
        "م",
        "ن",
        "و",
        "ه",
        "ی"

    ]

    first_part = random.randint(10, 99)

    second_part = random.choice(
        letters
    )

    third_part = random.randint(
        100,
        999
    )

    fourth_part = random.randint(
        10,
        99
    )


    plate = (

        f"{first_part} "
        f"{third_part} "
        f"{second_part} "
        f"{fourth_part}"

    )


    return to_persian_digits(
        plate
    )


# -------------------------------------

def generate_person():

    birth_date = generate_birth_date()

    license_issue_date = (

        generate_license_issue_date(

            birth_date

        )

    )

    person = {

        # اطلاعات شخص

        "first_name":

        fake.first_name_male(),

        "last_name":

        fake.last_name(),

        "father_name":

        fake.first_name_male(),

        "national_id":

        to_persian_digits(

            generate_valid_national_id()

        ),

        "birth_date":

        format_date(
            birth_date
        ),


        # کارت ملی

        "national_card_expire":

        format_date(

            generate_national_card_expire()

        ),


        # گواهینامه

        "license_number":

        to_persian_digits(

            generate_license_number()

        ),

        "license_issue_date":

        format_date(

            license_issue_date

        ),

        "license_expire_date":

        format_date(

            generate_license_expire_date()

        ),


        # کارت خودرو

        "vin":

        generate_vin(),

        "plate_number":

        generate_plate_number()

    }

    return person