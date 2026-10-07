<?php

namespace App\Actions\Notification;

use App\Enums\OutboundChannel;
use App\Enums\OutboundStatus;
use App\Exceptions\OutboundMessageNotResendable;
use App\Jobs\ResendOutboundMessageJob;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Policies\OutboundMessagePolicy;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

/**
 * Nút "Gửi lại" của nhật ký thư (M6 Task 10; M6.5 Task 13 để lại: "gửi lại thủ công là việc
 * riêng"). Đứng trên một dòng `outbound_messages` có `status = failed` và xếp hàng MỘT lần gửi lại
 * qua {@see ResendOutboundMessageJob}.
 *
 * # Không sửa dòng cũ, không tự gửi
 *
 * Dòng hỏng là BẰNG CHỨNG của lần gửi hỏng ("khách nói không nhận được thư", SPEC §4.15) — không
 * bao giờ bị sửa. Thư gửi lại là một lần gửi thật, đi qua ĐÚNG đường của mẫu đó
 * ({@see ResendTargets}), nên `OutboundLedgerTransport` tự ghi một dòng MỚI cho nó, `sent` hoặc
 * `failed`. Action này không gọi `Mail::` và không gọi transport: R2 — mọi thư qua hàng đợi, sau
 * commit, không nằm trong transaction; transaction dưới đây chỉ khoá, đọc, ghi nhật ký kiểm toán
 * và dispatch `->afterCommit()` (cùng khuôn `CheckStaleMatters::processOne()`).
 *
 * # Người nhận suy lại, không chép cột `recipient` cũ
 *
 * Người nhận cũ có thể đã nghỉ việc, bị khoá, đổi email, hoặc không còn xem được vụ. Vì vậy hàm hỏi
 * {@see ResendTarget::$eligible} — CHÍNH hàm `eligibleRecipients()` của Action/Job gốc, gồm mọi cổng
 * lúc-gửi của nó và luật người nhận R3 (nhân sự) / R12 (khách) — không có luật thứ hai ở đây. Không
 * còn ai đủ điều kiện → {@see OutboundMessageNotResendable::noEligibleRecipient()}, không gửi. Có
 * người đủ điều kiện nhưng ai cũng đã có một dòng `sent` cho đúng thư này (gửi thành công ở lần
 * khác, kể cả lần gửi lại trước) → {@see OutboundMessageNotResendable::alreadyDelivered()}. Cổng
 * lúc-gửi của mẫu gốc đôi khi trả "không ai" cả khi sự thật là "đã nhận" (`stage_logs.notified_at`
 * ghi sau một lượt thử lại thành công) — {@see self::deliveredLater()} chỉ chọn CÂU đúng cho trường
 * hợp đó. Lúc job chạy, đường gửi thật tính lại TẤT CẢ một lần nữa (cửa sổ hàng đợi) và tự bỏ qua
 * người đã nhận.
 *
 * # Đúng sự việc mà dòng hỏng nói về
 *
 * Một bản ghi có thể sinh nhiều thư theo thời gian: mỗi lần từ chối một đầu mục là một thư
 * `client.document_rejected` riêng (khoá `rejected@<reviewed_at>` ở `payload.tier`). Nếu đầu mục đã
 * bị từ chối LẦN MỚI, gửi lại dòng cũ là gửi thư lần mới dưới danh nghĩa lần cũ →
 * {@see OutboundMessageNotResendable::superseded()} ({@see ResendTargets::isSameInstance()}, hỏi
 * lại ở job).
 *
 * # Bấm hai lần
 *
 * Dòng hỏng KHÔNG đổi sau khi bấm và job chưa chắc đã chạy, nên không có "trạng thái mới" nào để chặn
 * lần bấm thứ hai. Dấu chặn là dòng nhật ký kiểm toán {@see self::AUDIT_EVENT} mang
 * `outbound_message_id` — được ghi TRONG cùng transaction, dưới khoá của dòng nhật ký thư (và của
 * dòng `matters` trước, thứ tự khoá của dự án: vụ việc trước). Lần bấm thứ hai đợi khoá, thấy dấu,
 * bị từ chối bằng {@see OutboundMessageNotResendable::alreadyRequested()}. Một dòng hỏng chỉ gửi lại
 * MỘT lần; nếu lần gửi lại cũng hỏng thì chính nó là một dòng `failed` mới, có nút riêng.
 *
 * # Quyền
 *
 * `Gate resend` ({@see OutboundMessagePolicy::resend()}: admin, và vẫn qua `view()` của
 * ĐÚNG dòng đó, kể cả vụ `restricted`). Cổng quyền chạy TRƯỚC mọi cổng trạng thái — cùng thứ tự
 * `ClientRequestNotOpen` mô tả — nên các câu từ chối trạng thái không tiết lộ gì cho người không xem
 * được dòng. Audit chỉ ghi ai bấm, dòng nào, mẫu nào, bao nhiêu người nhận — không ghi địa chỉ,
 * không ghi nội dung thư.
 *
 * # Chỉ dòng EMAIL (M12 R13)
 *
 * Từ M12 nhật ký có cả dòng thông báo đẩy (`channel = push`, `RecordOutboundPush`), và giá trị chủ đề
 * đẩy TRÙNG tên mẫu thư (`client.stage_update`…). Mọi cổng dưới đây hỏi theo `template` và
 * `related_type`, nên thiếu cổng kênh thì một dòng push hỏng (máy đã gỡ app trả 410) có nút "Gửi lại" —
 * và bấm là xếp một THƯ. Push không gửi lại: nó là tiện ích, thư mới là chứng cứ, và thư của cùng sự
 * việc đã có dòng riêng. Cổng kênh đứng ở {@see self::canResend()}, ở {@see self::handle()} (sau cổng
 * quyền, trước mọi cổng khác) và ở job (`ResendOutboundMessageJob::handle()`).
 */
