<?php

use App\Actions\Storage\PushDocumentFileToRemote;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\DriveObjectRetirement;
use App\Enums\PushOutcome;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Models\Document;
use App\Models\DriveObject;
use App\Models\Matter;
use App\Models\User;
use App\Support\Storage\DocumentStore;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Support\FakeGoogleDrive;
use Tests\Support\StagingFixtures;
use Tests\Support\StorageCommandFixtures as Ops;

/*
|--------------------------------------------------------------------------
| M14 Task 6 — `vkcrm:storage:migrate`: chuyển tệp cũ lên kho (R11)
|--------------------------------------------------------------------------
|
| Lệnh gọi ĐÚNG `PushDocumentFileToRemote` cho từng media còn ở vùng đệm, từ id nhỏ tới lớn — không
| bản thứ hai của luật kiểm checksum. Chạy thử (`--dry-run`) không ghi gì; chạy thật từ chối khi kho
| chưa bật hay kiểm tra sẵn sàng có ĐỎ. Mã thoát: 0 xong (kể cả dừng vì `--limit`/`--max-minutes`); 1
| có tệp lỗi; 2 điều kiện tiên quyết không đạt.
|
| Kiểm tra sẵn sàng chạy THẬT trên máy chủ Drive giả; tệp được đẩy lên đĩa kho GIẢ (trừ các ca dùng
| adapter thật, nơi cần đo thùng rác và thế hệ).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 20:00:00', 'Asia/Ho_Chi_Minh'));

    $this->media = [
        StagingFixtures::media('%PDF-1.4 tep cu mot'),
        StagingFixtures::media('%PDF-1.4 tep cu hai, dai hon'),
        StagingFixtures::media('%PDF-1.4 tep cu ba'),
    ];
});

function t6Migrate(array $options = []): array
{
    return Ops::artisan('vkcrm:storage:migrate', $options);
}

/** Kho đã bật (công tắc + mốc) và sẵn sàng XANH. */
function t6EnableAndReady(): FakeGoogleDrive
{
    $drive = Ops::ready();
    StagingFixtures::enableRemote(now()->subMinute());

    return $drive;
}

// ---------------------------------------------------------------------------------------------
// Điều kiện tiên quyết
// ---------------------------------------------------------------------------------------------

it('kho chưa bật (công tắc local, hay google_drive mà chưa có mốc) → mã 2, không media nào đổi', function (bool $switch) {
    Ops::ready();

    if ($switch) {
        config(['vkcrm.storage.driver' => DocumentStore::DRIVER_GOOGLE_DRIVE]);
    }

    [$exit, $output] = t6Migrate();

    expect($exit)->toBe(2)
        ->and($output)->toContain('vkcrm:storage:enable')
        ->and(Media::query()->where('disk', DocumentStore::STAGING_DISK)->count())->toBe(3)
        ->and(Ops::audits('document_store_migration_run'))->toBe([]);
    Http::assertNothingSent();
})->with(['local' => false, 'google_drive chưa enable' => true]);

it('StorageReadiness chưa xanh → mã 2, không ghi gì', function () {
    $drive = t6EnableAndReady();
    $drive->drive['restrictions']['driveMembersOnly'] = false;

    [$exit, $output] = t6Migrate();

    expect($exit)->toBe(2)
        ->and($output)->toContain('drive_sharing:')
        ->and(Media::query()->where('disk', DocumentStore::STAGING_DISK)->count())->toBe(3)
        ->and(Ops::remoteFiles())->toBe([])
        ->and(Ops::audits('document_store_migration_run'))->toBe([]);
});

// ---------------------------------------------------------------------------------------------
// Chạy thử
// ---------------------------------------------------------------------------------------------

