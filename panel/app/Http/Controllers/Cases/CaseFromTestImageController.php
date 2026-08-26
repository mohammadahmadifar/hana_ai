<?php

namespace App\Http\Controllers\Cases;

use App\Http\Controllers\Controller;
use App\Models\CaseDocument;
use App\Models\PermitCase;
use App\Models\ServiceType;
use App\Models\TestImage;
use App\Services\Cases\DocumentPrecheck;
use App\Support\PersianValue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * «بفرست به فرایند بررسی» — پلِ بین ساخت تصویر تستی و ویزارد درخواست خدمت.
 *
 * خواستهٔ صریح تسک ۶۲۷ این بود: «خروجی: پیش‌نمایش تصویر + دکمهٔ دانلود +
 * دکمهٔ بفرست به فرایند بررسی تا همان‌جا تست شود». دو تای اول ساخته شده بود
 * و همین یکی جا مانده بود؛ بدون آن، برای آزمودن یک اعوجاجِ ساخته‌شده باید
 * تصویر را دانلود می‌کردی و دوباره دستی در ویزارد بارگذاری می‌کردی.
 *
 * کاری که می‌کند: یک **پروندهٔ پیش‌نویس** تازه از نوع خدمتِ انتخاب‌شده باز
 * می‌کند، همین تصویر را به‌عنوان مدرکِ همان نوع رویش می‌نشاند، بررسی اولیهٔ
 * فایل (تسک ۶۳۰) را اجرا می‌کند و کاربر را می‌برد سر صفحهٔ مدارک تا بقیهٔ
 * مدارک لازم را بگذارد و پرونده را ثبت کند.
 *
 * سه قاعده‌ای که عمداً رعایت شده:
 *
 *  ۱) **پرونده در وضعیت پیش‌نویس می‌ماند، خودکار ثبت نمی‌شود.** یک مدرک از
 *     سه‌تا آمده؛ ثبتِ خودکار یعنی پروندهٔ ناقص در صف بررسی.
 *  ۲) **مسیر فایل از ورودی کاربر ساخته نمی‌شود** — همان قاعدهٔ
 *     CaseDocumentController: `cases/{case_id}/{document_type_key}-{uuid}.{ext}`.
 *  ۳) **تصویر کپی می‌شود، منتقل نمی‌شود.** تصویر تستی سر جایش می‌ماند تا
 *     بشود همان را چند بار و در چند پرونده آزمود.
 */
class CaseFromTestImageController extends Controller
{
    /** دیسک خصوصی مدارک پرونده — همان دیسکِ CaseDocumentController. */
    private const DISK = 'documents';

    /** پسوندهای مجاز برای فایلِ ساختهٔ خودِ موتور. */
    private const EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    public function store(Request $request, TestImage $testImage, DocumentPrecheck $precheck): RedirectResponse
    {
        abort_unless(
            $request->user()->isAdmin() || $testImage->user_id === $request->user()->id,
            403,
            'این تصویر تستی متعلق به کاربر دیگری است و شما اجازهٔ فرستادنش به فرایند بررسی را ندارید.',
        );

        $testImage->load('documentType');

        $type = $testImage->documentType;

        if ($type === null) {
            return back()->with('error', 'نوع مدرک این تصویر تستی مشخص نیست، پس نمی‌توان آن را وارد پرونده کرد.');
        }

        $service = $this->chooseService($request->integer('service_type_id'), (int) $type->id);

        if ($service === null) {
            return back()->with('error', 'هیچ خدمت فعالی «'.$type->label_fa.'» را جزو مدارک لازمش ندارد، '
                .'پس این تصویر جایی در فرایند بررسی ندارد. اول در بخش مدیریت، این مدرک را به یک خدمت اضافه کنید.');
        }

        $source = Storage::disk($testImage->disk);

        if (! $source->exists($testImage->path)) {
            return back()->with('error', 'فایل این تصویر تستی روی سرور پیدا نشد، پس پرونده‌ای ساخته نشد. '
                .'تصویر را دوباره بسازید و همین دکمه را بزنید.');
        }

        $case = PermitCase::openDraft(
            userId: $request->user()->id,
            serviceTypeId: (int) $service->id,
            applicantName: $this->applicantName($testImage),
            applicantNationalId: $this->applicantNationalId($testImage),
        );

        $path = 'cases/'.$case->id.'/'.$this->safeFileName($type->key, $testImage->path);

        try {
            Storage::disk(self::DISK)->put($path, $source->get($testImage->path));
        } catch (Throwable $failure) {
            $reference = Str::upper(Str::random(6));

            Log::error('کپی تصویر تستی روی دیسک مدارک انجام نشد.', [
                'reference' => $reference,
                'test_image_id' => $testImage->id,
                'case_id' => $case->id,
                'exception' => $failure,
            ]);

            $case->delete();

            return back()->with('error', 'نشد تصویر را در محل نگهداری مدارک بنویسیم، پس پرونده‌ای ساخته نشد. '
                .'ایراد از سمت سرور است (معمولاً مجوز پوشهٔ ذخیره‌سازی)، پس تکرارِ همین کار نتیجه نمی‌دهد. '
                .'این کد پیگیری را به مدیر سامانه بدهید: '.$reference.'.');
        }

        $document = CaseDocument::create([
            'case_id' => $case->id,
            'document_type_id' => $type->id,
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => 'تصویر تستی #'.PersianValue::toPersianDigits((string) $testImage->id),
            'width' => $testImage->width,
            'height' => $testImage->height,
            'precheck_status' => 'pending',
            'ocr_status' => 'pending',
        ]);

        $accepted = $precheck->inspect($document);

        return redirect()
            ->route('cases.documents.edit', $case)
            ->with(...$this->resultFlash($case, $service, $type->label_fa, $accepted));
    }

