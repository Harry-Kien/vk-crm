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
             * tuổi, và bản của đêm TRƯỚC đó ~30 giờ: vượt 36 giờ nghĩa là HAI đêm liền không lên
             * được (một đêm hỏng đơn lẻ đã có thư lỗi của chính lượt đẩy). Chủ văn phòng giữ 36 giờ.
             * Hằng số, cùng lý lẽ với `keep`.
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

        /*
         * Ép HTTPS (SPEC §10 mục 1, kế hoạch M8 Task 1). Giá trị THÔ ở đây — CHƯA giải "để trống
         * nghĩa là gì": đọc qua {@see \App\Support\Security\HttpsDefaults::boolFromRaw()} ở
         * {@see \App\Http\Middleware\EnforceHttps}, KHÔNG đọc trực tiếp khoá này, cùng lý do
         * `csp_mode` ở trên (hỏi `app()->environment()` lúc xử lý request, không bị đông cứng nếu
         * `config:cache` chạy ở một môi trường rồi copy sang môi trường khác).
         */
        'force_https' => env('FORCE_HTTPS'),

        /*
         * HSTS `max-age` (giây) cho lớp dự phòng ở tầng app khi hosting không cho cấu hình máy chủ
         * web (SPEC §10 mục 1). Giá trị THÔ; giải qua
         * {@see \App\Support\Security\HttpsDefaults::secondsFromRaw()} ở
         * {@see \App\Http\Middleware\EnforceHttps} — để trống là 31536000 (một năm) ở mọi môi trường
         * trừ `local`/`testing` (0, tức tắt).
         */
        'hsts_max_age' => env('HSTS_MAX_AGE'),

        /*
         * `includeSubDomains`/`preload` của header Strict-Transport-Security — KHÔNG bật mặc định
         * dù để trống hay có giá trị khác rỗng: website luatvukhang.com và các tên miền con khác
         * của văn phòng nằm NGOÀI ứng dụng này, và bật nhầm khoá luôn chúng vào https một năm.
         * Chỉ bật khi `.env` ghi rõ `true` (bất kể môi trường).
         */
        'hsts_include_subdomains' => filter_var(env('HSTS_INCLUDE_SUBDOMAINS', false), FILTER_VALIDATE_BOOLEAN),
        'hsts_preload' => filter_var(env('HSTS_PRELOAD', false), FILTER_VALIDATE_BOOLEAN),

        /*
         * Danh sách IP/CIDR được vào `/admin` (R7, SPEC §3 và §10 mục 10), phân tách dấu phẩy.
         * Rỗng = tắt hẳn (mặc định) — {@see \App\Http\Middleware\RestrictAdminIpAllowlist} đọc
         * qua đây, không đọc `env()` trực tiếp.
         */
        'admin_ip_allowlist' => (string) env('ADMIN_IP_ALLOWLIST', ''),
    ],

    /*
     * `vkcrm:preflight` (R1, kế hoạch M8 Task 1) — {@see \App\Actions\Deployment\RunPreflight}.
     */
    'deployment' => [
        /*
         * PHP extension bắt buộc ở production, HẰNG SỐ chứ không đoán theo máy đang chạy lệnh:
         * `composer check-platform-reqs --no-dev` ngày 2026-09-28 cộng `pdo_mysql` (MariaDB, SPEC
         * §2). KHÔNG có `gd` — xem {@see \App\Actions\Deployment\RunPreflight} vì sao đó là một
         * dòng VÀNG riêng, không phải một extension bắt buộc.
         *
         * Cấu hình được (không phải một `const` cứng trong Action) để test gài một tên giả vào
         * đây mà không cần gỡ thật một extension của container —
         * `tests/Feature/Deployment/PreflightCommandTest.php` dùng đúng cách này để dựng cả hai
         * chiều đỏ/xanh của điều kiện "thiếu extension".
         *
         * M12 Task 4 (R6) thêm `curl`: `minishlink/web-push` (gói thông báo đẩy) đòi `ext-curl`.
         * Từ đây danh sách được canh bằng `composer.lock`: test "mọi ext-* mà một gói production
         * đòi" của `PreflightCommandTest` đỏ khi một gói mới đòi extension chưa có ở đây.
         */
        'required_extensions' => [
            'ctype', 'curl', 'dom', 'exif', 'fileinfo', 'filter', 'hash', 'iconv', 'intl', 'json',
            'libxml', 'mbstring', 'openssl', 'pcre', 'session', 'tokenizer', 'xmlreader', 'zip',
            'zlib', 'pdo_mysql',
        ],

        /*
         * Việc sau gộp M7 (làn fu2): extension mà giờ chết của worker cần — `GenerateHandoverPackage::
         * $timeout`/`$failOnTimeout` và `--timeout=1200` của mục lịch `queue.handover` chỉ có tác dụng
         * khi PHP DÒNG LỆNH có ext-pcntl (thiếu nó, `Worker::registerTimeoutHandler()` bỏ qua lặng
         * lẽ). KHÔNG nằm trong `required_extensions` ở trên: danh sách đó đúng bằng
         * `composer check-platform-reqs` + `pdo_mysql` (`docs/CAI-DAT.md`, Bước 1), và thiếu pcntl
         * không làm vỡ màn hình nào — `vkcrm:preflight` báo VÀNG ({@see
         * \App\Actions\Deployment\RunPreflight}). Cấu hình được chỉ vì cùng lý do với
         * `required_extensions`: test gài một tên giả để dựng chiều VÀNG.
         */
        'worker_timeout_extension' => 'pcntl',

        /*
         * Rà soát cuối làn fu2 (I1): các hàm pcntl mà `Illuminate\Queue\Worker::daemon()` GỌI khi
         * extension ở trên đã nạp — `pcntl_async_signals()`/`pcntl_signal()` ở `listenForSignals()`,
         * `pcntl_signal()`/`pcntl_alarm()` ở `registerTimeoutHandler()`. `Worker::
         * supportsAsyncSignals()` chỉ hỏi `extension_loaded('pcntl')`, nên khi một hàm ở đây nằm
         * trong `disable_functions` (PHP 8: hàm bị chặn là hàm không tồn tại) mọi lượt `queue:work`
         * chết ở vòng đầu — `vkcrm:preflight` báo ĐỎ. Danh sách phải đúng bằng các hàm `pcntl_*` mà
         * Worker gọi: `PreflightCommandTest` đọc mã nguồn Worker để chặn trôi khi nâng Laravel.
         * Cấu hình được chỉ để test gài một tên hàm giả mà dựng chiều ĐỎ.
         */
        'worker_signal_functions' => ['pcntl_async_signals', 'pcntl_signal', 'pcntl_alarm'],
    ],

    /*
     * Nhận diện thương hiệu của chính văn phòng, lấy từ luatvukhang.com để hệ thống nội bộ và
     * cổng khách hàng trông liền một mạch với website — khách đăng nhập vào đây phải thấy ngay
     * đây là hệ thống của Vũ Khang, không phải một phần mềm thuê ngoài dán tên.
     *
     * Bảng màu và bộ chữ lấy đúng biến CSS của website (--navy, --red, --paper, --muted; Be
     * Vietnam Pro cho chữ thường, Noto Serif cho tiêu đề trang trọng). Đổi ở đây là đổi cả hai
     * panel, trang đăng nhập, email và tệp PDF xuất ra về sau.
     *
     * M7 Task 10: chín trường `legal_name`, `website`, `hotline`, `zalo`, `reply_to` và bốn thông
     * tin pháp lý bên dưới sửa được TRONG APP (trang "Thông tin văn phòng", chỉ admin). Giá trị ở
     * đây chỉ còn là MẶC ĐỊNH: mã đọc chúng qua `App\Support\OfficeProfile` (bảng `settings` trước,
     * rồi tới đây), không bao giờ `config('vkcrm.brand.<trường>')` trực tiếp — có test cấu trúc.
     * Màu, logo, font, lockup không sửa được trong app.
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
         * hơn một chỗ trống. Chủ văn phòng điền vào .env là xong, không phải sửa mã — hoặc, từ M7
         * Task 10 (quyết định của chủ văn phòng ngày 2026-09-24), tự nhập ở trang "Thông tin văn
         * phòng"; giá trị nhập trong app thắng giá trị ở đây.
         *
         * Địa chỉ trụ sở do chính chủ văn phòng cung cấp ngày 2026-10-02 nên là giá trị mặc định;
         * ba thông tin còn lại vẫn để trống tới khi chủ văn phòng đưa. `BRAND_OFFICE_ADDRESS`
         * trong .env (hoặc trang "Thông tin văn phòng" của M7) vẫn ghi đè được.
         */
        'tax_code' => env('BRAND_TAX_CODE'),
        'bar_association' => env('BRAND_BAR_ASSOCIATION'),
        'licence_number' => env('BRAND_LICENCE_NUMBER'),
        'office_address' => env('BRAND_OFFICE_ADDRESS', '1808 đường Nguyễn Ái Quốc, phường Trấn Biên, thành phố Đồng Nai'),

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
     * M12 — app trên điện thoại (PWA). Kế hoạch `docs/superpowers/plans/2026-09-24-m12-pwa.md`.
     *
     * `theme_color`/`background_color` của manifest KHÔNG nằm ở đây: chúng đọc thẳng
     * `brand.colors.navy`/`brand.colors.paper` (phán quyết R2 — không viết mã màu lần thứ hai).
     */
    'pwa' => [
        /*
         * R3 — nền đặc của biểu tượng maskable và `apple-touch-icon` theo từng app, là KHOÁ trong
         * `brand.colors` chứ không phải mã màu. Hai nền khác nhau để một nhân sự cài cả hai app
         * phân biệt được bằng mắt: cổng khách navy, nội bộ paper. `tools/brand/make-logo.php` đọc
         * đúng khoá này khi sinh PNG; đổi ở đây thì phải chạy lại công cụ đó (và đổi tên tệp —
         * docblock của công cụ nói vì sao).
         */
        'icon_background' => [
            'admin' => 'paper',
            'portal' => 'navy',
        ],

        /*
         * R4 — danh sách cho phép DUY NHẤT của bộ đệm service worker: tài nguyên tĩnh công khai
         * dưới `public/` (CSS/JS/phông của Filament, biểu tượng, `register.js`), lưu theo
         * stale-while-revalidate. Mọi đường dẫn khác — trang HTML, Livewire (`/livewire-…`),
         * tệp hồ sơ (`…/documents/{id}/download`), JSON — KHÔNG BAO GIỜ vào bộ đệm trình duyệt.
         * Render nguyên văn vào `sw.js` (`resources/views/pwa/sw-js.blade.php`) và nằm trong
         * VERSION; `tests/Feature/Pwa/ServiceWorkerTest.php` khẳng định hai bên bằng nhau. Thêm một
         * tiền tố ở đây là thêm một thứ vào điện thoại của khách: chỉ thêm tài nguyên công khai,
         * có dấu `/` ở cuối (so tiền tố chuỗi — `/brand` không dấu `/` sẽ khớp cả `/brandx/…`).
         */
        'static_prefixes' => ['/css/filament/', '/js/filament/', '/fonts/filament/', '/brand/', '/pwa/'],

        /*
         * R8 — máy chủ push mà một endpoint đăng ký được phép trỏ tới. Máy chủ của văn phòng
         * POST tới endpoint theo lịch (job push), nên KHÔNG có danh sách này thì một người đã đăng
         * nhập — kể cả khách — gửi `endpoint = http://169.254.169.254/…` là biến máy chủ thành
         * công cụ gọi vào địa chỉ nội bộ, có sẵn bộ hẹn giờ (SSRF).
         *
         * Tên đầy đủ khớp đúng tên; `*.` khớp MỘT hay nhiều nhãn đứng trước phần đuôi (không khớp
         * chính phần đuôi). So không phân biệt hoa thường. Luật đầy đủ (chỉ `https`, cổng 443, chỉ
         * ký tự URL in được, không `@`/`#`/`\`): `App\Actions\Push\RegisterPushDevice`.
         * Chrome và Samsung Internet đi qua FCM, Safari qua Apple, Firefox qua Mozilla, Edge qua WNS.
         *
         * FCM có HAI tên máy: `fcm.googleapis.com` và `jmt17.google.com` — ĐO ngày 2026-10-04 (M12
         * Task 10): bản Chromium 153 của Playwright (đăng ký THẬT, không bản giả) trả endpoint
         * `https://jmt17.google.com/fcm/send/…`; thiếu tên này thì trình duyệt đó bấm Bật nhận 422.
         * Google Chrome trên Android CHƯA đo (bước D1 của danh sách kiểm tra máy thật) — có thể vẫn
         * trả `fcm.googleapis.com`, nên giữ cả hai. Ghi đúng tên, không `*.google.com`: Google đổi tên
         * máy lần nữa thì nút Bật báo "Chưa bật được" (D1) và tên mới được thêm vào đây — rộng hơn là
         * mở cho mọi máy của Google.
         */
        'push_hosts' => [
            'fcm.googleapis.com',
            'jmt17.google.com',
            '*.push.apple.com',
            'updates.push.services.mozilla.com',
            '*.notify.windows.com',
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

    /*
     * M7 Task 4 — thư mục TẠM để dựng gói bàn giao (zip + MUC-LUC.pdf) trước khi gắn vào kho hồ sơ.
     * Mỗi lần yêu cầu có một thư mục con riêng (`<id vụ>-<dấu yêu cầu>`, xem
     * `BuildHandoverPackage::workDirectory()`), xoá khi xong, khi lỗi, và — với một tiến trình bị
     * giết giữa chừng — ở lần chạy lại hoặc khi job thất bại hẳn. Đặt riêng ở đây
     * vì gói có thể vài trăm MB: trên shared hosting nơi `storage/` nằm trên phần đĩa nhỏ, chỉ tới
     * một ổ rộng hơn bằng `HANDOVER_WORK_DIR`. Không bao giờ đặt nó bên trong đĩa `private` (thư
     * mục tạm ở đó sẽ bị lẫn với tệp hồ sơ thật).
     */
    'handover' => [
        'work_dir' => env('HANDOVER_WORK_DIR', storage_path('app/handover-tmp')),
    ],

    /*
     * M14 — kho tài liệu: Google Drive (Shared Drive của văn phòng) làm kho phía sau CRM. Đọc qua
     * `App\Support\Storage\DocumentStore`, không đọc `env()` ở nơi khác (sau `config:cache`, `env()`
     * ngoài tệp cấu hình trả `null`).
     *
     * Số đọc từ biến môi trường theo thành ngữ `?:` + `max(1, …)` của khối `backup` ở trên: trống
     * và `0` là MẶC ĐỊNH, không phải 0; số âm bị chặn về 1. Riêng `chunk_mb` có khoảng hợp lệ riêng
     * (xem dưới). Số không có biến môi trường là hằng của kế hoạch, không phải tham số vận hành.
     */
    'storage' => [
        /*
         * Công tắc `DOCUMENT_STORAGE`: `local` (mặc định, tệp ở máy chủ như trước M14) hay
         * `google_drive`. `google_drive` chỉ CHO PHÉP đẩy tệp lên kho; lệnh bật kho mới BẬT (ghi mốc
         * `settings.storage.remote_enabled_at`). Giá trị được giữ NGUYÊN, kể cả khi gõ sai:
         * `DocumentStore::driverIsValid()` phải thấy lỗi gõ để kiểm tra sẵn sàng báo ĐỎ.
         */
        'driver' => env('DOCUMENT_STORAGE') ?: 'local',

        // Bản trong vùng đệm (`private`) được giữ ít nhất chừng này giờ sau khi đẩy lên kho (R2).
        'staging_grace_hours' => max(1, (int) (env('DOCUMENT_STAGING_GRACE_HOURS') ?: 24)),

        // Tệp mới chờ đẩy quá chừng này phút thì kiểm tra sức khoẻ kho báo VÀNG.
        'push_alert_minutes' => max(1, (int) (env('DOCUMENT_PUSH_ALERT_MINUTES') ?: 60)),

        /*
         * Khoá đẩy `document-push:{media_id}` (`DocumentStore::pushLock()`): store `database` (bảng
         * `cache_locks`), chung mọi tiến trình; hạn 2100 giây = `$timeout` 1800 của job đẩy + 300.
         * Hạn ngắn hơn thì khoá hết giữa một lượt tải gói 2 GB và lượt thứ hai chạy song song.
         */
        'lock_store' => 'database',
        'lock_ttl_seconds' => 2100,

        'google_drive' => [
            /*
             * Đường dẫn tệp khoá JSON của tài khoản dịch vụ — một tệp NGOÀI repo và ngoài gốc web
             * (R6). Nội dung khoá không bao giờ nằm trong `.env` hay trong CSDL.
             */
            'credentials_path' => $domain(env('GOOGLE_DRIVE_CREDENTIALS_PATH')),
            'shared_drive_id' => $domain(env('GOOGLE_DRIVE_SHARED_DRIVE_ID')),
            'root_folder_id' => $domain(env('GOOGLE_DRIVE_ROOT_FOLDER_ID')),

            /*
             * Thành viên được phép của Shared Drive ngoài tài khoản dịch vụ (R5), phân tách dấu
             * phẩy, mỗi mục dạng `email:vai`. Giá trị THÔ; phân tích và kiểm dạng ở nơi kiểm chia
             * sẻ.
             */
            'allowed_members' => $domain(env('GOOGLE_DRIVE_ALLOWED_MEMBERS')),

            // Thời gian chờ (giây, R9): kết nối; lệnh metadata; giữa hai khối khi tải xuống.
            'connect_timeout' => 5,
            'timeout' => 30,
            'read_timeout' => 60,

            /*
             * Cỡ một khối tải lên resumable, MiB (R9). Số nguyên 1–64, nên luôn là bội của 256 KiB
             * như Google đòi. Ngoài khoảng đó (vắng, trống, 0, âm, không phải số, quá 64) thì rơi về
             * 8, không chặn về biên: đó là cấu hình sai, và mỗi lượt tải giữ một khối trong bộ nhớ.
             * Không cần `?:` của thành ngữ chung: vắng và trống thành `(int)` 0, và 0 đã ngoài khoảng.
             */
            'chunk_mb' => (static fn (int $mb): int => $mb >= 1 && $mb <= 64 ? $mb : 8)(
                (int) env('GOOGLE_DRIVE_CHUNK_MB'),
            ),

            /*
             * Store `file`, không phải store mặc định `database`: access token (R6) và trạng thái
             * ngắt mạch (R9) không bao giờ vào bản sao lưu CSDL, và không phụ thuộc CSDL đang chậm.
             */
            'token_cache_store' => 'file',
            'breaker_store' => 'file',

            // Số mục của một Shared Drive: VÀNG từ 300.000, giới hạn của Google là 400.000.
            'item_warn' => 300000,
            'item_limit' => 400000,
        ],

        'office' => [
            /*
             * Remote rclone nơi máy văn phòng gửi biên nhận bản thứ hai (R10), ví dụ
             * `gdrive:VK-CRM-backups/office-receipts/vk-crm-production`. Trống = chưa có máy văn
             * phòng: vùng đệm không bao giờ được dọn.
             */
            'receipts_path' => $domain(env('DOCUMENT_OFFICE_RECEIPTS_PATH')),

            // Biên nhận gần nhất cũ hơn chừng này giờ thì báo VÀNG.
            'max_age_hours' => 36,

            // Bản trong vùng đệm chỉ được dọn khi biên nhận đã có ít nhất chừng này giờ (R10).
            'purge_margin_hours' => 24,

            // Trần một tệp biên nhận (32 MiB); lớn hơn thì từ chối cả tệp.
            'receipt_max_bytes' => 33554432,

            /*
             * Hạn (giây) cho mỗi lệnh `rclone` của lượt nhập biên nhận (`lsjson` thư mục biên nhận,
             * `cat` một tệp; M14 Task 7). Không dùng 1800 của sao lưu: lượt này chỉ đọc vài tệp
             * JSON, và một `rclone` treo không được giữ khoá của lượt 07:00 nửa giờ.
             */
            'rclone_timeout' => 120,
        ],
    ],
];
