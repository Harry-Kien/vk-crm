<?php

use App\Actions\Schedule\PurgeStagedDocumentCopies;
use App\Actions\Schedule\PushPendingDocumentFiles;
use App\Actions\Storage\ImportOfficeReceipts;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Models\Document;
use App\Models\DriveObject;
use App\Models\Matter;
use App\Models\Setting;
use App\Models\User;
use App\Support\Storage\DocumentStore;
use App\Support\Storage\StagedCopy;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\DocumentStoreFixtures as Store;
use Tests\Support\OfficeReceiptFixtures as Receipts;
use Tests\Support\RemoteDocuments;
use Tests\Support\StagingFixtures;
use Tests\Support\StorageCommandFixtures as Ops;

/*
|--------------------------------------------------------------------------
| M14 Task 6 — `vkcrm:storage:rollback`: kéo mọi tệp về máy chủ (R11)
|--------------------------------------------------------------------------
|
| Cổng DUY NHẤT là công tắc `local` (đọc qua `config()`): quay lui trước, tắt kho sau, là quay lui tự
| đảo ngược — tác vụ quét đẩy lại mọi dòng vừa quay lui trong 15 phút. Tệp còn bản cục bộ (md5 khớp)
| đổi đĩa ngay, không cần Drive; tệp đã dọn được tải về, kiểm md5, rồi mới đổi. Chỉ khi đó mới cần khoá
| và Drive tới được — chia sẻ lệch không chặn. Bản trên kho và chỉ mục không bị chạm.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 20:00:00', 'Asia/Ho_Chi_Minh'));
});

function t6Rollback(): array
{
    return Ops::artisan('vkcrm:storage:rollback');
}

/** Hai media đã lên kho (đĩa kho giả), bản cục bộ còn; rồi tắt công tắc. */
function t6PushedPair(): array
{
    $first = StagingFixtures::media('%PDF-1.4 mot');
    $second = StagingFixtures::media('%PDF-1.4 hai');
    StagingFixtures::enableRemote();

    $pair = [Ops::push($first), Ops::push($second)];
    config(['vkcrm.storage.driver' => DocumentStore::DRIVER_LOCAL]);

    return $pair;
}

it('công tắc còn google_drive (hay gõ sai) → mã 2, KHÔNG đổi gì: media ở kho, mốc bật kho còn', function (string $driver) {
    [$first] = t6PushedPair();
    config(['vkcrm.storage.driver' => $driver]);

    [$exit, $output] = t6Rollback();

    expect($exit)->toBe(2)
        ->and($output)->toContain('DOCUMENT_STORAGE=local')
        ->and(Ops::disk($first->id))->toBe(DocumentStore::REMOTE_DISK)
        ->and(Setting::query()->where('key', DocumentStore::REMOTE_ENABLED_AT_KEY)->exists())->toBeTrue()
        ->and(Ops::audits('document_store_rollback_run'))->toBe([]);
})->with(['google_drive', 'local ']);

it('bản cục bộ còn → đổi về private, KHÔNG đọc kho; local_purge_after và remote_pushed_at về NULL; mốc bật kho bị xoá', function () {
    [$first, $second] = t6PushedPair();
    $remote = StagingFixtures::hookRemote();

    [$exit, $output] = t6Rollback();

    $row = StagingFixtures::row($first->id);

    expect($exit)->toBe(0, $output)
        ->and($row->disk)->toBe(DocumentStore::STAGING_DISK)
        ->and($row->conversions_disk)->toBe(DocumentStore::STAGING_DISK)
        ->and($row->local_purge_after)->toBeNull()
        ->and($row->remote_pushed_at)->toBeNull()
        ->and($row->checksum_md5)->toBe(md5('%PDF-1.4 mot'))
        ->and(Ops::disk($second->id))->toBe(DocumentStore::STAGING_DISK)
        ->and($remote->calls)->toBe([])
        ->and(Setting::query()->where('key', DocumentStore::REMOTE_ENABLED_AT_KEY)->exists())->toBeFalse()
        // Bản trên kho còn nguyên.
        ->and(Ops::remoteFiles())->toHaveCount(2);
});

