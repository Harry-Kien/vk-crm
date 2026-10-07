<?php

namespace Database\Seeders;

use App\Actions\Billing\ActivateContract;
use App\Actions\Billing\DraftContract;
use App\Actions\Billing\RecordPayment;
use App\Actions\Matter\ReassignMatters;
use App\Enums\ClientRequestStatus;
use App\Enums\Confidentiality;
use App\Enums\InstalmentTrigger;
use App\Enums\MatterRole;
use App\Enums\PaymentMethod;
use App\Enums\UserPosition;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterType;
use App\Models\PerformanceSnapshot;
use App\Models\StageLog;
use App\Models\User;
use App\Support\Performance\PerformancePeriod;
use App\Support\Performance\TeamRoster;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * M13 Task 8 — dữ liệu MẪU cho "Theo dõi đội ngũ", "Trang của một người" và "Hiệu suất theo kỳ": đủ để ba
 * trang vẽ ra một bức tranh THẬT ngay sau `migrate:fresh --seed`, và để đi hết luồng nghiệm thu của kế hoạch
 * M13 (Task 8) trên chính dữ liệu này (`tests/Feature/Performance/DemoWalkthroughTest.php`).
 *
 * **Chỉ là dữ liệu mẫu, không bao giờ chạy ở production.** Gọi từ `DemoDataSeeder` (sau `BillingSeeder`),
 * mà `DatabaseSeeder` chỉ chạy ở `local`/`testing`; `docs/CAI-DAT.md` cấm `db:seed --class=DemoDataSeeder`
 * trên máy chủ thật. Đặc biệt, 90 ngày **ảnh chụp giả** trong `performance_snapshots` ở đây là số BỊA để biểu
 * đồ xu hướng có hình: trên máy chủ thật xu hướng bắt đầu từ ngày triển khai, không bao giờ dựng ngược (R10).
 *
 * # Các tình huống (mỗi mục một khẳng định ở `tests/Feature/Seeders/TeamPerformanceSeederTest.php`)
 *
 * Bốn vụ mới, mỗi vụ mở 100 ngày trước, có hợp đồng một đợt (đã thu), dòng tiến độ trải ba tháng dương lịch
 * (một lần vào giai đoạn tháng trước nữa, một lần tháng trước, một lần tháng này) do chính luật sư phụ trách
 * ghi. Mọi mốc và yêu cầu của "tháng trước" rơi vào ngày làm việc:
 *  - **Luật sư A** (`luatsu1`, {@see self::LAWYER_A_EMAIL}) — "người có mốc lỡ": vụ thường quá hạn cập nhật
 *    cho khách (cùng vụ 1 của `MatterSeeder` là HAI vụ thường quá hạn), tháng trước 2 mốc đúng hạn, 1 trễ,
 *    2 lỡ (còn quá hạn tới nay), một yêu cầu trả lời chậm (hơn một ngày), một yêu cầu đóng không trả lời (P10);
 *    và một vụ `restricted` đã bật cổng, quá hạn cập nhật, có một mốc lỡ tháng trước và một mốc quá hạn
 *    mấy ngày nay — trưởng phòng không thấy vụ này, A và quản trị viên thấy (R4).
 *  - **Luật sư Hà** (`luatsu2`) — "đúng hạn đều": 5 mốc tháng trước đều xong trong ngày; trả lời khách nhanh;
 *    trợ lý Lan giữ 2 mốc đúng hạn của vụ đó, trợ lý Tùng được giao một yêu cầu và trả lời nó.
 *  - **Luật sư nghỉ việc** ({@see self::DEPARTED_EMAIL}) — tháng trước có 1 mốc đúng hạn, 1 mốc LỠ, trả lời 2
 *    luồng CHƯA GIAO AI, để 1 luồng chưa trả lời tới hết tháng; đầu tháng này bàn giao vụ sang **luật sư Bảo**
 *    ({@see self::RECEIVER_EMAIL}) qua {@see ReassignMatters} — ĐÚNG đường thật của màn hình "Bàn giao hàng
 *    loạt", nên dòng `matter_reassigned` và một dòng `deadline_responsible_changed` cho TỪNG mốc đã chuyển
 *    (R9) do chính mã thật ghi — rồi tài khoản bị vô hiệu hoá (dòng nhật ký `updated` của `User`, R3). Vì vậy
 *    trên "Hiệu suất" kỳ "tháng trước": người nghỉ việc vẫn có dòng của mình (cả mốc lỡ và ba luồng), Bảo
 *    không bị tính mốc lỡ hay luồng nào của người trước (R9, R18); trên "Theo dõi đội ngũ" (bây giờ) mốc lỡ đó
 *    và luồng chưa trả lời là việc Bảo phải làm NGAY.
 *  - **90 ngày ảnh chụp giả** tới hôm qua: dòng `normal` cho mọi người được theo dõi (kể cả người nghỉ việc —
 *    họ còn làm cho tới hôm nay), dòng `restricted` chỉ cho luật sư A, mọi số đều > 0 ở một cột.
 *
 * # Đường đi
 *
 * Bàn giao, hợp đồng và khoản thu đi qua Action thật ({@see ReassignMatters}, {@see DraftContract},
 * {@see ActivateContract}, {@see RecordPayment}). Mốc, yêu cầu và dòng tiến độ của THÁNG TRƯỚC thì dựng bằng
 * factory với ngày giờ tường minh, như `MatterSeeder`: Action ghi `now()` (`SetDeadlineCompletion`,
 * `ReplyToClientRequest`), và seeder không `travelTo()`. Hình dạng giữ đúng hình dạng Action ghi: mốc xong
 * có cả `is_completed` lẫn `completed_at`, yêu cầu đã trả lời có `answered_at` và một dòng trả lời của người
 * trả lời, dòng tiến độ mang `created_by` của người ghi.
 *
 * **Chạy lại an toàn:** tài khoản người nghỉ việc là mốc idempotency — có rồi thì không làm gì. Thiếu tài
 * khoản demo hay loại vụ (seeder gọi lẻ) thì hỏng to ngay (`firstOrFail()`), như `MatterSeeder`.
 */
