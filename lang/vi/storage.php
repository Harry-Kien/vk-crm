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

    // M14 Task 3: đẩy tệp từ vùng đệm lên kho, dọn vùng đệm (App\Actions\Storage\PushDocumentFileToRemote,
    // App\Actions\Schedule\PurgeStagedDocumentCopies, App\Listeners\DiscardStagedCopyOnMediaDeleted).
    // Chỉ đi vào log và ngoại lệ nội bộ; ngữ cảnh log chỉ mang mã media và khoá mờ.
    'push' => [
        // App\Exceptions\StoredFileMissing::staged()
        'staged_file_missing' => 'Vùng đệm của máy chủ không còn tệp có khoá :key, dù media vẫn ghi tệp nằm ở máy chủ.',
        'checksum_mismatch' => 'Bản trên kho của khoá :key lệch md5 hoặc kích thước so với bản trong vùng đệm; đã cho bản trên kho vào thùng rác, lượt sau tải lại.',
        'write_failed' => 'Kho tài liệu không nhận bản ghi của khoá :key.',
        'log' => [
            'key_rejected' => 'Đẩy tệp lên kho: không đẩy media này — khoá không đúng khuôn <media_id>/<ULID>.<đuôi> của kho, hoặc media không nằm ở vùng đệm. Tệp ở lại máy chủ.',
            'staged_missing' => 'Đẩy tệp lên kho: media còn ghi tệp ở máy chủ mà vùng đệm không còn tệp. Không đổi đĩa.',
            'checksum_mismatch' => 'Đẩy tệp lên kho: bản trên kho lệch md5 hoặc kích thước; đã cho vào thùng rác.',
            'gone_trash_failed' => 'Đẩy tệp lên kho: media đã bị xoá nhưng không cho được bản vừa tải vào thùng rác; tệp mồ côi, vkcrm:storage:orphans sẽ báo.',
            'staged_discard_failed' => 'Không xoá được bản trong vùng đệm của một media; lượt sau thử lại hoặc người vận hành xoá tay.',
        ],
    ],

    // M14 Task 4: đọc qua CRM (App\Actions\Storage\OpenStoredFile, App\Actions\Storage\MaterialiseStoredFile).
    // Chỉ đi vào log và ngoại lệ nội bộ; ngữ cảnh log chỉ mang mã media, đĩa và khoá mờ.
    'read' => [
        'checksum_mismatch' => 'Bản tải về từ kho của khoá :key lệch md5 hoặc kích thước so với dòng media.',
        'log' => [
            'missing' => 'Đọc tệp hồ sơ: dòng media ghi tệp có mà nơi chứa không còn (kho trả 404, chỉ mục không có khoá, hay đĩa không mở được). Trả 404 cho người tải.',
            'checksum_mismatch' => 'Tải tệp từ kho về máy chủ: lệch md5 hoặc kích thước; không dùng bản tải về.',
            'read_failed' => 'Tải tệp từ kho về máy chủ: kết nối tới kho hỏng giữa lúc đọc tệp; đã xoá bản tải dở.',
            'free_space_unknown' => 'Gói bàn giao: không đo được chỗ trống của ổ đĩa (hàm disk_free_space bị tắt hoặc không trả lời); bỏ qua bước kiểm chỗ trống.',
        ],
    ],

    // M14 Task 4: trang 503 khi kho tài liệu tạm thời chưa truy cập được
    // (resources/views/errors/storage-unavailable.blade.php), cho cả nhân sự lẫn khách (R9).
    // Câu chính là `exceptions.unavailable` ở trên. Không chi tiết kỹ thuật.
    'unavailable_page' => [
        'title' => 'Kho tài liệu tạm thời chưa truy cập được',
        'heading' => 'Chưa tải được tài liệu lúc này',
        'call_lead' => 'Nếu cần gấp, anh/chị gọi văn phòng:',
        'call' => 'Gọi :hotline',
        'home' => 'Về trang chủ',
    ],
];
