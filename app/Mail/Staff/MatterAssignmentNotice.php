<?php

namespace App\Mail\Staff;

use App\Jobs\SendMatterAssignmentNotice;
use App\Jobs\SendWorkReminder;
use App\Mail\BrandedMailable;
use App\Mail\OutboundHeaders;
use App\Models\Matter;
use App\Models\User;
use App\Support\OfficeProfile;
use App\Support\Tasks\SeatInbox;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Thư của "Nhận hồ sơ" (làn tk, Task 2) — ba mẫu `staff.task.matter.<loại>` (hằng `KIND_*`):
 *
 *  - `declined`: người nhận đã trả lại một ghế trong đội ngũ vụ, kèm lý do — gửi người giao, luật sư phụ
 *    trách, người giám sát vụ ({@see SendMatterAssignmentNotice});
 *  - `reminder.awaiting`: lịch nhắc — ghế chờ người nhận bấm "Đã nhận hồ sơ" đã quá ngưỡng giờ làm việc;
 *  - `reminder.unaccepted`: lịch nhắc — báo người giao khi người nhận vẫn chưa nhận sau ngưỡng thứ hai
 *    ({@see SendWorkReminder}).
 *
 * Họ mẫu `staff.task.*` — không gửi lại được (`ResendTargets`), không đẩy lên điện thoại. Tiêu đề thư không
 * mang mã vụ hay tên khách (dòng tiêu đề hiện trên màn hình khoá); thân thư mang mã vụ, người, vai, lý do —
 * người nhận xem được vụ ở lúc gửi (job đã lọc). Bản ghi liên quan là vụ việc. Header `LEDGER_TIER` mang
 * khoá của LẦN báo/nhắc — job so khoá này để một lần thử lại không gửi lần hai.
 *
 * Đường dẫn (vòng sửa cuối 1, F2): lời nhắc chờ nhận trỏ tới chỗ người nhận bấm nhận ghế ({@see SeatInbox}:
 * "Việc của tôi", hay tab "Đội ngũ" của vụ với quản trị viên…); hai mẫu kia trỏ tab "Đội ngũ" của vụ.
 */
class MatterAssignmentNotice extends BrandedMailable
{
    public const KIND_DECLINED = 'declined';

    public const KIND_REMINDER_AWAITING = 'reminder.awaiting';

    public const KIND_REMINDER_UNACCEPTED = 'reminder.unaccepted';

    public const TEMPLATE_PREFIX = 'staff.task.matter.';

    /**
     * @param  string  $ledgerKey  khoá của lần báo/nhắc (`payload.tier` của dòng sổ thư)
     */
    public function __construct(
        public Matter $matter,
        public string $kind,
        public User $recipient,
        public string $memberName,
        public string $roleLabel,
        public ?string $reason,
        public string $since,
        public string $ledgerKey,
    ) {}

    protected function template(): string
    {
        return self::TEMPLATE_PREFIX.$this->kind;
    }

    protected function relatedRecord(): ?Model
    {
        return $this->matter;
    }

    /** @return array<string, string> */
    protected function additionalLedgerHeaders(): array
    {
        return [OutboundHeaders::LEDGER_TIER => $this->ledgerKey];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('tasks.seat.email.subject.'.$this->kind, ['name' => $this->memberName]),
        );
    }

    public function content(): Content
    {
        $toMember = $this->kind === self::KIND_REMINDER_AWAITING;

        return new Content(
            view: 'emails.staff.task-notice',
            text: 'emails.staff.task-notice-text',
            with: [
                'recipientName' => $this->recipient->name,
                'headline' => __('tasks.seat.email.headline.'.$this->kind, ['name' => $this->memberName, 'since' => $this->since]),
                'details' => array_values(array_filter([
                    ['label' => __('tasks.seat.email.fields.matter'), 'value' => (string) $this->matter->code],
                    ['label' => __('tasks.seat.email.fields.member'), 'value' => $this->memberName],
                    ['label' => __('tasks.seat.email.fields.role'), 'value' => $this->roleLabel],
                    $this->kind === self::KIND_DECLINED ? ['label' => __('tasks.seat.email.fields.reason'), 'value' => (string) $this->reason] : null,
                ])),
                // Vòng sửa cuối 1 (F2): chỗ người nhận bấm nhận ghế — `SeatInbox` (docblock lớp).
                'url' => $toMember
                    ? SeatInbox::urlFor($this->recipient, $this->matter)
                    : SeatInbox::teamTabUrl((int) $this->matter->getKey()),
                'action' => __('tasks.seat.email.action.'.$this->kind.($toMember && ! SeatInbox::onMyWork($this->recipient) ? '_team_tab' : '')),
                'office' => OfficeProfile::current()->legalName(),
            ],
        );
    }
}
