<?php

namespace App\Actions\Schedule;

use App\Actions\Notification\ResolveStaffRecipients;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\User;
use App\Notifications\Staff\UnseenUpdatesAlert;
use App\Support\UnseenStageLogs;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SPEC §4.18, §7.1 mục 5 — dòng tiến độ đã công bố quá {@see UnseenStageLogs::AFTER_DAYS} ngày mà
 * khách chưa mở: luật sư phụ trách được báo TRONG HỆ THỐNG để GỌI ĐIỆN cho khách, 08:30 hằng ngày.
 * KHÔNG một thư nào tới khách: khách không xem thường là khách không dùng được cổng (không nhận
 * được thư báo, hay không biết đăng nhập), nên thêm một thư là thêm một thứ họ sẽ không đọc — thứ
 * cần là một cuộc gọi. Vì vậy Action này không có gì liên quan tới `Mail::`, `OutboundMessage` hay
 * `ResolveClientRecipients`, và cũng không phụ thuộc tài khoản khách có kích hoạt hay không.
 *
 * # MỘT định nghĩa "chưa xem" — {@see UnseenStageLogs}
 *
 * Tập dòng chưa xem là của lớp đó, ĐÚNG câu mà `UnseenUpdatesWidget` dùng (widget ghép thêm
 * `listableBy($user)`; Action không ghép phạm vi người xem, vì người nhận do
 * {@see ResolveStaffRecipients} chọn theo R3). Action KHÔNG có điều kiện `published_at`,
 * `views` hay `is_published_to_portal` nào của riêng nó, và cố ý không lọc `closed_at` — cập nhật
 * cuối trên hồ sơ vừa đóng mà khách chưa thấy là cuộc gọi đáng gọi nhất. Test "agrees with the
 * widget" so thẳng hai bên trên cùng một bộ dữ liệu.
 *
 * # Người nhận (R3) và nội dung
 *
 * `ResolveStaffRecipients::handle($matter, [$matter->leadLawyer])`: luật sư phụ trách nếu còn
 * `is_active` và qua `Gate::view()`; nếu không, chuỗi dự phòng (manager xem được vụ → admin) — không
 * bao giờ im lặng. Một hồ sơ `restricted` vì vậy không lộ mã/tiêu đề cho manager không xem được nó
 * ({@see UnseenUpdatesAlert}).
 *
 * # Chống lặp (R3 của kế hoạch M6, R4): một thông báo cho mỗi (người nhận, hồ sơ) mỗi "lô chưa xem"
 *
 * Không thêm cột "đã nhắc lúc nào". "Lô chưa xem" được khoá bằng dòng chưa xem MỚI NHẤT của hồ sơ
 * (`published_at` giảm dần, rồi `id`): thông báo mang `viewData = {matter_id, stage_log_id}` và
 * {@see self::alreadyNotified()} tra bảng `notifications` theo (người nhận, `stage_log_id`) — cùng
 * hình dạng `CheckStaleMatters::alreadyNotified()`, chỉ khác khoá "lô" là dòng mới nhất thay vì
 * `created_at >= đầu đợt`.
 *
 *  - Hồ sơ có ba dòng chưa xem → MỘT thông báo (khoá theo dòng mới nhất), không phải ba.
 *  - Chạy Action hai lần liên tiếp → lần hai không thêm gì (R4).
 *  - Một dòng MỚI được công bố và lại quá 5 ngày chưa xem → dòng mới nhất đổi → nhắc lại. Còn một
 *    dòng mới trong 5 ngày đầu thì CHƯA thuộc định nghĩa, nên dòng chưa xem mới nhất vẫn là dòng cũ
 *    và không nhắc lại.
 *  - Khách mở trang → dòng biến khỏi định nghĩa → không nhắc nữa.
 *
 * Hệ quả cần nói ra, vì trí nhớ chống lặp CHÍNH LÀ bảng `notifications` (R3, không cột riêng): nếu
 * luật sư xoá thông báo ở chuông trong lúc khách vẫn chưa mở, dòng đó không còn nữa nên 08:30 hôm
 * sau nó hiện lại — một lần mỗi lần xoá, không hơn (test "comes back once if the lawyer clears
 * it"). Hàng đợi gọi điện bền vững vẫn là widget "Khách chưa xem cập nhật". Và nếu người nhận đổi
 * (luật sư phụ trách bị thay), người mới chưa có thông báo nào cho lô này nên được báo một lần.
 *
 * # Khoá dòng + đọc lại TRONG transaction; thông báo ra NGOÀI transaction (luật kiến trúc)
 *
 * Danh sách hồ sơ ứng viên dựng TRƯỚC vòng lặp; {@see self::processOne()} khoá dòng `matters`
 * (`lockForUpdate()` — dòng `matters` luôn khoá trước) rồi đọc lại ĐÚNG định nghĩa đó cho hồ sơ này
 * — một lần khách mở trang, hay hồ sơ bị gỡ khỏi cổng, đã commit ở nơi khác trong lúc chờ tới lượt
 * vẫn được thấy. Transaction chỉ khoá và đọc; `->notify(` thật sự chạy SAU khi commit
 * (`tests/Feature/ArchitectureTest.php` cấm `Notification::send`/`->notify(` trong
 * `DB::transaction()` ở `app/Actions`).
 */
class RemindUnseenUpdates
{
    public function __invoke(): array
    {
        return $this->handle();
    }

    /**
     * @return array{notified: int}
     */
    public function handle(): array
    {
        $notified = 0;

        $candidates = UnseenStageLogs::query()
            ->distinct()
            ->orderBy('matter_id')
            ->pluck('matter_id');

        foreach ($candidates as $id) {
            try {
                $this->processOne($id, $notified);
            } catch (Throwable $e) {
                // Một hồ sơ lỗi không được dừng cả vòng lặp — cùng lý lẽ `CheckStaleMatters::handle()`.
                report($e);
            }
        }

        return ['notified' => $notified];
    }

    /**
     * Một hồ sơ, một transaction — tách khỏi {@see self::handle()} để `foreach` bắt lỗi gọn.
     *
     * `$notice` (`array{matter: Matter, recipients: Collection<int, User>, newest: StageLog,
     * oldest: StageLog, count: int}|null`) mang dữ liệu cho thông báo ra NGOÀI closure của
     * transaction — xem docblock lớp.
     */
    private function processOne(int $id, int &$notified): void
    {
        $notice = null;

        DB::transaction(function () use ($id, &$notice): void {
            /** @var Matter|null $matter */
            $matter = Matter::query()->whereKey($id)->lockForUpdate()->first();

            if ($matter === null) {
                return;
            }

            // Đọc lại ĐÚNG định nghĩa đã dựng danh sách ứng viên, TRONG transaction, sau khi đã khoá
            // dòng: khách vừa mở trang hay hồ sơ vừa gỡ khỏi cổng ở nơi khác phải loại hồ sơ này ra.
            $unseen = UnseenStageLogs::query()
                ->where('matter_id', $matter->getKey())
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->get();

            if ($unseen->isEmpty()) {
                return;
            }

            $recipients = app(ResolveStaffRecipients::class)->handle($matter, [$matter->leadLawyer]);

            if ($recipients->isNotEmpty()) {
                $notice = [
                    'matter' => $matter,
                    'recipients' => $recipients,
                    'newest' => $unseen->first(),
                    'oldest' => $unseen->last(),
                    'count' => $unseen->count(),
                ];
            }
        });

        if ($notice === null) {
            return;
        }

        /** @var Collection<int, User> $recipients */
        $recipients = $notice['recipients'];

        foreach ($recipients as $recipient) {
            // Mỗi người một `try` — một lần ghi hỏng cho người này không làm những người nhận còn
            // lại mất thông báo (cùng `CheckStaleMatters`).
            try {
                if ($this->alreadyNotified($recipient, $notice['newest'])) {
                    continue;
                }

                $recipient->notify(new UnseenUpdatesAlert(
                    $notice['matter'],
                    $notice['newest'],
                    $notice['oldest'],
                    $notice['count'],
                ));
                $notified++;
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Người này đã có thông báo cho dòng chưa xem mới nhất này chưa: khoá là (người nhận,
     * `viewData.stage_log_id`). Một dòng tiến độ thuộc đúng MỘT hồ sơ nên `stage_log_id` đã định
     * danh cả hồ sơ lẫn "lô" — thêm `matter_id` vào truy vấn là một điều kiện chết (mutation probe:
     * gỡ nó không làm test nào đỏ), nên không có. `matter_id` vẫn nằm trong `viewData` để người đọc
     * dòng thông báo biết nó nói về hồ sơ nào. Không có `created_at` nào ở đây: dòng chưa xem mới
     * nhất tự nó định danh lô, nên một lô mới không bị chặn bởi thông báo của lô cũ.
     */
    private function alreadyNotified(User $recipient, StageLog $newest): bool
    {
        return $recipient->notifications()
            ->where('type', UnseenUpdatesAlert::class)
            ->where('data->viewData->stage_log_id', $newest->getKey())
            ->exists();
    }
}
