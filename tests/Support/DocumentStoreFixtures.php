<?php

namespace Tests\Support;

use App\Actions\Storage\StorageReadiness;
use App\Enums\PreflightLevel;
use App\Models\Setting;
use App\Support\Storage\DocumentStore;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * M14 Task 5 — dữ liệu và phép đo dùng chung cho test của kiểm tra sẵn sàng, kiểm tra sức khoẻ và
 * trang "Kho tài liệu". Lớp tĩnh chứ không hàm Pest toàn cục: hai tệp test cùng khai một hàm toàn cục
 * thì bộ test song song vỡ ở tệp nạp sau.
 *
 * Dòng `media` và `drive_objects` được chèn THẲNG vào bảng: các con số của kho (tồn đọng, bản cục
 * bộ, biên nhận văn phòng) chỉ đọc hai bảng này, và dựng media thật qua thư viện media sẽ ghi tệp,
 * điều mà các test này không đo.
 */
final class DocumentStoreFixtures
{
    public static function setting(string $key, ?string $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** Công tắc `google_drive` + mốc bật kho (việc `vkcrm:storage:enable` của Task 6 sẽ làm). */
    public static function enableRemote(CarbonInterface $at): void
    {
        config(['vkcrm.storage.driver' => DocumentStore::DRIVER_GOOGLE_DRIVE]);
        self::setting(DocumentStore::REMOTE_ENABLED_AT_KEY, $at->toIso8601String());
    }

    /** Một dòng `media`; trả id. Mặc định: tệp ở vùng đệm, tạo lúc này. */
    public static function media(array $overrides = []): int
    {
        return (int) DB::table('media')->insertGetId(array_merge([
            'model_type' => 'document',
            'model_id' => 1,
            'uuid' => (string) Str::uuid(),
            'collection_name' => 'file',
            'name' => 'ho-so',
            'file_name' => strtolower((string) Str::ulid()).'.pdf',
            'mime_type' => 'application/pdf',
            'disk' => DocumentStore::STAGING_DISK,
            'conversions_disk' => DocumentStore::STAGING_DISK,
            'size' => 1024,
            'manipulations' => '[]',
            'custom_properties' => '[]',
            'generated_conversions' => '[]',
            'responsive_images' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /** Một media đã ở kho, kèm dòng chỉ mục sống của nó; `$receipt` = lúc có biên nhận văn phòng. */
    public static function remoteMedia(?CarbonInterface $receipt = null, array $media = [], array $object = []): int
    {
        $md5 = $media['checksum_md5'] ?? md5('noi dung '.Str::random(8));

        $id = self::media(array_merge([
            'disk' => DocumentStore::REMOTE_DISK,
            'conversions_disk' => DocumentStore::REMOTE_DISK,
            'remote_pushed_at' => now(),
            'checksum_md5' => $md5,
        ], $media));

        $fileName = DB::table('media')->where('id', $id)->value('file_name');

        self::driveObject(array_merge([
            'object_key' => $id.'/'.$fileName,
            'md5' => $md5,
            'office_copied_at' => $receipt,
        ], $object));

        return $id;
    }

    /** Một dòng `drive_objects` trên Shared Drive giả; trả id. */
    public static function driveObject(array $overrides = []): int
    {
        return (int) DB::table('drive_objects')->insertGetId(array_merge([
            'drive_id' => FakeGoogleDrive::DRIVE_ID,
            'object_key' => null,
            'generation' => 1,
            'file_id' => 'f'.Str::random(20),
            'parent_id' => FakeGoogleDrive::ROOT_FOLDER_ID,
            'size' => 1024,
            'md5' => md5(Str::random(8)),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /** @param  list<array{key: string, level: PreflightLevel, message: string}>  $rows */
    public static function find(array $rows, string $key): ?array
    {
        foreach ($rows as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }

        return null;
    }

    /** Dòng `$key` của `StorageReadiness::rows()`, bắt buộc có. */
    public static function readiness(string $key): array
    {
        $row = self::find(app(StorageReadiness::class)->rows(), $key);

        expect($row)->not->toBeNull("rows() thiếu dòng {$key}");

        return $row;
    }

    /** Dòng `$key` của `StorageReadiness::stateRows()`, hoặc null. */
    public static function state(string $key): ?array
    {
        return self::find(app(StorageReadiness::class)->stateRows(), $key);
    }

    /** @return array{0: int, 1: string} mã thoát và đầu ra của `vkcrm:storage:check` */
    public static function check(): array
    {
        $exit = Artisan::call('vkcrm:storage:check');

        return [$exit, Artisan::output()];
    }

    /**
     * Cùng một dòng qua HAI đường của kế hoạch (Task 5, "Test bắt buộc"): `StorageReadiness` và lệnh
     * `vkcrm:storage:check`. Lệnh in `[MỨC] khoá: câu`, nên đầu ra phải mang đúng mức và khoá.
     */
    public static function expectRow(string $key, PreflightLevel $level, bool $state = false): array
    {
        $row = $state ? self::state($key) : self::readiness($key);

        expect($row)->not->toBeNull("thiếu dòng {$key}")
            ->and($row['level'])->toBe($level, "dòng {$key}: ".($row['message'] ?? ''));

        [, $output] = self::check();

        expect($output)->toContain('['.$level->label().'] '.$key.':');

        return $row;
    }
}
