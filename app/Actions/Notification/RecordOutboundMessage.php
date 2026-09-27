<?php

namespace App\Actions\Notification;

use App\Enums\OutboundChannel;
use App\Enums\OutboundStatus;
use App\Mail\OutboundHeaders;
use App\Models\OutboundMessage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Throwable;

/**
 * Mở, đóng và đánh dấu hỏng một dòng nhật ký thư đi ra (SPEC §4.15).
 *
 * Đây là NƠI DUY NHẤT biết luật của nhật ký ấy: thư không khai báo mẫu thì ghi mẫu gì, người
 * nhận của một thư nhiều địa chỉ được gộp ra sao, một header viết sai thì bỏ qua thế nào. Cả hai
 * lớp gọi tới nó không giữ luật nào, đúng quy ước "nghiệp vụ nằm trong app/Actions/" của
 * CLAUDE.md.
 *
 * **Vì sao không ai phải nhớ gọi nó** (Phán quyết R1 của kế hoạch M6): cột nhật ký này tồn tại
 * để trả lời câu "tôi không nhận được thông báo", tức một câu hỏi về những thư mà không ai nhớ
 * là mình đã gửi. Nên nó được móc vào sự kiện của chính framework, không vào từng nơi gửi thư:
 * một `Mail::raw()` trần ở một controller viết vội ba năm nữa cũng để lại dấu vết.
 *
 * **M6.5 Task 12 (`notify/notify-11`) đổi ai đóng dòng lại, không đổi ai MỞ nó.**
 * `App\Listeners\RecordOutboundMail` chỉ còn nghe MỘT sự kiện (`MessageSending` →
 * {@see self::sending()}) — mở dòng, đọc `template`/`related` từ header của `BrandedMailable`.
 * `App\Support\Mail\OutboundLedgerTransport` giờ đóng dòng ở CẢ HAI NHÁNH (thành công lẫn thất
 * bại), qua {@see self::detachInternalHeaders()} rồi {@see self::markSent()}/
 * {@see self::markFailed()}: nó PHẢI là nơi gỡ header (xem docblock `detachInternalHeaders()`
 * cho lý do kỹ thuật — Symfony CLONE thông điệp trước khi gửi), nên tiện thể cũng là nơi đóng
 * dòng, thay vì tách thành hai cơ chế (gỡ header ở một chỗ, đóng dòng thành công ở một sự kiện
 * KHÁC mà đến lúc đó header đã mất, không tra lại được).
 *
 * **Hệ quả cho M6 Task 8 (Phán quyết R3), nói ra ở đây vì đây là nơi trạng thái được đặt:** một
 * thư THẤT BẠI vẫn là một dòng. Truy vấn chống gửi trùng vì vậy phải lọc `status = sent`; đếm cả
 * dòng `failed` là biến một lần gửi hỏng thành một lần im lặng không gửi lại.
 *
 * **Phạm vi:** `channel` luôn là `email`, và đó là sự thật chứ không phải một giá trị tạm —
 * cánh cửa này canh sự kiện thư của Laravel, nên nó chỉ thấy email. `OutboundChannel` còn hai
 * case `zns` và `sms` (SPEC §4.15) cho ngày văn phòng gửi Zalo; khi ấy phải có một cánh cửa
 * riêng cho kênh ấy, chứ không phải sửa chỗ này.
 */
class RecordOutboundMessage
{
    /**
     * Mở một dòng ở trạng thái `queued` và gắn khoá của nó vào thư để hai chặng sau tìm lại được.
     */
    public function sending(Email $message): OutboundMessage
    {
        [$relatedType, $relatedId] = $this->related($message);

        $row = OutboundMessage::query()->create([
            'channel' => OutboundChannel::Email,
            'recipient' => $this->recipients($message),
            'template' => $this->template($message),
            'payload' => ['subject' => $message->getSubject()],
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'status' => OutboundStatus::Queued,
        ]);

        // `remove()` trước `addTextHeader()`: `Symfony\Component\Mime\Header\Headers::add()` CHO
        // PHÉP nhiều header cùng tên với một tên ngoài danh sách chuẩn, và `get()` trả về cái
        // ĐẦU TIÊN. Thiếu dòng xoá, một mẫu thư tự đặt `X-VKCRM-Ledger-Id` (cố ý hay chép nhầm
        // từ mẫu khác) sẽ trỏ hai chặng sau sang một dòng khác, và dòng thật đứng lại ở `queued`.
        $headers = $message->getHeaders();
        $headers->remove(OutboundHeaders::LEDGER_ID);
        $headers->addTextHeader(OutboundHeaders::LEDGER_ID, (string) $row->getKey());

        return $row;
    }