class TeamPerformanceSeeder extends Seeder
{
    /** Luật sư nghỉ việc sau tháng trước — tài khoản đã vô hiệu hoá, không đăng nhập được. */
    public const DEPARTED_EMAIL = 'luatsu4@luatvukhang.com';

    /** Luật sư nhận bàn giao từ người nghỉ việc. */
    public const RECEIVER_EMAIL = 'luatsu3@luatvukhang.com';

    /** "Luật sư A" của luồng nghiệm thu: có mốc lỡ và một vụ `restricted`. */
    public const LAWYER_A_EMAIL = 'luatsu1@luatvukhang.com';

    /** Luật sư đúng hạn đều. */
    public const ON_TIME_EMAIL = 'luatsu2@luatvukhang.com';

    private const ACCOUNTANT_EMAIL = 'ketoan@luatvukhang.com';

    private User $admin;

    private User $accountant;

    private MatterType $type;

    private PerformancePeriod $lastMonth;

    /** @return list<string> email của mọi luật sư trong dữ liệu mẫu, kể cả người nghỉ việc */
    public static function lawyerEmails(): array
    {
        return [self::LAWYER_A_EMAIL, self::ON_TIME_EMAIL, self::RECEIVER_EMAIL, self::DEPARTED_EMAIL];
    }

