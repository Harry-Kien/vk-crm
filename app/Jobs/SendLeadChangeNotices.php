<?php

namespace App\Jobs;

use App\Actions\Matter\ReassignMatter;
use App\Actions\Matter\ReassignMatters;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\Confidentiality;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Models\Matter;
use App\Models\User;
use App\Notifications\Staff\LeadChangeAlert;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Spatie\Activitylog\Models\Activity;

/**
 * Lời báo "đổi luật sư phụ trách" của MỘT lần bàn giao — một vụ ({@see ReassignMatter}) hay cả lô
 * ({@see ReassignMatters}) — làn fa2, phán quyết 5. Xếp `->afterCommit()`; payload chỉ mang id: `$events` là
 * `matter_id => id dòng nhật ký matter_reassigned` của từng vụ đã bàn giao xong.
 *
 * # Ai nhận, đọc lại lúc chạy
 *
 *  - Người phụ trách CŨ (`from_user_id` của dòng nhật ký): còn hoạt động, chưa xoá, không phải người bấm —
 *    {@see LeadChangeAlert::HANDED_AWAY}. Mã hồ sơ hiện khi vụ KHÔNG hạn chế (họ vừa phụ trách nó nên đã biết mã —
 *    cùng luật {@see SendTeamDepartureNotices}) hay khi họ còn mở được hồ sơ (`ResolveStaffRecipients::qualifies()`
 *    — giữ lại làm cộng sự, hay có quyền xem mọi vụ); một vụ hạn chế họ không còn mở được chỉ được đếm. Nút "Mở"
 *    chỉ trỏ tới hồ sơ họ còn mở được.
 *  - Thành viên còn lại của đội ngũ LÚC CHẠY, trừ người phụ trách mới, người cũ và người bấm, và chỉ người còn
 *    mở được hồ sơ (`qualifies()`) — {@see LeadChangeAlert::LEAD_CHANGED}. Một vụ hạn chế không bao giờ tới
 *    thành viên không mở được nó.
 *  - Người phụ trách mới không qua đây: họ đã có chuông "được giao hồ sơ" và thư tổng hợp bàn giao.
 *
 * Vụ đã bị huỷ, hay đã đổi luật sư phụ trách lần nữa (lời báo của lần sau thay lời này), thì bỏ qua.
 *
 * # Một chuông mỗi người cho cả lô, chống trùng khi thử lại
 *
 * Gộp theo người nhận: một chuông cho mọi hồ sơ của lô. Gộp integ-fa (rà soát chéo, mục 13): lô có nhiều người phụ trách
 * cũ (thành viên ngồi ở hồ sơ của hai người khác nhau) — mỗi mã trong chuông `LEAD_CHANGED` kèm người phụ trách cũ của
 * chính hồ sơ đó (`internal_flow.lead_change.code_from`), câu không nêu một tên "từ" chung. Khoá `lead@<id các dòng nhật ký>` nằm ở
 * `viewData.lead_change_key`; chuông đã có khoá đó thì không gửi lại.
 */
class SendLeadChangeNotices implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @param  array<int, int>  $events  `matter_id => id dòng matter_reassigned` */
    public function __construct(
        public readonly array $events,
        public readonly int $actorId,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(ResolveStaffRecipients $recipients): void
    {
        $key = 'lead@'.implode('-', array_values($this->events));
        $actorName = (string) User::query()->withTrashed()->whereKey($this->actorId)->value('name');

        /** @var array<string, array{user: User, kind: string, codes: list<string>, froms: list<string>, count: int, from: string, to: string, url: ?string, opens: bool}> $notices */
        $notices = [];

        foreach ($this->events as $matterId => $eventId) {
            $matter = Matter::query()->withoutGlobalScope(ClientPortalScope::class)->with('team')->find($matterId);
            $event = Activity::query()->find($eventId);

            if ($matter === null || $event === null) {
                continue;
            }

            $fromId = (int) $event->getExtraProperty('from_user_id');
            $toId = (int) $event->getExtraProperty('to_user_id');

            if ($matter->lead_lawyer_id !== $toId) {
                continue;
            }

            $from = User::query()->withTrashed()->find($fromId);
            $toName = (string) User::query()->withTrashed()->whereKey($toId)->value('name');

            if ($from !== null && $from->is_active && ! $from->trashed() && $fromId !== $this->actorId) {
                $opens = $recipients->qualifies($from, $matter);

                $this->add($notices, $from, LeadChangeAlert::HANDED_AWAY, $matter, $opens || $matter->confidentiality !== Confidentiality::Restricted, $opens, '', $toName);
            }

            foreach ($matter->team as $member) {
                if (in_array($member->getKey(), [$fromId, $toId, $this->actorId], true) || ! $recipients->qualifies($member, $matter)) {
                    continue;
                }

                $this->add($notices, $member, LeadChangeAlert::LEAD_CHANGED, $matter, true, true, (string) $from?->name, $toName);
            }
        }

        foreach ($notices as $notice) {
            $user = $notice['user'];

            if ($user->notifications()->where('type', LeadChangeAlert::class)->where('data->viewData->lead_change_key', $key)->exists()) {
                continue;
            }

            // Gộp integ-fa (rà soát chéo, mục 13): lô có NHIỀU người phụ trách cũ — mỗi mã kèm người phụ trách cũ của
            // chính nó, không gán cho mọi hồ sơ tên người cũ của hồ sơ đầu tiên.
            $mixed = count(array_unique($notice['froms'])) > 1;
            $codes = $mixed
                ? array_map(fn (string $code, string $from): string => __('internal_flow.lead_change.code_from', ['code' => $code, 'from' => $from]), $notice['codes'], $notice['froms'])
                : $notice['codes'];

            $user->notify(new LeadChangeAlert(
                $notice['kind'],
                $codes,
                $notice['count'],
                $mixed ? '' : $notice['from'],
                $notice['to'],
                $actorName,
                $key,
                $notice['count'] === 1 ? $notice['url'] : null,
                $notice['opens'],
                mixedFrom: $mixed,
            ));
        }
    }

    /**
     * @param  array<string, array{user: User, kind: string, codes: list<string>, froms: list<string>, count: int, from: string, to: string, url: ?string, opens: bool}>  $notices
     * @param  bool  $named  mã hồ sơ được phép hiện với người này (docblock lớp)
     * @param  bool  $opens  người này còn mở được hồ sơ — nút "Mở" trỏ tới nó
     */
    private function add(array &$notices, User $user, string $kind, Matter $matter, bool $named, bool $opens, string $from, string $to): void
    {
        $slot = $user->getKey().'|'.$kind;
        $notices[$slot] ??= ['user' => $user, 'kind' => $kind, 'codes' => [], 'froms' => [], 'count' => 0, 'from' => $from, 'to' => $to, 'url' => null, 'opens' => true];
        $notices[$slot]['count']++;
        $notices[$slot]['opens'] = $notices[$slot]['opens'] && $opens;

        if ($named) {
            $notices[$slot]['codes'][] = (string) $matter->code;
            $notices[$slot]['froms'][] = $from;
        }

        if ($opens) {
            $notices[$slot]['url'] = ViewMatter::getUrl(['record' => $matter->getKey()], panel: 'admin');
        }
    }
}
