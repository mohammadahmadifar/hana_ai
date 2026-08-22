<?php

namespace App\Services\Cases;

use App\Exceptions\EngineException;
use App\Models\CaseDocument;
use App\Models\OcrRun;
use App\Services\HanaEngine;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * گام دوم فرایند مجوز: «متن‌خوانی مدرک» (OCR).
 *
 * این کلاس تنها جایی است که در پنل `ocr_document` موتور صدا زده می‌شود. کارش
 * عمداً کوچک است: یک مدرک می‌گیرد، متن خام و کلیدهای ویژهٔ همان نوع مدرک را
 * می‌گیرد و در `ocr_runs` ذخیره می‌کند. تفسیر متن (استخراج فیلد) کار
 * FieldExtractor است و ترتیب مرحله‌ها کار CasePipeline.
 *
 * سه تصمیمی که ارزش توضیح دارند:
 *
 * ۱) **مدرکی که اعتبارسنجی اولیه ردش کرده هرگز به موتور نمی‌رود.** هر تماس با
 *    موتور یک پروسهٔ پایتون تازه است (حدود ۱۷۰ms فقط راه‌اندازی، به‌علاوهٔ چند
 *    ثانیه خودِ OCR) و خرج کردنش روی فایلی که از پیش می‌دانیم خوانا نیست
 *    اتلاف محض است. در این حالت هم ردیف `ocr_runs` نوشته می‌شود — با وضعیت
 *    `failed` و پیام فارسی — تا صفحهٔ نتیجه بتواند بگوید «چرا این مدرک متن ندارد».
 *
 * ۲) **نسخهٔ موتور یک بار برای هر نمونهٔ سرویس پرسیده می‌شود.** `version()` هم
 *    یک پروسهٔ پایتون کامل است؛ اگر برای هر مدرک صدا زده شود، هزینهٔ یک پروندهٔ
 *    چهارمدرکی دو برابر می‌شود. پس همان‌جا حفظ (memoize) می‌شود و Job که برای
 *    همهٔ مدارک یک نمونه از این سرویس را نگه می‌دارد، عملاً یک بار می‌پرسد.
 *
 * ۳) **برای هر مدرک فقط یک ردیف `ocr_runs` می‌ماند.** اجرای دوبارهٔ پردازش
 *    همان ردیف را به‌روز می‌کند، ردیف تازه نمی‌سازد؛ وگرنه هر بار «پردازش دوباره»
 *    تاریخچهٔ متن خام را دوتا می‌کرد و `latestOcrRun` هم گران‌تر می‌شد.
 *
 * هیچ‌جای این کلاس exec یا shell_exec نیست — تنها پل، App\Services\HanaEngine است.
 */
final class DocumentOcr
{
    /** برچسب فارسی وضعیت‌های ستون `case_documents.ocr_status`. */
    public const STATUS_LABELS = [
        'pending' => 'در نوبت',
        'queued' => 'در صف',
        'running' => 'در حال متن‌خوانی',
        'done' => 'انجام‌شده',
        'failed' => 'ناموفق',
    ];

    /**
     * سقف متن خامی که ذخیره می‌شود.
     *
     * ستون longText است و متن یک مدرک معمولاً چند صد کاراکتر؛ این سقف فقط
     * جلوی خروجی معیوبِ چندمگابایتی را می‌گیرد تا صفحهٔ نتیجه از نفس نیفتد.
     */
    private const RAW_TEXT_LIMIT = 200_000;

    /** طول ستون `ocr_runs.engine_version`. */
    private const VERSION_LIMIT = 40;

    /**
     * سقف متن هر «نسخه» (بزرگ‌نمایی) در کلید extra.
     *
     * نسخه‌ها کنار هم در یک ستون json می‌نشینند، پس سقفشان از سقف متن اصلی
     * تنگ‌تر است: متن یک مدرک چند صد کاراکتر است و این عدد فقط جلوی خروجی
     * معیوب را می‌گیرد.
     */
    private const VARIANT_TEXT_LIMIT = 20_000;

    /** بیشترین تعداد نسخه‌ای که ذخیره می‌شود. */
    private const MAX_VARIANTS = 6;

    private ?string $engineVersion = null;

