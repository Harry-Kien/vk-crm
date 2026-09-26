<?php

namespace App\Actions\Notification;

use App\Mail\Client\StageUpdate;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\StageLog;
use Illuminate\Database\Eloquent\Builder;
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

        return $this->eligibleRecipientsQuery($clientId)->get();
    }

    /**
     * Task 7 (R12, phát hiện `stage/stage-06` nửa "luật sư không biết khách không được báo"):
     * form "Chuyển giai đoạn"/"Thêm cập nhật" gọi hàm này TRƯỚC khi gửi, để cảnh báo luật sư ngay
     * trên form khi sẽ không ai nhận được thư — xem
     * `App\Filament\Admin\Resources\Matters\Actions\Concerns\BuildsStageUpdateSchema::noActivatedAccountWarning()`.
     * Đi qua `eligibleRecipientsQuery()` — CÙNG một điều kiện với `recipientsFor()` — để cảnh báo
     * này không bao giờ lệch với chính Action gửi thư thật.
     */
    public function hasEligibleRecipient(Matter $matter): bool
    {
        return $this->eligibleRecipientsQuery($matter->client_id)->exists();
    }

    /**
     * Một nơi DUY NHẤT đọc "tài khoản cổng nào của một khách hàng đủ điều kiện nhận thư về vụ
     * việc" — `recipientsFor()` (gửi thư thật) và `hasEligibleRecipient()` (cảnh báo trên form,
     * Task 7) đều gọi qua đây, để Task 11 (đưa Action này lên hàng đợi — xem brief Task 7, mục
     * "Controller context") chỉ phải giữ một chỗ khi mang luật này sang, không phải chép lại ba
     * điều kiện dưới đây ở hai nơi rồi để chúng trôi lệch nhau.
     *
     * `is_active` (giữ nguyên từ trước): tài khoản văn phòng đã chủ động khoá thì không nhận —
     * gửi vào đó mâu thuẫn với chính quyết định khoá.
     *
     * `whereNotNull('activated_at')` (R12, phát hiện `intake/intake-04`, `intake/intake-05`):
     * activated_at chỉ được hệ thống ghi khi khách TỰ TAY đổi mật khẩu lần đầu thành công
     * (`App\Filament\Portal\Pages\Auth\ChangePassword::changePassword()`) — bằng chứng DUY NHẤT
     * người nhận làm chủ hộp thư đã gõ. Email tài khoản cổng do nhân sự gõ tay lúc nghe điện
     * thoại, không qua bước xác minh nào; thiếu điều kiện này thì một địa chỉ gõ nhầm nhận được
     * tên khách, mã hồ sơ và nội dung công bố ở MỌI lần cập nhật sau đó, và văn phòng không có
     * tín hiệu nào để biết (đây là toàn bộ nội dung hai phát hiện trên).
     *
     * `whereHas('client')` (rà soát Task 2): `Client::delete()`
     * (`App\Filament\Admin\Resources\Clients\Pages\EditClient` → `DeleteAction`) không tự tắt các
     * `client_users` của khách đó — `is_active` MỘT MÌNH không đủ để loại một tài khoản của khách
     * hàng văn phòng đã xoá mềm. `ClientUser::client()` là `BelongsTo` thường nên mang theo
     * `SoftDeletingScope` của `Client` (cùng lý lẽ đã dùng ở `ClientUser::canAccessPanel()`), nên
     * `whereHas('client')` tự loại đúng những tài khoản mà quan hệ đó rơi về `null`.
     */
    private function eligibleRecipientsQuery(int $clientId): Builder
    {
        return ClientUser::query()
            ->where('client_id', $clientId)
            ->where('is_active', true)
            ->whereNotNull('activated_at')
            ->whereHas('client');
    }
}
