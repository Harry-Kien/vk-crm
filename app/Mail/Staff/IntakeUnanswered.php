<?php

namespace App\Mail\Staff;

use App\Filament\Admin\Resources\IntakeRequests\IntakeRequestResource;
use App\Jobs\SendUnansweredIntakeReminderMail;
use App\Mail\BrandedMailable;
use App\Models\IntakeRequest;
use App\Models\User;
use App\Support\Intake\FirstResponseClock;
use App\Support\OfficeProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Mẫu `staff.intake_unanswered` (SPEC §9, đính chính M10): một lần có người liên hệ văn phòng đã quá
 * ngưỡng phản hồi lần đầu (mặc định 4 giờ làm việc) mà chưa ai gọi lại (M10 R5).
 *
 * **Thư chỉ mang mã bản ghi, nguồn, lúc nhận, thời gian đã chờ và liên kết** — KHÔNG tên, số điện
 * thoại, email, người giới thiệu, câu chuyện hay bên đối lập của người liên hệ. Hộp thư là nơi dữ liệu
 * nằm lâu nhất và ít ai kiểm soát nhất; người được nhắc mở liên kết và đọc phần còn lại trong hệ
 * thống, sau `IntakeRequestPolicy::view`. Lớp này vì vậy chỉ đọc đúng năm thứ đó của bản ghi — thêm
 * một trường nữa vào `content()` là một quyết định về dữ liệu cá nhân, không phải về câu chữ.
 *
 * "Thời gian đã chờ" tính theo GIỜ LÀM VIỆC lúc thư được dựng (trong job, ngay trước khi gửi), cùng
 * đồng hồ với ngưỡng ({@see FirstResponseClock}).
 *
 * Không tự `ShouldQueue`: lớp này chỉ được dựng bên trong {@see SendUnansweredIntakeReminderMail}, một
 * job đã nằm trên hàng đợi (M6.5 R2) — cùng khuôn `DeadlineReminder`.
 */
class IntakeUnanswered extends BrandedMailable
{
    public const TEMPLATE = 'staff.intake_unanswered';

    public function __construct(
        public IntakeRequest $intake,
        public User $recipient,
    ) {}

    protected function template(): string
    {
        return self::TEMPLATE;
    }

    protected function relatedRecord(): ?Model
    {
        return $this->intake;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('intake.reminder.email.subject', [
                'code' => $this->intake->code,
                'hours' => FirstResponseClock::fromConfig()->thresholdHours,
            ]),
        );
    }

    public function content(): Content
    {
        $clock = FirstResponseClock::fromConfig();

        return new Content(
            view: 'emails.staff.intake-unanswered',
            text: 'emails.staff.intake-unanswered-text',
            with: [
                'recipientName' => $this->recipient->name,
                'code' => $this->intake->code,
                'source' => $this->intake->source->label(),
                'receivedAt' => $this->intake->received_at->format('H:i d/m/Y'),
                'waited' => FirstResponseClock::formatMinutes($clock->waitedMinutes($this->intake)),
                'thresholdHours' => $clock->thresholdHours,
                'url' => IntakeRequestResource::getUrl('edit', ['record' => $this->intake], panel: 'admin'),
                // Gộp `main` (M7 Task 9): tên văn phòng sửa được ở trang "Thông tin văn phòng" — đọc lúc
                // render, qua MỘT chỗ như mọi thư khác (`OfficeProfile`), không đọc thẳng cấu hình.
                'office' => OfficeProfile::current()->legalName(),
            ],
        );
    }
}