    private bool $engineVersionAsked = false;

    public function __construct(private readonly HanaEngine $engine) {}

    /**
     * OCR یک مدرک.
     *
     * وضعیت `case_documents.ocr_status` و ردیف `ocr_runs` را خودش می‌نویسد و
     * هرگز استثنا بیرون نمی‌دهد: خطای موتور به ردیف `failed` با پیام فارسی
     * تبدیل می‌شود تا یک مدرکِ خراب کل پرونده را زمین نزند.
     */
    public function run(CaseDocument $document): OcrRun
    {
        $document->loadMissing('documentType');

        $typeKey = $document->documentType?->key;

        // ---------- ۱) مدرکی که پیش‌تر رد شده، خرج موتور ندارد ----------

        if ($document->precheck_status === 'failed') {
            return $this->finish($document, 'failed', [
                'error' => 'این مدرک در اعتبارسنجی اولیهٔ فایل رد شده است، پس متن‌خوانی روی آن اجرا نشد. '
                    .'فایل سالم را جایگزین کنید تا پردازش دوباره انجام شود.',
                'params' => $this->params($typeKey, ['skipped' => 'precheck_failed']),
                'raw_text' => null,
                'extra' => null,
                'duration_ms' => 0,
                // موتور اصلاً صدا زده نشد، پس حتی نسخه‌اش هم پرسیده نمی‌شود.
                'engine_version' => null,
            ]);
        }

        // ---------- ۲) فایل باید واقعاً روی دیسک باشد ----------

        $path = $this->absolutePath($document);

        if ($path === null) {
            return $this->finish($document, 'failed', [
                'error' => 'فایل این مدرک روی سرور پیدا نشد و متن‌خوانی ممکن نشد. '
                    .'مدرک را دوباره بارگذاری کنید.',
                'params' => $this->params($typeKey, ['disk' => $document->disk, 'path' => $document->path]),
                'raw_text' => null,
                'extra' => null,
                'duration_ms' => 0,
                'engine_version' => null,
            ]);
        }

        // ---------- ۳) تماس با موتور ----------

        $this->markRunning($document);

        $startedAt = microtime(true);

        try {
            // برای کارت خودرو همین تماس، مسیر ویژهٔ VIN و پلاک را هم اجرا می‌کند
            // و نتیجه‌اش در کلید extra برمی‌گردد.
            $result = $this->engine->ocrDocument($path, $typeKey);
        } catch (EngineException $exception) {
            Log::warning('OCR مدرک ناموفق بود.', [
                'case_document_id' => $document->id,
                'document_type' => $typeKey,
            ] + $exception->context());

            return $this->finish($document, 'failed', [
                'error' => $exception->userMessage(),
                'params' => $this->params($typeKey, ['engine_detail' => $this->clip($exception->detail, 500)]),
                'raw_text' => null,
                'extra' => null,
                'duration_ms' => $this->elapsed($startedAt),
            ]);
        } catch (Throwable $exception) {
            Log::error('OCR مدرک با خطای غیرمنتظره متوقف شد.', [
                'case_document_id' => $document->id,
                'exception' => $exception::class.': '.$exception->getMessage(),
            ]);

            return $this->finish($document, 'failed', [
                'error' => 'متن‌خوانی این مدرک با خطای غیرمنتظره متوقف شد؛ '
                    .'دوباره پردازش کنید و اگر تکرار شد به مدیر سامانه اطلاع دهید.',
                'params' => $this->params($typeKey, ['exception' => $this->clip($exception::class, 120)]),
                'raw_text' => null,
                'extra' => null,
                'duration_ms' => $this->elapsed($startedAt),
            ]);
        }

        // ---------- ۴) ذخیرهٔ نتیجه ----------

        $rawText = $this->clip((string) ($result['raw_text'] ?? ''), self::RAW_TEXT_LIMIT);
        $extra = $this->extra($result);

        // متن خالی خطای موتور نیست ولی «انجام‌شده»ی بی‌فایده است؛ به‌جای شکست،
        // وضعیت done می‌ماند و هشدارش را DocumentValidator از نبود فیلدها می‌گیرد.
        return $this->finish($document, 'done', [
            'error' => trim($rawText) === ''
                ? 'موتور هیچ متنی از این تصویر نخواند؛ کیفیت یا زاویهٔ عکس را بهتر کنید.'
                : null,
            'params' => $this->params($typeKey, [
                'preprocess' => (bool) ($result['preprocess'] ?? true),
                'lang' => $result['lang'] ?? null,
                'config' => $result['config'] ?? null,
                'char_count' => (int) ($result['char_count'] ?? mb_strlen($rawText)),
                'line_count' => (int) ($result['line_count'] ?? 0),
                // برای توضیح نتیجه لازم است: با چه عرضی خوانده شد و عرض مرجع
                // این نوع مدرک چقدر بود (تسک ۶۶۲ و پیام «تصویر کوچک است»).
                'source_width' => isset($result['source_width']) ? (int) $result['source_width'] : null,
                'reference_width' => isset($result['reference_width']) ? (int) $result['reference_width'] : null,
                'variants' => is_array($extra['variants'] ?? null) ? count($extra['variants']) : null,
                'engine_duration_ms' => isset($result['duration_ms']) ? (int) $result['duration_ms'] : null,
            ]),
            'raw_text' => $rawText,
            'extra' => $extra,
            'duration_ms' => $this->elapsed($startedAt),
        ]);
    }

