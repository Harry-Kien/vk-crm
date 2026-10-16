<?php

/*
 * Làn st (chủ văn phòng 2026-10-15): trung tâm "Cài đặt" của khu quản trị và bốn trang con — "Cài đặt
 * nghiệp vụ" (App\Filament\Admin\Pages\Settings\BusinessSettingsPage), "Vai trò & quyền", "Bảo mật đăng
 * nhập", "Thư điện tử". Chỉ quản trị viên vào được.
 */
return [
    'breadcrumb' => 'Cài đặt',

    // -----------------------------------------------------------------------------------------
    // Trang tổng "Cài đặt"
    // -----------------------------------------------------------------------------------------
    'hub' => [
        'navigation_label' => 'Cài đặt',
        'title' => 'Cài đặt',
        'intro' => 'Mọi cấu hình của hệ thống ở một chỗ. Bấm vào một thẻ để mở trang cài đặt của nó; trạng thái trên thẻ là trạng thái đang chạy thật.',
        'search' => [
            'label' => 'Tìm một cài đặt',
            'placeholder' => 'Ví dụ: thư, cổng khách, 2FA, Google Drive…',
            'empty' => 'Không có cài đặt nào khớp ":term".',
        ],
        'server_only' => 'Đặt trên máy chủ — xem CAI-DAT',
        'open' => 'Mở',

        'groups' => [
            'office' => 'Văn phòng',
            'people' => 'Người dùng & phân quyền',
            'matters' => 'Vụ việc & tiếp nhận',
            'portal' => 'Cổng khách hàng & thông báo',
            'finance' => 'Tài chính',
            'documents' => 'Tài liệu & lưu trữ',
            'ai' => 'Trợ lý AI',
        ],

        'cards' => [
            'office_profile' => [
                'title' => 'Thông tin văn phòng',
                'description' => 'Tên pháp lý, mã số thuế, địa chỉ, hotline, email liên hệ in trên thư và cổng khách.',
                'status' => 'Thông tin pháp lý: :filled/:total đã điền',
            ],
            'matter_types' => [
                'title' => 'Loại vụ việc & giai đoạn',
                'description' => 'Danh mục loại vụ việc, các giai đoạn và danh mục giấy tờ mẫu của từng loại.',
                'status' => ':count loại vụ việc',
            ],
            'staff' => [
                'title' => 'Nhân sự',
                'description' => 'Thêm, sửa, vô hiệu hoá tài khoản nhân sự và chọn vai trò của từng người.',
                'status' => ':count người đang hoạt động',
            ],
            'roles' => [
                'title' => 'Vai trò & quyền',
                'description' => 'Bảng quyền của sáu vai trò — ai được xem, sửa, công bố, thu tiền.',
                'status' => ':roles vai trò · :permissions quyền',
            ],
            'security' => [
                'title' => 'Bảo mật đăng nhập',
                'description' => '2FA bắt buộc, khoá đăng nhập sai, giới hạn IP, HTTPS, quét virus, sao lưu.',
                'status' => '2FA: :done/:total nhân sự đã cài',
            ],
            'matter_rules' => [
                'title' => 'Vụ việc',
                'description' => 'Khách còn xem hồ sơ bao lâu sau khi vụ kết thúc; thư báo hồ sơ lâu chưa cập nhật cho khách.',
                'status' => 'Khách xem :days ngày sau khi kết thúc · Thư hồ sơ đứng im: :mail',
            ],
            'intake_rules' => [
                'title' => 'Tiếp nhận khách tiềm năng',
                'description' => 'Thời hạn phản hồi lần đầu một người liên hệ văn phòng trước khi hệ thống nhắc.',
                'status' => 'Phản hồi trong :hours giờ làm việc',
            ],
            'portal_rules' => [
                'title' => 'Cổng khách hàng',
                'description' => 'Khách gửi yêu cầu mới trên cổng; dung lượng tối đa một tệp tải lên.',
                'status' => 'Yêu cầu mới: :requests · Tệp tối đa :mb MB',
            ],
            'client_notifications' => [
                'title' => 'Thông báo tự động cho khách',
                'description' => 'Thư nhắc khách nộp giấy tờ còn thiếu (thứ Hai, Tư, Sáu).',
                'status' => 'Nhắc nộp giấy tờ: :state',
            ],
            'mail' => [
                'title' => 'Thư điện tử',
                'description' => 'Máy chủ gửi thư, địa chỉ gửi đi, gửi một thư thử tới chính bạn.',
            ],
            'client_payments' => [
                'title' => 'Nhắc thanh toán cho khách',
                'description' => 'Công tắc nhắc lịch thanh toán, thông tin chuyển khoản và mã VietQR.',
                'status' => 'Nhắc thanh toán: :state',
            ],
            'document_store' => [
                'title' => 'Kho tài liệu',
                'description' => 'Nơi lưu tệp hồ sơ: máy chủ hay Google Drive dùng chung của văn phòng.',
                'status_remote' => 'Google Drive: Đang dùng',
                'status_local' => 'Google Drive: Chưa bật (lưu trên máy chủ)',
            ],
            'backup' => [
                'title' => 'Sao lưu',
                'description' => 'Bản sao lưu hằng đêm và bản sao ngoài máy chủ (Google Drive).',
                'status_none' => 'Chưa cấu hình đích sao lưu ngoài máy chủ',
                'status_never' => 'Chưa có bản sao nào đã xác minh ngoài máy chủ',
                'status_last' => 'Bản đã xác minh gần nhất: :at',
            ],
            'activity_log' => [
                'title' => 'Nhật ký hệ thống',
                'description' => 'Ai đã làm gì, lúc nào — kể cả mọi lần đổi cài đặt.',
            ],
            'ai' => [
                'title' => 'Kết nối AI',
                'description' => 'Cho nhân sự dùng trợ lý AI cá nhân đọc/ghi hồ sơ qua máy chủ MCP.',
                'status' => 'Kết nối AI: :state',
            ],
        ],
    ],

    'state' => [
        'on' => 'Bật',
        'off' => 'Tắt',
    ],

    // -----------------------------------------------------------------------------------------
    // "Cài đặt nghiệp vụ"
    // -----------------------------------------------------------------------------------------
    'business' => [
        'title' => 'Cài đặt nghiệp vụ',
        'intro' => 'Các công tắc và thông số dưới đây chỉnh được ngay tại đây, có hiệu lực từ lần dùng kế tiếp. Giá trị lưu ở đây thắng giá trị trong tệp cấu hình của máy chủ (.env); chưa đổi thì hệ thống chạy đúng như trước.',
        'audit_hint' => 'Mỗi lần lưu được ghi vào Nhật ký hệ thống: ai đổi, đổi gì, giá trị cũ và mới.',
        'submit' => 'Lưu cài đặt',

        'sections' => [
            'matters' => 'Vụ việc',
            'intake' => 'Tiếp nhận khách tiềm năng',
            'portal' => 'Cổng khách hàng',
            'client_notifications' => 'Thông báo tự động cho khách',
        ],

        'source' => [
            'server' => 'Đang theo giá trị của máy chủ (.env).',
            'screen' => 'Đã đặt tại đây.',
            'default' => 'Mặc định của máy chủ: :value.',
        ],

        'fields' => [
            'client_access_days' => [
                'label' => 'Số ngày khách còn xem hồ sơ sau khi vụ kết thúc',
                'suffix' => 'ngày',
                'hint' => 'Từ :min đến :max ngày. Áp dụng cho vụ kết thúc từ nay; vụ đã kết thúc giữ hạn cũ (gia hạn từng vụ bằng nút "Gia hạn truy cập").',
            ],
            'stale_matter_mail' => [
                'label' => 'Gửi thư khi hồ sơ quá 21 ngày chưa cập nhật cho khách',
                'hint' => 'Thư tới luật sư phụ trách và cấp trên. Tắt thì vẫn còn chuông nhắc trong hệ thống sau 14 ngày và cảnh báo trên trang chủ.',
            ],
            'intake_response_hours' => [
                'label' => 'Thời hạn phản hồi lần đầu một người liên hệ',
                'suffix' => 'giờ làm việc',
                'hint' => 'Từ :min đến :max giờ làm việc. Quá hạn mà chưa ai gọi lại thì người được giao (hoặc trưởng phòng) nhận chuông và thư nhắc.',
            ],
            'client_requests' => [
                'label' => 'Khách gửi yêu cầu mới trên cổng',
                'hint' => 'Tắt thì cổng ẩn ô "Gửi yêu cầu mới" và từ chối yêu cầu mới; các cuộc trao đổi đã có vẫn viết tiếp được ở cả hai phía.',
            ],
            'upload_max_mb' => [
                'label' => 'Dung lượng tối đa một tệp tải lên',
                'suffix' => 'MB',
                'hint' => 'Áp dụng cho tệp nhân sự tải lên và tệp khách nộp trên cổng.',
                'ceiling' => 'Trần của máy chủ: :max MB (muốn cao hơn phải nâng UPLOAD_MAX_MB và giới hạn PHP trên máy chủ, xem CAI-DAT).',
            ],
            'missing_documents_mail' => [
                'label' => 'Tự động nhắc khách nộp giấy tờ còn thiếu',
                'hint' => 'Thư gửi tài khoản cổng của khách vào sáng thứ Hai, Tư, Sáu. Tắt thì luật sư vẫn nhận chuông khi hồ sơ thiếu giấy tờ quá 14 ngày.',
            ],
        ],

        'fixed' => [
            'heading' => 'Đặt theo luật hoặc trên máy chủ — không đổi tại đây',
            'where' => 'Những giá trị này là quy định pháp lý hoặc an toàn của hệ thống; đổi trong tệp cấu hình của máy chủ theo hướng dẫn CAI-DAT, sau khi luật sư xác nhận.',
            'retention_years' => 'Thời hạn lưu trữ hồ sơ sau khi kết thúc: :years năm (RETENTION_YEARS).',
            'prospect_retention_months' => 'Thời hạn giữ dữ liệu người liên hệ không thành khách: :months tháng (PROSPECT_RETENTION_MONTHS) — gắn với câu thông báo xử lý dữ liệu cá nhân.',
            'staleness' => 'Hồ sơ "chưa cập nhật cho khách": tô vàng sau :warning ngày, đỏ và chuông sau :danger ngày, thư sau :mail ngày (SPEC §6.4).',
            'deadlines' => 'Nhắc mốc thời hạn trước :tiers ngày và khi quá hạn (SPEC §6.5).',
        ],

        'reset' => [
            'label' => 'Đưa tất cả về mặc định',
            'heading' => 'Đưa mọi cài đặt nghiệp vụ về giá trị của máy chủ?',
            'body' => 'Các giá trị đã đặt tại đây sẽ bị xoá; hệ thống theo lại tệp cấu hình của máy chủ (.env). Lần đổi này được ghi vào nhật ký.',
            'confirm' => 'Đưa về mặc định',
        ],

        'notifications' => [
            'saved' => 'Đã lưu cài đặt nghiệp vụ.',
            'unchanged' => 'Không có gì thay đổi.',
            'reset' => 'Đã đưa cài đặt nghiệp vụ về giá trị của máy chủ.',
        ],

        // Cổng khách hàng, trang "Yêu cầu" khi công tắc "khách gửi yêu cầu mới" đang tắt.
        'portal_requests_off' => 'Văn phòng tạm không nhận yêu cầu mới qua cổng. Anh/chị vẫn trả lời được các cuộc trao đổi bên dưới, hoặc gọi hotline của văn phòng.',
        'portal_requests_off_hotline' => 'Hotline: :hotline',
        'portal_requests_refused' => 'Văn phòng tạm không nhận yêu cầu mới qua cổng. Vui lòng gọi hotline của văn phòng hoặc trả lời trong một cuộc trao đổi đã có.',
    ],

    // -----------------------------------------------------------------------------------------
    // "Vai trò & quyền" (đọc-chỉ)
    // -----------------------------------------------------------------------------------------
    'roles' => [
        'title' => 'Vai trò & quyền',
        'intro' => 'Mỗi nhân sự mang một vai trò; bảng dưới cho biết mỗi vai trò được làm gì. Dấu ✓ là có quyền, dấu — là không.',
        'read_only' => 'Quyền theo vai do hệ thống quy định (SPEC §5); đổi vai của một người ở trang Nhân sự.',
        'staff_link' => 'Mở trang Nhân sự',
        'permission_column' => 'Quyền',
        'people' => ':count người',
        'granted' => 'Có quyền',
        'not_granted' => 'Không có quyền',
        'groups' => [
            'matters' => 'Vụ việc',
            'documents' => 'Tài liệu & giấy tờ',
            'clients' => 'Khách hàng & cổng khách',
            'money' => 'Hợp đồng & tiền',
            'intake' => 'Tiếp nhận khách tiềm năng',
            'system' => 'Hệ thống',
            'other' => 'Quyền khác',
        ],
    ],

    // -----------------------------------------------------------------------------------------
    // "Bảo mật đăng nhập" (đọc-chỉ)
    // -----------------------------------------------------------------------------------------
    'security' => [
        'title' => 'Bảo mật đăng nhập',
        'intro' => 'Tình trạng bảo mật đang chạy thật trên máy chủ này.',
        'read_only' => 'Các mục dưới đây là quy định bảo mật hoặc cấu hình của máy chủ nên không đổi được trên giao diện — mỗi dòng ghi nơi đặt nó (xem hướng dẫn CAI-DAT).',
        'rows' => [
            'two_factor' => [
                'label' => 'Xác thực hai lớp (2FA) cho nhân sự',
                'value' => 'Luôn bắt buộc',
                'where' => 'Quy định của hệ thống (SPEC §10), không tắt được. Nhân sự chưa cài sẽ được dẫn tới trang cài đặt ở lần đăng nhập kế tiếp.',
            ],
            'two_factor_staff' => [
                'label' => 'Nhân sự đã cài 2FA',
                'value' => ':done đã cài · :missing chưa cài',
                'where' => 'Đếm trên nhân sự đang hoạt động. Người quên thiết bị: "Đặt lại 2FA" ở trang Nhân sự.',
                'names' => 'Chưa cài: :names',
            ],
            'lockout' => [
                'label' => 'Khoá đăng nhập khi nhập sai',
                'value' => 'Sau :attempts lần sai trong :minutes phút',
                'where' => 'Cố định theo SPEC §10.3, cho cả mật khẩu lẫn mã 2FA, theo email và theo địa chỉ IP.',
            ],
            'ip_allowlist' => [
                'label' => 'Giới hạn địa chỉ IP vào khu quản trị',
                'on' => 'Bật — :count dải IP được phép',
                'off' => 'Tắt — mọi địa chỉ IP vào được trang đăng nhập',
                'where' => 'Đặt bằng ADMIN_IP_ALLOWLIST trong .env của máy chủ.',
            ],
            'https' => [
                'label' => 'Bắt buộc HTTPS',
                'on' => 'Bật',
                'off' => 'Tắt',
                'where' => 'Đặt bằng FORCE_HTTPS trong .env (để trống = bật ở máy chủ thật).',
            ],
            'hsts' => [
                'label' => 'HSTS (trình duyệt chỉ dùng HTTPS)',
                'on' => 'Bật — :days ngày',
                'off' => 'Tắt',
                'where' => 'Đặt bằng HSTS_MAX_AGE trong .env (để trống = 1 năm ở máy chủ thật).',
            ],
            'csp' => [
                'label' => 'Chính sách nội dung (CSP)',
                'enforce' => 'Thi hành',
                'report' => 'Chỉ báo cáo, không chặn',
                'off' => 'Tắt',
                'where' => 'Đặt bằng CSP_MODE trong .env (để trống = thi hành ở máy chủ thật).',
            ],
            'clamav' => [
                'label' => 'Quét virus tệp tải lên (ClamAV)',
                'on' => 'Bật',
                'off' => 'Tắt',
                'where' => 'Đặt bằng CLAMAV_ENABLED và CLAMAV_SOCKET trong .env; máy chủ phải chạy clamd.',
            ],
            'backup' => [
                'label' => 'Sao lưu ngoài máy chủ (Google Drive)',
                'not_configured' => 'Chưa cấu hình đích sao lưu ngoài máy chủ',
                'never' => 'Chưa có bản nào đã xác minh',
                'last' => 'Bản đã xác minh gần nhất: :at',
                'where' => 'Đặt bằng BACKUP_RCLONE_REMOTE trong .env; xem hướng dẫn SAO-LUU-KHOI-PHUC.',
            ],
        ],
    ],

    // -----------------------------------------------------------------------------------------
    // "Thư điện tử"
    // -----------------------------------------------------------------------------------------
    'mail' => [
        'title' => 'Thư điện tử',
        'intro' => 'Hệ thống gửi thư nhắc hạn, thư cho khách, mã đăng nhập… qua máy chủ thư dưới đây. Bấm "Gửi thư thử" để kiểm tra thư có tới hộp thư của bạn không.',
        'not_configured' => 'Máy chủ này chưa cấu hình gửi thư thật — thư chỉ được ghi lại, không tới ai.',
        'status_heading' => 'Đang gửi thư thế nào',
        'unset' => 'Chưa đặt',
        'fields' => [
            'transport' => 'Đường gửi',
            'from_address' => 'Địa chỉ gửi đi',
            'from_name' => 'Tên người gửi',
            'reply_to' => 'Trả lời về (Reply-To)',
            'queue' => 'Hàng đợi',
        ],
        'transport' => [
            'smtp' => 'Máy chủ SMTP :host (cổng :port)',
            'log' => 'Ghi ra tệp nhật ký của máy chủ (không gửi thật)',
            'array' => 'Giữ trong bộ nhớ (môi trường thử, không gửi thật)',
            'other' => 'Dịch vụ gửi thư ":mailer"',
        ],
        'queue' => 'Thư đi qua hàng đợi ":queue"',
        'where' => 'Máy chủ thư, tài khoản và mật khẩu đặt bằng MAIL_* trong .env của máy chủ (xem CAI-DAT); "Trả lời về" là Email liên hệ ở trang Thông tin văn phòng.',

        'test' => [
            'label' => 'Gửi thư thử',
            'heading' => 'Gửi một thư thử?',
            'body' => 'Một thư thử sẽ được gửi tới :email (email của bạn) qua hàng đợi. Tối đa 3 lần mỗi 10 phút.',
            'confirm' => 'Gửi thư thử',
            'queued' => 'Đã xếp thư thử tới :email. Vài phút nữa xem hộp thư, hoặc xem kết quả ngay dưới đây và ở Thư đã gửi.',
            'throttled' => 'Bạn đã gửi :max thư thử trong 10 phút. Hãy thử lại sau khoảng :minutes phút.',
        ],

        'recent' => [
            'heading' => 'Thư thử gần đây',
            'hint' => 'Kết quả theo sổ thư: "Đã gửi" là máy chủ thư đã nhận thư; "Lỗi" thì xem lý do ở Thư đã gửi.',
            'empty' => 'Chưa có thư thử nào.',
            'open_ledger' => 'Mở Thư đã gửi',
        ],

        'email' => [
            'subject' => 'Thư thử từ hệ thống quản lý khách hàng',
            'greeting' => 'Chào :name,',
            'line' => 'Đây là thư thử gửi lúc :at từ trang Cài đặt › Thư điện tử.',
            'transport' => 'Thư đi qua: :transport.',
            'action' => 'Bạn nhận được thư này nghĩa là máy chủ thư đang hoạt động. Không cần trả lời.',
            'salutation' => 'Hệ thống VK-CRM — :office',
        ],
    ],
];
