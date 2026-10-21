<?php

namespace Database\Seeders;

use App\Actions\Billing\ActivateContract;
use App\Actions\Billing\DraftContract;
use App\Actions\Billing\RecordPayment;
use App\Actions\Intake\ChangeIntakeStatus;
use App\Actions\Intake\ConvertIntakeToMatter;
use App\Actions\Intake\HandIntakeToLawyer;
use App\Actions\Intake\RecordIntake;
use App\Actions\Intake\RecordPrivacyNotice;
use App\Actions\Intake\UpdateIntakeIdentity;
use App\Enums\ClientType;
use App\Enums\Confidentiality;
use App\Enums\InstalmentTrigger;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\PartyRole;
use App\Enums\PaymentMethod;
use App\Models\Instalment;
use App\Models\IntakeParty;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use App\Support\Normalizer;
use App\Support\Scopes\ClientPortalScope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Dữ liệu mẫu "tiền do nhân viên kinh doanh đem về" (2026-10-21): hai lead của nhân viên kinh doanh mẫu
 * (`kinhdoanh@`) thành hồ sơ CÓ TIỀN, để trên bản demo chủ văn phòng chọn "Nhân viên kinh doanh" ở trang Doanh
 * thu (hay chuyển "Doanh thu theo người" sang "Kinh doanh") mà thấy số thật. Luật: mọi số của hồ sơ mở từ một
 * bản ghi tiếp nhận người đó được giao (`App\Support\Billing\SalesAttribution`).
 *
 * **Mọi bước đi qua đúng Action của sản phẩm**, như `IntakeSeeder`/`BillingSeeder`: nhân viên kinh doanh ghi và
 * tự nhận lead (`RecordIntake`, `RecordPrivacyNotice`), gọi lại, tư vấn, "Chuyển cho luật sư"
 * (`HandIntakeToLawyer`), báo phí (`UpdateIntakeIdentity` + `ChangeIntakeStatus`); LUẬT SƯ chuyển thành hồ sơ
 * (`ConvertIntakeToMatter`, khách mới) — chuyển đổi vẫn là việc của luật sư — rồi soạn và ghi nhận ký hợp đồng
 * (`DraftContract`, `ActivateContract`); KẾ TOÁN ghi khoản thu (`RecordPayment`). Không factory, không câu SQL
 * thẳng nào: `assigned_to`, `matter_id`, `attributed_lawyer_id`, nhật ký… do chính mã thật đặt.
 *
 *  - Lead 1 (Ngô Thanh Tùng, Zalo): luật sư Vũ Đức Khang (`luatsu1`) chuyển thành hồ sơ 40 ngày trước; hợp
 *    đồng 60.000.000 đ ký 38 ngày trước, tạm ứng 50% về 36 ngày trước, phần còn lại về HÔM NAY.
 *  - Lead 2 (Trịnh Văn Hậu, website): luật sư Phạm Thu Hà (`luatsu2`) chuyển thành hồ sơ 12 ngày trước; hợp
 *    đồng 40.000.000 đ ký 10 ngày trước, tạm ứng 50% về HÔM NAY, 50% còn phải thu, đến hạn sau 20 ngày.
 *
 * Nên ở kỳ mặc định "Tháng này" nhân viên kinh doanh mẫu luôn có tiền đã thu (hai khoản về hôm nay) và còn phải
 * thu; theo năm có cả khoản tạm ứng tháng trước. Mỗi lead một bên đối lập (`0977 000 2xx`), sang hồ sơ khi chuyển
 * đổi. SĐT người liên hệ `0966 300 2xx` và bên đối lập không trùng khách, bên của vụ hay lead mẫu nào (dải
 * `0966 300 1xx` là năm lead của `IntakeSeeder::salesLeads()`, `0977 000 0xx` là bên đối lập của M10), nên cả hai
 * Xanh. Thêm 2 vụ và 2 khách (không tài khoản cổng) vào dữ liệu mẫu: 29 vụ, 14 khách (SPEC §12, đính chính 2026-10-21).
 *
 * **Chạy lại không thêm gì**: đã có bản ghi tiếp nhận mang SĐT của lead 1 (kể cả đã xoá mềm) thì dừng. Chạy
 * được trên một CSDL demo đã có sẵn (không cần `migrate:fresh`) — `.superpowers/sdd/rbp/demo-sales.php` gọi
 * đúng lớp này. Thiếu tài khoản mẫu thì hỏng to (`firstOrFail()`), như các seeder mẫu khác.
 */
