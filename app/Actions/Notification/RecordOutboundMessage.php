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
 * nhận của một thư nhiều địa chỉ được gộp ra sao, một header viết sai thì bỏ qua thế nào. Ba lời
 * gọi tới nó đến từ hai lớp — `App\Listeners\RecordOutboundMail` cho hai sự kiện thư của
 * Laravel, và `App\Support\Mail\OutboundLedgerTransport` cho lúc việc gửi ném lỗi — và cả hai
 * lớp ấy không giữ luật nào, đúng quy ước "nghiệp vụ nằm trong app/Actions/" của CLAUDE.md.
 *
 * **Vì sao không ai phải nhớ gọi nó** (Phán quyết R1 của kế hoạch M6): cột nhật ký này tồn tại
 * để trả lời câu "tôi không nhận được thông báo", tức một câu hỏi về những thư mà không ai nhớ
 * là mình đã gửi. Nên nó được móc vào sự kiện của chính framework, không vào từng nơi gửi thư:
 * một `Mail::raw()` trần ở một controller viết vội ba năm nữa cũng để lại dấu vết.
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

    /** Thư đã rời khỏi máy chủ: đóng dòng lại. */
    public function sent(Message $message): void
    {
        $this->find($message)?->update([
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
     */
    public function failed(Message $message, Throwable $error): void
    {
        $this->find($message)?->update([
            'status' => OutboundStatus::Failed,
            'error' => $error::class.': '.$error->getMessage(),
        ]);
    }

    /**
     * Tìm lại dòng đã mở ở `sending()`.
     *
     * `withoutGlobalScopes()` là bắt buộc chứ không phải phòng xa: `OutboundMessage` dùng
     * `RestrictedToClientPortal` và chặn SẠCH (`whereRaw('1 = 0')`) khi có khách đang mở cổng.
     * Rất nhiều thư của M6 được kích hoạt bởi chính khách đang đăng nhập (khách nộp tài liệu,
     * khách gửi yêu cầu → thư cho nhân sự), nên thiếu dòng này thì những dòng ấy đứng lại mãi ở
     * `queued` — nhật ký nói dối đúng vào những lần nó được hỏi nhiều nhất.
     */
    private function find(Message $message): ?OutboundMessage
    {
        // Ép về chuỗi để `ctype_digit()` không nhận `null` (đã bị phế từ PHP 8.1). Không có
        // header thì `''`, và `ctype_digit('')` là false — tức "chưa có dòng nào mở cho thư
        // này", đúng tình huống một `Email` đi thẳng vào transport mà không qua `Mailer`.
        $id = (string) $message->getHeaders()->get(OutboundHeaders::LEDGER_ID)?->getBodyAsString();

        if (! ctype_digit($id)) {
            return null;
        }

        return OutboundMessage::query()->withoutGlobalScopes()->find((int) $id);
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
