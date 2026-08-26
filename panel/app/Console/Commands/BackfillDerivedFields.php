<?php

namespace App\Console\Commands;

use App\Models\CaseDocument;
use App\Models\ExtractedField;
use App\Models\PermitCase;
use App\Services\Cases\CaseScorer;
use App\Services\Cases\DocumentValidator;
use App\Services\Cases\FieldDeriver;
use App\Support\PersianValue;
use Illuminate\Console\Command;
use Throwable;

/**
 * پرکردن فیلدهای محاسبه‌شده روی پرونده‌های **قبلاً پردازش‌شده** (تسک ۷۲۵).
 *
 * چرا لازم است: از این به بعد `FieldExtractor` خودش تاریخ انقضای گواهینامه را
 * از تاریخ صدور می‌سازد، ولی پرونده‌هایی که پیش از این تغییر پردازش شده‌اند
 * ردیفش را ندارند و بدون OCR دوباره هم نمی‌گیرند. این دستور همان یک مرحله را
 * جدا اجرا می‌کند — بدون موتور، بدون صف، در چند ثانیه.
 *
 * چون مقدار تازه روی «اعتبار زمانی مدرک» اثر دارد، اعتبارسنجی و امتیازدهی هم
 * دوباره اجرا می‌شوند؛ وگرنه ردیف تازه‌ای نوشته می‌شد که هیچ بررسی‌ای ندیده
 * بود و صفحهٔ پرونده همچنان «بررسی نشد» نشان می‌داد. تصمیم دستی کارشناس
 * دست‌نخورده می‌ماند (CaseScorer آن را بازنویسی نمی‌کند).
 *
 * ```
 * php artisan hana:backfill-derived --dry   # فقط گزارش، بدون نوشتن
 * php artisan hana:backfill-derived
 * ```
 */
class BackfillDerivedFields extends Command
{
    protected $signature = 'hana:backfill-derived
                            {--dry : فقط گزارش کن، چیزی ننویس}
                            {--case= : فقط یک پرونده}';

    protected $description = 'ساخت فیلدهای محاسبه‌شده (تاریخ انقضای گواهینامه) روی پرونده‌های قدیمی';

    public function handle(FieldDeriver $deriver, DocumentValidator $validator, CaseScorer $scorer): int
    {
        $dry = (bool) $this->option('dry');
        $onlyCase = (int) $this->option('case');

        $written = 0;
        $found = 0;
        $caseIds = [];

        // chunkById و نه get(): جدول مدارک با هر پرونده رشد می‌کند و این دستور
        // برای همان جدولِ بزرگ نوشته شده. بارگذاری یکجای همه‌اش دقیقاً همان
        // کاری است که یک بار روی سرور کوچک حافظه را تمام می‌کند.
        CaseDocument::query()
            ->with('documentType.fields')
            ->when($onlyCase > 0, fn ($query) => $query->where('case_id', $onlyCase))
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use ($deriver, $dry, &$written, &$found, &$caseIds): void {
                foreach ($chunk as $document) {
                    if (! FieldDeriver::handles($document)) {
                        continue;
                    }

                    $found++;
                    $preview = $deriver->preview($document);

                    if ($preview !== null) {
                        $this->line('  '.$document->documentType->label_fa
                            .' (پروندهٔ '.PersianValue::toPersianDigits((string) $document->case_id).'): '
                            .$preview['from'].' ← '.$preview['value']);
                    }

                    if ($dry) {
                        if ($preview !== null) {
                            $written++;
                        }

                        continue;
                    }

                    // حتی وقتی preview خالی است derive() صدا زده می‌شود: تنها
                    // کسی که ردیفِ محاسبه‌شدهٔ بی‌پشتوانه را پاک می‌کند همان
                    // است (تاریخ صدوری که ناخوانا شده باشد). آن حالت نادر است،
                    // پس پرس‌وجوی «آیا ردیفی برای پاک‌کردن هست؟» فقط همان‌جا
                    // زده می‌شود، نه به‌ازای هر مدرک.
                    $stale = $preview === null && ExtractedField::query()
                        ->where('case_document_id', $document->id)
                        ->where('source', FieldDeriver::SOURCE)
                        ->exists();

                    $changed = $deriver->derive($document);

                    if ($changed > 0 || $stale) {
                        $caseIds[(int) $document->case_id] = true;
                    }

                    $written += $changed;
                }
            });

        if ($found === 0) {
            $this->components->info('هیچ مدرکی با قاعدهٔ استنتاج پیدا نشد؛ کاری لازم نبود.');

            return self::SUCCESS;
        }

        $this->components->info(
            PersianValue::toPersianDigits((string) $found)
            .' مدرک با قاعدهٔ استنتاج بررسی شد.'
            .($dry ? ' (حالت آزمایشی — چیزی نوشته نمی‌شود)' : '')
        );

        if ($dry || $caseIds === []) {
            $this->components->info(
                PersianValue::toPersianDigits((string) $written).' مقدار محاسبه‌شده'
                .($dry ? ' نوشته می‌شد.' : ' نوشته شد.')
            );

            return self::SUCCESS;
        }

        // مقدار تازه باید دیده شود: بدون این دو مرحله، ردیف نوشته می‌شد ولی
        // «اعتبار زمانی مدرک» همچنان «بررسی نشد» می‌ماند.
        $revalidated = 0;

        foreach (array_keys($caseIds) as $caseId) {
            $case = PermitCase::find($caseId);

            if ($case === null) {
                continue;
            }

            try {
                $validator->validate($case);
                $scorer->score($case);
                $revalidated++;
            } catch (Throwable $exception) {
                $this->components->warn(
                    'پروندهٔ '.PersianValue::toPersianDigits((string) $caseId)
                    .' دوباره ارزیابی نشد: '.$exception->getMessage()
                );
            }
        }

        $this->components->info(
            PersianValue::toPersianDigits((string) $written).' مقدار محاسبه‌شده نوشته شد و '
            .PersianValue::toPersianDigits((string) $revalidated).' پرونده دوباره اعتبارسنجی و امتیازدهی شد.'
        );

        return self::SUCCESS;
    }
}
