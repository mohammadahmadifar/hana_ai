<?php

namespace App\Services\Cases;

use App\Exceptions\EngineException;
use App\Models\CaseDocument;
use App\Models\Setting;
use App\Models\ValidationResult;
use App\Services\HanaEngine;
use App\Support\PersianValue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * گام یکم فرایند مجوز: «اعتبارسنجی اولیه فایل» — پیش از خرج کردن OCR.
 *
 * ترتیب کار عمدی است:
 *   ۱) بررسی‌های محلی و ارزان (وجود فایل، حجم، MIME واقعی، ابعاد) — بدون موتور.
 *      اگر این‌جا رد شد، هیچ پروسهٔ پایتونی بالا نمی‌آید. این همان «رد سریع» است.
 *   ۲) فقط اگر فایل از همهٔ بررسی‌های محلی گذشت، تاری و روشنایی از موتور
 *      پرسیده می‌شود (`image_quality`). موتور اگر خطا داد، کل آپلود زمین
 *      نمی‌خورد؛ یک ایراد از نوع «هشدار» ثبت می‌شود و مدرک قبول می‌ماند.
 *
 * MIME از روی محتوا sniff می‌شود نه از روی نام فایل: کاربر می‌تواند یک PDF
 * را «card.png» صدا بزند و مرورگر هم همان را اعلام کند.
 *
 * هیچ آستانه‌ای این‌جا هاردکد نیست؛ همه از `Setting::get('precheck.limits')`
 * می‌آید و اگر تنظیمات هنوز seed نشده باشد، پیش‌فرض امن زیر جای آن را می‌گیرد.
 */
final class DocumentPrecheck
{
    /**
     * پیش‌فرض امن وقتی ReferenceDataSeeder اجرا نشده یا کلیدی جا افتاده.
     * هم‌ارز با مقادیر همان seeder است تا رفتار دو محیط یکی بماند.
     *
     * @var array<string, mixed>
     */
    public const DEFAULT_LIMITS = [
        'min_bytes' => 20480,             // ۲۰ کیلوبایت
        'max_bytes' => 12582912,          // ۱۲ مگابایت
        'min_width' => 600,
        'min_height' => 380,
        'min_blur_score' => 60,           // واریانس لاپلاسین؛ کمتر یعنی تارتر
        'min_brightness' => 40,
        'max_brightness' => 225,
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
    ];

    /** نام خوانای فرمت‌های مجاز، برای پیام خطا. */
    private const MIME_LABELS = [
        'image/jpeg' => 'JPG',
        'image/png' => 'PNG',
        'image/webp' => 'WEBP',
        'image/gif' => 'GIF',
        'image/bmp' => 'BMP',
        'application/pdf' => 'PDF',
    ];

    /**
     * بازرسی فایل تازه‌آپلودشده.
     *
     * رکورد `case_documents` را خودش پر و ذخیره می‌کند و برای هر ایراد یک
     * ردیف `validation_results` با scope=file می‌نویسد.
     *
     * @return bool true یعنی مدرک قبول است و می‌تواند به OCR برود
     */
    public function inspect(CaseDocument $document): bool
    {
        $limits = $this->limits();

        /** @var list<PrecheckIssue> $issues */
        $issues = [];

        $facts = [
            'mime' => null,
            'size_bytes' => null,
            'width' => null,
            'height' => null,
            'checksum' => null,
            'blur_score' => null,
            'brightness_score' => null,
        ];

        $path = $this->absolutePath($document);

        if ($path === null) {
            $issues[] = PrecheckIssue::error(
                'file.missing',
                'فایل این مدرک روی سرور پیدا نشد.',
                'لطفاً مدرک را دوباره بارگذاری کنید؛ اگر باز هم تکرار شد با پشتیبانی تماس بگیرید.',
                ['disk' => $document->disk, 'path' => $document->path],
            );

            return $this->persist($document, $facts, $issues);
        }

        // ---------- بررسی‌های محلی (بدون موتور) ----------

        $size = (int) @filesize($path);
        $facts['size_bytes'] = $size;
        $facts['checksum'] = $this->checksum($path);

        $issues = array_merge($issues, $this->checkSize($size, $limits));

        $mime = $this->sniffMime($path);
        $facts['mime'] = $mime;

        $mimeIssue = $this->checkMime($mime, $limits);

        if ($mimeIssue !== null) {
            $issues[] = $mimeIssue;
        }

        $dimensionsKnown = false;

        if ($mimeIssue === null) {
            $info = @getimagesize($path);

            if (! is_array($info) || (int) ($info[0] ?? 0) <= 0) {
                $issues[] = PrecheckIssue::error(
                    'file.unreadable_image',
                    'محتوای این فایل تصویر سالمی نیست و باز نمی‌شود.',
                    'احتمالاً بارگذاری نیمه‌کاره مانده است؛ همان عکس را دوباره بارگذاری کنید.',
                    ['mime' => $mime],
                );
            } else {
                $dimensionsKnown = true;
                $facts['width'] = (int) $info[0];
                $facts['height'] = (int) $info[1];

                $sizeIssue = $this->checkDimensions($facts['width'], $facts['height'], $limits);

                if ($sizeIssue !== null) {
                    $issues[] = $sizeIssue;
                }
            }
        }

        // ---------- تاری و روشنایی (فقط اگر محلی‌ها پاک بودند) ----------

        if ($dimensionsKnown && ! $this->hasBlocking($issues)) {
            $issues = array_merge($issues, $this->checkQuality($path, $facts, $limits));
        }

        return $this->persist($document, $facts, $issues);
    }

