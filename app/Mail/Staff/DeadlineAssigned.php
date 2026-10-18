<?php

namespace App\Mail\Staff;

use App\Filament\Admin\Resources\Matters\RelationManagers\DeadlinesRelationManager;
use App\Jobs\SendDeadlineAssignmentNotice;
use App\Mail\BrandedMailable;
use App\Mail\OutboundHeaders;
use App\Models\Deadline;
use App\Models\User;
use App\Notifications\Staff\DeadlineAssignedAlert;
use App\Support\MatterTab;
use App\Support\OfficeProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Mẫu `staff.deadline_assigned` (làn fa2, phán quyết 6): "anh/chị được giao một mốc thời hạn" — gửi NGAY người giữ
 * mốc mới khi người khác giao mốc cho họ (thêm mốc, sửa mốc, "Đổi người phụ trách"), kèm hạn và số ngày còn lại
 * ({@see DeadlineAssignedAlert::dueLine()}). Chỉ {@see SendDeadlineAssignmentNotice} gửi, sau khi đã lọc người nhận.
 * Thư này đứng thay bậc nhắc gần nhất đã gửi cho người giữ cũ: `CheckDeadlines` không gửi lại bậc đã qua.
 *
 * Tiêu đề không mang mã hồ sơ, tên mốc hay tên khách (hiện trên màn hình khoá); thân thư mang tên mốc và mã hồ sơ
 * — người nhận mở được hồ sơ ở lúc gửi. Bản ghi liên quan là mốc. `LEDGER_TIER` mang khoá của lần giao. Không gửi
 * lại được từ nhật ký thư (`ResendTargets::NOT_RESENDABLE`).
 */
class DeadlineAssigned extends BrandedMailable
{
    public function __construct(
        public Deadline $deadline,
        public User $recipient,
        public string $actorName,
        public string $ledgerKey,
    ) {}

    protected function template(): string
    {
        return 'staff.deadline_assigned';
    }

    protected function relatedRecord(): ?Model
    {
        return $this->deadline;
    }

    /** @return array<string, string> */
    protected function additionalLedgerHeaders(): array
    {
        return [OutboundHeaders::LEDGER_TIER => $this->ledgerKey];
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('internal_flow.deadline_assigned.email.subject'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.staff.task-notice',
            text: 'emails.staff.task-notice-text',
            with: [
                'recipientName' => $this->recipient->name,
                'headline' => __('internal_flow.deadline_assigned.email.headline', ['who' => $this->actorName]),
                'details' => [
                    ['label' => __('internal_flow.deadline_assigned.email.fields.name'), 'value' => (string) $this->deadline->name],
                    ['label' => __('internal_flow.deadline_assigned.email.fields.matter'), 'value' => (string) $this->deadline->matter?->code],
                    ['label' => __('internal_flow.deadline_assigned.email.fields.due'), 'value' => DeadlineAssignedAlert::dueLine($this->deadline)],
                    ['label' => __('internal_flow.deadline_assigned.email.fields.severity'), 'value' => $this->deadline->severity->label()],
                ],
                'url' => MatterTab::url((int) $this->deadline->matter_id, DeadlinesRelationManager::class),
                'action' => __('internal_flow.deadline_assigned.email.action'),
                'office' => OfficeProfile::current()->legalName(),
            ],
        );
    }
}
