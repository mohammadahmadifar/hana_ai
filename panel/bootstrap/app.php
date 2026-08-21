<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
        ]);

        $middleware->redirectGuestsTo('/login');

        // نگهبان «حساب فعال» روی همه روت‌های web: کاربری که وسط نشست غیرفعال
        // می‌شود باید بلافاصله بیرون بیفتد، نه اینکه تا انقضای نشست دسترسی داشته باشد.
        // EnsureRole بدون آرگومان مهمان‌ها را دست‌نخورده رد می‌کند، پس حلقه ریدایرکت نمی‌سازد.
        $middleware->appendToGroup('web', \App\Http\Middleware\EnsureRole::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