    public function run(): void
    {
        if (User::withTrashed()->where('email', self::DEPARTED_EMAIL)->exists()) {
            return;
        }

        $this->admin = User::query()->where('email', DemoAccountsSeeder::ADMIN_EMAIL)->firstOrFail();
        $this->accountant = User::query()->where('email', self::ACCOUNTANT_EMAIL)->firstOrFail();
        $this->type = MatterType::query()->where('code', 'DS')->with('stages')->firstOrFail();
        $this->lastMonth = PerformancePeriod::fromFilters(['period' => PerformancePeriod::LAST_MONTH]);

        $lawyerA = User::query()->where('email', self::LAWYER_A_EMAIL)->firstOrFail();
        $onTime = User::query()->where('email', self::ON_TIME_EMAIL)->firstOrFail();
        $receiver = User::query()->where('email', self::RECEIVER_EMAIL)->firstOrFail();
        $lan = User::query()->where('email', 'troly1@luatvukhang.com')->firstOrFail();
        $tung = User::query()->where('email', 'troly2@luatvukhang.com')->firstOrFail();
        $clients = Client::query()->orderBy('id')->get()->values();

        auth('web')->setUser($this->admin);

        $departed = User::query()->create([
            'email' => self::DEPARTED_EMAIL,
            'name' => 'Trịnh Minh Đức',
            'password' => Str::random(40),
            'position' => UserPosition::Lawyer,
            'bar_number' => 'LS-1004',
            'phone' => '0909990004',
            'is_active' => true,
        ]);
        $departed->assignRoleFromPosition();

        $this->lawyerA($lawyerA, $clients->get(6), $clients->get(7));
        $this->onTime($onTime, $lan, $tung, $clients->get(8));
        $handedOver = $this->departed($departed, $clients->get(9));

        // Đầu tháng này: quản trị viên bàn giao vụ của người nghỉ việc qua màn hình "Bàn giao hàng loạt".
        $results = app(ReassignMatters::class)->handle(
            [$handedOver->getKey()],
            $this->admin,
            $receiver,
            'Luật sư Trịnh Minh Đức nghỉ việc — bàn giao toàn bộ hồ sơ (dữ liệu mẫu M13).',
            false,
            $departed->getKey(),
        );

        foreach ($results as $result) {
            if (! $result->success) {
                throw new RuntimeException("TeamPerformanceSeeder: bàn giao vụ {$result->matterId} thất bại — {$result->message}");
            }
        }

        // Người nhận ghi dòng tiến độ đầu tiên của mình trên vụ vừa nhận (tháng này).
        $this->entry($handedOver->fresh(), $receiver, 2, 3, now());

        // Rồi tài khoản bị vô hiệu hoá: dòng nhật ký `updated` (old.is_active = true → false) là thứ
        // `TeamRoster::subjectsForPeriod()` đọc để giữ người này trong kỳ "tháng trước" (R3).
        $departed->forceFill(['is_active' => false])->save();

        $this->snapshots($lawyerA);

        auth('web')->forgetUser();
    }

    /** Luật sư A: một vụ thường quá hạn cập nhật, có mốc lỡ, và một vụ `restricted` của riêng A. */
    private function lawyerA(User $lawyer, Client $client, Client $restrictedClient): void
    {
        $matter = $this->matter($lawyer, $client, 'Tranh chấp hợp đồng thuê kho với Công ty TNHH Hưng Thịnh', 1, stale: true);

        $this->deadline($matter, $lawyer, 'Nộp bản tự khai', 2, completedAt: $this->day(2, '10:00'));
        $this->deadline($matter, $lawyer, 'Nộp chứng cứ bổ sung', 8, completedAt: $this->day(7, '16:00'));
        $this->deadline($matter, $lawyer, 'Gửi ý kiến về biên bản hoà giải', 11, completedAt: $this->day(14, '09:00'));
        $this->deadline($matter, $lawyer, 'Nộp đơn đề nghị áp dụng biện pháp khẩn cấp', 17);
        $this->deadline($matter, $lawyer, 'Nộp bản đối chiếu công nợ', 21);

        $this->request($matter, $lawyer, 'Hỏi về lịch hoà giải', $this->day(4, '09:00'), $this->day(7, '15:00'));
        $this->request($matter, null, 'Gửi nhầm, xin huỷ yêu cầu', $this->day(19, '10:00'), null, ClientRequestStatus::Closed);

        $restricted = $this->matter($lawyer, $restrictedClient, 'Tư vấn tái cấu trúc nợ — hồ sơ hạn chế truy cập', 2, stale: true, confidentiality: Confidentiality::Restricted);

        $this->deadline($restricted, $lawyer, 'Gửi phương án cơ cấu nợ cho chủ nợ', 12);
        $this->deadline($restricted, $lawyer, 'Phản hồi đề nghị của ngân hàng', today()->subDays(3)->toImmutable());
    }

    /** Luật sư Hà: mọi mốc xong trong ngày, trả lời nhanh; hai trợ lý giữ việc trên cùng vụ. */
    private function onTime(User $lawyer, User $lan, User $tung, Client $client): void
    {
        $matter = $this->matter($lawyer, $client, 'Ly hôn và chia tài sản chung với Kha Văn Toàn', 3, stale: false);
        $matter->addTeamMember($lan, MatterRole::Assistant);
        $matter->addTeamMember($tung, MatterRole::Assistant);

        foreach ([1, 5, 10, 15, 22] as $n) {
            $this->deadline($matter, $lawyer, "Mốc tố tụng ngày {$n} của tháng", $n, completedAt: $this->day($n, '09:30'));
        }

        $this->deadline($matter, $lan, 'Sao y chứng thực giấy tờ nhà đất', 6, completedAt: $this->day(6, '08:30')->subDay());
        $this->deadline($matter, $lan, 'Nộp lệ phí thụ lý', 19, completedAt: $this->day(19, '08:30')->subDay());

        $this->request($matter, $lawyer, 'Hỏi về giấy tờ cần mang tới buổi hoà giải', $this->day(2, '09:00'), $this->day(2, '10:30'));
        $this->request($matter, $lawyer, 'Xin lịch hẹn gặp luật sư', $this->day(9, '08:30'), $this->day(9, '09:15'));
        $this->request($matter, $tung, 'Hỏi về chi phí sao y giấy tờ', $this->day(13, '09:00'), $this->day(15, '15:00'), assignedTo: $tung);
    }

