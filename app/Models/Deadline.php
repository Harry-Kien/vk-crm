<?php

namespace App\Models;

use App\Enums\CreatedVia;
use App\Enums\DeadlineOutcome;
use App\Enums\DeadlineSeverity;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\RestrictedToClientPortal;
use Carbon\CarbonInterface;
use Database\Factories\DeadlineFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Deadline extends Model
{
    use HasBlameable;

    /** @use HasFactory<DeadlineFactory> */
    use HasFactory;

    use RestrictedToClientPortal;
    use SoftDeletes;

    /**
     * M11 R5: `created_via`, `confirmed_at`, `confirmed_by` cố ý KHÔNG ở đây — tool `create_deadline`
     * (Task 13) ép `mcp`, và chỉ nút "Xác nhận" (Task 12) ghi hai cột xác nhận. Một form web điền được
     * chúng qua `fill()` là tự dán nhãn "tạo qua AI" hay "đã xác nhận" cho một mốc.
     */
    protected $fillable = [
        'matter_id', 'name', 'due_date', 'severity', 'responsible_user_id',
        'is_completed', 'completed_at', 'is_published', 'reminders_sent',
    ];

    /** `created_via` khớp mặc định của cột, để một instance vừa tạo nói đúng như dòng nó vừa ghi. */
    protected $attributes = ['reminders_sent' => '[]', 'severity' => 'normal', 'created_via' => 'web'];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'severity' => DeadlineSeverity::class,
            'is_completed' => 'boolean',
            'completed_at' => 'datetime',
            'is_published' => 'boolean',
            'reminders_sent' => 'array',
            'created_via' => CreatedVia::class,
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * Cửa sổ "mốc sắp tới" của SPEC §7.1 mục 2 ("Mốc thời hạn 7 ngày tới") và của cột N6 ở M13.
     * `UpcomingDeadlinesWidget::WINDOW_DAYS` là bí danh của hằng số này (chuyển xuống model ở
     * M13 Task 2, vì `App\Actions` không được dùng lớp của Filament — `ArchitectureTest`).
     */
    public const UPCOMING_WINDOW_DAYS = 7;

    /**
     * Hạn chưa xong, đến hạn trong N ngày tới (kể cả đã quá hạn) — {@see self::scopeOverdue()} hợp
     * {@see self::scopeDueWithin()}, hai tập rời nhau (`DeadlineScopeBoundaryTest` ghim phép hợp).
     *
     * **Cận trên đủ giờ (M13 Task 2, bản sửa lỗi có chủ đích).** Bản trước so `due_date <=` chuỗi
     * ngày trần `Y-m-d`: trên SQLite cột `date` lưu `Y-m-d 00:00:00`, lớn hơn chuỗi đó, nên mốc đến
     * hạn đúng ngày +N rơi khỏi widget trang chủ trên SQLite mà vẫn có mặt trên MariaDB — trong khi
     * `CheckDeadlines::tierFor()` trả `d7` cho đúng ngày +7. Nay so với 23:59:59 của ngày +N, đúng
     * trên cả hai CSDL (bài học `RevenueFilters::bounds()` của M9 Task 13).
     */
    public function scopeUpcoming(Builder $query, int $days): Builder
    {
        return $query
            ->where('is_completed', false)
            ->where('due_date', '<=', self::endOfDayAfter($days));
    }

    /**
     * Mốc quá hạn (M13, cột N5): chưa xong, ngày đến hạn TRƯỚC hôm nay (`due_date < hôm nay 00:00:00`).
     * Cùng điều kiện mà `CheckDeadlines::tierFor()` trả `OVERDUE_KEY` (số ngày còn lại < 0) và
     * cùng nửa "đã quá hạn" của {@see self::scopeUpcoming()} — mốc đến hạn HÔM NAY chưa quá hạn.
     *
     * Không lọc vụ việc: người gọi tự ghép `whereHas('matter', open()->listableBy(...))` như
     * `UpcomingDeadlinesWidget::rowsFor()`. Mốc tạo qua AI chưa xác nhận (M11) tính như mọi mốc (R20).
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->where($this->qualifyColumn('is_completed'), false)
            ->where($this->qualifyColumn('due_date'), '<', today()->toDateTimeString());
    }

    /**
     * Mốc đến hạn trong `$days` ngày tới, tính cả hôm nay (M13, cột N6): chưa xong,
     * `hôm nay 00:00:00 ≤ due_date ≤ (hôm nay + $days) 23:59:59`. Nửa "chưa quá hạn" của
     * {@see self::scopeUpcoming()}; với mốc THƯỜNG, đúng các mốc mà `CheckDeadlines::tierFor()` trả
     * `d1`/`d3`/`d7` khi `$days = 7` (mốc `critical` có thêm bậc `d14`, ngoài cửa sổ này).
     */
    public function scopeDueWithin(Builder $query, int $days): Builder
    {
        return $query
            ->where($this->qualifyColumn('is_completed'), false)
            ->whereBetween($this->qualifyColumn('due_date'), [today()->toDateTimeString(), self::endOfDayAfter($days)]);
    }

    /**
     * Mốc đến hạn trong một kỳ (M13, cột P1): `due_date` trong `$bounds` — hai cận đủ giờ của
     * `PerformancePeriod::bounds()` — và CHƯA gỡ. Không lọc trạng thái hoàn thành: phân loại là việc
     * của {@see self::outcomeAt()}. `whereNull('deleted_at')` nói tường minh (như `Matter::scopeOpen()`):
     * một `withTrashed()` phía trên không được kéo mốc đã gỡ vào kỳ — mốc đã gỡ là P2
     * ({@see self::scopeRemovedBetween()}), hai tập rời nhau.
     *
     * @param  array{0: string, 1: string}  $bounds
     */
    public function scopeDueBetween(Builder $query, array $bounds): Builder
    {
        return $query
            ->whereBetween($this->qualifyColumn('due_date'), $bounds)
            ->whereNull($this->qualifyColumn('deleted_at'));
    }

    /**
     * Mốc đã gỡ (xoá mềm kèm lý do, `DeleteDeadline`) trong một kỳ (M13, cột P2): chỉ dòng đã xoá
     * mềm, `deleted_at` trong `$bounds`. Không vào tỉ lệ nào; hiện ra để tỉ lệ không đẹp lên nhờ gỡ mốc.
     *
     * @param  array{0: string, 1: string}  $bounds
     */
    public function scopeRemovedBetween(Builder $query, array $bounds): Builder
    {
        return $query
            ->onlyTrashed()
            ->whereBetween($this->qualifyColumn('deleted_at'), $bounds);
    }

    /**
     * Mốc `$subject` đang giữ — `responsible_user_id` HIỆN TẠI (M13, danh sách mốc trên trang của
     * một người). Không phải "người giữ vào ngày đến hạn": câu đó là `DeadlineHolderAtDue` (R9).
     */
    public function scopeHeldBy(Builder $query, User $subject): Builder
    {
        return $query->where($this->qualifyColumn('responsible_user_id'), $subject->getKey());
    }

    /**
     * Kết quả của mốc này trong một kỳ có mốc cắt `$cutoff` (M13, cột P1; R19:
     * `PerformancePeriod::cutoff()` = `min(23:59:59 ngày cuối kỳ, now())`). `null` = mốc không vào
     * tập của kỳ. Bảng ca biên của kế hoạch M13 (SPEC §6.14), theo ĐÚNG thứ tự dưới đây; `$dueEnd` là
     * 23:59:59 của `due_date` theo `APP_TIMEZONE`:
     *
     *  1. `created_at > $dueEnd` (mốc ghi vào hệ thống sau ngày đến hạn: nhập dữ liệu cũ, ghi lại một
     *     phiên toà đã qua, mốc AI tạo với ngày đã qua) → `null`: không ai được giao việc đó trước hạn;
     *  2. đã xong mà `completed_at` rỗng (dữ liệu cũ) → `null`: không đoán đúng hạn hay trễ;
     *  3. đã xong, `completed_at ≤ $dueEnd` → đúng hạn;
     *  4. đã xong, `$dueEnd < completed_at ≤ $cutoff` → trễ;
     *  5. (từ đây: chưa xong, hoặc xong SAU `$cutoff`) `$dueEnd > $cutoff` — đến hạn hôm nay, kỳ đang
     *     chạy → `null`: chưa hết ngày đến hạn;
     *  6. vụ {@see Matter::closedOnOrBefore()} ngày đến hạn (kể cả kết thúc ĐÚNG ngày đó) → `null`:
     *     vụ đã kết thúc thì mốc hết hiệu lực;
     *  7. còn lại → lỡ. Xong sau `$cutoff` vẫn là "lỡ" của kỳ đó: kỳ đã đóng không trôi (R19).
     *  8. Mốc mở lại sau ngày đến hạn (`SetDeadlineCompletion` ghi `completed_at = null`) đi theo
     *     trạng thái hiện tại: ca 7, hoặc ca 4 nếu xong lại trước `$cutoff`.
     *
     * Không đọc lịch sử người giữ (ca 9 thuộc `DeadlineHolderAtDue`), và không lọc mốc tạo qua AI
     * (R20). Cần quan hệ `matter` đã nạp (kể cả `closed_at`) — người gọi nạp cả lô bằng `with('matter')`;
     * vụ không nạp được (đã huỷ) không bao giờ tới đây vì tập của kỳ đi qua `listableBy()`.
     */
    public function outcomeAt(CarbonInterface $cutoff): ?DeadlineOutcome
    {
        // So bằng DẤU THỜI GIAN (giây), không bằng Carbon: trang "Hiệu suất theo kỳ" gọi hàm này cho mọi mốc
        // của kỳ (hàng nghìn mốc một quý), và mỗi lần đọc cast ngày của Eloquent dựng một Carbon mới — bốn
        // Carbon cùng các phép so của chúng là phần lớn thời gian của báo cáo (rà soát làn ops 2026-10-22, số
        // đo ở PROGRESS). Cùng đáp án: mọi cột ở đây lưu tới GIÂY, nên so phần nguyên giây của `$cutoff` (có
        // thể mang micro giây của `now()`) cho cùng kết quả với so Carbon — `OutcomeAtParityTest` ghim.
        $dueEnd = $this->dueEndTimestamp();
        $createdAt = $this->storedTimestamp('created_at');
        $cutoffAt = $cutoff->getTimestamp();

        if ($createdAt !== null && $createdAt > $dueEnd) {
            return null;
        }

        if ($this->is_completed) {
            $completedAt = $this->storedTimestamp('completed_at');

            if ($completedAt === null) {
                return null;
            }

            if ($completedAt <= $dueEnd) {
                return DeadlineOutcome::OnTime;
            }

            if ($completedAt <= $cutoffAt) {
                return DeadlineOutcome::Late;
            }
        }

        if ($dueEnd > $cutoffAt) {
            return null;
        }

        // `$dueEnd` là 23:59:59 của `due_date`; `closedOnOrBefore()` tự lấy hết ngày của nó — cùng ngày. Vụ chưa
        // có `closed_at` thì không thể "đã kết thúc" (`Matter::isClosed()`): khỏi dựng Carbon cho mỗi mốc lỡ.
        $matter = $this->matter;

        if ($matter !== null && ($matter->getAttributes()['closed_at'] ?? null) !== null && $matter->closedOnOrBefore($this->dueEnd())) {
            return null;
        }

        return DeadlineOutcome::Missed;
    }

    /**
     * Hết ngày đến hạn: 23:59:59 của `due_date` theo `APP_TIMEZONE` (M13). MỘT định nghĩa cho hai câu
     * hỏi của kỳ: "xong đúng hạn" ({@see self::outcomeAt()}: `completed_at ≤ dueEnd`) và "người giữ
     * mốc vào ngày đến hạn" (`App\Support\Performance\DeadlineHolderAtDue`: lần đổi người sớm nhất
     * SAU mốc này) — cùng một biên, nên việc làm lúc 23:59:59 ngày đến hạn là "trong hạn" ở cả hai.
     */
    public function dueEnd(): CarbonInterface
    {
        return $this->due_date->copy()->setTime(23, 59, 59);
    }

    /**
     * {@see self::dueEnd()} dưới dạng dấu thời gian Unix, không dựng Carbon nào — cho hai vòng lặp hàng nghìn
     * mốc của trang "Hiệu suất theo kỳ" ({@see self::outcomeAt()}, `DeadlineHolderAtDue`). Đọc phần NGÀY của giá
     * trị lưu (`Y-m-d` hay `Y-m-d H:i:s`, cast `date` bỏ phần giờ) rồi 23:59:59 theo múi giờ mặc định của PHP —
     * Laravel đặt nó bằng `APP_TIMEZONE`, cùng múi giờ cast dùng. `OutcomeAtParityTest` ghim bằng nhau với
     * `dueEnd()->getTimestamp()`.
     */
    public function dueEndTimestamp(): int
    {
        $stored = $this->getAttributes()['due_date'] ?? null;
        $timestamp = is_string($stored) && preg_match('/^\d{4}-\d{2}-\d{2}/', $stored) === 1
            ? strtotime(substr($stored, 0, 10).' 23:59:59')
            : false;

        return $timestamp !== false ? $timestamp : $this->dueEnd()->getTimestamp();
    }

    /** Dấu thời gian Unix của một cột ngày-giờ đã lưu (chuỗi `Y-m-d H:i:s`), không qua cast; rỗng là `null`. */
    private function storedTimestamp(string $column): ?int
    {
        $stored = $this->getAttributes()[$column] ?? null;

        if ($stored === null) {
            return null;
        }

        $timestamp = is_string($stored) ? strtotime($stored) : false;

        return $timestamp !== false ? $timestamp : $this->asDateTime($stored)->getTimestamp();
    }

    /** 23:59:59 của ngày (hôm nay + `$days`), dạng chuỗi ngày-giờ cho một cận trên cột `date`. */
    private static function endOfDayAfter(int $days): string
    {
        return today()->addDays($days)->endOfDay()->toDateTimeString();
    }

    /** Ghi mốc nhắc đã gửi (d7, d3, d1, overdue), không ghi trùng. Không tự khoá dòng; job gọi phương thức này phải nạp Deadline bằng lockForUpdate() trong transaction. */
    public function markReminderSent(string $key): void
    {
        $sent = $this->reminders_sent ?? [];

        if (! in_array($key, $sent, true)) {
            $sent[] = $key;
            $this->update(['reminders_sent' => $sent]);
        }
    }

    /**
     * `whereNull('deleted_at')`: một mốc hạn đã bị văn phòng rút khỏi hồ sơ không quay lại tay
     * khách bằng một lần `withTrashed()` — thứ chỉ gỡ `SoftDeletingScope` chứ không đụng tới
     * `ClientPortalScope`. Cùng lý lẽ với {@see Matter::applyClientPortalConstraints()}: một điều
     * kiện chỉ do một scope KHÁC giữ là một điều kiện người khác tắt được, và ở phía nội bộ
     * `withTrashed()` là một công cụ đúng đắn nên nó sẽ được gọi.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->where($this->qualifyColumn('is_published'), true)
            ->whereNull($this->qualifyColumn('deleted_at'))
            ->whereHas('matter');
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /** M11 R5: người đã bấm "Xác nhận" trên một mốc tạo qua AI (Task 12). */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
