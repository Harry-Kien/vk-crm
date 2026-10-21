<?php

namespace App\Notifications\Staff;

use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Jobs\SendLeadChangeNotices;
use Filament\Actions\Action;
use Illuminate\Notifications\Notification;

/**
 * Chuông "đổi luật sư phụ trách" (làn fa2, phán quyết 5) — chỉ {@see SendLeadChangeNotices} gửi, sau commit
 * của lần bàn giao (một vụ hay cả lô), MỘT chuông mỗi người cho cả lô. Hai loại:
 *
 *  - {@see self::HANDED_AWAY}: gửi người phụ trách CŨ — "hồ sơ … đã được giao cho X". Mã chỉ có ở những hồ sơ job
 *    cho phép (`$codes`): một vụ hạn chế người đó không còn mở được không bao giờ có mã — chỉ được đếm ("và n hồ sơ
 *    khác" / câu chung). Người đó đã rời đội ngũ (`$opens` sai) thì câu nói thêm việc nội bộ của họ cũng đã chuyển.
 *  - {@see self::LEAD_CHANGED}: gửi thành viên còn lại của đội — "hồ sơ … đổi luật sư phụ trách: A → B". Chỉ
 *    người còn mở được hồ sơ (job lọc qua `ResolveStaffRecipients::qualifies()`), nên mã luôn được phép hiện.
 *
 * Cùng khuôn {@see MatterAssignmentAlert}: kênh `database`, không `ShouldQueue`, `e()` mọi giá trị nội suy.
 * Không tên khách, không tiêu đề hồ sơ. `viewData.lead_change_key` là khoá chống trùng của job.
 */
class LeadChangeAlert extends Notification
{
    public const HANDED_AWAY = 'handed_away';

    public const LEAD_CHANGED = 'lead_changed';

    /** Số mã hồ sơ in trong một chuông; còn lại là "…". */
    public const CODES_SHOWN = 10;

    /**
     * @param  list<string>  $codes  mã các hồ sơ người nhận còn mở được (có thể rỗng với {@see self::HANDED_AWAY})
     * @param  int  $count  tổng số hồ sơ của lời báo (kể cả hồ sơ không in mã)
     * @param  string  $from  tên luật sư phụ trách cũ (chỉ {@see self::LEAD_CHANGED})
     * @param  string  $to  tên luật sư phụ trách mới
     * @param  string|null  $url  hồ sơ duy nhất người nhận còn mở được; `null`: danh sách hồ sơ
     * @param  bool  $opens  người nhận còn mở được MỌI hồ sơ của lời báo — sai thì câu nói họ đã rời đội ngũ và
     *                       việc nội bộ của họ trên đó cũng đã chuyển
     * @param  bool  $mixedFrom  gộp integ-fa (rà soát chéo, mục 13): các hồ sơ có người phụ trách cũ KHÁC nhau — `$codes`
     *                           đã mang tên người cũ của từng hồ sơ, câu {@see self::LEAD_CHANGED} không nêu `$from` chung
     */
    public function __construct(
        private readonly string $kind,
        private readonly array $codes,
        private readonly int $count,
        private readonly string $from,
        private readonly string $to,
        private readonly string $actorName,
        private readonly string $key,
        private readonly ?string $url = null,
        private readonly bool $opens = true,
        private readonly bool $mixedFrom = false,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $shown = array_slice($this->codes, 0, self::CODES_SHOWN);
        $codes = e(implode(', ', $shown)).(count($this->codes) > count($shown) ? ', …' : '');
        $unnamed = $this->count - count($this->codes);

        if ($this->count > 1 && $this->codes !== [] && $unnamed > 0) {
            $codes .= __('internal_flow.lead_change.and_others', ['count' => $unnamed]);
        }

        $variant = match (true) {
            $this->count > 1 && $this->codes === [] => 'many_hidden',
            $this->count > 1 && $this->mixedFrom && $this->kind === self::LEAD_CHANGED => 'many_mixed',
            $this->count > 1 => $this->opens ? 'many' : 'many_left',
            $this->codes === [] => 'hidden',
            default => $this->opens ? 'one' : 'one_left',
        };

        return [
            'actions' => [
                Action::make('open')
                    ->label(__('internal_flow.lead_change.open'))
                    ->url($this->url ?? MatterResource::getUrl('index', panel: 'admin'))
                    ->markAsRead()
                    ->toArray(),
            ],
            'body' => __("internal_flow.lead_change.{$this->kind}.body_{$variant}", [
                'code' => $codes,
                'codes' => $codes,
                'count' => $this->count,
                'from' => e($this->from),
                'to' => e($this->to),
                'who' => e($this->actorName),
            ]),
            'color' => 'info',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => 'info',
            'title' => __("internal_flow.lead_change.{$this->kind}.title"),
            'view' => null,
            'viewData' => ['lead_change_key' => $this->key],
            'format' => 'filament',
        ];
    }
}
