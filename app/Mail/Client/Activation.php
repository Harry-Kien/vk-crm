<?php

namespace App\Mail\Client;

use App\Mail\BrandedMailable;
use App\Models\ClientUser;
use App\Support\OfficeProfile;
use App\Support\PortalUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use SensitiveParameter;

/**
 * Mẫu `client.activation` của SPEC §9 — con đường DUY NHẤT một khách có mật khẩu cổng thông tin
 * (ghi nhận ở brief Task 3, "Những chỗ đã biết trước là sẽ cắn": "Portal không có đường đặt lại
 * mật khẩu"). Trước Task 3, tài khoản portal được tạo bằng cách một luật sư GÕ TAY mật khẩu vào
 * form rồi đọc cho khách qua điện thoại — không có thư kích hoạt nào cả, và mật khẩu đó nằm
 * nguyên văn trong request Livewire, trong `activity_log` (nếu form log field đó), và trong trí
 * nhớ của người đọc điện thoại. `App\Actions\Client\IssuePortalAccess` cùng thư này lấp lỗ đó.
 *
 * **Ngoại lệ CÓ CHỦ Ý của R12** (nói rõ trong docblock `App\Actions\Notification\
 * ResolveClientRecipients`): thư này gửi tới CHÍNH tài khoản vừa được cấp/cấp lại quyền truy cập,
 * tài khoản đó `activated_at` CÒN NULL — vì đây CHÍNH LÀ thư chứng minh hộp thư, không phải phần
 * thưởng cho một hộp thư đã chứng minh rồi. Vẫn đòi `is_active` và khách chưa xoá mềm — xem
 * `App\Jobs\SendPortalActivationMail::stillEligibleForActivation()`.
 *
 * **Mật khẩu tạm không bao giờ được serialize.** `$temporaryPassword` là tham số CONSTRUCTOR của
 * một Mailable KHÔNG `ShouldQueue` — lớp này chỉ được dựng và gửi (`Mail::to()->send()`, ĐỒNG BỘ)
 * BÊN TRONG `App\Jobs\SendPortalActivationMail::handle()`, một job ĐÃ nằm trên hàng đợi. Không có
 * gì serialize đối tượng NÀY vào bảng `jobs`: nó sống và chết trong đúng một lần gọi hàm, đúng
 * cách `App\Mail\Client\StageUpdate` không bao giờ bị `ShouldQueue` cho chính nó (xem docblock lớp
 * đó và `App\Listeners\SendStageUpdateNotification`). `#[SensitiveParameter]` chỉ là một lớp
 * phòng thủ THÊM: nó che tham số này khỏi backtrace của một exception không bắt được (`ini_set`
 * `zend.exception_ignore_args` không cần bật để có tác dụng đó — PHP 8.2+ áp dụng thuộc tính này
 * bất kể ini), phòng khi thứ gì đó ngày sau dump backtrace vào log.
 */
class Activation extends BrandedMailable
{
    /**
     * @param  bool  $reissue  Việc sau gộp M6 (làn fu, mục 6 — N1): `true` khi đây là lần CẤP LẠI
     *                         (nút "Cấp lại mật khẩu"; đổi email hay bật lại tài khoản trên trang
     *                         sửa CHỈ khi tài khoản đã từng được cấp —
     *                         `IssuePortalAccess::hasBeenIssued()`) — thư không nói "đã tạo tài
     *                         khoản" và nói rõ mật khẩu trước không còn dùng được. Nơi gọi
     *                         `App\Actions\Client\IssuePortalAccess` quyết định, job chỉ chuyển
     *                         tiếp; thư không tự đoán.
     */
    public function __construct(
        public ClientUser $recipient,
        #[SensitiveParameter]
        public string $temporaryPassword,
        public bool $reissue = false,
    ) {}

    protected function template(): string
    {
        return 'client.activation';
    }

    /**
     * `client_user`, không `matter` — tài khoản không thuộc về một vụ việc cụ thể nào. Không nằm
     * trong `OutboundMessage::DIRECT_MATTER_TYPES` (SPEC §4.15), nên dòng nhật ký của thư này chỉ
     * admin thấy trên màn hình nhật ký thư (M6.5 Task 13) — an toàn, chấp nhận được (xem báo cáo
     * Task 3).
     */
    protected function relatedRecord(): ?Model
    {
        return $this->recipient;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __($this->reissue ? 'portal.email.activation.subject_reissued' : 'portal.email.activation.subject'),
        );
    }

    /**
     * `line` và `previousInvalid` chọn ở ĐÂY (không ở view), để bản HTML và bản văn bản thuần không
     * thể nói hai điều khác nhau về lần cấp này.
     */
    public function content(): Content
    {
        $office = OfficeProfile::current();

        return new Content(
            view: 'emails.client.activation',
            text: 'emails.client.activation-text',
            with: [
                'name' => $this->recipient->name,
                'email' => $this->recipient->email,
                'line' => __($this->reissue ? 'portal.email.activation.line_reissued' : 'portal.email.activation.line'),
                'previousInvalid' => $this->reissue ? __('portal.email.activation.previous_invalid') : null,
                'temporaryPassword' => $this->temporaryPassword,
                'portalUrl' => PortalUrl::base(),
                'office' => $office->legalName(),
                'hotline' => $office->hotline(),
            ],
        );
    }
}
