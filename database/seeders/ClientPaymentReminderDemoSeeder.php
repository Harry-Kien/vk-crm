<?php

namespace Database\Seeders;

use App\Actions\Billing\RescheduleInstalment;
use App\Enums\Confidentiality;
use App\Enums\ContractStatus;
use App\Enums\InstalmentState;
use App\Models\ClientUser;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Dữ liệu MẪU cho thư nhắc tiền của khách (làn nt Task 2) — để khi chủ văn phòng BẬT công tắc "Nhắc lịch
 * thanh toán cho khách" trên bản xem thử thì thấy ngay:
 *
 *  - **một đợt sắp đến hạn:** đợt đầu tiên còn chờ thu, đã có ngày hạn mà chưa quá hạn, của vụ đầu tiên
 *    đã công bố cổng có hợp đồng đang hiệu lực của khách demo `khach1@example.com` (vụ 1 của
 *    `BillingSeeder`: "Tạm ứng khi ký hợp đồng", hạn sau 7 ngày) được DỜI HẠN về hôm nay + 2 ngày — trong
 *    cửa sổ 3 ngày mặc định của thư báo trước hạn. Đi qua đúng Action của sản phẩm
 *    ({@see RescheduleInstalment}, người dời là quản trị viên demo, có lý do và dòng nhật ký), không ghi
 *    thẳng bảng. Đã đúng ngày thì không làm gì (chạy lại trong ngày là vô hại);
 *  - **một đợt quá hạn có nút "Gửi thư nhắc":** `BillingSeeder` đã có sẵn nhiều đợt quá hạn trên vụ
 *    thường đã công bố cổng, mà khách có tài khoản cổng đã kích hoạt (mọi khách demo đều có) — seeder này
 *    không thêm gì, chỉ hỏng to nếu điều đó thôi đúng (để bản xem thử không âm thầm mất phần trình diễn);
 *  - **thư trong hộp thư thử (Mailpit):** bấm "Gửi thư nhắc" trên đợt quá hạn (thư đi qua hàng đợi —
 *    lịch `queue.drain` mỗi phút), hoặc chạy ngay lượt báo trước hạn thay vì chờ 08:15:
 *    `bin/dev artisan schedule:test --name=client-payments.due-soon`.
 *
 * Công tắc KHÔNG bị bật ở đây — `ClientPaymentSettingsSeeder` ghi `'0'` và seeder này không chạm cấu hình.
 * Chỉ chạy trong {@see DemoDataSeeder}, sau `BillingSeeder` và `ClientPaymentSettingsSeeder`.
 */
class ClientPaymentReminderDemoSeeder extends Seeder
{
    public const DEMO_CLIENT_EMAIL = 'khach1@example.com';

    /** Hạn mới của đợt "sắp đến hạn" — trong cửa sổ báo trước mặc định (3 ngày). */
    public const DUE_SOON_IN_DAYS = 2;

    public function run(RescheduleInstalment $reschedule): void
    {
        $admin = User::query()->where('email', DemoAccountsSeeder::ADMIN_EMAIL)->firstOrFail();
        $demo = ClientUser::query()->where('email', self::DEMO_CLIENT_EMAIL)->firstOrFail();

        $matter = Matter::query()
            ->where('client_id', $demo->client_id)
            ->where('is_published_to_portal', true)
            ->where('confidentiality', Confidentiality::Normal->value)
            ->whereHas('contract', fn ($query) => $query->where('status', ContractStatus::Active->value))
            ->orderBy('id')
            ->firstOrFail();

        $target = today()->addDays(self::DUE_SOON_IN_DAYS)->toDateString();

        /** @var Instalment|null $instalment */
        $instalment = $matter->contract->instalments()
            ->whereNotNull('due_date')
            ->orderBy('sequence')
            ->get()
            ->each(fn (Instalment $row) => $row->setRelation('contract', $matter->contract))
            ->first(fn (Instalment $row): bool => in_array($row->state(), [InstalmentState::Due, InstalmentState::PartiallyPaid], true));

        if ($instalment === null) {
            throw new \RuntimeException('Dữ liệu mẫu: vụ của khách demo không còn đợt nào chờ thu để làm đợt "sắp đến hạn".');
        }

        if ($instalment->due_date->toDateString() !== $target) {
            $reschedule->handle(
                $admin,
                $instalment,
                $target,
                'Dữ liệu mẫu: dời hạn để bản xem thử có một đợt sắp đến hạn cho thư báo trước hạn của khách.',
            );
        }

        if (! Instalment::query()->overdue()->whereHas('contract.matter', fn ($query) => $query
            ->where('is_published_to_portal', true)
            ->where('confidentiality', Confidentiality::Normal->value))->exists()) {
            throw new \RuntimeException('Dữ liệu mẫu: không còn đợt quá hạn nào trên vụ đã công bố cổng để trình diễn nút "Gửi thư nhắc".');
        }
    }
}
