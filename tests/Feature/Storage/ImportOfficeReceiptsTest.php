<?php

use App\Actions\Storage\ImportOfficeReceipts;
use App\Models\Setting;
use App\Models\SystemHealth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Spatie\Backup\Events\BackupHasFailed;
use Tests\Support\DocumentStoreFixtures;
use Tests\Support\FakeGoogleDrive;
use Tests\Support\OfficeReceiptFixtures as Receipts;

/*
|--------------------------------------------------------------------------
| M14 Task 7 — CRM nhập biên nhận bản thứ hai của máy văn phòng (kế hoạch R10)
|--------------------------------------------------------------------------
|
| Biên nhận là điều kiện DUY NHẤT để vùng đệm trên máy chủ web được dọn (R10, điều 4 của
| `PurgeStagedDocumentCopies`, làn m14 Task 3). Một biên nhận nhận nhầm là một tệp chỉ còn bản trong
| Google. Vì vậy mỗi dòng chỉ đánh dấu khi tên đọc ngược ra đúng khoá VÀ thế hệ của một dòng chỉ mục
| SỐNG trên đúng `drive_id`, với md5 và cỡ khớp; cả tệp bị từ chối khi nó thuộc Shared Drive hay thư
| mục gốc khác. `rclone` chỉ là `Process::fake` (phán quyết C2): không binary thật, không Google thật.
|
| Dòng `drive_objects` được gieo thẳng (phán quyết của controller cho làn m14b): `PushDocumentFileToRemote`
| và `PurgeStagedDocumentCopies` thuộc làn m14 Task 3; test "đi trọn đường" ở
| `OfficeReceiptPurgePathTest` (dời sang Task 6).
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Receipts::configure();
    $this->freezeTime();
});

function t7Copied(int $id): ?string
{
    return DB::table('drive_objects')->where('id', $id)->value('office_copied_at');
}

it('biên nhận hợp lệ: đúng các dòng khớp được đánh dấu, dòng không có trong biên nhận thì không', function () {
    $a = Receipts::key(1834);
    $b = Receipts::key(1835, '');
    $c = Receipts::key(1836);
    $rowA = DocumentStoreFixtures::driveObject(['object_key' => $a, 'md5' => md5('a'), 'size' => 10]);
    $rowB = DocumentStoreFixtures::driveObject(['object_key' => $b, 'md5' => md5('b'), 'size' => 20]);
    $rowC = DocumentStoreFixtures::driveObject(['object_key' => $c, 'md5' => md5('c'), 'size' => 30]);

    Receipts::fakeRclone([Receipts::fileName() => Receipts::receipt([
        Receipts::line($a, md5('a'), 10),
        Receipts::line($b, md5('b'), 20),
    ])]);

    $result = app(ImportOfficeReceipts::class)->handle();

    expect($result->imported)->toBe(1)
        ->and($result->marked)->toBe(2)
        ->and($result->rejected)->toBe(0)
        ->and(t7Copied($rowA))->not->toBeNull()
        ->and(t7Copied($rowB))->not->toBeNull()
        ->and(t7Copied($rowC))->toBeNull();

    $health = SystemHealth::current();
    expect($health->last_office_receipt_at?->equalTo(now()->startOfSecond()))->toBeTrue()
        ->and($health->last_office_receipt_error)->toBeNull()
        ->and(Setting::query()->where('key', ImportOfficeReceipts::CURSOR_KEY)->value('value'))->toBe(Receipts::fileName());
});

it('team_drive khác cấu hình → cả tệp bị từ chối, không dòng nào, có lỗi, cursor vẫn qua tệp đó', function () {
    $key = Receipts::key();
    $row = DocumentStoreFixtures::driveObject(['object_key' => $key, 'md5' => md5('x'), 'size' => 10]);

    Receipts::fakeRclone([Receipts::fileName() => Receipts::receipt([Receipts::line($key, md5('x'), 10)], [
        'kho' => ['team_drive' => '0AKhoKhac', 'root_folder_id' => FakeGoogleDrive::ROOT_FOLDER_ID],
    ])]);

    $result = app(ImportOfficeReceipts::class)->handle();

    $health = SystemHealth::current();
    expect($result->rejected)->toBe(1)
        ->and($result->imported)->toBe(0)
        ->and(t7Copied($row))->toBeNull()
        ->and($health->last_office_receipt_error)->toContain(Receipts::fileName())
        ->and($health->last_office_receipt_at)->toBeNull()
        ->and(Setting::query()->where('key', ImportOfficeReceipts::CURSOR_KEY)->value('value'))->toBe(Receipts::fileName());
});

it('root_folder_id khác cấu hình → không dòng nào', function () {
    $key = Receipts::key();
    $row = DocumentStoreFixtures::driveObject(['object_key' => $key, 'md5' => md5('x'), 'size' => 10]);

    Receipts::fakeRclone([Receipts::fileName() => Receipts::receipt([Receipts::line($key, md5('x'), 10)], [
        'kho' => ['team_drive' => FakeGoogleDrive::DRIVE_ID, 'root_folder_id' => '1GocKhac'],
    ])]);

    $result = app(ImportOfficeReceipts::class)->handle();

    expect($result->rejected)->toBe(1)
        ->and(t7Copied($row))->toBeNull()
        ->and(SystemHealth::current()->last_office_receipt_error)->not->toBeNull();
});

it('md5 khác → dòng đó không được đánh dấu, dòng khớp vẫn được, và có lỗi "tệp bị đổi"', function () {
    $good = Receipts::key(10);
    $bad = Receipts::key(11);
    $rowGood = DocumentStoreFixtures::driveObject(['object_key' => $good, 'md5' => md5('g'), 'size' => 10]);
    $rowBad = DocumentStoreFixtures::driveObject(['object_key' => $bad, 'md5' => md5('b'), 'size' => 10]);

    Receipts::fakeRclone([Receipts::fileName() => Receipts::receipt([
        Receipts::line($good, md5('g'), 10),
        Receipts::line($bad, md5('khac'), 10),
    ])]);

    $result = app(ImportOfficeReceipts::class)->handle();

    expect($result->marked)->toBe(1)
        ->and($result->mismatched)->toBe(1)
        ->and(t7Copied($rowGood))->not->toBeNull()
        ->and(t7Copied($rowBad))->toBeNull()
        ->and(SystemHealth::current()->last_office_receipt_error)->toContain('md5');
});

it('cỡ khác → dòng đó không được đánh dấu', function () {
    $key = Receipts::key();
    $row = DocumentStoreFixtures::driveObject(['object_key' => $key, 'md5' => md5('x'), 'size' => 10]);

    Receipts::fakeRclone([Receipts::fileName() => Receipts::receipt([Receipts::line($key, md5('x'), 11)])]);

    $result = app(ImportOfficeReceipts::class)->handle();

    expect($result->marked)->toBe(0)
        ->and($result->mismatched)->toBe(1)
        ->and(t7Copied($row))->toBeNull();
});

it('thế hệ khác → không (cả hai chiều), đúng thế hệ → có', function () {
    $key1 = Receipts::key(20);
    $key2 = Receipts::key(21);
    $key3 = Receipts::key(22);
    $gen2 = DocumentStoreFixtures::driveObject(['object_key' => $key1, 'generation' => 2, 'md5' => md5('1'), 'size' => 10]);
    $gen1 = DocumentStoreFixtures::driveObject(['object_key' => $key2, 'generation' => 1, 'md5' => md5('2'), 'size' => 10]);
    $gen3 = DocumentStoreFixtures::driveObject(['object_key' => $key3, 'generation' => 3, 'md5' => md5('3'), 'size' => 10]);

    Receipts::fakeRclone([Receipts::fileName() => Receipts::receipt([
        Receipts::line($key1, md5('1'), 10, 1),
        Receipts::line($key2, md5('2'), 10, 2),
        Receipts::line($key3, md5('3'), 10, 3),
    ])]);

    $result = app(ImportOfficeReceipts::class)->handle();

    expect(t7Copied($gen2))->toBeNull()
        ->and(t7Copied($gen1))->toBeNull()
        ->and(t7Copied($gen3))->not->toBeNull()
        ->and($result->unmatched)->toBe(2)
        ->and($result->marked)->toBe(1);
});

it('dòng ở drive_id khác → không', function () {
    $key = Receipts::key();
    $row = DocumentStoreFixtures::driveObject(['drive_id' => '0AKhoCu', 'object_key' => $key, 'md5' => md5('x'), 'size' => 10]);

    Receipts::fakeRclone([Receipts::fileName() => Receipts::receipt([Receipts::line($key, md5('x'), 10)])]);

    $result = app(ImportOfficeReceipts::class)->handle();

    expect(t7Copied($row))->toBeNull()
        ->and($result->unmatched)->toBe(1);
});

it('dòng đã rời chỉ mục sống (vào thùng rác, khoá ở former_key) → không', function () {
    $key = Receipts::key();
    $row = DocumentStoreFixtures::driveObject([
        'object_key' => null, 'former_key' => $key, 'retired_reason' => 'trashed', 'retired_at' => now(),
        'md5' => md5('x'), 'size' => 10,
    ]);

    Receipts::fakeRclone([Receipts::fileName() => Receipts::receipt([Receipts::line($key, md5('x'), 10)])]);

    app(ImportOfficeReceipts::class)->handle();

    expect(t7Copied($row))->toBeNull();
});

it('tên lạ → đếm, không ghi, không phải lỗi; các dòng khác vẫn được đánh dấu', function () {
    $key = Receipts::key();
    $row = DocumentStoreFixtures::driveObject(['object_key' => $key, 'md5' => md5('x'), 'size' => 10]);

    Receipts::fakeRclone([Receipts::fileName() => Receipts::receipt([
        ['name' => 'preflight~01k6xq0f9m2y7c4w8r3t5v6n1b.txt', 'md5' => md5('p'), 'size' => 5],
        ['name' => 'Hop dong.pdf', 'md5' => md5('q'), 'size' => 5],
        Receipts::line($key, md5('x'), 10),
    ])]);

    $result = app(ImportOfficeReceipts::class)->handle();

    expect($result->unknownNames)->toBe(2)
        ->and($result->marked)->toBe(1)
        ->and(t7Copied($row))->not->toBeNull()
        ->and(SystemHealth::current()->last_office_receipt_error)->toBeNull();
});

it('dòng đã có biên nhận → không ghi đè mốc cũ', function () {
    $key = Receipts::key();
    $earlier = now()->subDays(3)->startOfSecond();
    $row = DocumentStoreFixtures::driveObject(['object_key' => $key, 'md5' => md5('x'), 'size' => 10, 'office_copied_at' => $earlier]);

    Receipts::fakeRclone([Receipts::fileName() => Receipts::receipt([Receipts::line($key, md5('x'), 10)])]);

    $result = app(ImportOfficeReceipts::class)->handle();

    expect($result->alreadyMarked)->toBe(1)
        ->and($result->marked)->toBe(0)
        ->and(DB::table('drive_objects')->where('id', $row)->value('office_copied_at'))->toBe($earlier->format('Y-m-d H:i:s'));
});

it('tệp quá cỡ (theo lsjson) → bỏ, không đọc nội dung, có lỗi, cursor qua tệp đó', function () {
    config(['vkcrm.storage.office.receipt_max_bytes' => 1000]);
    $key = Receipts::key();
    $row = DocumentStoreFixtures::driveObject(['object_key' => $key, 'md5' => md5('x'), 'size' => 10]);

    Receipts::fakeRclone(
        [Receipts::fileName() => Receipts::receipt([Receipts::line($key, md5('x'), 10)])],
        sizes: [Receipts::fileName() => 1001],
    );

    $result = app(ImportOfficeReceipts::class)->handle();

    expect($result->rejected)->toBe(1)
        ->and(Receipts::catted())->toBe([])
        ->and(t7Copied($row))->toBeNull()
        ->and(SystemHealth::current()->last_office_receipt_error)->not->toBeNull()
        ->and(Setting::query()->where('key', ImportOfficeReceipts::CURSOR_KEY)->value('value'))->toBe(Receipts::fileName());
});

it('tệp đúng trần cỡ thì được đọc', function () {
    $key = Receipts::key();
    // `rclone` giả nối một "\n" vào cuối đầu ra (FakeProcessResult); `office-pull.sh` cũng kết thúc
    // biên nhận bằng "\n". Nội dung đặt sẵn dấu đó để cỡ khai và cỡ đọc về bằng nhau, đúng trần.
    $content = Receipts::receipt([Receipts::line($key, md5('x'), 10)])."\n";
    config(['vkcrm.storage.office.receipt_max_bytes' => strlen($content)]);
    $row = DocumentStoreFixtures::driveObject(['object_key' => $key, 'md5' => md5('x'), 'size' => 10]);

    Receipts::fakeRclone([Receipts::fileName() => $content]);

    $result = app(ImportOfficeReceipts::class)->handle();

    expect($result->imported)->toBe(1)
        ->and(t7Copied($row))->not->toBeNull();
});

it('nội dung đọc về lớn hơn trần dù lsjson khai nhỏ → bỏ', function () {
    $key = Receipts::key();
    $content = Receipts::receipt([Receipts::line($key, md5('x'), 10)])."\n";
    config(['vkcrm.storage.office.receipt_max_bytes' => strlen($content) - 1]);
    $row = DocumentStoreFixtures::driveObject(['object_key' => $key, 'md5' => md5('x'), 'size' => 10]);

    Receipts::fakeRclone([Receipts::fileName() => $content], sizes: [Receipts::fileName() => 10]);

    $result = app(ImportOfficeReceipts::class)->handle();

    expect($result->rejected)->toBe(1)
        ->and(t7Copied($row))->toBeNull();
});

it('started_at ở tương lai quá 5 phút → bỏ cả tệp', function () {
    $key = Receipts::key();
    $row = DocumentStoreFixtures::driveObject(['object_key' => $key, 'md5' => md5('x'), 'size' => 10]);

    Receipts::fakeRclone([Receipts::fileName() => Receipts::receipt([Receipts::line($key, md5('x'), 10)], [
        'started_at' => now()->addMinutes(6)->utc()->format('Y-m-d\TH:i:s\Z'),
    ])]);

    $result = app(ImportOfficeReceipts::class)->handle();

    expect($result->rejected)->toBe(1)
        ->and(t7Copied($row))->toBeNull();
});

it('started_at sớm hơn 5 phút tới (lệch đồng hồ nhỏ) → nhận', function () {
    $key = Receipts::key();
    $row = DocumentStoreFixtures::driveObject(['object_key' => $key, 'md5' => md5('x'), 'size' => 10]);

    Receipts::fakeRclone([Receipts::fileName() => Receipts::receipt([Receipts::line($key, md5('x'), 10)], [
        'started_at' => now()->addMinutes(5)->utc()->format('Y-m-d\TH:i:s\Z'),
    ])]);

    $result = app(ImportOfficeReceipts::class)->handle();

    expect($result->imported)->toBe(1)
        ->and(t7Copied($row))->not->toBeNull();
});

it('nhập lại cùng tệp → không đọc lại, không đổi gì (cursor)', function () {
    $key = Receipts::key();
    $row = DocumentStoreFixtures::driveObject(['object_key' => $key, 'md5' => md5('x'), 'size' => 10]);
    $files = [Receipts::fileName() => Receipts::receipt([Receipts::line($key, md5('x'), 10)])];

    Receipts::fakeRclone($files);
    app(ImportOfficeReceipts::class)->handle();
    $first = t7Copied($row);
    $firstAt = SystemHealth::current()->last_office_receipt_at;

    $this->travel(1)->days();
    Receipts::fakeRclone($files);
    $result = app(ImportOfficeReceipts::class)->handle();

    expect($result->imported)->toBe(0)
        ->and(Receipts::catted())->toBe([])
        ->and(t7Copied($row))->toBe($first)
        ->and(SystemHealth::current()->last_office_receipt_at?->equalTo($firstAt))->toBeTrue();
});

it('chỉ đọc tệp receipt-<UTC>.json có tên lớn hơn cursor, theo thứ tự tên; tệp khác bị bỏ qua', function () {
    DocumentStoreFixtures::setting(ImportOfficeReceipts::CURSOR_KEY, Receipts::fileName('20261005T010000Z'));

    Receipts::fakeRclone([
        Receipts::fileName('20261007T010000Z') => Receipts::receipt([]),
        Receipts::fileName('20261004T010000Z') => Receipts::receipt([]),
        'ghi-chu.json' => Receipts::receipt([]),
        'receipt-latest.json' => Receipts::receipt([]),
        Receipts::fileName('20261006T010000Z') => Receipts::receipt([]),
        Receipts::fileName('20261005T010000Z') => Receipts::receipt([]),
    ]);

    $result = app(ImportOfficeReceipts::class)->handle();

    expect(Receipts::catted())->toBe([Receipts::fileName('20261006T010000Z'), Receipts::fileName('20261007T010000Z')])
        ->and($result->imported)->toBe(2)
        ->and(Setting::query()->where('key', ImportOfficeReceipts::CURSOR_KEY)->value('value'))->toBe(Receipts::fileName('20261007T010000Z'));
});

it('errors > 0 trong biên nhận → có lỗi, các dòng khớp vẫn được đánh dấu', function () {
    $key = Receipts::key();
    $row = DocumentStoreFixtures::driveObject(['object_key' => $key, 'md5' => md5('x'), 'size' => 10]);

    Receipts::fakeRclone([Receipts::fileName() => Receipts::receipt([Receipts::line($key, md5('x'), 10)], ['errors' => 3])]);

    $result = app(ImportOfficeReceipts::class)->handle();

    $health = SystemHealth::current();
    expect(t7Copied($row))->not->toBeNull()
        ->and($result->imported)->toBe(1)
        ->and($health->last_office_receipt_at)->not->toBeNull()
        ->and($health->last_office_receipt_error)->toContain('3 lỗi');
});

it('một biên nhận sạch sau đó xoá lỗi cũ', function () {
    SystemHealth::current()->update(['last_office_receipt_error' => 'lỗi cũ']);

    Receipts::fakeRclone([Receipts::fileName() => Receipts::receipt([])]);

    app(ImportOfficeReceipts::class)->handle();

    expect(SystemHealth::current()->last_office_receipt_error)->toBeNull();
});

it('không có biên nhận mới → không chạm dòng sức khoẻ', function () {
    SystemHealth::current()->update(['last_office_receipt_error' => 'lỗi cũ']);

    Receipts::fakeRclone([]);

    app(ImportOfficeReceipts::class)->handle();

    expect(SystemHealth::current()->last_office_receipt_error)->toBe('lỗi cũ');
});

it('lỗi rclone lsjson → BackupHasFailed rclone:office-receipts, không ghi gì', function () {
    Event::fake([BackupHasFailed::class]);
    Receipts::fakeRclone([], failList: true);

    $result = app(ImportOfficeReceipts::class)->handle();

    expect($result->rcloneFailed)->toBeTrue();
    Event::assertDispatched(BackupHasFailed::class, fn (BackupHasFailed $event) => $event->diskName === 'rclone:office-receipts'
        && $event->backupName === config('backup.backup.name'));
    expect(Setting::query()->where('key', ImportOfficeReceipts::CURSOR_KEY)->exists())->toBeFalse();
});

it('lỗi rclone cat → BackupHasFailed, dừng lại, cursor KHÔNG qua tệp đó (lượt sau đọc lại)', function () {
    Event::fake([BackupHasFailed::class]);
    $first = Receipts::fileName('20261006T010000Z');
    $second = Receipts::fileName('20261007T010000Z');

    Receipts::fakeRclone([$first => Receipts::receipt([]), $second => Receipts::receipt([])], failCat: [$second]);

    $result = app(ImportOfficeReceipts::class)->handle();

    expect($result->rcloneFailed)->toBeTrue()
        ->and($result->imported)->toBe(1)
        ->and(Setting::query()->where('key', ImportOfficeReceipts::CURSOR_KEY)->value('value'))->toBe($first);
    Event::assertDispatched(BackupHasFailed::class, fn (BackupHasFailed $event) => $event->diskName === 'rclone:office-receipts');
});

it('lỗi rclone cat ở tệp đầu → dừng, không đọc tệp sau, cursor không đổi', function () {
    Event::fake([BackupHasFailed::class]);
    $first = Receipts::fileName('20261006T010000Z');
    $second = Receipts::fileName('20261007T010000Z');

    Receipts::fakeRclone([$first => Receipts::receipt([]), $second => Receipts::receipt([])], failCat: [$first]);

    $result = app(ImportOfficeReceipts::class)->handle();

    expect($result->rcloneFailed)->toBeTrue()
        ->and($result->imported)->toBe(0)
        ->and(Receipts::catted())->toBe([$first])
        ->and(Setting::query()->where('key', ImportOfficeReceipts::CURSOR_KEY)->exists())->toBeFalse();
});

it('lsjson và cat dùng thời gian chờ riêng 120 giây, không 1800 của sao lưu', function () {
    Receipts::fakeRclone([Receipts::fileName() => Receipts::receipt([])]);

    app(ImportOfficeReceipts::class)->handle();

    Process::assertRan(fn ($process) => in_array('lsjson', $process->command, true) && $process->timeout === 120);
    Process::assertRan(fn ($process) => in_array('cat', $process->command, true) && $process->timeout === 120);
    Process::assertDidntRun(fn ($process) => $process->timeout !== 120);
});

it('đọc đúng thư mục biên nhận đã cấu hình, và tệp theo đường của nó', function () {
    Receipts::fakeRclone([Receipts::fileName() => Receipts::receipt([])]);

    app(ImportOfficeReceipts::class)->handle();

    Process::assertRan(fn ($process) => array_slice($process->command, -2) === ['lsjson', Receipts::RECEIPTS_PATH]);
    Process::assertRan(fn ($process) => array_slice($process->command, -2) === ['cat', Receipts::RECEIPTS_PATH.'/'.Receipts::fileName()]);
});

it('chưa cấu hình máy văn phòng → không tiến trình nào', function () {
    config(['vkcrm.storage.office.receipts_path' => null]);
    Receipts::fakeRclone([Receipts::fileName() => Receipts::receipt([])]);

    $result = app(ImportOfficeReceipts::class)->handle();

    expect($result->configured)->toBeFalse();
    Process::assertNothingRan();
});

it('chưa cấu hình Shared Drive hay thư mục gốc → không tiến trình nào (không có gì để ràng biên nhận vào)', function (string $missing) {
    config([$missing => null]);
    Receipts::fakeRclone([Receipts::fileName() => Receipts::receipt([])]);

    $result = app(ImportOfficeReceipts::class)->handle();

    expect($result->configured)->toBeFalse();
    Process::assertNothingRan();
})->with([
    'shared drive' => 'vkcrm.storage.google_drive.shared_drive_id',
    'thư mục gốc' => 'vkcrm.storage.google_drive.root_folder_id',
]);

it('đang có lượt khác giữ khoá storage-office-receipts → không chạy gì', function () {
    Receipts::fakeRclone([Receipts::fileName() => Receipts::receipt([])]);
    $lock = Cache::lock(ImportOfficeReceipts::LOCK_KEY, 600);
    expect($lock->get())->toBeTrue();

    try {
        $result = app(ImportOfficeReceipts::class)->handle();
    } finally {
        $lock->release();
    }

    expect($result->busy)->toBeTrue();
    Process::assertNothingRan();
});

it('khoá chống chạy chồng hết hạn sau 600 giây', function () {
    expect(ImportOfficeReceipts::LOCK_KEY)->toBe('storage-office-receipts')
        ->and(ImportOfficeReceipts::LOCK_SECONDS)->toBe(600);
});
