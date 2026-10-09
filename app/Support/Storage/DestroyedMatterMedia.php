<?php

namespace App\Support\Storage;

use Illuminate\Support\Facades\DB;

/**
 * Media nào thuộc một vụ đã GHI quyết định tiêu huỷ (`matter_archives.destroyed_at`, M7 Task 6, SPEC
 * §4.19) — kế hoạch M14, R15. `vkcrm:storage:verify` và `vkcrm:storage:orphans` báo tệp của các vụ đó
 * thành một nhóm riêng, không tính là lỗi: sau quyết định huỷ, người có quyền xoá tệp ở từng nơi
 * (`vkcrm:storage:destruction-list`), nên tệp thiếu hay lệch của chúng là điều được chờ đợi.
 *
 * Mọi media của dự án thuộc một `Document` (`model_type = 'document'`, kể cả gói bàn giao); tài liệu
 * mang `matter_id`. Đọc thẳng bảng (`DB::table`), không model: không global scope nào, và tài liệu đã
 * xoá mềm vẫn thuộc vụ của nó. Bản ghi lưu trữ đã xoá mềm không tính.
 */
final class DestroyedMatterMedia
{
    private const CHUNK = 500;

    /**
     * @param  iterable<int>  $mediaIds
     * @return array<int, true> mã media thuộc vụ đã ghi huỷ
     */
    public static function among(iterable $mediaIds): array
    {
        $found = [];

        foreach (collect($mediaIds)->map(fn ($id): int => (int) $id)->unique()->chunk(self::CHUNK) as $chunk) {
            DB::table('media')
                ->join('documents', 'documents.id', '=', 'media.model_id')
                ->join('matter_archives', 'matter_archives.matter_id', '=', 'documents.matter_id')
                ->where('media.model_type', 'document')
                ->whereIn('media.id', $chunk->values()->all())
                ->whereNotNull('matter_archives.destroyed_at')
                ->whereNull('matter_archives.deleted_at')
                ->pluck('media.id')
                ->each(function ($id) use (&$found): void {
                    $found[(int) $id] = true;
                });
        }

        return $found;
    }
}
