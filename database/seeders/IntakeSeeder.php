<?php

namespace Database\Seeders;

use App\Actions\Intake\AcknowledgeIntakeConflict;
use App\Actions\Intake\AnonymiseProspect;
use App\Actions\Intake\ChangeIntakeStatus;
use App\Actions\Intake\ConvertIntakeToMatter;
use App\Actions\Intake\DeclineIntake;
use App\Actions\Intake\MergeIntake;
use App\Actions\Intake\RecordIntake;
use App\Actions\Intake\RecordPrivacyNotice;
use App\Actions\Intake\UpdateIntakeIdentity;
use App\Actions\Intake\UpdateIntakeSummary;
use App\Enums\ClientType;
use App\Enums\Confidentiality;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\PartyRole;
use App\Exceptions\ExistingClientConfirmationRequired;
use App\Models\IntakeParty;
use App\Models\IntakeRequest;
use App\Models\MatterType;
use App\Models\User;
use App\Support\Intake\FirstResponseClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Dữ liệu mẫu TIẾP NHẬN (M10 Task 8): mười hai lần có người liên hệ văn phòng, đủ để mở màn hình
 * "Tiếp nhận", widget "Liên hệ chưa ai gọi lại" và trang "Bức tranh đầu vào" ra mà thấy được mọi tình
 * huống của kế hoạch — bản ghi ở MỌI trạng thái (`IntakeStatus`), một cặp tiếp nhận đối nhau, một bản
 * Đỏ chờ trưởng phòng, một bản quá hạn phản hồi, một bản đã ẩn danh — trên đúng văn phòng mẫu mà
 * `StaffSeeder`/`ClientSeeder`/`MatterSeeder` dựng (nên PHẢI chạy sau ba seeder đó: hai bản Đỏ trỏ vào
 * khách hàng hiện hữu, và bản chuyển thành vụ gắn vào một khách đã có).
 *
 * **Mọi bản ghi đi qua đúng các Action của mã sản phẩm** — `RecordIntake`, `RecordPrivacyNotice`,
 * `AcknowledgeIntakeConflict`, `UpdateIntakeSummary`, `ChangeIntakeStatus`, `UpdateIntakeIdentity` (phí
 * đã báo), `DeclineIntake`, `MergeIntake`, `ConvertIntakeToMatter`, `AnonymiseProspect::expire()` — không một `create()` hay
 * `forceFill()` nào trên bảng tiếp nhận, cùng luật `MatterSeeder`: dữ liệu mẫu không được mang một hình
 * dạng mà mã sản phẩm không sinh ra được (mức xung đột, dấu Đỏ chờ, bằng chứng kiểm tra trong nhật ký,
 * hạn lưu, mốc phản hồi đầu đều do chính các Action đặt). Người làm mỗi bước là đúng vai được phép làm
 * bước đó (trợ lý ghi cuộc gọi, luật sư chuyển đổi, trưởng phòng từ chối vì xung đột).
 *
 * **Thời gian thật của từng bước.** Mỗi bước chạy ở thời điểm nó "xảy ra" (`at()`: đặt đồng hồ của
 * Carbon tạm thời, như `travelTo()` của test, rồi trả lại ĐÚNG đồng hồ trước đó — kể cả một đồng hồ
 * test đã đặt trước khi seed), nên `received_at`, `first_response_at`, hạn lưu, mã `TN-{năm}` và mọi
 * dòng nhật ký khớp nhau như dữ liệu thật — báo cáo đầu vào (Task 6) có thời gian phản hồi để đo. Mốc
 * tính từ lúc seed (`now()` lúc bắt đầu), không từ một ngày cố định.
 *
 * Hai mốc phụ thuộc cấu hình, nên tính từ cấu hình chứ không viết cứng: bản đã ẩn danh mất liên hệ
 * `retentionMonths() + 1` tháng trước (hạn lưu đã qua một tháng với mọi `PROSPECT_RETENTION_MONTHS`),
 * và bản quá hạn phản hồi được nhận đủ sớm để quá ngưỡng `INTAKE_RESPONSE_HOURS` theo giờ làm việc.
 *
 * Số điện thoại người liên hệ `0988 000 0xx`, bên đối lập `0977 000 0xx`: không trùng khách, bên của vụ
 * hay nhân sự mẫu nào, trừ hai bản Đỏ (bên đối lập mang đúng SĐT của khách hiện hữu Lê Hoàng Cường và
 * Vũ Thị Em) và bản chuyển đổi (người liên hệ là khách hiện hữu Phạm Thị Dung gọi về một việc mới).
 *
 * Chạy lại không thêm gì nếu đã có bản ghi tiếp nhận nào (kể cả đã xoá mềm).
 */
