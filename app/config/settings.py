import os
import shutil

from pathlib import Path

BASE_DIR = Path(__file__).resolve().parents[2]

FONT_PATH = BASE_DIR / "fonts" / "Vazir-Medium.ttf"

TEMPLATE_DIR = BASE_DIR / "dataset" / "templates"

GENERATED_DIR = BASE_DIR / "dataset" / "generated"

# -------------------------------------
# مسیر اجرایی Tesseract
# ویندوز: مسیر پیش‌فرض نصب
# لینوکس/مک: از PATH پیدا می‌شود
# قابل بازنویسی با متغیر محیطی TESSERACT_CMD
# -------------------------------------

WINDOWS_TESSERACT = (
    r"C:\Program Files\Tesseract-OCR\tesseract.exe"
)


def resolve_tesseract_cmd():

    from_env = os.environ.get("TESSERACT_CMD")

    if from_env:
        return from_env

    if os.name == "nt":
        return WINDOWS_TESSERACT

    return shutil.which("tesseract") or "tesseract"


TESSERACT_CMD = resolve_tesseract_cmd()
