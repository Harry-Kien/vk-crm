<?php

namespace App\Models;

use App\Enums\OutboundChannel;
use App\Enums\OutboundStatus;
use App\Enums\Role as StaffRole;
use App\Models\Concerns\RestrictedToClientPortal;
use Database\Factories\OutboundMessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

class OutboundMessage extends Model
{
    /** @use HasFactory<OutboundMessageFactory> */
    use HasFactory;

    use RestrictedToClientPortal;

    /**
     * Mẫu ghi cho một thư KHÔNG khai báo mẫu nào (`App\Mail\OutboundHeaders::TEMPLATE`).
     *
     * Một giá trị nói thẳng, chứ không phải `null` và cũng không phải một lần ném lỗi. Nhật ký
     * này tồn tại để trả lời "tôi không nhận được thông báo", nên nó phải ghi được cả những thư
     * mà không ai nhớ là mình gửi — một `Mail::raw()` trong một lần vá vội vẫn để lại dấu vết,
     * và dấu vết ấy tự nói ra rằng nó chưa được khai báo.
     */
    public const TEMPLATE_UNDECLARED = 'undeclared';

    /**
     * Bí danh morph (SPEC §4.15, `App\Providers\AppServiceProvider::enforceMorphMap()`) mà bản
     * ghi của nó mang cột `matter_id` TRỰC TIẾP — dùng để dựng điều kiện SQL của
     * `scopeVisibleTo()`/`scopeForMatter()` mà không phải nạp từng dòng (N+1) chỉ để hỏi vụ việc.
     *
     * Đây là mặt SQL của đúng một luật mà {@see self::relatedMatter()} trả lời bằng PHP (đọc
     * `related()->matter` sau khi morphTo() đã nạp): hai nơi PHẢI khớp nhau, vì
     * `OutboundMessagePolicy::view()` dùng bản PHP, còn danh sách/bộ đếm Filament dùng bản SQL
     * này. `client_request_reply` và `matter` tự có nhánh riêng ngay dưới, không nằm trong mảng
     * này, vì đường tới `matter_id` của chúng không thẳng một cột.
     *
     * Thêm một bí danh MỚI có `matter_id` trực tiếp mà quên thêm ở đây không rò rỉ gì — dòng đó
     * chỉ rơi vào "không có vụ việc" và mặc định CHỈ ADMIN xem (an toàn nhưng sai, che mất một
     * dòng đáng lẽ thấy được). Ngược lại, thêm nhầm một bí danh KHÔNG có `matter_id` trực tiếp
     * vào đây là một lỗi 500 ngay khi bảng chạy (cột không tồn tại).
     *
     * @var array<string, string>
     */
    private const DIRECT_MATTER_TYPES = [
        'stage_log' => 'stage_logs',
        'document' => 'documents',
        'deadline' => 'deadlines',
        'client_request' => 'client_requests',
        'matter_party' => 'matter_parties',
        'matter_checklist_item' => 'matter_checklist_items',
    ];

    /**
     * Bí danh của những bản ghi KHÔNG BAO GIỜ gắn với một vụ việc nào (tài khoản cổng, nhân sự
     * nhận thư không về vụ việc nào). Dòng `related_type` là một trong số này, hoặc `null`
     * (không khai được bản ghi liên quan), mặc định CHỈ ADMIN xem — quyết định của M6.5 Task 13
     * (SPEC §4.15/§7.4 không nói ai xem loại thư này), xem thêm `OutboundMessagePolicy::view()`.
     *
     * @var list<string>
     */
    private const NO_MATTER_TYPES = ['user', 'client_user', 'client'];

    protected $fillable = [
        'channel', 'recipient', 'template', 'payload', 'related_type', 'related_id', 'status', 'sent_at', 'error',
    ];

    protected $attributes = ['status' => 'queued', 'channel' => 'email'];

