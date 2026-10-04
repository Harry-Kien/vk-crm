<?php

namespace Database\Seeders;

use App\Actions\Billing\ActivateContract;
use App\Actions\Billing\AmendContract;
use App\Actions\Billing\DraftContract;
use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\VoidPayment;
use App\Actions\Billing\WaiveInstalment;
use App\Actions\Matter\ReassignMatter;
use App\Enums\Confidentiality;
use App\Enums\InstalmentTrigger;
use App\Enums\PaymentMethod;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use App\Support\Billing\SplitByPercent;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * M9 Task 13 — tiền MẪU: hợp đồng, lịch thu, khoản thu, một phụ lục, một lần miễn, một khoản thu đã
 * huỷ và một lần bàn giao, đủ để trang doanh thu, trang "Công nợ", tab "Hợp đồng và thanh toán" và
 * khối tiền trên cổng khách vẽ ra một bức tranh THẬT (kế hoạch M9 Task 13 bước 1).
 *
 * **Chỉ là dữ liệu mẫu.** Gọi từ `DemoDataSeeder` (cuối danh sách, sau `MatterSeeder` — nó đọc vụ
 * việc của seeder đó), KHÔNG BAO GIỜ từ `ReferenceDataSeeder`: `DatabaseSeeder` chỉ chạy
 * `DemoDataSeeder` ở `local`/`testing`, nên `migrate:fresh --seed` trên máy chủ thật không có một
 * đồng tiền mẫu nào. Người cố ý `db:seed --class=DemoDataSeeder` trên máy chủ thật (demo cho khách,
 * `docs/CAI-DAT.md` Bước 5) nhận tiền mẫu cùng với vụ việc mẫu — cùng một quyết định.
 *
 * **Mọi đồng đi qua đúng các Action của sản phẩm** — `DraftContract`, `ActivateContract`,
 * `AmendContract`, `RecordPayment`, `VoidPayment`, `WaiveInstalment`, `ReassignMatter` — với actor
 * tường minh là người làm việc đó ở văn phòng thật: luật sư phụ trách soạn, kích hoạt, ký phụ lục và
 * miễn; kế toán ghi và huỷ khoản thu; riêng vụ `restricted` thì luật sư phụ trách tự ghi (P3); quản
 * trị viên bàn giao. Vì vậy bất biến tổng, `attributed_lawyer_id` (P2), `created_by`, trạng thái
 * `paid` và nhật ký do chính mã thật giữ, và `billing:check-invariants` chạy sạch trên dữ liệu này là
 * một phép đo chứ không phải một hằng số. Không factory, không `DB::table()`.
 *
 * **Ngày tháng lùi, tính từ ngày mở hồ sơ.** Action từ chối ngày ký/ngày thu ở tương lai, và seeder
 * không `travelTo()`: hợp đồng ký 3 ngày sau `opened_at` của vụ, tạm ứng về 2 ngày sau khi ký. Vụ
 * mẫu cũ nhất mở khoảng chín tháng trước (`MatterSeeder`, `30 + 12·i` ngày), nên tiền về rải trên ít
 * nhất tám tháng dương lịch, mỗi tháng có ít nhất một khoản, tới hôm nay.
 *
 * **Đợt theo giai đoạn chỉ gắn vào giai đoạn vụ CHƯA tới, hoặc giai đoạn có dòng `stage_logs` thật.**
 * `MatterSeeder` ghi `matters.stage` thẳng và dựng dòng tiến độ thưa (vào `intake`, `collecting_
 * documents`, đôi khi `drafting`), nên một vụ đang ở "Đã nộp đơn" không có dòng nào VÀO `filed` —
 * đúng tình huống "nhập hợp đồng đang chạy lúc go-live" (`docs/QUY-TRINH.md`): đợt của giai đoạn đã
 * qua mà không có dòng tiến độ thì nhập là `due_date`. `ActivateContract` kích hoạt ngay đợt của
 * giai đoạn vụ đã chạm (M9 Task 6) — không cần gọi đối chiếu cuối seed.
 *
 * **Chạy lại an toàn, từng vụ một:** vụ đã có hợp đồng (bất kể trạng thái) thì bỏ qua cả câu chuyện
 * tiền của nó — `contracts.matter_id` là unique, và một hợp đồng văn phòng tự soạn trên một vụ mẫu
 * không bao giờ bị đụng. Thiếu tài khoản hay vụ việc mẫu (seeder gọi lẻ, không qua
 * `DemoDataSeeder`) thì hỏng to ngay (`firstOrFail()`), như `MatterSeeder`.
 *
 * Các tình huống cố ý (mỗi mục một khẳng định ở `tests/Feature/Seeders/BillingSeederTest.php`):
 *  - mọi vụ đã rời giai đoạn đầu có hợp đồng `active`; vụ 9 (còn ở `intake`) có bản nháp; vụ 15
 *    (còn ở `intake`) cố ý không có hợp đồng;
 *  - giá trị 15.000.000 – 450.000.000 đ; thuế suất 8%, 10% hoặc không có;
 *  - lịch 30% khi ký / 40% khi nộp đơn / 30% khi xét xử sơ thẩm (vụ 1, 2, 10, 19, 20 và bản nháp vụ
 *    9), cộng đợt theo giai đoạn ở vụ 3, 4, 5, 11, 12, 13, 14, 17 và vụ `restricted` — 14 hợp đồng
 *    đang hiệu lực có ít nhất một đợt theo giai đoạn;
 *  - quá hạn theo ngày (vụ 6, và đợt cuối của vụ đã kết thúc), quá hạn theo giai đoạn (vụ 4 — dòng
 *    vào giai đoạn "Soạn đơn" có thật, cộng 15 ngày đã qua), thu một phần chưa tới hạn (vụ 13, 18);
 *  - một lần miễn có lý do đọc được (vụ 8), một khoản thu đã huỷ kèm lý do (vụ 7), một phụ lục tăng
 *    giá trị (vụ 16), một vụ đã kết thúc còn nợ, một vụ `restricted` có hợp đồng, một vụ bàn giao có
 *    khoản thu trước và sau (vụ 20), và vụ đầu của khách demo `khach1@example.com` (vụ 1, công bố
 *    cổng) có hợp đồng ký hôm nay — trang doanh thu ở kỳ mặc định "tháng này" không trống.
 */
