<?php

namespace App\Support\Storage\GoogleDrive;

use App\Enums\DriveObjectRetirement;
use App\Models\DriveObject;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

/**
 * Chỉ mục `drive_objects`: khoá đối tượng → tệp Google Drive (kế hoạch M14, R4, R8). Mọi câu hỏi
 * không cần byte của tệp — tồn tại, kích thước, loại, liệt kê — trả lời từ đây, không gọi mạng, nên
 * một lượt tải xuống tốn đúng MỘT lệnh gọi Google.
 *
 * - Dòng SỐNG có `object_key`; dòng đã rời (thùng rác, bị thay) có `object_key = NULL`, khoá cũ ở
 *   `former_key`. Không dòng nào bị xoá.
 * - Tra theo khoá, không lọc theo `drive_id`: `object_key` là unique trên mọi Shared Drive, và dòng
 *   của một Shared Drive cũ chỉ nhường chỗ qua `vkcrm:storage:reindex` (R11).
 * - **Thư mục là tiền tố có `/`** (R4): thư mục `d` (bỏ `/` cuối) gồm đúng các khoá sống thoả
 *   `object_key LIKE '<d đã thoát>/%' ESCAPE '!'`. Không có `/` thì `18` khớp cả `180/…`, `1800/…`, và
 *   `DefaultFileRemover` (gọi `allFiles('18/')`, `deleteDirectory('18/')` ở MỌI lượt xoá media) cho
 *   tệp của media khác vào thùng rác. `%`, `_` và chính ký tự thoát được thoát. Ký tự thoát là `!`,
 *   không phải `\` như bản kế hoạch: `'\'` là một chuỗi chưa đóng trên MariaDB (gạch chéo ngược là ký
 *   tự thoát của chuỗi SQL), còn `'\\'` là hai ký tự trên SQLite (ESCAPE đòi đúng một) — `!` viết
 *   giống nhau trên cả hai.
 * - Trên MariaDB cột khoá dùng collation nhị phân (migration `drive_objects`), nên `=` và `LIKE` so
 *   từng byte. SQLite so `LIKE` không phân biệt hoa thường với chữ ASCII; khoá thư viện media vốn
 *   viết thường, nên khác biệt đó không chạm dữ liệu thật.
 * - **Không phụ thuộc guard nào đang mở**: mọi truy vấn đi qua {@see self::query()}, bỏ
 *   `ClientPortalScope` — trong phiên khách của cổng, scope đó làm chỉ mục RỖNG.
 */
final class DriveObjectIndex
{
    private const LIKE_ESCAPE = '!';

    public function live(string $key): ?DriveObject
    {
        return $this->query()->where('object_key', $key)->first();
    }

    /**
     * Thế hệ cho lần tải kế tiếp của một khoá chưa có dòng sống: 1 + thế hệ CAO NHẤT trong các dòng
     * đã rời của khoá đó (`former_key` = khoá); chưa từng có thì 1.
     *
     * Kế hoạch viết "1 + số dòng đã rời". Hai cách bằng nhau khi các thế hệ liền nhau (mỗi lần ghi
     * lại sau thùng rác tăng đúng một). Khi không liền nhau — dựng lại chỉ mục chỉ nhận thế hệ cao
     * nhất có md5 khớp (R11), nên chỉ mục có thể chỉ biết thế hệ 5 — thì đếm trả 2, một tên CÓ THỂ
     * đã nằm trên Drive và ở máy văn phòng: đúng điều số thế hệ có mặt để tránh (R4).
     */
    public function nextGeneration(string $key): int
    {
        return 1 + (int) $this->query()->where('former_key', $key)->max('generation');
    }

    /**
     * Các dòng sống dưới một thư mục, theo khoá. `''` là gốc: mọi dòng sống.
     *
     * @return LazyCollection<int, DriveObject>
     */
    public function liveUnder(string $directory): LazyCollection
    {
        return $this->underQuery($directory)->orderBy('object_key')->cursor();
    }

