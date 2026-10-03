<?php

namespace App\Actions\Notification;

use App\Actions\Schedule\RemindMissingDocuments;
use App\Jobs\SendMissingDocumentsMail;
use App\Jobs\SendStaleMatterMail;
use App\Mail\Client\DocumentRejected;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\OutboundMessage;
use App\Models\StageLog;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Danh sách mẫu thư mà nút "Gửi lại" của nhật ký thư (M6 Task 10) được phép dựng lại, và cách nối
 * từng mẫu vào ĐÚNG hàm gửi thật của nó. MỘT chỗ khai báo, để "mẫu nào gửi lại được" không nằm rải
 * ở màn hình, Action và test.
 *
 * # Gửi lại được (dựng lại được từ `related_type`/`related_id`)
 *
 * `client.stage_update`, `client.document_published`, `client.document_rejected`,
 * `client.request_answered`, `client.missing_documents`, `staff.new_client_request`,
 * `staff.new_client_document`, `staff.stale_matter`.
 *
 * # KHÔNG gửi lại — {@see self::NOT_RESENDABLE}, mỗi mục một lý do, và đó là quyết định, không phải sót
 *
 *  - `client.otp`: mã 5 phút gửi ĐỒNG BỘ (phán quyết M5); gửi lại từ nhật ký là gửi một mã đã chết.
 *  - `staff.deadline_reminder`: bậc nhắc hỏng HẲN (hết lượt thử) thì `SendDeadlineReminderMail::
 *    failed()` rút bậc khỏi `reminders_sent`, ghi dòng `deadline_reminder_failed` và rung chuông
 *    (một lần mỗi ngày) cho người phụ trách mốc, luật sư phụ trách và cấp trên; `CheckDeadlines`
 *    KHÔNG xếp lại bậc đó trong ngày (`SendDeadlineReminderMail::failedForGoodToday()`, final review
 *    wave 2, I-2) — lượt `deadlines.check` ĐẦU TIÊN của ngày hôm sau (07:00) mới nhắc lại mốc này.
 *    Chặn trùng của job (`tier@due_date`, theo từng người nhận, chỉ dòng `sent`) đủ để một lần gửi
 *    tay ở đây không thành hai thư, nên thêm mẫu này vào nút là AN TOÀN về chống trùng — nhưng đó là
 *    quyết định của chủ văn phòng, chưa làm (Ghi chú M6, việc sau gộp).
 *  - `client.activation`: gửi lại thư kích hoạt là CẤP mật khẩu tạm mới — việc đó có nút riêng ở
 *    màn hình tài khoản cổng khách hàng (Task 3), có ghi nhật ký và ép đổi mật khẩu.
 *  - `staff.instalment_overdue` (M9): `instalments.remind` chạy 08:00 mỗi ngày và chỉ dòng `sent`
 *    chặn lời nhắc mới (`SendInstalmentOverdueMail::alreadyReminded()`), nên đợt còn quá hạn tự
 *    được nhắc lại ở lượt 08:00 kế tiếp. Không có gì để gửi tay.
 *  - `staff.backup_alert.*` (M8a; hậu tố là loại sự cố: `backup_failed`, `cleanup_failed`,
 *    `unhealthy`): thư mang trạng thái của MỘT lượt sao lưu đã qua — gửi lại là báo một sự kiện
 *    cũ. Sự cố còn thì lượt sao lưu 02:00 hoặc lượt kiểm tra 08:00 kế tiếp tự báo lại.
 *  - `staff.matter_reassigned` (M7 Task 1, gộp M7 vào `main`): thư tổng hợp mốc thời hạn cho luật
 *    sư phụ trách MỚI liệt kê các mốc ở đúng lúc bàn giao, không có một bản ghi `related` nào để
 *    dựng lại (một lô vụ việc). Hỏng hẳn thì `SendReassignmentDigest::failed()` đã ghi dòng
 *    `matter_reassignment_digest_failed` và rung chuông cho chính luật sư đó; các mốc vẫn ở trang
 *    chủ, ở tab "Mốc thời hạn" và trong thư nhắc mốc theo lịch.
 *  - `staff.handover_ready` (M7 Task 4, gộp M7 vào `main`): thư chỉ báo "gói bàn giao đã sinh xong"
 *    — trạng thái gói luôn hiện ở khối "Gói bàn giao" trên trang vụ việc, và chuông trong hệ thống
 *    đi cùng lúc với thư. Gửi lại là báo một sự kiện đã qua (gói có thể đã được sinh lại).
 *  - `undeclared` và mọi mẫu lạ: không biết dựng lại từ đâu (mẫu lạ nhận câu từ chối chung).
 *
 * # Nguyên tắc: KHÔNG viết luật thứ hai
 *
 * Mỗi mục chỉ chuyển sang hàm CÓ SẴN của Action/Job gốc: `eligibleRecipients()` (mọi cổng lúc-gửi
 * và luật người nhận R3/R12), `alreadyDelivered()` (chống trùng theo nhật ký thư) và `handle()`
 * (đường gửi thật). Sửa luật của một mẫu ở Action gốc thì nút "Gửi lại" đổi theo, không có bản
 * sao nào để quên.
 */
