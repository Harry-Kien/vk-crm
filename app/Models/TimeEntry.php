<?php

namespace App\Models;

use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\RestrictedToClientPortal;
use Database\Factories\TimeEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * KHUNG cho tính phí theo giờ giai đoạn 2 (SPEC §15; ghi chú M1: "hoãn sang giai đoạn 2 cùng bảng
 * time_entries"). M9 Task 12 dựng ĐÚNG bảng, model, quan hệ, policy đóng kín, factory — **KHÔNG
 * Action, KHÔNG màn hình, KHÔNG một con số nào trên dashboard đọc bảng này ở M9.** Trang doanh thu
 * (Task 9) không biết bảng này tồn tại.
 *
 * **Cổng khách đóng kín MÃI** (P1, Task 2/10): khác bốn model tiền, không có task nào mở
 * `applyClientPortalConstraints()` sau này trong kế hoạch M9 — khách không bao giờ thấy nhật ký
 * giờ làm việc nội bộ của luật sư.
 *
 * `is_billable` và `hourly_rate` là HÌNH DẠNG CỘT cho mô hình tính phí theo giờ tương lai
 * (`BillingModel::Hourly`), không phải nghiệp vụ đang chạy — không Action nào đọc hay ghi ý nghĩa
 * "tính tiền" của chúng ở M9.
 */
class TimeEntry extends Model
{
    use HasBlameable;

    /** @use HasFactory<TimeEntryFactory> */
    use HasFactory;

    use RestrictedToClientPortal;

    protected $fillable = [
        'matter_id', 'user_id', 'worked_on', 'minutes', 'description', 'is_billable',
        'hourly_rate', 'invoiced_at',
    ];

    protected function casts(): array
    {
        return [
            'worked_on' => 'date',
            'minutes' => 'integer',
            'is_billable' => 'boolean',
            'hourly_rate' => 'integer',
            'invoiced_at' => 'datetime',
        ];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Cổng khách đóng kín MÃI (xem docblock lớp) — `1 = 0` chặn sạch, cùng thiết bị với
     * `Contract::applyClientPortalConstraints()`.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereRaw('1 = 0');
    }
}