    /** Luật sư nghỉ việc: một mốc lỡ, hai luồng chưa giao ai đã trả lời, một luồng chưa trả lời; trả về vụ sẽ bàn giao. */
    private function departed(User $lawyer, Client $client): Matter
    {
        $matter = $this->matter($lawyer, $client, 'Tranh chấp quyền sử dụng lối đi chung với Hộ ông Lục Văn Kiên', 4, stale: false);

        $this->deadline($matter, $lawyer, 'Nộp đơn khởi kiện bổ sung', 3, completedAt: $this->day(3, '11:00'));
        $this->deadline($matter, $lawyer, 'Nộp biên lai tạm ứng án phí', 9);
        $this->deadline($matter, $lawyer, 'Tham dự phiên hoà giải', today()->addDays(6)->toImmutable(), createdAt: now()->subDays(5));

        $this->request($matter, $lawyer, 'Hỏi về kết quả xác minh hiện trạng', $this->day(5, '09:00'), $this->day(5, '11:00'));
        $this->request($matter, $lawyer, 'Hỏi về lịch đo đạc lại', $this->day(16, '09:30'), $this->day(17, '10:00'));
        $this->request($matter, null, 'Gửi thêm ảnh chụp lối đi', $this->day(23, '14:00'), null, ClientRequestStatus::New);

        return $matter;
    }

    /**
     * Một vụ việc mẫu M13: mở 100 ngày trước, đã bật cổng, hai bên (khách và một bị đơn), hợp đồng một đợt
     * đã thu, dòng tiến độ trải ba tháng dương lịch. `$stale` = lần cập nhật cho khách gần nhất 20 ngày trước
     * (quá 14 ngày, `MatterStaleness`), không thì 3 ngày trước.
     */
    private function matter(User $lead, Client $client, string $title, int $k, bool $stale, Confidentiality $confidentiality = Confidentiality::Normal): Matter
    {
        $stages = $this->workingStages();
        $openedAt = now()->subDays(100);

        $matter = Matter::query()->create([
            'client_id' => $client->id,
            'matter_type_id' => $this->type->id,
            'title' => $title,
            'description_internal' => 'Dữ liệu mẫu M13 (theo dõi đội ngũ và hiệu suất).',
            'summary_for_client' => 'Văn phòng đang đại diện anh/chị trong vụ việc này và sẽ cập nhật từng bước.',
            'stage' => $stages[2],
            'stage_entered_at' => $this->day(15, '09:00'),
            'lead_lawyer_id' => $lead->id,
            'opened_at' => $openedAt->toDateString(),
            'is_published_to_portal' => true,
            'confidentiality' => $confidentiality,
            'court_name' => 'Toà án nhân dân Quận '.(3 + $k).', TP. Hồ Chí Minh',
            'last_client_update_at' => $stale ? now()->subDays(20) : now()->subDays(3),
        ]);

        MatterParty::factory()->ourClient($client)->for($matter)->create();
        MatterParty::factory()->for($matter)->defendant()->identify(sprintf('0%011d', 900000000300 + $k), sprintf('0938%06d', 300 + $k))->create([
            'name' => 'Bên bị đơn của vụ mẫu M13 số '.$k,
            'address' => 'TP. Hồ Chí Minh',
        ]);

        $this->contract($matter, $lead, $confidentiality === Confidentiality::Restricted ? $lead : $this->accountant);

        // Dòng tiến độ: vào giai đoạn đầu lúc mở hồ sơ, vào giai đoạn 2 ở tháng trước nữa, một cập nhật cùng
        // giai đoạn và một lần vào giai đoạn 3 ở tháng trước. Lần vào giai đoạn 4 (tháng này) do người đang
        // phụ trách ghi — với vụ sẽ bàn giao thì người nhận ghi, sau lần bàn giao (`run()`).
        $this->entry($matter, $lead, null, 0, $openedAt);
        $this->entry($matter, $lead, 0, 1, $this->lastMonth->from->subDays(20)->setTime(9, 0));
        $this->entry($matter, $lead, 1, 1, $this->day(7, '15:00'));
        $this->entry($matter, $lead, 1, 2, $this->day(15, '09:00'));

        if ($lead->email !== self::DEPARTED_EMAIL) {
            $this->entry($matter, $lead, 2, 3, now());
        }

        return $matter;
    }

