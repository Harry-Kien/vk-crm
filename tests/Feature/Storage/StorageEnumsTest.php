<?php

use App\Enums\DocumentStoreStatus;
use App\Enums\DriveObjectRetirement;
use App\Enums\PushOutcome;

/*
|--------------------------------------------------------------------------
| M14 Task 1 — ba enum của kho tài liệu
|--------------------------------------------------------------------------
|
| Nhãn của mọi case đã được kiểm chung ở `tests/Unit/EnumLabelsTest.php` và `ArchitectureTest`
| ("mọi enum đều là backed string và có nhãn tiếng Việt"), vì cả hai quét `app/Enums/`. Ở đây chỉ
| ghim GIÁ TRỊ: chúng nằm trong CSDL (`system_health.document_store_status`,
| `drive_objects.retired_reason`) và đổi một giá trị là làm mồ côi mọi dòng đã ghi.
*/

it('các case và giá trị đúng kế hoạch', function (string $enum, array $values) {
    expect(array_map(fn (BackedEnum $case) => $case->value, $enum::cases()))->toBe($values);
})->with([
    'DocumentStoreStatus' => [DocumentStoreStatus::class, ['ok', 'degraded', 'unavailable', 'misconfigured']],
    'DriveObjectRetirement' => [DriveObjectRetirement::class, ['trashed', 'superseded']],
    // `rejected`: thêm ở Task 3 — khoá lệch khuôn R4 (hay media ở một đĩa lạ) thì không đẩy, và thử lại
    // không đổi được gì (khác `locked`, khác lỗi tạm thời).
    'PushOutcome' => [PushOutcome::class, ['pushed', 'already_remote', 'gone', 'disabled', 'locked', 'rejected']],
]);

it('mỗi case có nhãn tiếng Việt riêng, và giá trị vừa cột string(20) chứa nó', function (string $enum) {
    $labels = array_map(fn ($case) => $case->label(), $enum::cases());

    expect($labels)->each->not->toStartWith('enums.')
        ->and(array_unique($labels))->toHaveCount(count($labels));

    foreach ($enum::cases() as $case) {
        expect(strlen($case->value))->toBeLessThanOrEqual(20);
    }
})->with([DocumentStoreStatus::class, DriveObjectRetirement::class, PushOutcome::class]);
