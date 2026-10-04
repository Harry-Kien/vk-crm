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
 * {@see self::recipientsFor()} (cả hai đi qua {@see ResolveClientRecipients}) — để luật sư thấy
 * cảnh báo NGAY TRÊN FORM trước khi bấm gửi, thay vì tin rằng khách đã được báo.
 *
 * # Task 3 — luật người nhận tách ra dùng chung
 *
 * `eligibleRecipientsQuery()` (ba điều kiện R12: `is_active`, `activated_at` không null,
 * `whereHas('client')`) đã chuyển ra {@see ResolveClientRecipients}, MỘT chỗ định nghĩa duy nhất
 * — Task 3 cần đúng luật này cho ba mẫu thư mới (`client.document_published`,
 * `client.document_rejected`, và phần "nhân sự đặt lại quyền truy cập" của `client.activation`),
 * và chép luật này thêm ba lần là chép một luật bảo mật thêm ba lần. Lớp này giờ chỉ còn GỌI LẠI
 * class đó, không giữ điều kiện nào của riêng mình nữa.
 *
 * # Cảnh báo trên form và việc gửi thư hỏi CÙNG một câu (rà soát cuối M7, I3)
 *
 * `handle()` gửi cho {@see self::eligibleRecipients()}: tài khoản đủ điều kiện (R12, qua
 * {@see ResolveClientRecipients}) LỌC QUA {@see ResolveClientRecipients::onPortal()} (vụ còn trên
 * cổng của người đó). {@see self::hasEligibleRecipient()} hỏi đúng hai bước đó, qua cùng
 * {@see self::recipientsOnPortal()}: form cảnh báo ⇔ sẽ không ai nhận thư (khi hai cờ công bố đang
 * bật — form tự hỏi cờ vụ, cờ dòng là công tắc trên chính form). Trước bản sửa, form chỉ hỏi bước
 * đầu: vụ đã kết thúc và quá hạn tra cứu, tài khoản khách còn hoạt động nhờ một vụ khác → form im
 * lặng, luật sư bấm công bố, không thư nào đi. {@see self::hasEligibleAccount()} (chỉ bước đầu) chỉ
 * còn để form chọn ĐÚNG câu cảnh báo: "chưa có tài khoản" hay "vụ không còn trên cổng của khách".
 * Nút "Gửi lại" ({@see ResendOutboundMessage}) đi qua cùng `eligibleRecipients()`, nên cũng không
 * gửi lại cho vụ đã rời cổng (gộp M7 vào `main`).
 *
 * Form hỏi vụ NHƯ LÚC MỞ FORM. Trên form "Chuyển giai đoạn", một giai đoạn đích không kết thúc mở lại
 * vụ (`SyncMatterArchive` xoá `client_access_until`) và đưa vụ về cổng, nên câu "vụ không còn trên
 * cổng" ở form đó còn hỏi giai đoạn đích (`TransitionStageAction::targetKeepsMatterClosed()`). Để
 * `handle()` thấy đúng trạng thái sau lần mở lại đó, `TransitionMatterStage` phát `MatterStageChanged`
 * (đồng bộ dòng lưu trữ) TRƯỚC `StageLogPublished` (thư).
 */
