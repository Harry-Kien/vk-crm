<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Điền created_by / updated_by từ nhân sự đang đăng nhập (guard web).
 * Không đưa hai cột này vào $fillable: chỉ hệ thống mới được ghi.
 *
 * **Phiên đăng nhập chỉ là PHƯƠNG ÁN CUỐI.** Mọi Action trong `app/Actions/` đều nhận `$actor`
 * tường minh (vì chính actor đó được đem đi kiểm tra quyền), và một Action biết actor là ai thì
 * phải được quyền ghi đúng người đó vào hai cột này — kể cả khi phiên `web` đang thuộc về người
 * khác, hoặc không có phiên nào (job, lệnh console, import). Vì vậy cả hai hook đều NHƯỜNG cho
 * giá trị đã gán tường minh: `creating` dùng `??=`, còn `updating` chỉ ghi khi `updated_by` chưa
 * bị gán trong chính lần lưu này (`isDirty`). Không có nhánh nhường đó thì một dòng
 * `$model->updated_by = $actor->id` ngay trước `update()` bị hook ghi đè lại bằng phiên — im
 * lặng, và không cách nào sửa từ phía Action.
 */
trait HasBlameable
{
    public static function bootHasBlameable(): void
    {
        static::creating(function (Model $model): void {
            $id = auth('web')->id();

            if ($id === null) {
                return;
            }

            $model->created_by ??= $id;
            $model->updated_by ??= $id;
        });

        static::updating(function (Model $model): void {
            // Đã có người gán tường minh trong lần lưu này (một Action biết actor của nó) —
            // không đè lên bằng phiên đăng nhập ambient. Xem docblock trait.
            if ($model->isDirty('updated_by')) {
                return;
            }

            $id = auth('web')->id();

            if ($id !== null) {
                $model->updated_by = $id;
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
