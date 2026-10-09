<?php

namespace App\Models;

use App\Enums\DriveObjectRetirement;
use App\Models\Concerns\RestrictedToClientPortal;
use App\Policies\DriveObjectPolicy;
use App\Support\Storage\GoogleDrive\DriveObjectIndex;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Một dòng của chỉ mục khoá → tệp Google Drive (M14, kế hoạch R4). Ý nghĩa từng cột ở docblock của
 * migration `2026_10_04_000001_create_drive_objects_table`.
 *
 * Bảng của hạ tầng, không phải dữ liệu nghiệp vụ:
 *  - không màn hình nào liệt kê nó, và {@see DriveObjectPolicy} từ chối mọi thao tác
 *    với mọi người, kể cả admin;
 *  - cổng khách không bao giờ thấy dòng nào ({@see self::applyClientPortalConstraints()}). Riêng
 *    mã của kho ({@see DriveObjectIndex}) đọc bảng KHÔNG qua scope đó: adapter chạy cả trong phiên
 *    khách của route tải, sau khi route đã kiểm quyền, và không dòng nào rời adapter (R3);
 *  - không `HasBlameable`, không `LogsActivity`: không người nào ghi bảng này, chỉ mã của kho.
 *
 * Mọi mã Drive (`file_id`, `parent_id`, `drive_id`) nằm trong `$hidden`: chúng không bao giờ rời máy
 * chủ (R3), kể cả qua một `toArray()`/`toJson()` vô tình (thuộc tính Livewire, log ngữ cảnh). Mã của
 * kho vẫn đọc chúng bình thường như thuộc tính.
 *
 * `office_copied_at` KHÔNG nằm trong `$fillable`: chỉ lượt nhập biên nhận của máy văn phòng được ghi
 * cột này (R10), bằng một câu UPDATE có chủ đích — không một `create()`/`fill()` nào đặt nó được.
 *
 * @property int $id
 * @property string $drive_id
 * @property ?string $object_key
 * @property int $generation
 * @property ?string $former_key
 * @property ?DriveObjectRetirement $retired_reason
 * @property ?Carbon $retired_at
 * @property string $file_id
 * @property string $parent_id
 * @property int $size
 * @property string $md5
 * @property ?string $mime_type
 * @property ?Carbon $office_copied_at
 */
class DriveObject extends Model
{
    use RestrictedToClientPortal;

    protected $fillable = [
        'drive_id',
        'object_key',
        'generation',
        'former_key',
        'retired_reason',
        'retired_at',
        'file_id',
        'parent_id',
        'size',
        'md5',
        'mime_type',
    ];

    protected $hidden = [
        'drive_id',
        'file_id',
        'parent_id',
    ];

    protected function casts(): array
    {
        return [
            'generation' => 'integer',
            'size' => 'integer',
            'retired_reason' => DriveObjectRetirement::class,
            'retired_at' => 'datetime',
            'office_copied_at' => 'datetime',
        ];
    }

    /** Chỉ mục kho là dữ liệu hạ tầng, khách không bao giờ đọc (kế hoạch M14, "Mô hình dữ liệu"). */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereRaw('1 = 0');
    }
}