class ResendOutboundMessage
{
    /** Khoá nhật ký kiểm toán (nhãn ở `lang/vi/activity.php`, mục `events`). */
    public const AUDIT_EVENT = 'outbound_message_resent';

    /**
     * Dòng này CÓ THỂ được gửi lại không (dòng email, mẫu gửi lại được, đang `failed`, `related_type`
     * khớp mẫu) — để màn hình quyết định hiện nút mà không truy vấn. Không thay {@see self::handle()}:
     * các cổng cần đọc CSDL (người nhận, đã gửi lại chưa) chỉ chạy ở đó.
     */
    public static function canResend(OutboundMessage $message): bool
    {
        if ($message->channel !== OutboundChannel::Email || $message->status !== OutboundStatus::Failed) {
            return false;
        }

        $target = ResendTargets::for($message->template);

        return $target !== null && $message->related_type === $target->relatedType;
    }

    /**
     * @return int số người nhận đã xếp hàng gửi lại (luôn >= 1; không có ai thì ném ngoại lệ)
     *
     * @throws AuthorizationException khi `$actor` không được gửi lại dòng này
     * @throws OutboundMessageNotResendable khi trạng thái/loại/người nhận không cho gửi lại
     */
    public function handle(User $actor, OutboundMessage $message): int
    {
        Gate::forUser($actor)->authorize('resend', $message);

        if ($message->channel !== OutboundChannel::Email) {
            throw OutboundMessageNotResendable::channel();
        }

        $target = ResendTargets::for($message->template);

        if ($target === null || $message->related_type !== $target->relatedType) {
            throw OutboundMessageNotResendable::template($message->template);
        }

        // Trạng thái `failed` KHÔNG hỏi ở đây trên bản trong bộ nhớ (có thể đã cũ — trang mở từ
        // trước): bản đọc có khoá bên trong transaction là thứ duy nhất quyết định.

        // Thứ tự khoá của dự án: dòng `matters` TRƯỚC, rồi mới tới dòng nhật ký thư.
        $matterId = $message->relatedMatter()?->getKey();

        return DB::transaction(function () use ($actor, $message, $target, $matterId): int {
            $matter = $matterId === null
                ? null
                : Matter::query()->withoutGlobalScopes()->whereKey($matterId)->lockForUpdate()->first();

            /** @var OutboundMessage|null $locked */
            $locked = OutboundMessage::query()
                ->withoutGlobalScopes()
                ->whereKey($message->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== OutboundStatus::Failed) {
                throw OutboundMessageNotResendable::notFailed();
            }

            $previous = Activity::query()
                ->where('event', self::AUDIT_EVENT)
                ->where('properties->outbound_message_id', $locked->getKey())
                ->latest('id')
                ->first();

            if ($previous !== null) {
                throw OutboundMessageNotResendable::alreadyRequested($previous->created_at);
            }

            $related = ResendTargets::relatedOf($locked, $target);

            if ($related === null) {
                throw OutboundMessageNotResendable::relatedGone();
            }

            if (! ResendTargets::isSameInstance($locked, $target, $related)) {
                throw OutboundMessageNotResendable::superseded();
            }

            $eligible = ($target->eligible)($related);

            if ($eligible->isEmpty()) {
                // Cổng lúc-gửi của mẫu gốc trả "không ai" cả khi sự thật là "đã nhận rồi" (ví dụ
                // `stage_logs.notified_at` đã ghi sau một lượt thử lại thành công) — câu từ chối
                // phải nói đúng sự thật đó, không đổ cho vụ việc hay tài khoản.
                throw $this->deliveredLater($locked)
                    ? OutboundMessageNotResendable::alreadyDelivered()
                    : OutboundMessageNotResendable::noEligibleRecipient();
            }

            $pending = $eligible->reject(fn ($recipient): bool => ($target->delivered)($related, $recipient));

            if ($pending->isEmpty()) {
                throw OutboundMessageNotResendable::alreadyDelivered();
            }

            // Tên sự kiện viết LITERAL (không dùng hằng) để `ActivityLogEventTranslationsTest` quét
            // được và đòi nhãn ở `lang/vi/activity.php`; một test khác ghim `self::AUDIT_EVENT` vào
            // đúng chuỗi này để hai nơi không lệch nhau.
            Audit::record('outbound_message_resent', $matter, [
                'outbound_message_id' => $locked->getKey(),
                'template' => $locked->template,
                'recipients' => $pending->count(),
            ], $actor);

            ResendOutboundMessageJob::dispatch($locked->getKey(), $actor->getKey())->afterCommit();

            return $pending->count();
        });
    }

