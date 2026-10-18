<?php

namespace App\Mail\Staff;

use App\Jobs\SendMatterAssignmentNotice;
use App\Jobs\SendSeatReminderDigest;
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
 * Thư của "Nhận hồ sơ" (làn tk, Task 2) — bốn mẫu `staff.task.matter.<loại>` (hằng `KIND_*`):
 *
 *  - `assigned` (làn fa2, phán quyết 4): người tạo vụ đặt một đồng nghiệp làm luật sư phụ trách — ghế đó chờ
 *    người nhận bấm "Đã nhận hồ sơ", nên họ được báo bằng thư như khi được bàn giao ({@see SendMatterAssignmentNotice}
 *    với `withMail`, xếp từ `OpenMatter`). Chữ ở `internal_flow.seat_assigned` (không ở `tasks.seat.email`);
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
 * Thư GỘP (vòng sửa polish 1 — phán quyết controller (3)): hai mẫu nhắc cho nhiều hồ sơ của cùng một người
 * trong một lượt lịch ({@see SendSeatReminderDigest}, tham số `$batch`) — CÙNG mẫu, tiêu đề và câu mở đầu
 * theo bộ chữ `*_batch` (số hồ sơ), mỗi hồ sơ một dòng; dòng sổ thư gắn hồ sơ đầu, `LEDGER_TIER` mang khoá
 * của mọi ghế trong thư.
 *
 * Đường dẫn (vòng sửa cuối 1, F2): lời nhắc chờ nhận trỏ tới chỗ người nhận bấm nhận ghế ({@see SeatInbox}:
 * "Việc của tôi", hay tab "Đội ngũ" của vụ với quản trị viên…); hai mẫu kia trỏ tab "Đội ngũ" của vụ.
 */
class MatterAssignmentNotice extends BrandedMailable
{
    /** Làn fa2, phán quyết 4 — xem docblock lớp. */
    public const KIND_ASSIGNED = 'assigned';

    public const KIND_DECLINED = 'declined';

    public const KIND_REMINDER_AWAITING = 'reminder.awaiting';

    public const KIND_REMINDER_UNACCEPTED = 'reminder.unaccepted';

    public const TEMPLATE_PREFIX = 'staff.task.matter.';

    /**
     * @param  string  $ledgerKey  khoá của lần báo/nhắc (`payload.tier` của dòng sổ thư)
     * @param  list<array{code: string, member: string, role: string}>  $batch  vòng sửa polish 1 (phán quyết
     *                                                                          controller (3)): thư GỘP hai lời nhắc cho nhiều hồ sơ
     *                                                                          ({@see SendSeatReminderDigest}) — mỗi hồ sơ một dòng; `$matter`
     *                                                                          là hồ sơ đầu (dòng sổ thư, đường dẫn). Rỗng: thư một hồ sơ.
     * @param  string  $assignerName  làn fa2: người giao ghế — chỉ thư {@see self::KIND_ASSIGNED} dùng
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
        public array $batch = [],
        public string $assignerName = '',
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
        if ($this->kind === self::KIND_ASSIGNED) {
            return new Envelope(subject: __('internal_flow.seat_assigned.subject'));
        }

        return new Envelope(
            subject: $this->batch === []
                ? __('tasks.seat.email.subject.'.$this->kind, ['name' => $this->memberName])
                : __('tasks.seat.email.subject.'.$this->kind.'_batch', ['count' => count($this->batch)]),
        );
    }

    /**
     * Dòng chi tiết của thư gộp: mã hồ sơ — vai (lời nhắc người được giao), hay mã hồ sơ — người được giao,
     * vai (lời báo người giao).
     *
     * @return list<array{label: string, value: string}>
     */
    private function batchDetails(bool $toMember): array
    {
        return array_map(fn (array $row): array => [
            'label' => $row['code'],
            'value' => $toMember ? $row['role'] : $row['member'].' — '.$row['role'],
        ], $this->batch);
    }

    public function content(): Content
    {
        if ($this->kind === self::KIND_ASSIGNED) {
            return $this->assignedContent();
        }

        $toMember = $this->kind === self::KIND_REMINDER_AWAITING;

        return new Content(
            view: 'emails.staff.task-notice',
            text: 'emails.staff.task-notice-text',
            with: [
                'recipientName' => $this->recipient->name,
                'headline' => $this->batch === []
                    ? __('tasks.seat.email.headline.'.$this->kind, ['name' => $this->memberName, 'since' => $this->since])
                    : __('tasks.seat.email.headline.'.$this->kind.'_batch', ['count' => count($this->batch), 'since' => $this->since]),
                'details' => $this->batch !== [] ? $this->batchDetails($toMember) : array_values(array_filter([
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

    /**
     * Thư {@see self::KIND_ASSIGNED} (làn fa2): người nhận là người ngồi ghế — đường dẫn tới chỗ họ bấm nhận
     * ({@see SeatInbox}), cùng luật với lời nhắc chờ nhận.
     */
    private function assignedContent(): Content
    {
        return new Content(
            view: 'emails.staff.task-notice',
            text: 'emails.staff.task-notice-text',
            with: [
                'recipientName' => $this->recipient->name,
                'headline' => __('internal_flow.seat_assigned.headline', ['who' => $this->assignerName]),
                'details' => [
                    ['label' => __('tasks.seat.email.fields.matter'), 'value' => (string) $this->matter->code],
                    ['label' => __('tasks.seat.email.fields.role'), 'value' => $this->roleLabel],
                    ['label' => __('internal_flow.seat_assigned.assigned_by'), 'value' => $this->assignerName],
                ],
                'url' => SeatInbox::urlFor($this->recipient, $this->matter),
                'action' => __(SeatInbox::onMyWork($this->recipient) ? 'internal_flow.seat_assigned.action' : 'internal_flow.seat_assigned.action_team_tab'),
                'office' => OfficeProfile::current()->legalName(),
            ],
        );
    }
}
