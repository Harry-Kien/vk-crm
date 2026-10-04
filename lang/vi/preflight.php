<?php

use App\Actions\Deployment\RunPreflight;
use App\Console\Commands\PreflightCommand;

/**
 * `vkcrm:preflight` (R1, kế hoạch M8 Task 1) — {@see RunPreflight},
 * {@see PreflightCommand}. Không đưa giá trị bí mật (mật khẩu, token) vào
 * bất kỳ khoá nào dưới đây — chỉ tên biến/đường dẫn và kết luận.
 */
return [
    'levels' => [
        'red' => 'ĐỎ',
        'yellow' => 'VÀNG',
        'green' => 'XANH',
    ],

    'app_env_blank' => 'APP_ENV đang để trống — không có môi trường hợp lệ nào được phép để '
        .'trống biến này. Đặt APP_ENV=production trên máy chủ thật (hay local/staging/testing ở '
        .'nơi khác).',
    'app_env_non_production' => 'APP_ENV=:env (khác production) — các điều kiện ra mắt bên dưới '
        .'KHÔNG được kiểm ở môi trường này. Chạy lại lệnh với APP_ENV=production trên máy chủ '
        .'thật trước khi mở cổng.',
    'app_env_production' => 'APP_ENV=production — kiểm đủ các điều kiện ra mắt bên dưới.',

    'trusted_proxies_missing' => 'TRUSTED_PROXIES đang để trống. Đứng sau một proxy/CDN mà '
        .'không khai báo proxy đó: mọi khách dùng CHUNG một ô đếm đăng nhập (năm lần gõ sai của '
        .'bất kỳ ai khoá cả cổng 15 phút), và last_login_ip/stage_log_views.ip ghi địa chỉ của '
        .'proxy chứ không của khách. Điền địa chỉ proxy thật vào TRUSTED_PROXIES.',
    'trusted_proxies_ok' => 'TRUSTED_PROXIES đã khai báo.',
    'trusted_proxies_trust_all' => 'TRUSTED_PROXIES đang tin TOÀN BỘ IP (*, **, 0.0.0.0/0 hay '
        .'::/0) — bất kỳ ai cũng tự khai được X-Forwarded-For, xuyên thủng giới hạn IP admin '
        .'(ADMIN_IP_ALLOWLIST) và bộ đếm đăng nhập theo IP, và cột bằng chứng IP trong nhật ký '
        .'mất ý nghĩa. Nếu máy chủ web nói thẳng với php-fpm, không qua proxy tách rời (đúng mẫu '
        .'tools/deploy/), điền TRUSTED_PROXIES=127.0.0.1 — không có proxy nào để tin, REMOTE_ADDR '
        .'đã là địa chỉ thật.',

    'heartbeat_url_missing' => 'HEARTBEAT_URL đang để trống — không có giám sát khi cron lặng lẽ '
        .'ngừng chạy (SPEC §2). Đăng ký một dịch vụ giám sát cron miễn phí và điền URL vào đây.',
    'heartbeat_url_ok' => 'HEARTBEAT_URL đã khai báo.',

    'session_secure_cookie_off' => 'SESSION_SECURE_COOKIE giải ra khác true (config(\'session.'
        .'secure\') = :value) — cookie phiên có thể bị gửi qua kết nối http không mã hoá. Để '
        .'trống biến này trên máy chủ thật (mặc định tự bật true ngoài local/testing), hoặc đặt '
        .'rõ SESSION_SECURE_COOKIE=true.',
    'session_secure_cookie_ok' => 'SESSION_SECURE_COOKIE giải ra true.',

    'app_debug_on' => 'APP_DEBUG=true ở production — trang lỗi in ra biến môi trường, đường dẫn '
        .'và có thể cả bí mật cấu hình cho bất kỳ ai kích hoạt được một lỗi. Đặt APP_DEBUG=false.',
    'app_debug_ok' => 'APP_DEBUG=false.',

    // Final review I4: tài khoản nhân sự demo (DemoDataSeeder) còn mật khẩu mẫu trên máy chủ thật —
    // xem App\Actions\Deployment\RunPreflight::demoAccountsRow().
    'demo_accounts_exposed' => 'Còn :count tài khoản nhân sự demo dùng mật khẩu mẫu "password" '
        .'(:emails) trong khi ADMIN_IP_ALLOWLIST để trống — /admin mở cho cả Internet, ai đăng '
        .'nhập trước thì tự cài 2FA của mình và chiếm tài khoản đó (kể cả quản trị viên, thấy mọi '
        .'vụ việc). Đặt ADMIN_IP_ALLOWLIST ngay, hoặc chạy chuỗi "Hết demo, chuyển sang dùng thật" '
        .'ở docs/CAI-DAT.md, Bước 5.',
    'demo_accounts_allowlisted' => 'Còn :count tài khoản nhân sự demo dùng mật khẩu mẫu '
        .'(:emails) — /admin chỉ mở trong ADMIN_IP_ALLOWLIST nên người ngoài mạng đó chưa đăng nhập '
        .'được. Trước khi dùng thật, chạy chuỗi "Hết demo, chuyển sang dùng thật" ở '
        .'docs/CAI-DAT.md, Bước 5.',
    'demo_accounts_ok' => 'Không còn tài khoản nhân sự demo nào dùng mật khẩu mẫu.',

    'extensions_missing' => 'Thiếu PHP extension bắt buộc: :extensions. Cài đủ trước khi mở cổng '
        .'— thiếu một extension trong danh sách này thường không lộ ra ở màn hình đăng nhập mà '
        .'chỉ vỡ ở đúng màn hình dùng tới nó.',
    'extensions_ok' => 'Đủ PHP extension bắt buộc.',

    'gd_missing' => 'Thiếu extension gd — media-library dùng "gd" làm image_driver mặc định '
        .'(config/media-library.php), nhưng dự án CHƯA đăng ký addMediaConversion/'
        .'registerMediaConversions nào (soát ngày 2026-09-28), nên không có ảnh nào thực sự cần '
        .'dựng lại hôm nay. VÀNG chứ không ĐỎ — cài trước khi ai đó bật một chuyển đổi ảnh.',
    'gd_ok' => 'Extension gd có sẵn.',

    'brand_fields_missing' => 'Còn thiếu thông tin pháp lý của văn phòng: :fields — thư gửi khách '
        .'và PDF xuất ra sẽ thiếu các trường này cho tới khi điền. Chủ văn phòng điền ở trang '
        .'"Thông tin văn phòng" trong /admin (hoặc đặt biến tương ứng trong .env); giá trị nhập trong '
        .'app thắng giá trị trong .env.',
    'brand_fields_ok' => 'Đủ bốn thông tin pháp lý của văn phòng.',

    'storage_private_exposed' => 'storage/app/private PHỤC VỤ CÔNG KHAI được, qua :url — hồ sơ '
        .'khách hàng có thể bị tải trực tiếp không qua kiểm tra quyền. Kiểm lại document root của '
        .'máy chủ web (phải là public/, không phải gốc dự án) và symlink public/storage (phải '
        .'trỏ vào storage/app/public, không phải storage/app/private).',
    'storage_private_unreachable' => 'Không kiểm được storage/app/private có phục vụ công khai '
        .'hay không — không gọi được APP_URL (:detail). Kiểm tay bằng cách mở '
        .':url1 và :url2 từ một trình duyệt.',
    'storage_private_ok' => 'storage/app/private không phục vụ công khai được (đã thử :count '
        .'đường dẫn).',

    'zip_aes256_missing' => 'PHP zip không dựng với hỗ trợ mã hoá AES-256 (ZipArchive::EM_AES_256 '
        .'không tồn tại — thường do libzip quá cũ). Mọi lượt sao lưu ở production sẽ bị từ chối '
        .'(không tạo bản không mã hoá). Nâng cấp libzip/PHP zip.',
    'zip_aes256_ok' => 'PHP zip hỗ trợ mã hoá AES-256.',

    'proc_open_disabled' => 'Hàm proc_open() không dùng được (thường do disable_functions trong '
        .'php.ini) — không dump được CSDL và không gọi được rclone, sao lưu sẽ luôn thất bại. '
        .'Bỏ proc_open khỏi disable_functions.',
    'proc_open_ok' => 'Hàm proc_open() dùng được.',

    'mariadb_dump_missing' => 'Không tìm thấy lệnh mariadb-dump hay mysqldump trong PATH của máy '
        .'chủ — không dump được CSDL, sao lưu sẽ luôn thất bại. Cài gói mariadb-client (ví dụ '
        .'apt install mariadb-client).',
    'mariadb_dump_ok' => 'Tìm thấy lệnh :binary trong PATH.',

    'backup_env_non_numeric' => ':variable có giá trị ":value" — không phải một số nguyên. Biến '
        .'này bị ép kiểu (int) lặng lẽ ở nơi đọc nó, nên một lỗi gõ ở đây không hiện ra ngay mà '
        .'chỉ đổi hành vi (ví dụ ":value" có thể bị cắt còn một số nhỏ hơn nhiều so với ý định). '
        .'Sửa lại thành một số nguyên, hoặc để trống để dùng mặc định.',

    // M9 Task 13 — tầng 4 của bất biến tổng tiền, xem RunPreflight::billingInvariantsRow().
    'billing_invariants_mismatch' => 'Có :count hợp đồng đang có hiệu lực mà tổng các đợt thanh '
        .'toán khác giá trị hợp đồng: :codes. Màn hình tiền, công nợ và doanh thu đang tính sai cho '
        .'các hợp đồng này. Chạy php artisan billing:check-invariants để xem từng con số, rồi luật sư '
        .'phụ trách sửa bằng một phụ lục (không sửa thẳng vào CSDL). Dòng ĐỎ này không chặn mở cổng '
        .'(php artisan up): nó là dữ liệu, không phải cấu hình máy, và chỉ sửa được trong app — nên vẫn '
        .'mở cổng rồi sửa ngay. Mọi dòng ĐỎ khác vẫn chặn.',
    'billing_invariants_ok' => 'Tổng các đợt thanh toán khớp giá trị hợp đồng trên cả :count hợp '
        .'đồng đang có hiệu lực.',

    'summary_red' => 'Có mục ĐỎ — KHÔNG mở cổng cho tới khi sửa hết.',
    // Rà soát cuối làn m9f, I2 — xem RunPreflight::blocksOpening().
    'summary_red_billing_only' => 'Mục ĐỎ duy nhất là bất biến tiền — dữ liệu, không phải cấu hình máy: '
        .'vẫn mở cổng (php artisan up), rồi luật sư phụ trách ký ngay phụ lục cho từng hợp đồng lệch. '
        .'Mã thoát vẫn khác 0 cho tới khi sạch.',
    'summary_yellow' => 'Không có mục ĐỎ, còn mục VÀNG cần chú ý.',
    'summary_ok' => 'Mọi điều kiện ra mắt đều đạt.',
];
