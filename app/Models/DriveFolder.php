<?php

namespace App\Models;

use App\Models\Concerns\RestrictedToClientPortal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Một thư mục tháng `<thư mục gốc>/<YYYY-MM>/` trên Google Drive (M14, kế hoạch R4). Ý nghĩa ở
 * docblock của migration `2026_10_04_000002_create_drive_folders_table`.
 *
 * Cùng lập trường với {@see DriveObject}: dữ liệu hạ tầng, không màn hình, policy từ chối mọi người,
 * cổng khách không thấy dòng nào, mọi mã Drive nằm trong `$hidden`.
 *
 * @property int $id
 * @property string $drive_id
 * @property string $root_folder_id
 * @property string $name
 * @property string $folder_id
 */
class DriveFolder extends Model
{
    use RestrictedToClientPortal;

    protected $fillable = [
        'drive_id',
        'root_folder_id',
        'name',
        'folder_id',
    ];

    protected $hidden = [
        'drive_id',
        'root_folder_id',
        'folder_id',
    ];

    /** Thư mục kho là dữ liệu hạ tầng, khách không bao giờ đọc. */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereRaw('1 = 0');
    }
}