class IntakeSeeder extends Seeder
{
    private CarbonImmutable $now;

    /** @var array<string, User> */
    private array $staff = [];

    public function run(): void
    {
        if (IntakeRequest::withTrashed()->exists()) {
            return;
        }

        $this->now = CarbonImmutable::now();
        $this->staff = User::query()
            ->whereIn('email', [
                'quanly@luatvukhang.com', 'luatsu1@luatvukhang.com', 'luatsu2@luatvukhang.com',
                'luatsu3@luatvukhang.com', 'troly1@luatvukhang.com', 'troly2@luatvukhang.com',
            ])
            ->get()
            ->keyBy(fn (User $user): string => strstr($user->email, '@', true))
            ->all();

        $this->anonymisedAfterRetention();
        $this->declinedOutsidePractice();
        $this->declinedForConflict();
        $this->lostAfterConsulting();
        $this->convertedForExistingClient();
        $this->overdueForFirstResponse();
        $this->quoted();
        $this->opposingPair();
        $this->redWaitingForManager();
        $this->freshCall();
    }

    /**
     * Một người mất liên hệ quá hạn lưu (R7b): ghi, gọi lại, "khách không theo tiếp" `retentionMonths()+1`
     * tháng trước, rồi tác vụ hằng ngày (`AnonymiseProspect::expire()`, đúng Action của lịch
     * `prospects.anonymise`) ẩn danh nó hôm nay. Dòng, mã, nguồn, trạng thái, mốc thời gian ở lại cho báo cáo.
     */
    private function anonymisedAfterRetention(): void
    {
        $months = IntakeRequest::retentionMonths();
        $start = $this->now->subMonthsNoOverflow($months + 2)->setTime(9, 15);

        $intake = $this->at($start, fn () => $this->record('troly2', [
            'contact_name' => 'Phan Văn Cũ',
            'contact_phone' => '0988 000 001',
            'contact_role' => PartyRole::Plaintiff,
            'source' => IntakeSource::Referral,
            'referred_by' => 'Ông Tư, khách cũ của văn phòng',
            'matter_type_id' => $this->type('DS'),
        ], [
            ['name' => 'Nguyễn Văn Nợ', 'role' => PartyRole::Defendant, 'phone' => '0977 000 001'],
        ], story: 'Ông Cũ cho người quen vay 80 triệu đồng, có giấy viết tay, người vay hẹn mãi không trả.'));

        $this->at($start->addHours(2), fn () => $this->status('troly2', $intake, IntakeStatus::Contacted));
        $this->at($this->now->subMonthsNoOverflow($months + 1)->setTime(16, 0), fn () => $this->status('troly2', $intake, IntakeStatus::Lost));

        if (! app(AnonymiseProspect::class)->expire($intake->fresh())) {
            throw new RuntimeException("IntakeSeeder: {$intake->code} chưa quá hạn lưu, không ẩn danh được.");
        }
    }

    /** Từ chối vì lý do thường (ngoài lĩnh vực): luật sư đã ghi bản ghi tự từ chối, kèm lý do. */
    private function declinedOutsidePractice(): void
    {
        $start = $this->now->subDays(40)->setTime(14, 5);

        $intake = $this->at($start, fn () => $this->record('luatsu3', [
            'contact_name' => 'Đoàn Thị Hỏi',
            'contact_phone' => '0988 000 002',
            'contact_email' => 'doan.thi.hoi@example.com',
            'contact_role' => PartyRole::Plaintiff,
            'source' => IntakeSource::WebsiteForm,
        ], [
            ['name' => 'Công ty TNHH Nhãn Hiệu Sao Mai', 'role' => PartyRole::Defendant, 'phone' => '0977 000 002'],
        ], story: 'Bà Hỏi muốn đăng ký bảo hộ nhãn hiệu và kiện một công ty đang dùng tên gần giống.'));

        $this->at($start->addHours(3), fn () => $this->status('luatsu3', $intake, IntakeStatus::Contacted));
        $this->at($start->addDay()->setTime(9, 0), fn () => app(DeclineIntake::class)->handle(
            $this->staff['luatsu3'],
            $intake->fresh(),
            'Sở hữu trí tuệ nằm ngoài lĩnh vực văn phòng nhận; đã giới thiệu bà Hỏi tới một văn phòng chuyên về nhãn hiệu.',
        ));
    }

