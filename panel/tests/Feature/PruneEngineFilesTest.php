<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * تسک ۶۶۸ — پاک‌سازی تصویرهای موقتی موتور.
 *
 * این تست روی فایل واقعی کار می‌کند نه Storage::fake، چون خودِ دستور با
 * `unlink` و `filemtime` سروکار دارد و دیسک ساختگی سنِ فایل را نمی‌سازد.
 *
 * ولی روی storage **واقعی** اجرا نمی‌شود: ریشهٔ پاک‌سازی از `config('hana.prune_base')`
 * می‌آید و این‌جا به یک پوشهٔ موقت تغییر داده می‌شود. اولین نسخهٔ همین تست این
 * کار را نمی‌کرد و اجرای `--days=0` خروجی موتورِ همان لحظه را هم پاک کرد —
 * تستی که دادهٔ واقعی را می‌برد، خودش باگ است.
 */
class PruneEngineFilesTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = storage_path('framework/testing/prune-'.uniqid());

        mkdir($this->base, 0775, true);

        config(['hana.prune_base' => $this->base]);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->base);

        parent::tearDown();
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.'/'.$entry;

            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }

        @rmdir($path);
    }

    public function test_old_engine_files_go_and_fresh_ones_stay(): void
    {
        $old = $this->makeFile('engine/ocr_document/prune_old.png', Carbon::now()->subDays(30));
        $fresh = $this->makeFile('engine/ocr_document/prune_fresh.png', Carbon::now());

        $this->artisan('hana:prune-engine-files', ['--days' => 7])->assertSuccessful();

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($fresh);
    }

    public function test_dry_run_reports_but_deletes_nothing(): void
    {
        $old = $this->makeFile('engine/ocr_document/prune_dry.png', Carbon::now()->subDays(30));

        $this->artisan('hana:prune-engine-files', ['--days' => 7, '--dry-run' => true])
            ->expectsOutputToContain('فقط گزارش')
            ->assertSuccessful();

        $this->assertFileExists($old);
    }

    /**
     * مهم‌ترین تست این فایل: مدرک پرونده هرگز پاک نمی‌شود.
     *
     * فایل‌های `documents` تنها نسخهٔ مدرک متقاضی‌اند و هیچ سنی دلیل
     * پاک‌کردنشان نیست؛ این دستور فقط خروجی موقتِ موتور را می‌برد.
     */
    public function test_case_documents_are_never_touched(): void
    {
        $document = $this->makeFile('documents/cases/999999/prune_guard.jpg', Carbon::now()->subYears(5));
        $testImage = $this->makeFile('testimages/999999/prune_guard.png', Carbon::now()->subYears(5));

        $this->artisan('hana:prune-engine-files', ['--days' => 0])->assertSuccessful();

        $this->assertFileExists($document);
        $this->assertFileExists($testImage);
    }

    /** پیش‌نمایش صفحهٔ «تصویر تستی» خروجی موقت است و پاک می‌شود. */
    public function test_the_test_image_preview_folder_is_pruned(): void
    {
        $preview = $this->makeFile('testimages/_preprocessed/1405-05/prune_old.png', Carbon::now()->subDays(30));

        $this->artisan('hana:prune-engine-files', ['--days' => 7])->assertSuccessful();

        $this->assertFileDoesNotExist($preview);
    }

    private function makeFile(string $relative, Carbon $modifiedAt): string
    {
        $path = $this->base.'/'.$relative;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, 'تست');
        touch($path, $modifiedAt->getTimestamp());

        return $path;
    }
}
