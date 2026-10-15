<?php

namespace App\Actions\Schedule;

use App\Jobs\SendPaymentDueSoonMail;
use App\Mail\Client\PaymentReminder;
use App\Models\Instalment;
use App\Support\Billing\BillingSummary;
use App\Support\Billing\ClientPaymentReminders;
use App\Support\Billing\ClientPaymentSettings;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Thư báo trước hạn cho KHÁCH (làn nt Task 2) — mục lịch `client-payments.due-soon`, 08:15 hằng ngày giờ
 * Việt Nam (`routes/console.php`). Chỉ khi công tắc "Nhắc lịch thanh toán cho khách" bật: tắt thì câu
 * đầu tiên trả về, không đọc tiền, không xếp gì.
 *
 * # Đợt nào ({@see self::candidates()})
 *
 * Đợt `pending` của hợp đồng `active`, còn phải trả > 0 (thu một phần vẫn còn), vụ chưa xoá mềm, có
 * ngày hạn từ HÔM NAY tới hôm nay + N ngày (N = "Báo trước hạn (số ngày)", mặc định 3, đọc lúc chạy).
 * Phán quyết của làn: "rơi vào N ngày tới" là một CỬA SỔ, không phải đúng ngày thứ N — một lượt 08:15 bị
 * lỡ (máy chủ khởi động lại, cron trễ), một đợt mới có hạn gần hơn N ngày (kích hoạt theo giai đoạn, phụ
 * lục) hay một lần giảm N vẫn được báo ở lượt kế tiếp, mà khoá chống trùng giữ "mỗi đợt một thư".
 *  - Đợt theo giai đoạn chưa tới giai đoạn chưa có `due_date` → không báo (khách chưa phải trả).
 *  - Đợt đã QUÁ HẠN (hạn trước hôm nay) → không báo: quá hạn không tự gửi gì cho khách, người quản lý
 *    hợp đồng quyết bằng nút "Gửi thư nhắc".
 *  - Đã thu đủ, đã miễn, đã huỷ, hợp đồng nháp/huỷ/hoàn tất → không báo. Điều kiện "còn phải trả" là
 *    {@see BillingSummary::pendingInstalmentsQuery()} + {@see BillingSummary::collectedExpression()}, cùng
 *    một định nghĩa với `Instalment::scopeOverdue()`, không viết lại.
 *
 * # Ai, và một lần thôi
 *
 * Người nhận: {@see ClientPaymentReminders::recipients()} (R12 + cổng còn cho xem vụ). Chống trùng theo
 * từng người, khoá `due-soon:{đợt}@{ngày hạn}` trong sổ thư ({@see ClientPaymentReminders::alreadyDelivered()},
 * chỉ dòng `sent`): chạy lại trong ngày, ngày hôm sau, hay sau khi đổi N đều không thành thư thứ hai;
 * "Dời hạn" sang ngày khác thì khách được báo cho ngày mới. Ở đây người nhận chỉ dùng để QUYẾT có xếp
 * job không — job tính lại tất cả lúc chạy.
 *
 * # Không transaction, mỗi đợt một `try`
 *
 * Chỉ ĐỌC rồi xếp job `->afterCommit()` (R2) — không khoá hàng tiền nào. Dưới hàng đợi `sync` một transport
 * hỏng ném ngược lên đây: bắt, `report()`, đi tiếp (cùng lý do `RemindOverdueInstalments`); dòng `failed`
 * trong sổ thư là bằng chứng, lượt hôm sau thử lại khi đợt còn trong cửa sổ.
 */
class RemindClientsOfUpcomingInstalments
{
    /** @return array{queued: int, skipped: int} */
    public function __invoke(): array
    {
        return $this->handle();
    }

    /**
     * @return array{queued: int, skipped: int} `queued` — số đợt đã xếp job; `skipped` — số đợt trong
     *                                          cửa sổ mà không còn ai cần nhận (đã nhận, hay không có tài khoản nào).
     */
    public function handle(): array
    {
        $queued = 0;
        $skipped = 0;

        $settings = ClientPaymentSettings::current();

        if (! $settings->isEnabled()) {
            return ['queued' => 0, 'skipped' => 0];
        }

        $instalments = self::candidates($settings->daysBeforeDue())
            ->orderBy('instalments.due_date')
            ->orderBy('instalments.id')
            ->get();

        foreach ($instalments as $instalment) {
            try {
                $matter = $instalment->contract->matter;
                $dueDate = $instalment->due_date->toDateString();
                $key = ClientPaymentReminders::dueSoonKey($instalment, $dueDate);

                $pending = ClientPaymentReminders::recipients($matter)->reject(
                    fn ($recipient): bool => ClientPaymentReminders::alreadyDelivered(PaymentReminder::KIND_DUE_SOON, $key, $recipient),
                );

                if ($pending->isEmpty()) {
                    $skipped++;

                    continue;
                }

                $queued++;

                // Chỉ id + ngày hạn: job đọc lại mọi thứ lúc chạy.
                SendPaymentDueSoonMail::dispatch($instalment->getKey(), $dueDate)->afterCommit();
            } catch (Throwable $e) {
                report($e);
            }
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * Tập đợt cần báo trước hạn với cửa sổ `$days` ngày — MỘT định nghĩa, dùng ở đây và trong job lúc gửi.
     *
     * @return Builder<Instalment>
     */
    public static function candidates(int $days): Builder
    {
        return BillingSummary::pendingInstalmentsQuery()
            ->whereNotNull('instalments.due_date')
            ->where('instalments.due_date', '>=', today()->toDateString())
            // Biên trên viết "trước ngày N + 1", không "<= ngày N": cột `date` của MariaDB so đúng cả hai
            // cách, còn SQLite lưu `Y-m-d H:i:s` và `'… 00:00:00' <= 'Y-m-d'` là sai — đợt hạn đúng ngày N lọt mất.
            ->where('instalments.due_date', '<', today()->addDays($days + 1)->toDateString())
            ->whereRaw('instalments.amount > '.BillingSummary::collectedExpression('instalments.id'))
            ->whereHas('contract.matter')
            ->with(['contract.matter']);
    }
}