    /**
     * نسخهٔ موتور — یک بار پرسیده و تا پایان عمر این نمونه نگه داشته می‌شود.
     *
     * اگر موتور در دسترس نباشد null برمی‌گردد و کار متوقف نمی‌شود؛ نداشتن
     * شمارهٔ نسخه دلیل خوبی برای پردازش‌نکردن پرونده نیست.
     */
    public function engineVersion(): ?string
    {
        if ($this->engineVersionAsked) {
            return $this->engineVersion;
        }

        $this->engineVersionAsked = true;

        try {
            $version = $this->engine->version();
            $value = trim((string) ($version['engine_version'] ?? ''));

            $this->engineVersion = $value === '' ? null : $this->clip($value, self::VERSION_LIMIT);
        } catch (Throwable $exception) {
            Log::info('نسخهٔ موتور خوانده نشد؛ ردیف OCR بدون شمارهٔ نسخه ذخیره می‌شود.', [
                'exception' => $exception->getMessage(),
            ]);

            $this->engineVersion = null;
        }

        return $this->engineVersion;
    }

    // ------------------------------------------------------------------
    // ذخیره‌سازی
    // ------------------------------------------------------------------

    /** مدرک را «در حال متن‌خوانی» علامت می‌زند تا صفحهٔ وضعیت زنده بماند. */
    private function markRunning(CaseDocument $document): void
    {
        $this->persistRun($document, [
            'status' => 'running',
            'engine_version' => $this->engineVersion(),
            'error' => null,
        ]);

        $this->setDocumentStatus($document, 'running');
    }

    /**
     * ردیف اجرا را می‌بندد و وضعیت مدرک را هم‌راستا می‌کند.
     *
     * @param  'done'|'failed'  $status
     * @param  array<string, mixed>  $attributes
     */
    private function finish(CaseDocument $document, string $status, array $attributes): OcrRun
    {
        // اگر مسیرِ فراخوان نسخه را صریح داده باشد (مثلاً null چون موتور اصلاً
        // صدا زده نشد) همان محترم است؛ وگرنه نسخهٔ حفظ‌شده پرسیده می‌شود.
        // شرط صریح لازم است: در آرایهٔ سمت راستِ «+» تابع همیشه اجرا می‌شد.
        if (! array_key_exists('engine_version', $attributes)) {
            $attributes['engine_version'] = $this->engineVersion();
        }

        $run = $this->persistRun($document, $attributes + ['status' => $status]);

        $this->setDocumentStatus($document, $status);

        return $run;
    }

