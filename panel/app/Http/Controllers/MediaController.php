<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * سرو فایل‌های دیسک‌های خصوصی.
 *
 * فایل‌های آپلودی و تصاویر ساخته‌شده هرگز در public نمی‌روند (قانون پروژه).
 * تنها راه دیدنشان همین روت است که احراز هویت می‌خواهد.
 *
 * پارامتر اختیاری «w» یک نسخهٔ کوچک‌شده می‌دهد:
 *   route('media', ['disk' => 'dataset', 'path' => $s->path, 'w' => 320])
 * بدون آن، فهرستی با ۲۴ ردیف ده‌ها مگابایت PNG تمام‌اندازه می‌فرستد.
 * نسخهٔ کوچک یک بار ساخته و روی دیسک کش می‌شود.
 */
class MediaController extends Controller
{
    /** فقط این دیسک‌ها قابل سرو شدن‌اند. */
    private const ALLOWED_DISKS = ['documents', 'dataset', 'testimages'];

    /** عرض‌های مجاز بندانگشتی — فهرست بسته، تا کسی با w دلخواه دیسک را پر نکند. */
    private const ALLOWED_WIDTHS = [120, 200, 320, 480, 800];

    private const THUMB_DISK = 'thumbs';

    public function show(Request $request, string $disk, string $path): StreamedResponse|Response
    {
        abort_unless(in_array($disk, self::ALLOWED_DISKS, true), 404);

        // جلوگیری از پیمایش مسیر: هیچ «..» و هیچ مسیر مطلقی پذیرفته نمی‌شود
        abort_if(str_contains($path, '..') || str_starts_with($path, '/'), 404);

        $storage = Storage::disk($disk);

        abort_unless($storage->exists($path), 404);

        // اطمینان نهایی: مسیر واقعیِ حل‌شده باید داخل ریشهٔ همان دیسک بماند
        $real = realpath($storage->path($path));
        $root = realpath($storage->path(''));

        abort_if($real === false || $root === false || ! str_starts_with($real, $root), 404);

        $width = $this->requestedWidth($request);

        if ($width !== null) {
            $thumb = $this->thumbnail($disk, $path, $real, $width);

            if ($thumb !== null) {
                return response()->file($thumb, [
                    'Cache-Control' => 'private, max-age=86400',
                ]);
            }
        }

        return $storage->response($path, null, [
            'Cache-Control' => 'private, max-age=600',
        ]);
    }

    /** عرض درخواستی، فقط اگر در فهرست مجاز باشد. */
    private function requestedWidth(Request $request): ?int
    {
        $raw = $request->query('w');

        if (! is_scalar($raw)) {
            return null;
        }

        $width = (int) $raw;

        return in_array($width, self::ALLOWED_WIDTHS, true) ? $width : null;
    }

    /**
     * مسیر نسخهٔ کوچک‌شده؛ در نبود کش یک بار ساخته می‌شود.
     * اگر ساخت به هر دلیلی نشد، null برمی‌گردد تا تصویر اصلی سرو شود.
     */
    private function thumbnail(string $disk, string $path, string $source, int $width): ?string
    {
        $key = $disk.'/'.$width.'/'.sha1($path).'.jpg';
        $thumbs = Storage::disk(self::THUMB_DISK);

        if ($thumbs->exists($key) && $thumbs->lastModified($key) >= filemtime($source)) {
            return $thumbs->path($key);
        }

        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $target = $thumbs->path($key);

        if (! is_dir(dirname($target)) && ! @mkdir(dirname($target), 0o775, true) && ! is_dir(dirname($target))) {
            return null;
        }

        try {
            $image = @imagecreatefromstring((string) file_get_contents($source));

            if ($image === false) {
                return null;
            }

            $srcW = imagesx($image);
            $srcH = imagesy($image);

            if ($srcW < 1 || $srcH < 1) {
                imagedestroy($image);

                return null;
            }

            // تصویری که از عرض درخواستی کوچک‌تر است بزرگ نمی‌شود
            $newW = min($width, $srcW);
            $newH = max(1, (int) round($srcH * ($newW / $srcW)));

            $canvas = imagecreatetruecolor($newW, $newH);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

            $ok = imagejpeg($canvas, $target, 78);

            imagedestroy($canvas);
            imagedestroy($image);

            return $ok && is_file($target) ? $target : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
