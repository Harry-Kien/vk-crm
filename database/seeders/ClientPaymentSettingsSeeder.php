<?php

namespace Database\Seeders;

use App\Actions\Settings\WriteSettings;
use App\Support\Billing\ClientPaymentSettings;
use Illuminate\Database\Seeder;

/**
 * Dữ liệu MẪU của "Nhắc lịch thanh toán cho khách" (làn nt Task 1): thông tin chuyển khoản RÕ LÀ GIẢ
 * (chủ tài khoản "TAI KHOAN THU NGHIEM"), để bản xem thử có sẵn thứ cần điền khi chủ văn phòng muốn bật
 * công tắc — và công tắc vẫn **TẮT** (ghi tường minh `'0'`), đúng mặc định chủ văn phòng đã chọn.
 *
 * Bật để xem thử: đăng nhập quản trị → "Nhắc thanh toán cho khách" → bật công tắc → Lưu. Trước khi dùng
 * thật, thay thông tin chuyển khoản bằng tài khoản thật của văn phòng và quét mã QR xem thử trên trang.
 *
 * Chỉ chạy trong {@see DemoDataSeeder} (máy dev, bộ test, hay `--class` tường minh), không bao giờ trong
 * `ReferenceDataSeeder`: một bản cài thật không được nhận số tài khoản giả. Ghi qua bước ghi chung
 * {@see WriteSettings} (không actor — dòng `updated_by` để trống), không qua Action của trang: seeder
 * không có quản trị viên đăng nhập, và không cần dòng nhật ký "ai đổi cấu hình".
 */
class ClientPaymentSettingsSeeder extends Seeder
{
    public const DEMO_ACCOUNT_NUMBER = '0000123456789';

    public function run(WriteSettings $writeSettings): void
    {
        $writeSettings->handle([
            ClientPaymentSettings::ENABLED => ClientPaymentSettings::OFF,
            ClientPaymentSettings::DAYS_BEFORE_DUE => (string) ClientPaymentSettings::DEFAULT_DAYS_BEFORE_DUE,
            ClientPaymentSettings::BANK_BIN => '970436',
            ClientPaymentSettings::ACCOUNT_NUMBER => self::DEMO_ACCOUNT_NUMBER,
            ClientPaymentSettings::ACCOUNT_HOLDER => 'TAI KHOAN THU NGHIEM',
            ClientPaymentSettings::BANK_BRANCH => 'Chi nhánh mẫu (dữ liệu thử)',
        ], null);
    }
}
