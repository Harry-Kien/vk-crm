<?php

namespace App\Actions\Schedule;

use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\Confidentiality;
use App\Enums\DeadlineSeverity;
use App\Enums\Role;
use App\Jobs\SendDeadlineReminderMail;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

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
 *
 * # M6.5 Task 11 (`deadlines/F1`, `notify/notify-2`, `e2e/F3`) — thư ra khỏi transaction
 *
 * Trước Task 11, `Mail::to()->send()` chạy ĐỒNG BỘ ngay trong `DB::transaction()` của từng mốc.
 * Một transport hỏng ném `TransportException` xuyên qua transaction: dòng `outbound_messages` vừa
 * ghi (kể cả dòng `sent` của thư đã thật sự tới người trước đó trong cùng mốc) bị ROLLBACK theo,
 * và ngoại lệ thoát khỏi `foreach` nên mọi mốc xếp sau (gấp hơn, vì `orderBy('due_date')`) không
 * bao giờ được xét trong lượt chạy đó — đúng phán quyết R2 bị vi phạm ("mọi thư qua hàng đợi, sau
 * khi commit, không bao giờ nằm trong transaction").
 *
 * Bây giờ: transaction của từng mốc CHỈ khoá dòng, tính bậc, và ghi `reminders_sent` — không có gì
 * gọi ra mạng bên trong nó. Việc gửi thư thật được giao cho {@see SendDeadlineReminderMail},
 * dispatch bằng `->afterCommit()` NGAY TRONG transaction (Laravel hoãn việc đẩy job tới khi
 * transaction ngoài cùng thật sự commit — cùng cơ chế `ShouldDispatchAfterCommit` mà
 * `StageLogPublished` dùng). Job chỉ nhận ID, tự đọc lại mốc/người nhận lúc nó THẬT SỰ chạy — xem
 * docblock của job để biết vì sao (`reminders_sent` đánh dấu trước, vụ việc có thể đã huỷ giữa
 * chừng).
 *
 * `handle()` bọc mỗi `DB::transaction()` của một mốc trong `try`/`catch` riêng: dưới hàng đợi
 * `sync` (mặc định của bộ test), job vừa dispatch chạy ĐỒNG BỘ ngay khi transaction commit, nên
 * một transport hỏng vẫn có thể ném ngược lên tới đây. Bọc riêng từng mốc là cách duy nhất giữ
 * đúng "mỗi mốc độc lập, lỗi ở một mốc không dừng vòng lặp" bất kể hàng đợi nào đang chạy — dưới
 * hàng đợi `database` thật (production), dispatch chỉ là một câu INSERT nhanh và sẽ không bao giờ
 * ném vì lý do mạng, nhưng `try`/`catch` không hại gì khi đó, chỉ là không có gì để bắt.
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
            // `deadlines/F8` + fix round 1, finding S1 (M6.5 Task 5): vụ việc đã xoá mềm HOẶC đã
            // đóng (`closed_at` có giá trị, qua `TransitionMatterStage` vào giai đoạn
            // `is_terminal` — R8) không còn "việc dở dang" theo đúng định nghĩa mà `OpenWork`
            // dùng cho nghỉ việc/gỡ thành viên — CheckDeadlines phải đồng ý với chúng.
            // `whereHas('matter', fn ($q) => $q->open())` gọi ĐÚNG MỘT định nghĩa
            // `Matter::scopeOpen()` (SoftDeletingScope + closed_at null), không viết lại nó lần
            // nữa. Bản trước chỉ `whereHas('matter')` — loại được vụ xoá mềm nhưng bỏ sót vụ đã
            // đóng: một mốc của vụ ĐÃ ĐÓNG (không xoá mềm) vẫn bị nhắc mãi, và
            // `SetDeadlineCompletion` cũng chặn vụ đã đóng cùng cách nó chặn vụ trashed
            // (`MatterPolicy::update`), nên mốc đó không đánh dấu xong được — cùng cái bẫy F8,
            // khác đường vào.
            ->whereHas('matter', fn ($query) => $query->open())
            ->orderBy('due_date')
            ->pluck('id');

        foreach ($candidates as $id) {
            try {
                $this->processOne($id, $reminded, $skipped);
            } catch (Throwable $e) {
                // "Mỗi mốc độc lập, lỗi ở một mốc không dừng vòng lặp" (brief Task 11): dưới hàng
                // đợi `sync` của bộ test, `SendDeadlineReminderMail::dispatch()->afterCommit()`
                // chạy ĐỒNG BỘ ngay khi transaction của DÒNG NÀY commit — một transport hỏng có
                // thể ném ngược lên tới đây. Bắt và tiếp tục, thay vì để nó phá vòng `foreach` như
                // trước Task 11. `report()` để lỗi không biến mất hoàn toàn khỏi `laravel.log`,
                // dù trên shared hosting (SPEC §2) không ai đọc file đó thường xuyên — bằng chứng
                // thật của một thư hỏng vẫn nằm ở dòng `outbound_messages` do job ghi, không phải
                // ở đây.
                report($e);
            }
        }

        return ['reminded' => $reminded, 'skipped_tiers' => $skipped];
    }

    /** Một mốc, một transaction — tách ra khỏi {@see self::handle()} để foreach bắt lỗi gọn. */
    private function processOne(int $id, int &$reminded, int &$skipped): void
    {
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

            // Fix round 1, finding S1 (phần thứ hai): tập ứng viên được dựng TRƯỚC vòng lặp
            // này — một lần huỷ/đóng vụ việc chạy đua GIỮA lúc cron đang xử lý CÁC MỐC KHÁC
            // (không phải trước khi vòng lặp bắt đầu) vẫn để mốc này lọt vào danh sách. Đọc
            // lại `Matter::scopeOpen()` NGAY TRONG giao dịch của chính dòng này — sau khi đã
            // khoá dòng `deadlines`, cùng vị trí với lần đọc lại `is_completed` ngay trên —
            // để một lần huỷ vừa commit ở một giao dịch khác trong lúc chờ tới lượt vẫn được
            // thấy (mỗi vòng lặp mở một `DB::transaction()` MỚI, nên ảnh chụp REPEATABLE READ
            // của nó bắt đầu lại từ đây, không phải từ lúc `pluck('id')` chạy).
            if (! Matter::query()->whereKey($deadline->matter_id)->open()->exists()) {
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

            // Đánh dấu NGAY, trước khi thư rời tay: xem docblock lớp này và docblock
            // `SendDeadlineReminderMail` — chống gửi trùng từ đây là "đã có job xếp hàng đi
            // gửi", không phải "đã gửi tới hộp thư".
            $deadline->markReminderSent($key);
            $reminded++;

            // `->afterCommit()`: Laravel hoãn việc đẩy job tới khi transaction NÀY thật sự
            // commit. Payload chỉ mang ID (SPEC §10.5) — job tự đọc lại mốc và người nhận lúc
            // nó chạy, xem docblock của job để biết vì sao.
            SendDeadlineReminderMail::dispatch($deadline->getKey(), $recipients->pluck('id')->all(), $key)
                ->afterCommit();
        });
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
     * SPEC §6.8, cột "Người nhận" — đọc lại theo R3 (M6.5 Task 12, `deadlines/F2`, `notify/notify-3`;
     * `deadlines/F4`, `notify/notify-4`): "người nhận thư về một vụ việc là người được xem vụ đó."
     *
     * Trước bản sửa này, hàm tự lọc thủ công (`is_active`/`trashed`), không hỏi
     * `Gate::view()` — một vụ `restricted` vẫn gửi mã hồ sơ và tiêu đề cho trưởng phòng và trợ lý
     * trong đội ngũ, những người `Matter::isListableBy()` từ chối thẳng (`deadlines/F2`). Khi
     * người phụ trách bị vô hiệu hoá và không ai khác lọt vào danh sách xây thủ công ở đây, mốc
     * im lặng hoàn toàn tới bậc 1 ngày, không có chuỗi dự phòng nào (`deadlines/F4`).
     *
     * Bây giờ: hàm này chỉ dựng "danh sách ưu tiên" theo đúng ngữ cảnh mốc thời hạn biết rõ nhất
     * (người phụ trách mốc; trợ lý trong đội ngũ ở bậc 3 ngày; quản lý/admin ở bậc 1 ngày và quá
     * hạn), rồi giao TOÀN BỘ việc lọc is_active + Gate::view + chuỗi dự phòng "không bao giờ im
     * lặng" cho {@see ResolveStaffRecipients} — R3, SỞ HỮU DUY NHẤT của luật đó (Task 8; Task 11
     * đã dùng lại ở `SendDeadlineReminderMail::failed()`), không viết lại một bản nữa ở đây.
     *
     * **Vì sao bậc 1 ngày/quá hạn KHÔNG cộng cả quản lý LẪN admin, mà chọn MỘT trong hai theo
     * `confidentiality` (R3, câu thứ hai: "vụ restricted thì thay manager bằng admin").** Đẩy cả
     * hai vào `$preferred` không phân biệt vụ việc (một cách đọc khác của cùng câu R3) sẽ khiến
     * MỌI admin đang hoạt động nhận thêm thư nhắc bậc 1 ngày/quá hạn của MỌI vụ việc THƯỜNG, không
     * riêng vụ `restricted` — vì `Gate::view()` của một vụ thường vốn đã cho admin đi qua
     * (`Matter::isListableBy()`: `matter.viewAny` là đủ). Đo được bằng chính bộ test của tệp này:
     * "sends nothing for a deadline whose matter is cancelled..." dựng sẵn MỘT admin (dùng làm
     * actor huỷ vụ) cạnh một mốc `d1` của vụ THƯỜNG không hề huỷ — cộng cả hai vai trò không điều
     * kiện làm admin đó cũng nhận thư, tổng `Mail::assertSent(DeadlineReminder::class, 1)` hoá
     * thành 2 và bài test đỏ. Nhánh riêng theo `confidentiality` ở ĐÂY (phía caller), không phải
     * một điều kiện thêm trong `ResolveStaffRecipients::qualify()`, giữ đúng ranh giới: lớp kia
     * chỉ lọc những gì được đưa vào, không tự quyết định "vụ này nên mời ai".
     *
     * @return Collection<int, User>
     */
    public function recipientsFor(Deadline $deadline, string $key): Collection
    {
        $matter = $deadline->matter;

        if ($matter === null) {
            return collect();
        }

        $preferred = collect();

        $responsible = $deadline->responsible;

        if ($responsible instanceof User) {
            $preferred->push($responsible);
        }

        if ($key === 'd3') {
            // Trợ lý trong đội ngũ của CHÍNH vụ việc này, không phải mọi trợ lý của văn phòng.
            // Gate::view() ở ResolveStaffRecipients tự loại người không được xem vụ (restricted,
            // hay đã bị vô hiệu hoá/xoá mềm) — không lọc trước ở đây.
            $preferred = $preferred->merge(
                $matter->team()->get()->filter(fn (User $u): bool => $u->hasRole(Role::Assistant->value))
            );
        }

        if ($key === 'd1' || $key === self::OVERDUE_KEY) {
            $preferred = $preferred->merge(
                $matter->confidentiality === Confidentiality::Restricted
                    ? User::query()->where('is_active', true)->role(Role::Admin->value)->get()
                    : User::query()->where('is_active', true)->role(Role::Manager->value)->get()
            );
        }

        return app(ResolveStaffRecipients::class)->handle($matter, $preferred->all());
    }
}