class BillingSeeder extends Seeder
{
    private const ACCOUNTANT_EMAIL = 'ketoan@luatvukhang.com';

    /** Lịch ba đợt của kế hoạch, dùng cho các vụ dân sự chưa nộp đơn. */
    private const THIRTY_FORTY_THIRTY = [
        ['name' => 'Tạm ứng khi ký hợp đồng', 'percent' => 30, 'trigger' => InstalmentTrigger::OnSigning, 'due_days' => 7],
        ['name' => 'Thanh toán đợt 2 khi nộp đơn khởi kiện', 'percent' => 40, 'trigger' => InstalmentTrigger::Stage, 'stage' => 'filed', 'due_days' => 15],
        ['name' => 'Thanh toán đợt 3 khi toà xét xử sơ thẩm', 'percent' => 30, 'trigger' => InstalmentTrigger::Stage, 'stage' => 'first_instance', 'due_days' => 15],
    ];

    private User $admin;

    private User $accountant;

    public function run(): void
    {
        $this->admin = User::query()->where('email', DemoAccountsSeeder::ADMIN_EMAIL)->firstOrFail();
        $this->accountant = User::query()->where('email', self::ACCOUNTANT_EMAIL)->firstOrFail();
        $closed = Matter::query()->where('case_number', MatterSeeder::CLOSED_MATTER_CASE_NUMBER)->firstOrFail();
        $restricted = Matter::query()->where('confidentiality', Confidentiality::Restricted->value)->orderBy('id')->firstOrFail();

        // Vụ i (1..20) của `MatterSeeder`, theo đúng thứ tự tạo. Thiếu một vụ thì `stories()` hỏng
        // to ngay (chỉ số không có), như `MatterSeeder` hỏng to khi thiếu tài khoản demo.
        $numbered = Matter::query()
            ->where('confidentiality', Confidentiality::Normal->value)
            ->whereKeyNot($closed->getKey())
            ->orderBy('id')
            ->take(20)
            ->get()
            ->values();

        foreach ($this->stories($numbered, $restricted, $closed) as [$matter, $story]) {
            if (Contract::query()->where('matter_id', $matter->getKey())->exists()) {
                continue;
            }

            $story($matter->fresh());
        }
    }

