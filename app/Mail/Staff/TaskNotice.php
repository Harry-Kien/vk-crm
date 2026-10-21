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
use App\Support\Tasks\StandIn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Lang;

/**
 * Thư của luồng giao việc nội bộ (làn tk, Task 1) — các mẫu `staff.task.<loại>` (hằng `KIND_*`): người
 * nhận được giao việc; người giao được báo việc đã nhận, bị trả lại (kèm lý do), hoàn thành. Làn fa1 (kiểm
 * tra luồng nội bộ 2026-10-16) thêm ba mẫu cho người ĐANG GIỮ việc: việc vừa được sửa (`updated` — dòng "Điều
 * đã đổi"), việc đã hoàn thành được mở lại để làm tiếp (`reopened` — kèm lý do), việc đã huỷ được khôi phục cho
 * họ (`restored` — kèm lý do). Chỉ {@see SendTaskNotice} gửi, sau commit và sau khi đã lọc người nhận.
 *
 * **Tiêu đề thư không mang tên việc hay mã vụ** (dòng tiêu đề hiện trên màn hình khoá điện thoại); thân
 * thư mang tiêu đề việc, mã vụ (nếu có), người giao/người nhận, hạn, mức ưu tiên, và lý do/ghi chú/điều đã
 * đổi — người nhận xem được việc đó ở lúc gửi. Không tên khách, không mô tả nội bộ (lời "đã sửa" chỉ nói mô tả
 * đã sửa, không chép nội dung).
 *
 * Bản ghi liên quan là việc (`task`): dòng nhật ký thư chỉ quản trị viên thấy (`OutboundMessage::
 * scopeVisibleTo()` không mở loại này cho ai khác). Header `LEDGER_TIER` mang khoá của LẦN báo
 * ({@see self::ledgerKey()}) — một việc có thể được giao lại, trả lại nhiều lần, mỗi lần một thư; job
 * so khoá này để một lần thử lại không gửi lần hai (cùng thiết bị `DocumentRejected::ledgerKeyFor()`).
 *
 * Người nhận thay người giao (làn tk, Task 2, vòng sửa 1 — I1; làn fa1 thêm "đã nhận", "đã hoàn thành"): thư
 * báo người giao tới luật sư phụ trách hiện tại khi người giao đã rời vụ hay nghỉ việc mang `$onBehalfOf` = tên
 * người giao — tiêu đề, câu mở đầu và nút theo bộ chữ `*_on_behalf`, thêm dòng "Người giao", và đường dẫn về tab
 * "Công việc" của vụ (`TaskAlert::onBehalfUrl()`) thay vì "Tôi đã giao". Cùng mẫu, cùng khoá sổ thư.
 *
 * `$reason` (vòng sửa polish 1): lý do của CHÍNH lần trả lại / mở lại / khôi phục, đọc từ dòng nhật ký của lần đó
 * (`SendTaskNotice`) — một lần "Giao lại" trước khi thư đi đã xoá `decline_reason` trên dòng việc. `null`: đọc
 * cột của việc. `$extra['changes']`: điều đã đổi của lần "Sửa" (`TaskChangeSummary`).
 */
class TaskNotice extends BrandedMailable
{
    public const KIND_ASSIGNED = 'assigned';

    public const KIND_ACCEPTED = 'accepted';

    public const KIND_DECLINED = 'declined';

    public const KIND_COMPLETED = 'completed';

    public const KIND_UPDATED = 'updated';

    public const KIND_REOPENED = 'reopened';

    public const KIND_RESTORED = 'restored';

    public const TEMPLATE_PREFIX = 'staff.task.';

    /**
     * @param  array<string, string>  $extra  xem docblock lớp
     */
    public function __construct(
        public Task $task,
        public string $kind,
        public User $recipient,
        public string $actorName,
        public int $eventId,
        public ?string $onBehalfOf = null,
        public ?string $reason = null,
        public array $extra = [],
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
            subject: __($this->langKey('subject'), ['name' => $this->actorName]),
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
                'headline' => __($this->langKey('headline'), [
                    'name' => $this->actorName,
                    'assigner' => (string) $this->onBehalfOf,
                    'code' => (string) $this->task->matter?->code,
                ]),
                'details' => $this->details(),
                'url' => $this->standIn() !== null
                    ? StandIn::url($this->task, (string) $this->standIn())
                    : TaskResource::getUrl('index', ['tab' => TaskAlert::tabFor($this->kind, $this->task)], panel: 'admin'),
                'action' => __($this->langKey('action')),
                'office' => $office->legalName(),
            ],
        );
    }

    /** Vai người nhận thay (kiểm toán 2026-10-21, N1/A7) — cùng định nghĩa của chuông (`TaskAlert::standInRole()`). */
    private function standIn(): ?string
    {
        return TaskAlert::standInRole($this->kind, $this->task, $this->onBehalfOf, $this->recipient);
    }

    /**
     * Khoá câu chữ của phần `$part` (`subject`, `headline`, `action`): loại thư, cộng `_on_behalf` khi người nhận
     * nhận thay người giao; loại có từ làn tk ở `tasks.email.<phần>.<loại>`, loại làn fa1 ở
     * `tasks.notice_email.<phần>.<loại>`.
     */
    private function langKey(string $part): string
    {
        $managed = StandIn::managedKey('email.'.$part, $this->kind, $this->standIn());

        if ($managed !== null) {
            return $managed;
        }

        $kind = $this->kind.($this->standIn() !== null ? '_on_behalf' : '');
        $added = 'tasks.notice_email.'.$part.'.'.$kind;

        return Lang::has($added) ? $added : 'tasks.email.'.$part.'.'.$kind;
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
        $reasoned = in_array($this->kind, [self::KIND_REOPENED, self::KIND_RESTORED], true);

        return array_values(array_filter([
            $line('title', $task->title),
            $task->matter?->code === null ? null : $line('matter', $task->matter->code),
            $this->standIn() !== null ? $line('assigner', (string) $this->onBehalfOf) : null,
            $this->kind === self::KIND_UPDATED && filled($this->extra['changes'] ?? null)
                ? ['label' => __('tasks.notice_email.fields.changes'), 'value' => (string) $this->extra['changes']]
                : null,
            $line('due_date', $task->due_date?->format('d/m/Y') ?? __('tasks.email.no_due_date')),
            $line('priority', $task->priority->label()),
            $this->kind === self::KIND_DECLINED ? $line('reason', (string) ($this->reason ?? $task->decline_reason)) : null,
            $reasoned && filled($this->reason) ? ['label' => __('tasks.notice_email.fields.reason'), 'value' => (string) $this->reason] : null,
            $this->kind === self::KIND_COMPLETED && filled($task->completion_note) ? $line('note', (string) $task->completion_note) : null,
        ]));
    }
}
