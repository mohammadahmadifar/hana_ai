<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * سرو فایل‌های دیسک‌های خصوصی.
 *
 * فایل‌های آپلودی و تصاویر ساخته‌شده هرگز در public نمی‌روند (قانون پروژه).
 * تنها راه دیدنشان همین روت است که احراز هویت می‌خواهد.
 */
class MediaController extends Controller
{
    /** فقط این دیسک‌ها قابل سرو شدن‌اند. */
    private const ALLOWED_DISKS = ['documents', 'dataset', 'testimages'];

    public function show(Request $request, string $disk, string $path): StreamedResponse
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

        return $storage->response($path, null, [
            'Cache-Control' => 'private, max-age=600',
        ]);
    }
}
