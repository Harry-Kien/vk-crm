<?php

namespace App\Actions\Storage;

use App\Enums\DriveObjectRetirement;
use App\Support\Storage\DocumentStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Các con số của kho tài liệu (kế hoạch M14, Task 5): MỘT định nghĩa cho ba người đọc — dòng trạng
 * thái của `StorageReadiness`, kiểm tra sức khoẻ mỗi giờ, và trang "Kho tài liệu".
 *
 * Chỉ ĐẾM, không bao giờ trả một dòng: không mã media, không tên tệp, không mã Drive rời lớp này. Đọc
 * bằng query builder (`DB::table`), không qua model: không scope nào của cổng khách hay của thư viện
 * media chen vào một con số vận hành. Không gọi mạng.
 *
 * # Tệp mới và tệp cũ (R2)
 *
 * Mốc bật kho (`DocumentStore::remoteEnabledAt()`) chia các media còn ở vùng đệm (`disk = private`):
 *  - tạo TỪ mốc trở đi = tệp mới, tác vụ quét đẩy chúng lên kho; chờ quá `push_alert_minutes` là tồn
 *    đọng;
 *  - tạo TRƯỚC mốc (hoặc mọi tệp khi chưa có mốc) = tệp cũ, chỉ đi qua `vkcrm:storage:migrate`. Chúng
 *    được đếm riêng và không bao giờ là tồn đọng.
 *
 * Mốc được đổi về múi giờ ứng dụng trước khi so: cột `created_at` lưu giờ theo múi đó, còn mốc lưu
 * ISO-8601 có thể mang múi khác.
 */
final class MeasureDocumentStore
{
    /** Thùng rác của Shared Drive tự xoá sau chừng này ngày; tệp trong thùng rác vẫn tính vào số mục. */
    public const TRASH_RETENTION_DAYS = 30;

    /** Tệp mới (tạo từ mốc) còn ở vùng đệm; `$olderThanMinutes` chỉ đếm tệp đã chờ quá chừng đó phút. */
    public function pendingNewFiles(?int $olderThanMinutes = null): int
    {
        $query = $this->pendingQuery();

        if ($query === null) {
            return 0;
        }

        if ($olderThanMinutes !== null) {
            $query->where('created_at', '<=', now()->subMinutes($olderThanMinutes));
        }

        return $query->count();
    }

    /** Lúc tạo của tệp mới chờ đẩy lâu nhất, `null` khi không có. */
    public function oldestPendingAt(): ?CarbonImmutable
    {
        $oldest = $this->pendingQuery()?->min('created_at');

        return $oldest === null ? null : CarbonImmutable::parse($oldest, (string) config('app.timezone'));
    }

    /** Tệp cũ còn ở vùng đệm: tạo trước mốc, hoặc mọi tệp ở vùng đệm khi chưa có mốc. */
    public function legacyFiles(): int
    {
        $query = DB::table('media')->where('disk', DocumentStore::STAGING_DISK);
        $enabledAt = $this->enabledAt();

        if ($enabledAt !== null) {
            $query->where('created_at', '<', $enabledAt);
        }

        return $query->count();
    }

    /** Media đã ở kho mà bản trong vùng đệm còn được giữ (`local_purge_after` chưa về NULL, R10). */
    public function localCopiesKept(): int
    {
        return DB::table('media')
            ->where('disk', DocumentStore::REMOTE_DISK)
            ->whereNotNull('local_purge_after')
            ->count();
    }

    public function remoteMedia(): int
    {
        return DB::table('media')->where('disk', DocumentStore::REMOTE_DISK)->count();
    }

    /**
     * Media trên kho CHƯA có bản ngoài Google được xác nhận: không có dòng `drive_objects` SỐNG của
     * đúng khoá `<media_id>/<file_name>`, trên đúng Shared Drive đang cấu hình, cùng md5 với
     * `media.checksum_md5`, đã có `office_copied_at`. Cùng ba điều mà lượt dọn vùng đệm đòi (R10, điều
     * 3 và nửa đầu điều 4), nên con số này là số tệp mà vùng đệm còn phải giữ vì chưa có biên nhận.
     */
    public function remoteWithoutOfficeReceipt(): int
    {
        $driveId = (string) config('vkcrm.storage.google_drive.shared_drive_id');

        return DB::table('media')
            ->where('disk', DocumentStore::REMOTE_DISK)
            ->whereNotExists(fn (Builder $receipt) => $receipt->selectRaw('1')
                ->from('drive_objects')
                ->whereRaw('drive_objects.object_key = '.$this->mediaKeyExpression())
                ->whereColumn('drive_objects.md5', 'media.checksum_md5')
                ->where('drive_objects.drive_id', $driveId)
                ->whereNotNull('drive_objects.office_copied_at'))
            ->count();
    }

    /**
     * Số mục của Shared Drive đang cấu hình, đếm từ chỉ mục (không gọi mạng): mọi tệp còn trên Drive
     * (sống, hay bị thay khi dựng lại chỉ mục) cộng tệp vào thùng rác trong
     * {@see self::TRASH_RETENTION_DAYS} ngày gần nhất (giới hạn 400.000 mục có thể tính cả thùng rác),
     * cộng thư mục tháng. Tệp vào thùng rác lâu hơn đã bị Drive tự xoá.
     */
    public function driveItems(): int
    {
        $driveId = (string) config('vkcrm.storage.google_drive.shared_drive_id');

        $objects = DB::table('drive_objects')
            ->where('drive_id', $driveId)
            ->where(fn (Builder $query) => $query
                ->whereNull('retired_reason')
                ->orWhere('retired_reason', '!=', DriveObjectRetirement::Trashed->value)
                ->orWhere('retired_at', '>=', now()->subDays(self::TRASH_RETENTION_DAYS)))
            ->count();

        return $objects + DB::table('drive_folders')->where('drive_id', $driveId)->count();
    }

    private function pendingQuery(): ?Builder
    {
        $enabledAt = $this->enabledAt();

        if ($enabledAt === null) {
            return null;
        }

        return DB::table('media')
            ->where('disk', DocumentStore::STAGING_DISK)
            ->where('created_at', '>=', $enabledAt);
    }

    /** Mốc bật kho theo múi giờ ứng dụng, dạng chuỗi cột `created_at` so được. */
    private function enabledAt(): ?string
    {
        return DocumentStore::remoteEnabledAt()
            ?->setTimezone((string) config('app.timezone'))
            ->format('Y-m-d H:i:s');
    }

    /**
     * `<media.id>/<media.file_name>` bằng SQL của đúng CSDL đang dùng. Trên MariaDB/MySQL so theo
     * collation nhị phân, như cột `object_key` (khoá phân biệt hoa thường, migration
     * `create_drive_objects_table`).
     */
    private function mediaKeyExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'mariadb', 'mysql' => "CONCAT(media.id, '/', media.file_name) COLLATE utf8mb4_bin",
            default => "(media.id || '/' || media.file_name)",
        };
    }
}