class SalesRevenueDemoSeeder extends Seeder
{
    public const SALES_EMAIL = 'kinhdoanh@luatvukhang.com';

    /** SĐT của hai lead, theo thứ tự. */
    public const PHONES = ['0966 300 201', '0966 300 202'];

    private const ACCOUNTANT_EMAIL = 'ketoan@luatvukhang.com';

    /** @var array<string, User> */
    private array $staff = [];

    public function run(): void
    {
        $phones = array_map(fn (string $phone): ?string => Normalizer::phone($phone), self::PHONES);

        if (IntakeRequest::query()->withoutGlobalScope(ClientPortalScope::class)->withTrashed()->whereIn('contact_phone_normalized', $phones)->exists()) {
            return;
        }

        foreach (['kinhdoanh' => self::SALES_EMAIL, 'luatsu1' => 'luatsu1@luatvukhang.com', 'luatsu2' => 'luatsu2@luatvukhang.com', 'ketoan' => self::ACCOUNTANT_EMAIL] as $key => $email) {
            $this->staff[$key] = User::query()->where('email', $email)->firstOrFail();
        }

        $now = CarbonImmutable::now();

        $this->story(
            name: 'Ngô Thanh Tùng',
            phone: self::PHONES[0],
            opposing: ['name' => 'Hộ ông Lâm Văn Thịnh', 'role' => PartyRole::Defendant, 'phone' => '0977 000 201'],
            source: IntakeSource::Zalo,
            typeCode: 'DD',
            quote: '60.000.000',
            lawyer: $this->staff['luatsu1'],
            title: 'Tranh chấp ranh giới đất với hộ liền kề tại xã Phước Kiển',
            receivedAt: $now->subDays(46)->setTime(9, 20),
            convertedAt: $now->subDays(40)->setTime(10, 0),
            total: 60_000_000,
            signedDaysAgo: 38,
            payments: [[0, 36, 'UNC 2026-KD01'], [1, 0, 'UNC 2026-KD02']],
        );

        $this->story(
            name: 'Trịnh Văn Hậu',
            phone: self::PHONES[1],
            opposing: ['name' => 'Bà Đoàn Thị Mai', 'role' => PartyRole::Defendant, 'phone' => '0977 000 202'],
            source: IntakeSource::WebsiteForm,
            typeCode: 'HN',
            quote: '40.000.000',
            lawyer: $this->staff['luatsu2'],
            title: 'Ly hôn thuận tình và thoả thuận nuôi con',
            receivedAt: $now->subDays(16)->setTime(14, 10),
            convertedAt: $now->subDays(12)->setTime(15, 0),
            total: 40_000_000,
            signedDaysAgo: 10,
            payments: [[0, 0, 'UNC 2026-KD03']],
        );
    }

