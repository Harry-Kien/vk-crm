<?php

namespace App\Notifications\Staff;

use App\Enums\TaskStatus;
use App\Filament\Admin\Resources\Tasks\Tables\TaskColumns;
use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Jobs\SendTaskNotice;
use App\Models\Task;
use Filament\Actions\Action;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;

/**
 * Chuông TRONG HỆ THỐNG của luồng giao việc nội bộ (làn tk, Task 1) — một lớp cho mọi loại lời báo
 * (hằng số dưới đây; làn fa1 thêm bảy loại: sửa, mở lại, người nhận tự mở lại, khôi phục, và ba lời báo người
 * giao gốc khi người khác giao lại / huỷ / khôi phục việc của họ). Chỉ {@see SendTaskNotice} gửi nó, SAU khi đã lọc người
 * nhận: đang hoạt động, còn xem được việc (`ResolveStaffRecipients::forTask()`; các loại
 * {@see self::ON_BEHALF_KINDS} qua `forTaskAssigner()`, cùng bộ lọc đó); riêng loại {@see self::REASSIGNED_AWAY}
 * chỉ cần đang hoạt động (`ResolveStaffRecipients::forFormerTaskHolder()`).
 *
 * Cùng khuôn `IntakeAssigned`/`NewClientRequestAlert`: kênh `database`, không `ShouldQueue` (một câu
 * INSERT, chạy trong job đã nằm sau commit), mảng đúng hình dạng Filament đọc được. Thêm một nút "Mở"
 * trỏ về đúng tab của trang "Công việc".
 *
 * **`e()` mọi giá trị nội suy.** Tiêu đề việc, lý do trả lại/huỷ là chữ người dùng gõ; Filament vẽ thân
 * thông báo qua `sanitizeHtml()`, vẫn giữ `<a href>`/`<img>`/`style` — không escape thì một tiêu đề
 * dàn dựng biến chuông thành một lớp phủ bấm được (cùng lý do `NewClientRequestAlert`).
 *
 * Không mang tên khách: chỉ tiêu đề việc và mã vụ — người nhận xem được vụ ở lúc báo.
 *
 * Ngoại lệ (vòng sửa 1, I1): {@see self::REASSIGNED_AWAY} báo người VỪA MẤT việc. Họ không còn xem được
 * việc đó (chỉ người nhận hiện tại xem được), và có thể đã rời đội ngũ của một vụ hạn chế — nên chuông của
 * họ chỉ là một câu chung kèm tên người giao lại: không tiêu đề việc, không mã vụ, không lý do. Nút "Mở" về
 * tab "Đang làm" (`active` — những việc họ còn giữ).
 *
 * # Người nhận thay người giao (làn tk, Task 2, vòng sửa 1 — I1)
 *
 * Lời "bị trả lại" — và từ làn fa1 cả "đã nhận", "đã hoàn thành", "người nhận tự mở lại" ({@see self::ON_BEHALF_KINDS})
 * — có thể tới luật sư phụ trách hiện tại thay người giao đã rời vụ hay nghỉ việc
 * (`ResolveStaffRecipients::forTaskAssigner()`). Khi đó `$onBehalfOf` mang tên người giao, và chuông dùng
 * câu của người nhận thay (`tasks.alert.declined_on_behalf`, `tasks.notice.<loại>_on_behalf` — "do :assigner
 * giao", không "việc anh/chị giao"), nút "Mở" về tab "Công việc" của vụ ({@see self::onBehalfUrl()}): việc đó không nằm ở "Tôi đã
 * giao" của họ (`Task::scopeAssignedBy()` theo `assigned_by`). Lời báo cho người nhận thay luôn là việc của
 * một vụ — việc chung không có người nhận thay.
 */
class TaskAlert extends Notification
{
    public const ASSIGNED = 'assigned';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    /** Việc người nhận đang giữ (chờ nhận hay đang làm) vừa được giao lại cho người khác — chỉ chuông. */
    public const REASSIGNED_AWAY = 'reassigned_away';

    // --- Làn fa1 (kiểm tra luồng nội bộ 2026-10-16) — câu chữ ở `tasks.notice.<loại>` ---------------------------

