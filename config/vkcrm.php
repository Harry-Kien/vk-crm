<?php

$domain = static fn (?string $value): ?string => filled($value) ? $value : null;

return [
    // Tên miền riêng cho từng panel. Để trống => chạy chung một tên miền theo đường dẫn.
    'admin_domain' => $domain(env('ADMIN_DOMAIN')),
    'portal_domain' => $domain(env('PORTAL_DOMAIN')),

    // Tiền tố mã hồ sơ: {prefix}-{YYYY}-{loại}-{0001}
    'matter_code_prefix' => env('MATTER_CODE_PREFIX', 'VK'),

    // Giới hạn tệp khách nộp (MB)
    'upload_max_mb' => (int) env('UPLOAD_MAX_MB', 20),

    // Chính sách lưu trữ
    'retention_years' => (int) env('RETENTION_YEARS', 10),
    'client_access_days' => (int) env('CLIENT_ACCESS_DAYS', 90),

    // Giám sát cron
    'heartbeat_url' => $domain(env('HEARTBEAT_URL')),

    'clamav' => [
        'enabled' => (bool) env('CLAMAV_ENABLED', false),
        'socket' => env('CLAMAV_SOCKET', '/var/run/clamav/clamd.ctl'),

        /*
         * Hạn cho MỖI lần đọc/ghi trên socket sau khi đã kết nối (giây). Khác hẳn timeout kết
         * nối: một daemon quá tải vẫn nhận kết nối ngay rồi im lặng, và không có hạn này thì
         * lần chờ câu trả lời rơi về `default_socket_timeout` của PHP — 60 giây khoá worker cho
         * mỗi tệp. 30 giây đủ để một tệp 20 MB đi qua trên máy chủ bận.
         */
        'timeout' => (int) env('CLAMAV_TIMEOUT', 30),
    ],

    'backup' => [
        /*
         * Người nhận thư báo lỗi sao lưu/dọn dẹp/bản sao không lành mạnh (SPEC §10 mục 8, M8a
         * Task 1). Đọc qua ĐÂY (`config('vkcrm.backup.notify_email')`, `null` khi trống) — không
         * qua `config('backup.notifications.mail.to')` của gói: trường đó BẮT BUỘC là một email
         * hợp lệ (xem docblock ở `config/backup.php`) nên không thể mang giá trị rỗng, còn nghiệp
         * vụ "trống thì gửi mọi admin đang hoạt động" (App\Actions\Backup\
         * ResolveBackupNotificationRecipients) cần phân biệt được "trống" với "một email".
         */
        'notify_email' => $domain(env('BACKUP_NOTIFY_EMAIL')),
    ],

    /*
     * Nhận diện thương hiệu của chính văn phòng, lấy từ luatvukhang.com để hệ thống nội bộ và
     * cổng khách hàng trông liền một mạch với website — khách đăng nhập vào đây phải thấy ngay
     * đây là hệ thống của Vũ Khang, không phải một phần mềm thuê ngoài dán tên.
     *
     * Bảng màu và bộ chữ lấy đúng biến CSS của website (--navy, --red, --paper, --muted; Be
     * Vietnam Pro cho chữ thường, Noto Serif cho tiêu đề trang trọng). Đổi ở đây là đổi cả hai
     * panel, trang đăng nhập, email và tệp PDF xuất ra về sau.
     */
    'brand' => [
        'legal_name' => env('BRAND_LEGAL_NAME', 'Công ty Luật TNHH Vũ Khang Solutions & Partners'),
        'short_name' => env('BRAND_SHORT_NAME', 'Luật Vũ Khang'),

        /*
         * Tên pháp lý đầy đủ tách làm ba dòng cho khối nhận diện cạnh logo. Tách ở đây chứ không
         * trong Blade để đổi tên là sửa một chỗ, và để loại hình doanh nghiệp ("Công ty Luật
         * TNHH") không bị bỏ rơi khi ai đó rút gọn phần hiển thị: với một tổ chức hành nghề luật,
         * loại hình là một phần của danh tính pháp lý, không phải chữ trang trí.
         */
        'lockup' => [
            'entity' => env('BRAND_LOCKUP_ENTITY', 'Công ty Luật TNHH'),
            'name' => env('BRAND_LOCKUP_NAME', 'Vũ Khang'),
            'suffix' => env('BRAND_LOCKUP_SUFFIX', 'Solutions & Partners'),
        ],
        'tagline' => env('BRAND_TAGLINE', 'Thấu hiểu vấn đề. Vững vàng quyết định.'),
        'website' => env('BRAND_WEBSITE', 'https://luatvukhang.com'),
        'hotline' => env('BRAND_HOTLINE', '0832270898'),
        'zalo' => env('BRAND_ZALO', 'https://zalo.me/0832270898'),

        /*
         * Bốn thông tin dưới đây PHẢI có trước khi hệ thống gửi email cho khách hoặc xuất PDF:
         * luật và thông lệ đều đòi chân thư của một tổ chức hành nghề luật nêu đủ tên pháp lý, mã
         * số thuế, Đoàn Luật sư và số Giấy đăng ký hoạt động.
         *
         * Đã tra luatvukhang.com (trang chủ, /vi/about, /vi/contact) ngày 19/09/2026: website
         * KHÔNG đăng bốn thông tin này, nên không có cách nào lấy tự động cho chính xác. Để trống
         * có chủ đích thay vì điền phỏng đoán — một mã số thuế sai trên văn bản gửi khách còn tệ
         * hơn một chỗ trống. Chủ văn phòng điền vào .env là xong, không phải sửa mã.
         */
        'tax_code' => env('BRAND_TAX_CODE'),
        'bar_association' => env('BRAND_BAR_ASSOCIATION'),
        'licence_number' => env('BRAND_LICENCE_NUMBER'),
        'office_address' => env('BRAND_OFFICE_ADDRESS'),

        'colors' => [
            'navy' => '#101d35',
            'deep' => '#091322',
            'red' => '#c6283d',
            'paper' => '#f7f8fa',
            'muted' => '#475467',
        ],

        'font' => env('BRAND_FONT', 'Be Vietnam Pro'),

        /*
         * Dải sắc độ chủ đạo cho panel khách hàng, viết thẳng thay vì để `Color::hex()` tự sinh.
         *
         * `Color::hex('#101d35')` chỉ lấy TÔNG MÀU của mã này rồi áp đường cong độ sáng cố định
         * của Filament, trong đó sắc độ 600 — sắc độ dùng cho nút bấm chính — nằm ở L 0.598, tức
         * một màu xanh sáng, không còn là navy trầm của văn phòng. Dải dưới đây giữ nguyên tông
         * 261.7° đo được từ `--navy` của luatvukhang.com, nhưng kéo các sắc độ giữa tối lại cho
         * đúng chất; sắc độ 950 là CHÍNH XÁC `#101d35`. Độ tương phản chữ trắng trên nền 600 vẫn
         * trên 4.5:1 nên không đánh đổi khả năng đọc để lấy thẩm mỹ.
         */
        'primary_ramp' => [
            50 => 'oklch(0.977 0.014 261.7)',
            100 => 'oklch(0.950 0.028 261.7)',
            200 => 'oklch(0.905 0.050 261.7)',
            300 => 'oklch(0.840 0.075 261.7)',
            400 => 'oklch(0.720 0.105 261.7)',
            500 => 'oklch(0.560 0.125 261.7)',
            600 => 'oklch(0.420 0.112 261.7)',
            700 => 'oklch(0.365 0.098 261.7)',
            800 => 'oklch(0.300 0.075 261.7)',
            900 => 'oklch(0.260 0.058 261.7)',
            950 => 'oklch(0.233 0.050 261.7)',
        ],
    ],
];
