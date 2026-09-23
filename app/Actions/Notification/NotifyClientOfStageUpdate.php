<?php

namespace App\Actions\Notification;

use App\Mail\Client\StageUpdate;
use App\Models\ClientUser;
use App\Models\StageLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * SPEC §9 mẫu `client.stage_update`, và SPEC §14 mục 3: "Luật sư chuyển giai đoạn một lần thì
 * khách nhận được email VÀ thấy cập nhật trên portal, không cần thao tác nào thêm."
 *
 * CHỐNG GỬI TRÙNG dùng cột `stage_logs.notified_at` mà SPEC §4.8 đã chỉ định sẵn — không dùng
 * nhật ký thư. Đây là ngoại lệ thứ hai của phán quyết R3, cùng lý lẽ với `deadlines.reminders_sent`:
 * cột này là một phần của chính dòng tiến độ, nên nó đi theo dòng đó khi vụ việc được bàn giao,
 * và nó trả lời được câu "dòng này đã báo cho khách chưa" mà không phải dò một bảng khác.
 *
 * AI NHẬN: mọi tài khoản cổng khách CÒN HOẠT ĐỘNG của khách hàng sở hữu vụ việc. SPEC §4.3 nói rõ
 * một khách hàng có thể có nhiều tài khoản — vợ và chồng là ví dụ trong chính đặc tả — và cả hai
 * đều là người của vụ việc đó, nên cả hai đều được báo. Tài khoản đã khoá thì không: gửi vào một
 * hộp thư văn phòng đã chủ động ngắt là mâu thuẫn với chính quyết định ngắt.
 *
 * KHÔNG tự kiểm tra `is_published` hay `is_published_to_portal` ở đây, và đó là chủ ý chứ không
 * phải thiếu sót: Action này chỉ chạy từ sự kiện `StageLogPublished`, mà sự kiện ấy chỉ được phát
 * khi CẢ HAI điều kiện đã đúng (`TransitionMatterStage` bước 6). Lặp lại điều kiện ở đây tạo ra
 * bản sao thứ hai của một luật, và hai bản sao sẽ lệch nhau. Đổi lại, có một test đi qua ĐÚNG
 * đường sản phẩm — gọi `TransitionMatterStage` thật — chứ không gọi thẳng Action này.
 */
class NotifyClientOfStageUpdate
{
    public function handle(StageLog $stageLog): int
    {
        if ($stageLog->notified_at !== null) {
            return 0;
        }

        $recipients = $this->recipientsFor($stageLog);

        if ($recipients->isEmpty()) {
            // Không đánh dấu đã báo: khi văn phòng mở lại một tài khoản cổng khách, lời báo này
            // phải còn nguyên. Cùng nguyên tắc với nhắc mốc thời hạn.
            return 0;
        }

        foreach ($recipients as $recipient) {
            Mail::to($recipient->email)->send(new StageUpdate($stageLog, $recipient));
        }

        $stageLog->forceFill(['notified_at' => now()])->saveQuietly();

        return $recipients->count();
    }

    /** @return Collection<int, ClientUser> */
    public function recipientsFor(StageLog $stageLog): Collection
    {
        $clientId = $stageLog->matter?->client_id;

        if ($clientId === null) {
            return collect();
        }

        return ClientUser::query()
            ->where('client_id', $clientId)
            ->where('is_active', true)
            ->get();
    }
}
