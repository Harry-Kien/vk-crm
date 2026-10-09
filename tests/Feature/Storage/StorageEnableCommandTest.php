<?php

use App\Models\Setting;
use App\Support\Storage\DocumentStore;
use App\Support\Storage\TransferDossier;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Support\DocumentStoreFixtures as Store;
use Tests\Support\FakeGoogleDrive;
use Tests\Support\StorageCommandFixtures as Ops;

/*
|--------------------------------------------------------------------------
| M14 Task 6 — `vkcrm:storage:enable`: bật kho có mốc, sau kiểm sẵn sàng và cổng pháp lý (R2, R11, R13)
|--------------------------------------------------------------------------
|
| Công tắc `DOCUMENT_STORAGE=google_drive` chỉ CHO PHÉP; lệnh này mới BẬT, bằng cách ghi
| `settings.storage.remote_enabled_at`. Mã thoát: 0 bật xong hoặc đã bật từ trước; 2 khi một điều kiện
| tiên quyết không đạt. Kiểm tra sẵn sàng là thật: máy chủ Drive giả trên `Http::fake()`.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 20:00:00', 'Asia/Ho_Chi_Minh'));
});

function t6EnabledAt(): ?string
{
    return Setting::query()->where('key', DocumentStore::REMOTE_ENABLED_AT_KEY)->value('value');
}

it('công tắc local → mã 2, không mốc, không request nào tới Drive', function () {
    Ops::ready();

    [$exit, $output] = Ops::artisan('vkcrm:storage:enable');

    expect($exit)->toBe(2)
        ->and($output)->toContain('DOCUMENT_STORAGE')
        ->and(t6EnabledAt())->toBeNull()
        ->and(Ops::audits('document_store_enabled'))->toBe([]);
    Http::assertNothingSent();
});

it('công tắc gõ sai → mã 2 (chỉ đúng chuỗi google_drive mới bật được)', function () {
    Ops::ready();
    config(['vkcrm.storage.driver' => 'Google_Drive']);

    [$exit] = Ops::artisan('vkcrm:storage:enable');

    expect($exit)->toBe(2)->and(t6EnabledAt())->toBeNull();
});

it('kiểm tra sẵn sàng có dòng ĐỎ → mã 2, không mốc; câu in dòng đỏ', function () {
    $drive = Ops::ready();
    $drive->files[FakeGoogleDrive::ROOT_FOLDER_ID]['trashed'] = true;
    config(['vkcrm.storage.driver' => DocumentStore::DRIVER_GOOGLE_DRIVE]);

    [$exit, $output] = Ops::artisan('vkcrm:storage:enable');

    expect($exit)->toBe(2)
        ->and($output)->toContain('drive_root_folder:')
        ->and(t6EnabledAt())->toBeNull()
        ->and(Ops::audits('document_store_enabled'))->toBe([]);
});

it('sẵn sàng XANH, ngoài production → ghi mốc bằng giờ hiện tại, một dòng audit, mã 0', function () {
    Ops::ready();
    config(['vkcrm.storage.driver' => DocumentStore::DRIVER_GOOGLE_DRIVE]);

    [$exit, $output] = Ops::artisan('vkcrm:storage:enable');

    expect($exit)->toBe(0, $output)
        ->and(CarbonImmutable::parse(t6EnabledAt())->equalTo(now()))->toBeTrue()
        ->and(DocumentStore::pushesNewFiles())->toBeTrue()
        ->and(Ops::audits('document_store_enabled'))->toHaveCount(1)
        ->and(Ops::audits('document_store_enabled')[0]->properties->get('remote_enabled_at'))->toBe(t6EnabledAt());
});

it('production thiếu cổng pháp lý (không ngày hồ sơ, không ý kiến cho chuyển trước) → mã 2, không mốc', function () {
    Ops::ready();
    config(['vkcrm.storage.driver' => DocumentStore::DRIVER_GOOGLE_DRIVE, 'app.env' => 'production']);

    [$exit, $output] = Ops::artisan('vkcrm:storage:enable');

    expect($exit)->toBe(2)
        ->and($output)->toContain('Kho tài liệu')
        ->and(t6EnabledAt())->toBeNull();
});

it('production có ngày hồ sơ, hoặc có ý kiến luật sư cho chuyển trước → bật được', function (string $field) {
    Ops::ready();
    config(['vkcrm.storage.driver' => DocumentStore::DRIVER_GOOGLE_DRIVE, 'app.env' => 'production']);
    Store::setting(TransferDossier::KEYS[$field], '2026-10-01');

    [$exit, $output] = Ops::artisan('vkcrm:storage:enable');

    expect($exit)->toBe(0, $output)->and(t6EnabledAt())->not->toBeNull();
})->with(['transfer_dossier_on', 'transfer_before_dossier_on']);

it('chạy lại khi đã bật → mã 0, in mốc cũ, mốc KHÔNG dời, không audit thứ hai', function () {
    Ops::ready();
    config(['vkcrm.storage.driver' => DocumentStore::DRIVER_GOOGLE_DRIVE]);

    Ops::artisan('vkcrm:storage:enable');
    $first = t6EnabledAt();

    $this->travel(3)->days();
    $requestsBefore = count(Http::recorded());
    [$exit, $output] = Ops::artisan('vkcrm:storage:enable');

    expect($exit)->toBe(0)
        ->and(t6EnabledAt())->toBe($first)
        ->and($output)->toContain('20:00 07/10/2026')
        ->and(Ops::audits('document_store_enabled'))->toHaveCount(1)
        // Đã bật thì trả lời ngay, không kiểm sẵn sàng lại (không tệp thăm dò nào nữa).
        ->and(count(Http::recorded()))->toBe($requestsBefore);
});

it('hai lượt bật chạy chồng: lượt thứ hai không dời mốc của lượt đầu (câu ghi chỉ ghi khi giá trị còn trống)', function () {
    $drive = Ops::ready();
    config(['vkcrm.storage.driver' => DocumentStore::DRIVER_GOOGLE_DRIVE]);
    $first = now()->subHour()->toIso8601String();

    // Lượt kia ghi mốc SAU khi lượt này đọc "chưa bật" — ngay lúc lượt này đang kiểm sẵn sàng.
    $drive->respondNext('GET', '/drives/', function () use ($drive, $first) {
        Setting::query()->updateOrCreate(['key' => DocumentStore::REMOTE_ENABLED_AT_KEY], ['value' => $first]);

        return Factory::response($drive->drive);
    });

    [$exit] = Ops::artisan('vkcrm:storage:enable');

    expect($exit)->toBe(0)
        ->and(t6EnabledAt())->toBe($first)
        ->and(Ops::audits('document_store_enabled'))->toBe([]);
});
