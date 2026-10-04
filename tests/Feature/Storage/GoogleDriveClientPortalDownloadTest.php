<?php

use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\DriveObject;
use App\Models\Matter;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use App\Support\Storage\DocumentStore;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Tests\Support\FakeGoogleDrive;

/*
|--------------------------------------------------------------------------
| M14 Task 2, vòng sửa 1 — khách tải từ cổng một tệp nằm trên kho Google Drive (R3, R11)
|--------------------------------------------------------------------------
|
| Route `documents.download` nằm ngoài mọi panel Filament. Với khách, guard `client` đăng nhập và
| guard `web` không, nên `ClientPortalScope` KÍCH HOẠT trong suốt request — kể cả trong adapter của
| đĩa kho. Chỉ mục `drive_objects` mang scope đó (`1 = 0`, Task 1): nếu adapter hỏi chỉ mục qua
| scope thì `exists()` trả false và controller trả 404 cho MỌI lượt tải của khách trên tệp đã đẩy,
| trong khi nhân sự vẫn tải được. Adapter thật trên Drive giả (`FakeGoogleDrive`), không gọi Google.
|
| Task 3 mới đổi `media.disk` sang `documents_remote`; ở đây đĩa của media được đặt tường minh.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->drive = FakeGoogleDrive::install();
    $this->drive->disk();

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->clientUser = ClientUser::factory()->create(['client_id' => Client::factory()->create()->id]);
    $matter = Matter::factory()->create(['client_id' => $this->clientUser->client_id, 'lead_lawyer_id' => $this->lawyer->id]);

    $this->document = Document::factory()->create([
        'matter_id' => $matter->id,
        'group' => DocumentGroup::ClientProvided,
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
        'title' => 'Giấy chứng nhận quyền sử dụng đất',
    ]);

    $this->document->addMedia(UploadedFile::fake()->createWithContent('nguon.pdf', '%PDF-1.4 noi dung that'))
        ->usingFileName('01k5g7q8wz0000000000000000.pdf')
        ->toMediaCollection('file', DocumentStore::REMOTE_DISK);

    // Tự kiểm: tệp thật sự nằm trên kho (dòng chỉ mục sống), không phải trên đĩa cục bộ.
    $media = $this->document->refresh()->getFirstMedia('file');

    expect($media->disk)->toBe(DocumentStore::REMOTE_DISK)
        ->and(DriveObject::query()->where('object_key', $media->getPathRelativeToRoot())->exists())->toBeTrue();
});

it('khách tải được tài liệu đã công bố của chính mình khi tệp nằm trên kho Google Drive', function () {
    $response = $this->actingAs($this->clientUser, 'client')
        ->get($this->document->downloadUrlFor($this->clientUser));

    expect(ClientPortalScope::isActive())->toBeTrue();

    $response->assertOk();
    expect($response->streamedContent())->toBe('%PDF-1.4 noi dung that')
        ->and(DocumentDownload::query()->withoutGlobalScope(ClientPortalScope::class)->count())->toBe(1);
});

it('cặp sinh đôi — nhân sự của vụ tải được cùng tệp đó trên kho Google Drive', function () {
    $response = $this->actingAs($this->lawyer, 'web')
        ->get($this->document->downloadUrlFor($this->lawyer));

    $response->assertOk();
    expect($response->streamedContent())->toBe('%PDF-1.4 noi dung that');
});
