from app.person.person_generator import generate_person

from dataset.image_writer import create_national_card
from dataset.image_writer import create_driving_license
from dataset.image_writer import create_vehicle_card
from app.preprocessing.image_preprocessing import preprocess_folder
from app.ocr.ocr_engine import ocr_folder
from app.evaluation.ocr_evaluation import evaluate_folder


number = 1


for i in range(number):

    person = generate_person()

    create_national_card(person)

    create_driving_license(person)

    create_vehicle_card(person)




#-------------------------------------


preprocess_folder(

    "dataset/processed/national_card",

    "dataset/preprocessed/national_card"

)


preprocess_folder(

    "dataset/processed/driving_license",

    "dataset/preprocessed/driving_license"

)


preprocess_folder(

    "dataset/processed/vehicle_card",

    "dataset/preprocessed/vehicle_card"

)


#-------------------------------------


ocr_folder(

    "dataset/preprocessed/national_card",

    "dataset/ocr_results/national_card"

)

ocr_folder(

    "dataset/preprocessed/driving_license",

    "dataset/ocr_results/driving_license"

)


ocr_folder(

    "dataset/preprocessed/vehicle_card",

    "dataset/ocr_results/vehicle_card"

)



#-------------------------------------




evaluate_folder(

    labels_folder="dataset/labels/national_card",

    ocr_folder="dataset/ocr_results/national_card"

)




evaluate_folder(

    labels_folder="dataset/labels/driving_license",

    ocr_folder="dataset/ocr_results/driving_license"

)




evaluate_folder(

    labels_folder="dataset/labels/vehicle_card",

    ocr_folder="dataset/ocr_results/vehicle_card"

)