    /** Người giao / luật sư phụ trách / quản lý vừa sửa việc người nhận đang giữ — chuông + thư, nêu điều đã đổi. */
    public const UPDATED = 'updated';

    /** Việc người nhận đã hoàn thành được người khác "Mở lại" (yêu cầu làm lại) — chuông + thư kèm lý do. */
    public const REOPENED = 'reopened';

    /** Chính người nhận "Mở lại" việc mình vừa báo xong (bấm nhầm) — chuông cho người giao (hay người nhận thay). */
    public const REOPENED_BY_HOLDER = 'reopened_by_holder';

    /** Việc đã huỷ được "Khôi phục" về "Chờ nhận" cho người nhận cũ — chuông + thư kèm lý do. */
    public const RESTORED = 'restored';

    /** Luật sư phụ trách / quản lý giao lại việc NGƯỜI GIAO GỐC đã giao — chuông cho người giao gốc. */
    public const REASSIGNED_BY_OTHER = 'reassigned_by_other';

    /** Luật sư phụ trách / quản lý huỷ việc NGƯỜI GIAO GỐC đã giao — chuông cho người giao gốc, kèm lý do. */
    public const CANCELLED_BY_OTHER = 'cancelled_by_other';

    /**
     * Luật sư phụ trách / quản lý KHÔI PHỤC việc người giao gốc đã giao (thường sau một {@see self::CANCELLED_BY_OTHER})
     * — chuông cho người giao gốc, kèm lý do (làn fa1, vòng sửa 1, m7).
     */
    public const RESTORED_BY_OTHER = 'restored_by_other';

    /**
     * Các loại báo NGƯỜI GIAO đi qua `ResolveStaffRecipients::forTaskAssigner()` — người giao đã rời vụ hay nghỉ
     * thì luật sư phụ trách nhận thay, với câu `*_on_behalf` (làn fa1, phán quyết 7: thêm "đã nhận", "đã hoàn thành"
     * và "người nhận tự mở lại"). Chỉ "bị trả lại" còn rơi tiếp tới người quản lý của vụ — xem {@see self::INFO_ONLY_KINDS}.
     */
    public const ON_BEHALF_KINDS = [self::DECLINED, self::ACCEPTED, self::COMPLETED, self::REOPENED_BY_HOLDER];

    /**
     * Phần của {@see self::ON_BEHALF_KINDS} chỉ là TIN cho người giao, không cần ai quyết định (làn fa1, vòng sửa 1 —
     * rà soát Task 1, m5): `forTaskAssigner(decisionNeeded: false)` — người giao, hay luật sư phụ trách nhận thay, không
     * bao giờ mọi trưởng phòng.
     */
    public const INFO_ONLY_KINDS = [self::ACCEPTED, self::COMPLETED, self::REOPENED_BY_HOLDER];

    /**
     * @param  string  $kind  một trong các hằng số của lớp
     * @param  int  $eventId  id dòng nhật ký của lần chuyển — khoá chống trùng của job
     * @param  string|null  $onBehalfOf  tên người giao khi người nhận chuông nhận THAY họ (chỉ các loại
     *                                   {@see self::ON_BEHALF_KINDS} — xem docblock lớp); `null`: người nhận là người giao
     * @param  string|null  $reason  lý do của CHÍNH lần trả lại / huỷ / mở lại / khôi phục, đọc từ dòng nhật ký của
     *                               lần đó lúc job chạy (vòng sửa polish 1): một lần "Giao lại" trước khi job chạy
     *                               đã xoá `decline_reason` trên dòng việc; `null`: đọc cột của việc
     * @param  array<string, string>  $details  chữ thêm của lần đó, chưa escape (làn fa1): `changes` — các điều đã
     *                                          đổi của lần "Sửa" (`TaskChangeSummary`); `assignee` — tên người nhận mới
     */
    public function __construct(
        private readonly Task $task,
        private readonly string $kind,
        private readonly string $actorName,
        private readonly int $eventId,
        private readonly ?string $onBehalfOf = null,
        private readonly ?string $reason = null,
        private readonly array $details = [],
    ) {}

