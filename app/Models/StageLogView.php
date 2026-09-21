<?php

namespace App\Models;

use App\Actions\Portal\RecordStageLogView;
use App\Exceptions\StageLogViewImmutable;
use App\Models\Concerns\RestrictedToClientPortal;
use App\Policies\StageLogViewPolicy;
use Database\Factories\StageLogViewFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StageLogView extends Model
{
    /** @use HasFactory<StageLogViewFactory> */
    use HasFactory;

    use RestrictedToClientPortal;

    protected $fillable = ['stage_log_id', 'client_user_id', 'viewed_at', 'ip'];

    protected function casts(): array
    {
        return ['viewed_at' => 'datetime'];
    }

    /**
     * **Bảng này là bằng chứng, nên model tự canh — không chỉ `Gate`.**
     *
     * `StageLogViewPolicy` không có `update` lẫn `delete`, nên hôm nay `Gate` đã từ chối cả hai.
     * Nhưng `Gate` chỉ canh những đường CÓ HỎI nó: `$view->update([...])` từ một Action, một
     * lệnh console, một job, hay một lần dọn dữ liệu đi thẳng qua Eloquent thì không ai hỏi. Đo
     * được trước vòng sửa này: `update(['viewed_at' => now()->addYear(), 'ip' => '8.8.8.8'])`
     * thành công, `delete()` thành công. Hợp đồng số 2 của
     * {@see RecordStageLogView} — "`viewed_at` không bao giờ bị ghi đè" —
     * khi đó chỉ được giữ bởi ĐÚNG MỘT hàm, trên đúng cái bảng được mô tả là thứ đưa ra trước
     * toà.
     *
     * Cùng thiết bị và cùng hình dạng với {@see StageLog::booted()} (nhật ký chỉ thêm, SPEC §4.8)
     * và với `Matter::forceDeleting` → `MatterNotDestroyable`. Khác một chỗ, và khác có chủ ý:
     * `StageLog` còn một danh sách cột được sửa (`StageLog::MUTABLE`, vì công bố là một hành
     * động sau khi ghi), còn ở đây KHÔNG có cột nào sửa được. Bốn cột của bảng — dòng tiến độ,
     * tài khoản, thời điểm, địa chỉ — đều là lời khai; không cột nào trong đó là một trạng thái
     * còn đi tiếp.
     *
     * Không chặn `creating`: ghi lần đầu chính là việc của bảng.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw StageLogViewImmutable::make();
        });

        static::deleting(function (): void {
            throw StageLogViewImmutable::make();
        });
    }

    /**
     * `whereHas('stageLog')` kế thừa nguyên điều kiện của `StageLog` — đã công bố, thuộc vụ việc
     * khách thấy được — nên không lặp lại `client_id` ở đây.
     *
     * KHÔNG lọc theo `client_user_id`, và đó là một câu đã chốt: phạm vi của portal là **theo
     * `Client`** (phán quyết 19/09/2026, xem {@see ClientRequest::applyClientPortalConstraints()}),
     * nên hai tài khoản portal của cùng một khách hàng đọc được biên bản của nhau. Lý lẽ đầy đủ
     * ở docblock {@see StageLogViewPolicy}; nói ngắn: nhãn "Khách đã xem" ở panel
     * nội bộ (SPEC §7.2) cũng nói về khách hàng chứ không về một tài khoản.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereHas('stageLog');
    }

    public function stageLog(): BelongsTo
    {
        return $this->belongsTo(StageLog::class);
    }

    public function clientUser(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class);
    }
}
