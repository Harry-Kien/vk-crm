<?php

namespace App\Models;

use App\Enums\ClientRequestStatus;
use App\Models\Concerns\RestrictedToClientPortal;
use App\Policies\StageLogViewPolicy;
use Database\Factories\ClientRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientRequest extends Model
{
    /** @use HasFactory<ClientRequestFactory> */
    use HasFactory;

    use RestrictedToClientPortal;
    use SoftDeletes;

    protected $fillable = [
        'matter_id', 'client_user_id', 'subject', 'content', 'status', 'assigned_to', 'answered_at',
        'last_activity_at',
    ];

    protected $attributes = ['status' => 'new'];

    protected function casts(): array
    {
        return [
            'status' => ClientRequestStatus::class,
            'answered_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * Giới hạn theo vụ việc, tức theo `client_id`, **không theo `client_user_id`**: SPEC §4.3
     * nói rõ mọi truy vấn portal giới hạn theo khách hàng, để hai tài khoản cùng một khách đọc
     * chung.
     *
     * **Đây là CÂU ĐƯỢC CHỐT, không phải một mặc định còn treo.** SPEC §5 viết "Tạo và xem
     * `ClientRequest` của chính mình", và câu đó đọc được theo cả hai nghĩa — "của chính tài
     * khoản này" hay "của chính khách hàng này". Câu hỏi đã được nêu hai lần (rà soát M2 Task 6,
     * rà soát M4 Task 2) và chủ văn phòng chốt ngày **19/09/2026: theo `Client`**. Hệ quả cần
     * nói thẳng, vì nó là một quyết định về sự riêng tư giữa hai người trong cùng một gia đình:
     * **một vụ việc có hai tài khoản portal (SPEC §4.3 nêu ví dụ hai vợ chồng) thì người này đọc
     * được yêu cầu người kia gửi, và đọc được cả câu văn phòng trả lời.**
     *
     * Ghim bằng test ở `tests/Feature/Authorization/PortalIsolationSweepTest.php` (cả vế dương —
     * tài khoản anh em đọc được — lẫn vế âm — khách hàng khác thì không). Đổi cách đọc là đổi
     * đúng hàm này cộng dòng tương ứng ở `ClientRequestReply`, và bộ test sẽ đỏ, nên lần đổi đó
     * không lặng lẽ xảy ra được.
     *
     * Cùng cách đọc áp cho `StageLogView` (biên bản đã xem) — xem {@see StageLogViewPolicy}.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        // `whereNull('deleted_at')`: một yêu cầu đã xoá mềm không quay lại bằng `withTrashed()`,
        // thứ chỉ gỡ `SoftDeletingScope` chứ không đụng tới scope này. Xem
        // `Matter::applyClientPortalConstraints()`.
        $query->whereNull($this->qualifyColumn('deleted_at'))->whereHas('matter');
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function clientUser(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(ClientRequestReply::class, 'request_id')->orderBy('created_at');
    }
}