    /** Hợp đồng một đợt, ký 3 ngày sau ngày mở hồ sơ, thu đủ ở tháng trước — qua đúng ba Action của M9. */
    private function contract(Matter $matter, User $lead, User $recorder): void
    {
        $contract = app(DraftContract::class)->handle($lead, $matter, ['total_amount' => 30_000_000, 'vat_rate_percent' => null], [[
            'name' => 'Thanh toán toàn bộ khi ký hợp đồng',
            'amount' => 30_000_000,
            'percent_basis' => '100',
            'trigger_type' => InstalmentTrigger::OnSigning,
            'trigger_stage_key' => null,
            'due_days_after_trigger' => 7,
            'due_date' => null,
        ]]);

        app(ActivateContract::class)->handle($lead, $contract, $matter->opened_at->copy()->addDays(3));

        $instalment = $contract->instalments()->orderBy('sequence')->firstOrFail();

        app(RecordPayment::class)->handle(
            $recorder,
            $instalment->fresh(),
            30_000_000,
            $this->day(4, '10:00')->toDateString(),
            PaymentMethod::BankTransfer,
            'UNC M13-'.$matter->getKey(),
            null,
            null,
        );
    }

    /**
     * Một dòng tiến độ nội bộ do `$author` ghi: `$from` → `$to` là chỉ số giai đoạn làm việc của loại vụ
     * (`null` = dòng mở đầu). `$from === $to` là một cập nhật cùng giai đoạn — không phải "vào giai đoạn".
     */
    private function entry(Matter $matter, User $author, ?int $from, int $to, CarbonInterface $occurredAt): void
    {
        $stages = $this->workingStages();

        StageLog::factory()->for($matter)->create([
            'from_stage' => $from === null ? null : $stages[$from],
            'to_stage' => $stages[$to],
            'occurred_at' => $occurredAt,
            'internal_note' => 'Dữ liệu mẫu M13: '.($from === $to ? 'cập nhật tiến độ trong giai đoạn.' : 'chuyển giai đoạn.'),
            'public_content' => null,
            'next_step' => null,
            'expected_next_update_at' => null,
            'is_published' => false,
            'published_at' => null,
            'notified_at' => null,
            'created_by' => $author->id,
        ]);

        if ($from !== $to) {
            $matter->forceFill(['stage' => $stages[$to], 'stage_entered_at' => $occurredAt])->saveQuietly();
        }
    }

    /**
     * Một mốc: `$due` là ngày thứ `$due` của tháng trước (dời sang thứ Hai nếu rơi vào cuối tuần) hoặc một
     * ngày tường minh; ghi vào hệ thống 20 ngày trước hạn (`$createdAt` đè). `$completedAt` rỗng = chưa xong.
     */
    private function deadline(Matter $matter, User $holder, string $name, int|CarbonImmutable $due, ?CarbonInterface $completedAt = null, ?CarbonInterface $createdAt = null): void
    {
        $dueDate = is_int($due) ? $this->day($due, '00:00') : $due->startOfDay();

        Deadline::factory()->for($matter)->create([
            'name' => $name,
            'due_date' => $dueDate->toDateString(),
            'responsible_user_id' => $holder->id,
            'is_completed' => $completedAt !== null,
            'completed_at' => $completedAt,
            'created_at' => $createdAt ?? $dueDate->subDays(20)->setTime(9, 0),
        ]);
    }

