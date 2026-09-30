<?php

namespace App\Actions\Schedule;

use App\Actions\Notification\ResolveStaffRecipients;
use App\Jobs\SendInstalmentOverdueMail;
use App\Mail\Staff\InstalmentOverdue;
use App\Models\Instalment;
use App\Support\Billing\AccountantBillingRow;
use App\Support\Billing\BillingSummary;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * SPEC §6.8 (đính chính M9) — nhắc NỘI BỘ cho đợt thanh toán quá hạn, chạy 08:00 hằng ngày.
 *
 * **Chọn đợt: đúng một định nghĩa "quá hạn".** {@see self::candidates()} là `Instalment::overdue()`
 * (`status = pending`, `due_date` trước hôm nay, hợp đồng `active`, còn phải thu > 0 — một đợt thu
 * MỘT PHẦN đã quá ngày vẫn quá hạn) cộng đúng MỘT điều kiện của tác vụ này: vụ việc chưa xoá mềm.
 * Không viết lại điều kiện quá hạn ở đây. Vụ ĐÃ KẾT THÚC (`closed_at`) vẫn được nhắc — nợ không biến
 * mất khi đóng hồ sơ — nên KHÁC `CheckDeadlines`, tác vụ bỏ qua vụ đã đóng vì một mốc thời hạn của
 * vụ đã đóng không còn việc để làm. Tác vụ này không đọc `Matter::scopeOpen()` một chút nào; test
 * "still reminds about a matter that has been closed" ghim điều đó.
 *
 * **Nhịp: ngày đầu tiên quá hạn, rồi 7 ngày một lần, tới khi thu đủ, miễn hoặc huỷ.** Không có cột
 * `reminders_sent` (khác `deadlines`, §4.13): trí nhớ chống trùng là sổ thư `outbound_messages`
 * (M6 R3), theo TỪNG người nhận — xem {@see SendInstalmentOverdueMail::alreadyReminded()}, MỘT
 * định nghĩa dùng cả ở đây (quyết định có xếp job không) lẫn trong job (quyết định có gửi không).
 * "Tới khi thu đủ, miễn hoặc huỷ" không cần mã riêng: đợt đó thôi khớp {@see self::candidates()}.
 *
 * **Người nhận: {@see ResolveStaffRecipients::forBilling()} với
 * {@see ResolveStaffRecipients::billingAudienceFor()}** — quyết định vai trò (vụ thường: luật sư
 * phụ trách + kế toán; vụ `restricted`: luật sư phụ trách + admin) nằm trong lớp đó, không ở đây,
 * và chuỗi dự phòng R3 bảo đảm không bao giờ im lặng. Người nhận được tính ở đây CHỈ để quyết định
 * có xếp job hay không; job tính lại từ đầu lúc chạy ("re-derive the audience at send time").
 *
 * **Không có thư cho khách (P1).** Muốn nhắc khách là một phán quyết mới, và phải theo M6.5 R12
 * (chỉ tài khoản `is_active` và đã `activated_at`).
 *
 * **Không có transaction, không ghi dòng tiền nào.** Tác vụ chỉ ĐỌC (đợt, vụ việc, sổ thư) rồi xếp
 * job; nên không có khoá `matters` → `contracts` → `instalments` → `payments` nào để giữ, và
 * `Mail::` không thể nằm trong một `DB::transaction` ở đây (luật kiến trúc của `app/Actions`).
 * `->afterCommit()` vẫn được gọi cho trường hợp một nơi gọi bọc tác vụ trong transaction của nó: job
 * chỉ được đẩy khi transaction ngoài đó commit (M6.5 R2).
 *
 * **Mỗi đợt một `try/catch`.** Dưới hàng đợi `sync` của bộ test (và của shared hosting nếu ai đó đặt
 * `QUEUE_CONNECTION=sync`), job chạy ĐỒNG BỘ ngay trong `dispatch()`: một transport hỏng ném ngược
 * lên tới đây. Bắt và đi tiếp — một đợt hỏng không được làm hỏng cả lượt hay để lộ ra thành lỗi 500.
 * Bằng chứng của một thư hỏng là dòng `failed` trong `outbound_messages` do transport ghi; lượt chạy
 * ngày mai gửi lại vì chỉ dòng `sent` mới chặn.
 *
 * **URL không dựng ở đây.** Liên kết tới trang "Công nợ" hay tab tiền của vụ cần lớp Filament, mà
 * `App\Actions` không được dùng Filament (`ArchitectureTest`): {@see InstalmentOverdue}.
 */
class RemindOverdueInstalments
{
    /**
     * @return array{reminded: int, skipped: int} `reminded` — số đợt đã xếp job; `skipped` — số đợt
     *                                            quá hạn nhưng mọi người nhận đã được nhắc trong 7 ngày, hoặc không có ai để nhận.
     */
    public function __invoke(): array
    {
        return $this->handle();
    }

    /**
     * @return array{reminded: int, skipped: int}
     */
    public function handle(): array
    {
        $reminded = 0;
        $skipped = 0;
        $resolver = app(ResolveStaffRecipients::class);

        $instalments = self::candidates()
            ->orderBy('instalments.due_date')
            ->orderBy('instalments.id')
            ->get();

        foreach ($instalments as $instalment) {
            try {
                $matter = $instalment->contract->matter;

                $recipients = $resolver->forBilling($matter, $resolver->billingAudienceFor($matter)->all());

                $dueDate = $instalment->due_date->toDateString();

                $pending = $recipients->reject(
                    fn ($recipient): bool => SendInstalmentOverdueMail::alreadyReminded($instalment, $recipient, $dueDate),
                );

                if ($pending->isEmpty()) {
                    $skipped++;

                    continue;
                }

                $reminded++;

                // Chỉ mang id + ngày đến hạn (SPEC §10.5): job tự đọc lại đợt, hợp đồng, vụ việc,
                // người nhận và sổ thư lúc nó thật sự chạy.
                SendInstalmentOverdueMail::dispatch($instalment->getKey(), $dueDate)->afterCommit();
            } catch (Throwable $e) {
                report($e);
            }
        }

        return ['reminded' => $reminded, 'skipped' => $skipped];
    }

    /**
     * Tập đợt cần nhắc — MỘT định nghĩa, dùng cả bởi {@see self::handle()} lẫn job lúc gửi.
     *
     * `BillingSummary::pendingInstalmentsQuery()` cho sẵn cột `collected_amount` /
     * `outstanding_amount` (một câu SQL cho cả tập, không N+1) mà {@see AccountantBillingRow}
     * đọc; `overdue()` là điều kiện quá hạn duy nhất; `whereHas('contract.matter')` để
     * `SoftDeletingScope` loại vụ đã xoá mềm (một vụ xoá mềm mà còn nợ là dữ liệu lỡ có — M9 chặn
     * việc đó ở `Matter::deleting`, nhưng tác vụ không tin điều đó).
     *
     * @return Builder<Instalment>
     */
    public static function candidates(): Builder
    {
        return BillingSummary::pendingInstalmentsQuery()
            ->overdue()
            ->whereHas('contract.matter')
            ->with(['contract.matter.matterType', 'contract.matter.client']);
    }
}