it('bản cục bộ đã dọn → tải về, kiểm md5, đặt vào chỗ, rồi mới đổi đĩa; tải xuống trả đúng byte', function () {
    [$first] = t6PushedPair();
    StagedCopy::discard($first);
    // Tải về cần khoá và Drive tới được (`drive_reachable`): máy chủ Drive giả; tệp vẫn ở đĩa kho giả.
    Ops::ready();

    [$exit, $output] = t6Rollback();

    expect($exit)->toBe(0, $output)
        ->and(Ops::disk($first->id))->toBe(DocumentStore::STAGING_DISK)
        ->and(DocumentStore::staging()->get($first->getPathRelativeToRoot()))->toBe('%PDF-1.4 mot')
        // Không tệp tạm nào ở lại cạnh đích.
        ->and(DocumentStore::staging()->files((string) $first->id))->toBe([$first->getPathRelativeToRoot()]);
});

it('bản tải về lệch md5 → không đổi đĩa, không để tệp ở vùng đệm, báo mã media, mã thoát 1', function () {
    [$first, $second] = t6PushedPair();
    StagedCopy::discard($first);
    // Tải về cần khoá và Drive tới được (`drive_reachable`): máy chủ Drive giả; tệp vẫn ở đĩa kho giả.
    Ops::ready();
    DocumentStore::remote()->put($first->getPathRelativeToRoot(), '%PDF-1.4 bi doi');

    [$exit, $output] = t6Rollback();

    // Rà soát cuối M14 vòng sửa 1 (I7d): bản trên kho đã bị đổi — chạy lại không bao giờ xong. Câu cuối
    // chỉ tới verify và Phụ lục D, không bảo "chạy lại khi Drive tới được".
    expect($exit)->toBe(1)
        ->and($output)->toContain('#'.$first->id.': '.__('storage.commands.reasons.changed'))
        ->and($output)->toContain(__('storage.commands.rollback.incomplete_manual'))
        ->and($output)->not->toContain(__('storage.commands.rollback.incomplete_retry'))
        ->and(__('storage.commands.rollback.incomplete_manual'))->toContain('vkcrm:storage:verify')
        ->toContain('Phụ lục D')
        ->and(Ops::disk($first->id))->toBe(DocumentStore::REMOTE_DISK)
        ->and(DocumentStore::staging()->exists($first->getPathRelativeToRoot()))->toBeFalse()
        ->and(Ops::disk($second->id))->toBe(DocumentStore::STAGING_DISK);
});

it('kho không còn tệp (bản cục bộ đã dọn) → lý do missing, câu cuối chỉ tới verify và Phụ lục D, không bảo chạy lại (rà soát cuối I7d)', function () {
    [$first] = t6PushedPair();
    StagedCopy::discard($first);
    Ops::ready();
    DocumentStore::remote()->delete($first->getPathRelativeToRoot());

    [$exit, $output] = t6Rollback();

    expect($exit)->toBe(1)
        ->and($output)->toContain('#'.$first->id.': '.__('storage.commands.reasons.missing'))
        ->and($output)->toContain(__('storage.commands.rollback.incomplete_manual'))
        ->and($output)->not->toContain(__('storage.commands.rollback.incomplete_retry'));
});

it('bản cục bộ còn mà lệch md5 → tải bản trên kho về thay, rồi mới đổi', function () {
    [$first] = t6PushedPair();
    DocumentStore::staging()->put($first->getPathRelativeToRoot(), 'hong');
    // Tải về cần khoá và Drive tới được (`drive_reachable`): máy chủ Drive giả; tệp vẫn ở đĩa kho giả.
    Ops::ready();

    [$exit] = t6Rollback();

    expect($exit)->toBe(0)
        ->and(Ops::disk($first->id))->toBe(DocumentStore::STAGING_DISK)
        ->and(DocumentStore::staging()->get($first->getPathRelativeToRoot()))->toBe('%PDF-1.4 mot');
});

// ---------------------------------------------------------------------------------------------
// Adapter thật trên Drive giả: Drive không tới được, chia sẻ lệch, kho và chỉ mục còn nguyên.
// ---------------------------------------------------------------------------------------------

