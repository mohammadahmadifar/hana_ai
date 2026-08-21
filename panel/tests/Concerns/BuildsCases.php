<?php

namespace Tests\Concerns;

use App\Models\CaseDocument;
use App\Models\DocumentType;
use App\Models\PermitCase;
use App\Models\ServiceType;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * ابزار مشترک تست‌های «فرایند مجوز».
 *
 * چرا این‌جاست: چند تست مستقل (اعتبارسنجی فایل، OCR، استخراج فیلد، اعتبارسنجی
 * اسناد، امتیازدهی، صفحه نتیجه) همگی به «یک پروندهٔ کامل با سه مدرک» و به
 * «یک تصویر واقعیِ روی دیسک» نیاز دارند. اگر هر تست خودش بسازد، شش نسخهٔ
 * ناهماهنگ می‌شود.
 *
 * قانون پروژه: هیچ مدرک هویتی واقعی در تست نیست — تصویرها با GD ساخته
 * می‌شوند و محتوایشان نویز/رنگ ساده است، نه اسکن مدرک.
 */
trait BuildsCases
{
    /** دادهٔ مرجع (انواع مدرک، انواع خدمت، تنظیمات امتیازدهی) را می‌آورد. */
    protected function seedReferenceData(): void
    {
        $this->seed(ReferenceDataSeeder::class);
    }

    /** دیسک‌های خصوصی را جعلی می‌کند تا تست چیزی روی storage واقعی ننویسد. */
    protected function fakeDisks(): void
    {
        Storage::fake('documents');
        Storage::fake('dataset');
        Storage::fake('testimages');
        Storage::fake('thumbs');
    }

    protected function expertUser(): User
    {
        return $this->userWithRole('expert');
    }

    protected function adminUser(): User
    {
        return $this->userWithRole('admin');
    }

    protected function dataUser(): User
    {
        return $this->userWithRole('data');
    }

    /**
     * کاربر آزمایشی با نقش مشخص.
     *
     * دو نکته که تست را بی‌سروصدا می‌شکنند و این‌جا یک‌بار حل شده‌اند:
     *   ۱) مدل User با #[Fillable(['name','email','password'])] بسته است، پس
     *      User::factory()->create(['role' => 'admin']) نقش را ساکت دور می‌ریزد.
     *   ۲) is_active پیش‌فرضِ دیتابیس دارد، ولی نمونهٔ درون‌حافظه‌ای آن را ندارد؛
     *      actingAs با همان نمونه کار می‌کند و میان‌افزار سراسری EnsureRole
     *      کاربر را «غیرفعال» می‌بیند و بیرون می‌اندازد.
     * پس نقش و فعال‌بودن صریح نوشته و مدل تازه‌سازی می‌شود.
     *
     * @param  'admin'|'expert'|'data'  $role
     */
    protected function userWithRole(string $role): User
    {
        $user = User::factory()->create();

        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user->refresh();
    }

    /**
     * پرونده‌ای با همهٔ مدارک لازمِ همان خدمت.
     *
     * @param  'issue'|'renew'  $serviceKey
     */
    protected function makeCaseWithDocuments(string $serviceKey = 'issue', ?User $user = null): PermitCase
    {
        $service = ServiceType::query()->where('key', $serviceKey)->firstOrFail();

        $case = PermitCase::factory()
            ->for($user ?? $this->expertUser(), 'user')
            ->create(['service_type_id' => $service->id]);

        foreach ($service->documentTypes as $type) {
            CaseDocument::factory()->prechecked()->create([
                'case_id' => $case->id,
                'document_type_id' => $type->id,
            ]);
        }

        return $case->fresh(['documents']);
    }

    /** شناسهٔ یک نوع مدرک بر پایهٔ کلیدش. */
    protected function documentTypeId(string $key): int
    {
        return (int) DocumentType::query()->where('key', $key)->value('id');
    }

    /**
     * یک PNG مصنوعی روی دیسک موقت و برگرداندن مسیر مطلقش.
     *
     * @param  'sharp'|'blurry'|'dark'|'bright'  $look
     */
    protected function makeImageFile(
        int $width = 960,
        int $height = 540,
        string $look = 'sharp',
        string $extension = 'png',
    ): string {
        $image = imagecreatetruecolor($width, $height);

        $background = match ($look) {
            'dark' => imagecolorallocate($image, 12, 12, 14),
            'bright' => imagecolorallocate($image, 252, 252, 252),
            default => imagecolorallocate($image, 236, 238, 240),
        };

        imagefill($image, 0, 0, $background);

        // خطوط پرکنتراست = واریانس لاپلاسین بالا (تصویر «واضح»).
        // برای حالت «تار» همان خطوط با فیلتر محو کشیده می‌شوند.
        $ink = match ($look) {
            'dark' => imagecolorallocate($image, 70, 70, 76),
            'bright' => imagecolorallocate($image, 214, 214, 214),
            default => imagecolorallocate($image, 18, 18, 22),
        };

        for ($y = 40; $y < $height - 40; $y += 26) {
            imagefilledrectangle($image, 60, $y, $width - 60, $y + 9, $ink);
        }

        if ($look === 'blurry') {
            for ($i = 0; $i < 24; $i++) {
                imagefilter($image, IMG_FILTER_GAUSSIAN_BLUR);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'hana_test_').'.'.$extension;

        match ($extension) {
            'jpg', 'jpeg' => imagejpeg($image, $path, 90),
            'webp' => imagewebp($image, $path),
            default => imagepng($image, $path),
        };

        imagedestroy($image);

        return $path;
    }

    /** همان تصویر، ولی به شکل فایل آپلودی برای تست کنترلر. */
    protected function uploadedImage(
        string $name = 'card.png',
        int $width = 960,
        int $height = 540,
        string $look = 'sharp',
    ): UploadedFile {
        $extension = pathinfo($name, PATHINFO_EXTENSION) ?: 'png';

        return new UploadedFile(
            $this->makeImageFile($width, $height, $look, $extension),
            $name,
            null,
            null,
            true,
        );
    }

    /** فایلی که تصویر نیست — برای تست «فرمت نامعتبر». */
    protected function uploadedFakePdf(string $name = 'scan.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'hana_test_').'.pdf';
        file_put_contents($path, "%PDF-1.4\n".str_repeat("0123456789\n", 4000)."%%EOF\n");

        return new UploadedFile($path, $name, null, null, true);
    }

    /** فایل خیلی کوچک — برای تست «حجم کمتر از حد مجاز». */
    protected function uploadedTinyFile(string $name = 'tiny.png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'hana_test_').'.png';

        $image = imagecreatetruecolor(40, 24);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagepng($image, $path, 9);
        imagedestroy($image);

        return new UploadedFile($path, $name, null, null, true);
    }
}