    protected function casts(): array
    {
        return [
            'channel' => OutboundChannel::class,
            'status' => OutboundStatus::class,
            'payload' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * Nhật ký thông báo gửi đi phục vụ tra cứu nội bộ (SPEC §4.15). Chặn sạch ở tầng truy vấn
     * thay vì trông vào việc không ai viết resource cho nó.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereRaw('1 = 0');
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Vụ việc mà thư này nói về, đọc qua bản ghi `related()` (M6.5 Task 13, notify-8/spec-gap-07).
     *
     * `related_type` có thể chính là `matter` (thư nói thẳng về vụ việc), hoặc một bản ghi CON
     * của vụ việc (`stage_log`, `deadline`, `document`, `client_request`, `matter_party`,
     * `matter_checklist_item` — mọi model này đều có quan hệ `matter()`), hoặc
     * `client_request_reply` (đi qua `request()->matter`, vì bảng đó không có cột `matter_id`
     * trực tiếp), hoặc không gắn vụ việc nào (`user`, `client_user`, `client`, hay `null`).
     *
     * `method_exists($related, 'matter')` — không liệt kê cứng danh sách lớp — để một model MỚI
     * mai sau có quan hệ `matter()` tự được nhận ra ở đây mà không cần sửa hàm này; danh sách CÓ
     * liệt kê cứng nằm ở mặt SQL ({@see self::DIRECT_MATTER_TYPES}), nơi bắt buộc phải biết tên
     * cột/bảng.
     */
    public function relatedMatter(): ?Matter
    {
        $related = $this->related;

        return match (true) {
            $related instanceof Matter => $related,
            $related instanceof ClientRequestReply => $related->request?->matter,
            $related !== null && method_exists($related, 'matter') => $related->matter,
            default => null,
        };
    }

    /**
     * Nhật ký thư chỉ hiện dòng mà `$user` được xem (SPEC §4.15, M6.5 Task 13). Dòng gắn vụ việc
     * đi qua ĐÚNG MỘT định nghĩa hiển thị của toàn hệ thống, {@see Matter::scopeListableBy()} —
     * không viết lại luật restricted/team ở đây. Dòng KHÔNG gắn vụ việc nào mặc định chỉ admin
     * (xem {@see self::NO_MATTER_TYPES}).
     *
     * Viết bằng `where`/`whereIn` trên bảng con thay vì nạp từng bản ghi rồi hỏi
     * {@see self::relatedMatter()}: cách đó sẽ là N+1 truy vấn VÀ không lọc được ở tầng SQL, tức
     * phân trang của Filament (LIMIT/OFFSET, đếm tổng) sẽ sai ngay khi có một dòng bị ẩn.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $outer) use ($user): void {
            $matterIds = fn () => Matter::query()->listableBy($user)->select('id');

            foreach (self::DIRECT_MATTER_TYPES as $alias => $table) {
                $outer->orWhere(fn (Builder $q) => $q->where('related_type', $alias)
                    ->whereIn('related_id', DB::table($table)->whereIn('matter_id', $matterIds())->select('id')));
            }

            $outer->orWhere(fn (Builder $q) => $q->where('related_type', 'matter')->whereIn('related_id', $matterIds()));

            $outer->orWhere(fn (Builder $q) => $q->where('related_type', 'client_request_reply')
                ->whereIn('related_id', DB::table('client_request_replies')
                    ->whereIn('request_id', DB::table('client_requests')->whereIn('matter_id', $matterIds())->select('id'))
                    ->select('id')));

            if ($user->hasRole(StaffRole::Admin->value)) {
                $outer->orWhere(fn (Builder $q) => $q->whereNull('related_type')->orWhereIn('related_type', self::NO_MATTER_TYPES));
            }
        });
    }

    /**
     * Bộ lọc "vụ việc" của bảng — cùng luật khoanh vùng theo loại bản ghi với
     * {@see self::scopeVisibleTo()}, nhưng khoá vào MỘT `$matterId` thay vì tập vụ việc xem được
     * của `$user`. Dùng cho liên kết "Thư đã gửi" trên tab vụ việc
     * (`App\Filament\Admin\Resources\Matters\Pages\ViewMatter`).
     *
     * KHÔNG tự thay {@see self::scopeVisibleTo()} và KHÔNG được gọi một mình trên
     * `getEloquentQuery()`: `OutboundMessageResource::getEloquentQuery()` luôn áp
     * `scopeVisibleTo()` TRƯỚC, nên scope này chỉ THU HẸP THÊM bên trong tập đã được lọc — một mã
     * vụ việc bị GÁN TAY qua URL cho một vụ người xem không được thấy vẫn rơi vào khoảng trống của
     * `scopeVisibleTo()` và trả về rỗng, không lộ gì.
     */
    public function scopeForMatter(Builder $query, int $matterId): Builder
    {
        return $query->where(function (Builder $outer) use ($matterId): void {
            foreach (self::DIRECT_MATTER_TYPES as $alias => $table) {
                $outer->orWhere(fn (Builder $q) => $q->where('related_type', $alias)
                    ->whereIn('related_id', DB::table($table)->where('matter_id', $matterId)->select('id')));
            }

            $outer->orWhere(fn (Builder $q) => $q->where('related_type', 'matter')->where('related_id', $matterId));

            $outer->orWhere(fn (Builder $q) => $q->where('related_type', 'client_request_reply')
                ->whereIn('related_id', DB::table('client_request_replies')
                    ->whereIn('request_id', DB::table('client_requests')->where('matter_id', $matterId)->select('id'))
                    ->select('id')));
        });
    }
}
