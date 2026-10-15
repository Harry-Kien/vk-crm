<?php

namespace App\Mail\Staff;

use App\Actions\Schedule\RemindPendingWork;
use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Jobs\SendWorkReminder;
use App\Mail\BrandedMailable;
use App\Mail\OutboundHeaders;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskReminderAlert;
use App\Support\OfficeProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Thư của lịch nhắc VIỆC giao nội bộ (làn tk, Task 2) — năm mẫu `staff.task.reminder.<loại>` (hằng
 * `KIND_*`, cùng giá trị các hằng loại của {@see RemindPendingWork}). Chỉ {@see SendWorkReminder} gửi, sau
 * khi đã lọc người nhận (còn xem được việc) và hỏi lại điều kiện nhắc lúc gửi.
 *
 * Họ mẫu `staff.task.*`: không gửi lại được (`ResendTargets`), không đẩy lên điện thoại. Tiêu đề thư không
 * mang tên việc hay mã vụ; thân thư mang tiêu đề việc, mã vụ, người nhận việc, hạn, mức ưu tiên — như
 * {@see TaskNotice}. Header `LEDGER_TIER` mang khoá của LẦN nhắc — một lượt nhắc chạy lại không gửi lần hai.
 */
class TaskReminder extends BrandedMailable
{
    public const KIND_AWAITING = 'reminder.awaiting';

    public const KIND_UNACCEPTED = 'reminder.unaccepted';

    public const KIND_DUE_SOON = 'reminder.due_soon';

    public const KIND_OVERDUE = 'reminder.overdue';

    public const KIND_OVERDUE_ASSIGNER = 'reminder.overdue_assigner';

    public const TEMPLATE_PREFIX = 'staff.task.';

    public function __construct(
        public Task $task,
        public string $kind,
        public User $recipient,
        public string $detail,
        public string $ledgerKey,
    ) {}

    protected function template(): string
    {
        return self::TEMPLATE_PREFIX.$this->kind;
    }

    protected function relatedRecord(): ?Model
    {
        return $this->task;
    }

    /** @return array<string, string> */
    protected function additionalLedgerHeaders(): array
    {
        return [OutboundHeaders::LEDGER_TIER => $this->ledgerKey];
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('tasks.reminder.email.subject.'.$this->kindKey()));
    }

    public function content(): Content
    {
        $task = $this->task;
        $line = fn (string $key, string $value): array => ['label' => __('tasks.email.fields.'.$key), 'value' => $value];

        return new Content(
            view: 'emails.staff.task-notice',
            text: 'emails.staff.task-notice-text',
            with: [
                'recipientName' => $this->recipient->name,
                'headline' => __('tasks.reminder.email.headline.'.$this->kindKey(), [
                    'name' => (string) $task->assignee?->name,
                    'detail' => $this->detail,
                ]),
                'details' => array_values(array_filter([
                    $line('title', $task->title),
                    $task->matter?->code === null ? null : $line('matter', $task->matter->code),
                    ['label' => __('tasks.reminder.email.assignee'), 'value' => (string) $task->assignee?->name],
                    $line('due_date', $task->due_date?->format('d/m/Y') ?? __('tasks.email.no_due_date')),
                    $line('priority', $task->priority->label()),
                ])),
                'url' => TaskResource::getUrl('index', ['tab' => TaskReminderAlert::tabFor($this->kind)], panel: 'admin'),
                'action' => __('tasks.reminder.email.action.'.$this->kindKey()),
                'office' => OfficeProfile::current()->legalName(),
            ],
        );
    }

    /** Hậu tố khoá câu chữ: `reminder.due_soon` → `due_soon`. */
    private function kindKey(): string
    {
        return str_replace('reminder.', '', $this->kind);
    }
}