    /**
     * Đỏ rồi trưởng phòng từ chối vì xung đột (R1, R8): bên đối lập là khách hàng hiện hữu Lê Hoàng Cường
     * (khớp SĐT). Câu chuyện không bao giờ được ghi; lý do chỉ trưởng phòng/quản trị thấy.
     */
    private function declinedForConflict(): void
    {
        $start = $this->now->subDays(30)->setTime(10, 20);

        $intake = $this->at($start, fn () => $this->record('troly1', [
            'contact_name' => 'Mạc Văn Kiện',
            'contact_phone' => '0988 000 003',
            'contact_role' => PartyRole::Plaintiff,
            'source' => IntakeSource::Phone,
            'matter_type_id' => $this->type('DS'),
        ], [
            ['name' => 'Lê Hoàng Cường', 'role' => PartyRole::Defendant, 'phone' => '0903 456 789'],
        ]));

        $this->at($start->addHours(1), fn () => app(DeclineIntake::class)->handle(
            $this->staff['quanly'],
            $intake->fresh(),
            'Bên bị kiện là khách hàng hiện hữu của văn phòng (khớp số điện thoại). Không nhận; trả lời người gọi theo câu chuẩn, không giải thích.',
            true,
        ));
    }

    /** Tư vấn xong, khách không theo tiếp (`lost`) mười ngày trước — còn trong hạn lưu (luồng 3 của Task 8). */
    private function lostAfterConsulting(): void
    {
        $start = $this->now->subDays(20)->setTime(8, 40);

        $intake = $this->at($start, fn () => $this->record('troly2', [
            'contact_name' => 'Hồ Thị Mất',
            'contact_phone' => '0988 000 004',
            'contact_role' => PartyRole::Plaintiff,
            'source' => IntakeSource::Zalo,
            'matter_type_id' => $this->type('HN'),
        ], [
            ['name' => 'Tạ Văn Chồng', 'role' => PartyRole::Defendant, 'phone' => '0977 000 004'],
        ], story: 'Chị Mất hỏi thủ tục ly hôn đơn phương và việc giành quyền nuôi con nhỏ.'));

        $this->at($start->addHours(2), fn () => $this->status('troly2', $intake, IntakeStatus::Contacted));
        $this->at($start->addDays(5)->setTime(15, 0), fn () => $this->status('troly2', $intake, IntakeStatus::Consulting));
        $this->at($start->addDays(10)->setTime(11, 0), fn () => $this->status('troly2', $intake, IntakeStatus::Lost));
    }

