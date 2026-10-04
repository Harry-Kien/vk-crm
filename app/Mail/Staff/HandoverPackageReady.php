<?php

namespace App\Mail\Staff;

use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Jobs\SendHandoverPackageReady;
use App\Mail\BrandedMailable;
use App\Models\Document;
use App\Models\Matter;
use App\Models\User;
use App\Support\OfficeProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Mẫu thư `staff.handover_ready` (M7 Task 4): báo nhân sự gói bàn giao hồ sơ đã sinh xong và cần
 * được xem lại rồi công bố. Chỉ gửi cho nhân sự, nên được phép mang mã và tên vụ việc (người nhận
 * đã qua `ResolveStaffRecipients`, tức xem được vụ — kể cả vụ `restricted`). Gửi bởi
 * {@see SendHandoverPackageReady} bằng `Mail::send()` từ trong job (việc sau gộp M7, làn fu2); không
 * `ShouldQueue` ở lớp này và không bao giờ `Mail::queue()` nó: lớp mang nguyên model `User`, và
 * `BrandedMailable` không `SerializesModels` — xếp hàng nó là chép mã băm mật khẩu và bí mật 2FA của
 * người nhận vào `jobs.payload` (docblock job).
 *
 * Không đính kèm gói: nó có thể vài trăm MB, và khách chưa được thấy nó — người nhận mở vụ việc để
 * xem lại. Thư chỉ mang đường dẫn tới trang vụ việc (cần đăng nhập).
 */
class HandoverPackageReady extends BrandedMailable
{
    public function __construct(
        public User $recipient,
        public Matter $matter,
        public Document $document,
    ) {}

    protected function template(): string
    {
        return 'staff.handover_ready';
    }

    protected function relatedRecord(): ?Model
    {
        return $this->document;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('handover.email.subject', ['code' => $this->matter->code]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.staff.handover-ready',
            text: 'emails.staff.handover-ready-text',
            with: [
                'recipientName' => $this->recipient->name,
                'matterCode' => $this->matter->code,
                'matterTitle' => $this->matter->title,
                'url' => MatterResource::getUrl('view', ['record' => $this->matter], panel: 'admin'),
                // Đọc LÚC RENDER, không lúc xếp hàng — xem docblock `OfficeProfile`.
                'office' => OfficeProfile::current()->legalName(),
            ],
        );
    }
}
