<?php

namespace App\Actions\Storage;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\Matter\RecordMatterDestruction;
use App\Models\DriveFolder;
use App\Models\DriveObject;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\User;
use App\Support\Audit;
use App\Support\Storage\DocumentStore;
use App\Support\Storage\GoogleDrive\DriveObjectName;
use App\Support\Storage\StagedCopy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Liệt kê nơi còn tệp của một vụ đã ghi quyết định tiêu huỷ — `vkcrm:storage:destruction-list
 * {matter} --by=<email>` (kế hoạch M14, R15; sổ tay "Huỷ tệp của hồ sơ đã quá hạn lưu" trong
 * `docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md`).
 *
 * CRM vẫn KHÔNG xoá gì ({@see RecordMatterDestruction}: việc huỷ vật lý là thao tác có biên bản, ngoài
 * hệ thống). Với kho thì người vận hành cũng không xoá được bằng CRM: tài khoản dịch vụ chỉ cho vào
 * thùng rác, và bản ở văn phòng không bao giờ tự xoá. Lớp này in danh sách để người có quyền huỷ ở
 * từng nơi.
 *
 * # Cổng
 *
 * - `{matter}` là mã hồ sơ (hoặc mã số dòng); không có → `not_found`. Vụ đã xoá mềm vẫn được tìm: tệp
 *   của nó vẫn phải huỷ.
 * - Bản ghi lưu trữ (chưa xoá mềm) chưa có `destroyed_at` → `not_destroyed`.
 * - Người `--by` được ĐỌC LẠI từ CSDL theo email, phải đang hoạt động và qua
 *   `Gate::forUser($user)->allows('recordDestruction', $matter)` (chỉ admin) → nếu không: `forbidden`.
 *   Cùng luật với {@see RecordMatterDestruction}: người ghi quyết định là người được liệt kê.
 *
 * # Ba danh sách (chỉ mã và tên mờ: không tiêu đề, không mã hồ sơ, không mã tệp Drive)
 *
 * Media của vụ = media của MỌI tài liệu mang `matter_id` của vụ (kể cả tài liệu đã xoá mềm và gói bàn
 * giao).
 * 1. Tên trên Drive: mọi dòng `drive_objects` có `object_key` HOẶC `former_key` bắt đầu bằng
 *    `<media_id>/` — mọi thế hệ, cả dòng đã vào thùng rác hay bị thay (`superseded`) — đổi thành tên
 *    Drive bằng {@see DriveObjectName::fromKey()}. Tiền tố có `/`: media 18 không kéo theo 180, 1800…
 * 2. Đường vùng đệm còn trên máy chủ: mọi tệp dưới thư mục vùng đệm của media ({@see StagedCopy}),
 *    đường tuyệt đối.
 * 3. Đường trong remote `crypt` của văn phòng: `vkoffice:kho/<YYYY-MM>/<tên>`, với `<YYYY-MM>` là tên
 *    thư mục tháng của dòng đó (`drive_folders` theo `parent_id`); tệp ở ngay thư mục gốc thì
 *    `vkoffice:kho/<tên>`. Máy văn phòng kéo `vkkho:` (gốc của môi trường) về `vkoffice:kho` giữ nguyên
 *    cây.
 *
 * Giới hạn đã biết: media đã bị xoá khỏi CRM (gói bàn giao cũ thay bằng gói mới) không còn dòng
 * `media` để nối về vụ, nên không có trong danh sách; bản ở văn phòng của chúng chỉ tìm được bằng
 * `vkcrm:storage:orphans` và nhật ký.
 *
 * Audit `matter_storage_destruction_listed` (chủ thể là vụ, người thực hiện là người `--by`): số tên
 * Drive, số tệp vùng đệm, số đường ở văn phòng.
 */
final class ListMatterFilesForDestruction
{
    use ReadsWithoutPortalScope;

    /**
     * @return array{status: 'not_found'|'not_destroyed'|'forbidden'|'done', drive_names: list<string>, staged_paths: list<string>, office_paths: list<string>}
     */
    public function handle(string $matterRef, string $byEmail): array
    {
        $empty = ['drive_names' => [], 'staged_paths' => [], 'office_paths' => []];
        $matter = $this->matter($matterRef);

        if ($matter === null) {
            return ['status' => 'not_found'] + $empty;
        }

        $destroyed = $this->scopelessly(MatterArchive::query())
            ->where('matter_id', $matter->getKey())
            ->whereNotNull('destroyed_at')
            ->exists();

        if (! $destroyed) {
            return ['status' => 'not_destroyed'] + $empty;
        }

        $user = $this->scopelessly(User::query())->where('email', $byEmail)->first();

        if ($user === null || ! $user->is_active || ! Gate::forUser($user)->allows('recordDestruction', $matter)) {
            return ['status' => 'forbidden'] + $empty;
        }

        $media = Media::query()->whereIn('id', DB::table('media')
            ->join('documents', 'documents.id', '=', 'media.model_id')
            ->where('media.model_type', 'document')
            ->where('documents.matter_id', $matter->getKey())
            ->select('media.id'))
            ->orderBy('id')
            ->get();

        $folders = $this->scopelessly(DriveFolder::query())->pluck('name', 'folder_id');
        $driveNames = [];
        $officePaths = [];
        $stagedPaths = [];

        foreach ($media as $item) {
            foreach ($this->objectsOf((int) $item->getKey()) as $object) {
                try {
                    $name = DriveObjectName::fromKey((string) ($object->object_key ?? $object->former_key), $object->generation);
                } catch (InvalidArgumentException) {
                    continue;
                }

                $driveNames[] = $name;
                $month = $folders->get($object->parent_id);
                $officePaths[] = 'vkoffice:kho/'.($month === null ? '' : $month.'/').$name;
            }

            $directory = StagedCopy::directory($item);

            foreach (DocumentStore::staging()->allFiles($directory) as $file) {
                $stagedPaths[] = DocumentStore::staging()->path($file);
            }
        }

        $result = [
            'drive_names' => array_values(array_unique($driveNames)),
            'staged_paths' => $stagedPaths,
            'office_paths' => array_values(array_unique($officePaths)),
        ];

        Audit::record('matter_storage_destruction_listed', $matter, [
            'drive_files' => count($result['drive_names']),
            'staged_files' => count($result['staged_paths']),
            'office_files' => count($result['office_paths']),
        ], $user);

        return ['status' => 'done'] + $result;
    }

    private function matter(string $reference): ?Matter
    {
        $query = fn () => $this->scopelessly(Matter::query())->withTrashed();

        return $query()->where('code', $reference)->first()
            ?? (ctype_digit($reference) ? $query()->whereKey((int) $reference)->first() : null);
    }

    /** @return iterable<DriveObject> mọi dòng (sống hay đã rời) của khoá `<media_id>/…`, theo thế hệ */
    private function objectsOf(int $mediaId): iterable
    {
        $prefix = $mediaId.'/%';

        return $this->scopelessly(DriveObject::query())
            ->where(fn ($query) => $query->where('object_key', 'like', $prefix)->orWhere('former_key', 'like', $prefix))
            ->orderBy('generation')
            ->orderBy('id')
            ->get();
    }
}
