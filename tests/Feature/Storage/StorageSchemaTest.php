<?php

use App\Enums\DriveObjectRetirement;
use App\Models\DriveFolder;
use App\Models\DriveObject;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| M14 Task 1 — bốn migration của kho tài liệu (kế hoạch M14, "Mô hình dữ liệu")
|--------------------------------------------------------------------------
|
| Đo trên CSDL đang chạy (`Schema::getColumns()`/`getIndexes()`), không đọc tệp migration. Tệp này
| chạy cả trên SQLite (bộ test thường) lẫn MariaDB (`test:mariadb`): trên MariaDB kiểu được so ĐỦ
| độ dài (`varchar(128)`, `char(32)`), vì MariaDB strict cắt hoặc từ chối giá trị dài hơn cột; trên
| SQLite chỉ so họ kiểu, vì SQLite không giữ độ dài khai báo.
*/

/**
 * Kiểu mong đợi của một cột: `[mẫu kiểu MariaDB, kiểu SQLite, nullable]`. Mẫu MariaDB cho phép độ
 * rộng hiển thị của số nguyên có hoặc không (`bigint(20) unsigned` / `bigint unsigned`), vì nó đổi
 * theo phiên bản máy chủ mà không đổi kiểu.
 *
 * @return array<string, array{0: string, 1: string, 2: bool}>
 */
function expectedStorageColumns(string $table): array
{
    $bigUnsigned = '/^bigint(\(\d+\))? unsigned$/';
    $varchar = fn (int $length): string => '/^varchar\('.$length.'\)$/';
    $char = fn (int $length): string => '/^char\('.$length.'\)$/';
    $timestamp = '/^timestamp$/';

    return match ($table) {
        'drive_objects' => [
            'id' => [$bigUnsigned, 'integer', false],
            'drive_id' => [$varchar(128), 'varchar', false],
            'object_key' => [$varchar(255), 'varchar', true],
            'generation' => ['/^smallint(\(\d+\))? unsigned$/', 'integer', false],
            'former_key' => [$varchar(255), 'varchar', true],
            'retired_reason' => [$varchar(20), 'varchar', true],
            'retired_at' => [$timestamp, 'datetime', true],
            'file_id' => [$varchar(128), 'varchar', false],
            'parent_id' => [$varchar(128), 'varchar', false],
            'size' => [$bigUnsigned, 'integer', false],
            'md5' => [$char(32), 'varchar', false],
            'mime_type' => [$varchar(255), 'varchar', true],
            'office_copied_at' => [$timestamp, 'datetime', true],
            'created_at' => [$timestamp, 'datetime', true],
            'updated_at' => [$timestamp, 'datetime', true],
        ],
        'drive_folders' => [
            'id' => [$bigUnsigned, 'integer', false],
            'drive_id' => [$varchar(128), 'varchar', false],
            'root_folder_id' => [$varchar(128), 'varchar', false],
            'name' => [$varchar(20), 'varchar', false],
            'folder_id' => [$varchar(128), 'varchar', false],
            'created_at' => [$timestamp, 'datetime', true],
            'updated_at' => [$timestamp, 'datetime', true],
        ],
        'media' => [
            'remote_pushed_at' => [$timestamp, 'datetime', true],
            'local_purge_after' => [$timestamp, 'datetime', true],
            'checksum_md5' => [$char(32), 'varchar', true],
            'checksum_sha256' => [$char(64), 'varchar', true],
        ],
        'system_health' => [
            'document_store_status' => [$varchar(20), 'varchar', true],
            'document_store_checked_at' => [$timestamp, 'datetime', true],
            'document_store_detail' => ['/^text$/', 'text', true],
            'last_office_receipt_at' => [$timestamp, 'datetime', true],
            'last_office_receipt_error' => ['/^text$/', 'text', true],
        ],
    };
}

it('các cột mới có đúng kiểu, độ dài và nullable', function (string $table) {
    $columns = collect(Schema::getColumns($table))->keyBy('name');
    $onSqlite = DB::connection()->getDriverName() === 'sqlite';

    foreach (expectedStorageColumns($table) as $name => [$mariadbType, $sqliteType, $nullable]) {
        expect($columns->has($name))->toBeTrue("{$table}.{$name} không tồn tại");

        $column = $columns[$name];

        $onSqlite
            ? expect($column['type'])->toBe($sqliteType, "{$table}.{$name}")
            : expect($column['type'])->toMatch($mariadbType, "{$table}.{$name}");

        expect($column['nullable'])->toBe($nullable, "{$table}.{$name} nullable");
    }
})->with(['drive_objects', 'drive_folders', 'media', 'system_health']);

