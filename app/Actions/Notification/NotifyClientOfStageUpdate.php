<?php

namespace App\Actions\Notification;

use App\Enums\OutboundStatus;
use App\Mail\Client\StageUpdate;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\StageLog;
use App\Support\Scopes\ClientPortalScope;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Throwable;

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
 *
 * # M6.5 Task 11 (`stage/stage-01`, `notify/notify-1`) — một người nhận hỏng không được chặn người khác
 *
 * Trước Task 11, `foreach` gọi `Mail::to()->send()` cho từng người nhận và KHÔNG bắt lỗi: một
 * transport hỏng ở người nhận thứ nhất (ví dụ hộp thư đã đầy) làm ngoại lệ thoát khỏi `handle()`
 * ngay lập tức — người nhận thứ hai (ví dụ người chồng, khi người vợ đứng trước trong danh sách)
 * KHÔNG BAO GIỜ được gọi tới, dù transport của họ có sống hay không. Bây giờ mỗi người nhận được
 * thử ĐỘC LẬP: một người hỏng không chặn những người còn lại trong CÙNG một lượt gọi. Ngoại lệ đầu
 * tiên gặp phải được NÉM LẠI sau khi đã thử hết danh sách, để hàng đợi (Task 11,
 * `App\Listeners\SendStageUpdateNotification::$tries`/`$backoff`) coi lượt này là thất bại và thử
 * lại — dòng `outbound_messages` `failed` của `OutboundLedgerTransport` đã ghi lý do cho từng
 * người nhận hỏng, không phụ thuộc vào việc `handle()` có ném lại hay không.
 *
 * **Vì sao lần thử lại không gửi trùng cho người đã nhận.** Một lượt gọi lại (job hàng đợi thử lại
 * sau khi lượt trước ném lỗi) chạy lại TOÀN BỘ `recipientsFor()` — kể cả người đã nhận thành công
 * ở lượt trước, vì `notified_at` của CẢ DÒNG chỉ được ghi khi MỌI người nhận đều xong, không phải
 * theo từng người. `alreadyDelivered()` bên dưới hỏi thẳng nhật ký `outbound_messages` (SPEC
 * §4.15) — nguồn sự thật duy nhất về "đã tới nơi chưa" theo TỪNG người nhận — trước khi gửi lại,
 * nên người đã nhận không nhận thêm bản thứ hai chỉ vì người khác trong cùng dòng từng hỏng.
 *
 * # Lời hứa đã BỎ (`notify/notify-13`)
 *
 * Bản trước ghi "không đánh dấu đã báo: khi văn phòng mở lại một tài khoản cổng khách, lời báo
 * này phải còn nguyên" — SAI: không có job, lịch hay hook nào quét `stage_logs.notified_at IS
 * NULL` để gửi lại khi một tài khoản được kích hoạt sau đó. `StageLogPublished` chỉ bắn ĐÚNG MỘT
 * LẦN, lúc tạo dòng. Lời hứa đó bị bỏ hẳn, không viết lại bằng lời khác: khi KHÔNG có tài khoản
 * nào đủ điều kiện tại thời điểm công bố, dòng đó sẽ không bao giờ tự được báo sau này. Điều thay
 * thế nó không phải một cơ chế gửi lại, mà là một CẢNH BÁO TRƯỚC (Task 7): form "Chuyển giai
 * đoạn"/"Thêm cập nhật" gọi {@see self::hasEligibleRecipient()} — CÙNG điều kiện với
 * {@see self::eligibleRecipientsQuery()} bên dưới — để luật sư thấy cảnh báo NGAY TRÊN FORM trước
 * khi bấm gửi, thay vì tin rằng khách đã được báo.
 */
class NotifyClientOfStageUpdate
{
    public function handle(StageLog $stageLog): int
    {
        if ($stageLog->notified_at !== null) {
            return 0;
        }

        // Final review B-M2: hỏi lại NGAY LÚC GỬI — trong cửa sổ hàng đợi dòng tiến độ có thể đã bị
        // rút khỏi cổng, hoặc vụ việc đã tắt công bố. Không gửi, không đánh dấu `notified_at`.
        if (! $this->stillReleasedToPortal($stageLog)) {
            return 0;
        }

        $recipients = $this->recipientsFor($stageLog);

        if ($recipients->isEmpty()) {
            // Không đánh dấu đã báo — nhưng không có gì tự gửi lại sau này (xem docblock lớp:
            // lời hứa "mở lại tài khoản thì lời báo còn nguyên" đã bị bỏ). Task 7 cảnh báo TRƯỚC,
            // trên chính form, thay cho một cơ chế gửi lại không tồn tại.
            return 0;
        }

        $sent = 0;
        $failure = null;

        foreach ($recipients as $recipient) {
            if ($this->alreadyDelivered($stageLog, $recipient)) {
                $sent++;

                continue;
            }

            try {
                Mail::to($recipient->email)->send(new StageUpdate($stageLog, $recipient));
                $sent++;
            } catch (Throwable $e) {
                // Giữ lại NGOẠI LỆ ĐẦU TIÊN gặp phải, nhưng không dừng vòng lặp: những người nhận
                // còn lại vẫn phải được thử (xem docblock lớp).
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }

        $stageLog->forceFill(['notified_at' => now()])->saveQuietly();

        return $sent;
    }

    /**
     * Đã có một dòng `sent` trong nhật ký thư (SPEC §4.15) cho ĐÚNG dòng tiến độ này và ĐÚNG địa
     * chỉ này chưa — dùng để một lượt thử lại (retry của hàng đợi) không gửi thêm một bản cho
     * người đã nhận, xem docblock lớp.
     *
     * `withoutGlobalScopes()`: cùng lý do `RecordOutboundMessage::find()` phải bỏ — bảng này bị
     * `RestrictedToClientPortal` chặn sạch khi có khách đang mở cổng, và listener/job của Task 11
     * có thể chạy trong một tiến trình worker mà ngữ cảnh đó vẫn còn treo.
     */
    private function alreadyDelivered(StageLog $stageLog, ClientUser $recipient): bool
    {
        return OutboundMessage::query()
            ->withoutGlobalScopes()
            ->where('related_type', $stageLog->getMorphClass())
            ->where('related_id', $stageLog->getKey())
            ->where('recipient', $recipient->email)
            ->where('status', OutboundStatus::Sent)
            ->exists();
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
     * Final review B-M2: đọc TƯƠI từ CSDL (không tin bản trong bộ nhớ của `$stageLog`, có thể đã
     * cũ từ lúc xếp hàng) rằng dòng tiến độ còn `is_published` VÀ vụ việc còn
     * `is_published_to_portal`. Vụ đã xoá mềm do `recipientsFor()` lo (quan hệ `matter` mang
     * `SoftDeletingScope`), không lặp lại ở đây.
     */
    private function stillReleasedToPortal(StageLog $stageLog): bool
    {
        $fresh = StageLog::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->whereKey($stageLog->getKey())
            ->first(['id', 'matter_id', 'is_published']);

        if ($fresh === null || ! $fresh->is_published) {
            return false;
        }

        return Matter::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->whereKey($fresh->matter_id)
            ->where('is_published_to_portal', true)
            ->exists();
    }

    /**
     * Final review B-M3: thư báo tiến độ đã hỏng HẲN (listener hết `$tries`). Báo trong hệ thống
     * cho luật sư phụ trách — qua {@see ResolveStaffRecipients::handle()} (R3: còn đi làm, xem được
     * vụ; lead không nhận được thì rơi xuống chuỗi dự phòng của chính resolver đó), để văn phòng
     * biết khách CHƯA được báo và liên hệ bằng kênh khác. Vụ việc không còn (đã xoá cứng) thì
     * không có ai để báo về nó.
     */
    public function reportFailure(StageLog $stageLog): void
    {
        $matter = Matter::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->withTrashed()
            ->find($stageLog->matter_id);

        if ($matter === null) {
            return;
        }

        $recipients = app(ResolveStaffRecipients::class)->handle($matter, [$matter->leadLawyer]);

        foreach ($recipients as $recipient) {
            // `notifyNow()`, không `sendToDatabase()`: bản Filament xếp thêm MỘT job hàng đợi cho
            // mỗi người nhận, trong khi đây chính là lúc một job hàng đợi vừa hỏng hẳn.
            $recipient->notifyNow(Notification::make()
                ->title(__('matters.stage_update_failed_notification.title'))
                ->body(__('matters.stage_update_failed_notification.body', ['code' => $matter->code]))
                ->color('danger')
                ->toDatabase());
        }
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
