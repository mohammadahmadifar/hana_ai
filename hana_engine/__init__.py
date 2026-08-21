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

# فضای ذخیره‌سازی خصوصی پنل؛ مالک آن www-data است (کاربر php-fpm و کارگر صف)
PANEL_STORAGE = ENGINE_ROOT / "panel" / "storage" / "app" / "private"

# مسیرهای مجاز برای «خواندن»: کل مخزن موتور — قالب‌ها، فونت و دیتاست لازم‌اند
READABLE_ROOTS = (
    ENGINE_ROOT,
)

# مسیرهای مجاز برای «نوشتن»: فقط خروجی‌ها.
# قالب‌ها (dataset/templates)، فونت‌ها، app/ ، hana_engine/ و کد پنل هرگز
# با نوشتن موتور بازنویسی نمی‌شوند.
WRITABLE_ROOTS = (
    PANEL_STORAGE,
    ENGINE_ROOT / "dataset" / "generated",
    ENGINE_ROOT / "dataset" / "processed",
    ENGINE_ROOT / "dataset" / "preprocessed",
    ENGINE_ROOT / "dataset" / "ocr_results",
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


def _current_user() -> str:
    """نام کاربر اجراکنندهٔ فرایند، برای پیام‌های خطای روشن‌تر."""
    try:
        import pwd

        return pwd.getpwuid(os.geteuid()).pw_name
    except Exception:
        return str(os.geteuid())


def _within(path, roots) -> bool:
    target = _real(path)

    for root in roots:
        root_real = _real(root)

        if target == root_real or root_real in target.parents:
            return True

    return False


def is_readable_path(path) -> bool:
    """آیا این مسیر در محدودهٔ مجاز خواندن است؟"""
    return _within(path, READABLE_ROOTS)


def is_writable_path(path) -> bool:
    """آیا نوشتن در این مسیر مجاز است؟ (فقط پوشه‌های خروجی)"""
    return _within(path, WRITABLE_ROOTS)


def default_out_dir(command: str) -> Path:
    """
    پوشهٔ پیش‌فرض خروجی یک دستور.

    زیر storage پنل است نه dataset/ ، چون php-fpm و کارگر صف با کاربر
    www-data اجرا می‌شوند و مالک dataset/ کاربر root است.
    """
    return PANEL_STORAGE / "engine" / (command or "misc")


def assert_writable_dir(path, create: bool = True) -> Path:
    """اعتبارسنجی پوشهٔ خروجی: در محدودهٔ مجاز نوشتن باشد و واقعاً قابل نوشتن."""
    if path is None or str(path).strip() == "":
        raise EngineError("مسیر پوشه خروجی مشخص نشده است.", "out_dir is empty")

    raw = Path(str(path))

    # مسیر نسبی را نسبت به ریشه موتور معنا می‌کنیم، نه نسبت به cwd
    if not raw.is_absolute():
        raw = ENGINE_ROOT / raw

    target = _real(raw)

    if not is_writable_path(target):
        raise EngineError(
            "نوشتن در این مسیر مجاز نیست؛ خروجی موتور فقط در فضای ذخیره‌سازی پنل "
            "یا پوشه‌های خروجی دیتاست نوشته می‌شود.",
            f"out_dir={target} writable_roots={[str(r) for r in WRITABLE_ROOTS]}",
        )

    if create:
        try:
            target.mkdir(parents=True, exist_ok=True)
        except OSError as exc:
            raise EngineError(
                f"ساخت پوشهٔ خروجی «{target}» ممکن نشد؛ "
                f"کاربر «{_current_user()}» حق نوشتن در پوشهٔ بالادست را ندارد.",
                f"{type(exc).__name__}: {exc}",
            ) from exc

    if not target.is_dir():
        raise EngineError("مسیر خروجی یک پوشه معتبر نیست.", f"out_dir={target}")

    if not os.access(str(target), os.W_OK | os.X_OK):
        raise EngineError(
            f"پوشهٔ خروجی «{target}» برای کاربر «{_current_user()}» قابل نوشتن نیست.",
            f"out_dir={target} uid={os.geteuid()}",
        )

    return target


def assert_readable(path, must_exist: bool = True) -> Path:
    """اعتبارسنجی مسیر فایل ورودی (فقط خواندن)."""
    if path is None or str(path).strip() == "":
        raise EngineError("مسیر فایل مشخص نشده است.", "path is empty")

    raw = Path(str(path))

    # مسیر نسبی را نسبت به ریشه موتور معنا می‌کنیم
    if not raw.is_absolute():
        raw = ENGINE_ROOT / raw

    target = _real(raw)

    if not is_readable_path(target):
        raise EngineError(
            "مسیر فایل خارج از مسیرهای مجاز موتور است.",
            f"path={target} readable_roots={[str(r) for r in READABLE_ROOTS]}",
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
    "PANEL_STORAGE",
    "READABLE_ROOTS",
    "WRITABLE_ROOTS",
    "EngineError",
    "is_readable_path",
    "is_writable_path",
    "default_out_dir",
    "assert_writable_dir",
    "assert_readable",
    "safe_basename",
]

