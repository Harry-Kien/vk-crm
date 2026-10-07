<?php

use App\Actions\Document\UploadStaffDocument;
use App\Enums\DocumentGroup;
use App\Enums\Role;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;

/**
 * M7 Task 4, vòng sửa 1 (rà soát m7): `FileGuard` cho tệp tới UPLOAD_MAX_MB (20 MB) đi qua, nhưng
 * trần 10 MB mặc định của medialibrary làm mọi tệp 10–20 MB hỏng SAU cổng, bằng một
 * `FileIsTooBig` không ai dịch. Cả `UploadStaffDocument` lẫn `SubmitClientDocument` lưu tệp qua
 * cùng một chỗ (`StoresDocumentFile::storeFile()`), nên một đường là đủ để ghim. Cấu hình THẬT:
 * test không đổi `media-library.max_file_size`.
 */
it('tệp 15 MB — dưới trần tải lên — được lưu qua UploadStaffDocument với cấu hình thật', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $file = UploadedFile::fake()->createWithContent('ban-scan-ho-so.pdf', "%PDF-1.4\n".random_bytes(15 * 1024 * 1024));

    expect($file->getSize())->toBeLessThan((int) config('vkcrm.upload_max_mb') * 1024 * 1024);

    $document = app(UploadStaffDocument::class)->handle(
        matter: $matter,
        actor: $lawyer,
        file: $file,
        group: DocumentGroup::Issued,
        title: 'Bản scan hồ sơ',
    );

    expect($document->getFirstMedia('file')->size)->toBe($file->getSize());
});
