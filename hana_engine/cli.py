"""
پل JSON بین پنل لاراول و موتور پایتون.

اجرا:
    python -m hana_engine.cli   < ورودی JSON روی stdin

ورودی:
    {"command": "...", "payload": {...}}

خروجی (فقط و فقط یک سند JSON روی stdout):
    {"ok": true,  "data": {...}}
    {"ok": false, "error": "پیام فارسی", "detail": "..."}

نکته مهم: از همان ابتدای اجرا، توصیف‌گر فایل stdout به stderr منتقل می‌شود.
بنابراین هر print باقی‌مانده در ماژول‌های قدیمی (مثلاً app/ocr/vehicle_card_ocr.py
هنگام خواندن پلاک) به stderr می‌رود و JSON خروجی را خراب نمی‌کند. سند نهایی
روی نسخهٔ اصلی stdout که کنار گذاشته شده نوشته می‌شود.
"""

from __future__ import annotations

import json
import os
import sys
import traceback

from . import ENGINE_ROOT, ENGINE_VERSION, EngineError

# ---------------------------------------------------------------
# مهار stdout: از این لحظه هر چیزی که روی stdout نوشته شود به stderr می‌رود
# ---------------------------------------------------------------
_REAL_STDOUT_FD = os.dup(1)
os.dup2(2, 1)

try:
    sys.stdout.reconfigure(encoding="utf-8")
    sys.stderr.reconfigure(encoding="utf-8")
except Exception:  # پایتون‌های قدیمی‌تر
    pass


def _emit(document, exit_code):
    text = json.dumps(document, ensure_ascii=False)

    with os.fdopen(_REAL_STDOUT_FD, "w", encoding="utf-8", closefd=True) as out:
        out.write(text)
        out.write("\n")
        out.flush()

    sys.exit(exit_code)


def ok(data):
    _emit({"ok": True, "data": data}, 0)


def fail(message, detail=""):
    _emit({"ok": False, "error": message, "detail": str(detail)[:4000]}, 1)


# ---------------------------------------------------------------
# دستورها
# ---------------------------------------------------------------

def cmd_version(payload):
    import shutil
    import subprocess

    from app.config.settings import FONT_PATH, TESSERACT_CMD

    tesseract_version = None
    languages = []
    tesseract_error = None

    binary = shutil.which(TESSERACT_CMD) or TESSERACT_CMD

    try:
        result = subprocess.run(
            [binary, "--version"],
            capture_output=True,
            text=True,
            timeout=20,
        )
        first_line = (result.stdout or result.stderr or "").strip().splitlines()
        tesseract_version = first_line[0] if first_line else None

        langs = subprocess.run(
            [binary, "--list-langs"],
            capture_output=True,
            text=True,
            timeout=20,
        )
        lines = (langs.stdout or "").strip().splitlines()
        languages = [line.strip() for line in lines[1:] if line.strip()]

    except Exception as exc:
        tesseract_error = f"{type(exc).__name__}: {exc}"

    return {
        "engine_version": ENGINE_VERSION,
        "engine_root": str(ENGINE_ROOT),
        "python_version": sys.version.split()[0],
        "python_executable": sys.executable,
        "tesseract_cmd": str(TESSERACT_CMD),
        "tesseract_version": tesseract_version,
        "tesseract_languages": languages,
        "tesseract_error": tesseract_error,
        "has_persian": "fas" in languages,
        "font_path": str(FONT_PATH),
        "font_exists": os.path.isfile(str(FONT_PATH)),
        "commands": sorted(COMMANDS.keys()),
    }


def cmd_generate_person(payload):
    from app.person.person_generator import generate_person

    count = int(payload.get("count", 1) or 1)

    if count < 1 or count > 200:
        raise EngineError(
            "تعداد درخواستی باید بین ۱ تا ۲۰۰ باشد.",
            f"count={count}",
        )

    people = [generate_person() for _ in range(count)]

    return {
        "person": people[0],
        "people": people,
        "count": len(people),
    }


def cmd_document_layouts(payload):
    from .layouts import describe

    return {"layouts": describe()}


def cmd_render_document(payload):
    from .render import render_document

    return render_document(
        document_type=payload.get("document_type"),
        payload=payload.get("payload"),
        augmentations=payload.get("augmentations"),
        out_dir=payload.get("out_dir"),
        basename=payload.get("basename"),
    )


def cmd_ocr_document(payload):
    from .ocr import ocr_document

    return ocr_document(
        path=payload.get("path"),
        document_type=payload.get("document_type"),
        preprocess=bool(payload.get("preprocess", True)),
        out_dir=payload.get("out_dir"),
    )


def cmd_image_quality(payload):
    from .quality import image_quality

    return image_quality(payload.get("path"))


COMMANDS = {
    "version": cmd_version,
    "generate_person": cmd_generate_person,
    "document_layouts": cmd_document_layouts,
    "render_document": cmd_render_document,
    "ocr_document": cmd_ocr_document,
    "image_quality": cmd_image_quality,
}


# ---------------------------------------------------------------
# نقطهٔ ورود
# ---------------------------------------------------------------

def read_request():
    raw = ""

    try:
        raw = sys.stdin.read()
    except Exception:
        raw = ""

    if not (raw or "").strip():
        # حالت کمکی برای اجرای دستی:  python -m hana_engine.cli version
        if len(sys.argv) > 1:
            return {"command": sys.argv[1], "payload": {}}

        raise EngineError(
            "درخواستی برای موتور ارسال نشده است.",
            "empty stdin",
        )

    try:
        request = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise EngineError(
            "قالب درخواست ارسالی به موتور نامعتبر است.",
            f"json error: {exc}",
        ) from exc

    if not isinstance(request, dict):
        raise EngineError(
            "قالب درخواست ارسالی به موتور نامعتبر است.",
            "request is not an object",
        )

    return request


def main():
    try:
        request = read_request()

        command = (request.get("command") or "").strip()
        payload = request.get("payload") or {}

        if not command:
            raise EngineError("دستور موتور مشخص نشده است.", "missing command")

        if command not in COMMANDS:
            raise EngineError(
                "دستور درخواستی در موتور تعریف نشده است.",
                f"command={command} available={sorted(COMMANDS.keys())}",
            )

        if not isinstance(payload, dict):
            raise EngineError(
                "داده‌های ارسالی به موتور باید یک شیء (object) باشد.",
                "payload is not an object",
            )

        data = COMMANDS[command](payload)

    except EngineError as exc:
        fail(exc.message, exc.detail)

    except SystemExit:
        raise

    except MemoryError:
        fail("حافظهٔ کافی برای پردازش این تصویر وجود ندارد.", traceback.format_exc())

    except Exception as exc:
        traceback.print_exc(file=sys.stderr)
        fail(
            "اجرای موتور با خطای پیش‌بینی‌نشده متوقف شد.",
            f"{type(exc).__name__}: {exc}",
        )

    else:
        ok(data)


if __name__ == "__main__":
    main()
