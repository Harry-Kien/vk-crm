<?php

use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Document;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * M15 Task 1 — phản hồi có phiên của panel admin không được nằm lại trong bộ nhớ đệm trình duyệt
 * hay proxy: `Cache-Control` phải chứa `no-store` (trang đăng nhập cũng vậy, vì nó mang token CSRF
 * và có thể trả về trang đã đăng nhập). Tải tệp đã có `private, no-store` từ trước; ở đây khoá lại.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('private');
    config(['media-library.prefix' => 'test-'.Str::random(16)]);
});

it('trang đăng nhập admin mang no-store', function () {
    $response = $this->get('/admin/login')->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('trang panel admin của nhân sự đã đăng nhập mang no-store', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();

    foreach (['/admin', '/admin/matters'] as $path) {
        $response = $this->actingAs($admin, 'web')->get($path);

        expect($response->getStatusCode())->toBeLessThan(400, "{$path} không mở được")
            ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
    }
});

it('tải tài liệu mang no-store', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->for(Client::factory()->create())->create(['lead_lawyer_id' => $lawyer->id]);
    $document = Document::factory()->for($matter)->create([
        'group' => DocumentGroup::ClientProvided->value,
        'status' => DocumentStatus::Published->value,
    ]);
    $document->addMedia(UploadedFile::fake()->createWithContent('a.pdf', '%PDF-1.4 test'))->toMediaCollection('file');

    $response = $this->actingAs($lawyer, 'web')->get($document->downloadUrlFor($lawyer))->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});