final class ResendTargets
{
    /**
     * Mẫu thư KHÔNG gửi lại được, ghi TƯỜNG MINH (lý do từng mục ở docblock lớp). Mỗi mục cũng là
     * khoá câu từ chối riêng ở `outbound.resend.refused.template_reasons`. Mục kết thúc bằng `.*` là
     * một HỌ mẫu có hậu tố động — {@see self::exclusionOf()}. Một mẫu không có ở đây và không có ở
     * {@see self::for()} vẫn bị chặn (nhánh `default`), nhưng nhận câu chung; test
     * `MailTemplateRegistryTest` đòi mọi mẫu của `app/Mail` nằm ở MỘT trong hai nơi.
     */
    public const NOT_RESENDABLE = [
        'client.otp',
        'staff.deadline_reminder',
        'client.activation',
        'staff.instalment_overdue',
        'staff.backup_alert.*',
        'staff.matter_reassigned',
        'staff.handover_ready',
        'undeclared',
    ];

    /** Tệp thuộc CÙNG một lần nộp nằm trong khoảng này kể từ tệp đại diện — xem {@see self::batchOf()}. */
    private const BATCH_WINDOW_SECONDS = 60;

    /**
     * Mục của {@see self::NOT_RESENDABLE} khớp `$template` — tên đầy đủ, hoặc họ `xxx.*` khi tên mẫu
     * bắt đầu bằng `xxx.` (`staff.backup_alert.unhealthy` → `staff.backup_alert.*`). `null` khi mẫu
     * không bị loại tường minh (gửi lại được, hoặc là một mẫu lạ chưa ai khai).
     */
    public static function exclusionOf(string $template): ?string
    {
        foreach (self::NOT_RESENDABLE as $entry) {
            $matches = str_ends_with($entry, '.*')
                ? str_starts_with($template, substr($entry, 0, -1))
                : $template === $entry;

            if ($matches) {
                return $entry;
            }
        }

        return null;
    }

    public static function for(string $template): ?ResendTarget
    {
        return match ($template) {
            'client.stage_update' => new ResendTarget(
                'stage_log',
                fn (StageLog $log) => app(NotifyClientOfStageUpdate::class)->eligibleRecipients($log),
                fn (StageLog $log, $to) => app(NotifyClientOfStageUpdate::class)->alreadyDelivered($log, $to),
                fn (StageLog $log) => app(NotifyClientOfStageUpdate::class)->handle($log),
            ),
            'client.document_published' => new ResendTarget(
                'document',
                fn (Document $document) => app(NotifyClientOfDocumentPublished::class)->eligibleRecipients($document),
                fn (Document $document, $to) => app(NotifyClientOfDocumentPublished::class)->alreadyDelivered($document, $to),
                fn (Document $document) => app(NotifyClientOfDocumentPublished::class)->handle($document),
            ),
            'client.document_rejected' => new ResendTarget(
                'matter_checklist_item',
                fn (MatterChecklistItem $item) => app(NotifyClientOfChecklistItemRejected::class)->eligibleRecipients($item),
                fn (MatterChecklistItem $item, $to) => app(NotifyClientOfChecklistItemRejected::class)->alreadyDelivered($item, $to),
                fn (MatterChecklistItem $item) => app(NotifyClientOfChecklistItemRejected::class)->handle($item),
                fn (MatterChecklistItem $item) => DocumentRejected::ledgerKeyFor($item),
            ),
            'client.request_answered' => new ResendTarget(
                'client_request_reply',
                fn (ClientRequestReply $reply) => app(NotifyClientOfRequestAnswered::class)->eligibleRecipients($reply),
                fn (ClientRequestReply $reply, $to) => app(NotifyClientOfRequestAnswered::class)->alreadyDelivered($reply, $to),
                fn (ClientRequestReply $reply) => app(NotifyClientOfRequestAnswered::class)->handle($reply),
            ),
            'client.missing_documents' => new ResendTarget(
                'matter',
                fn (Matter $matter) => (new SendMissingDocumentsMail($matter->getKey()))->eligibleRecipients(),
                fn (Matter $matter, $to) => RemindMissingDocuments::alreadyDelivered($matter, $to),
                fn (Matter $matter) => (new SendMissingDocumentsMail($matter->getKey()))->handle(),
            ),
            'staff.new_client_request' => new ResendTarget(
                'client_request',
                fn (ClientRequest $request) => app(NotifyStaffOfNewClientRequest::class)->eligibleRecipients($request),
                fn (ClientRequest $request, $to) => app(NotifyStaffOfNewClientRequest::class)->alreadyDelivered($request, $to),
                fn (ClientRequest $request) => app(NotifyStaffOfNewClientRequest::class)->handle($request),
            ),
            'staff.new_client_document' => new ResendTarget(
                'document',
                fn (Document $document) => app(NotifyStaffOfNewClientDocument::class)->eligibleRecipients($document),
                fn (Document $document, $to) => app(NotifyStaffOfNewClientDocument::class)->alreadyDelivered($document, $to),
                fn (Document $document) => app(NotifyStaffOfNewClientDocument::class)->handle(self::batchOf($document)),
            ),
            'staff.stale_matter' => new ResendTarget(
                'matter',
                fn (Matter $matter) => (new SendStaleMatterMail($matter->getKey()))->eligibleRecipients(),
                fn (Matter $matter, $to) => (new SendStaleMatterMail($matter->getKey()))->alreadyDelivered($matter, $to),
                fn (Matter $matter) => (new SendStaleMatterMail($matter->getKey()))->handle(),
            ),
            default => null,
        };
    }

