<?php

namespace App\Console\Commands;

use App\Exceptions\EngineException;
use App\Services\HanaEngine;
use Illuminate\Console\Command;

/**
 * تست سلامت پل پنل ↔ موتور پایتون.
 *
 * یک نسخه‌خوانی، یک تولید تصویر واقعی و یک OCR واقعی اجرا می‌کند
 * تا مطمئن شویم زنجیرهٔ کامل (PHP → cli.py → app/*.py → Tesseract) کار می‌کند.
 */
class EngineCheck extends Command
{
    protected $signature = 'hana:engine-check
                            {--type=national_card : نوع مدرکی که برای تست ساخته می‌شود}
                            {--keep : فایل‌های ساخته‌شده پاک نشوند}';

    protected $description = 'بررسی سلامت موتور پایتون هانا (نسخه، ساخت تصویر، OCR)';

    private array $artifacts = [];

    public function handle(HanaEngine $engine): int
    {
        $type = (string) $this->option('type');

        $this->newLine();
        $this->components->info('بررسی سلامت موتور «هانا»');

        $this->components->twoColumnDetail('مفسر پایتون', (string) config('hana.python'));
        $this->components->twoColumnDetail('ریشهٔ موتور', (string) config('hana.root'));
        $this->components->twoColumnDetail('سقف زمان (ثانیه)', (string) config('hana.timeout'));
        $this->newLine();

        $outDir = storage_path('app/private/engine-check');

        try {
            // ---------------------------------------------------------
            // ۱) نسخه
            // ---------------------------------------------------------
            $this->components->task('۱) خواندن نسخهٔ موتور', function () use ($engine, &$version) {
                $version = $engine->version();

                return true;
            });

            $this->components->twoColumnDetail('نسخهٔ پل', $version['engine_version'] ?? '؟');
            $this->components->twoColumnDetail('پایتون', $version['python_version'] ?? '؟');
            $this->components->twoColumnDetail('Tesseract', $version['tesseract_version'] ?? '؟');
            $this->components->twoColumnDetail(
                'زبان‌ها',
                implode('، ', $version['tesseract_languages'] ?? []),
            );

            if (empty($version['has_persian'])) {
                $this->components->error('بستهٔ زبان فارسی Tesseract نصب نیست (tesseract-ocr-fas).');

                return self::FAILURE;
            }

            if (empty($version['font_exists'])) {
                $this->components->error('فونت فارسی موتور پیدا نشد: '.($version['font_path'] ?? '؟'));

                return self::FAILURE;
            }

            $this->newLine();

            // ---------------------------------------------------------
            // ۲) دادهٔ مصنوعی
            // ---------------------------------------------------------
            $this->components->task('۲) تولید شخص مصنوعی', function () use ($engine, &$person) {
                $person = $engine->generatePerson()['person'] ?? [];

                return $person !== [];
            });

            $this->components->twoColumnDetail(
                'نمونه',
                ($person['first_name'] ?? '').' '.($person['last_name'] ?? '').' — '.($person['national_id'] ?? ''),
            );
            $this->newLine();

            // ---------------------------------------------------------
            // ۳) نقشهٔ فیلدها
            // ---------------------------------------------------------
            $this->components->task('۳) خواندن نقشهٔ فیلدها', function () use ($engine, &$layouts) {
                $layouts = $engine->documentLayouts()['layouts'] ?? [];

                return $layouts !== [];
            });

            foreach ($layouts as $key => $layout) {
                $this->components->twoColumnDetail(
                    ($layout['label_fa'] ?? $key)." ({$key})",
                    count($layout['fields'] ?? []).' فیلد — '
                        .($layout['template_width'] ?? '؟').'×'.($layout['template_height'] ?? '؟'),
                );
            }

            if (! array_key_exists($type, $layouts)) {
                $this->newLine();
                $this->components->error("نوع مدرک «{$type}» در موتور تعریف نشده است.");

                return self::FAILURE;
            }

            $this->newLine();

            // ---------------------------------------------------------
            // ۴) ساخت تصویر واقعی
            // ---------------------------------------------------------
            $basename = 'engine_check_'.$type;

            $this->components->task('۴) ساخت تصویر «'.$layouts[$type]['label_fa'].'»', function () use ($engine, $type, $person, $outDir, $basename, &$render) {
                $render = $engine->renderDocument(
                    documentType: $type,
                    payload: $person,
                    augmentations: [
                        'rotation' => ['enabled' => true, 'angle' => 4],
                        'brightness' => ['enabled' => true, 'value' => -15],
                        'noise' => ['enabled' => true, 'std' => 6, 'seed' => 7],
                    ],
                    outDir: $outDir,
                    basename: $basename,
                );

                return is_file($render['clean_path'] ?? '');
            });

            $this->artifacts[] = $render['clean_path'] ?? null;
            $this->artifacts[] = $render['augmented_path'] ?? null;

            $this->components->twoColumnDetail('تصویر تمیز', $render['clean_path']);
            $this->components->twoColumnDetail('تصویر با اعوجاج', (string) $render['augmented_path']);
            $this->components->twoColumnDetail(
                'ابعاد / زمان',
                $render['width'].'×'.$render['height'].' — '.$render['duration_ms'].' میلی‌ثانیه',
            );
            $this->components->twoColumnDetail('کادرهای برگشتی', (string) count($render['fields']));

            if (! empty($render['missing_fields'])) {
                $this->components->warn('فیلدهای بدون مقدار: '.implode('، ', $render['missing_fields']));
            }

            foreach ($render['fields'] as $key => $field) {
                $norm = $field['norm'];

                $this->components->twoColumnDetail(
                    '  '.$key,
                    sprintf('x=%.3f y=%.3f w=%.3f h=%.3f', $norm['x'], $norm['y'], $norm['w'], $norm['h']),
                );
            }

            $this->newLine();

            // ---------------------------------------------------------
            // ۵) کیفیت تصویر
            // ---------------------------------------------------------
            $this->components->task('۵) سنجش کیفیت تصویر', function () use ($engine, $render, &$quality) {
                $quality = $engine->imageQuality($render['clean_path']);

                return isset($quality['blur_score']);
            });

            $this->components->twoColumnDetail(
                'تاری / روشنایی',
                $quality['blur_score'].' / '.$quality['brightness']
                    .($quality['is_blurry_hint'] ? '  (تار)' : '  (واضح)'),
            );
            $this->newLine();

            // ---------------------------------------------------------
            // ۶) OCR واقعی
            // ---------------------------------------------------------
            $this->components->task('۶) اجرای OCR روی تصویر ساخته‌شده', function () use ($engine, $render, $type, $outDir, &$ocr) {
                $ocr = $engine->ocrDocument(
                    path: $render['clean_path'],
                    documentType: $type,
                    preprocess: true,
                    outDir: $outDir,
                );

                return trim((string) ($ocr['raw_text'] ?? '')) !== '';
            });

            $this->artifacts[] = $ocr['preprocessed_path'] ?? null;

            $this->components->twoColumnDetail('تصویر پیش‌پردازش‌شده', (string) $ocr['preprocessed_path']);
            $this->components->twoColumnDetail(
                'حجم متن / زمان',
                $ocr['char_count'].' نویسه در '.$ocr['line_count'].' خط — '.$ocr['duration_ms'].' میلی‌ثانیه',
            );

            if (! empty($ocr['extra']['vin']) || ! empty($ocr['extra']['plate'])) {
                $this->components->twoColumnDetail('شماره شاسی', (string) ($ocr['extra']['vin'] ?? '—'));
                $this->components->twoColumnDetail('پلاک', (string) ($ocr['extra']['plate'] ?? '—'));
            }

            $this->newLine();
            $this->line('  <fg=gray>--- متن استخراج‌شده ---</>');

            foreach (array_slice(preg_split('/\R/u', trim((string) $ocr['raw_text'])), 0, 12) as $line) {
                $this->line('  '.$line);
            }

            $this->newLine();
            $this->components->info('موتور سالم است؛ زنجیرهٔ پنل ← پل ← موتور ← Tesseract کامل کار کرد.');

            return self::SUCCESS;
        } catch (EngineException $exception) {
            $this->newLine();
            $this->components->error($exception->userMessage());
            $this->line('  <fg=gray>جزئیات فنی: '.$exception->detail.'</>');
            $this->line('  <fg=gray>دستور: '.$exception->command.'</>');

            return self::FAILURE;
        } finally {
            $this->cleanup($outDir);
        }
    }

    private function cleanup(string $outDir): void
    {
        if ($this->option('keep')) {
            $this->newLine();
            $this->components->warn('فایل‌های تست نگه داشته شدند: '.$outDir);

            return;
        }

        foreach (array_filter($this->artifacts) as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        if (is_dir($outDir) && count((array) scandir($outDir)) <= 2) {
            @rmdir($outDir);
        }
    }
}
