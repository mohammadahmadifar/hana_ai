import json

from pathlib import Path


# -------------------------------------


def save_json(data, folder_path, image_name):

    folder_path.mkdir(

        parents=True,

        exist_ok=True

    )

    file_name = image_name.replace(

        ".png",

        ".json"

    )

    output_path = (

        folder_path /

        file_name

    )

    with open(

            output_path,

            "w",

            encoding="utf-8"

    ) as file:

        json.dump(

            data,

            file,

            ensure_ascii=False,

            indent=4

        )


# -------------------------------------


def create_national_card_label(
        person,
        image_name
):

    data = {

        "national_id":
            person["national_id"],

        "first_name":
            person["first_name"],

        "last_name":
            person["last_name"],

        "birth_date":
            person["birth_date"],

        "father_name":
            person["father_name"],

        "national_card_expire":
            person["national_card_expire"]

    }

    folder_path = Path(

        "dataset/labels/national_card"

    )

    save_json(

        data,
        folder_path,
        image_name

    )


# -------------------------------------


def create_driving_license_label(
        person,
        image_name
):

    data = {

        "national_id":
            person["national_id"],

        "full_name":
            person["first_name"]
            + " "
            + person["last_name"],

        "birth_date":
            person["birth_date"],

        "license_issue_date":
            person["license_issue_date"],

        "license_number":
            person["license_number"]

    }

    folder_path = Path(

        "dataset/labels/driving_license"

    )

    save_json(

        data,
        folder_path,
        image_name

    )


# -------------------------------------


def create_vehicle_card_label(
        person,
        image_name
):

    data = {

        "full_name":
            person["first_name"]
            + " "
            + person["last_name"],

        "national_id":
            person["national_id"],

        "father_name":
            person["father_name"],

        "vin":
            person["vin"],

        "plate_number":
            person["plate_number"]

    }

    folder_path = Path(

        "dataset/labels/vehicle_card"

    )

    save_json(

        data,
        folder_path,
        image_name

    )