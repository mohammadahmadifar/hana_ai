<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * کمک‌کار ناوبری پنل.
 *
 * چرا لازم است: قالب‌های سراسری (منوی کناری و کارت‌های داشبورد) نام روت‌هایی را
 * صدا می‌زنند که ممکن است هنوز ساخته نشده باشند. تنها `Route::has()` کافی نیست،
 * چون روتی که ثبت شده ولی پارامتر اجباری دارد (مثلاً `/cases/{status}`) باعث
 * می‌شود `route()` استثنای UrlGenerationException بیندازد و چون این کد داخل
 * قالب پایه است، *همه* صفحه‌های پنل ۵۰۰ می‌شوند.
 *
 * پس ساخت نشانی همیشه از این‌جا رد می‌شود: اگر روت نبود یا ساختن نشانی شکست
 * خورد، به‌جای استثنا مقدار null برمی‌گردد و قالب آیتم را «به‌زودی» نشان می‌دهد.
 */
final class PanelMenu
{
    /**
     * نشانی یک روت نام‌دار، یا null اگر روت ثبت نشده یا ساختن نشانی ممکن نیست.
     *
     * @param  array<array-key, mixed>  $parameters
     */
    public static function url(?string $name, array $parameters = []): ?string
    {
        if ($name === null || $name === '' || ! Route::has($name)) {
            return null;
        }

        try {
            return route($name, $parameters);
        } catch (Throwable) {
            // پارامتر اجباریِ داده‌نشده یا هر خطای دیگر در ساخت نشانی:
            // قالب باید بی‌سروصدا به حالت «به‌زودی» برگردد، نه اینکه صفحه بیفتد.
            return null;
        }
    }

    /**
     * آیا می‌شود برای این روت لینک ساخت؟
     *
     * @param  array<array-key, mixed>  $parameters
     */
    public static function linkable(?string $name, array $parameters = []): bool
    {
        return static::url($name, $parameters) !== null;
    }
}
