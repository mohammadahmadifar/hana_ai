<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\BuildsCases;
use Tests\TestCase;

/**
 * تسک ۷۴۰ — ساخت و ویرایش کاربر، حالا با کد ملی.
 *
 * کد ملی نام کاربری ورود است، پس فرم مدیر تنها جایی است که این نام ساخته
 * می‌شود؛ هر سوراخی این‌جا یعنی حسابی که یا وارد نمی‌شود یا نام کاربری‌اش با
 * حساب دیگری یکی است.
 */
class UserManagementTest extends TestCase
{
    use BuildsCases;
    use RefreshDatabase;

    private const NEW_NID = '0022222227';

    public function test_admin_creates_a_user_with_a_national_id_and_that_user_can_log_in(): void
    {
        $this->actingAs($this->adminUser())
            ->post(route('admin.users.store'), $this->payload())
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success');

        $created = User::query()->where('email', 'tester@hana.local')->firstOrFail();
        $this->assertSame(self::NEW_NID, $created->national_id);

        // تعریف done: همان کاربر با همان کد ملی وارد می‌شود.
        Auth::logout();

        $this->post('/login', ['national_id' => self::NEW_NID, 'password' => 'hana-test-1405'])
            ->assertRedirect(route('dashboard'));

        $this->assertSame($created->id, Auth::id());
    }

