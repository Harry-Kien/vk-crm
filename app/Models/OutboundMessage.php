<?php

namespace App\Models;

use App\Enums\OutboundChannel;
use App\Enums\OutboundStatus;
use App\Enums\Permission;
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
     * Vụ việc mà thư này nói về (M6.5 Task 13, notify-8/spec-gap-07).
     *
     * **Fix round 1, finding I1 — đọc thẳng bằng SQL trên bảng con, KHÔNG qua quan hệ Eloquent
     * `related()`/`matter()` như bản đầu.** Quan hệ Eloquent tự áp `SoftDeletingScope` của model
     * con: một mốc thời hạn bị xoá mềm (R14 — "Xoá một mốc hạn là xoá mềm kèm lý do", bình
     * thường, không phải lỗi) khiến `$this->related` trả `null`, nên bản đầu của hàm này cũng trả
     * `null` — trong khi {@see self::scopeVisibleTo()} (dùng `DB::table()`, không áp scope đó)
     * vẫn xếp đúng dòng vào tập nhìn thấy được của lead. Kết quả: dòng hiện trong danh sách
     * nhưng nhãn "Không gắn vụ việc nào" và trang xem trả 403 — nhãn/quyền và danh sách LỆCH
     * NHAU trên CÙNG một dòng. Hàm này giờ đọc đúng MỘT nguồn dữ liệu mà cả hai nơi dùng
     * ({@see self::DIRECT_MATTER_TYPES}), nên không còn lệch được nữa.
     *
     * `Matter::query()->withTrashed()`: chính vụ việc cũng có thể đã xoá mềm (ruling fix round 1:
     * admin vẫn phải thấy dòng của một vụ đã xoá mềm) — thiếu `withTrashed()` ở bước cuối này,
     * `relatedMatterId()` trả đúng id nhưng `find()` lại không thấy gì, y hệt lỗi vừa sửa nhưng
     * lùi một bước.
     */
    public function relatedMatter(): ?Matter
    {
        $matterId = $this->relatedMatterId();

        return $matterId === null ? null : Matter::query()->withTrashed()->find($matterId);
    }

    /**
     * ID vụ việc, đọc thẳng bằng SQL — mặt PHP dùng chung nguồn dữ liệu với mặt SQL của
     * {@see self::scopeVisibleTo()}/{@see self::scopeForMatter()} (xem docblock
     * {@see self::relatedMatter()} cho lý do phải chung nguồn).
     *
     * Trả `null` cho `related_type` là `null`, hoặc một bí danh KHÔNG nằm trong
     * {@see self::DIRECT_MATTER_TYPES} và không phải `matter`/`client_request_reply` (bao gồm cả
     * một bí danh LẠ — dữ liệu hỏng, hay một loại morph tương lai chưa được khai ở đây) — dòng đó
     * "không có vụ việc" theo đúng nghĩa `OutboundMessagePolicy::view()` dùng, không phải một lần
     * ném lỗi.
     */
    private function relatedMatterId(): ?int
    {
        if ($this->related_type === null) {
            return null;
        }

        if ($this->related_type === 'matter') {
            return (int) $this->related_id;
        }

        if ($this->related_type === 'client_request_reply') {
            $matterId = DB::table('client_request_replies')
                ->join('client_requests', 'client_requests.id', '=', 'client_request_replies.request_id')
                ->where('client_request_replies.id', $this->related_id)
                ->value('client_requests.matter_id');

            return $matterId === null ? null : (int) $matterId;
        }

        if (! array_key_exists($this->related_type, self::DIRECT_MATTER_TYPES)) {
            return null;
        }

        $matterId = DB::table(self::DIRECT_MATTER_TYPES[$this->related_type])
            ->where('id', $this->related_id)
            ->value('matter_id');

        return $matterId === null ? null : (int) $matterId;
    }

    /**
     * Nhật ký thư chỉ hiện dòng mà `$user` được xem (SPEC §4.15, M6.5 Task 13). Dòng gắn vụ việc
     * đi qua ĐÚNG MỘT định nghĩa hiển thị của toàn hệ thống, {@see Matter::scopeListableBy()} —
     * không viết lại luật restricted/team ở đây.
     *
     * **Ruling fix round 1 (chủ nhiệm) — admin không lọc gì cả, thấy MỌI dòng.** Nhật ký thư tồn
     * tại để trả lời "khách nói không nhận được thư" (SPEC §4.15); nó không được phép LÀM MẤT một
     * dòng trước mắt admin chỉ vì dòng đó gắn với một vụ việc đã xoá mềm, hay mang một
     * `related_type` lạ/mồ côi (dữ liệu hỏng, hay một bí danh morph tương lai chưa kịp thêm vào
     * {@see self::DIRECT_MATTER_TYPES}) — hai ca mà một luật viết theo kiểu "liệt kê những gì
     * admin được thấy thêm" (bản trước: `whereNull()->orWhereIn(NO_MATTER_TYPES)`) không bao giờ
     * phủ hết được, vì nó chỉ biết liệt kê những gì ĐÃ NGHĨ TỚI. `return $query` không lọc gì —
     * không nhánh nào có thể bỏ sót.
     *
     * **Fix round 1, minor — kế toán (và bất kỳ vai nào không có `matter.view`) bị chặn ngay TẠI
     * SCOPE, không chỉ tại `OutboundMessagePolicy::viewAny()`.** Trước bản sửa này, thiếu điều
     * kiện này thì `Matter::scopeListableBy($accountant)` vẫn trả về TOÀN BỘ vụ việc thường (kế
     * toán có `matter.viewAny`, và nhánh "thường" của `listableBy()` trả sớm — không lọc gì thêm
     * — cho bất kỳ ai có quyền đó), nên nếu scope này được gọi từ một nơi không đi qua
     * `OutboundMessagePolicy` (một Action, một lệnh console, hay chính policy đó bị nới lỏng ở
     * một bản sửa sau này), kế toán vẫn đọc được nhật ký thư của mọi vụ việc thường — hai lớp
     * phòng thủ (scope + policy) phải ĐỘC LẬP đúng, không phải một lớp dựa vào lớp kia.
     *
     * Viết bằng `where`/`whereIn` trên bảng con thay vì nạp từng bản ghi rồi hỏi
     * {@see self::relatedMatter()}: cách đó sẽ là N+1 truy vấn VÀ không lọc được ở tầng SQL, tức
     * phân trang của Filament (LIMIT/OFFSET, đếm tổng) sẽ sai ngay khi có một dòng bị ẩn.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasRole(StaffRole::Admin->value)) {
            return $query;
        }

        if (! $user->can(Permission::MatterView->value)) {
            return $query->whereRaw('1 = 0');
        }

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
