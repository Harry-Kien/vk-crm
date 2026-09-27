<?php

namespace App\Actions\Schedule;

use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\DeadlineSeverity;
use App\Enums\Role;
use App\Jobs\SendDeadlineReminderMail;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use App\Notifications\Staff\DeadlineOverdueAlert;
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

    /**
     * Một mốc, một transaction — tách ra khỏi {@see self::handle()} để foreach bắt lỗi gọn.
     *
     * `$overdueNotify` (`array{deadline: Deadline, recipients: Collection<int, User>}|null`) mang
     * dữ liệu cho thông báo TRONG HỆ THỐNG của bậc quá hạn (M6.5 Task 14) ra NGOÀI closure của
     * transaction — xem chú thích tại chỗ gán nó cho lý do bắt buộc phải làm vậy.
     */
    private function processOne(int $id, int &$reminded, int &$skipped): void
    {
        $overdueNotify = null;

        DB::transaction(function () use ($id, &$reminded, &$skipped, &$overdueNotify): void {
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

            // M6.5 Task 14 (`deadlines/F6`, `spec-gap-05`): SPEC §6.8 bậc quá hạn ghi "Đánh dấu
            // quá hạn, TẠO THÔNG BÁO CẢNH BÁO" — khác ba bậc 7/3/1 chỉ ghi "Email". CHỈ chuẩn bị
            // dữ liệu ở đây, KHÔNG gọi `->notify(` bên trong transaction: luật kiến trúc "không có
            // Mail::/Notification::send/route/->notify( nào chạy bên trong DB::transaction ở
            // app/Actions" (ArchitectureTest.php, vòng sửa 1 của Task 11) cấm đúng lời gọi đó —
            // cùng lý do `Mail::` bị cấm, dù kênh `database` của `DeadlineOverdueAlert` không chạm
            // mạng: luật quét theo TÊN PHƯƠNG THỨC, không theo từng lớp. Người nhận là
            // `$recipients` ở trên — tức `recipientsFor()`, CÙNG hàm mà job gửi thư gọi lại lúc
            // chạy (R3, không viết luật nhận thứ hai) — gửi thật diễn ra ở `processOne()`, NGOÀI
            // closure này, sau khi transaction đã commit.
            if ($key === self::OVERDUE_KEY) {
                $overdueNotify = ['deadline' => $deadline, 'recipients' => $recipients];
            }

            // `->afterCommit()`: Laravel hoãn việc đẩy job tới khi transaction NÀY thật sự
            // commit. Payload chỉ mang ID + bậc (SPEC §10.5) — vòng sửa 1 (ruling "re-derive
            // audience at send time") bỏ hẳn danh sách người nhận khỏi payload: job tự gọi lại
            // CHÍNH `recipientsFor()` này lúc nó THẬT SỰ chạy, không tin bất kỳ ảnh chụp nào được
            // dựng ở đây — xem docblock của job để biết vì sao. `$recipients` ở trên chỉ còn dùng
            // để quyết định CÓ dispatch hay không (rỗng thì không đánh dấu, xem trên).
            SendDeadlineReminderMail::dispatch($deadline->getKey(), $key)->afterCommit();
        });

        // Ngoài transaction, cố ý — xem chú thích ở trên. Tier đã được đánh dấu VÀ commit trước
        // khi tới đây, nên một lần chạy `CheckDeadlines` kế tiếp không bao giờ lặp lại nhánh này
        // cho cùng một mốc/bậc (`in_array($key, $already, true)` chặn ở đầu closure) — "chỉ tạo
        // một dòng notifications cho mỗi mốc mỗi bậc" không cần một cột chống trùng RIÊNG.
        if ($overdueNotify !== null) {
            foreach ($overdueNotify['recipients'] as $recipient) {
                $recipient->notify(new DeadlineOverdueAlert($overdueNotify['deadline']));
            }
        }
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
     * **Bậc 1 ngày/quá hạn cộng {@see ResolveStaffRecipients::supervisorsFor()} (vòng sửa 1, M1) —
     * KHÔNG cộng cả quản lý LẪN admin.** `supervisorsFor()` là NƠI DUY NHẤT quyết định "quản lý
     * hay admin" cho một vụ việc (R3, câu thứ hai: "vụ restricted thì thay manager bằng admin") —
     * xem docblock của nó cho lý do đầy đủ (đẩy cả hai vai trò không điều kiện làm mọi admin đang
     * hoạt động nhận thêm thư của mọi vụ THƯỜNG, không riêng vụ `restricted`, vì `Gate::view()`
     * của một vụ thường vốn đã cho admin đi qua).
     *
     * **Người phụ trách MỐC không hợp lệ (vô hiệu hoá, xoá mềm, hay không còn `Gate::view()` được
     * — ví dụ một cộng sự cũ của một vụ vừa bị siết thành `restricted`) thì LUẬT SƯ PHỤ TRÁCH VỤ
     * thế chỗ, ở MỌI bậc (vòng sửa 1, I1).** Trước bản sửa này, việc "người phụ trách vụ thế chỗ"
     * chỉ xảy ra qua `ResolveStaffRecipients::fallbackChain()` — và chuỗi đó CHỈ chạy khi TOÀN BỘ
     * `$preferred` rỗng. Ở bậc `d3`, một trợ lý hợp lệ khác trong đội ngũ (hay ở bậc `d1`/quá hạn,
     * một quản lý/admin hợp lệ từ `supervisorsFor()`) giữ `$preferred` không rỗng, nên chuỗi dự
     * phòng KHÔNG BAO GIỜ kích hoạt — luật sư phụ trách vụ biến mất khỏi bậc đó, dù người phụ
     * trách MỐC đã nghỉ việc hay không còn xem được vụ. Kiểm qua {@see ResolveStaffRecipients::
     * qualifies()} NGAY TẠI ĐÂY, cho riêng "ô người phụ trách" — độc lập với phần còn lại của
     * `$preferred` — để phép thế chỗ này áp dụng bất kể bậc nào khác cộng thêm ai.
     *
     * **Ô người phụ trách vẫn TRỐNG (cả hai đều không hợp lệ) thì cộng `supervisorsFor` ở MỌI
     * bậc, không riêng d1/quá hạn (vòng sửa 2, minor).** Trước bản sửa này, bậc `d3` CHỈ cộng trợ
     * lý trong đội ngũ — nếu ít nhất một trợ lý hợp lệ tồn tại, `$preferred` không rỗng, nên chuỗi
     * dự phòng của `ResolveStaffRecipients::handle()` (chỉ chạy khi `$preferred` rỗng TOÀN BỘ)
     * không bao giờ kích hoạt, và một mốc mà "người phụ trách" thật sự đã biến mất khỏi bức tranh
     * (nghỉ việc, hay không còn xem được vụ) chỉ còn đúng MỘT trợ lý biết tới — không ai giám sát.
     * `$responsibleSlotFilled` theo dõi riêng việc ô đó có được lấp hay không, độc lập với trợ lý
     * đội ngũ; khi vẫn trống, `supervisorsFor` được cộng bất kể bậc nào.
     *
     * @return Collection<int, User>
     */
    public function recipientsFor(Deadline $deadline, string $key): Collection
    {
        $matter = $deadline->matter;

        if ($matter === null) {
            return collect();
        }

        $resolver = app(ResolveStaffRecipients::class);
        $preferred = collect();

        $responsible = $deadline->responsible;
        $responsibleSlotFilled = false;

        if ($responsible instanceof User && $resolver->qualifies($responsible, $matter)) {
            $preferred->push($responsible);
            $responsibleSlotFilled = true;
        } elseif ($matter->leadLawyer !== null && $resolver->qualifies($matter->leadLawyer, $matter)) {
            $preferred->push($matter->leadLawyer);
            $responsibleSlotFilled = true;
        }

        if ($key === 'd3') {
            // Trợ lý trong đội ngũ của CHÍNH vụ việc này, không phải mọi trợ lý của văn phòng.
            // Gate::view() ở ResolveStaffRecipients tự loại người không được xem vụ (restricted,
            // hay đã bị vô hiệu hoá/xoá mềm) — không lọc trước ở đây.
            $preferred = $preferred->merge(
                $matter->team()->get()->filter(fn (User $u): bool => $u->hasRole(Role::Assistant->value))
            );
        }

        if ($key === 'd1' || $key === self::OVERDUE_KEY || ! $responsibleSlotFilled) {
            $preferred = $preferred->merge($resolver->supervisorsFor($matter));
        }

        return $resolver->handle($matter, $preferred->all());
    }
}
