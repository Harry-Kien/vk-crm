<?php

namespace App\Notifications\Staff;

use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Jobs\SendStaffWorkTransferNotices;
use Filament\Actions\Action;
use Illuminate\Notifications\Notification;

/**
 * MỘT chuông cho cả lô "Chuyển việc nội bộ và ghế" (làn fa2, Task 1, vòng sửa 1 — I2) — chỉ
 * {@see SendStaffWorkTransferNotices} gửi. Hai loại:
 *
 *  - {@see self::RECEIVED}: người NHẬN việc — ai đã chuyển, việc của ai, trên những hồ sơ nào, bao nhiêu việc nội bộ,
 *    việc đã giao, mốc hạn, yêu cầu của khách, ghế mới. Nút "Mở" về tab "Chờ tôi nhận" của trang "Công việc".
 *  - {@see self::HANDED_OVER}: người CŨ — việc của họ đã sang tay ai, họ đã rời đội ngũ các hồ sơ đó. Nút "Mở" về tab
 *    "Đang làm" (những việc họ còn giữ), cùng chỗ của `TeamDepartureAlert::MEMBER_LEFT`.
 *  - {@see self::ASSIGNER} (gộp integ-fa): người giao (hay người nhận thay họ) của các việc đã chuyển — bao nhiêu việc
 *    họ theo dõi đã sang tay ai, trên những hồ sơ nào. Nút "Mở" về tab "Tất cả" của trang "Công việc" (người nhận thay
 *    người giao không có các việc đó ở "Tôi đã giao").
 *
 * Câu: lô chỉ có việc chung (không hồ sơ nào) → `body_general`; người cũ chỉ bị gỡ ghế, không việc nào khác →
 * `body_seats_only`; người giao có cả việc trên hồ sơ lẫn việc chung (`$general`) → `body_mixed`; còn lại `body`. Chỉ các
 * phần khác 0 được nêu ({@see self::itemsPhrase()}). Chuông của người NHẬN thêm, sau câu, một dòng mỗi mốc hạn đã sang
 * tay họ (`$deadlineLines`, job dựng — gộp integ-fa), mỗi dòng qua `e()`, nối bằng `<br>`.
 *
 * `$codes`: chỉ mã hồ sơ job cho phép hiện với người nhận chuông (vụ hạn chế họ không mở được không có mặt);
 * `$hidden`: số hồ sơ còn lại, chỉ đếm. Không tiêu đề việc, không tiêu đề hồ sơ, không tên khách. Kênh `database`,
 * không `ShouldQueue`, `e()` mọi giá trị nội suy. `viewData.staff_work_key` là khoá chống trùng của job.
 */
class StaffWorkTransferAlert extends Notification
{
    public const RECEIVED = 'received';

    public const HANDED_OVER = 'handed_over';

    public const ASSIGNER = 'assigner';

    /**
     * @param  list<string>  $codes
     * @param  array{tasks: int, followed: int, deadlines: int, requests: int, seats: int}  $counts
     * @param  list<string>  $deadlineLines  gộp integ-fa: chữ thô, một dòng mỗi mốc (chỉ chuông người nhận)
     * @param  bool  $general  gộp integ-fa: người giao có cả việc chung trong lô (chỉ {@see self::ASSIGNER})
     */
    public function __construct(
        private readonly string $kind,
        private readonly string $actorName,
        private readonly string $fromName,
        private readonly string $toName,
        private readonly array $codes,
        private readonly int $hidden,
        private readonly array $counts,
        private readonly string $key,
        private readonly array $deadlineLines = [],
        private readonly bool $general = false,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * "2 hồ sơ (VK-…, VK-…)", "3 hồ sơ (VK-… và 2 hồ sơ khác)", "1 hồ sơ" — một định nghĩa cho chuông và thư;
     * `null` khi lô chỉ có việc chung (không thuộc hồ sơ nào).
     *
     * @param  list<string>  $codes  đã escape nếu dùng trong chuông
     */
    public static function mattersPhrase(array $codes, int $hidden): ?string
    {
        $count = count($codes) + $hidden;

        return match (true) {
            $count === 0 => null,
            $codes === [] => __('internal_flow.staff_work.digest.matters_unnamed', ['count' => $count]),
            $hidden === 0 => __('internal_flow.staff_work.digest.matters', ['count' => $count, 'codes' => implode(', ', $codes)]),
            default => __('internal_flow.staff_work.digest.matters_with_hidden', ['count' => $count, 'codes' => implode(', ', $codes), 'hidden' => $hidden]),
        };
    }

    /**
     * "2 việc nội bộ, 4 mốc hạn, 1 yêu cầu của khách" — chỉ những phần khác 0, theo thứ tự cố định.
     *
     * @param  array{tasks: int, followed: int, deadlines: int, requests: int, seats: int}  $counts
     * @param  list<string>  $parts  khoá của `$counts` cần nêu
     */
    public static function itemsPhrase(array $counts, array $parts): string
    {
        return collect($parts)
            ->filter(fn (string $part): bool => ($counts[$part] ?? 0) > 0)
            ->map(fn (string $part): string => __("internal_flow.staff_work.digest.{$part}", ['count' => $counts[$part]]))
            ->implode(', ');
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $received = $this->kind === self::RECEIVED;
        $matters = self::mattersPhrase(array_map(e(...), $this->codes), $this->hidden);
        $items = self::itemsPhrase($this->counts, match ($this->kind) {
            self::RECEIVED => ['tasks', 'followed', 'deadlines', 'requests', 'seats'],
            self::ASSIGNER => ['tasks'],
            default => ['tasks', 'followed', 'deadlines', 'requests'],
        });
        $tab = match ($this->kind) {
            self::RECEIVED => 'awaiting',
            self::ASSIGNER => 'all',
            default => 'active',
        };
        $deadlines = $this->deadlineLines === []
            ? ''
            : '<br>'.e(__('internal_flow.staff_work.digest.deadlines_heading')).'<br>'.implode('<br>', array_map(e(...), $this->deadlineLines));

        return [
            'actions' => [
                Action::make('open')
                    ->label(__('internal_flow.staff_work.digest.open'))
                    ->url(TaskResource::getUrl('index', ['tab' => $tab], panel: 'admin'))
                    ->markAsRead()
                    ->toArray(),
            ],
            'body' => __("internal_flow.staff_work.digest.{$this->kind}.".match (true) {
                $matters === null => 'body_general',
                $items === '' => 'body_seats_only',
                $this->general => 'body_mixed',
                default => 'body',
            }, [
                'who' => e($this->actorName),
                'from' => e($this->fromName),
                'to' => e($this->toName),
                'matters' => $matters ?? '',
                'items' => $items,
            ]).$deadlines,
            'color' => 'info',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => 'info',
            'title' => __("internal_flow.staff_work.digest.{$this->kind}.title"),
            'view' => null,
            'viewData' => ['staff_work_key' => $this->key],
            'format' => 'filament',
        ];
    }
}