    /**
     * Bản ghi mà dòng nhật ký nói về, đọc KHÔNG qua scope nào (kể cả xoá mềm): việc tài liệu/vụ
     * việc đã xoá mềm hay không là quyết định của cổng lúc-gửi trong Action gốc, không phải của
     * bước tìm bản ghi. `null` khi `related_type` không khớp mẫu hoặc bản ghi đã xoá cứng.
     */
    public static function relatedOf(OutboundMessage $message, ResendTarget $target): ?Model
    {
        if ($message->related_type !== $target->relatedType || $message->related_id === null) {
            return null;
        }

        $class = Relation::getMorphedModel($target->relatedType);

        if ($class === null) {
            return null;
        }

        return $class::query()->withoutGlobalScopes()->find($message->related_id);
    }

    /**
     * Dòng hỏng còn nói về ĐÚNG sự việc mà bản ghi hiện mang không — so khoá `payload.tier` mà
     * mailable gốc đã ghi (qua header, `RecordOutboundMessage`) với khoá của bản ghi HIỆN TẠI, bằng
     * CHÍNH hàm tạo khoá của mailable đó ({@see ResendTarget::$instanceKey}). Mẫu không khai
     * `instanceKey` luôn đúng. Không có luật mới: đây là cổng "đúng lần từ chối mà sự kiện đã bắn
     * ra" của `NotifyClientOfChecklistItemRejected::stillRejected()`, với dòng nhật ký đứng ở chỗ
     * của sự kiện (sự kiện gốc không được lưu lại).
     */
    public static function isSameInstance(OutboundMessage $message, ResendTarget $target, Model $related): bool
    {
        if ($target->instanceKey === null) {
            return true;
        }

        return ($message->payload['tier'] ?? null) === ($target->instanceKey)($related);
    }

    /**
     * Dựng lại LÔ tệp của một lần nộp từ tệp đại diện mà nhật ký lưu (`staff.new_client_document`
     * trỏ tới `$documents->first()`, xem `NotifyStaffOfNewClientDocument`). Lô của một lần nộp là
     * các tệp CÙNG vụ, đầu mục, version, nhóm, CÙNG người nộp, tạo ra ngay sau tệp đại diện (id lớn
     * hơn hoặc bằng, trong {@see self::BATCH_WINDOW_SECONDS}) — `SubmitClientDocument` tạo cả lô
     * trong một transaction, và sự kiện gốc không được lưu lại, nên đây là bản dựng lại tốt nhất có
     * thể. Một lần nộp bổ sung R10 tái dùng cùng version vẫn tách ra vì nó đến sau cửa sổ đó; một
     * tài khoản khác của cùng khách nộp cùng lúc tách ra nhờ điều kiện người nộp. Sai số nếu có chỉ
     * nằm ở SỐ TỆP ghi trong thư nội bộ — `NotifyStaffOfNewClientDocument::freshCount()` đếm tiếp
     * bên trong đúng tập này (bỏ tệp đã xoá mềm).
     */
    private static function batchOf(Document $representative): EloquentCollection
    {
        $siblings = Document::query()
            ->withoutGlobalScopes()
            ->where('matter_id', $representative->matter_id)
            ->where('matter_checklist_item_id', $representative->matter_checklist_item_id)
            ->where('version', $representative->version)
            ->where('group', $representative->group)
            ->where('uploader_type', $representative->uploader_type)
            ->where('uploader_id', $representative->uploader_id)
            ->where('id', '>=', $representative->getKey())
            ->where('created_at', '<=', $representative->created_at?->copy()->addSeconds(self::BATCH_WINDOW_SECONDS))
            ->orderBy('id')
            ->get();

        return $siblings->isEmpty() ? new EloquentCollection([$representative]) : $siblings;
    }
}
