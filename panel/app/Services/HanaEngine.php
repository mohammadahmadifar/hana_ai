<?php

namespace App\Services;

use App\Exceptions\EngineException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * تنها پل پنل به موتور پایتون.
 *
 * قرارداد: یک سند JSON روی stdin می‌رود، یک سند JSON از stdout برمی‌گردد.
 * هیچ جای دیگری در پنل نباید exec/shell_exec بنویسد؛ اگر روزی موتور به
 * ماشین دیگری منتقل شد، فقط بدنهٔ همین کلاس عوض می‌شود.
 */
class HanaEngine
{
    /**
     * فراخوانی یک دستور موتور.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws EngineException
     */
    public function call(string $command, array $payload = []): array
    {
        $python = (string) config('hana.python');
        $root = (string) config('hana.root');
        $module = (string) config('hana.module', 'hana_engine.cli');
        $timeout = (int) config('hana.timeout', 300);

        if ($python === '' || $root === '') {
            throw EngineException::notConfigured('hana.python or hana.root is empty');
        }

        if (! is_dir($root)) {
            throw EngineException::notConfigured("engine root not found: {$root}");
        }

        if (! is_file($python)) {
            throw EngineException::notConfigured("python binary not found: {$python}");
        }

        $input = json_encode(
            ['command' => $command, 'payload' => (object) $payload],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        if ($input === false) {
            throw EngineException::fromEngine(
                $command,
                'ساخت درخواست برای موتور ممکن نشد.',
                json_last_error_msg(),
            );
        }

        $process = new Process(
            command: [$python, '-m', $module],
            cwd: $root,
            env: [
                'PYTHONUNBUFFERED' => '1',
                'PYTHONIOENCODING' => 'utf-8',
                'PYTHONDONTWRITEBYTECODE' => '1',
            ],
            input: $input,
            timeout: $timeout > 0 ? $timeout : null,
        );

        $startedAt = microtime(true);

        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            $this->logStderr($command, $process->getErrorOutput());

            throw EngineException::timedOut($command, $timeout, $exception);
        } catch (Throwable $exception) {
            throw EngineException::crashed(
                $command,
                $exception::class.': '.$exception->getMessage(),
                $exception,
            );
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->logStderr($command, $process->getErrorOutput());

        $stdout = trim($process->getOutput());

        if ($stdout === '') {
            Log::error('hana-engine: empty stdout', [
                'command' => $command,
                'exit_code' => $process->getExitCode(),
                'duration_ms' => $durationMs,
            ]);

            throw EngineException::unreadableOutput(
                $command,
                'empty stdout, exit_code='.$process->getExitCode(),
            );
        }

        $decoded = json_decode($stdout, true);

        if (! is_array($decoded) || ! array_key_exists('ok', $decoded)) {
            Log::error('hana-engine: invalid json', [
                'command' => $command,
                'exit_code' => $process->getExitCode(),
                'stdout' => mb_substr($stdout, 0, 1000),
            ]);

            throw EngineException::unreadableOutput(
                $command,
                'invalid json: '.mb_substr($stdout, 0, 500),
            );
        }

        if ($decoded['ok'] !== true) {
            $message = (string) ($decoded['error'] ?? 'خطای نامشخص در موتور پردازش تصویر.');
            $detail = (string) ($decoded['detail'] ?? '');

            Log::warning('hana-engine: command failed', [
                'command' => $command,
                'error' => $message,
                'detail' => mb_substr($detail, 0, 1000),
                'duration_ms' => $durationMs,
            ]);

            throw EngineException::fromEngine($command, $message, $detail);
        }

        Log::debug('hana-engine: ok', [
            'command' => $command,
            'duration_ms' => $durationMs,
        ]);

        $data = $decoded['data'] ?? [];

        return is_array($data) ? $data : ['value' => $data];
    }

    // ------------------------------------------------------------------
    // متدهای راحت
    // ------------------------------------------------------------------

    /**
     * نسخهٔ موتور، نسخهٔ Tesseract و زبان‌های موجود.
     *
     * @return array<string, mixed>
     */
    public function version(): array
    {
        return $this->call('version');
    }

    /**
     * تولید شخص مصنوعی (هیچ دادهٔ هویتی واقعی در کار نیست).
     *
     * @return array<string, mixed>
     */
    public function generatePerson(int $count = 1): array
    {
        return $this->call('generate_person', ['count' => $count]);
    }

    /**
     * نقشهٔ فیلدهای هر نوع مدرک روی قالب.
     *
     * @return array<string, mixed>
     */
    public function documentLayouts(): array
    {
        return $this->call('document_layouts');
    }

    /**
     * ساخت تصویر مدرک + کادر نسبی هر فیلد + نسخهٔ با اعوجاج.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $augmentations
     * @return array<string, mixed>
     */
    public function renderDocument(
        string $documentType,
        array $payload,
        array $augmentations = [],
        ?string $outDir = null,
        ?string $basename = null,
    ): array {
        return $this->call('render_document', [
            'document_type' => $documentType,
            'payload' => $payload,
            'augmentations' => (object) $augmentations,
            'out_dir' => $outDir,
            'basename' => $basename,
        ]);
    }

    /**
     * اجرای OCR روی یک تصویر مدرک.
     *
     * @return array<string, mixed>
     */
    public function ocrDocument(
        string $path,
        ?string $documentType = null,
        bool $preprocess = true,
        ?string $outDir = null,
    ): array {
        return $this->call('ocr_document', [
            'path' => $path,
            'document_type' => $documentType,
            'preprocess' => $preprocess,
            'out_dir' => $outDir,
        ]);
    }

    /**
     * سنجش کیفیت تصویر (ابعاد، تاری، روشنایی).
     *
     * @return array<string, mixed>
     */
    public function imageQuality(string $path): array
    {
        return $this->call('image_quality', ['path' => $path]);
    }

    // ------------------------------------------------------------------

    private function logStderr(string $command, string $stderr): void
    {
        $stderr = trim($stderr);

        if ($stderr === '' || ! config('hana.log_stderr', true)) {
            return;
        }

        Log::info('hana-engine stderr', [
            'command' => $command,
            'stderr' => mb_substr($stderr, 0, (int) config('hana.stderr_limit', 4000)),
        ]);
    }
}
