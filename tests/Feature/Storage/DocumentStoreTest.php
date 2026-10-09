<?php

use App\Models\Setting;
use App\Support\Storage\DocumentStore;
use Carbon\CarbonImmutable;
use Illuminate\Cache\DatabaseLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| M14 Task 1 — `DocumentStore`: công tắc `DOCUMENT_STORAGE`, mốc bật kho, khoá đẩy
|--------------------------------------------------------------------------
|
| Kế hoạch M14, R2 và R7: `DOCUMENT_STORAGE=google_drive` chỉ CHO PHÉP đẩy tệp lên kho; mốc
| `settings.storage.remote_enabled_at` (do `vkcrm:storage:enable` ghi, Task 6) mới BẬT. Giá trị
| gõ sai không bao giờ được hiểu là "kho" (tệp ở lại máy chủ, không mất), và phải lộ ra được
| (`driverIsValid()`), vì người vận hành đang tin tệp ở trên kho.
*/

function recordRemoteEnabledAt(?string $value): void
{
    Setting::query()->updateOrCreate(['key' => DocumentStore::REMOTE_ENABLED_AT_KEY], ['value' => $value]);
}

it('công tắc local: không dùng kho, và là giá trị hợp lệ', function () {
    config(['vkcrm.storage.driver' => 'local']);

    expect(DocumentStore::usesRemote())->toBeFalse()
        ->and(DocumentStore::driverIsValid())->toBeTrue();
});

it('công tắc google_drive: dùng kho, và là giá trị hợp lệ', function () {
    config(['vkcrm.storage.driver' => 'google_drive']);

    expect(DocumentStore::usesRemote())->toBeTrue()
        ->and(DocumentStore::driverIsValid())->toBeTrue();
});

it('giá trị gõ sai: không dùng kho (tệp ở lại máy chủ) VÀ bị đánh dấu không hợp lệ', function (mixed $value) {
    config(['vkcrm.storage.driver' => $value]);

    expect(DocumentStore::usesRemote())->toBeFalse()
        ->and(DocumentStore::driverIsValid())->toBeFalse();
})->with([
    'thừa chữ o' => 'gooogle_drive',
    'viết hoa' => 'Google_Drive',
    'khoảng trắng cuối' => 'google_drive ',
    'gạch ngang' => 'google-drive',
    'tên gói khác' => 's3',
    'chuỗi rỗng' => '',
    'null' => null,
]);

it('remoteEnabledAt(): chưa có dòng settings thì null', function () {
    expect(DocumentStore::remoteEnabledAt())->toBeNull();
});

it('remoteEnabledAt(): dòng có giá trị NULL hoặc rỗng thì null', function (?string $value) {
    recordRemoteEnabledAt($value);

    expect(DocumentStore::remoteEnabledAt())->toBeNull();
})->with(['NULL' => null, 'rỗng' => '', 'khoảng trắng' => '   ']);

it('remoteEnabledAt(): đọc mốc ISO-8601 thành CarbonImmutable đúng thời điểm', function () {
    recordRemoteEnabledAt('2026-10-04T21:30:00+07:00');

    $at = DocumentStore::remoteEnabledAt();

    expect($at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($at->equalTo(CarbonImmutable::parse('2026-10-04 14:30:00', 'UTC')))->toBeTrue();
});

it('remoteEnabledAt(): giá trị hỏng thì null (kho coi như chưa bật) kèm cảnh báo, không ném lỗi', function () {
    Log::spy();
    recordRemoteEnabledAt('không-phải-ngày');

    expect(DocumentStore::remoteEnabledAt())->toBeNull();

    Log::shouldHaveReceived('warning')->once();
});

it('pushesNewFiles(): chỉ đúng khi công tắc là google_drive VÀ đã có mốc bật', function (string $driver, ?string $enabledAt, bool $expected) {
    config(['vkcrm.storage.driver' => $driver]);

    if ($enabledAt !== null) {
        recordRemoteEnabledAt($enabledAt);
    }

    expect(DocumentStore::pushesNewFiles())->toBe($expected);
})->with([
    'local, chưa có mốc' => ['local', null, false],
    'local, có mốc (mốc cũ còn sót sau khi tắt)' => ['local', '2026-10-04T21:30:00+07:00', false],
    'google_drive, chưa có mốc (mới cho phép, chưa bật)' => ['google_drive', null, false],
    'google_drive, có mốc' => ['google_drive', '2026-10-04T21:30:00+07:00', true],
    'gõ sai, có mốc' => ['gooogle_drive', '2026-10-04T21:30:00+07:00', false],
]);

it('pushesNewFiles(): ở chế độ local không đọc bảng settings (listener tạo media không tốn một truy vấn)', function () {
    config(['vkcrm.storage.driver' => 'local']);
    recordRemoteEnabledAt('2026-10-04T21:30:00+07:00');

    DB::enableQueryLog();
    DocumentStore::pushesNewFiles();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toBe([]);
});

/**
 * TTL của khoá đẩy phải lớn hơn `$timeout` của job `PushDocumentFile` (Task 3, 1800 giây): khoá hết
 * hạn giữa một lượt tải gói 2 GB thì lượt thứ hai chạy song song và đụng `object_key` unique. Ghim
 * bằng SỐ (1800), không bằng hằng của chính job: job chưa tồn tại tới Task 3, và một test so một
 * hằng với chính nó thì không đỏ được.
 *
 * Đo bằng hành vi: dòng `cache_locks` mà khoá ghi ra có hạn đúng `now + 2100`. Test chạy với
 * `CACHE_STORE=array` (phpunit.xml) nên dòng đó chỉ tồn tại khi khoá thật sự đi qua store
 * `database` — store chung mọi tiến trình (web, cron, worker), thứ duy nhất làm khoá có nghĩa.
 */
it('pushLock(): khoá document-push:{id} trên store database, hạn 2100 giây > 1800 giây timeout của job đẩy', function () {
    $this->freezeTime();

    $lock = DocumentStore::pushLock(42);

    expect($lock)->toBeInstanceOf(DatabaseLock::class)
        ->and($lock->get())->toBeTrue();

    $row = DB::table('cache_locks')->where('key', 'like', '%document-push:42')->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->expiration)->toBe(now()->getTimestamp() + 2100)
        ->and((int) $row->expiration - now()->getTimestamp())->toBeGreaterThan(1800);

    $lock->release();
});

it('pushLock(): hai lượt cùng media loại trừ nhau, media khác không bị chặn', function () {
    $first = DocumentStore::pushLock(7);
    $second = DocumentStore::pushLock(7);
    $other = DocumentStore::pushLock(70);

    expect($first->get())->toBeTrue()
        ->and($second->get())->toBeFalse()
        ->and($other->get())->toBeTrue();

    $first->release();
    $other->release();

    expect($second->get())->toBeTrue();

    $second->release();
});

it('remote() là đĩa documents_remote, staging() là đĩa private', function () {
    expect(DocumentStore::REMOTE_DISK)->toBe('documents_remote')
        ->and(DocumentStore::STAGING_DISK)->toBe('private')
        ->and(DocumentStore::remote())->toBe(Storage::disk('documents_remote'))
        ->and(DocumentStore::staging())->toBe(Storage::disk('private'));
});
