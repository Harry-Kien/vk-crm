<?php

namespace App\Mail\Staff;

use App\Actions\Schedule\CheckDeadlines;
use App\Jobs\SendDeadlineReminderMail;
use App\Mail\BrandedMailable;
use App\Models\Deadline;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Mẫu `staff.deadline_reminder` của SPEC §9.
 *
 * Thư gửi NHÂN SỰ, nên nó được phép nói bằng ngôn ngữ nghề nghiệp và được phép mang mã hồ sơ —
 * ngược hẳn với thư gửi khách. Nhưng nó vẫn không mang ghi chú nội bộ của dòng tiến độ nào: thư
 * này nói về một mốc thời hạn, và chỉ nói đúng chừng ấy.
 *
 * Tiêu đề thư đổi theo bậc, và đó là chủ ý: một người mở hộp thư lúc 7 giờ sáng phải phân biệt
 * được "còn bảy ngày" với "đã quá hạn" mà không cần mở thư.
 *
 * M6.5 Task 11: lớp này KHÔNG tự `ShouldQueue` — nó không cần, vì kể từ Task 11 nó chỉ còn được
 * dựng bên trong {@see SendDeadlineReminderMail}, một job ĐÃ nằm trên hàng đợi. Trước
 * đó, `CheckDeadlines` dựng và `Mail::to()->send()` lớp này ngay trong `DB::transaction()` của nó
 * — xem docblock của job và của `CheckDeadlines` cho lý do đổi.
 */
class DeadlineReminder extends BrandedMailable
{
    public function __construct(
        public Deadline $deadline,
        public User $recipient,
        public string $tierKey,
    ) {}

    protected function template(): string
    {
        return 'staff.deadline_reminder';
    }

    protected function relatedRecord(): ?Model
    {
        return $this->deadline;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('deadlines.email.subject.'.$this->tierKey, [
                'name' => $this->deadline->name,
                'code' => $this->deadline->matter?->code ?? '',
            ]),
        );
    }

    public function content(): Content
    {
        $daysLeft = (int) today()->diffInDays($this->deadline->due_date, false);

        return new Content(
            view: 'emails.staff.deadline-reminder',
            text: 'emails.staff.deadline-reminder-text',
            with: [
                'recipientName' => $this->recipient->name,
                'deadlineName' => $this->deadline->name,
                'matterCode' => $this->deadline->matter?->code,
                'matterTitle' => $this->deadline->matter?->title,
                'dueDate' => $this->deadline->due_date->format('d/m/Y'),
                'daysLeft' => $daysLeft,
                'isOverdue' => $this->tierKey === CheckDeadlines::OVERDUE_KEY,
                'office' => config('vkcrm.brand.legal_name'),
                'hotline' => config('vkcrm.brand.hotline'),
            ],
        );
    }
}
