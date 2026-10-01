<?php

namespace App\Mail;

use App\Support\OfficeProfile;
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
     * cho người trả lời. "Email liên hệ" của {@see OfficeProfile} là địa chỉ liên hệ THẬT của văn
     * phòng — sửa được ở trang "Thông tin văn phòng" (M7 Task 10), mặc định
     * `BRAND_REPLY_TO_ADDRESS` của `.env` — đọc LÚC GỬI, nên một thư đã xếp hàng mang địa chỉ mới.
     *
     * **Blank-safe (vòng sửa 1, minor).** `BRAND_REPLY_TO_ADDRESS=` (rỗng, có chủ ý đặt vậy trong
     * `.env`) nghĩa là "chưa cấu hình", KHÔNG được dựng thành một `Address('')` — trả `null` để
     * {@see self::prepareMailableForDelivery()} bỏ qua hẳn, không gọi `$this->replyTo()` chút nào.
     *
     * Từ M7 Task 10, phép thử "trống" là `filled()` BÊN TRONG {@see OfficeProfile} — ở `stored()`
     * và `configured()`, hai nửa của `value()` (trim rồi so `''`, không `empty()`): một chuỗi CHỈ
     * CÓ KHOẢNG TRẮNG (gõ nhầm `BRAND_REPLY_TO_ADDRESS=" "`) làm `empty(' ')` trả `false` — lọt
     * qua thì Symfony ném
     * `RfcComplianceException` ngay khi gửi thư thật (`Email " " does not comply with addr-spec of
     * RFC 2822`), một lỗi 500 cho MỌI thư của văn phòng chỉ vì một khoảng trắng gõ nhầm trong
     * `.env`. Đo được: `tests/Feature/Mail/SenderIdentityTest.php`, "treats a whitespace-only
     * reply-to address as blank too". Giá trị lưu từ trang thì đã qua luật `email` của
     * `UpdateOfficeProfile`.
     */
    protected function replyToAddress(): ?string
    {
        return OfficeProfile::current()->replyTo();
    }

    /**
     * Áp Reply-To MẶC ĐỊNH cho MỌI mẫu thư kế thừa lớp này (vòng sửa 1, minor — "set it in
     * BrandedMailable itself so every subclass gets it by default").
     *
     * **Vì sao ở ĐÂY, không phải một `envelope()` chung.** Lớp này không có `envelope()` để mẫu
     * con ghi đè (mỗi mẫu có tham số tiêu đề riêng — `template()`/`content()` là trừu tượng, nhưng
     * `envelope()` thì KHÔNG, mỗi mẫu tự khai báo trọn vẹn). `prepareMailableForDelivery()` thì
     * KHÁC: `Illuminate\Mail\Mailable::send()` luôn gọi nó — bất kể mẫu con có tự định nghĩa
     * `envelope()` ra sao — NGAY TRƯỚC khi thư được dựng, nên đây là chỗ DUY NHẤT áp một mặc định
     * cho "mọi mẫu thư", không cần từng mẫu tự gọi một dòng nào.
     *
     * `parent::prepareMailableForDelivery()` TRƯỚC: đó là nơi Laravel hydrate `$this->replyTo` từ
     * `envelope()->replyTo` của chính mẫu con (nếu mẫu con có tự đặt — hiện tại chưa mẫu nào cần,
     * nhưng không cấm). `empty($this->replyTo)` sau đó mới đúng nghĩa "mẫu con chưa tự đặt gì" —
     * kiểm TRƯỚC khi gọi parent sẽ luôn thấy rỗng, dù mẫu con CÓ đặt, và ghi đè nhầm lên nó.
     */
    protected function prepareMailableForDelivery()
    {
        parent::prepareMailableForDelivery();

        if (empty($this->replyTo)) {
            $address = $this->replyToAddress();

            if ($address !== null) {
                $this->replyTo($address);
            }
        }
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

        return new Headers(text: $text + $this->additionalLedgerHeaders());
    }

    /**
     * Header nội bộ THÊM, ngoài `Template`/`Related` — vòng sửa 2 (I1): `App\Mail\Staff\
     * DeadlineReminder` ghi đè để mang theo BẬC nhắc (`X-VKCRM-Ledger-Tier`), thứ
     * `RecordOutboundMessage::sending()` chép vào `payload['tier']`, và
     * `App\Support\Mail\OutboundLedgerTransport` gỡ khỏi thông điệp trước khi nó rời máy chủ —
     * cùng luật với `Template`/`Related`/`Ledger-Id` (`notify/notify-11`). Rỗng theo mặc định:
     * hầu hết mẫu thư không có khái niệm "bậc" nào để mang.
     *
     * @return array<string, string>
     */
    protected function additionalLedgerHeaders(): array
    {
        return [];
    }
}
