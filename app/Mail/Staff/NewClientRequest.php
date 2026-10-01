<?php

namespace App\Mail\Staff;

use App\Actions\Notification\ResolveStaffRecipients;
use App\Mail\BrandedMailable;
use App\Models\ClientRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Mẫu `staff.new_client_request` của SPEC §9 — khách vừa gửi một yêu cầu qua cổng
 * (`App\Actions\Portal\OpenClientRequest`), kích hoạt bởi `App\Events\ClientRequestOpened` và gửi
 * bởi `App\Actions\Notification\NotifyStaffOfNewClientRequest`.
 *
 * Thư gửi NHÂN SỰ, không phải khách hàng: nó được phép mang mã hồ sơ, và — khác hẳn thư cho khách
 * — thân thư ĐƯỢC PHÉP trích nguyên văn tiêu đề yêu cầu (`ClientRequest::subject`), vì người nhận
 * đã qua {@see ResolveStaffRecipients} (R3: đang `is_active` và xem
 * được đúng vụ việc này).
 *
 * **Tiêu đề KHÔNG mang chữ khách gõ** (phán quyết controller, task-4-brief.md — cùng lý do tiêu đề
 * thư về tài liệu không mang tên tài liệu, M6.5 Task 13): dòng `outbound_messages` của thư này
 * hiện cho MỌI người xem được vụ việc trên màn hình nhật ký thư nội bộ, và `subject`/`content` của
 * một yêu cầu có thể chứa thông tin khách chỉ muốn nói với đúng người phụ trách. Tiêu đề vì vậy
 * chỉ mang mã hồ sơ và một câu chung; nội dung khách gõ chỉ nằm trong THÂN thư.
 */
class NewClientRequest extends BrandedMailable
{
    public function __construct(
        public ClientRequest $request,
        public User $recipient,
    ) {}

    protected function template(): string
    {
        return 'staff.new_client_request';
    }

    protected function relatedRecord(): ?Model
    {
        return $this->request;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('requests.email.new_request.subject', [
                'code' => $this->request->matter?->code ?? '',
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.staff.new-client-request',
            text: 'emails.staff.new-client-request-text',
            with: [
                'recipientName' => $this->recipient->name,
                'matterCode' => $this->request->matter?->code,
                'matterTitle' => $this->request->matter?->title,
                'requestSubject' => $this->request->subject,
                'requestContent' => $this->request->content,
                'office' => config('vkcrm.brand.legal_name'),
            ],
        );
    }
}
