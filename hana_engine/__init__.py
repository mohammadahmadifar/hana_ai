"""
پل ارتباطی موتور پایتون «هانا» با پنل لاراول.

این پکیج تنها راه مجاز فراخوانی موتور از سمت PHP است.
هیچ ماژولی از این پکیج نباید چیزی روی stdout چاپ کند؛ خروجی stdout
فقط و فقط توسط hana_engine.cli و به‌صورت یک سند JSON نوشته می‌شود.
"""

from __future__ import annotations

import os
import sys
from pathlib import Path

# نسخه موتور (پل)
ENGINE_VERSION = "1.0.0"

# ریشه پروژه پایتون: /home/coder/hana_ai
ENGINE_ROOT = Path(__file__).resolve().parents[1]

# تا ماژول‌های app/ و dataset/ بدون وابستگی به cwd قابل import باشند
if str(ENGINE_ROOT) not in sys.path:
    sys.path.insert(0, str(ENGINE_ROOT))

# مسیرهای مجاز برای خواندن/نوشتن فایل
ALLOWED_ROOTS = (
    ENGINE_ROOT,
    ENGINE_ROOT / "panel" / "storage" / "app" / "private",
)


class EngineError(Exception):
    """خطای قابل نمایش به کاربر؛ پیام فارسی + جزئیات فنی."""

    def __init__(self, message: str, detail: str = ""):
        super().__init__(message)
        self.message = message
        self.detail = detail


def _real(path) -> Path:
    """مسیر مطلق و بدون symlink (حتی اگر هنوز ساخته نشده باشد)."""
    return Path(os.path.realpath(str(Path(path).expanduser())))


def is_allowed(path) -> bool:
    target = _real(path)
    for root in ALLOWED_ROOTS:
        root_real = _real(root)
        if target == root_real or root_real in target.parents:
            return True
    return False


def ensure_allowed_dir(path, create: bool = True) -> Path:
    """اعتبارسنجی مسیر پوشه خروجی و (در صورت نیاز) ساخت آن."""
    if path is None or str(path).strip() == "":
        raise EngineError("مسیر پوشه خروجی مشخص نشده است.", "out_dir is empty")

    target = _real(path)

    if not is_allowed(target):
        raise EngineError(
            "مسیر خروجی خارج از مسیرهای مجاز موتور است.",
            f"out_dir={target} allowed={[str(r) for r in ALLOWED_ROOTS]}",
        )

    if create:
        try:
            target.mkdir(parents=True, exist_ok=True)
        except OSError as exc:
            raise EngineError(
                "ساخت پوشه خروجی ممکن نشد.",
                f"{type(exc).__name__}: {exc}",
            ) from exc

    if not target.is_dir():
        raise EngineError("مسیر خروجی یک پوشه معتبر نیست.", f"out_dir={target}")

    return target


def ensure_allowed_file(path, must_exist: bool = True) -> Path:
    """اعتبارسنجی مسیر فایل ورودی."""
    if path is None or str(path).strip() == "":
        raise EngineError("مسیر فایل مشخص نشده است.", "path is empty")

    raw = Path(str(path))

    # مسیر نسبی را نسبت به ریشه موتور معنا می‌کنیم
    if not raw.is_absolute():
        raw = ENGINE_ROOT / raw

    target = _real(raw)

    if not is_allowed(target):
        raise EngineError(
            "مسیر فایل خارج از مسیرهای مجاز موتور است.",
            f"path={target} allowed={[str(r) for r in ALLOWED_ROOTS]}",
        )

    if must_exist and not target.is_file():
        raise EngineError("فایل مورد نظر پیدا نشد.", f"path={target}")

    return target


def safe_basename(name, fallback: str) -> str:
    """نام فایل امن: بدون مسیر، بدون نقطهٔ ابتدایی، فقط کاراکترهای بی‌خطر."""
    if name is None or str(name).strip() == "":
        return fallback

    raw = str(name).strip()
    raw = raw.replace("\\", "/").split("/")[-1]

    cleaned = "".join(
        ch if (ch.isalnum() or ch in ("-", "_", ".")) else "_"
        for ch in raw
    ).strip("._")

    if cleaned.lower().endswith(".png"):
        cleaned = cleaned[:-4]

    return cleaned or fallback


__all__ = [
    "ENGINE_VERSION",
    "ENGINE_ROOT",
    "ALLOWED_ROOTS",
    "EngineError",
    "is_allowed",
    "ensure_allowed_dir",
    "ensure_allowed_file",
    "safe_basename",
]
