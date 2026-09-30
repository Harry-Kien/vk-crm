<?php

namespace App\Models;

use App\Enums\HandoverPackageStatus;
use App\Models\Concerns\RestrictedToClientPortal;
use App\Support\Scopes\ClientPortalScope;
use Database\Factories\MatterArchiveFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MatterArchive extends Model
{
    /** @use HasFactory<MatterArchiveFactory> */
    use HasFactory;

    use RestrictedToClientPortal;
    use SoftDeletes;

    /**
     * M7 Task 3 (R1, đính chính SPEC §4.19): `handover_package_path` KHÔNG còn trong danh sách
     * này — gói bàn giao là một bản ghi `Document` (xem {@see self::handoverDocument()}), không
     * phải một chuỗi đường dẫn. Cột vẫn còn trên bảng (migration Task 3 không xoá nó) vì xoá một
     * cột đã NULL ở mọi dòng hiện có là một thao tác phá huỷ không cần thiết; bỏ nó khỏi đây là
     * đủ để không còn đường ghi nào chạm tới nó nữa.
     */
    protected $fillable = [
        'matter_id', 'archived_at', 'archived_by', 'handover_document_id', 'handover_generated_at',
        'handover_status', 'handover_requested_at', 'handover_requested_by', 'handover_error',
        'client_access_until', 'retention_until', 'destroyed_at',
        'destruction_reason', 'destruction_record_no', 'destroyed_by',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
            'handover_generated_at' => 'datetime',
            'handover_status' => HandoverPackageStatus::class,
            'handover_requested_at' => 'datetime',
            'client_access_until' => 'date',
            'retention_until' => 'date',
            'destroyed_at' => 'datetime',
        ];
    }

    /**
     * Hồ sơ lưu trữ và đường dẫn gói bàn giao là dữ liệu nội bộ (SPEC §4.19). Chặn sạch ở tầng
     * truy vấn thay vì trông vào việc không ai viết resource cho nó.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereRaw('1 = 0');
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    /** M7 Task 3 (R1): gói bàn giao là một `Document` nhóm B, không phải một đường dẫn. */
    public function handoverDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'handover_document_id');
    }

    /** M7 Task 4: người bấm "Sinh gói bàn giao" (NULL với lần tự sinh khi vụ đóng). */
    public function handoverRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handover_requested_by');
    }

    /**
     * Id của MỌI version của tài liệu gói bàn giao (R1): đi từ `handover_document_id` ngược theo
     * `parent_document_id`. Dùng để (1) không bao giờ đưa chính gói — bất kỳ version nào — vào gói
     * sau (R8) và (2) nhận biết một lượt tải là lượt tải gói để ghi `data_exported`.
     *
     * Đọc bằng `DB::table()` chứ không qua model: `Document` mang `ClientPortalScope` và
     * `SoftDeletingScope`, và một version đã xoá mềm của gói vẫn là gói. Chuỗi có giới hạn bước
     * (một chuỗi hỏng tự trỏ vòng không được treo job).
     *
     * @return Collection<int, int>
     */
    public function handoverDocumentIds(): Collection
    {
        $ids = collect();
        $current = $this->handover_document_id === null ? null : (int) $this->handover_document_id;

        while ($current !== null && ! $ids->contains($current) && $ids->count() < 1000) {
            $ids->push((int) $current);
            $parent = DB::table('documents')->where('id', $current)->value('parent_document_id');
            $current = $parent === null ? null : (int) $parent;
        }

        return $ids;
    }

    /**
     * `$document` có phải một version của tài liệu gói bàn giao của vụ việc của nó không (M7 Task
     * 4) — dùng khi tải tệp để ghi `data_exported`. Bỏ `ClientPortalScope`: khách cũng tải gói
     * (sau khi được công bố), và scope của model này chặn sạch mọi truy vấn của khách.
     */
    public static function isHandoverDocument(Document $document): bool
    {
        $archive = static::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->where('matter_id', $document->matter_id)
            ->whereNotNull('handover_document_id')
            ->first();

        return $archive?->handoverDocumentIds()->contains((int) $document->getKey()) ?? false;
    }

    /** M7 Task 3 (chuẩn bị cho Task 6): người ra quyết định tiêu huỷ hồ sơ. */
    public function destroyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'destroyed_by');
    }
}
