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
    // M10 R7b: số tháng giữ dữ liệu người liên hệ KHÔNG thành khách (từ lúc bị từ chối, không theo
    // tiếp hoặc bị gộp) rồi tự ẩn danh. Riêng với `retention_years` (hồ sơ vụ việc, M7). Đọc qua
    // `IntakeRequest::retentionMonths()`, nơi một giá trị vô nghĩa về 24 — không ép kiểu ở đây.
    'prospect_retention_months' => env('PROSPECT_RETENTION_MONTHS', 24),

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
         * Task 1, fix I1). Một hoặc nhiều địa chỉ phân tách dấu phẩy — phân tích và validate
         * TỪNG địa chỉ ở `App\Support\Backup\BackupNotifyEmails::parse()` (địa chỉ hỏng bị bỏ
         * qua + ghi log, không ném lỗi). Giá trị THÔ (chưa validate) được giữ nguyên ở đây.
         *
         * Đọc qua ĐÂY (`config('vkcrm.backup.notify_email')`) — KHÔNG BAO GIỜ qua
         * `config('backup.notifications.mail.to')` của gói: trường đó là một placeholder CỐ ĐỊNH,
         * không liên quan tới biến này (xem docblock ở `config/backup.php` — đưa giá trị thô của
         * biến này vào đó làm hỏng MỌI lệnh artisan, không riêng sao lưu).
         */
        'notify_email' => $domain(env('BACKUP_NOTIFY_EMAIL')),

        /*
         * Số bản giữ lại TRÊN MÁY CHỦ (disk `local_backups`) khi đích rclone bên dưới đã bật (M8a
         * Task 2, phán quyết Ruling 2 của brief). `local_backups` khi đó chỉ còn là đĩa TRUNG
         * CHUYỂN — 30 bản đầy đủ hồ sơ khách hàng có thể lấp đầy một VPS 60 GB, nên chỉ giữ vài
         * bản gần nhất để khôi phục nhanh; bản đầy đủ (30 bản, SPEC §10 mục 8) nằm ở Google Drive.
         *
         * KHÔNG BAO GIỜ ĐƯỢC DƯỚI 1: {@see \App\Actions\Backup\PruneLocalBackupsDisk} giữ N bản
         * MỚI NHẤT; một cấu hình sai không được phép biến thành "xoá luôn cả bản vừa tạo" — bản đó
         * là bản DUY NHẤT còn lại nếu lượt đẩy lên rclone chưa chạy hay đã hỏng.
         *
         * `?: 7`, KHÔNG `env('BACKUP_LOCAL_KEEP', 7)` (fix I1, lượt rà soát cuối M8a — cùng bẫy
         * với NB1 của `BACKUP_NAME`): `.env.example` giao `BACKUP_LOCAL_KEEP=` rỗng, `env()` trả
         * `''` cho biến có mặt mà rỗng (tham số mặc định chỉ dùng khi biến VẮNG MẶT), và
         * `(int) ''` là 0 — bản trước vì vậy giữ MỘT bản thay vì 7. Với `?:`, cả `''` lẫn `'0'`
         * (hai giá trị PHP coi là rỗng) rơi về 7; `max(1, ...)` còn lại chặn số âm. Kiểm ở
         * `tests/Feature/Backup/BackupConfigTest.php`.
         */
        'local_keep' => max(1, (int) (env('BACKUP_LOCAL_KEEP') ?: 7)),

        'rclone' => [
            /*
             * Remote rclone đích, ví dụ `gdrive:VK-CRM-backups` (M8a Task 2, Ruling 1 của brief —
             * dùng `rclone`, KHÔNG dùng adapter Flysystem `masbug/flysystem-google-drive-ext`; lý
             * do đầy đủ ở docs/research/2026-09-26-sao-luu.md, mục "Task 2 — chuyển sang rclone").
             * Rỗng thì TẮT HẲN việc đẩy — {@see \App\Actions\Backup\PushBackupArchiveToRclone}
             * không chạy `rclone` nào, và {@see \App\Actions\Backup\CheckBackupDestinations} bỏ
             * qua đích này.
             */
            'remote' => $domain(env('BACKUP_RCLONE_REMOTE')),

            // Đường dẫn binary `rclone` trên máy chủ. Rỗng dùng "rclone" (tìm trong PATH).
            'binary' => env('BACKUP_RCLONE_BINARY') ?: 'rclone',

            /*
             * Đường dẫn tệp `rclone.conf` nếu KHÔNG dùng nơi dò tìm mặc định của rclone hay biến
             * `RCLONE_CONFIG_*`. Rỗng thì KHÔNG truyền `--config` — để rclone tự dò (hành vi mặc
             * định của chính nó, không phải một giá trị dự án tự chọn).
             */
            'config_path' => $domain(env('BACKUP_RCLONE_CONFIG')),

            /*
             * Hạn cho MỖI lệnh `rclone` (giây), `BACKUP_RCLONE_TIMEOUT`, trống thì 1800. 30 phút vì
             * archive có thể nặng vài GB và mạng lên Google Drive từ một VPS không phải lúc nào
             * cũng nhanh; hạn quá ngắn biến "mạng chậm" thành "sao lưu thất bại" mỗi đêm. Đọc từ
             * env (lượt rà soát cuối M8a, M6) để một văn phòng có archive lớn hơn hay đường truyền
             * chậm hơn nới ra được mà không sửa mã. `?:` + `max(1, ...)`: trống là mặc định, `0`
             * không thành "không hạn giờ" hay "hết giờ ngay" — cùng thành ngữ với `local_keep`.
             */
            'timeout' => max(1, (int) (env('BACKUP_RCLONE_TIMEOUT') ?: 1800)),

            /*
             * Số bản GIỮ LẠI trên remote (SPEC §10 mục 8: "giữ 30 bản"). Hằng số, không đọc env —
             * cùng lý lẽ với `keep_all_backups_for_days` ở `config/backup.php`: đây là một con số
             * của SPEC, không phải một tham số vận hành.
             */
            'keep' => 30,

            /*
             * Tuổi tối đa (giờ) của bản MỚI NHẤT trên remote trước khi giám sát 08:00 báo lỗi
             * (`App\Actions\Backup\CheckRcloneRemoteFreshness`, fix I4 lượt rà soát cuối M8a).
             * Lượt đẩy chạy mỗi đêm lúc 02:00, nên lúc 08:00 bản mới nhất bình thường chỉ ~6 giờ
             * tuổi; 36 giờ nghĩa là đã lỡ ít nhất MỘT đêm, cộng biên cho một lượt sao lưu chạy
             * chậm. Hằng số, cùng lý lẽ với `keep`.
             */
            'max_age_hours' => 36,
        ],
    ],

    'security' => [
        /*
         * Chế độ Content-Security-Policy (SPEC §10 mục 2, phán quyết R4): `off` | `report` |
         * `enforce`. Giữ giá trị THÔ ở đây; đọc, chuẩn hoá và chọn mặc định ở
         * {@see \App\Support\Security\ContentSecurityPolicy::mode()} — để trống là `report` CHỈ ở
         * `local`/`testing` và `enforce` ở mọi môi trường khác, một giá trị lạ rơi về `enforce`.
         * Mặc định nằm ở đó chứ không ở đây để nó hỏi `app()->environment()` lúc chạy, cùng một
         * nguồn với mọi chỗ khác của ứng dụng hỏi "đây là môi trường nào".
         */
        'csp_mode' => env('CSP_MODE'),
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
         * M6.5 Task 12 (`notify/notify-14`): Reply-To dùng chung cho MỌI thư của văn phòng — xem
         * `App\Mail\BrandedMailable::replyToAddresses()`. Khác `MAIL_FROM_ADDRESS`
         * (`no-reply@luatvukhang.com`, chỉ dùng cho SPF/DKIM): đây là hộp thư THẬT có người đọc,
         * để khách/nhân sự bấm "Trả lời" không rơi vào chỗ không ai xem.
         */
        'reply_to' => env('BRAND_REPLY_TO_ADDRESS', 'lienhe@luatvukhang.com'),

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

    /*
     * M10 Task 5 (R5) — ngưỡng phản hồi lần đầu của một lần có người liên hệ: quá chừng ấy GIỜ LÀM
     * VIỆC (`business_hours` dưới đây) mà bản ghi còn ở `new` thì `RemindUnansweredIntakes` nhắc và
     * widget "Liên hệ chưa ai gọi lại" hiện nó. Số nguyên giờ, `INTAKE_RESPONSE_HOURS`; trống, `0`,
     * số âm hay chữ đều rơi về 4 (mặc định của kế hoạch M10) — không bao giờ thành "nhắc ngay khi vừa
     * nhận" hay "không bao giờ nhắc".
     */
    'intake_response_hours' => (static fn (int $hours): int => $hours >= 1 ? $hours : 4)((int) env('INTAKE_RESPONSE_HOURS')),

    /*
     * Giờ làm việc của văn phòng (M10 R5) — MỘT định nghĩa, đọc qua `App\Support\BusinessHours::
     * fromConfig()`, theo `APP_TIMEZONE`. Ngày theo ISO-8601: 1 = Thứ Hai … 7 = Chủ nhật. Khung giờ
     * tính cả hai đầu (08:00 và 17:30 đều là trong giờ).
     *
     * Viết thẳng ở đây, không đọc `.env`: đổi lịch làm việc là một quyết định của văn phòng, nên đi
     * qua mã và bộ test (`VkcrmConfigTest` ghim mặc định), không qua một biến môi trường gõ nhầm được.
     * Văn phòng làm thêm Thứ Bảy thì thêm `6` vào `days` — tác vụ nhắc chạy mỗi 15 phút và tự hỏi
     * lịch này, nên không cron nào phải sửa theo.
     *
     * **Ngày lễ không mô hình hoá ở M10** (kế hoạch M10, mục 5 "Còn cần xác nhận"): một ngày lễ rơi
     * vào Thứ Hai–Thứ Sáu vẫn được tính là ngày làm việc — lời nhắc có thể tới giữa kỳ nghỉ.
     */
    'business_hours' => [
        'days' => [1, 2, 3, 4, 5],
        'opens_at' => '08:00',
        'closes_at' => '17:30',
    ],
];
