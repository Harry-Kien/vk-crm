<?php

/**
 * Kho tài liệu (M14): Google Drive (Shared Drive của văn phòng) làm kho phía sau CRM. Mọi chữ hiển
 * thị của tính năng — thông điệp ngoại lệ, trang 503, thư cảnh báo, dòng kiểm sẵn sàng, đầu ra lệnh
 * Artisan cho người vận hành — đi qua tệp này (`__('storage.…')`).
 *
 * Luật cho mọi chuỗi ở đây: không mã tệp Drive, không bí mật, không tiêu đề tài liệu, mã hồ sơ hay
 * tên khách. Chỉ số đếm, mã `media`/`document` và khoá mờ (`<media_id>/<ulid>.<đuôi>`).
 */
return [
    'exceptions' => [
        // App\Exceptions\DocumentStorageUnavailable — lỗi TẠM THỜI; người tải thấy trang 503.
        'unavailable' => 'Kho tài liệu tạm thời chưa truy cập được. Tài liệu vẫn được lưu an toàn; vui lòng thử lại sau ít phút.',
        // App\Exceptions\StoredFileMissing — chỉ mục nói có, kho nói không. Chỉ đi vào log.
        'stored_file_missing' => 'Kho tài liệu không còn tệp có khoá :key, dù chỉ mục của CRM vẫn ghi là có.',

        // App\Exceptions\DocumentStorageMisconfigured — lỗi CẤU HÌNH; thử lại không giúp gì (R9).
        'not_configured' => 'Kho tài liệu Google Drive chưa cấu hình đủ: thiếu :missing. Báo người quản trị hệ thống.',
        'credentials_missing' => 'Chưa cấu hình đường dẫn khoá tài khoản dịch vụ Google (GOOGLE_DRIVE_CREDENTIALS_PATH).',
        'credentials_unusable' => 'Không dùng được khoá tài khoản dịch vụ Google (GOOGLE_DRIVE_CREDENTIALS_PATH): tệp không có, không đọc được, hoặc không phải khoá JSON hợp lệ của một tài khoản dịch vụ.',
        'token_rejected' => 'Google từ chối cấp quyền truy cập cho tài khoản dịch vụ (:error). Kiểm khoá JSON còn hiệu lực, và đồng hồ máy chủ đúng giờ (NTP).',
        'credentials_rejected' => 'Google Drive từ chối thông tin đăng nhập của tài khoản dịch vụ, kể cả sau khi đã lấy access token mới.',
        'container_not_found' => 'Không tìm thấy Shared Drive hoặc thư mục của kho trên Google Drive. Kiểm GOOGLE_DRIVE_SHARED_DRIVE_ID, GOOGLE_DRIVE_ROOT_FOLDER_ID, và tài khoản dịch vụ còn là thành viên của Shared Drive.',
        'drive_rejected' => 'Google Drive từ chối yêu cầu (mã :status, lý do :reason). Thử lại không giúp gì; báo người quản trị hệ thống.',
        'drive_reasons' => [
            'storageQuotaExceeded' => 'Kho Google Drive đã hết dung lượng lưu trữ.',
            'teamDriveFileLimitExceeded' => 'Shared Drive của kho đã chạm giới hạn 400.000 mục của Google.',
            'numChildrenInNonRootLimitExceeded' => 'Một thư mục của kho đã chạm giới hạn 500.000 mục con của Google.',
            'insufficientFilePermissions' => 'Tài khoản dịch vụ không đủ quyền trên kho: cần đúng vai "Người quản lý nội dung" của Shared Drive.',
            'teamDriveMembershipRequired' => 'Tài khoản dịch vụ không còn là thành viên của Shared Drive kho.',
        ],
    ],

    // App\Support\Storage\GoogleDrive — chỉ đi vào log và ngoại lệ nội bộ, không bao giờ tới người dùng.
    'drive' => [
        'api_error' => 'Google Drive :operation: HTTP :status, lý do :reason, lần thử :attempt.',
        'checksum_mismatch' => 'Google Drive :operation: tệp vừa lên kho lệch md5 hoặc kích thước so với bản gửi đi.',
        'stream_size_mismatch' => 'Luồng tải lên không đúng kích thước đã khai (khai :declared byte, đọc được :read byte).',
        'upload_protocol' => 'Google Drive trả về vị trí tải lên không hợp lệ cho phiên resumable.',
        'immutable_key' => 'Khoá đã có tệp trên kho; tệp hồ sơ là bất biến, không ghi đè (R8).',
        'index_write_failed' => 'Đã tải tệp lên kho nhưng không ghi được chỉ mục; bản vừa tải đã được cho vào thùng rác.',
        'not_indexed' => 'Khoá không có trong chỉ mục kho.',
        'trashed' => 'Tệp đã nằm trong thùng rác của kho.',
        'checksum_algo' => 'Kho chỉ cung cấp md5 do Google tính.',
        'root_directory' => 'Không xoá cả thư mục gốc của kho.',
        'log' => [
            'request_failed' => 'Google Drive: lệnh gọi thất bại.',
            'token_failed' => 'Google Drive: lấy access token thất bại.',
            'credentials_unusable' => 'Google Drive: không dùng được khoá tài khoản dịch vụ.',
            'breaker_opened' => 'Google Drive: ngắt mạch mở, tạm ngừng gọi kho.',
            'trash_after_failure' => 'Google Drive: không cho được bản tải lên hỏng vào thùng rác; tệp mồ côi, vkcrm:storage:orphans sẽ báo.',
        ],
    ],
];
