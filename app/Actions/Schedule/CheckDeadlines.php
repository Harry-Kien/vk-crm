<?php

namespace App\Actions\Schedule;

use App\Enums\DeadlineSeverity;
use App\Enums\Role;
use App\Mail\Staff\DeadlineReminder;
use App\Models\Deadline;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * SPEC §6.8 — nhắc mốc thời hạn tố tụng, chạy 07:00 hằng ngày.
 *
 * Đây là tác vụ mang rủi ro nghề nghiệp cao nhất trong hệ thống. Một mốc kháng cáo bị lỡ không
 * phải là bất tiện; nó là trách nhiệm nghề nghiệp của văn phòng. Vì vậy mọi quyết định dưới đây
 * nghiêng về phía "nói ra" chứ không nghiêng về phía "đừng làm phiền".
 *
 * BẬC NHẮC (SPEC §6.8): 7 ngày, 3 ngày, 1 ngày, và quá hạn. Mốc `critical` có thêm bậc 14 ngày.
 *
 * CHỌN BẬC NÀO KHI LỊCH ĐÃ CHẾT MẤY NGÀY — chỗ này SPEC không nói, và nó quyết định hệ thống có
 * ích hay không. Cron ngừng ba ngày rồi chạy lại: một mốc còn 2 ngày lẽ ra đã phải nhắc ở bậc 7.
 * Hai cách sai: gửi cả ba thư cùng lúc (người đọc học được rằng thư của hệ thống là rác), hoặc
 * im lặng vì "bậc 7 đã trôi qua" (đúng cái mà cả tác vụ này sinh ra để chống). Cách ở đây: gửi
 * ĐÚNG MỘT thư, ở bậc gần nhất còn ý nghĩa — tức bậc nhỏ nhất mà số ngày còn lại vẫn nằm trong —
 * rồi ĐÁNH DẤU mọi bậc đã trôi qua là đã gửi, để chúng không bắn ngược về sau.
 *
 * CHỐNG GỬI TRÙNG dùng cột `reminders_sent` mà SPEC §4.13 đã chỉ định sẵn, không dùng nhật ký
 * thư. Đây là ngoại lệ duy nhất của phán quyết R3 (nhật ký là trí nhớ chống trùng), và nó có lý
 * do: cột này là một phần của bản ghi mốc hạn, nên nó đi theo mốc hạn khi vụ việc được bàn giao
 * cho luật sư khác.
 *
 * "ĐÁNH DẤU QUÁ HẠN" của SPEC §6.8 không phải một cột: quá hạn là `due_date < today` và chưa
 * xong, tính lúc đọc. Thêm một cột `is_overdue` là tạo ra thứ có thể lệch với ngày tháng, và nó
 * sẽ lệch. Dấu vết của việc ĐÃ CẢNH BÁO nằm ở khoá `overdue` trong `reminders_sent`.
 */
class CheckDeadlines
{
    /** Bậc nhắc theo số ngày còn lại, từ xa tới gần. Bậc 14 chỉ áp cho mốc `critical`. */
    private const TIERS = [14, 7, 3, 1];

    public const OVERDUE_KEY = 'overdue';

    public function __invoke(): array
    {
        return $this->handle();
    }

