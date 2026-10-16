<?php

namespace App\Models;

use App\Actions\Push\RegisterNativeDevice;
use App\Enums\ApnsEnvironment;
use App\Models\Concerns\RestrictedToClientPortal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Một chiếc iPhone của nhân sự nhận thông báo qua APNs (M15). Ghi qua {@see RegisterNativeDevice};
 * gỡ qua `ForgetPushDevice`. Token là thứ mang quyền gửi tới máy đó: không bao giờ vào nhật ký/log.
 *
 * Cổng khách không có ứng dụng iPhone, nên với tài khoản khách bảng này rỗng.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token
 * @property ApnsEnvironment $environment
 * @property string $device_label
 * @property string $app_version
 * @property Carbon|null $last_seen_at
 */
class NativePushDevice extends Model
{
    use RestrictedToClientPortal;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'environment' => ApnsEnvironment::class,
            'last_seen_at' => 'datetime',
        ];
    }

    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereRaw('1 = 0');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