    /**
     * یک ردیف `ocr_runs` برای هر مدرک — نه بیشتر.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function persistRun(CaseDocument $document, array $attributes): OcrRun
    {
        $run = OcrRun::query()->firstOrNew([
            'subject_type' => $document->getMorphClass(),
            'subject_id' => $document->getKey(),
        ]);

        $run->forceFill($attributes)->save();

        $document->setRelation('latestOcrRun', $run);

        return $run;
    }

    private function setDocumentStatus(CaseDocument $document, string $status): void
    {
        if ($document->ocr_status === $status) {
            return;
        }

        $document->forceFill(['ocr_status' => $status])->save();
    }

    // ------------------------------------------------------------------
    // کمکی‌ها
    // ------------------------------------------------------------------

    /** مسیر مطلق فایل، یا null اگر روی دیسک نباشد. */
    private function absolutePath(CaseDocument $document): ?string
    {
        if (($document->path ?? '') === '' || ($document->disk ?? '') === '') {
            return null;
        }

        try {
            $path = $document->absolutePath();
        } catch (Throwable $exception) {
            Log::warning('مسیر مدرک قابل حل نبود.', [
                'case_document_id' => $document->id,
                'disk' => $document->disk,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        return is_file($path) ? $path : null;
    }

    /**
     * فقط کلیدهای قراردادی extra نگه داشته می‌شوند.
     *
     * `vin` و `plate` را FieldExtractor مستقیم می‌خواند؛ `error` هم پیام مسیر
     * ویژهٔ کارت خودرو است که اگر بیاید باید دیده شود.
     *
     * `variants` (تسک ۶۶۲) متن همان مدرک در بزرگ‌نمایی‌های دیگر است. این‌جا
     * ذخیره می‌شود و نه در ستونی تازه، چون `extra` از قبل ستون json است و
     * حجم واقعی‌اش چند صد کاراکتر در هر نسخه — مهاجرت برای این اندازه داده
     * هزینهٔ بی‌دلیلی است. ذخیره لازم است چون «پردازش دوباره»ی استخراج فیلد
     * باید بتواند بدون اجرای دوبارهٔ موتور همان نسخه‌ها را ببیند.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>|null
     */
    private function extra(array $result): ?array
    {
        $extra = $result['extra'] ?? null;
        $variants = $this->variants($result);

        // نه کلید extra ای آمد نه نسخه‌ای: چیزی برای ذخیره نیست.
        if (! is_array($extra) && $variants === []) {
            return null;
        }

        if (! is_array($extra)) {
            $extra = [];
        }

        $clean = [
            'vin' => $this->text($extra['vin'] ?? null),
            'plate' => $this->text($extra['plate'] ?? null),
        ];

        if (isset($extra['error']) && is_string($extra['error']) && trim($extra['error']) !== '') {
            $clean['error'] = $this->clip($extra['error'], 500);
        }

        if ($variants !== []) {
            $clean['variants'] = $variants;
        }

        return $clean;
    }

    /**
     * نسخه‌های چندمقیاسی، به همان ترتیبی که موتور داده (نسخهٔ اول = مقیاس ۱.۰).
     *
     * نسخهٔ بدون متن دور ریخته می‌شود: چیزی برای استخراج ندارد و فقط ستون را
     * چاق می‌کند. `image_path` هم ذخیره نمی‌شود — فایل موقت است و نگه‌داشتن
     * مسیرش در دیتابیس فقط توهم دسترسی می‌سازد.
     *
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    private function variants(array $result): array
    {
        $variants = $result['variants'] ?? null;

        if (! is_array($variants)) {
            return [];
        }

        $out = [];

        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            $rawText = $this->clip((string) ($variant['raw_text'] ?? ''), self::VARIANT_TEXT_LIMIT);

            if (trim($rawText) === '') {
                continue;
            }

            $variantExtra = is_array($variant['extra'] ?? null) ? $variant['extra'] : [];

            $out[] = [
                'scale' => isset($variant['scale']) ? (float) $variant['scale'] : null,
                'width' => isset($variant['width']) ? (int) $variant['width'] : null,
                'raw_text' => $rawText,
                'vin' => $this->text($variantExtra['vin'] ?? null),
                'plate' => $this->text($variantExtra['plate'] ?? null),
            ];

            if (count($out) >= self::MAX_VARIANTS) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $more
     * @return array<string, mixed>
     */
    private function params(?string $typeKey, array $more = []): array
    {
        return array_filter(
            ['document_type' => $typeKey] + $more,
            static fn ($value): bool => $value !== null,
        );
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function clip(string $value, int $limit): string
    {
        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) : $value;
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