it('Drive không tới được → media có bản cục bộ vẫn quay lui, media cần tải về bị bỏ qua, mã thoát 1', function () {
    $keep = StagingFixtures::media('%PDF-1.4 con ban cuc bo');
    $gone = StagingFixtures::media('%PDF-1.4 da don');
    $drive = Ops::ready(RemoteDocuments::bindRealDriveAdapter([$keep, $gone]));
    // Bản cục bộ của $keep vẫn còn (bindRealDriveAdapter đã dọn nó: đặt lại).
    DocumentStore::staging()->put($keep->getPathRelativeToRoot(), '%PDF-1.4 con ban cuc bo');
    $drive->failNext('GET', '/drives/', 0, times: 10);

    [$exit, $output] = t6Rollback();

    expect($exit)->toBe(1)
        ->and(Ops::disk($keep->id))->toBe(DocumentStore::STAGING_DISK)
        ->and(Ops::disk($gone->id))->toBe(DocumentStore::REMOTE_DISK)
        ->and($output)->toContain('drive_reachable:')
        ->and($output)->toContain(__('storage.commands.rollback.incomplete_retry'))
        ->and($output)->not->toContain(__('storage.commands.rollback.incomplete_manual'))
        ->and(Ops::requests('alt=media'))->toBe([]);
});

it('drive_sharing ĐỎ (thành viên lạ) KHÔNG chặn quay lui: tệp đã dọn vẫn được tải về', function () {
    $gone = StagingFixtures::media('%PDF-1.4 da don');
    $drive = Ops::ready(RemoteDocuments::bindRealDriveAdapter([$gone]));
    $drive->permissionPages[0]['permissions'][] = ['id' => 'p9', 'type' => 'user', 'role' => 'writer', 'emailAddress' => 'nguoi-la@ngoai.vn'];

    [$exit, $output] = t6Rollback();

    expect($exit)->toBe(0, $output)
        ->and(Ops::disk($gone->id))->toBe(DocumentStore::STAGING_DISK)
        ->and(DocumentStore::staging()->get($gone->getPathRelativeToRoot()))->toBe('%PDF-1.4 da don');
});

it('bản trên kho và chỉ mục còn nguyên sau quay lui (không thùng rác); bật và chuyển lại → không tải lần hai', function () {
    $media = StagingFixtures::media('%PDF-1.4 quay lui roi chuyen lai');
    $drive = Ops::ready(RemoteDocuments::bindRealDriveAdapter([$media]));

    t6Rollback();

    $object = DriveObject::query()->where('object_key', $media->getPathRelativeToRoot())->sole();
    expect(Ops::disk($media->id))->toBe(DocumentStore::STAGING_DISK)
        ->and($drive->files[$object->file_id]['trashed'])->toBeFalse()
        ->and(Ops::writeRequests())->toBe([]);

    config(['vkcrm.storage.driver' => DocumentStore::DRIVER_GOOGLE_DRIVE]);
    Ops::artisan('vkcrm:storage:enable');
    [$exit, $output] = Ops::artisan('vkcrm:storage:migrate');

    expect($exit)->toBe(0, $output)
        ->and(Ops::disk($media->id))->toBe(DocumentStore::REMOTE_DISK)
        ->and(Ops::uploadedNames(withoutProbes: true))->toBe([])
        ->and(DriveObject::query()->where('object_key', $media->getPathRelativeToRoot())->sole()->id)->toBe($object->id);
});

it('ghi MỘT audit tổng document_store_rollback_run, không danh sách tệp', function () {
    [$first] = t6PushedPair();
    StagedCopy::discard($first);
    // Tải về cần khoá và Drive tới được (`drive_reachable`): máy chủ Drive giả; tệp vẫn ở đĩa kho giả.
    Ops::ready();

    t6Rollback();

    $audits = Ops::audits('document_store_rollback_run');
    expect($audits)->toHaveCount(1)
        ->and(array_keys($audits[0]->properties->all()))->toEqualCanonicalizing(['local', 'downloaded', 'bytes', 'unreachable', 'locked', 'failed', 'seconds'])
        ->and($audits[0]->properties->get('local'))->toBe(1)
        ->and($audits[0]->properties->get('downloaded'))->toBe(1);
});

