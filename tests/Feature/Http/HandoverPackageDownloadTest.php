<?php

use App\Actions\Document\PublishDocument;
use App\Actions\Document\RetractDocument;
use App\Actions\Matter\BuildHandoverPackage;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\HandoverPackageStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\RemoteDocuments;

/**
 * Tải gói bàn giao (M7 Task 4, SPEC §10.6 "xuất dữ liệu"): đi qua đúng route ký `documents.download`
 * và ghi `data_exported` THÊM vào `document_downloaded`, chỉ cho tài liệu gói.
 *
 * M14 Task 4 (kế hoạch R3, R12): mọi test chạy hai lần, `local` (tệp trong vùng đệm) và `remote` (tệp
 * gói và tài liệu CHỈ còn trên kho — `RemoteDocuments::settle()` đẩy bằng Action thật của Task 3 rồi dọn
 * vùng đệm).
 */
beforeEach(function () {
    RemoteDocuments::adopt($this);
    $this->seed(RolesAndPermissionsSeeder::class);
    Queue::fake();
    config(['media-library.prefix' => RemoteDocuments::mediaPrefix('test-'.Str::random(16))]);

    // Thư mục tạm RIÊNG của mỗi test (tên thư mục làm việc cố định theo vụ + dấu yêu cầu, và các
    // tiến trình test song song đánh id vụ từ 1).
    $this->workRoot = storage_path('framework/testing/handover-'.Str::random(16));
    config(['vkcrm.handover.work_dir' => $this->workRoot]);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->create(['client_id' => $this->client->id]);
    $this->matter = Matter::factory()->for($this->client)->create([
        'lead_lawyer_id' => $this->lawyer->id,
        'closed_at' => now()->subDay()->toDateString(),
    ]);
    $this->archive = MatterArchive::factory()->create([
        'matter_id' => $this->matter->id,
        'handover_status' => HandoverPackageStatus::Generating,
        'handover_requested_at' => now()->startOfSecond(),
        'handover_requested_by' => $this->lawyer->id,
    ]);

    $this->package = app(BuildHandoverPackage::class)->handle(
        $this->matter->id,
        $this->archive->fresh()->handover_requested_at->getTimestamp(),
    );

    $this->package = RemoteDocuments::settle($this->package);
})->with(RemoteDocuments::MODES);

afterEach(fn () => File::deleteDirectory($this->workRoot));

function hpdPublish(object $test): void
{
    app(PublishDocument::class)->handle(
        $test->package->refresh(), $test->lawyer,
        clientCanView: true, clientCanDownload: true,
        expectedClientCanView: false, expectedClientCanDownload: false, expectedIsReleased: false,
    );
}

it('luật sư tải gói: ghi document_downloaded VÀ data_exported (action downloaded)', function () {
    $response = $this->actingAs($this->lawyer, 'web')->get($this->package->downloadUrlFor($this->lawyer));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('goi-ban-giao-'.$this->matter->code.'.zip');

    expect(Activity::query()->where('event', 'document_downloaded')->count())->toBe(1);

    $export = Activity::query()->where('event', 'data_exported')->where('properties->action', 'downloaded')->sole();

    expect($export->subject_id)->toBe($this->package->id)
        ->and($export->causer_id)->toBe($this->lawyer->id)
        ->and($export->properties['matter_id'])->toBe($this->matter->id)
        ->and($export->properties['kind'])->toBe('handover_package')
        ->and($export->properties['version'])->toBe(1)
        ->and(DocumentDownload::query()->where('document_id', $this->package->id)->count())->toBe(1);
});

it('khách tải gói sau khi được công bố: data_exported ghi đúng khách', function () {
    // Chưa công bố: khách không tải được (404 như mọi tài liệu chưa công bố).
    $this->actingAs($this->clientUser, 'client')
        ->get($this->package->downloadUrlFor($this->clientUser))
        ->assertNotFound();

    expect(Activity::query()->where('event', 'data_exported')->where('properties->action', 'downloaded')->count())->toBe(0);

    hpdPublish($this);

    $this->actingAs($this->clientUser, 'client')
        ->get($this->package->downloadUrlFor($this->clientUser))
        ->assertOk();

    $export = Activity::query()->where('event', 'data_exported')->where('properties->action', 'downloaded')->sole();

    expect($export->causer_id)->toBe($this->clientUser->id)
        ->and($export->causer_type)->toBe($this->clientUser->getMorphClass())
        ->and($export->properties['client_id'])->toBe($this->client->id);
});

it('tải MỘT VERSION CŨ của gói cũng ghi data_exported (nhận biết qua chuỗi version)', function () {
    hpdPublish($this);

    // Gói đang công bố thì không sinh lại được (rà soát cuối M7, I2): rút nó trước, qua đường thật.
    // Version 1 đã rút giữ dòng tài liệu VÀ tệp — tải lại được, bên dưới.
    app(RetractDocument::class)->handle($this->package->refresh(), $this->lawyer, 'Gói này thiếu bản án phúc thẩm, văn phòng sẽ gửi gói mới.');

    // Sinh lại: version 2.
    MatterArchive::query()->whereKey($this->archive->id)->update([
        'handover_status' => HandoverPackageStatus::Generating->value,
        'handover_requested_at' => now()->addSeconds(5)->startOfSecond(),
    ]);
    $second = app(BuildHandoverPackage::class)->handle(
        $this->matter->id,
        $this->archive->fresh()->handover_requested_at->getTimestamp(),
    );
    $second = RemoteDocuments::settle($second);

    expect(MatterArchive::isHandoverDocument($this->package->refresh()))->toBeTrue()
        ->and(MatterArchive::isHandoverDocument($second))->toBeTrue();

    $this->actingAs($this->lawyer, 'web')->get($second->downloadUrlFor($this->lawyer))->assertOk();
    $this->actingAs($this->lawyer, 'web')->get($this->package->downloadUrlFor($this->lawyer))->assertOk();

    expect(Activity::query()->where('event', 'data_exported')->where('properties->action', 'downloaded')->orderBy('id')->pluck('properties')->map(fn ($properties) => $properties['version'])->all())
        ->toBe([2, 1]);
});

it('tải tài liệu bình thường KHÔNG ghi data_exported', function () {
    $plain = Document::factory()->create([
        'matter_id' => $this->matter->id, 'group' => DocumentGroup::Issued, 'status' => DocumentStatus::SignedFiled,
    ]);
    $plain->addMediaFromString('noi dung')->usingFileName(RemoteDocuments::remote() ? Str::lower((string) Str::ulid()).'.pdf' : 'a.pdf')->toMediaCollection('file');
    $plain = RemoteDocuments::settle($plain);

    $this->actingAs($this->lawyer, 'web')->get($plain->downloadUrlFor($this->lawyer))->assertOk();

    expect(Activity::query()->where('event', 'document_downloaded')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'data_exported')->where('properties->action', 'downloaded')->count())->toBe(0)
        ->and(MatterArchive::isHandoverDocument($plain))->toBeFalse();
});

it('gói của vụ khác không làm tài liệu của vụ này thành "gói"', function () {
    $other = Matter::factory()->create(['closed_at' => now()->toDateString()]);
    $document = Document::factory()->create(['matter_id' => $other->id]);

    expect(MatterArchive::isHandoverDocument($document))->toBeFalse();
});