    // ------------------------------------------------------------------
    // بررسی‌ها
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $limits
     * @return list<PrecheckIssue>
     */
    private function checkSize(int $size, array $limits): array
    {
        $min = (int) $limits['min_bytes'];
        $max = (int) $limits['max_bytes'];

        if ($size < $min) {
            return [PrecheckIssue::error(
                'file.too_small',
                'حجم فایل ('.$this->bytes($size).') کمتر از حداقل مجاز ('.$this->bytes($min).') است.',
                'این معمولاً یعنی تصویر خیلی کوچک یا ناقص ذخیره شده؛ به‌جای اسکرین‌شات، عکس اصلی دوربین یا اسکن مدرک را بارگذاری کنید.',
                ['size_bytes' => $size, 'min_bytes' => $min],
            )];
        }

        if ($max > 0 && $size > $max) {
            return [PrecheckIssue::error(
                'file.too_large',
                'حجم فایل ('.$this->bytes($size).') بیشتر از حداکثر مجاز ('.$this->bytes($max).') است.',
                'عکس را با کیفیت پایین‌تر ذخیره کنید یا از حالت «سند/Document» دوربین گوشی استفاده کنید.',
                ['size_bytes' => $size, 'max_bytes' => $max],
            )];
        }

        return [];
    }

    /** @param array<string, mixed> $limits */
    private function checkMime(?string $mime, array $limits): ?PrecheckIssue
    {
        /** @var list<string> $allowed */
        $allowed = array_values(array_filter((array) $limits['allowed_mimes'], 'is_string'));

        if ($mime !== null && in_array($mime, $allowed, true)) {
            return null;
        }

        $allowedLabels = implode('، ', array_map(
            fn (string $item): string => self::MIME_LABELS[$item] ?? $item,
            $allowed,
        ));

        $found = $mime === null
            ? 'قابل تشخیص نیست'
            : (self::MIME_LABELS[$mime] ?? $mime);

        return PrecheckIssue::error(
            'file.mime',
            'محتوای فایل تصویر نیست؛ نوع واقعی آن '.$found.' است. فقط '.$allowedLabels.' پذیرفته می‌شود.',
            'پسوند نام فایل ملاک نیست، محتوای آن بررسی می‌شود. لطفاً مستقیم از مدرک عکس بگیرید یا صفحهٔ فایل را به تصویر JPG تبدیل کنید و دوباره بارگذاری کنید.',
            ['mime' => $mime, 'allowed_mimes' => $allowed],
        );
    }

    /** @param array<string, mixed> $limits */
    private function checkDimensions(int $width, int $height, array $limits): ?PrecheckIssue
    {
        $minWidth = (int) $limits['min_width'];
        $minHeight = (int) $limits['min_height'];

        if ($width >= $minWidth && $height >= $minHeight) {
            return null;
        }

        return PrecheckIssue::error(
            'file.too_narrow',
            'ابعاد تصویر ('.$this->pixels($width, $height).') کمتر از حداقل مجاز ('.$this->pixels($minWidth, $minHeight).') است.',
            'از فاصلهٔ نزدیک‌تر و با بالاترین کیفیت دوربین از کل مدرک عکس بگیرید؛ تصویر بریده یا کوچک‌شده نفرستید.',
            [
                'width' => $width,
                'height' => $height,
                'min_width' => $minWidth,
                'min_height' => $minHeight,
            ],
        );
    }

