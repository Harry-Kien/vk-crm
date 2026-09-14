<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Điền created_by / updated_by từ nhân sự đang đăng nhập (guard web).
 * Không đưa hai cột này vào $fillable: chỉ hệ thống mới được ghi.
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