    /**
     * @return array{reminded: int, skipped_tiers: int}
     */
    public function handle(): array
    {
        $reminded = 0;
        $skipped = 0;

        $candidates = Deadline::query()
            ->where('is_completed', false)
            ->orderBy('due_date')
            ->pluck('id');

        foreach ($candidates as $id) {
            DB::transaction(function () use ($id, &$reminded, &$skipped): void {
                /** @var Deadline|null $deadline */
                $deadline = Deadline::query()
                    ->whereKey($id)
                    ->lockForUpdate()
                    ->first();

                // Có thể đã xong hoặc đã bị rút trong lúc vòng lặp chạy. Khoá dòng rồi đọc lại
                // là cách duy nhất để hai tiến trình cron chồng nhau không gửi hai thư.
                if ($deadline === null || $deadline->is_completed) {
                    return;
                }

                $key = $this->tierFor($deadline);

                if ($key === null) {
                    return;
                }

                $already = $deadline->reminders_sent ?? [];

                // Đánh dấu những bậc đã trôi qua mà chưa gửi, để chúng không bắn ngược về sau.
                foreach ($this->passedTiers($deadline, $key) as $passed) {
                    if (! in_array($passed, $already, true)) {
                        $deadline->markReminderSent($passed);
                        $skipped++;
                    }
                }

                if (in_array($key, $already, true)) {
                    return;
                }

                $recipients = $this->recipientsFor($deadline, $key);

                if ($recipients->isEmpty()) {
                    // Không ai nhận được thì cũng không đánh dấu đã gửi: khi văn phòng bật lại
                    // tài khoản người phụ trách, lời nhắc phải còn nguyên chứ không biến mất.
                    return;
                }

                foreach ($recipients as $recipient) {
                    Mail::to($recipient->email)->send(new DeadlineReminder($deadline, $recipient, $key));
                }

                $deadline->markReminderSent($key);
                $reminded++;
            });
        }

        return ['reminded' => $reminded, 'skipped_tiers' => $skipped];
    }

    /** Bậc áp dụng hôm nay, hoặc `null` nếu còn quá xa để nhắc. */
    public function tierFor(Deadline $deadline): ?string
    {
        $daysLeft = (int) today()->diffInDays($deadline->due_date, false);

        if ($daysLeft < 0) {
            return self::OVERDUE_KEY;
        }

        foreach ($this->tiersFor($deadline) as $tier) {
            // Bậc nhỏ nhất mà số ngày còn lại vẫn nằm trong. Duyệt từ gần tới xa.
            if ($daysLeft <= $tier) {
                return 'd'.$tier;
            }
        }

        return null;
    }

    /** @return list<int> từ gần tới xa: [1, 3, 7] hoặc [1, 3, 7, 14] với mốc critical. */
    private function tiersFor(Deadline $deadline): array
    {
        $tiers = array_reverse(self::TIERS);

        return $deadline->severity === DeadlineSeverity::Critical
            ? $tiers
            : array_values(array_filter($tiers, fn (int $t): bool => $t !== 14));
    }

    /**
     * Các bậc XA HƠN bậc đang gửi — tức những bậc lẽ ra đã phải nhắc mà lịch đã bỏ lỡ.
     *
     * @return list<string>
     */
    private function passedTiers(Deadline $deadline, string $currentKey): array
    {
        $current = $currentKey === self::OVERDUE_KEY ? -1 : (int) mb_substr($currentKey, 1);

        $passed = [];

        foreach ($this->tiersFor($deadline) as $tier) {
            if ($current === -1 || $tier > $current) {
                $passed[] = 'd'.$tier;
            }
        }

        return $passed;
    }

    /**
     * SPEC §6.8, cột "Người nhận". Người phụ trách luôn có mặt; càng gần hạn thì càng nhiều
     * người biết, vì một lời nhắc chỉ gửi cho đúng người đang bận là một lời nhắc bị bỏ qua.
     *
     * @return Collection<int, User>
     */
    public function recipientsFor(Deadline $deadline, string $key): Collection
    {
        $people = collect();

        $responsible = $deadline->responsible;

        if ($responsible instanceof User) {
            $people->push($responsible);
        }

        if ($key === 'd3') {
            // Trợ lý trong đội ngũ của chính vụ việc này, không phải mọi trợ lý của văn phòng.
            $people = $people->merge(
                $deadline->matter?->team()->get()->filter(
                    fn (User $u): bool => $u->hasRole(Role::Assistant->value)
                ) ?? collect()
            );
        }

        if ($key === 'd1' || $key === self::OVERDUE_KEY) {
            $people = $people->merge(User::query()->role(Role::Manager->value)->get());
        }

        // Tài khoản đã khoá hoặc đã xoá không nhận thư: gửi cho một hộp thư không ai đọc là tự
        // dựng một bằng chứng sai rằng văn phòng đã được nhắc.
        return $people
            ->filter(fn (User $u): bool => $u->is_active && ! $u->trashed())
            ->unique(fn (User $u) => $u->getKey())
            ->values();
    }
}