    /**
     * Người nhận của dòng hỏng đã có một dòng `sent` SAU nó cho ĐÚNG thư này (cùng mẫu, cùng bản
     * ghi, cùng địa chỉ, cùng khoá `payload.tier` khi dòng hỏng mang khoá đó) — tức một lượt thử lại
     * của hàng đợi hay một lần gửi lại trước đã tới nơi. Chỉ dùng để CHỌN CÂU từ chối khi cổng
     * lúc-gửi đã nói "không ai"; không quyết định gửi hay không. Dòng thông báo đẩy cùng mẫu, cùng bản
     * ghi (M12 R13) không bao giờ khớp: `recipient` của nó là `client_user:{id}`, không phải địa chỉ.
     */
    private function deliveredLater(OutboundMessage $failed): bool
    {
        $tier = $failed->payload['tier'] ?? null;

        return OutboundMessage::query()
            ->withoutGlobalScopes()
            ->where('id', '>', $failed->getKey())
            ->where('template', $failed->template)
            ->where('related_type', $failed->related_type)
            ->where('related_id', $failed->related_id)
            ->where('recipient', $failed->recipient)
            ->where('status', OutboundStatus::Sent)
            ->when($tier !== null, fn ($query) => $query->where('payload->tier', $tier))
            ->exists();
    }
}