    /**
     * Gốc khoá câu chữ của `$kind` (đã cộng `_on_behalf` khi cần): loại có từ làn tk ở `tasks.alert.<loại>`, loại
     * làn fa1 thêm ở `tasks.notice.<loại>`. Một định nghĩa cho chuông và thư (`TaskNotice`).
     */
    public static function langKey(string $langKind): string
    {
        return Lang::has('tasks.notice.'.$langKind.'.title') ? 'tasks.notice.'.$langKind : 'tasks.alert.'.$langKind;
    }

    /** Lời báo này tới người nhận THAY người giao — một loại {@see self::ON_BEHALF_KINDS}, việc của một vụ, có tên người giao. */
    public static function isOnBehalf(string $kind, Task $task, ?string $onBehalfOf): bool
    {
        return $onBehalfOf !== null && in_array($kind, self::ON_BEHALF_KINDS, true) && $task->matter_id !== null;
    }

    /** Tab "Công việc" của vụ của `$task` — nơi người nhận thay người giao thấy việc đó (docblock lớp). */
    public static function onBehalfUrl(Task $task): string
    {
        return TaskColumns::matterTabUrl((int) $task->matter_id);
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $generic = $this->kind === self::REASSIGNED_AWAY;
        $onBehalf = self::isOnBehalf($this->kind, $this->task, $this->onBehalfOf);
        $lang = self::langKey($this->kind.($onBehalf ? '_on_behalf' : ''));
        $code = $generic ? null : $this->task->matter?->code;
        $warning = $generic || in_array($this->kind, [self::DECLINED, self::CANCELLED, self::REOPENED, self::CANCELLED_BY_OTHER], true);

        return [
            'actions' => [
                Action::make('open')
                    ->label(__('tasks.alert.open'))
                    ->url(match (true) {
                        $onBehalf => self::onBehalfUrl($this->task),
                        // Người vừa mất việc không còn xem được nó: chỉ tab, không id việc (docblock lớp).
                        $generic => TaskResource::getUrl('index', ['tab' => self::tabFor($this->kind, $this->task)], panel: 'admin'),
                        // Kiểm toán màn hình 2026-10-21 (mục 5): mở thẳng khung "Xem" của đúng việc, trên đúng tab.
                        default => TaskResource::taskUrl($this->task, self::tabFor($this->kind, $this->task)),
                    })
                    ->markAsRead()
                    ->toArray(),
            ],
            'body' => ($generic
                ? __('tasks.alert.reassigned_away.body', ['who' => e($this->actorName)])
                : __($lang.'.body', [
                    'title' => e($this->task->title),
                    'who' => e($this->actorName),
                    'reason' => e((string) ($this->reason ?? ($this->kind === self::CANCELLED ? $this->task->cancel_reason : $this->task->decline_reason))),
                    'assigner' => e((string) $this->onBehalfOf),
                    'changes' => e($this->details['changes'] ?? ''),
                    'assignee' => e($this->details['assignee'] ?? ''),
                ])).($code === null ? '' : ' '.__('tasks.alert.on_matter', ['code' => e($code)])),
            'color' => $warning ? 'warning' : 'info',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => $warning ? 'warning' : 'info',
            'title' => __($lang.'.title'),
            'view' => null,
            'viewData' => ['task_id' => $this->task->getKey(), 'task_event_id' => $this->eventId],
            'format' => 'filament',
        ];
    }

    /**
     * Tab của trang "Công việc" mà lời báo trỏ tới: việc giao cho tôi, việc tôi đã giao, hay — với người
     * vừa mất việc — những việc họ còn đang làm. Lời báo "đã sửa" (làn fa1) trỏ tab của TRẠNG THÁI việc (`$task`):
     * việc chưa nhận nằm ở "Chờ tôi nhận", việc đã nhận ở "Đang làm".
     */
    public static function tabFor(string $kind, ?Task $task = null): string
    {
        return match ($kind) {
            self::ASSIGNED, self::RESTORED => 'awaiting',
            self::UPDATED => $task?->status === TaskStatus::Pending ? 'awaiting' : 'active',
            self::REOPENED, self::REASSIGNED_AWAY => 'active',
            self::CANCELLED, self::CANCELLED_BY_OTHER => 'all',
            default => 'assigned',
        };
    }
}