/**
 * Bảng chỉ mục là của hạ tầng, đúng các cột của kế hoạch — không thừa cột nào (một cột
 * `web_view_link` hay `permission_id` ở đây là một đường tới tệp nằm ngoài route tải ký, R3/R5).
 */
it('drive_objects và drive_folders không có cột nào ngoài mô hình dữ liệu', function (string $table) {
    expect(collect(Schema::getColumns($table))->pluck('name')->sort()->values()->all())
        ->toBe(collect(array_keys(expectedStorageColumns($table)))->sort()->values()->all());
})->with(['drive_objects', 'drive_folders']);

it('chỉ mục và ràng buộc unique đúng mô hình dữ liệu', function (string $table, array $columns, bool $unique) {
    $match = collect(Schema::getIndexes($table))
        ->first(fn (array $index): bool => $index['columns'] === $columns && ! $index['primary']);

    expect($match)->not->toBeNull($table.'('.implode(', ', $columns).') thiếu chỉ mục')
        ->and($match['unique'])->toBe($unique, $table.'('.implode(', ', $columns).') unique');
})->with([
    'drive_objects.object_key unique' => ['drive_objects', ['object_key'], true],
    'drive_objects.file_id unique' => ['drive_objects', ['file_id'], true],
    'drive_objects.drive_id' => ['drive_objects', ['drive_id'], false],
    'drive_objects.former_key' => ['drive_objects', ['former_key'], false],
    'drive_objects.retired_at' => ['drive_objects', ['retired_at'], false],
    'drive_objects.office_copied_at' => ['drive_objects', ['office_copied_at'], false],
    'drive_folders.folder_id unique' => ['drive_folders', ['folder_id'], true],
    'drive_folders.(root_folder_id, name) unique' => ['drive_folders', ['root_folder_id', 'name'], true],
    'drive_folders.drive_id' => ['drive_folders', ['drive_id'], false],
    'media.local_purge_after' => ['media', ['local_purge_after'], false],
    'media.(disk, created_at)' => ['media', ['disk', 'created_at'], false],
    'media.(disk, local_purge_after)' => ['media', ['disk', 'local_purge_after'], false],
]);

/** @param  array<string, mixed>  $overrides */
function makeDriveObject(array $overrides = []): DriveObject
{
    static $sequence = 0;
    $sequence++;

    return DriveObject::query()->create(array_merge([
        'drive_id' => '0AbCdEfGhIjKlUk9PVA',
        'object_key' => $sequence.'/01k6xq0f9m2y7c4w8r3t5v6n1b.pdf',
        'file_id' => 'file-'.$sequence.'-aBcDeFgHiJkLmNoPqRsTuVwXyZ',
        'parent_id' => 'thang-2026-10',
        'size' => 832,
        'md5' => str_repeat('a', 32),
        'mime_type' => 'application/pdf',
    ], $overrides));
}

it('thế hệ mặc định là 1', function () {
    expect(makeDriveObject()->fresh()->generation)->toBe(1);
});

/**
 * R8: dòng đã rời chỉ mục sống (`object_key = NULL`, `former_key` giữ khoá cũ) — nhiều dòng như
 * vậy cùng tồn tại cho cùng một khoá (mỗi thế hệ hỏng một dòng). MariaDB cho nhiều NULL trong
 * unique, nên không cần partial index.
 */
it('hai dòng cùng object_key NULL được phép (dòng đã vào thùng rác hay bị thay)', function () {
    makeDriveObject(['object_key' => null, 'former_key' => '18/01k6xq0f9m2y7c4w8r3t5v6n1b.pdf', 'retired_reason' => DriveObjectRetirement::Trashed, 'retired_at' => now()]);
    makeDriveObject(['object_key' => null, 'former_key' => '18/01k6xq0f9m2y7c4w8r3t5v6n1b.pdf', 'retired_reason' => DriveObjectRetirement::Superseded, 'retired_at' => now()]);

    expect(DriveObject::query()->whereNull('object_key')->count())->toBe(2)
        ->and(DriveObject::query()->whereNull('object_key')->pluck('retired_reason')->all())
        ->toEqualCanonicalizing([DriveObjectRetirement::Trashed, DriveObjectRetirement::Superseded]);
});