    /** ارقام فارسیِ فرم مدیر هم به لاتین ذخیره می‌شوند، وگرنه ورود کار نمی‌کند. */
    public function test_persian_digits_are_stored_as_latin(): void
    {
        $this->actingAs($this->adminUser())
            ->post(route('admin.users.store'), $this->payload(['national_id' => '۰۰۲۲۲۲۲۲۲۷']))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            self::NEW_NID,
            User::query()->where('email', 'tester@hana.local')->value('national_id'),
        );
    }

    public function test_national_id_is_required(): void
    {
        $this->actingAs($this->adminUser())
            ->from(route('admin.users.create'))
            ->post(route('admin.users.store'), $this->payload(['national_id' => '']))
            ->assertSessionHasErrors('national_id');

        $this->assertSame(0, User::query()->where('email', 'tester@hana.local')->count());
    }

    /** رقم کنترل غلط رد می‌شود — همان قاعده‌ای که اعتبارسنجی مدارک اجرا می‌کند. */
    public function test_national_id_checksum_is_enforced(): void
    {
        $this->actingAs($this->adminUser())
            ->from(route('admin.users.create'))
            ->post(route('admin.users.store'), $this->payload(['national_id' => '1234567890']))
            ->assertSessionHasErrors('national_id');

        $this->assertSame(0, User::query()->where('email', 'tester@hana.local')->count());
    }

    public function test_national_id_must_be_ten_digits(): void
    {
        $this->actingAs($this->adminUser())
            ->from(route('admin.users.create'))
            ->post(route('admin.users.store'), $this->payload(['national_id' => '12345']))
            ->assertSessionHasErrors('national_id');
    }

    /** دو کاربر با یک کد ملی یعنی دو حساب با یک نام کاربری. */
    public function test_national_id_is_unique(): void
    {
        $admin = $this->adminUser();
        $taken = $this->userWithRole('expert');
        $taken->forceFill(['national_id' => self::NEW_NID])->save();

        $this->actingAs($admin)
            ->from(route('admin.users.create'))
            ->post(route('admin.users.store'), $this->payload())
            ->assertSessionHasErrors('national_id');
    }

    /** ویرایش، کد ملیِ خودِ همان کاربر را «تکراری» نمی‌بیند. */
    public function test_editing_a_user_without_changing_the_national_id_is_allowed(): void
    {
        $admin = $this->adminUser();
        $target = $this->userWithRole('expert');

        $this->actingAs($admin)
            ->put(route('admin.users.update', $target), [
                'name' => 'نام تازه',
                'email' => $target->email,
                'national_id' => $target->national_id,
                'role' => 'expert',
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('نام تازه', $target->fresh()->name);
    }

    /** تغییر کد ملی یعنی تغییر نام کاربری؛ ورود بعدی با مقدار تازه است. */
    public function test_changing_the_national_id_changes_the_login_name(): void
    {
        $admin = $this->adminUser();
        $target = $this->userWithRole('expert');
        $target->forceFill(['password' => bcrypt('hana-test-1405')])->save();

        $old = (string) $target->national_id;

        $this->actingAs($admin)
            ->put(route('admin.users.update', $target), [
                'name' => $target->name,
                'email' => $target->email,
                'national_id' => self::NEW_NID,
                'role' => 'expert',
                'is_active' => '1',
            ])
            ->assertSessionHasNoErrors();

        Auth::logout();

        $this->from(route('login'))
            ->post('/login', ['national_id' => $old, 'password' => 'hana-test-1405'])
            ->assertSessionHasErrors('auth');

        $this->post('/login', ['national_id' => self::NEW_NID, 'password' => 'hana-test-1405'])
            ->assertRedirect(route('dashboard'));
    }

    /** فهرست کاربران، کد ملی را هم نشان می‌دهد و هم جست‌وجو می‌کند. */
    public function test_user_list_shows_and_searches_national_id(): void
    {
        $admin = $this->adminUser();
        $target = $this->userWithRole('expert');
        $target->forceFill(['national_id' => self::NEW_NID, 'name' => 'کارشناس یکتا'])->save();

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('۰۰۲۲۲۲۲۲۲۷');

        // جست‌وجو با ارقام فارسی هم باید همان ردیف را بیاورد — و فقط همان را.
        // (نام مدیر در پانوشت سایدبار همیشه هست، پس ملاکِ «فیلتر شد» ایمیل
        // ردیف‌های جدول است نه نام کاربر جاری.)
        $this->actingAs($admin)
            ->get(route('admin.users.index', ['q' => '۰۰۲۲۲۲۲۲۲۷']))
            ->assertOk()
            ->assertSee('کارشناس یکتا')
            ->assertDontSee($admin->email);
    }

    /** ورودی آرایه‌ای فرم مدیر هم ۵۰۰ نمی‌دهد. */
    public function test_array_input_is_a_validation_error_not_a_500(): void
    {
        $this->actingAs($this->adminUser())
            ->from(route('admin.users.create'))
            ->post(route('admin.users.store'), $this->payload(['national_id' => ['1']]))
            ->assertRedirect(route('admin.users.create'))
            ->assertSessionHasErrors('national_id');

        $this->assertSame(0, User::query()->where('email', 'tester@hana.local')->count());
    }

    /**
     * مدیر نمی‌تواند کد ملی خودش را عوض کند.
     *
     * تنها فیلدی است که اشتباه نوشتنش او را از سامانه‌ای بیرون می‌گذارد که نه
     * ثبت‌نام دارد نه بازیابی رمز — همان منطقِ «نقش خودت را عوض نکن».
     */
    public function test_admin_cannot_change_their_own_national_id(): void
    {
        $admin = $this->adminUser();
        $own = (string) $admin->national_id;

        $this->actingAs($admin)
            ->put(route('admin.users.update', $admin), [
                'name' => $admin->name,
                'email' => $admin->email,
                'national_id' => self::NEW_NID,
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame($own, $admin->fresh()->national_id);

        // و فرم هم اصلاً ورودیِ قابل ارسال نشانش نمی‌دهد.
        $this->actingAs($admin)
            ->get(route('admin.users.edit', $admin))
            ->assertOk()
            ->assertSee('کد ملی حساب خودتان قابل تغییر نیست')
            ->assertDontSee('name="national_id"', false);
    }

    /**
     * پروندهٔ نمایشی هم صاحبِ قابل‌ورود می‌سازد.
     *
     * DemoCasesSeeder روی نصبی که هنوز کارشناس ندارد یک حساب می‌سازد؛ بدون کد
     * ملی، آن حساب هرگز نمی‌تواند وارد شود و چون ستون nullable است هیچ‌جا هم
     * صدا نمی‌دهد.
     */
    public function test_demo_seeder_owner_gets_a_usable_login_name(): void
    {
        $this->seedReferenceData();

        $this->assertSame(0, User::query()->count());

        $this->seed(\Database\Seeders\DemoCasesSeeder::class);

        $this->assertSame(
            0,
            User::query()->whereNull('national_id')->count(),
            'هیچ حسابی نباید بدون کد ملی ساخته شود؛ چنین حسابی هرگز وارد نمی‌شود.',
        );
    }

    /** @return array<string, string> */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'کاربر آزمایشی',
            'email' => 'tester@hana.local',
            'national_id' => self::NEW_NID,
            'role' => 'expert',
            'is_active' => '1',
            'password' => 'hana-test-1405',
            'password_confirmation' => 'hana-test-1405',
        ], $overrides);
    }
}