    /**
     * Gỡ CẢ BA header `X-VKCRM-*` khỏi thông điệp TRƯỚC KHI nó rời máy chủ (M6.5 Task 12,
     * `notify/notify-11`): chúng lộ id tuần tự nội bộ (id dòng nhật ký, và qua header `Related`,
     * id của bản ghi mà thư nói về) cho bất kỳ ai xem "Show original" trên hộp thư khách. Nhật ký
     * đã đọc xong `template`/`related` ở {@see self::sending()} (`MessageSending`, chạy TRƯỚC khi
     * `Mailer` gọi transport), nên gỡ ở đây không làm mất ngữ cảnh — chỉ mất thứ không ai cần đọc
     * ngoài chính hệ thống.
     *
     * **Vì sao TRẢ VỀ khoá dòng thay vì gỡ luôn cả `Ledger-Id` mà không nói gì.** Không giống
     * Template/Related (chỉ đọc một lần ở `sending()`), khoá dòng còn cần cho HAI bước SAU khi
     * thư đã rời tay: đóng dòng thành `sent`, hay đánh dấu `failed` nếu transport ném lỗi (xem
     * `OutboundLedgerTransport::send()`, nơi DUY NHẤT gọi hàm này). Gỡ khỏi thông điệp rồi trả
     * lại cho gọi bên ngoài giữ tạm — không phải mất, chỉ là chuyển chỗ giữ, từ "header đi theo
     * thư" sang "biến cục bộ của lệnh gửi này" — đúng lúc, đúng chỗ, không rời khỏi máy chủ.
     *
     * Không dùng lại được `sent()`/`failed()` kiểu cũ (dựa vào header còn nguyên trên thông điệp
     * ở `MessageSent`): `AbstractTransport::send()` của Symfony CLONE thông điệp trước khi giao
     * cho `doSend()`, và `SentMessage` của sự kiện `MessageSent` bọc đúng bản CLONE ấy — tức bản
     * ĐÃ bị gỡ header nếu gỡ trước khi gọi `inner->send()` (bắt buộc, để header không lọt ra
     * ngoài). Vì vậy `markSent()`/`markFailed()` dưới đây nhận THẲNG khoá dòng (không đọc lại
     * header từ thông điệp) — {@see self::markSent()}.
     */
    public function detachInternalHeaders(Message $message): ?int
    {
        $headers = $message->getHeaders();
        $raw = (string) $headers->get(OutboundHeaders::LEDGER_ID)?->getBodyAsString();
        $ledgerId = ctype_digit($raw) ? (int) $raw : null;

        foreach ([OutboundHeaders::TEMPLATE, OutboundHeaders::RELATED, OutboundHeaders::LEDGER_ID] as $name) {
            $headers->remove($name);
        }

        return $ledgerId;
    }

    /**
     * Thư đã rời khỏi máy chủ: đóng dòng lại.
     *
     * `$ledgerId` do {@see OutboundLedgerTransport::send()} truyền vào — id đã đọc TRƯỚC KHI gỡ
     * header (xem docblock `detachInternalHeaders()`), không đọc lại từ thông điệp: sau khi gỡ,
     * không còn header nào để đọc, dù việc gửi có thành công hay không.
     *
     * `withoutGlobalScopes()`: cùng lý do `find()` cũ từng cần — bảng này bị
     * `RestrictedToClientPortal` chặn sạch khi có khách đang mở cổng.
     */
    public function markSent(int $ledgerId): void
    {
        OutboundMessage::query()->withoutGlobalScopes()->whereKey($ledgerId)->first()?->update([
            'status' => OutboundStatus::Sent,
            'sent_at' => now(),
        ]);
    }

