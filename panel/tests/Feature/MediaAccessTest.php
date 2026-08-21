<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCases;
use Tests\TestCase;

/**
 * دسترسی به فایل‌های دیسک‌های خصوصی — یافتهٔ بازبینی تسک ۶۲۹.
 *
 * پیش از این فقط «لاگین بودن» بررسی می‌شد، پس «کارشناس داده» که کل بخش
 * درخواست خدمت را در منو نمی‌بیند، با داشتن نشانی می‌توانست اسکن کارت ملی
 * متقاضی‌ها را باز کند.
 */
class MediaAccessTest extends TestCase
{
    use BuildsCases;
    use RefreshDatabase;

    private function putDocument(): string
    {
        Storage::fake('documents');
        Storage::disk('documents')->put('cases/1/national_card-abc.png', 'fake-bytes');

        return 'cases/1/national_card-abc.png';
    }

    public function test_reviewer_roles_can_open_a_case_document(): void
    {
        $path = $this->putDocument();

        foreach ([$this->adminUser(), $this->expertUser()] as $user) {
            $this->actingAs($user)
                ->get(route('media', ['disk' => 'documents', 'path' => $path]))
                ->assertOk();
        }
    }

    public function test_dataset_role_cannot_open_a_case_document(): void
    {
        $path = $this->putDocument();

        $this->actingAs($this->dataUser())
            ->get(route('media', ['disk' => 'documents', 'path' => $path]))
            ->assertForbidden();
    }

    public function test_reviewer_cannot_open_a_dataset_sample(): void
    {
        Storage::fake('dataset');
        Storage::disk('dataset')->put('samples/1.png', 'fake-bytes');

        $this->actingAs($this->expertUser())
            ->get(route('media', ['disk' => 'dataset', 'path' => 'samples/1.png']))
            ->assertForbidden();

        $this->actingAs($this->dataUser())
            ->get(route('media', ['disk' => 'dataset', 'path' => 'samples/1.png']))
            ->assertOk();
    }

    public function test_guest_is_sent_to_login(): void
    {
        $path = $this->putDocument();

        $this->get(route('media', ['disk' => 'documents', 'path' => $path]))
            ->assertRedirect(route('login'));
    }

    public function test_path_traversal_and_unknown_disks_are_refused(): void
    {
        $this->putDocument();

        $this->actingAs($this->adminUser())
            ->get('/media/exports/anything.zip')
            ->assertNotFound();

        $this->actingAs($this->adminUser())
            ->get('/media/documents/'.'..%2F..%2F.env')
            ->assertNotFound();
    }
}
