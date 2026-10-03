<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một dòng cấu hình khoá–giá trị của bảng `settings` (M7 Task 10). Xem docblock của migration
 * `2026_09_28_071000_create_settings_table` cho ý nghĩa từng cột.
 *
 * Không đọc/ghi model này trực tiếp ở màn hình hay job:
 *  - GHI qua `App\Actions\Settings\WriteSettings` (dưới một Action của tính năng, nơi hỏi quyền
 *    và ghi audit — `UpdateOfficeProfile`, và của M11 sau này);
 *  - ĐỌC thông tin văn phòng qua `App\Support\OfficeProfile`.
 *
 * Không `LogsActivity`: một dòng activitylog "updated" cho mỗi khoá sẽ chép GIÁ TRỊ vào nhật ký,
 * trong khi Action của tính năng đã ghi một dòng có cấu trúc nêu TÊN trường đổi. Không nằm trong
 * ranh giới cổng khách (`RestrictedToClientPortal`): bảng không mang dữ liệu của khách nào, và
 * cổng PHẢI đọc được chín thông tin văn phòng (hotline trên trang lỗi, chân trang đăng nhập) —
 * một scope `1=0` ở đây sẽ lặng lẽ đưa cổng về giá trị `.env` cũ. Lý do được ghi ở danh sách miễn
 * trừ của `PortalCoverageTest`.
 *
 * @property string $key
 * @property ?string $value
 * @property ?int $updated_by
 */
class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'updated_by',
    ];

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }
}
