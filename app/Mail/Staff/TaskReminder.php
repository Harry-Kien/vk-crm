<?php

namespace App\Mail\Staff;

use App\Actions\Schedule\RemindPendingWork;
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
 * Thư của lịch nhắc VIỆC giao nội bộ (làn tk, Task 2) — sáu mẫu `staff.task.reminder.<loại>` (hằng
 * `KIND_*`, cùng giá trị các hằng loại của {@see RemindPendingWork}). Chỉ {@see SendWorkReminder} gửi, sau
 * khi đã lọc người nhận (còn xem được việc) và hỏi lại điều kiện nhắc lúc gửi.
 *
 * Họ mẫu `staff.task.*`: không gửi lại được (`ResendTargets`), không đẩy lên điện thoại. Tiêu đề thư không
 * mang tên việc hay mã vụ; thân thư mang tiêu đề việc, mã vụ, người nhận việc, hạn, mức ưu tiên — như
 * {@see TaskNotice}. Header `LEDGER_TIER` mang khoá của LẦN nhắc — một lượt nhắc chạy lại không gửi lần hai.
 *
 * Người nhận thay người giao (vòng sửa 1, I1): các loại báo người giao tới luật sư phụ trách hiện tại khi
 * người giao đã rời vụ hay nghỉ việc mang `$onBehalfOf` = tên người giao — tiêu đề, câu mở đầu, nút theo bộ
 * chữ `*_on_behalf`, thêm dòng "Người giao", đường dẫn về tab "Công việc" của vụ
 * (`TaskReminderAlert::urlFor()`). Cùng mẫu, cùng khoá sổ thư.
 *
 * Làn fa1 (phán quyết 5): `$needsReassignment` — người nhận việc không còn làm được việc này (việc hiện "Cần giao
 * lại") — thêm câu `tasks.reminder_needs_reassignment` vào câu mở đầu của các loại báo người giao, như chuông.
 *
 * Gộp (kiểm toán 2026-10-21, Q3 — `SendTaskReminderDigest`): `$batch` khác rỗng là MỘT thư cho nhiều việc của cùng một
 * người nhận trong một lượt — CÙNG mẫu; tiêu đề và câu mở đầu theo bộ chữ `tasks.reminder_digest.email` (số việc), mỗi
 * việc một dòng (`TaskReminderAlert::digestItem()`); dòng sổ thư gắn việc đầu, `LEDGER_TIER` mang khoá của từng việc.
 */
class TaskReminder extends BrandedMailable
{
    public const KIND_AWAITING = 'reminder.awaiting';

    public const KIND_UNACCEPTED = 'reminder.unaccepted';

    public const KIND_DUE_SOON = 'reminder.due_soon';

    public const KIND_OVERDUE = 'reminder.overdue';

    public const KIND_OVERDUE_ASSIGNER = 'reminder.overdue_assigner';

    /** Làn fa1, vòng sửa 1 (I1): sắp đến hạn mà người nhận không còn làm được — báo người giao. */
    public const KIND_DUE_SOON_ASSIGNER = 'reminder.due_soon_assigner';

    public const TEMPLATE_PREFIX = 'staff.task.';

    public function __construct(
        public Task $task,
        public string $kind,
        public User $recipient,
        public string $detail,
        public string $ledgerKey,
        public ?string $onBehalfOf = null,
        public bool $needsReassignment = false,
        /** @var list<array{title: string, code: ?string, due: ?string, assignee: string, detail: string, assigner: ?string, reassign: bool}> */
        public array $batch = [],
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
        return new Envelope(subject: $this->batch === []
            ? __('tasks.reminder.email.subject.'.$this->kindKey())
            : __('tasks.reminder_digest.email.subject.'.$this->digestKind(), ['count' => count($this->batch)]));
    }

    public function content(): Content
    {
        if ($this->batch !== []) {
            return $this->digestContent();
        }

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
                    'assigner' => (string) $this->onBehalfOf,
                ]).($this->needsReassignment && RemindPendingWork::isAssignerKind($this->kind)
                    ? ' '.__('tasks.reminder_needs_reassignment', ['who' => (string) $task->assignee?->name])
                    : ''),
                'details' => array_values(array_filter([
                    $line('title', $task->title),
                    $task->matter?->code === null ? null : $line('matter', $task->matter->code),
                    $this->onBehalf() ? $line('assigner', (string) $this->onBehalfOf) : null,
                    ['label' => __('tasks.reminder.email.assignee'), 'value' => (string) $task->assignee?->name],
                    $line('due_date', $task->due_date?->format('d/m/Y') ?? __('tasks.email.no_due_date')),
                    $line('priority', $task->priority->label()),
                ])),
                'url' => TaskReminderAlert::urlFor($this->kind, $task, $this->onBehalfOf),
                'action' => __('tasks.reminder.email.action.'.$this->kindKey()),
                'office' => OfficeProfile::current()->legalName(),
            ],
        );
    }

    /** Thư gộp — xem docblock lớp, đoạn "Gộp". */
    private function digestContent(): Content
    {
        return new Content(
            view: 'emails.staff.task-notice',
            text: 'emails.staff.task-notice-text',
            with: [
                'recipientName' => $this->recipient->name,
                'headline' => __('tasks.reminder_digest.email.headline.'.$this->digestKind(), ['count' => count($this->batch)]),
                'details' => array_map(fn (array $row): array => [
                    'label' => __('tasks.reminder_digest.email.item'),
                    'value' => TaskReminderAlert::digestItem($this->kind, $row),
                ], $this->batch),
                'url' => TaskReminderAlert::digestUrl($this->kind, $this->recipient, $this->batch),
                'action' => __('tasks.reminder_digest.email.action.'.$this->digestKind()),
                'office' => OfficeProfile::current()->legalName(),
            ],
        );
    }

    private function digestKind(): string
    {
        return str_replace('reminder.', '', $this->kind);
    }

    /** Thư tới người nhận THAY người giao — cùng định nghĩa của chuông (`TaskReminderAlert::onBehalf()`). */
    private function onBehalf(): bool
    {
        return TaskReminderAlert::onBehalf($this->kind, $this->task, $this->onBehalfOf);
    }

    /**
     * Hậu tố khoá câu chữ: `reminder.due_soon` → `due_soon`; cộng `_on_behalf` khi người nhận nhận thay người
     * giao (docblock lớp). Mẫu thư ({@see self::template()}) không đổi.
     */
    private function kindKey(): string
    {
        return str_replace('reminder.', '', $this->kind).($this->onBehalf() ? '_on_behalf' : '');
    }
}