it('--dry-run: không media nào đổi, đĩa kho rỗng, không dòng chỉ mục, không audit; in số tệp, byte, ước thời gian, chỗ trống, hạn mức', function () {
    $drive = Ops::ready();
    $bytes = collect($this->media)->sum('size');

    [$exit, $output] = t6Migrate(['--dry-run' => true]);

    expect($exit)->toBe(0, $output)
        ->and(Media::query()->where('disk', DocumentStore::STAGING_DISK)->count())->toBe(3)
        ->and(Ops::remoteFiles())->toBe([])
        ->and(DriveObject::query()->count())->toBe(0)
        ->and(Ops::audits('document_store_migration_run'))->toBe([])
        ->and($output)->toContain('3 tệp')
        ->and($output)->toContain(number_format($bytes, 0, ',', '.').' byte')
        ->and($output)->toContain('750 GB')
        ->and($output)->toContain('50 GB')
        ->and($output)->toContain('Ước tính')
        ->and($output)->toContain(__('storage.commands.migrate.dry_run_probe_trashed'));

    // Tệp thăm dò tốc độ 1 MiB đã lên Drive giả rồi vào thùng rác, không để lại gì sống.
    $probes = array_filter($drive->files, fn (array $file) => str_starts_with($file['name'], 'preflight~'));
    expect($probes)->toHaveCount(1)
        ->and(array_values($probes)[0]['trashed'])->toBeTrue()
        ->and(strlen(array_values($probes)[0]['content']))->toBe(1024 * 1024);
});

