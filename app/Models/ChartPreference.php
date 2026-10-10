<?php

namespace App\Models;

use App\Actions\Preference\RememberChartKind;
use App\Enums\ChartKind;
use App\Models\Concerns\RestrictedToClientPortal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dạng biểu đồ một nhân sự đã chọn cho một biểu đồ trên bảng điều khiển. Ghi qua
 * {@see RememberChartKind}; đọc qua {@see self::kindFor()}.
 *
 * Không phải dữ liệu hồ sơ: không mã vụ, không tên khách, không tiền — chỉ "người này thích xem biểu đồ
 * kia ở dạng nào". Cổng khách không có biểu đồ nào, nên với tài khoản khách bảng này rỗng.
 *
 * @property int $user_id
 * @property string $widget
 * @property ChartKind|null $chart_kind
 */
class ChartPreference extends Model
{
    use RestrictedToClientPortal;

    protected function casts(): array
    {
        return ['chart_kind' => ChartKind::class];
    }

    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereRaw('1 = 0');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * Dạng đã lưu của `$user` cho biểu đồ `$widget`, hoặc `null` khi chưa chọn lần nào — hoặc khi giá trị
     * trong cột không còn là một dạng hợp lệ (dòng cũ, sửa tay): đọc thô rồi `tryFrom`, vì cast enum của
     * Eloquent NÉM ngoại lệ trên một giá trị lạ và một dòng hỏng không được làm sập bảng điều khiển.
     */
    public static function kindFor(User $user, string $widget): ?ChartKind
    {
        $stored = self::query()
            ->where('user_id', $user->getKey())
            ->where('widget', $widget)
            ->toBase()
            ->value('chart_kind');

        return is_string($stored) ? ChartKind::tryFrom($stored) : null;
    }
}
