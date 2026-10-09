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

    'mail_scheme_unsupported' => 'MAIL_SCHEME=:value không phải giá trị Laravel nhận — MỌI thư sẽ hỏng ngay '
        .'lúc dựng kết nối. Chỉ có ba cách ghi: smtps cho cổng 465, smtp hoặc để trống (null) cho cổng 587/25 '
        .'(STARTTLS tự bật). Không ghi tls hay ssl dù nhà cung cấp email gọi như vậy (docs/CAI-DAT.md, Bước 3, mục 4).',
    'mail_scheme_ok' => 'MAIL_SCHEME hợp lệ cho thư qua SMTP.',

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

    // Việc sau gộp M7 (làn fu2): giờ chết của worker gói bàn giao — xem RunPreflight::pcntlRow().
    'pcntl_missing' => 'PHP dòng lệnh thiếu extension pcntl — worker không giết được một job chạy '
        .'quá giờ. Một gói bàn giao lớn có thể chạy quá 10 phút mà không bị dừng: luật sư không được '
        .'báo lỗi, và sau 15 phút lượt chạy kế tiếp có thể dựng cùng gói đó một lần nữa vào cùng thư '
        .'mục. VÀNG chứ không ĐỎ (không màn hình nào vỡ) — bật pcntl cho PHP dòng lệnh (php.ini của '
        .'CLI) trước khi đóng vụ việc có nhiều tài liệu.',
    // Rà soát cuối làn fu2 (I1): pcntl đã nạp nhưng hàm bị chặn — worker không khởi động được.
    'pcntl_functions_disabled' => 'PHP dòng lệnh có extension pcntl nhưng không dùng được hàm '
        .':functions (thường do disable_functions trong php.ini của dòng lệnh). Laravel chỉ hỏi '
        .'pcntl đã nạp chưa, nên worker hàng đợi vẫn gọi các hàm đó ngay khi khởi động và chết với '
        .'lỗi "Call to undefined function" trước khi chạy job nào: mọi lượt queue:work (queue.drain '
        .'và queue.handover) đều hỏng, không thư nào được gửi, kể cả thư nhắc mốc thời hạn. Bỏ '
        .':functions khỏi disable_functions của PHP dòng lệnh.',
    'pcntl_ok' => 'PHP dòng lệnh có extension pcntl và dùng được các hàm :functions (worker hàng đợi '
        .'khởi động được, giờ chết của job gói bàn giao có tác dụng).',

    'brand_fields_missing' => 'Còn thiếu thông tin pháp lý của văn phòng: :fields — thư gửi khách '
        .'và PDF xuất ra sẽ thiếu các trường này cho tới khi điền. Chủ văn phòng điền ở trang '
        .'"Thông tin văn phòng" trong /admin (hoặc đặt biến tương ứng trong .env); giá trị nhập trong '
        .'app thắng giá trị trong .env.',
    'brand_fields_ok' => 'Đủ bốn thông tin pháp lý của văn phòng.',

    // M12 Task 4 (R7) — App\Support\Push\VapidKeys. VÀNG, không ĐỎ: app trên điện thoại vẫn cài và
    // chạy được, chỉ thông báo đẩy tắt. Không bao giờ in giá trị của khoá, chỉ tên biến.
    // Task 10 (rà soát Task 4, Minor 5): `config:clear` TRƯỚC `webpush:vapid` — lệnh của gói dò dòng
    // cũ trong `.env` theo khoá đang có trong CẤU HÌNH; cấu hình đã cache với khoá rỗng thì dòng
    // `VAPID_PUBLIC_KEY=cu` thành `VAPID_PUBLIC_KEY=moicu`. Và cache lại sau khi điền, không thì khoá
    // mới không có hiệu lực (docs/CAI-DAT.md, Bước 3, "Khoá thông báo đẩy").
    'vapid_missing' => 'Chưa có khoá thông báo đẩy (:variables) — thông báo đẩy trên điện thoại đang '
        .'TẮT: không ai bật được, hệ thống không gửi (email vẫn đi bình thường). Sinh MỘT lần cho '
        .'máy chủ này: php artisan config:clear, rồi php artisan webpush:vapid, điền '
        .'VAPID_SUBJECT=mailto:<hộp thư có người đọc của văn phòng>, chạy lại php artisan '
        .'vkcrm:preflight rồi php artisan optimize và chmod 600 bootstrap/cache/config.php, và cất VAPID_PRIVATE_KEY cùng '
        .'chỗ với APP_KEY.',
    'vapid_invalid' => 'VAPID_PUBLIC_KEY/VAPID_PRIVATE_KEY sai định dạng (khoá công khai 65 byte, '
        .'khoá riêng 32 byte, mã base64url) — thông báo đẩy đang TẮT. Dán lại đúng cặp khoá đã cất. '
        .'Đừng sinh cặp mới nếu đã có người bật thông báo: khoá mới làm mọi đăng ký cũ chết, và sau '
        .'khi đổi khoá phải chạy php artisan vkcrm:push-reset.',
    'vapid_subject_invalid' => 'VAPID_SUBJECT phải là "mailto:" kèm hộp thư có người đọc của văn '
        .'phòng (ví dụ mailto:lienhe@luatvukhang.com) hoặc một địa chỉ https:// — máy chủ đẩy của '
        .'Apple từ chối khi thiếu. Thông báo đẩy đang TẮT.',
    'vapid_ok' => 'Khoá thông báo đẩy (VAPID) đã khai báo.',

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

    // Lượt quét trước bản 1.0 (rà soát Task 1, m4) — RunPreflight::prospectRetentionRows().
    'prospect_retention_ignored' => 'PROSPECT_RETENTION_MONTHS có giá trị ":value" — không phải một số '
        .'nguyên từ 1 đến :max, nên bị bỏ qua: hạn lưu dữ liệu của người không thành khách đang là '
        .':months tháng (mặc định). Hết hạn đó dữ liệu bị ẩn danh và không lấy lại được. Sửa thành số '
        .'tháng chủ văn phòng đã chọn, hoặc để trống để dùng mặc định.',

    // M9 Task 13 — tầng 4 của bất biến tổng tiền, xem RunPreflight::billingInvariantsRow().
    'billing_invariants_mismatch' => 'Có :count hợp đồng đang có hiệu lực mà tổng các đợt thanh '
        .'toán khác giá trị hợp đồng: :codes. Màn hình tiền, công nợ và doanh thu đang tính sai cho '
        .'các hợp đồng này. Chạy php artisan billing:check-invariants để xem từng con số, rồi luật sư '
        .'phụ trách sửa bằng một phụ lục (không sửa thẳng vào CSDL). Dòng ĐỎ này không chặn mở cổng '
        .'(php artisan up): nó là dữ liệu, không phải cấu hình máy, và chỉ sửa được trong app — nên vẫn '
        .'mở cổng rồi sửa ngay. Mọi dòng ĐỎ khác vẫn chặn.',
    'billing_invariants_ok' => 'Tổng các đợt thanh toán khớp giá trị hợp đồng trên cả :count hợp '
        .'đồng đang có hiệu lực.',

    // M11 Task 16 — máy chủ MCP cho nhân sự, xem RunPreflight::mcpRedirectDomainsRow(),
    // passportTokenTtlRow(), passportKeysRow().
    'mcp_redirect_domains_wildcard' => 'mcp.redirect_domains (config/mcp.php của laravel/mcp) còn '
        .'"*": nếu route đăng ký client của gói được bật, mọi redirect URI trên mọi tên miền đều được '
        .'nhận. Đặt lại thành [] — app dùng allowlist chính xác của riêng nó (config/vkcrm.php, '
        .'mcp.redirect_uris, và MCP_EXTRA_REDIRECT_URIS).',
    'mcp_redirect_domains_ok' => 'mcp.redirect_domains (config/mcp.php) không có "*".',
    'passport_token_ttl_too_long' => 'Access token của kết nối AI (Passport) sống :minutes phút — '
        .'quá 60 phút (kế hoạch M11, R7). Token bị lộ dùng được lâu hơn. Đặt lại '
        .'Passport::tokensExpireIn(PT1H) ở AppServiceProvider.',
    'passport_token_ttl_ok' => 'Access token của kết nối AI (Passport) sống :minutes phút.',
    'passport_keys_missing' => 'Khoá ký token của Passport thiếu, không đọc được, hoặc không phải khoá '
        .'RSA: :keys. Không có khoá thì không nhân sự nào kết nối được AI (/oauth/token và /mcp hỏng). Chạy php artisan '
        .'passport:keys bằng người dùng chạy PHP-FPM, hoặc dán đúng nội dung khoá vào '
        .'PASSPORT_PRIVATE_KEY/PASSPORT_PUBLIC_KEY; rồi cất khoá cùng chỗ với APP_KEY '
        .'(docs/CAI-DAT.md, Bước 3).',
    'passport_private_key_exposed' => 'Khoá riêng của Passport (:path) có quyền :mode — người dùng '
        .'khác trên máy chủ đọc được nó và tự ký access token cho bất kỳ nhân sự nào. Chạy chmod 600 '
        .'(hoặc 640/660 nếu nhóm của PHP-FPM cần đọc) cho tệp này.',
    'passport_keys_mismatch' => 'Khoá công khai của Passport không cùng cặp với khoá riêng: mọi kết '
        .'nối AI sẽ hỏng chữ ký (401). Dán lại PASSPORT_PUBLIC_KEY (hoặc tệp oauth-public.key) từ ĐÚNG '
        .'cặp của khoá riêng đang dùng, hoặc chạy lại php artisan passport:keys --force rồi cất cả hai '
        .'khoá cùng chỗ với APP_KEY (docs/CAI-DAT.md, Bước 3).',
    'passport_keys_ok' => 'Khoá ký token của Passport có đủ, là một cặp RSA, đọc được, và khoá riêng không '
        .'mở cho người dùng khác.',
    // M11 Task 16 (R12 mục 3), RunPreflight::mcpFilingDateRow().
    'mcp_filing_date_missing' => 'Máy chủ MCP đang bật (Kết nối AI) nhưng chưa ghi ngày đã nộp hồ sơ '
        .'đánh giá tác động chuyển dữ liệu cá nhân ra nước ngoài (hạn 60 ngày kể từ lần chuyển đầu '
        .'tiên). Nộp hồ sơ rồi ghi ngày ở trang "Kết nối AI" (docs/CHINH-SACH-AI.md).',
    'mcp_filing_date_ok' => 'Máy chủ MCP đang bật; hồ sơ đánh giá tác động đã nộp ngày :date.',
    'mcp_filing_date_server_off' => 'Máy chủ MCP đang tắt (Kết nối AI): chưa chuyển dữ liệu nào cho nền '
        .'tảng AI.',

    'summary_red' => 'Có mục ĐỎ — KHÔNG mở cổng cho tới khi sửa hết.',
    // Rà soát cuối làn m9f, I2 — xem RunPreflight::blocksOpening().
    'summary_red_billing_only' => 'Mục ĐỎ duy nhất là bất biến tiền — dữ liệu, không phải cấu hình máy: '
        .'vẫn mở cổng (php artisan up), rồi luật sư phụ trách ký ngay phụ lục cho từng hợp đồng lệch. '
        .'Mã thoát vẫn khác 0 cho tới khi sạch.',
    'summary_yellow' => 'Không có mục ĐỎ, còn mục VÀNG cần chú ý.',
    'summary_ok' => 'Mọi điều kiện ra mắt đều đạt.',
];