it('--dry-run khi kho chưa cấu hình: vẫn in số tệp, nói không đo được tốc độ, không request nào', function () {
    Http::preventStrayRequests();
    Http::fake();

    [$exit, $output] = t6Migrate(['--dry-run' => true]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('3 tệp')
        ->and($output)->toContain('không đo được')
        ->and($output)->not->toContain('thùng rác');
    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------------------------
// Chạy thật
// ---------------------------------------------------------------------------------------------

it('chuyển theo thứ tự id; --limit dừng đúng chỗ; chạy lần hai chỉ làm phần còn lại', function () {
    t6EnableAndReady();
    $remote = StagingFixtures::hookRemote();

    [$exit, $output] = t6Migrate(['--limit' => 2]);

    expect($exit)->toBe(0, $output)
        ->and(Ops::disk($this->media[0]->id))->toBe(DocumentStore::REMOTE_DISK)
        ->and(Ops::disk($this->media[1]->id))->toBe(DocumentStore::REMOTE_DISK)
        ->and(Ops::disk($this->media[2]->id))->toBe(DocumentStore::STAGING_DISK)
        ->and(array_count_values($remote->calls)['writeStream'])->toBe(2);

    $remote->calls = [];
    [$exit] = t6Migrate();

    expect($exit)->toBe(0)
        ->and(Ops::disk($this->media[2]->id))->toBe(DocumentStore::REMOTE_DISK)
        ->and(array_count_values($remote->calls)['writeStream'])->toBe(1);
});

it('--max-minutes dừng đúng chỗ theo đồng hồ (giả)', function () {
    t6EnableAndReady();

    // Mỗi lượt đẩy "tốn" 3 phút. Hạn 5 phút: media 1 (phút 0), media 2 (phút 3), dừng trước media 3 (phút 6).
    app()->bind(PushDocumentFileToRemote::class, fn () => new class extends PushDocumentFileToRemote
    {
        public function handle(int $mediaId, ?CarbonInterface $keepLocalUntil = null): PushOutcome
        {
            $outcome = parent::handle($mediaId, $keepLocalUntil);
            test()->travel(3)->minutes();

            return $outcome;
        }
    });

    [$exit, $output] = t6Migrate(['--max-minutes' => 5]);

    expect($exit)->toBe(0, $output)
        ->and(Ops::disk($this->media[0]->id))->toBe(DocumentStore::REMOTE_DISK)
        ->and(Ops::disk($this->media[1]->id))->toBe(DocumentStore::REMOTE_DISK)
        ->and(Ops::disk($this->media[2]->id))->toBe(DocumentStore::STAGING_DISK)
        ->and($output)->toContain('5 phút');
});

it('tệp chuyển bằng lệnh giữ bản cục bộ 30 ngày (mặc định) hoặc đúng số ngày của --keep-local-days', function (?int $days, int $expected) {
    t6EnableAndReady();

    t6Migrate(array_filter(['--limit' => 1, '--keep-local-days' => $days]));

    expect(CarbonImmutable::parse(StagingFixtures::row($this->media[0]->id)->local_purge_after, config('app.timezone'))
        ->equalTo(now()->addDays($expected)))->toBeTrue();
})->with(['mặc định' => [null, 30], '7 ngày' => [7, 7]]);

it('tệp cục bộ mất → báo mã media, không đổi đĩa, mã thoát 1; các tệp khác vẫn được chuyển', function () {
    t6EnableAndReady();
    DocumentStore::staging()->delete($this->media[1]->getPathRelativeToRoot());

    [$exit, $output] = t6Migrate();

    expect($exit)->toBe(1)
        ->and(Ops::disk($this->media[0]->id))->toBe(DocumentStore::REMOTE_DISK)
        ->and(Ops::disk($this->media[1]->id))->toBe(DocumentStore::STAGING_DISK)
        ->and(Ops::disk($this->media[2]->id))->toBe(DocumentStore::REMOTE_DISK)
        ->and($output)->toContain('#'.$this->media[1]->id.': '.__('storage.commands.reasons.staged_missing'));
});

it('mỗi lượt ghi MỘT audit tổng: số tệp, byte, lỗi, thời gian — không danh sách tệp', function () {
    t6EnableAndReady();
    DocumentStore::staging()->delete($this->media[1]->getPathRelativeToRoot());

    t6Migrate();

    $audits = Ops::audits('document_store_migration_run');
    expect($audits)->toHaveCount(1);

    $properties = $audits[0]->properties->all();
    expect(array_keys($properties))->toEqualCanonicalizing(['pushed', 'bytes', 'skipped', 'locked', 'failed', 'seconds', 'stopped'])
        ->and($properties['pushed'])->toBe(2)
        ->and($properties['failed'])->toBe(1)
        ->and($properties['bytes'])->toBe($this->media[0]->size + $this->media[2]->size);
});

// ---------------------------------------------------------------------------------------------
// Chạy lại: không tải lần hai; md5 lệch → thùng rác + thế hệ 2 (adapter thật trên Drive giả)
// ---------------------------------------------------------------------------------------------

it('tệp đã có trên kho (chỉ mục + md5 khớp) → không tải lần hai, chỉ đổi đĩa', function () {
    $drive = t6EnableAndReady();
    $drive->disk();
    $media = $this->media[0];
    $drive->seed($media->getPathRelativeToRoot(), (string) DocumentStore::staging()->get($media->getPathRelativeToRoot()));

    t6Migrate(['--limit' => 1]);

    expect(Ops::disk($media->id))->toBe(DocumentStore::REMOTE_DISK)
        ->and(Ops::uploadedNames(withoutProbes: true))->toBe([]);
});

it('md5 trên kho lệch → bản cũ vào thùng rác, tải lại với thế hệ 2', function () {
    $drive = t6EnableAndReady();
    $drive->disk();
    $media = $this->media[0];
    $key = $media->getPathRelativeToRoot();
    $old = $drive->seed($key, 'ban hong tren kho');

    t6Migrate(['--limit' => 1]);

    $live = DriveObject::query()->where('object_key', $key)->sole();

    expect(Ops::disk($media->id))->toBe(DocumentStore::REMOTE_DISK)
        ->and($drive->files[$old->file_id]['trashed'])->toBeTrue()
        ->and($old->fresh()->retired_reason)->toBe(DriveObjectRetirement::Trashed)
        ->and($live->generation)->toBe(2)
        ->and($drive->files[$live->file_id]['name'])->toBe(str_replace(['/', '.pdf'], ['~', '~g2.pdf'], $key));
});

/*
 * Rà soát cuối vòng sửa 1 (I5) — luồng mà sổ tay hứa: quay lui (giữ chỉ mục) → có người dọn Shared Drive
 * (cho tệp vào thùng rác, hay xoá hẳn khỏi thùng rác) → bật lại → migrate. Trước vòng sửa, dòng chỉ mục
 * còn SỐNG của một tệp đã ở thùng rác hay đã mất làm `checksum()` ném `UnableToProvideChecksum` ở MỌI
 * lượt: media báo "kho từ chối" mãi, không ai rút dòng đó, `reindex` cũng không (nó chỉ đi qua tên CÓ
 * trên Drive). Sau vòng sửa: dòng cũ được rút (`trashed`), tệp được tải lại với thế hệ kế tiếp.
 */
it('dòng chỉ mục còn sống mà tệp trên Drive đã vào thùng rác hay đã mất → rút dòng, tải lại thế hệ 2, mã 0', function (string $how) {
    $drive = t6EnableAndReady();
    $drive->disk();
    $media = $this->media[0];
    $key = $media->getPathRelativeToRoot();
    $content = (string) DocumentStore::staging()->get($key);
    $old = $drive->seed($key, $content);

    if ($how === 'trashed') {
        $drive->files[$old->file_id]['trashed'] = true;
    } else {
        unset($drive->files[$old->file_id]);
    }

    [$exit] = t6Migrate(['--limit' => 1]);

    $live = DriveObject::query()->where('object_key', $key)->sole();

    expect($exit)->toBe(0)
        ->and(Ops::disk($media->id))->toBe(DocumentStore::REMOTE_DISK)
        ->and($old->fresh()->object_key)->toBeNull()
        ->and($old->fresh()->retired_reason)->toBe(DriveObjectRetirement::Trashed)
        ->and($live->generation)->toBe(2)
        ->and($drive->files[$live->file_id]['content'])->toBe($content)
        ->and($drive->files[$live->file_id]['trashed'])->toBeFalse();
})->with(['trashed', 'deleted']);

// ---------------------------------------------------------------------------------------------
// Song song với lưu lượng thật
// ---------------------------------------------------------------------------------------------

it('giữa hai lô: một tải lên mới qua Livewire (đi đường job) và một lượt tải xuống media vừa đổi đĩa đều đúng', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $document = Document::factory()->for($matter)->group(DocumentGroup::Authority)->create(['status' => DocumentStatus::InternalDraft]);
    $old = StagingFixtures::media('%PDF-1.4 tep cu cua vu', document: $document);

    t6EnableAndReady();
    config(['queue.connections.storage' => ['driver' => 'sync']]);

    // Lô 1: ba tệp cũ đầu tiên (id nhỏ hơn) lên kho; tệp cũ của vụ còn ở vùng đệm.
    t6Migrate(['--limit' => 3]);
    expect(Ops::disk($old->id))->toBe(DocumentStore::STAGING_DISK);

    // Lô 2 chỉ một tệp: tệp cũ của vụ đổi đĩa.
    t6Migrate(['--limit' => 1]);
    expect(Ops::disk($old->id))->toBe(DocumentStore::REMOTE_DISK);

    // Tải lên mới qua màn hình: vào vùng đệm, job (đồng bộ) đẩy nó lên kho.
    Filament::setCurrentPanel('admin');
    $this->actingAs($lawyer, 'web')
        ->livewire(DocumentsRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
        ->callAction(TestAction::make('upload')->table(), data: [
            'file' => UploadedFile::fake()->createWithContent('moi.pdf', StagingFixtures::PDF),
            'title' => 'Thông báo mới',
            'group' => DocumentGroup::Authority->value,
        ])
        ->assertHasNoActionErrors();

    $new = Media::query()->latest('id')->first();
    expect($new->id)->not->toBe($old->id)
        ->and(Ops::disk($new->id))->toBe(DocumentStore::REMOTE_DISK);

    // Tải xuống media vừa đổi đĩa: đúng byte.
    $response = $this->actingAs($lawyer, 'web')->get($document->downloadUrlFor($lawyer));
    $response->assertOk();
    expect($response->streamedContent())->toBe('%PDF-1.4 tep cu cua vu');

    // Lô cuối: không còn gì; tệp mới không bị đẩy lần hai.
    $remote = StagingFixtures::hookRemote();
    [$exit, $output] = t6Migrate();

    expect($exit)->toBe(0, $output)
        ->and($remote->calls)->not->toContain('writeStream')
        ->and(Media::query()->where('disk', DocumentStore::STAGING_DISK)->count())->toBe(0);
});

it('kho không tới được giữa lượt → dừng cả lượt (không thử media sau), mã thoát 1, mọi media ở lại máy chủ', function () {
    $drive = t6EnableAndReady();
    $drive->disk();
    // Lượt tải tệp thăm dò của kiểm tra sẵn sàng đi qua; mọi phiên tải lên sau đó gặp lỗi kết nối.
    $drive->failNext('POST', '/upload/drive/v3/files', 0, times: 50, after: 1);

    [$exit, $output] = t6Migrate();

    expect($exit)->toBe(1)
        ->and(Media::query()->where('disk', DocumentStore::STAGING_DISK)->count())->toBe(3)
        ->and($output)->toContain('#'.$this->media[0]->id)
        ->and($output)->not->toContain('#'.$this->media[1]->id)
        ->and($output)->toContain(__('storage.commands.migrate.stopped_unavailable'))
        ->and(Ops::audits('document_store_migration_run')[0]->properties->get('stopped'))->toBe('unavailable');
});

// ---------------------------------------------------------------------------------------------
// Task 8 (đo độ phủ): hai kết quả của PushDocumentFileToRemote mà lệnh chưa có test nào chạy tới
// ---------------------------------------------------------------------------------------------

it('media đang bị một job đẩy giữ khoá → đếm "đang được job khác đẩy", ở lại máy chủ, không tính là lỗi (mã 0)', function () {
    t6EnableAndReady();
    $lock = DocumentStore::pushLock($this->media[1]->id);
    expect($lock->get())->toBeTrue();

    try {
        [$exit, $output] = t6Migrate();
    } finally {
        $lock->release();
    }

    expect($exit)->toBe(0, $output)
        ->and(Ops::disk($this->media[0]->id))->toBe(DocumentStore::REMOTE_DISK)
        ->and(Ops::disk($this->media[1]->id))->toBe(DocumentStore::STAGING_DISK)
        ->and(Ops::disk($this->media[2]->id))->toBe(DocumentStore::REMOTE_DISK)
        ->and($output)->toContain(__('storage.commands.migrate.locked', ['count' => 1]))
        ->and($output)->not->toContain('#'.$this->media[1]->id);
});

it('khoá lệch khuôn <media_id>/<ULID>.<đuôi> (tên người nộp đặt) → báo mã media lý do "khoá không đúng khuôn", ở lại máy chủ, mã 1', function () {
    t6EnableAndReady();
    $odd = StagingFixtures::media('%PDF-1.4 ten tu dat', 'Ho so Nguyen Van A.pdf');

    [$exit, $output] = t6Migrate();

    expect($exit)->toBe(1)
        ->and(Ops::disk($odd->id))->toBe(DocumentStore::STAGING_DISK)
        ->and(Ops::disk($this->media[0]->id))->toBe(DocumentStore::REMOTE_DISK)
        ->and($output)->toContain('#'.$odd->id.': '.__('storage.commands.reasons.rejected'))
        ->and($output)->not->toContain('Nguyen Van A');
});
