<?php

namespace Database\Seeders;

use App\Actions\Settings\UpdateClientPaymentSettings;
use App\Actions\Settings\WriteSettings;
use App\Support\Billing\ClientPaymentSettings;
use Illuminate\Database\Seeder;

/**
 * Dữ liệu MẪU của "Nhắc lịch thanh toán cho khách" (làn nt Task 1): thông tin chuyển khoản RÕ LÀ GIẢ
 * (số {@see ClientPaymentSettings::DEMO_ACCOUNT_NUMBER}, chủ tài khoản
 * {@see ClientPaymentSettings::DEMO_ACCOUNT_HOLDER}), để bản xem thử có sẵn thứ cần điền khi chủ văn
 * phòng muốn bật công tắc — và công tắc vẫn **TẮT** (ghi tường minh `'0'`), đúng mặc định chủ văn phòng
 * đã chọn.
 *
 * **Chỉ ghi khi chưa có gì được cấu hình** ({@see ClientPaymentSettings::hasAnyStoredValue()}): đã có
 * bất kỳ giá trị nào của sáu trường (thông tin chuyển khoản thật, công tắc, số ngày báo trước) thì seeder
 * không chạm gì — không đè tài khoản thật bằng tài khoản giả, không tắt công tắc văn phòng đã bật. Nhờ
 * thế chạy lại seeder (hay `db:seed --class=DemoDataSeeder` trên máy chủ thật) cũng vô hại.
 *
 * Bật để xem thử trên máy dev (`APP_ENV=local`): đăng nhập quản trị → "Nhắc thanh toán cho khách" → bật
 * công tắc → Lưu. Mọi môi trường khác (kể cả máy chủ thật có dữ liệu mẫu) không cho bật trên tài khoản
 * giả này ({@see UpdateClientPaymentSettings}, mục "Không bật trên tài khoản của dữ liệu mẫu"): thay bằng
 * tài khoản thật của văn phòng và quét mã QR xem thử trên trang trước.
 *
 * Chỉ chạy trong {@see DemoDataSeeder} (máy dev, bộ test, hay `--class` tường minh), không bao giờ trong
 * `ReferenceDataSeeder`: một bản cài thật không được nhận số tài khoản giả. Ghi qua bước ghi chung
 * {@see WriteSettings} (không actor — dòng `updated_by` để trống), không qua Action của trang: seeder
 * không có quản trị viên đăng nhập, và không cần dòng nhật ký "ai đổi cấu hình".
 */
class ClientPaymentSettingsSeeder extends Seeder
{
    public const DEMO_ACCOUNT_NUMBER = ClientPaymentSettings::DEMO_ACCOUNT_NUMBER;

    public function run(WriteSettings $writeSettings): void
    {
        if (ClientPaymentSettings::current()->hasAnyStoredValue()) {
            return;
        }

        $writeSettings->handle([
            ClientPaymentSettings::ENABLED => ClientPaymentSettings::OFF,
            ClientPaymentSettings::DAYS_BEFORE_DUE => (string) ClientPaymentSettings::DEFAULT_DAYS_BEFORE_DUE,
            ClientPaymentSettings::BANK_BIN => '970436',
            ClientPaymentSettings::ACCOUNT_NUMBER => ClientPaymentSettings::DEMO_ACCOUNT_NUMBER,
            ClientPaymentSettings::ACCOUNT_HOLDER => ClientPaymentSettings::DEMO_ACCOUNT_HOLDER,
            ClientPaymentSettings::BANK_BRANCH => 'Chi nhánh mẫu (dữ liệu thử)',
        ], null);
    }
}