    /**
     * Câu chuyện tiền của từng vụ, theo thứ tự — mã hợp đồng `HD-{năm}-{0001}` đi theo thứ tự này.
     *
     * @param  Collection<int, Matter>  $numbered
     * @return list<array{0: Matter, 1: callable(Matter): void}>
     */
    private function stories(Collection $numbered, Matter $restricted, Matter $closed): array
    {
        $vu = fn (int $i): Matter => $numbered[$i - 1];

        return [
            // Vụ 1 — khách demo `khach1`, công bố cổng: ký HÔM NAY, tạm ứng đến hạn sau 7 ngày, hai
            // đợt chờ bước "nộp đơn" và "xét xử sơ thẩm".
            [$vu(1), fn (Matter $m) => $this->signed($m, 60_000_000, 10, self::THIRTY_FORTY_THIRTY, today())],

            [$vu(2), function (Matter $m): void {
                $rows = $this->signed($m, 90_000_000, null, self::THIRTY_FORTY_THIRTY);
                $this->payInFull($rows[0], $this->afterSigning($m, 2), PaymentMethod::BankTransfer, 'UNC 2026-0211');
            }],

            // Vụ 3 — đã ở "Đã nộp đơn" mà không có dòng tiến độ vào bước đó: đợt 2 nhập theo ngày.
            [$vu(3), function (Matter $m): void {
                $rows = $this->signed($m, 35_000_000, 8, [
                    self::THIRTY_FORTY_THIRTY[0],
                    ['name' => 'Thanh toán đợt 2 khi nộp đơn khởi kiện', 'percent' => 40, 'trigger' => InstalmentTrigger::DueDate, 'due_date' => today()->subDays(30)],
                    self::THIRTY_FORTY_THIRTY[2],
                ]);
                $this->payInFull($rows[0], $this->afterSigning($m, 2), PaymentMethod::Cash, 'PT-0307');
                $this->payInFull($rows[1], today()->subDays(31), PaymentMethod::BankTransfer, 'UNC 2026-0388');
            }],

            // Vụ 4 — QUÁ HẠN THEO GIAI ĐOẠN: đợt 2 chờ bước "Soạn đơn", vụ đã vào bước đó (dòng tiến độ
            // thật của MatterSeeder, 48 ngày trước) — kích hoạt lúc ký hợp đồng, hạn +15 ngày đã qua.
            [$vu(4), function (Matter $m): void {
                $rows = $this->signed($m, 48_000_000, null, [
                    self::THIRTY_FORTY_THIRTY[0],
                    ['name' => 'Thanh toán đợt 2 khi bắt đầu soạn đơn khởi kiện', 'percent' => 40, 'trigger' => InstalmentTrigger::Stage, 'stage' => 'drafting', 'due_days' => 15],
                    self::THIRTY_FORTY_THIRTY[2],
                ]);
                $this->payInFull($rows[0], $this->afterSigning($m, 2), PaymentMethod::BankTransfer, 'UNC 2026-0412');
            }],

            [$vu(5), function (Matter $m): void {
                $rows = $this->signed($m, 25_000_000, 10, [
                    ['name' => 'Tạm ứng khi ký hợp đồng', 'percent' => 50, 'trigger' => InstalmentTrigger::OnSigning, 'due_days' => 7],
                    ['name' => 'Thanh toán phần còn lại khi nộp hồ sơ cho cơ quan đăng ký', 'percent' => 50, 'trigger' => InstalmentTrigger::Stage, 'stage' => 'submitted', 'due_days' => 7],
                ]);
                $this->payInFull($rows[0], $this->afterSigning($m, 2), PaymentMethod::Card, 'POS 558120');
            }],

            // Vụ 6 — QUÁ HẠN THEO NGÀY: đợt 2 hẹn 40 ngày trước, chưa thu đồng nào.
            [$vu(6), function (Matter $m): void {
                $rows = $this->signed($m, 150_000_000, 10, [
                    self::THIRTY_FORTY_THIRTY[0],
                    ['name' => 'Thanh toán đợt 2 khi nộp đơn khởi kiện', 'percent' => 40, 'trigger' => InstalmentTrigger::DueDate, 'due_date' => today()->subDays(40)],
                    ['name' => 'Thanh toán đợt 3 khi có bản án sơ thẩm', 'percent' => 30, 'trigger' => InstalmentTrigger::DueDate, 'due_date' => today()->addDays(20)],
                ]);
                $this->payInFull($rows[0], $this->afterSigning($m, 2), PaymentMethod::BankTransfer, 'UNC 2026-0602');
            }],

            // Vụ 7 — KHOẢN THU ĐÃ HUỶ: kế toán gõ thiếu một chữ số 0, huỷ kèm lý do, ghi lại đúng.
            [$vu(7), function (Matter $m): void {
                $rows = $this->signed($m, 120_000_000, 8, [
                    self::THIRTY_FORTY_THIRTY[0],
                    ['name' => 'Thanh toán đợt 2 khi nộp đơn khởi kiện', 'percent' => 40, 'trigger' => InstalmentTrigger::DueDate, 'due_date' => today()->subDays(60)],
                    ['name' => 'Thanh toán đợt 3 khi có bản án phúc thẩm', 'percent' => 30, 'trigger' => InstalmentTrigger::DueDate, 'due_date' => today()->addDays(15)],
                ]);
                $this->payInFull($rows[0], $this->afterSigning($m, 2), PaymentMethod::BankTransfer, 'UNC 2026-0705');

                $mistake = $this->pay($rows[1], 4_800_000, today()->subDays(58), PaymentMethod::BankTransfer, 'UNC 2026-0731');
                app(VoidPayment::class)->handle(
                    $this->accountant,
                    $mistake,
                    'Gõ nhầm số tiền: thiếu một chữ số 0 so với uỷ nhiệm chi 48.000.000 đ của khách; đã ghi lại khoản đúng.',
                );
                $this->payInFull($rows[1], today()->subDays(58), PaymentMethod::BankTransfer, 'UNC 2026-0731');
            }],

            // Vụ 8 — MIỄN đợt cuối, kèm lý do thật.
            [$vu(8), function (Matter $m): void {
                $rows = $this->signed($m, 80_000_000, null, [
                    self::THIRTY_FORTY_THIRTY[0],
                    ['name' => 'Thanh toán đợt 2 khi nộp đơn khởi kiện', 'percent' => 40, 'trigger' => InstalmentTrigger::DueDate, 'due_date' => today()->subDays(45)],
                    ['name' => 'Thanh toán đợt cuối khi bắt đầu thi hành án', 'percent' => 30, 'trigger' => InstalmentTrigger::DueDate, 'due_date' => today()->subDays(5)],
                ]);
                $this->payInFull($rows[0], $this->afterSigning($m, 2), PaymentMethod::Cash, 'PT-0811');
                $this->payInFull($rows[1], today()->subDays(44), PaymentMethod::BankTransfer, 'UNC 2026-0846');

                app(WaiveInstalment::class)->handle(
                    $m->leadLawyer,
                    $rows[2]->fresh(),
                    'Khách hàng mất việc sau khi bản án có hiệu lực và gặp khó khăn tài chính; luật sư phụ trách đề xuất, văn phòng đồng ý miễn đợt cuối.',
                );
            }],

            // Vụ 9 — còn ở "Tiếp nhận": BẢN NHÁP, chưa ký.
            [$vu(9), fn (Matter $m) => $this->draft($m, 45_000_000, 10, self::THIRTY_FORTY_THIRTY)],

            [$vu(10), function (Matter $m): void {
                $rows = $this->signed($m, 200_000_000, 10, self::THIRTY_FORTY_THIRTY);
                $this->payInFull($rows[0], $this->afterSigning($m, 2), PaymentMethod::BankTransfer, 'UNC 2026-1003');
            }],

            // Vụ 11 — đợt theo giai đoạn ĐÃ kích hoạt (dòng "Soạn đơn" thật) và đã thu đủ trước hạn.
            [$vu(11), function (Matter $m): void {
                $rows = $this->signed($m, 40_000_000, null, [
                    self::THIRTY_FORTY_THIRTY[0],
                    ['name' => 'Thanh toán đợt 2 khi bắt đầu soạn đơn khởi kiện', 'percent' => 40, 'trigger' => InstalmentTrigger::Stage, 'stage' => 'drafting', 'due_days' => 10],
                    self::THIRTY_FORTY_THIRTY[2],
                ]);
                $this->payInFull($rows[0], $this->afterSigning($m, 2), PaymentMethod::Cash, 'PT-1104');
                $this->payInFull($rows[1], $rows[1]->fresh()->due_date->copy()->subDays(5), PaymentMethod::BankTransfer, 'UNC 2026-1132');
            }],

            // Vụ 12 — giá trị lớn nhất; tạm ứng trả làm hai lần.
            [$vu(12), function (Matter $m): void {
                $rows = $this->signed($m, 450_000_000, 10, [
                    self::THIRTY_FORTY_THIRTY[0],
                    ['name' => 'Thanh toán đợt 2 khi nộp đơn khởi kiện', 'percent' => 40, 'trigger' => InstalmentTrigger::DueDate, 'due_date' => today()->subDays(20)],
                    self::THIRTY_FORTY_THIRTY[2],
                ]);
                $this->pay($rows[0], 100_000_000, $this->afterSigning($m, 2), PaymentMethod::BankTransfer, 'UNC 2026-1205');
                $this->payInFull($rows[0], $this->afterSigning($m, 9), PaymentMethod::BankTransfer, 'UNC 2026-1219');
                $this->payInFull($rows[1], today()->subDays(18), PaymentMethod::BankTransfer, 'UNC 2026-1288');
            }],

            // Vụ 13 — THU MỘT PHẦN, chưa tới hạn.
            [$vu(13), function (Matter $m): void {
                $rows = $this->signed($m, 100_000_000, 8, [
                    self::THIRTY_FORTY_THIRTY[0],
                    ['name' => 'Thanh toán đợt 2 sau khi toà thụ lý vụ án', 'percent' => 40, 'trigger' => InstalmentTrigger::DueDate, 'due_date' => today()->addDays(10)],
                    self::THIRTY_FORTY_THIRTY[2],
                ]);
                $this->payInFull($rows[0], $this->afterSigning($m, 2), PaymentMethod::BankTransfer, 'UNC 2026-1307');
                $this->pay($rows[1], 12_500_000, today()->subDays(3), PaymentMethod::Cash, 'PT-1390');
            }],

            [$vu(14), function (Matter $m): void {
                $rows = $this->signed($m, 15_000_000, null, [
                    ['name' => 'Tạm ứng khi ký hợp đồng', 'percent' => 50, 'trigger' => InstalmentTrigger::OnSigning, 'due_days' => 7],
                    ['name' => 'Thanh toán phần còn lại khi toà xét xử sơ thẩm', 'percent' => 50, 'trigger' => InstalmentTrigger::Stage, 'stage' => 'first_instance', 'due_days' => 15],
                ]);
                $this->payInFull($rows[0], $this->afterSigning($m, 2), PaymentMethod::Cash, 'PT-1402');
            }],

            // Vụ 15 — còn ở "Tiếp nhận", CỐ Ý không có hợp đồng.

            // Vụ 16 — PHỤ LỤC: đã thu đủ ba đợt, rồi ký phụ lục đại diện thêm ở cấp phúc thẩm.
            [$vu(16), function (Matter $m): void {
                $rows = $this->signed($m, 70_000_000, 10, [
                    self::THIRTY_FORTY_THIRTY[0],
                    ['name' => 'Thanh toán đợt 2 khi nộp đơn khởi kiện', 'percent' => 40, 'trigger' => InstalmentTrigger::DueDate, 'due_date' => today()->subDays(90)],
                    ['name' => 'Thanh toán đợt 3 khi có bản án sơ thẩm', 'percent' => 30, 'trigger' => InstalmentTrigger::DueDate, 'due_date' => today()->subDays(70)],
                ]);
                $this->payInFull($rows[0], $this->afterSigning($m, 2), PaymentMethod::BankTransfer, 'UNC 2026-1604');
                $this->payInFull($rows[1], today()->subDays(88), PaymentMethod::BankTransfer, 'UNC 2026-1652');
                $this->payInFull($rows[2], today()->subDays(69), PaymentMethod::BankTransfer, 'UNC 2026-1673');

                app(AmendContract::class)->handle(
                    $m->leadLawyer,
                    $m->contract()->firstOrFail(),
                    100_000_000,
                    [[
                        'action' => AmendContract::ADD,
                        'name' => 'Phí đại diện ở cấp phúc thẩm (phụ lục 01)',
                        'amount' => 30_000_000,
                        'trigger_type' => InstalmentTrigger::DueDate,
                        'due_date' => today()->addDays(30)->toDateString(),
                    ]],
                    'Khách đề nghị văn phòng tiếp tục đại diện ở cấp phúc thẩm sau khi có bản án sơ thẩm; hai bên ký phụ lục bổ sung phạm vi công việc và phí.',
                    today()->subDays(20),
                );
            }],

            [$vu(17), function (Matter $m): void {
                $rows = $this->signed($m, 30_000_000, 8, [
                    ['name' => 'Tạm ứng khi ký hợp đồng', 'percent' => 50, 'trigger' => InstalmentTrigger::OnSigning, 'due_days' => 7],
                    ['name' => 'Thanh toán phần còn lại khi nộp hồ sơ cho cơ quan đăng ký', 'percent' => 50, 'trigger' => InstalmentTrigger::Stage, 'stage' => 'submitted', 'due_days' => 7],
                ]);
                $this->payInFull($rows[0], $this->afterSigning($m, 2), PaymentMethod::BankTransfer, 'UNC 2026-1703');
            }],

            // Vụ 18 — khách trả dần đợt 2 trước hạn: thu một phần, chưa tới hạn.
            [$vu(18), function (Matter $m): void {
                $rows = $this->signed($m, 250_000_000, null, [
                    ['name' => 'Tạm ứng khi ký hợp đồng', 'percent' => 50, 'trigger' => InstalmentTrigger::OnSigning, 'due_days' => 7],
                    ['name' => 'Thanh toán phần còn lại trước phiên xét xử sơ thẩm', 'percent' => 50, 'trigger' => InstalmentTrigger::DueDate, 'due_date' => today()->addDays(25)],
                ]);
                $this->payInFull($rows[0], $this->afterSigning($m, 2), PaymentMethod::BankTransfer, 'UNC 2026-1806');
                $this->pay($rows[1], 50_000_000, today()->subDays(100), PaymentMethod::BankTransfer, 'UNC 2026-1841');
            }],

            [$vu(19), function (Matter $m): void {
                $rows = $this->signed($m, 180_000_000, 10, self::THIRTY_FORTY_THIRTY);
                $this->payInFull($rows[0], $this->afterSigning($m, 2), PaymentMethod::BankTransfer, 'UNC 2026-1902');
            }],

            // Vụ 20 — BÀN GIAO (P2): một phần tạm ứng về khi luật sư cũ phụ trách, quản trị viên bàn
            // giao vụ hôm nay (giữ luật sư cũ trong đội), phần còn lại về sau đó — ghi cho luật sư mới.
            [$vu(20), function (Matter $m): void {
                $rows = $this->signed($m, 300_000_000, 10, self::THIRTY_FORTY_THIRTY);
                $this->pay($rows[0], 50_000_000, $this->afterSigning($m, 2), PaymentMethod::BankTransfer, 'UNC 2026-2004');

                $newLead = User::query()->where('email', 'luatsu3@luatvukhang.com')->firstOrFail();
                app(ReassignMatter::class)->handle(
                    matter: $m,
                    actor: $this->admin,
                    newLead: $newLead,
                    reason: 'Luật sư phụ trách nghỉ phép dài ngày; bàn giao vụ để kịp hạn nộp đơn khởi kiện.',
                    keepOldLeadAsAssociate: true,
                    sendDigest: false,
                );

                $this->payInFull($rows[0], today(), PaymentMethod::BankTransfer, 'UNC 2026-2077');
            }],

            // Vụ `restricted` (P3) — chỉ luật sư phụ trách và quản trị viên thấy; chính luật sư phụ
            // trách ghi khoản thu (không ai khác ngoài admin thấy vụ để ghi).
            [$restricted, function (Matter $m): void {
                $rows = $this->signed($m, 120_000_000, 10, [
                    ['name' => 'Tạm ứng khi ký hợp đồng', 'percent' => 50, 'trigger' => InstalmentTrigger::OnSigning, 'due_days' => 7],
                    ['name' => 'Thanh toán phần còn lại khi nộp đơn khởi kiện', 'percent' => 50, 'trigger' => InstalmentTrigger::Stage, 'stage' => 'filed', 'due_days' => 15],
                ], $m->opened_at->copy()->addDays(2));
                $this->payInFull($rows[0], $this->afterSigning($m, 2), PaymentMethod::Cash, 'PT-R001', recorder: $m->leadLawyer);
            }],

            // Vụ ĐÃ KẾT THÚC còn công nợ: đợt cuối hẹn 10 ngày trước, chưa thu.
            [$closed, function (Matter $m): void {
                $rows = $this->signed($m, 65_000_000, 8, [
                    self::THIRTY_FORTY_THIRTY[0],
                    ['name' => 'Thanh toán đợt 2 khi nộp đơn khởi kiện', 'percent' => 40, 'trigger' => InstalmentTrigger::DueDate, 'due_date' => today()->subDays(100)],
                    ['name' => 'Thanh toán đợt cuối khi có bản án', 'percent' => 30, 'trigger' => InstalmentTrigger::DueDate, 'due_date' => today()->subDays(10)],
                ]);
                $this->payInFull($rows[0], $this->afterSigning($m, 2), PaymentMethod::BankTransfer, 'UNC 2026-9903');
                $this->payInFull($rows[1], today()->subDays(98), PaymentMethod::BankTransfer, 'UNC 2026-9951');
            }],
        ];
    }