    /** Một yêu cầu của khách; đã trả lời thì có `answered_at` và một dòng trả lời của `$answerer`. */
    private function request(Matter $matter, ?User $answerer, string $subject, CarbonInterface $sentAt, ?CarbonInterface $answeredAt, ClientRequestStatus $status = ClientRequestStatus::Answered, ?User $assignedTo = null): void
    {
        $request = ClientRequest::factory()->for($matter)->create([
            'client_user_id' => $matter->client->clientUsers()->firstOrFail()->id,
            'subject' => $subject,
            'content' => 'Kính gửi luật sư, '.Str::lower($subject).'. Xin cảm ơn.',
            'status' => $status,
            'assigned_to' => $assignedTo?->id,
            'answered_at' => $answeredAt,
            'last_activity_at' => $answeredAt ?? $sentAt,
            'created_at' => $sentAt,
            'updated_at' => $answeredAt ?? $sentAt,
        ]);

        if ($answerer !== null && $answeredAt !== null) {
            ClientRequestReply::factory()->create([
                'request_id' => $request->id,
                'author_type' => $answerer->getMorphClass(),
                'author_id' => $answerer->id,
                'content' => 'Chào anh/chị, văn phòng đã nhận được và xin trả lời như sau: luật sư sẽ liên hệ trong hôm nay.',
                'created_at' => $answeredAt,
                'updated_at' => $answeredAt,
            ]);
        }
    }

    /**
     * 90 ngày ảnh chụp GIẢ tới hôm qua (R10: chỉ dữ liệu mẫu có ảnh chụp giả). Một dòng `normal` mỗi ngày
     * cho mọi người được theo dõi — kể cả người đã nghỉ việc, vì họ còn làm cho tới hôm nay — và dòng
     * `restricted` chỉ cho luật sư A (người duy nhất đứng tên vụ `restricted`), mỗi dòng có ít nhất một số > 0.
     * Ghi bằng `upsert` trên khoá `(captured_on, user_id, confidentiality)`, như tác vụ chụp thật.
     */
    private function snapshots(User $lawyerA): void
    {
        $rows = [];
        $stamp = now();

        foreach (TeamRoster::members(includeInactive: true)->values() as $i => $person) {
            $leads = TeamRoster::leadsMatters($person) && $person->position !== UserPosition::Manager;

            foreach (range(1, 90) as $back) {
                $capturedOn = PerformanceSnapshot::storedDay(today()->subDays($back));
                $mine = $person->is($lawyerA);

                $rows[] = [
                    'captured_on' => $capturedOn,
                    'user_id' => $person->id,
                    'confidentiality' => Confidentiality::Normal->value,
                    'open_lead_matters' => $leads ? 6 + ($i + intdiv($back, 15)) % 3 : 0,
                    'stale_matters' => $leads ? ($mine && $back <= 20 ? 2 : ($i + intdiv($back, 10)) % 3) : 0,
                    'overdue_deadlines' => $leads ? ($i * 2 + intdiv($back, 7)) % 4 : ($i + intdiv($back, 12)) % 2,
                    'checklist_settled' => $leads ? 20 + intdiv(90 - $back, 6) : 0,
                    'checklist_total' => $leads ? 40 : 0,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ];

                if ($mine) {
                    $rows[] = [
                        'captured_on' => $capturedOn,
                        'user_id' => $person->id,
                        'confidentiality' => Confidentiality::Restricted->value,
                        'open_lead_matters' => $back <= 10 ? 2 : 1,
                        'stale_matters' => $back <= 20 ? 1 : 0,
                        'overdue_deadlines' => $back <= 3 ? 1 : 0,
                        'checklist_settled' => 0,
                        'checklist_total' => 0,
                        'created_at' => $stamp,
                        'updated_at' => $stamp,
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            PerformanceSnapshot::query()->upsert(
                $chunk,
                ['captured_on', 'user_id', 'confidentiality'],
                ['open_lead_matters', 'stale_matters', 'overdue_deadlines', 'checklist_settled', 'checklist_total', 'updated_at'],
            );
        }
    }

    /** Ngày thứ `$n` (0 = ngày 1) của tháng trước lúc `$time`; rơi vào thứ Bảy, Chủ nhật thì dời sang thứ Hai. */
    private function day(int $n, string $time): CarbonImmutable
    {
        $day = $this->lastMonth->from->addDays($n);

        if ($day->isWeekend()) {
            $day = $day->next(CarbonInterface::MONDAY);
        }

        return $day->setTimeFromTimeString($time);
    }

    /** @return list<string> khoá các giai đoạn làm việc của loại vụ (không kết thúc, không tạm dừng), theo thứ tự */
    private function workingStages(): array
    {
        return $this->type->stages
            ->reject(fn ($stage): bool => $stage->is_terminal || $stage->key === 'on_hold')
            ->pluck('key')
            ->values()
            ->all();
    }
}
