<?php

namespace App\Jobs;

use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\ClientRequestStatus;
use App\Mail\Staff\MatterReassigned;
use App\Models\ClientRequest;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * SPEC §6.11 bước 3 ("gửi email tổng hợp danh sách mốc hạn cho người nhận") — M6.5 Task 4 đổi
 * `lead_lawyer_id` và chuyển mốc hạn nhưng KHÔNG gửi thư này (hạ tầng thư xếp hàng của Task 11
 * chưa tồn tại lúc đó); M7 Task 1 dựng nó trên hạ tầng đã có, cùng phán quyết R2 ("mọi thư đi
 * qua hàng đợi, sau khi commit, không bao giờ nằm trong `DB::transaction`").
 *
 * **Dựng để dùng lại được cho CẢ LÔ** (phán quyết controller Task 1, cho M7 Task 2 — màn hình
 * bàn giao hàng loạt, chưa tới lượt ở milestone này): job mang MỘT `$newLeadId` và một mảng
 * `$matters` keyed theo `matter_id`, mỗi phần tử là {@see self::__construct()} — CHỈ id (không
 * model), cùng kỷ luật `SendDeadlineReminderMail`: không tin bất kỳ ảnh chụp nào, dựng lại TOÀN
 * BỘ nội dung tại thời điểm gửi. Một lần bàn giao MỘT vụ (`ReassignMatter::handle()`, Task 1)
 * là trường hợp lô có đúng MỘT phần tử.
 *
 * # Dựng lại lúc gửi, không tin ảnh chụp
 *
 * `App\Actions\Matter\ReassignMatter::handle()` tính `deadline_ids`/`client_request_ids` NGAY
 * LÚC BÀN GIAO, dưới khoá, trong transaction của chính nó — đúng những gì lần bàn giao NÀY
 * chuyển. Nhưng giữa lúc đó và lúc job này THẬT SỰ chạy (hàng đợi có thể trễ), trạng thái có thể
 * đã đổi: một mốc có thể đã được đánh dấu xong, hay đã bị bàn giao TIẾP cho một người thứ ba
 * (vòng bàn giao khác chen vào), hay cả vụ việc có thể đã bị siết `restricted` và bàn giao tiếp
 * khiến `$newLeadId` không còn xem được nó nữa. `handle()` dưới đây hỏi lại CSDL cho từng vụ,
 * từng mốc, từng yêu cầu khách — không tin payload nói gì ngoài "đây là những id CÓ THỂ còn liên
 * quan, đi kiểm tra lại".
 *
 * # Lọc theo vụ TRƯỚC, theo mốc SAU
 *
 * {@see ResolveStaffRecipients::qualifies()} là NƠI DUY NHẤT quyết định "người này còn xem được
 * vụ việc này không" (R3 của kế hoạch M6.5, dùng lại nguyên vẹn ở M7) — dùng lại y hệt ở đây,
 * không viết lại luật đó.
 * Một vụ mà `$newLeadId` không còn qua được (không `is_active`, đã xoá mềm, hay không còn
 * `Gate::view()` — ví dụ vụ `restricted` vừa bàn giao tiếp cho người khác) bị loại KHỎI TOÀN BỘ
 * lô, không chỉ lọc mốc của riêng nó: một người không còn xem được vụ việc không nên thấy BẤT KỲ
 * điều gì về nó, kể cả "vụ này không có mốc nào được chuyển".
 *
 * Với một vụ CÒN qua được, mốc hạn được lọc riêng: còn tồn tại, CHƯA hoàn thành, và HIỆN TẠI vẫn
 * do `$newLeadId` phụ trách (không phải một ảnh chụp "đã từng do họ phụ trách lúc bàn giao").
 * **Vụ không có mốc nào (dù ban đầu có mốc trong payload, hay payload rỗng ngay từ đầu — vụ Task
 * 1, R8) vẫn CÓ MẶT trong thư**, chỉ danh sách rỗng — view hiện câu "không có mốc hạn nào được
 * chuyển": lead mới cần biết mình vừa nhận vụ, kể cả khi không có việc gấp nào đi kèm.
 *
 * Yêu cầu khách CHƯA ĐÓNG đã chuyển được ĐẾM lại theo cùng nguyên tắc (còn tồn tại, còn gán cho
 * `$newLeadId`, chưa đóng) — chỉ hiện SỐ LƯỢNG (R8: "không lưu danh sách id mốc vào audit chỉ để
 * job đọc lại" áp dụng cùng tinh thần cho nội dung thư: đếm là đủ, không cần liệt kê từng yêu
 * cầu).
 *
 * **Không còn vụ nào qua được lọc thì KHÔNG gửi gì cả** — cùng "người nhận bị vô hiệu hoá giữa
 * lúc xếp hàng và lúc gửi" của brief Task 1: một người mất `is_active` khiến MỌI vụ trong lô đều
 * rớt ở bước `qualifies()` (điều kiện đầu tiên của nó), nên lô rỗng và không có gì để gửi — không
 * cần một điều kiện `is_active` RIÊNG ở đây.
 *
 * # Người nhận CHỈ qua ResolveStaffRecipients (R3)
 *
 * Thư này CHỈ có một người nhận — chính lead mới — nên không cần dựng danh sách "ưu tiên" như
 * `CheckDeadlines::recipientsFor()`; nhưng việc "người này còn hợp lệ không" vẫn phải hỏi
 * {@see ResolveStaffRecipients}, không tự kiểm `is_active` bằng tay ở đây (đã có ở
 * `qualify()` bên trong `ResolveStaffRecipients`, dùng lại qua `qualifies()` per-matter ở trên).
 *
 * # `failed()` — cùng hình dạng `SendDeadlineReminderMail::failed()`
 *
 * Job hỏng HẲN (hết `$tries`) ghi một dòng audit (`matter_reassignment_digest_failed`, chỉ mang
 * `new_lead_id` + `matter_ids` — SPEC §10.6, không ghi gì khác) và báo TRONG HỆ THỐNG cho chính
 * người nhận (không phải `ResolveStaffRecipients::supervisorsFor()` — đây không phải một sự cố
 * của MỘT vụ việc cụ thể để mà hỏi "ai giám sát vụ này", mà là thư của chính người nhận không
 * tới tay chính họ; phán quyết controller Task 1 nói thẳng "cho người nhận").
 */
class SendReassignmentDigest implements ShouldQueue
{
    use Queueable;

    /** Một lần hỏng thoáng qua (SMTP chết tạm) không cần báo động ngay; xem `backoff()`. */
    public int $tries = 5;

    /**
     * @param  array<int, array{deadline_ids: array<int, int>, client_request_ids: array<int, int>, reason: string}>  $matters
     *                                                                                                                          Keyed theo `matter_id`. `deadline_ids`/`client_request_ids` là những id
     *                                                                                                                          CÓ THỂ còn liên quan lúc bàn giao — `handle()` hỏi lại CSDL, không tin
     *                                                                                                                          chúng còn đúng lúc job chạy (xem docblock lớp). `reason` là lý do bàn
     *                                                                                                                          giao của CHÍNH lần này (một chuỗi cố định, không cần dựng lại — khác
     *                                                                                                                          hẳn mốc/yêu cầu khách, không có khái niệm "còn hợp lệ hay không" cho
     *                                                                                                                          một chuỗi lịch sử).
     */
    public function __construct(
        public readonly int $newLeadId,
        public readonly array $matters,
    ) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        $newLead = User::query()->find($this->newLeadId);

        if ($newLead === null) {
            return;
        }

        $resolver = app(ResolveStaffRecipients::class);
        $blocks = [];

        foreach ($this->matters as $matterId => $ids) {
            $matter = Matter::query()->find($matterId);

            // Vụ đã xoá mềm, hay người nhận không còn is_active/Gate::view() được nữa (đã bàn
            // giao tiếp, hay vụ vừa siết restricted) — loại HẲN khối này, không chỉ lọc mốc của
            // riêng nó. Xem docblock lớp, mục "Lọc theo vụ TRƯỚC, theo mốc SAU".
            if ($matter === null || ! $resolver->qualifies($newLead, $matter)) {
                continue;
            }

            $deadlines = Deadline::query()
                ->whereKey($ids['deadline_ids'] ?? [])
                ->where('is_completed', false)
                ->where('responsible_user_id', $newLead->getKey())
                ->orderBy('due_date')
                ->get();

            $clientRequestsMoved = ClientRequest::query()
                ->whereKey($ids['client_request_ids'] ?? [])
                ->where('assigned_to', $newLead->getKey())
                ->where('status', '!=', ClientRequestStatus::Closed->value)
                ->count();

            $blocks[] = [
                'matter' => $matter,
                'deadlines' => $deadlines,
                'client_requests_moved' => $clientRequestsMoved,
                'reason' => $ids['reason'] ?? '',
            ];
        }

        // "Không còn gì thì không gửi" (brief Task 1) — mọi vụ trong lô đã rớt ở bước qualifies().
        if ($blocks === []) {
            return;
        }

        Mail::to($newLead->email)->send(new MatterReassigned($newLead, $blocks));
    }

    /**
     * Chạy đúng MỘT lần, sau khi CẢ `$tries` lần đều thất bại — cùng hình dạng
     * `SendDeadlineReminderMail::failed()`. `?Throwable $exception` không dùng tới, cùng lý do:
     * chi tiết lỗi đã nằm ở `outbound_messages.error` (nếu thư kịp mở dòng nhật ký trước khi
     * hỏng); dòng audit này chỉ ghi ĐÃ HỎNG, không diễn giải TẠI SAO.
     */
    public function failed(?Throwable $exception): void
    {
        Audit::record('matter_reassignment_digest_failed', null, [
            'new_lead_id' => $this->newLeadId,
            'matter_ids' => array_keys($this->matters),
        ]);

        $newLead = User::query()->find($this->newLeadId);

        if ($newLead === null) {
            return;
        }

        Notification::make()
            ->title(__('reassign.digest_failed_notification.title'))
            ->body(__('reassign.digest_failed_notification.body'))
            ->color('danger')
            ->sendToDatabase($newLead);
    }
}
