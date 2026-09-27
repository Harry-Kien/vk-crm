<?php

namespace App\Mail\Client;

use App\Mail\BrandedMailable;
use App\Models\ClientUser;
use App\Models\StageLog;
use App\Support\PortalUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

/**
 * Mẫu `client.stage_update` của SPEC §9 — báo khách có cập nhật mới trên hồ sơ.
 *
 * SPEC §9 đặt hai ràng buộc và chúng kéo về hai hướng: "chỉ chứa nội dung đã công bố, tuyệt đối
 * không nhúng `internal_note`", và "nội dung tóm tắt ngắn, chi tiết mời bấm vào portal".
 *
 * CÁI GÌ ĐƯỢC ĐẶT VÀO THÂN THƯ, và vì sao đó là một quyết định chứ không phải mặc định: hộp thư
 * của khách kém an toàn hơn cổng khách — nó có thể mở trên máy chung, chuyển tiếp cho người nhà,
 * hoặc nằm trong một tài khoản đã bị lộ. Nhưng một thư chỉ nói "có cập nhật, mời đăng nhập" thì
 * phần lớn người đọc sẽ không đăng nhập. Chọn ở giữa: một đoạn TRÍCH NGẮN của nội dung đã công
 * bố, cộng đúng việc khách cần làm nếu có. Cả hai thứ đó khách đã được quyền đọc; cái không bao
 * giờ có mặt là ghi chú nội bộ, và có test ghim điều đó bằng một chuỗi đánh dấu.
 *
 * Tiêu đề thư mang MÃ HỒ SƠ chứ không mang tiêu đề vụ việc. Mã là thứ khách nhận ra mà người
 * ngoài liếc qua hộp thư thì không đọc được gì về nội dung vụ việc.
 */
class StageUpdate extends BrandedMailable
{
    /** Đủ để biết chuyện gì, không đủ để thay cho việc mở cổng khách. */
    private const EXCERPT_LENGTH = 200;

    public function __construct(
        public StageLog $stageLog,
        public ClientUser $recipient,
    ) {}

    protected function template(): string
    {
        return 'client.stage_update';
    }

    protected function relatedRecord(): ?Model
    {
        return $this->stageLog;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('portal.email.stage_update.subject', [
                'code' => $this->stageLog->matter?->code ?? '',
            ]),
            replyTo: $this->replyToAddresses(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.client.stage-update',
            text: 'emails.client.stage-update-text',
            with: [
                'name' => $this->recipient->name,
                'matterCode' => $this->stageLog->matter?->code,
                'excerpt' => Str::limit((string) $this->stageLog->public_content, self::EXCERPT_LENGTH),
                'clientAction' => $this->stageLog->client_action,
                // M6.5 Task 12 (`notify/notify-10`, `spec-gap/spec-gap-09`): KHÔNG `url('/portal')`
                // — thư này dựng SAU một request Livewire ở /admin (kể cả từ hàng đợi, Task 11),
                // nên `url()` lấy nhầm host quản trị khi ADMIN_DOMAIN/PORTAL_DOMAIN tách riêng.
                // Xem docblock `App\Support\PortalUrl`.
                'portalUrl' => PortalUrl::base(),
                'office' => config('vkcrm.brand.legal_name'),
                'hotline' => config('vkcrm.brand.hotline'),
            ],
        );
    }
}