    /**
     * Khách hiện hữu Phạm Thị Dung gọi về một việc MỚI (bẫy "vừa là người liên hệ mới vừa là khách cũ"):
     * lần kiểm tra lúc tiếp nhận ra Vàng (SĐT khớp chính bà ở hai vụ cũ) và trợ lý xác nhận đã xem trước
     * khi nghe chuyện (R1); tư vấn, báo giá 20 triệu, rồi luật sư Phạm Thu Hà chuyển thành vụ —
     * `ConvertIntakeToMatter` tra ra hồ sơ khách theo SĐT và gắn vào ĐÚNG hồ sơ đó sau xác nhận (hai lượt
     * của màn hình), không tạo khách thứ hai. Phí đã báo thành giá trị gợi ý của form soạn hợp đồng.
     */
    private function convertedForExistingClient(): void
    {
        $start = $this->now->subDays(15)->setTime(9, 10);

        $intake = $this->at($start, fn () => $this->record('troly1', [
            'contact_name' => 'Phạm Thị Dung',
            'contact_phone' => '0905678901',
            'contact_role' => PartyRole::Plaintiff,
            'source' => IntakeSource::Phone,
            'matter_type_id' => $this->type('LD'),
            'assigned_to' => $this->staff['luatsu2']->id,
        ], [
            ['name' => 'Công ty TNHH Bao bì Phương Nam', 'role' => PartyRole::Defendant, 'phone' => '0977 000 005'],
        ], story: 'Bà Dung bị công ty cũ nợ ba tháng lương và chưa chốt sổ bảo hiểm xã hội sau khi nghỉ việc.', acknowledge: true));

        $this->at($start->addHours(1), fn () => $this->status('troly1', $intake, IntakeStatus::Contacted));
        $this->at($start->addDays(2)->setTime(10, 0), fn () => $this->status('luatsu2', $intake, IntakeStatus::Consulting));
        $this->at($start->addDays(3)->setTime(16, 30), fn () => $this->quote('luatsu2', $intake, '20.000.000'));

        $this->at($start->addDays(5)->setTime(9, 0), function () use ($intake): void {
            $lawyer = $this->staff['luatsu2'];
            $attributes = [
                'title' => 'Đòi tiền lương và chốt sổ bảo hiểm với Công ty TNHH Bao bì Phương Nam',
                'matter_type_id' => $this->type('LD'),
                'lead_lawyer_id' => $lawyer->id,
                'client_role' => PartyRole::Plaintiff,
                'opened_at' => now()->toDateString(),
                'confidentiality' => Confidentiality::Normal,
                'client_type' => ClientType::Individual,
                'description_internal' => $intake->fresh()->summary,
            ];

            // Hai lượt của trang chuyển đổi: lượt đầu tra ra hồ sơ khách đã có và hiện mã + tên; lượt hai
            // gửi lại id đó làm xác nhận đúng người. Lần kiểm tra xung đột của `OpenMatter` ra Xanh (khách
            // khớp chính mình ở các vụ cũ không phải xung đột lúc mở vụ), nên không có lượt xác nhận Vàng;
            // bất kỳ lời từ chối nào khác là lỗi dữ liệu mẫu và đi ra nguyên vẹn.
            try {
                app(ConvertIntakeToMatter::class)->handle($lawyer, $intake->fresh(), $attributes);
            } catch (ExistingClientConfirmationRequired $shown) {
                app(ConvertIntakeToMatter::class)->handle($lawyer, $intake->fresh(), $attributes, confirmedClientId: $shown->client->id);
            }
        });
    }

    /**
     * Chưa ai gọi lại, quá ngưỡng phản hồi đầu (R5): lên widget trang chủ của người được giao (trợ lý
     * Ngô Thị Lan). Nhận đủ sớm để quá `INTAKE_RESPONSE_HOURS` giờ làm việc với mọi cấu hình.
     */
    private function overdueForFirstResponse(): void
    {
        $clock = FirstResponseClock::fromConfig();
        $receivedAt = $this->now->subDays(5)->setTime(9, 30);

        while ($clock->hours->addHours($receivedAt, $clock->thresholdHours)->greaterThan($this->now)) {
            $receivedAt = $receivedAt->subDay();
        }

        $this->at($receivedAt, fn () => $this->record('troly2', [
            'contact_name' => 'Kiều Văn Chờ',
            'contact_phone' => '0988 000 011',
            'contact_role' => PartyRole::Plaintiff,
            'source' => IntakeSource::Phone,
            'matter_type_id' => $this->type('DS'),
            'assigned_to' => $this->staff['troly1']->id,
        ]));
    }

