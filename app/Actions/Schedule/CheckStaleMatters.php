<?php

namespace App\Actions\Schedule;

use App\Actions\Notification\RecordOutboundMessage;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\OutboundStatus;
use App\Jobs\SendStaleMatterMail;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\Staff\StaleMatterAlert;
use App\Support\MatterStaleness;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SPEC §6.4 — hồ sơ quá hạn cập nhật cho khách, chạy 07:30 hằng ngày. 14 ngày: thông báo TRONG HỆ
 * THỐNG cho luật sư phụ trách. 21 ngày: thư `staff.stale_matter` cho luật sư phụ trách, đồng gửi
 * mọi manager xem được vụ.
 *
 * # Dùng lại hạ tầng M6.5, không viết lại (task-7-brief.md, "Sửa 2026-09-27, sau M6.5 Task 21")
 *
 * Ba điều, cả ba đã có sẵn trước task này:
 *
 *  - **"Vụ đang mở":** không tự viết `whereNull('closed_at')` — mọi truy vấn đi qua
 *    {@see Matter::scopeOpen()} (R8), gián tiếp qua {@see MatterStaleness::scopeStale()}.
 *  - **MỘT định nghĩa "quá hạn cập nhật":** {@see MatterStaleness::scopeStale()} — ĐÚNG câu mà
 *    `StaleMattersWidget` và cột tô màu của `MattersTable` dùng. Không viết một truy vấn COALESCE
 *    thứ hai: mốc 21 ngày hỏi qua {@see MatterStaleness::olderThan()}, một hàm TRÊN bản ghi đã
 *    tải, không phải một `where` mới.
 *  - **Người nhận theo R3:** {@see self::recipientsFor()} giao TOÀN BỘ việc chọn "luật sư phụ
 *    trách hợp lệ hay ai thế chỗ" và "manager xem được vụ hay admin cho vụ restricted" cho
 *    {@see ResolveStaffRecipients}, không tự lọc `is_active`/`Gate::view()` ở đây.
 *
 * # R5 — tần suất nhắc lại (chỗ SPEC im lặng, phán quyết setup task-7-brief.md)
 *
 * "Đợt đình trệ" bắt đầu ở đồng hồ {@see MatterStaleness::episodeStart()} HIỆN TẠI của vụ việc —
 * một cập nhật công bố cho khách (hay vào giai đoạn mới khi chưa từng cập nhật) TỰ NÓ dời đồng hồ
 * này tới, nên "đợt mới bắt đầu" không cần một cột riêng (R3: không thêm cột "đã nhắc lúc nào" nào
 * khác ngoài `deadlines.reminders_sent`).
 *
 *  - **14 ngày:** MỘT thông báo mỗi người nhận mỗi đợt — chống lặp qua bảng `notifications`
 *    ({@see self::alreadyNotified()}), khoá là (người nhận, `viewData.matter_id`, `created_at` ≥
 *    đầu đợt) — cùng hình dạng {@see CheckDeadlines::alreadyAlerted()}.
 *  - **21 ngày:** không quá MỘT thư mỗi 7 ngày trong lúc còn đình trệ — chống lặp qua
 *    `outbound_messages` ({@see self::recentlyMailed()}): `template = staff.stale_matter`,
 *    `related` = vụ việc, `status = sent` (thư `failed` KHÔNG tính — R3, một lần gửi hỏng vẫn phải
 *    được thử lại, không phải một lần "đã nhắc"), `sent_at` trong 7 ngày GẦN NHẤT (đã bao hàm
 *    "trong đợt hiện tại" — xem docblock hàm đó).
 *
 * # Khoá dòng + đọc lại TRONG transaction — cùng kỷ luật `CheckDeadlines`
 *
 * `handle()` dựng tập ứng viên TRƯỚC vòng lặp (một truy vấn `MatterStaleness::scopeStale()`, ĐÚNG
 * câu widget dùng). `processOne()` khoá dòng `matters` (`lockForUpdate()`) rồi ĐỌC LẠI đúng cùng
 * điều kiện đó — một lần đóng vụ, tắt cổng, hay một cập nhật khách vừa commit ở giao dịch khác
 * trong lúc chờ tới lượt vẫn được thấy, không dùng ảnh chụp lúc `pluck('id')` chạy.
 *
 * # Thư ra khỏi transaction, thông báo trong hệ thống cũng vậy (R2, luật kiến trúc)
 *
 * Transaction của MỖI vụ việc CHỈ khoá dòng, đọc lại điều kiện, và (nếu tới mốc) dispatch
 * {@see SendStaleMatterMail} bằng `->afterCommit()` — không có gì gọi `Mail::`/`->notify(` bên
 * trong nó (`tests/Feature/ArchitectureTest.php` cấm đúng điều đó cho `App\Actions`). Dữ liệu cho
 * thông báo 14 ngày được CHUẨN BỊ trong transaction (chỉ đọc, không ghi) nhưng `->notify(` thật sự
 * chạy NGOÀI nó, sau khi đã commit — cùng hình dạng `CheckDeadlines::processOne()` với
 * `$overdueNotify`.
 */