    /**
     * Soạn bản nháp (luật sư phụ trách) — phần trăm của từng dòng thành số đồng qua
     * `SplitByPercent::split()` (phần dư rơi vào đợt cuối, đúng như form soạn hợp đồng).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function draft(Matter $matter, int $total, ?int $vatRate, array $rows): Contract
    {
        $amounts = SplitByPercent::split($total, array_column($rows, 'percent'));

        $instalments = [];

        foreach (array_values($rows) as $index => $row) {
            $instalments[] = [
                'name' => $row['name'],
                'amount' => $amounts[$index],
                'percent_basis' => (string) $row['percent'],
                'trigger_type' => $row['trigger'],
                'trigger_stage_key' => $row['stage'] ?? null,
                'due_days_after_trigger' => $row['due_days'] ?? 0,
                'due_date' => isset($row['due_date']) ? $row['due_date']->toDateString() : null,
            ];
        }

        return app(DraftContract::class)->handle($matter->leadLawyer, $matter, [
            'total_amount' => $total,
            'vat_rate_percent' => $vatRate,
        ], $instalments);
    }

    /**
     * Soạn rồi kích hoạt (luật sư phụ trách ghi nhận việc ký) — mặc định ký 3 ngày sau ngày mở hồ
     * sơ. Trả các đợt theo thứ tự.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<Instalment>
     */
    private function signed(Matter $matter, int $total, ?int $vatRate, array $rows, ?CarbonInterface $signedOn = null): array
    {
        $contract = $this->draft($matter, $total, $vatRate, $rows);

        app(ActivateContract::class)->handle(
            $matter->leadLawyer,
            $contract,
            $signedOn ?? $matter->opened_at->copy()->addDays(3),
        );

        return $contract->instalments()->orderBy('sequence')->get()->all();
    }