    /**
     * تاری و روشنایی از پل موتور.
     *
     * موتور یک پروسهٔ پایتون بالا می‌آورد (اندازه‌گیری‌شده: حدود ۰٫۲ ثانیه برای
     * یک تصویر ۹۶۰×۵۴۰). خطای موتور نباید آپلود را زمین بزند، پس گرفته می‌شود
     * و به یک «هشدار» تبدیل می‌گردد.
     *
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $limits
     * @return list<PrecheckIssue>
     */
    private function checkQuality(string $path, array &$facts, array $limits): array
    {
        try {
            $quality = app(HanaEngine::class)->imageQuality($path);
        } catch (EngineException $exception) {
            Log::warning('precheck: image_quality failed', [
                'path' => $path,
            ] + $exception->context());

            return [PrecheckIssue::warning(
                'file.quality_unavailable',
                'بررسی خودکار تاری و روشنایی این تصویر انجام نشد.',
                'فرمت، حجم و ابعاد فایل سالم است و پردازش ادامه می‌یابد؛ اگر نتیجهٔ خواندن مدرک ضعیف بود، عکس واضح‌تری بارگذاری کنید.',
                ['engine_error' => $exception->getMessage()],
            )];
        }

        // ابعاد موتور دقیق‌تر است (تصویر واقعاً decode شده)، پس مرجع نهایی همان است.
        if ((int) ($quality['width'] ?? 0) > 0) {
            $facts['width'] = (int) $quality['width'];
            $facts['height'] = (int) $quality['height'];
        }

        $blur = isset($quality['blur_score']) ? (float) $quality['blur_score'] : null;
        $brightness = isset($quality['brightness']) ? (float) $quality['brightness'] : null;

        $facts['blur_score'] = $blur;
        $facts['brightness_score'] = $brightness;

        $issues = [];

        $minBlur = (float) $limits['min_blur_score'];
        $minBrightness = (float) $limits['min_brightness'];
        $maxBrightness = (float) $limits['max_brightness'];

        if ($blur !== null && $blur < $minBlur) {
            $issues[] = PrecheckIssue::error(
                'file.blurry',
                'تصویر خیلی تار است و نوشته‌های مدرک خوانده نمی‌شود.',
                'دوربین را ثابت نگه دارید، صبر کنید تا روی مدرک فوکوس کند و در نور کافی دوباره عکس بگیرید.',
                ['blur_score' => $blur, 'min_blur_score' => $minBlur],
            );
        }

        if ($brightness !== null && $brightness < $minBrightness) {
            $issues[] = PrecheckIssue::error(
                'file.too_dark',
                'تصویر خیلی تاریک است و متن مدرک دیده نمی‌شود.',
                'مدرک را زیر نور بیشتری بگذارید و نگذارید سایهٔ دست یا گوشی روی آن بیفتد.',
                ['brightness' => $brightness, 'min_brightness' => $minBrightness],
            );
        } elseif ($brightness !== null && $brightness > $maxBrightness) {
            $issues[] = PrecheckIssue::error(
                'file.too_bright',
                'تصویر بیش از حد روشن است و نوشته‌ها محو شده‌اند.',
                'فلاش را خاموش کنید و از تابش مستقیم نور یا انعکاس روی سطح مدرک فاصله بگیرید.',
                ['brightness' => $brightness, 'max_brightness' => $maxBrightness],
            );
        }

        return $issues;
    }

    // ------------------------------------------------------------------
    // ذخیره
    // ------------------------------------------------------------------

    /**
     * نوشتن نتیجه روی رکورد مدرک و روی جدول `validation_results`.
     *
     * @param  array<string, mixed>  $facts
     * @param  list<PrecheckIssue>  $issues
     */
    private function persist(CaseDocument $document, array $facts, array $issues): bool
    {
        $passed = ! $this->hasBlocking($issues);

        $document->fill([
            'mime' => $facts['mime'] ?? $document->mime,
            'size_bytes' => $facts['size_bytes'],
            'width' => $facts['width'],
            'height' => $facts['height'],
            'checksum' => $facts['checksum'],
            'blur_score' => $facts['blur_score'],
            'brightness_score' => $facts['brightness_score'],
            'precheck_status' => $passed ? 'passed' : 'failed',
            'precheck_issues' => array_map(
                static fn (PrecheckIssue $issue): array => $issue->toArray(),
                $issues,
            ),
        ])->save();

        $this->writeValidationResults($document, $issues);

        return $passed;
    }