class CheckStaleMatters
{
    public function __invoke(): array
    {
        return $this->handle();
    }

    /**
     * @return array{notified: int, mailed: int}
     */
    public function handle(): array
    {
        $notified = 0;
        $mailed = 0;

        $candidates = MatterStaleness::scopeStale(Matter::query())
            ->orderBy('id')
            ->pluck('id');

        foreach ($candidates as $id) {
            try {
                $this->processOne($id, $notified, $mailed);
            } catch (Throwable $e) {
                // Một vụ việc lỗi không được dừng cả vòng lặp — cùng lý lẽ `CheckDeadlines::handle()`.
                report($e);
            }
        }

        return ['notified' => $notified, 'mailed' => $mailed];
    }

    /**
     * Một vụ việc, một transaction — tách khỏi {@see self::handle()} để `foreach` bắt lỗi gọn.
     *
     * `$notice` (`array{matter: Matter, recipients: Collection<int, User>, episodeStart:
     * ?Carbon}|null`) mang dữ liệu cho thông báo 14 ngày ra NGOÀI closure của transaction — xem
     * docblock lớp, mục "Thư ra khỏi transaction".
     */
    private function processOne(int $id, int &$notified, int &$mailed): void
    {
        $notice = null;

        DB::transaction(function () use ($id, &$mailed, &$notice): void {
            /** @var Matter|null $matter */
            $matter = Matter::query()->whereKey($id)->lockForUpdate()->first();

            if ($matter === null) {
                return;
            }

            // Đọc lại ĐÚNG cùng điều kiện đã dựng danh sách ứng viên, TRONG transaction, sau khi
            // đã khoá dòng — một lần đóng vụ/tắt cổng/cập nhật khách vừa commit ở nơi khác trong
            // lúc chờ tới lượt phải loại vụ này ra, không dùng ảnh chụp lúc `pluck('id')` chạy.
            if (! MatterStaleness::scopeStale(Matter::query()->whereKey($matter->getKey()))->exists()) {
                return;
            }

            $episodeStart = MatterStaleness::episodeStart($matter);

            $noticeRecipients = $this->recipientsFor($matter, 'notice');

            if ($noticeRecipients->isNotEmpty()) {
                $notice = ['matter' => $matter, 'recipients' => $noticeRecipients, 'episodeStart' => $episodeStart];
            }

            if (
                MatterStaleness::olderThan($matter, MatterStaleness::EMAIL_AFTER_DAYS)
                && ! $this->recentlyMailed($matter)
            ) {
                $mailRecipients = $this->recipientsFor($matter, 'mail');

                if ($mailRecipients->isNotEmpty()) {
                    // `->afterCommit()`: Laravel hoãn việc đẩy job tới khi transaction NÀY thật sự
                    // commit — cùng cơ chế `CheckDeadlines`. Job tự đọc lại vụ việc VÀ tự tính lại
                    // người nhận lúc nó THẬT SỰ chạy (gọi lại chính `self::recipientsFor()`), không
                    // tin ảnh chụp `$mailRecipients` ở đây — xem docblock job.
                    SendStaleMatterMail::dispatch($matter->getKey())->afterCommit();
                    $mailed++;
                }
            }
        });

        if ($notice !== null) {
            foreach ($notice['recipients'] as $recipient) {
                // Task 14 fix round 1 (M2): mỗi người một `try` — một lần ghi hỏng cho người này
                // không được làm những người nhận còn lại mất thông báo.
                try {
                    if ($this->alreadyNotified($recipient, $notice['matter'], $notice['episodeStart'])) {
                        continue;
                    }

                    $recipient->notify(new StaleMatterAlert($notice['matter']));
                    $notified++;
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }
    }

    /**
     * Người nhận theo R3 (M6.5 Task 8), hai hình dạng:
     *
     *  - `'notice'` (14 ngày, trong hệ thống): chỉ luật sư phụ trách — nếu người đó không hợp lệ
     *    (vô hiệu hoá, xoá mềm, không còn `Gate::view()`), {@see ResolveStaffRecipients::
     *    fallbackChain()} tự thế chỗ (luật sư → manager xem được vụ → admin), không im lặng.
     *  - `'mail'` (21 ngày, thư): luật sư phụ trách CỘNG {@see ResolveStaffRecipients::
     *    supervisorsFor()} — "đồng gửi mọi manager" đọc là "mọi manager xem được vụ việc đó",
     *    admin thay manager ở vụ `restricted` (task-7-brief.md, sửa đổi Task 21). Không tự cộng cả
     *    hai vai trò không điều kiện ở đây — `supervisorsFor()` là nơi DUY NHẤT quyết định vai trò
     *    nào, xem docblock của nó.
     *
     * Dùng lại BỞI {@see SendStaleMatterMail::handle()} để tính lại TOÀN BỘ đối tượng nhận thư tại
     * thời điểm gửi (R3, không viết luật nhận thứ hai) — public vì lý do đó.
     *
     * @return Collection<int, User>
     */
    public function recipientsFor(Matter $matter, string $tier): Collection
    {
        $resolver = app(ResolveStaffRecipients::class);
        $preferred = collect([$matter->leadLawyer]);

        if ($tier === 'mail') {
            $preferred = $preferred->merge($resolver->supervisorsFor($matter));
        }

        return $resolver->handle($matter, $preferred->all());
    }

    /**
     * Người này đã có thông báo 14 ngày cho ĐÚNG vụ việc này, trong ĐỢT ĐÌNH TRỆ hiện tại chưa —
     * cùng hình dạng {@see CheckDeadlines::alreadyAlerted()}, cộng điều kiện
     * `created_at >= đầu đợt` (R5): một đợt MỚI (khách vừa được cập nhật) không bị chặn bởi một
     * thông báo của đợt CŨ.
     */
    private function alreadyNotified(User $recipient, Matter $matter, ?Carbon $episodeStart): bool
    {
        $query = $recipient->notifications()
            ->where('type', StaleMatterAlert::class)
            ->where('data->viewData->matter_id', $matter->getKey());

        if ($episodeStart !== null) {
            $query->where('created_at', '>=', $episodeStart);
        }

        return $query->exists();
    }

    /**
     * "Không quá một thư mỗi 7 ngày trong lúc còn đình trệ" (R5) — tra `outbound_messages`, KHÔNG
     * thêm cột (R3). `status = sent`: một thư `failed` vẫn là một dòng (R1) nhưng KHÔNG được tính
     * là "đã nhắc" — đếm cả nó biến một lần gửi hỏng thành một lần im lặng không gửi lại
     * ({@see RecordOutboundMessage} docblock, "Hệ quả cho M6 Task 8").
     *
     * Không có điều kiện `sent_at >= đầu đợt` riêng (R5 nói "trong 7 ngày VÀ trong đợt hiện tại"):
     * hàm này chỉ được gọi SAU khi vụ đã cũ hơn {@see MatterStaleness::EMAIL_AFTER_DAYS} (21) ngày
     * tính từ đầu đợt, mà một thư gửi trong 7 ngày gần nhất luôn muộn hơn đầu đợt ít nhất 14 ngày —
     * nên "trong 7 ngày" đã bao hàm "trong đợt", và một điều kiện thứ hai không test nào có thể đo.
     * Thư của một đợt CŨ (khách đã được cập nhật từ đó) vì vậy không bao giờ chặn đợt mới.
     */
    private function recentlyMailed(Matter $matter): bool
    {
        return OutboundMessage::query()
            ->withoutGlobalScopes()
            ->where('related_type', $matter->getMorphClass())
            ->where('related_id', $matter->getKey())
            ->where('template', 'staff.stale_matter')
            ->where('status', OutboundStatus::Sent)
            ->where('sent_at', '>=', now()->subDays(7))
            ->exists();
    }
}