    /**
     * Một lead từ lúc nhân viên kinh doanh nhận tới lúc tiền về. `$payments`: mỗi khoản `[đợt (0|1), số ngày
     * trước hôm nay, số tham chiếu]` — thu nốt đợt đó. `$opposing`: một bên đối lập (tên, vai, SĐT), ghi lúc tiếp
     * nhận và sang hồ sơ khi chuyển đổi, như mọi vụ mẫu (hai tới bốn bên).
     *
     * @param  array{name: string, role: PartyRole, phone: string}  $opposing
     * @param  list<array{0: int, 1: int, 2: string}>  $payments
     */
    private function story(
        string $name,
        string $phone,
        array $opposing,
        IntakeSource $source,
        string $typeCode,
        string $quote,
        User $lawyer,
        string $title,
        CarbonImmutable $receivedAt,
        CarbonImmutable $convertedAt,
        int $total,
        int $signedDaysAgo,
        array $payments,
    ): void {
        $sales = $this->staff['kinhdoanh'];
        $typeId = MatterType::query()->where('code', $typeCode)->value('id');

        $intake = $this->at($receivedAt, function () use ($sales, $name, $phone, $opposing, $source, $typeId): IntakeRequest {
            $intake = app(RecordIntake::class)->handle($sales, [
                'contact_name' => $name,
                'contact_phone' => $phone,
                'contact_role' => PartyRole::Plaintiff,
                'source' => $source,
                'matter_type_id' => $typeId,
                'assigned_to' => $sales->id,
            ], [$opposing])->intake;

            app(RecordPrivacyNotice::class)->handle($sales, $intake, true);

            return $intake->fresh();
        });

        $this->at($receivedAt->addHour(), fn () => app(ChangeIntakeStatus::class)->handle($sales, $intake->fresh(), IntakeStatus::Contacted));
        $this->at($receivedAt->addDay(), fn () => app(ChangeIntakeStatus::class)->handle($sales, $intake->fresh(), IntakeStatus::Consulting));
        $this->at($receivedAt->addDay()->addMinutes(10), fn () => app(HandIntakeToLawyer::class)->handle($sales, $intake->fresh(), $lawyer));

        $this->at($receivedAt->addDays(2), function () use ($sales, $intake, $quote): void {
            $fresh = $intake->fresh(['parties']);

            // Danh tính gửi lại y nguyên (kể cả bên đối lập, theo id) — chỉ phí đã báo đổi, như `IntakeSeeder::quote()`.
            app(UpdateIntakeIdentity::class)->handle($sales, $fresh, [
                'contact_name' => $fresh->contact_name,
                'contact_phone' => $fresh->contact_phone,
                'contact_email' => $fresh->contact_email,
                'contact_role' => $fresh->contact_role,
                'source' => $fresh->source,
                'referred_by' => $fresh->referred_by,
                'matter_type_id' => $fresh->matter_type_id,
                'assigned_to' => $fresh->assigned_to,
                'quoted_amount' => $quote,
            ], $fresh->parties->map(fn (IntakeParty $party): array => [
                'id' => $party->id,
                'name' => $party->name,
                'role' => $party->role,
            ])->all());

            app(ChangeIntakeStatus::class)->handle($sales, $intake->fresh(), IntakeStatus::Quoted);
        });

        // Chuyển thành hồ sơ: việc của luật sư được chuyển lead (khách mới — SĐT không trùng hồ sơ khách nào).
        $matter = $this->at($convertedAt, fn (): Matter => app(ConvertIntakeToMatter::class)->handle($lawyer, $intake->fresh(), [
            'title' => $title,
            'matter_type_id' => $typeId,
            'lead_lawyer_id' => $lawyer->id,
            'client_role' => PartyRole::Plaintiff,
            'opened_at' => now()->toDateString(),
            'confidentiality' => Confidentiality::Normal,
            'client_type' => ClientType::Individual,
        ])->opening->matter);

        $signedOn = today()->subDays($signedDaysAgo);
        $half = intdiv($total, 2);

        $contract = $this->at($signedOn->copy()->setTime(9, 0), function () use ($lawyer, $matter, $total, $half, $signedOn) {
            $draft = app(DraftContract::class)->handle($lawyer, $matter->fresh(), [
                'total_amount' => $total,
                'vat_rate_percent' => null,
            ], [
                [
                    'name' => 'Tạm ứng khi ký hợp đồng',
                    'amount' => $half,
                    'percent_basis' => '50',
                    'trigger_type' => InstalmentTrigger::OnSigning,
                    'trigger_stage_key' => null,
                    'due_days_after_trigger' => 7,
                    'due_date' => null,
                ],
                [
                    'name' => 'Thanh toán phần còn lại',
                    'amount' => $total - $half,
                    'percent_basis' => '50',
                    'trigger_type' => InstalmentTrigger::DueDate,
                    'trigger_stage_key' => null,
                    'due_days_after_trigger' => 0,
                    'due_date' => today()->addDays(30)->toDateString(),
                ],
            ]);

            return app(ActivateContract::class)->handle($lawyer, $draft, $signedOn);
        });

        /** @var list<Instalment> $instalments */
        $instalments = $contract->instalments()->orderBy('sequence')->get()->all();

        foreach ($payments as [$index, $daysAgo, $reference]) {
            $instalment = $instalments[$index]->fresh();

            app(RecordPayment::class)->handle(
                $this->staff['ketoan'],
                $instalment,
                $instalment->outstanding(),
                today()->subDays($daysAgo),
                PaymentMethod::BankTransfer,
                $reference,
                null,
                null,
            );
        }
    }

    /**
     * Chạy `$work` với đồng hồ đặt ở `$moment`, rồi trả lại đồng hồ trước đó — cùng cách của `IntakeSeeder::at()`.
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