    /**
     * `$days` ngày sau ngày ký hợp đồng của vụ (đọc lại từ CSDL). Mọi ngày ở đây đều đã qua với vụ
     * mẫu (vụ mới nhất mở 10 ngày trước); một ngày tương lai thì `RecordPayment` từ chối — hỏng to,
     * không lặng lẽ kẹp về hôm nay.
     */
    private function afterSigning(Matter $matter, int $days): CarbonInterface
    {
        return $matter->contract()->firstOrFail()->signed_at->copy()->addDays($days);
    }

    /** Một khoản thu — kế toán ghi, trừ khi `$recorder` được nêu (luật sư phụ trách vụ `restricted`). */
    private function pay(Instalment $instalment, int $amount, CarbonInterface $paidOn, PaymentMethod $method, string $reference, ?User $recorder = null): Payment
    {
        return app(RecordPayment::class)->handle(
            $recorder ?? $this->accountant,
            $instalment->fresh(),
            $amount,
            $paidOn,
            $method,
            $reference,
            null,
            null,
        );
    }

    /** Thu nốt phần còn lại của đợt — `Instalment::outstanding()` đọc lại số đã thu từ CSDL. */
    private function payInFull(Instalment $instalment, CarbonInterface $paidOn, PaymentMethod $method, string $reference, ?User $recorder = null): Payment
    {
        $fresh = $instalment->fresh();

        return $this->pay($fresh, $fresh->outstanding(), $paidOn, $method, $reference, $recorder);
    }
}
