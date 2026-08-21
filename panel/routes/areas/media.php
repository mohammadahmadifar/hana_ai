<?php

use App\Http\Controllers\MediaController;
use Illuminate\Support\Facades\Route;

// سرو فایل دیسک‌های خصوصی — همیشه پشت احراز هویت.
// استفاده در ویو:  route('media', ['disk' => 'dataset', 'path' => $sample->path])
Route::middleware('auth')
    ->get('/media/{disk}/{path}', [MediaController::class, 'show'])
    ->where('path', '.*')
    ->name('media');