    /**
     * یک ردیف scope=file برای هر ایراد.
     *
     * ردیف‌های قبلیِ همین مدرک اول پاک می‌شوند تا اجرای دوبارهٔ inspect()
     * (مثلاً بعد از جایگزینی فایل) ردیف تکراری یا کهنه جا نگذارد.
     *
     * @param  list<PrecheckIssue>  $issues
     */
    private function writeValidationResults(CaseDocument $document, array $issues): void
    {
        ValidationResult::query()
            ->where('case_document_id', $document->id)
            ->where('scope', 'file')
            ->delete();

        foreach ($issues as $issue) {
            ValidationResult::create([
                'case_id' => $document->case_id,
                'case_document_id' => $document->id,
                'rule_key' => mb_substr($issue->code, 0, 60),
                'scope' => 'file',
                'status' => $issue->validationStatus(),
                'message_fa' => mb_substr($issue->messageFa, 0, 255),
                'details' => $issue->details + ['hint_fa' => $issue->hintFa],
            ]);
        }
    }

    // ------------------------------------------------------------------
    // ابزار
    // ------------------------------------------------------------------

    /**
     * محدودیت‌ها از تنظیمات، با پرکردن کلیدهای جامانده از پیش‌فرض امن.
     *
     * @return array<string, mixed>
     */
    private function limits(): array
    {
        $stored = Setting::get('precheck.limits');

        if (! is_array($stored)) {
            return self::DEFAULT_LIMITS;
        }

        $limits = array_merge(self::DEFAULT_LIMITS, $stored);

        // یک مقدار بی‌معنا در تنظیمات نباید کل آپلود را قفل کند.
        if (! is_array($limits['allowed_mimes']) || $limits['allowed_mimes'] === []) {
            $limits['allowed_mimes'] = self::DEFAULT_LIMITS['allowed_mimes'];
        }

        return $limits;
    }

    /** مسیر مطلق فایل، یا null اگر روی دیسک نبود. */
    private function absolutePath(CaseDocument $document): ?string
    {
        if (blank($document->disk) || blank($document->path)) {
            return null;
        }

        try {
            if (! Storage::disk($document->disk)->exists($document->path)) {
                return null;
            }

            $path = $document->absolutePath();
        } catch (Throwable $exception) {
            Log::warning('precheck: document path is unreachable', [
                'document_id' => $document->id,
                'disk' => $document->disk,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        return is_file($path) && is_readable($path) ? $path : null;
    }

    /** MIME واقعی از روی محتوا — نام فایل و ادعای مرورگر نادیده گرفته می‌شود. */
    private function sniffMime(string $path): ?string
    {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo !== false) {
            $mime = @finfo_file($finfo, $path);
            finfo_close($finfo);

            if (is_string($mime) && $mime !== '') {
                return strtolower(trim(explode(';', $mime)[0]));
            }
        }

        // پشتیبان: هدر خود تصویر
        $info = @getimagesize($path);

        return is_array($info) && is_string($info['mime'] ?? null) ? strtolower($info['mime']) : null;
    }

    private function checksum(string $path): ?string
    {
        $hash = @hash_file('sha256', $path);

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /** @param list<PrecheckIssue> $issues */
    private function hasBlocking(array $issues): bool
    {
        foreach ($issues as $issue) {
            if ($issue->isBlocking()) {
                return true;
            }
        }

        return false;
    }

    /** حجم خوانا با ارقام فارسی. */
    private function bytes(int $size): string
    {
        if ($size >= 1048576) {
            return PersianValue::decimal($size / 1048576, 1).' مگابایت';
        }

        if ($size >= 1024) {
            return PersianValue::decimal($size / 1024, 0).' کیلوبایت';
        }

        return PersianValue::decimal($size, 0).' بایت';
    }

    private function pixels(int $width, int $height): string
    {
        return PersianValue::toPersianDigits($width.'×'.$height).' پیکسل';
    }
}