    /** Đã báo giá 12 triệu, chờ khách quyết — sẵn để luật sư phụ trách chuyển thành vụ. */
    private function quoted(): void
    {
        $start = $this->now->subDays(6)->setTime(13, 45);

        $intake = $this->at($start, fn () => $this->record('troly1', [
            'contact_name' => 'Đặng Thị Thu Hương',
            'contact_phone' => '0988 000 006',
            'contact_email' => 'thuhuong.dang@example.com',
            'contact_role' => PartyRole::Plaintiff,
            'source' => IntakeSource::WalkIn,
            'matter_type_id' => $this->type('HN'),
            'assigned_to' => $this->staff['luatsu1']->id,
        ], [
            ['name' => 'Lương Văn Tài', 'role' => PartyRole::Defendant, 'phone' => '0977 000 006'],
        ], story: 'Chị Hương muốn ly hôn thuận tình, hai bên chưa thống nhất chia căn hộ chung cư mua năm 2019.'));

        $this->at($start->addHours(1), fn () => $this->status('troly1', $intake, IntakeStatus::Contacted));
        $this->at($start->addDay()->setTime(10, 0), fn () => $this->status('luatsu1', $intake, IntakeStatus::Consulting));
        $this->at($start->addDays(2)->setTime(15, 0), fn () => $this->quote('luatsu1', $intake, '12.000.000'));
    }

    /**
     * Một cặp tiếp nhận ĐỐI NHAU (R1, nguồn dò thứ hai): anh Hùng tới văn phòng kể chuyện tranh chấp ranh
     * giới đất với chị Nga và được tư vấn; hôm sau chị Nga gọi tới về CÙNG tranh chấp, bên kia là anh
     * Hùng. Lần kiểm tra của chị Nga ra Vàng — khớp mã `TN-…` của anh Hùng ("đã liên hệ văn phòng ngày
     * …"), không câu chuyện — nên trợ lý ghi nhận được thông báo nhưng ô câu chuyện đóng tới khi có người
     * xác nhận (để trống: đúng chỗ luật sư phải quyết). Hôm sau nữa anh Hùng gọi lại bằng một cách gõ số
     * khác; bản trùng đó được luật sư gộp vào bản đầu (R4, gộp vào bản CŨ hơn — Task 5).
     */
    private function opposingPair(): void
    {
        $first = $this->at($this->now->subDays(4)->setTime(9, 0), fn () => $this->record('luatsu1', [
            'contact_name' => 'Trịnh Văn Hùng',
            'contact_phone' => '0988 000 007',
            'contact_role' => PartyRole::Plaintiff,
            'source' => IntakeSource::WalkIn,
            'matter_type_id' => $this->type('DD'),
        ], [
            ['name' => 'Lưu Thị Nga', 'role' => PartyRole::Defendant, 'phone' => '0988 000 008'],
        ], story: 'Anh Hùng cho rằng hàng rào nhà chị Nga lấn sang thửa đất của mình khoảng 40 cm, đã hoà giải ở phường không thành.'));

        $this->at($this->now->subDays(4)->setTime(9, 45), fn () => $this->status('luatsu1', $first, IntakeStatus::Contacted));
        $this->at($this->now->subDays(3)->setTime(9, 30), fn () => $this->status('luatsu1', $first, IntakeStatus::Consulting));

        $second = $this->at($this->now->subDays(3)->setTime(14, 0), fn () => $this->record('troly2', [
            'contact_name' => 'Lưu Thị Nga',
            'contact_phone' => '0988000008',
            'contact_role' => PartyRole::Plaintiff,
            'source' => IntakeSource::Phone,
            'matter_type_id' => $this->type('DD'),
        ], [
            ['name' => 'Trịnh Văn Hùng', 'role' => PartyRole::Defendant, 'phone' => '0988000007'],
        ]));

        $this->at($this->now->subDays(3)->setTime(15, 0), fn () => $this->status('troly2', $second, IntakeStatus::Contacted));

        $repeat = $this->at($this->now->subDays(2)->setTime(11, 0), fn () => $this->record('troly1', [
            'contact_name' => 'Trịnh Văn Hùng',
            'contact_phone' => '+84 988 000 007',
            'contact_role' => PartyRole::Plaintiff,
            'source' => IntakeSource::Zalo,
            'assigned_to' => $this->staff['luatsu1']->id,
        ]));

        $this->at($this->now->subDays(2)->setTime(11, 30), fn () => app(MergeIntake::class)->handle(
            $this->staff['luatsu1'], $repeat->fresh(), $first->fresh(),
        ));
    }