    public function hasLiveUnder(string $directory): bool
    {
        return $this->underQuery($directory)->exists();
    }

    /**
     * Ghi dòng sống cho một tệp vừa có trên Drive.
     *
     * @param  array{id: string, size: int|string, md5Checksum: string, mimeType?: ?string}  $file
     */
    public function record(string $driveId, string $key, int $generation, string $parentId, array $file, ?string $mimeType): DriveObject
    {
        return $this->query()->create([
            'drive_id' => $driveId,
            'object_key' => $key,
            'generation' => $generation,
            'file_id' => $file['id'],
            'parent_id' => $parentId,
            'size' => (int) $file['size'],
            'md5' => $file['md5Checksum'],
            'mime_type' => $mimeType,
        ]);
    }

    /** Dòng rời chỉ mục sống: `object_key = NULL`, `former_key` giữ khoá, lý do và lúc. */
    public function retire(DriveObject $object, DriveObjectRetirement $reason): void
    {
        $this->query()
            ->whereKey($object->getKey())
            ->where('object_key', $object->object_key)
            ->update([
                'object_key' => null,
                'former_key' => $object->object_key,
                'retired_reason' => $reason->value,
                'retired_at' => now(),
            ]);
    }

    /** Dòng sống đổi sang khoá mới (đổi tên trên Drive), với thế hệ của tên mới. */
    public function rekey(DriveObject $object, string $key, int $generation): void
    {
        $this->query()
            ->whereKey($object->getKey())
            ->where('object_key', $object->object_key)
            ->update(['object_key' => $key, 'generation' => $generation]);
    }

    /**
     * Mọi truy vấn của chỉ mục đi qua đây, và BỎ `ClientPortalScope`.
     *
     * `DriveObject` mang scope cổng khách (`1 = 0`, Task 1), và route `documents.download` nằm ngoài
     * panel Filament: khi khách tải từ cổng (guard `client` mở, guard `web` không), scope đó KÍCH
     * HOẠT ngay trong adapter. Không bỏ nó thì với khách chỉ mục rỗng: `fileExists()` trả false nên
     * controller trả 404 cho MỌI lượt tải tệp đã đẩy (R3, R11), `nextGeneration()` trả 1 và dùng lại
     * một tên đã vào thùng rác (R4), `live()` bỏ qua kiểm khoá bất biến (R8), `retire()`/`rekey()`
     * cập nhật 0 dòng.
     *
     * Bỏ scope ở đây không mở dòng nào cho khách. Adapter là hạ tầng, và quyết định ai được đọc tệp
     * nào thuộc về người gọi nó, TRƯỚC khi chạm đĩa: route tải kiểm chữ ký, mã người nhận và
     * `Gate::allows('download')` (404) rồi mới gọi `exists()`/`readStream()` (R3). Không dòng chỉ mục
     * nào rời adapter — khách chỉ nhận byte của đúng tệp đã được phép. Mọi truy vấn khác lên
     * `DriveObject` vẫn bị scope cắt. Gỡ đúng MỘT scope (`withoutGlobalScope`, không phải
     * `withoutGlobalScopes()`), để scope nào thêm sau vẫn áp — hôm nay model không có scope nào
     * khác, nên không test nào phân biệt hai cách đó.
     *
     * @return Builder<DriveObject>
     */
    private function query(): Builder
    {
        return DriveObject::query()->withoutGlobalScope(ClientPortalScope::class);
    }

    /** @return Builder<DriveObject> */
    private function underQuery(string $directory): Builder
    {
        $query = $this->query()->whereNotNull('object_key');

        if ($directory !== '') {
            $escaped = str_replace(
                [self::LIKE_ESCAPE, '%', '_'],
                [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'],
                $directory,
            );

            $query->whereRaw("object_key LIKE ? ESCAPE '".self::LIKE_ESCAPE."'", [$escaped.'/%']);
        }

        return $query;
    }
}
