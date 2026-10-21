<?php

namespace App\Jobs;

use App\Actions\Deadline\ChangeDeadlineResponsible;
use App\Actions\Deadline\UpdateDeadline;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use App\Notifications\Staff\DeadlineHandedAwayAlert;
use App\Support\ActivityPeople;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Spatie\Activitylog\Models\Activity;

/**
 * "Mốc anh/chị phụ trách đã chuyển cho X" (gộp integ-fa, rà soát chéo 2026-10-18, mục 16) — chuông tin cho người giữ mốc
 * CŨ khi người khác đổi người phụ trách ({@see ChangeDeadlineResponsible}, {@see UpdateDeadline}). Hai Action xếp job
 * này `->afterCommit()` khi người cũ không phải người bấm; payload chỉ mang id (mốc, người cũ, dòng
 * `deadline_responsible_changed`).
 *
 * Đọc lại lúc chạy: mốc còn, người phụ trách HIỆN TẠI không còn là người cũ (mốc đã quay về họ thì không báo), người cũ
 * còn hoạt động, chưa xoá. Tên mốc, mã hồ sơ và tên người nhận chỉ khi người cũ còn mở được hồ sơ
 * (`ResolveStaffRecipients::qualifies()`); không thì câu chung. Khoá `event@<id dòng nhật ký>` chống trùng.
 */
class SendDeadlineHandedAwayNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $deadlineId,
        public readonly int $formerHolderId,
        public readonly int $eventId,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(ResolveStaffRecipients $recipients): void
    {
        $deadline = Deadline::query()->withoutGlobalScope(ClientPortalScope::class)->find($this->deadlineId);
        $former = User::query()->where('is_active', true)->find($this->formerHolderId);
        $event = Activity::query()->find($this->eventId);

        if ($deadline === null || $former === null || $event === null || $deadline->responsible_user_id === $former->getKey()) {
            return;
        }

        $matter = Matter::query()->withoutGlobalScope(ClientPortalScope::class)->find($deadline->matter_id);

        if ($matter === null) {
            return;
        }

        $key = 'event@'.$this->eventId;

        if ($former->notifications()->where('type', DeadlineHandedAwayAlert::class)->where('data->viewData->deadline_handed_away_key', $key)->exists()) {
            return;
        }

        $former->notify(new DeadlineHandedAwayAlert(
            (int) $matter->getKey(),
            $recipients->qualifies($former, $matter),
            (string) $deadline->name,
            (string) $matter->code,
            (string) User::query()->withTrashed()->whereKey($deadline->responsible_user_id)->value('name'),
            ActivityPeople::name($event->causer) ?? '',
            $key,
        ));
    }
}
