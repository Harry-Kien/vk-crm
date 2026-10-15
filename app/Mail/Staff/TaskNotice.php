<?php

namespace App\Mail\Staff;

use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Jobs\SendTaskNotice;
use App\Mail\BrandedMailable;
use App\Mail\OutboundHeaders;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskAlert;
use App\Support\OfficeProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Thư của luồng giao việc nội bộ (làn tk, Task 1) — bốn mẫu `staff.task.<loại>` (hằng `KIND_*`): người
 * nhận được giao việc; người giao được báo việc đã nhận, bị trả lại (kèm lý do), hoàn thành. Chỉ
 * {@see SendTaskNotice} gửi, sau commit và sau khi đã lọc người nhận.
 *
 * **Tiêu đề thư không mang tên việc hay mã vụ** (dòng tiêu đề hiện trên màn hình khoá điện thoại); thân
 * thư mang tiêu đề việc, mã vụ (nếu có), người giao/người nhận, hạn, mức ưu tiên, và lý do/ghi chú —
 * người nhận xem được việc đó ở lúc gửi. Không tên khách, không mô tả nội bộ.
 *
 * Bản ghi liên quan là việc (`task`): dòng nhật ký thư chỉ quản trị viên thấy (`OutboundMessage::
 * scopeVisibleTo()` không mở loại này cho ai khác). Header `LEDGER_TIER` mang khoá của LẦN báo
 * ({@see self::ledgerKey()}) — một việc có thể được giao lại, trả lại nhiều lần, mỗi lần một thư; job
 * so khoá này để một lần thử lại không gửi lần hai (cùng thiết bị `DocumentRejected::ledgerKeyFor()`).
 *
 * Người nhận thay người giao (làn tk, Task 2, vòng sửa 1 — I1): thư "bị trả lại" tới luật sư phụ trách hiện
 * tại khi người giao đã rời vụ hay nghỉ việc mang `$onBehalfOf` = tên người giao — tiêu đề, câu mở đầu và
 * nút theo bộ chữ `*.declined_on_behalf`, thêm dòng "Người giao", và đường dẫn về tab "Công việc" của vụ
 * (`TaskAlert::onBehalfUrl()`) thay vì "Tôi đã giao". Cùng mẫu `staff.task.declined`, cùng khoá sổ thư.
 */
class TaskNotice extends BrandedMailable
{
    public const KIND_ASSIGNED = 'assigned';

    public const KIND_ACCEPTED = 'accepted';

    public const KIND_DECLINED = 'declined';

    public const KIND_COMPLETED = 'completed';

    public const TEMPLATE_PREFIX = 'staff.task.';

    public function __construct(
        public Task $task,
        public string $kind,
        public User $recipient,
        public string $actorName,
        public int $eventId,
        public ?string $onBehalfOf = null,
    ) {}

    public static function ledgerKey(int $eventId): string
    {
        return 'event@'.$eventId;
    }

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
        return [OutboundHeaders::LEDGER_TIER => self::ledgerKey($this->eventId)];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('tasks.email.subject.'.$this->langKind(), ['name' => $this->actorName]),
        );
    }

    public function content(): Content
    {
        $office = OfficeProfile::current();

        return new Content(
            view: 'emails.staff.task-notice',
            text: 'emails.staff.task-notice-text',
            with: [
                'recipientName' => $this->recipient->name,
                'headline' => __('tasks.email.headline.'.$this->langKind(), ['name' => $this->actorName, 'assigner' => (string) $this->onBehalfOf]),
                'details' => $this->details(),
                'url' => $this->onBehalf()
                    ? TaskAlert::onBehalfUrl($this->task)
                    : TaskResource::getUrl('index', ['tab' => TaskAlert::tabFor($this->kind)], panel: 'admin'),
                'action' => __('tasks.email.action.'.$this->langKind()),
                'office' => $office->legalName(),
            ],
        );
    }

    /** Thư này tới người nhận THAY người giao (docblock lớp) — chỉ loại "bị trả lại", chỉ việc của một vụ. */
    private function onBehalf(): bool
    {
        return $this->onBehalfOf !== null && $this->kind === self::KIND_DECLINED && $this->task->matter_id !== null;
    }

    /** Hậu tố khoá câu chữ: loại thư, cộng `_on_behalf` khi người nhận nhận thay người giao. */
    private function langKind(): string
    {
        return $this->kind.($this->onBehalf() ? '_on_behalf' : '');
    }

    /**
     * Các dòng "nhãn: giá trị" của thân thư, theo thứ tự in.
     *
     * @return list<array{label: string, value: string}>
     */
    private function details(): array
    {
        $task = $this->task;
        $line = fn (string $key, string $value): array => ['label' => __('tasks.email.fields.'.$key), 'value' => $value];

        return array_values(array_filter([
            $line('title', $task->title),
            $task->matter?->code === null ? null : $line('matter', $task->matter->code),
            $this->onBehalf() ? $line('assigner', (string) $this->onBehalfOf) : null,
            $line('due_date', $task->due_date?->format('d/m/Y') ?? __('tasks.email.no_due_date')),
            $line('priority', $task->priority->label()),
            $this->kind === self::KIND_DECLINED ? $line('reason', (string) $task->decline_reason) : null,
            $this->kind === self::KIND_COMPLETED && filled($task->completion_note) ? $line('note', (string) $task->completion_note) : null,
        ]));
    }
}
