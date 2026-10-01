<?php

namespace App\Mail\Staff;

use App\Jobs\SendDeadlineReminderMail;
use App\Mail\BrandedMailable;
use App\Mail\OutboundHeaders;
use App\Models\Deadline;
use App\Models\User;
use App\Support\OfficeProfile;
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
 * Tiêu đề thư đọc theo SỐ NGÀY THẬT CÒN LẠI, không theo bậc `$tierKey` (M6.5 Task 12,
 * `deadlines/F3`) — xem docblock `lang/vi/deadlines.php`, khoá `email.subject`/`email.headline`.
 * Đó là chủ ý: một người mở hộp thư lúc 7 giờ sáng phải phân biệt được "còn bảy ngày" với "đã quá
 * hạn" mà không cần mở thư, và con số đó phải là con số THẬT — không phải con số của bậc đã chọn
 * lời nhắc này (`tierFor()` chọn ĐÚNG bậc theo "số ngày còn lại ≤ bậc", nên số ngày còn lại luôn
 * NHỎ HƠN HOẶC BẰNG con số của bậc, không bao giờ lớn hơn — dùng con số của bậc là luôn nói ít
 * cấp bách hơn sự thật, không bao giờ ngược lại).
 *
 * `$tierKey` vẫn giữ nguyên trong constructor (không đổi chữ ký, `SendDeadlineReminderMail` và bộ
 * test hiện có còn truyền nó): nó vẫn cần cho việc khác (chống gửi trùng ở `reminders_sent`), chỉ
 * không còn dùng để chọn CHỮ của tiêu đề/thân thư nữa.
 *
 * M6.5 Task 11: lớp này KHÔNG tự `ShouldQueue` — nó không cần, vì kể từ Task 11 nó chỉ còn được
 * dựng bên trong {@see SendDeadlineReminderMail}, một job ĐÃ nằm trên hàng đợi. Trước
 * đó, `CheckDeadlines` dựng và `Mail::to()->send()` lớp này ngay trong `DB::transaction()` của nó
 * — xem docblock của job và của `CheckDeadlines` cho lý do đổi.
 */
class DeadlineReminder extends BrandedMailable
{
    /**
     * @param  string|null  $dueDate  ngày đến hạn (`Y-m-d`) mà lời nhắc này nói tới — ảnh chụp
     *                                `SendDeadlineReminderMail` mang từ lúc CheckDeadlines xếp hàng;
     *                                `null` thì lấy `due_date` hiện tại của mốc. Xem {@see self::ledgerTier()}.
     */
    public function __construct(
        public Deadline $deadline,
        public User $recipient,
        public string $tierKey,
        public ?string $dueDate = null,
    ) {}

    /**
     * Khoá chống gửi trùng của nhật ký thư: `bậc@ngày đến hạn`, ví dụ `d1@2026-10-01` (M6.5 Task
     * 14, fix round 1, C1). MỘT định nghĩa — lớp này ghi nó vào header, và
     * `App\Jobs\SendDeadlineReminderMail::alreadyDelivered()` so đúng chuỗi này.
     *
     * Ngày là phần bắt buộc của khoá: `UpdateDeadline` dọn `reminders_sent` khi hoãn một mốc để bậc
     * được nhắc lại cho ngày MỚI, và một khoá chỉ theo bậc sẽ thấy `d1` đã gửi cho ngày CŨ rồi im
     * lặng. Trong đời của MỘT ngày đến hạn, khoá vẫn ổn định qua ranh giới ngày (`d1` ở còn 1 ngày
     * và còn 0 ngày; `overdue` mọi ngày quá hạn) — đúng chống trùng của vòng sửa 2 Task 12.
     */
    public static function ledgerTier(string $tierKey, string $dueDate): string
    {
        return $tierKey.'@'.$dueDate;
    }

    protected function template(): string
    {
        return 'staff.deadline_reminder';
    }

    protected function relatedRecord(): ?Model
    {
        return $this->deadline;
    }

    /**
     * Vòng sửa 2 (I1): mang theo BẬC nhắc qua header nội bộ, để nhật ký chống gửi trùng theo
     * ĐÚNG bậc (`payload['tier']`) chứ không theo tiêu đề (đổi mỗi ngày) — xem docblock
     * `App\Mail\OutboundHeaders::LEDGER_TIER` và `App\Jobs\SendDeadlineReminderMail::
     * alreadyDelivered()`. Task 14 fix round 1 (C1): bậc KÈM ngày đến hạn — {@see self::ledgerTier()}.
     *
     * @return array<string, string>
     */
    protected function additionalLedgerHeaders(): array
    {
        return [OutboundHeaders::LEDGER_TIER => self::ledgerTier(
            $this->tierKey,
            $this->dueDate ?? $this->deadline->due_date->toDateString(),
        )];
    }

    public function envelope(): Envelope
    {
        $daysLeft = $this->daysLeft();

        return new Envelope(
            subject: __('deadlines.email.subject.'.$this->headlineKey($daysLeft), [
                'days' => abs($daysLeft),
                'name' => $this->deadline->name,
                'code' => $this->deadline->matter?->code ?? '',
            ]),
        );
    }

    /** Đọc thông tin văn phòng LÚC RENDER, không lúc xếp hàng — xem docblock `OfficeProfile`. */
    public function content(): Content
    {
        $daysLeft = $this->daysLeft();
        $office = OfficeProfile::current();

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
                'headlineKey' => $this->headlineKey($daysLeft),
                'office' => $office->legalName(),
                'hotline' => $office->hotline(),
            ],
        );
    }

    private function daysLeft(): int
    {
        return (int) today()->diffInDays($this->deadline->due_date, false);
    }

    /**
     * Khoá dùng chung cho `email.subject.*` và `email.headline.*` — CÙNG một khoá cho cả hai
     * (subject và thân thư nói cùng một điều bằng cùng một sự thật), phân biệt theo DẤU của số
     * ngày còn lại, không theo `$tierKey`: xem docblock lớp.
     */
    private function headlineKey(int $daysLeft): string
    {
        return match (true) {
            $daysLeft > 0 => 'upcoming',
            $daysLeft === 0 => 'due_today',
            default => 'overdue',
        };
    }
}
