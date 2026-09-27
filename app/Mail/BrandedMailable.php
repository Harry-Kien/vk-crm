<?php

namespace App\Mail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Headers;

/**
 * Lớp cơ sở của mọi mẫu thư ở SPEC §9.
 *
 * Việc duy nhất nó làm là gắn hai header đi kèm thư: tên mẫu, và bản ghi mà thư nói về. Nhật ký
 * `outbound_messages` đọc lại hai header ấy (`App\Actions\Notification\RecordOutboundMessage`),
 * nên một mẫu thư kế thừa lớp này thì tự động tra cứu được theo hồ sơ — còn một thư quên kế thừa
 * thì VẪN được ghi lại, chỉ là với `template = undeclared`. Đó là chủ ý: nhật ký không bao giờ
 * mất dòng, nó chỉ mất ngữ cảnh.
 *
 * Chuỗi hiển thị — tiêu đề thư trước hết — đi qua `__()` và `lang/vi/`, như mọi chỗ khác của dự
 * án. Lớp này không tự đặt tiêu đề, vì mỗi mẫu có một khoá riêng và một bộ tham số riêng; nó chỉ
 * bảo đảm phần đi kèm mà con người không nhìn thấy.
 */
abstract class BrandedMailable extends Mailable
{
    /** Tên mẫu theo SPEC §9, ví dụ `client.stage_update`. */
    abstract protected function template(): string;

    /** Hồ sơ, dòng tiến độ hay tài liệu mà thư này nói về — `null` khi thư không thuộc về bản ghi nào. */
    protected function relatedRecord(): ?Model
    {
        return null;
    }

    /**
     * Reply-To dùng chung cho MỌI thư của văn phòng (M6.5 Task 12, `notify/notify-14`): trước bản
     * sửa này không mẫu nào đặt Reply-To, nên khách/nhân sự bấm "Trả lời" rơi vào
     * `MAIL_FROM_ADDRESS` — một hộp `no-reply@` không ai đọc, dùng cho SPF/DKIM chứ không phải
     * cho người trả lời. `config('vkcrm.brand.reply_to')` là địa chỉ liên hệ THẬT của văn phòng.
     *
     * Trả về `array` (không phải `?string`) đúng chữ ký `Envelope::$replyTo` — mỗi Mailable con
     * gọi thẳng `replyTo: $this->replyToAddresses()` trong `envelope()` của mình: lớp này không có
     * một `envelope()` chung để ghi đè (mỗi mẫu thư có tham số tiêu đề riêng), nên không có chỗ
     * nào khác để đặt Reply-To một lần cho tất cả.
     *
     * @return array<int, string>
     */
    protected function replyToAddresses(): array
    {
        return [config('vkcrm.brand.reply_to')];
    }

    public function headers(): Headers
    {
        $text = [OutboundHeaders::TEMPLATE => $this->template()];

        $related = $this->relatedRecord();

        if ($related !== null) {
            // `getMorphClass()` chứ không phải `::class`: `AppServiceProvider` bật
            // `enforceMorphMap()`, nên bí danh mới là thứ mọi cột `*_type` khác của dự án lưu.
            $text[OutboundHeaders::RELATED] = $related->getMorphClass().':'.$related->getKey();
        }

        return new Headers(text: $text);
    }
}
