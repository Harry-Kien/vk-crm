<?php

use App\Actions\Storage\ImportOfficeReceipts;
use App\Actions\Storage\StorageReadiness;
use App\Enums\PreflightLevel;
use Illuminate\Support\Facades\Artisan;
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
| M14 Task 7 — lệnh tay `vkcrm:storage:office-receipts` (kế hoạch R10)
|--------------------------------------------------------------------------
|
| Lệnh gọi đúng `ImportOfficeReceipts` (cùng Action với mục lịch 07:00), dưới cùng khoá
| `storage-office-receipts`, rồi in số đếm bằng tiếng Việt. Chỉ số đếm và tên tệp biên nhận: không
| khoá đối tượng, không mã Drive.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Receipts::configure();
});

it('nhập biên nhận và in số đếm; mã thoát 0', function () {
    $key = Receipts::key();
    $row = DocumentStoreFixtures::driveObject(['object_key' => $key, 'md5' => md5('x'), 'size' => 10]);
    Receipts::fakeRclone([Receipts::fileName() => Receipts::receipt([
        Receipts::line($key, md5('x'), 10),
        ['name' => 'la.txt', 'md5' => md5('l'), 'size' => 1],
    ])]);

    $exit = Artisan::call('vkcrm:storage:office-receipts');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and(DB::table('drive_objects')->where('id', $row)->value('office_copied_at'))->not->toBeNull()
        ->and($output)->toContain('Biên nhận đã nhập: 1')
        ->and($output)->toContain('Tệp được đánh dấu có bản ở văn phòng: 1')
        ->and($output)->toContain('Tên tệp lạ: 1')
        ->and($output)->not->toContain($key)
        ->and($output)->not->toContain(FakeGoogleDrive::DRIVE_ID);
});

it('có biên nhận bị từ chối → in lý do kèm tên tệp biên nhận, mã thoát 1', function () {
    Receipts::fakeRclone([Receipts::fileName() => '{khong phai json']);

    $exit = Artisan::call('vkcrm:storage:office-receipts');

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain(Receipts::fileName());
});

it('lỗi rclone → thư lỗi sao lưu rclone:office-receipts, mã thoát 1', function () {
    Event::fake([BackupHasFailed::class]);
    Receipts::fakeRclone([], failList: true);

    expect(Artisan::call('vkcrm:storage:office-receipts'))->toBe(1)
        ->and(Artisan::output())->toContain('rclone');
    Event::assertDispatched(BackupHasFailed::class, fn (BackupHasFailed $event) => $event->diskName === 'rclone:office-receipts');
});

it('chưa cấu hình → nói rõ, không tiến trình nào, mã thoát 0; document_office_copy VÀNG', function () {
    config(['vkcrm.storage.office.receipts_path' => null]);
    Receipts::fakeRclone([Receipts::fileName() => Receipts::receipt([])]);

    $exit = Artisan::call('vkcrm:storage:office-receipts');

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('DOCUMENT_OFFICE_RECEIPTS_PATH');
    Process::assertNothingRan();

    $row = DocumentStoreFixtures::find(app(StorageReadiness::class)->stateRows(), 'document_office_copy');
    expect($row['level'])->toBe(PreflightLevel::Yellow);
});

it('lệnh tay chờ cùng khoá với mục lịch: khoá đang bị giữ → không chạy, mã thoát 1', function () {
    Receipts::fakeRclone([Receipts::fileName() => Receipts::receipt([])]);
    $lock = Cache::lock(ImportOfficeReceipts::LOCK_KEY, 600);
    $lock->get();

    try {
        $exit = Artisan::call('vkcrm:storage:office-receipts');
    } finally {
        $lock->release();
    }

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('đang chạy');
    Process::assertNothingRan();
});

it('nhập xong thì dòng document_office_copy XANH', function () {
    Receipts::fakeRclone([Receipts::fileName() => Receipts::receipt([])]);

    Artisan::call('vkcrm:storage:office-receipts');

    $row = DocumentStoreFixtures::find(app(StorageReadiness::class)->stateRows(), 'document_office_copy');
    expect($row['level'])->toBe(PreflightLevel::Green);
});
