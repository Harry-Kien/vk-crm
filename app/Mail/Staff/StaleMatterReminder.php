<?php

namespace App\Mail\Staff;

use App\Jobs\SendStaleMatterMail;
use App\Mail\BrandedMailable;
use App\Models\Matter;
use App\Models\User;
use App\Support\MatterStaleness;
use App\Support\OfficeProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Mẫu `staff.stale_matter` (SPEC §6.4, §9) — hồ sơ đã quá
 * {@see MatterStaleness::EMAIL_AFTER_DAYS} ngày chưa cập nhật cho khách. Gửi bởi
 * {@see SendStaleMatterMail}, người nhận là luật sư phụ trách + mọi manager xem được vụ (đọc
 * §6.4 "đồng gửi mọi manager" là "mọi manager xem được vụ việc đó" — task-7-brief.md, sửa đổi
 * Task 21).
 *
 * Thư gửi NHÂN SỰ, nên được phép mang mã hồ sơ và tiêu đề — cùng lý lẽ
 * `App\Mail\Staff\DeadlineReminder`/`NewClientDocument`. Số ngày trong thân thư tính LẠI lúc
 * render (`MatterStaleness::daysSinceUpdate()`), không phải một ảnh chụp mang từ lúc dispatch:
 * cùng chủ ý "nói đúng con số thật" của `DeadlineReminder` — một thư bị hoãn gửi (retry) vẫn phải
 * nói đúng số ngày CỦA LÚC NÓ THẬT SỰ RỜI TAY, không phải lúc `CheckStaleMatters` xếp hàng.
 */
class StaleMatterReminder extends BrandedMailable
{
    public function __construct(
        public Matter $matter,
        public User $recipient,
    ) {}

    protected function template(): string
    {
        return 'staff.stale_matter';
    }

    protected function relatedRecord(): ?Model
    {
        return $this->matter;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('matters.stale_reminder_email.subject', [
                'code' => $this->matter->code,
            ]),
        );
    }

    /** Đọc thông tin văn phòng LÚC RENDER, không lúc xếp hàng — xem docblock `OfficeProfile`. */
    public function content(): Content
    {
        $office = OfficeProfile::current();

        return new Content(
            view: 'emails.staff.stale-matter-reminder',
            text: 'emails.staff.stale-matter-reminder-text',
            with: [
                'recipientName' => $this->recipient->name,
                'matterCode' => $this->matter->code,
                'matterTitle' => $this->matter->title,
                // Số ngày TRÒN (đã đủ bao nhiêu ngày): `daysSinceUpdate()` trả số thực.
                'daysSinceUpdate' => (int) floor(MatterStaleness::daysSinceUpdate($this->matter) ?? MatterStaleness::EMAIL_AFTER_DAYS),
                'office' => $office->legalName(),
            ],
        );
    }
}