it('media đang bị khoá đẩy → bỏ qua, báo, mã thoát 1; chạy lại sau khi khoá nhả thì xong', function () {
    [$first] = t6PushedPair();
    $lock = DocumentStore::pushLock($first->id);
    $lock->get();

    [$exit] = t6Rollback();
    expect($exit)->toBe(1)->and(Ops::disk($first->id))->toBe(DocumentStore::REMOTE_DISK);

    $lock->release();
    [$exit] = t6Rollback();
    expect($exit)->toBe(0)->and(Ops::disk($first->id))->toBe(DocumentStore::STAGING_DISK);
});

// ---------------------------------------------------------------------------------------------
// Quay lui không tự đảo ngược
// ---------------------------------------------------------------------------------------------

it('sau quay lui tác vụ quét không xếp gì; đặt lại google_drive mà chưa enable → vẫn không; enable mới → vẫn không; chỉ migrate chuyển', function () {
    Queue::fake();
    $this->travel(-2)->hours();
    [$first, $second] = t6PushedPair();
    $this->travel(2)->hours();

    t6Rollback();
    expect(app(PushPendingDocumentFiles::class)->handle()['queued'])->toBe(0);

    config(['vkcrm.storage.driver' => DocumentStore::DRIVER_GOOGLE_DRIVE]);
    expect(app(PushPendingDocumentFiles::class)->handle()['queued'])->toBe(0);

    Ops::ready();
    [$exit] = Ops::artisan('vkcrm:storage:enable');
    expect($exit)->toBe(0)
        ->and(app(PushPendingDocumentFiles::class)->handle()['queued'])->toBe(0);

    $this->travel(1)->hours();
    expect(app(PushPendingDocumentFiles::class)->handle()['queued'])->toBe(0)
        ->and(Ops::disk($first->id))->toBe(DocumentStore::STAGING_DISK);

    $remote = StagingFixtures::hookRemote();
    [$exit] = Ops::artisan('vkcrm:storage:migrate');

    expect($exit)->toBe(0)
        ->and(Ops::disk($first->id))->toBe(DocumentStore::REMOTE_DISK)
        ->and(Ops::disk($second->id))->toBe(DocumentStore::REMOTE_DISK)
        // Bản trên kho còn từ lần trước, md5 khớp: không tải lần hai.
        ->and($remote->calls)->not->toContain('writeStream');
});

// ---------------------------------------------------------------------------------------------
// Quay lui rồi dọn: biên nhận mới không làm mất bản duy nhất
// ---------------------------------------------------------------------------------------------

it('quay lui → nhập một biên nhận mới khớp → dọn vùng đệm → tệp CÒN, tải xuống trả đúng byte', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $document = Document::factory()->for($matter)->group(DocumentGroup::Authority)->create(['status' => DocumentStatus::InternalDraft]);
    $media = StagingFixtures::media('%PDF-1.4 ban duy nhat', document: $document);
    StagingFixtures::enableRemote();
    Ops::push($media);
    $key = $media->getPathRelativeToRoot();
    // Đĩa kho giả không có chỉ mục: dòng sống của khoá, như adapter Drive ghi lúc đẩy.
    Store::driveObject(['object_key' => $key, 'md5' => md5('%PDF-1.4 ban duy nhat'), 'size' => strlen('%PDF-1.4 ban duy nhat')]);
    config(['vkcrm.storage.driver' => DocumentStore::DRIVER_LOCAL]);

    [$exit] = t6Rollback();
    expect($exit)->toBe(0);

    Http::preventStrayRequests();
    Receipts::configure();
    Receipts::fakeRclone([Receipts::fileName('20261007T120000Z') => Receipts::receipt([
        Receipts::line($key, md5('%PDF-1.4 ban duy nhat'), strlen('%PDF-1.4 ban duy nhat')),
    ])]);
    expect(app(ImportOfficeReceipts::class)->handle()->marked)->toBe(1);

    $this->travel(40)->days();
    app(PurgeStagedDocumentCopies::class)->handle();

    expect(DocumentStore::staging()->exists($key))->toBeTrue()
        ->and(Ops::disk($media->id))->toBe(DocumentStore::STAGING_DISK);

    $response = $this->actingAs($lawyer, 'web')->get($document->fresh()->downloadUrlFor($lawyer));
    $response->assertOk();
    expect($response->streamedContent())->toBe('%PDF-1.4 ban duy nhat');
});