    // ==================================================================
    // انتخاب خدمت
    // ==================================================================

    /**
     * خدمتِ درخواست‌شده، فقط اگر واقعاً این نوع مدرک را لازم داشته باشد؛
     * وگرنه اولین خدمت فعالی که دارد. نتیجهٔ null یعنی هیچ خدمتی ندارد.
     */
    private function chooseService(int $requestedId, int $documentTypeId): ?ServiceType
    {
        $services = $this->servicesFor($documentTypeId);

        return $services->firstWhere('id', $requestedId) ?? $services->first();
    }

    /** @return Collection<int, ServiceType> */
    private function servicesFor(int $documentTypeId): Collection
    {
        return ServiceType::query()
            ->active()
            ->with('documentTypes')
            ->whereHas('documentTypes', fn ($query) => $query->where('document_types.id', $documentTypeId))
            ->orderBy('sort')
            ->get();
    }

    // ==================================================================
    // نام و کد ملی متقاضی
    // ==================================================================

    /**
     * نام متقاضی از دادهٔ چاپ‌شده روی همان تصویر برداشته می‌شود.
     *
     * چرا مهم است: مهم‌ترین بررسی ضدجعلِ سامانه (تسک ۶۳۳) «تطابق نام و کد ملی
     * بین مدارک» است. اگر پرونده با نامِ همین مدرک باز شود، آزمودن آن بررسی
     * یک قدم ساده می‌شود — مدرک دوم را با نامی دیگر بساز و ناهمخوانی را ببین.
     */
    private function applicantName(TestImage $image): ?string
    {
        $payload = is_array($image->payload) ? $image->payload : [];

        $full = $this->firstFilled($payload, ['full_name']);

        if ($full !== null) {
            return $full;
        }

        $first = $this->firstFilled($payload, ['first_name']);
        $last = $this->firstFilled($payload, ['last_name']);

        $name = trim(($first ?? '').' '.($last ?? ''));

        return $name !== '' ? $name : null;
    }

    private function applicantNationalId(TestImage $image): ?string
    {
        $payload = is_array($image->payload) ? $image->payload : [];

        $value = $this->firstFilled($payload, ['national_id']);

        if ($value === null) {
            return null;
        }

        return PersianValue::forEngine('national_id', $value) ?: null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function firstFilled(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return mb_substr(PersianValue::normalize($value), 0, 120);
            }
        }

        return null;
    }

    // ==================================================================
    // فایل و پیام
    // ==================================================================

    /** نام امنِ سمت سرور — هیچ تکه‌ای از ورودی کاربر در آن نیست. */
    private function safeFileName(?string $typeKey, string $sourcePath): string
    {
        $key = preg_replace('/[^a-z0-9_-]+/', '', Str::lower((string) $typeKey)) ?: 'document';

        $extension = Str::lower(pathinfo($sourcePath, PATHINFO_EXTENSION));

        if (! in_array($extension, self::EXTENSIONS, true)) {
            $extension = 'png';
        }

        return $key.'-'.Str::uuid()->toString().'.'.$extension;
    }

    /**
     * پیام «چه شد و حالا چه کن» — با نام دقیق مدارکِ باقی‌مانده.
     *
     * @return array{0: string, 1: string}
     */
    private function resultFlash(PermitCase $case, ServiceType $service, string $typeLabel, bool $accepted): array
    {
        $missing = $service->documentTypes
            ->filter(fn ($type) => (bool) ($type->pivot->is_required ?? true))
            ->reject(fn ($type) => $case->documents->contains('document_type_id', $type->id))
            ->pluck('label_fa')
            ->all();

        $head = 'پروندهٔ «'.$service->label_fa.'» با کد '.PersianValue::toPersianDigits($case->code)
            .' ساخته شد و تصویر تستی به‌عنوان «'.$typeLabel.'» رویش نشست. ';

        $tail = $missing === []
            ? 'همهٔ مدارک لازم آماده است؛ با دکمهٔ «ثبت پرونده» بفرستیدش به فرایند بررسی.'
            : 'برای ثبت، این مدارک مانده‌اند: '.implode('، ', $missing).'.';

        if (! $accepted) {
            return ['warning', $head.'ولی همین تصویر بررسی اولیهٔ فایل را رد کرد — '
                .'دلیلش را روی کارت همان مدرک می‌بینید. '
                .'اگر عمداً خرابش کرده‌اید یعنی بررسی اولیه درست کار کرده؛ وگرنه تصویر سالم‌تری بسازید. '.$tail];
        }

        return ['success', $head.$tail];
    }
}