    /**
     * Việc gửi ném lỗi: dòng đã mở vẫn nằm nguyên đó, chỉ đổi trạng thái và ghi lý do.
     *
     * Lý do gồm cả TÊN LỚP ngoại lệ, vì phần lời của transport một mình thường không đủ để biết
     * phải làm gì — "Connection could not be established" đọc y hệt nhau khi máy chủ SMTP tắt,
     * khi mật khẩu sai và khi tường lửa chặn cổng.
     *
     * M6.5 Task 11 (R2) — vì sao dòng này KHÔNG BAO GIỜ mất, kể cả khi job hết sạch lượt thử.
     * `update()` ở đây là một câu lệnh SQL ĐƠN, tự commit ngay (autocommit), KHÔNG nằm trong bất
     * kỳ `DB::transaction()` nghiệp vụ nào — cả `CheckDeadlines` (từ Task 11) lẫn listener thư
     * tiến độ đều gọi Action gửi thư của mình từ BÊN NGOÀI mọi transaction nghiệp vụ (job hàng
     * đợi chạy sau khi transaction đã commit). Vì vậy: (1) một mốc/dòng tiến độ khác trong CÙNG
     * transaction rollback vì lý do khác không kéo dòng `failed` này theo; (2) hàng đợi tự thử
     * lại job (`$tries`/`backoff()`) và cuối cùng chuyển nó sang `failed_jobs` không xoá hay sửa
     * dòng này — đó là một bảng hoàn toàn khác, do Laravel tự quản lý.
     *
     * `$ledgerId`: cùng lý do `markSent()` — xem docblock `detachInternalHeaders()`.
     */
    public function markFailed(int $ledgerId, Throwable $error): void
    {
        OutboundMessage::query()->withoutGlobalScopes()->whereKey($ledgerId)->first()?->update([
            'status' => OutboundStatus::Failed,
            'error' => $error::class.': '.$error->getMessage(),
        ]);
    }

    /**
     * Mẫu thư đã khai báo, hoặc một giá trị nói thẳng là không có ai khai báo.
     *
     * Không ném lỗi khi thiếu: một thư không khai báo mẫu vẫn phải được ghi lại, vì nó chính là
     * hạng thư mà bảng này tồn tại để bắt.
     */
    private function template(Email $message): string
    {
        $template = trim((string) $message->getHeaders()->get(OutboundHeaders::TEMPLATE)?->getBodyAsString());

        return $template === ''
            ? OutboundMessage::TEMPLATE_UNDECLARED
            : Str::limit($template, 80, '');
    }

    /**
     * Mọi địa chỉ thật sự nhận được thư, gộp vào một dòng.
     *
     * Gồm cả `Cc` và `Bcc` chứ không riêng `To`: câu hỏi mà cột này trả lời là "thư có tới chỗ
     * người này không", và một người nhận bản sao ẩn cũng hỏi đúng câu đó. Cắt ở 200 ký tự cho
     * vừa cột (`string(200)` từ M1) — phần bị cắt là những địa chỉ sau cùng của một thư gửi hàng
     * loạt, không phải dữ liệu để đối chiếu.
     */
    private function recipients(Email $message): string
    {
        $addresses = array_map(
            fn ($address): string => $address->getAddress(),
            array_merge($message->getTo(), $message->getCc(), $message->getBcc()),
        );

        return Str::limit(implode(', ', array_unique($addresses)), 200, '');
    }

    /**
     * Bản ghi mà thư nói về, đọc từ header dạng `{morph alias}:{id}`.
     *
     * Header viết sai thì bỏ qua chứ không ném lỗi, và cũng không ghi một nửa: một
     * `related_type` không có `related_id` là một liên kết gãy mà mọi màn hình tra cứu về sau
     * phải tự đoán ý.
     *
     * @return array{0: ?string, 1: ?int}
     */
    private function related(Email $message): array
    {
        $value = trim((string) $message->getHeaders()->get(OutboundHeaders::RELATED)?->getBodyAsString());

        if (! str_contains($value, ':')) {
            return [null, null];
        }

        [$type, $id] = explode(':', $value, 2);

        if ($type === '' || ! ctype_digit($id)) {
            return [null, null];
        }

        return [$type, (int) $id];
    }
}