class NotifyClientOfStageUpdate
{
    public function handle(StageLog $stageLog): int
    {
        $recipients = $this->eligibleRecipients($stageLog);

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
     * Những tài khoản ĐỦ ĐIỀU KIỆN nhận thư về dòng tiến độ này NGAY BÂY GIỜ — mọi cổng kiểm tra
     * lúc gửi của {@see self::handle()} gộp lại một chỗ: dòng chưa đánh dấu `notified_at`, và
     * (final review B-M2: hỏi lại NGAY LÚC GỬI, đọc TƯƠI từ CSDL vì trong cửa sổ hàng đợi dòng
     * tiến độ có thể đã bị rút khỏi cổng hay vụ việc đã tắt công bố) dòng còn `is_published` +
     * vụ việc còn `is_published_to_portal`, rồi mới tới người nhận R12
     * ({@see self::recipientsFor()}), LỌC QUA vụ còn trên cổng của chính người đó
     * ({@see self::recipientsOnPortal()}, M7 Task 11 — CÙNG hai bước với `hasEligibleRecipient()`,
     * xem docblock lớp). Rỗng là "không gửi cho ai, không đánh dấu `notified_at`".
     *
     * Public vì nút "Gửi lại" của nhật ký thư ({@see ResendOutboundMessage}) phải hỏi ĐÚNG câu
     * này trước khi xếp hàng, không viết lại luật thứ hai: hai nơi cùng gọi một hàm thì không thể
     * lệch nhau.
     *
     * @return Collection<int, ClientUser>
     */
    public function eligibleRecipients(StageLog $stageLog): Collection
    {
        if ($stageLog->notified_at !== null) {
            return collect();
        }

        if (! $this->stillReleasedToPortal($stageLog)) {
            return collect();
        }

        // CÙNG hai bước với `hasEligibleRecipient()` (cảnh báo trên form) — xem docblock lớp.
        return $this->recipientsOnPortal((int) $stageLog->matter_id, $this->recipientsFor($stageLog));
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
    public function alreadyDelivered(StageLog $stageLog, ClientUser $recipient): bool
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

        return app(ResolveClientRecipients::class)->recipientsFor($clientId);
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
     * Bước hai của "ai nhận thư", dùng chung cho `handle()` và `hasEligibleRecipient()`: giữ lại
     * những tài khoản (đã qua {@see ResolveClientRecipients}, R12) mà vụ việc còn nằm trên cổng của
     * CHÍNH họ.
     *
     * Đọc vụ TƯƠI theo id (không tin `$stageLog->matter` đã nạp từ lúc xếp hàng, không tin đối tượng
     * form đang cầm), gỡ `ClientPortalScope` vì chính `Gate` mới là câu hỏi cổng. Vụ đã xoá mềm không
     * tìm thấy thì không ai nhận. (Vế `null` là một mutant tương đương — `Gate::allows('view', null)`
     * cũng sai — giữ lại cho rõ.)
     *
     * M7 Task 11 (rà soát M7 Task 5, m2): "còn trên cổng" hỏi bằng định nghĩa cổng —
     * {@see ResolveClientRecipients::onPortal()}, tức `Gate::forUser($account)->allows('view',
     * $matter)` — chứ không thêm một định nghĩa thứ ba. {@see self::stillReleasedToPortal()} chỉ hỏi
     * cờ `is_published_to_portal`, nên một vụ đã kết thúc và đã quá `client_access_until` (M7 Task 5,
     * R4: vụ rời cổng, cờ giữ nguyên) vẫn lọt: luật sư thêm một dòng cùng giai đoạn có công bố trên vụ
     * đã đóng, tài khoản khách còn hoạt động nhờ một vụ khác, và thư đi kèm liên kết tới một trang trả
     * 404. Gộp M7 vào `main`: bước lọc chuyển sang `ResolveClientRecipients` để ba thư khách của M6
     * (`client.document_published`, `client.document_rejected`, `client.request_answered`) dùng
     * đúng nó.
     *
     * @param  Collection<int, ClientUser>  $accounts
     * @return Collection<int, ClientUser>
     */
    private function recipientsOnPortal(int $matterId, Collection $accounts): Collection
    {
        $matter = Matter::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->find($matterId);

        if ($matter === null) {
            return collect();
        }

        return app(ResolveClientRecipients::class)->onPortal($matter, $accounts);
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
     * Task 7 (R12, phát hiện `stage/stage-06` nửa "luật sư không biết khách không được báo"), sửa ở
     * rà soát cuối M7 (I3): form "Chuyển giai đoạn"/"Thêm cập nhật" hỏi hàm này TRƯỚC khi gửi, để
     * cảnh báo luật sư ngay trên form khi sẽ không ai nhận được thư — xem
     * `App\Filament\Admin\Resources\Matters\Actions\Concerns\BuildsStageUpdateSchema`. Hai bước, ĐÚNG
     * hai bước của `handle()`: tài khoản đủ điều kiện ({@see ResolveClientRecipients}, CÙNG điều kiện
     * với `recipientsFor()`), rồi vụ còn trên cổng của tài khoản đó ({@see self::recipientsOnPortal()}).
     * Sai ⇔ `handle()` không gửi cho ai (khi hai cờ công bố đang bật).
     */
    public function hasEligibleRecipient(Matter $matter): bool
    {
        return $this->recipientsOnPortal(
            (int) $matter->getKey(),
            app(ResolveClientRecipients::class)->recipientsFor($matter->client_id),
        )->isNotEmpty();
    }

    /**
     * CHỈ bước đầu của {@see self::hasEligibleRecipient()}: khách có tài khoản cổng đủ điều kiện nhận
     * thư không (R12, {@see ResolveClientRecipients::hasEligibleRecipient()}), bất kể vụ này còn trên
     * cổng của họ hay không. Form dùng nó để chọn câu cảnh báo ("chưa có tài khoản" hay "vụ không còn
     * trên cổng của khách"), KHÔNG để quyết định có cảnh báo hay không — câu đó là của
     * `hasEligibleRecipient()`.
     */
    public function hasEligibleAccount(Matter $matter): bool
    {
        return app(ResolveClientRecipients::class)->hasEligibleRecipient($matter->client_id);
    }
}