it('hai dòng sống cùng object_key bị từ chối', function () {
    makeDriveObject(['object_key' => '18/01k6xq0f9m2y7c4w8r3t5v6n1b.pdf']);

    expect(fn () => makeDriveObject(['object_key' => '18/01k6xq0f9m2y7c4w8r3t5v6n1b.pdf']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('hai dòng cùng file_id bị từ chối', function () {
    makeDriveObject(['file_id' => '1aBcDeFgHiJkLmNoPqRsTuVwXyZ012345']);

    expect(fn () => makeDriveObject(['file_id' => '1aBcDeFgHiJkLmNoPqRsTuVwXyZ012345']))
        ->toThrow(UniqueConstraintViolationException::class);
});

/**
 * Mã tệp và mã thư mục của Drive phân biệt hoa thường. Với collation mặc định của dự án
 * (`utf8mb4_unicode_ci`) MariaDB coi hai mã chỉ khác hoa thường là TRÙNG: ràng buộc unique từ chối
 * mã thứ hai, và `where('file_id', …)` trả nhầm dòng. Các cột mã Drive và khoá vì thế dùng
 * collation nhị phân trên MariaDB; SQLite vốn so nhị phân. Test có nghĩa trên `test:mariadb`.
 */
it('mã Drive chỉ khác hoa thường là hai mã khác nhau, và tìm theo mã không trả nhầm dòng', function () {
    makeDriveObject(['file_id' => '1aBcDeFgHiJkLmNoPqRsTuVwXyZ012345']);
    $second = makeDriveObject(['file_id' => '1ABCDEFGHIJKLMNOPQRSTUVWXYZ012345']);

    DriveFolder::query()->create(['drive_id' => 'd', 'root_folder_id' => 'r', 'name' => '2026-10', 'folder_id' => 'fOlDeR']);
    DriveFolder::query()->create(['drive_id' => 'd', 'root_folder_id' => 'R', 'name' => '2026-10', 'folder_id' => 'FOLDER']);

    expect(DriveObject::query()->where('file_id', '1ABCDEFGHIJKLMNOPQRSTUVWXYZ012345')->pluck('id')->all())
        ->toBe([$second->id])
        ->and(DriveFolder::query()->count())->toBe(2);
});

it('drive_folders: một tên tháng chỉ có một thư mục dưới cùng một thư mục gốc', function () {
    DriveFolder::query()->create(['drive_id' => 'd', 'root_folder_id' => 'goc-1', 'name' => '2026-10', 'folder_id' => 'thu-muc-1']);
    DriveFolder::query()->create(['drive_id' => 'd', 'root_folder_id' => 'goc-2', 'name' => '2026-10', 'folder_id' => 'thu-muc-2']);

    expect(fn () => DriveFolder::query()->create(['drive_id' => 'd', 'root_folder_id' => 'goc-1', 'name' => '2026-10', 'folder_id' => 'thu-muc-3']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('DriveObject ép kiểu: thế hệ và cỡ là số, lý do rời chỉ mục là enum, các mốc là thời điểm', function () {
    $object = makeDriveObject([
        'object_key' => null,
        'former_key' => '18/01k6xq0f9m2y7c4w8r3t5v6n1b.pdf',
        'generation' => 3,
        'size' => 2147483648,
        'retired_reason' => DriveObjectRetirement::Trashed,
        'retired_at' => '2026-10-04 10:00:00',
    ]);
    $object->forceFill(['office_copied_at' => '2026-10-05 07:00:00'])->save();
    $object = $object->fresh();

    expect($object->generation)->toBe(3)
        ->and($object->size)->toBe(2147483648)
        ->and($object->retired_reason)->toBe(DriveObjectRetirement::Trashed)
        ->and($object->retired_at?->toDateTimeString())->toBe('2026-10-04 10:00:00')
        ->and($object->office_copied_at?->toDateTimeString())->toBe('2026-10-05 07:00:00');
});