    /**
     * Đỏ chờ trưởng phòng (R1): bên đối lập là khách hàng hiện hữu Vũ Thị Em (khớp SĐT). Thông báo đã
     * ghi nhận, câu chuyện khoá — người nhập thấy mã hồ sơ của khớp, không tiêu đề vụ; chưa ai từ chối hay
     * ghi đè.
     */
    private function redWaitingForManager(): void
    {
        $this->at($this->now->subHours(2), fn () => $this->record('troly2', [
            'contact_name' => 'Tôn Nữ Thanh Tranh',
            'contact_phone' => '0988 000 010',
            'contact_role' => PartyRole::Plaintiff,
            'source' => IntakeSource::Phone,
            'matter_type_id' => $this->type('DS'),
        ], [
            ['name' => 'Vũ Thị Em', 'role' => PartyRole::Defendant, 'phone' => '0907 890 123'],
        ]));
    }

    /** Vừa nhận qua Zalo, chưa tới hạn phản hồi: câu chuyện mở (Xanh) nhưng chưa ai ghi. */
    private function freshCall(): void
    {
        $this->at($this->now, fn () => $this->record('troly1', [
            'contact_name' => 'Lý Thị Mới Gọi',
            'contact_phone' => '0988 000 012',
            'contact_role' => PartyRole::Related,
            'source' => IntakeSource::Zalo,
        ]));
    }

    /**
     * Ghi một lần liên hệ (`RecordIntake`), ghi nhận thông báo (R7a), khi `$acknowledge` thì xác nhận
     * đã xem các khớp Vàng đang hiện (`AcknowledgeIntakeConflict`, đúng mức đã hiện — cổng R1), và — khi
     * `$story` có — ghi câu chuyện qua cổng thật (`UpdateIntakeSummary`; cổng đóng thì Action ném, seeder
     * hỏng lớn tiếng thay vì âm thầm bỏ câu chuyện).
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $parties
     */
    private function record(string $who, array $attributes, array $parties = [], ?string $story = null, bool $acknowledge = false): IntakeRequest
    {
        $actor = $this->staff[$who];
        $intake = app(RecordIntake::class)->handle($actor, $attributes, $parties)->intake;

        app(RecordPrivacyNotice::class)->handle($actor, $intake, true);

        if ($acknowledge) {
            app(AcknowledgeIntakeConflict::class)->handle($actor, $intake, $intake->conflict_level);
        }

        if ($story !== null) {
            app(UpdateIntakeSummary::class)->handle($actor, $intake->fresh(), $story);
        }

        return $intake->fresh();
    }

    private function status(string $who, IntakeRequest $intake, IntakeStatus $to): IntakeRequest
    {
        return app(ChangeIntakeStatus::class)->handle($this->staff[$who], $intake->fresh(), $to);
    }

    /**
     * Báo giá: ghi `quoted_amount` qua `UpdateIntakeIdentity` (đường duy nhất sửa phí đã báo — danh tính
     * gửi lại y nguyên nên không chạy lại kiểm tra), rồi chuyển `quoted`.
     */
    private function quote(string $who, IntakeRequest $intake, string $amount): void
    {
        $intake = $intake->fresh(['parties']);

        app(UpdateIntakeIdentity::class)->handle($this->staff[$who], $intake, [
            'contact_name' => $intake->contact_name,
            'contact_phone' => $intake->contact_phone,
            'contact_email' => $intake->contact_email,
            'contact_role' => $intake->contact_role,
            'source' => $intake->source,
            'referred_by' => $intake->referred_by,
            'matter_type_id' => $intake->matter_type_id,
            'assigned_to' => $intake->assigned_to,
            'quoted_amount' => $amount,
        ], $intake->parties->map(fn (IntakeParty $party): array => [
            'id' => $party->id,
            'name' => $party->name,
            'role' => $party->role,
        ])->all());

        $this->status($who, $intake, IntakeStatus::Quoted);
    }

    private function type(string $code): ?int
    {
        return MatterType::query()->where('code', $code)->value('id');
    }

    /**
     * Chạy `$work` với đồng hồ đặt ở `$moment`, rồi trả lại đồng hồ trước đó (null hay một đồng hồ test).
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function at(CarbonInterface $moment, Closure $work): mixed
    {
        $previous = Carbon::getTestNow();
        Carbon::setTestNow($moment);

        try {
            return $work();
        } finally {
            Carbon::setTestNow($previous);
        }
    }
}
