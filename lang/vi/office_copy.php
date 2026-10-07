<?php

/**
 * Bản thứ hai ở máy chủ văn phòng (M14 Task 7, kế hoạch R10): biên nhận của `office-pull.sh` và lượt
 * CRM nhập chúng (`App\Actions\Storage\ImportOfficeReceipts`, lệnh `vkcrm:storage:office-receipts`).
 * Tệp riêng, không nối vào `storage.php`, để làn m14 và làn m14b không cùng sửa một khối khi gộp
 * (phán quyết C4 của controller).
 *
 * Luật cho mọi chuỗi ở đây (cùng luật của `storage.php`): không mã Drive (`team_drive`,
 * `root_folder_id`, `file_id`), không khoá đối tượng, không tiêu đề tài liệu, mã hồ sơ hay tên khách.
 * Chỉ số đếm và tên tệp biên nhận (`receipt-<UTC>.json`). Các câu ở `import` đi vào cột
 * `system_health.last_office_receipt_error`, nên cũng đi vào thư cảnh báo kho và dòng
 * `document_office_copy`.
 */
return [
    // App\Exceptions\OfficeReceiptRejected — lý do cả tệp biên nhận bị từ chối (App\Support\Storage\OfficeReceipt).
    'receipt' => [
        'not_json' => 'nội dung không phải JSON hợp lệ.',
        'format' => 'khuôn biên nhận không phải bản 1 ("format": 1).',
        'team_drive' => 'biên nhận của một Shared Drive khác Shared Drive kho đang cấu hình (GOOGLE_DRIVE_SHARED_DRIVE_ID); remote vkkho trên máy văn phòng trỏ sai kho.',
        'root_folder' => 'biên nhận của một thư mục gốc khác thư mục gốc đang cấu hình (GOOGLE_DRIVE_ROOT_FOLDER_ID); remote vkkho trên máy văn phòng trỏ sai thư mục.',
        'started_at' => 'giờ bắt đầu ("started_at") không phải thời điểm ISO-8601 có múi giờ.',
        'started_in_future' => 'giờ bắt đầu ("started_at") ở tương lai quá :minutes phút so với máy chủ; kiểm đồng hồ (NTP) của máy văn phòng.',
        'errors' => 'số lỗi ("errors") không phải số nguyên không âm.',
        'files' => 'danh sách tệp ("files") không phải một mảng.',
        'line' => 'dòng thứ :line của danh sách tệp sai khuôn (cần "name" là chuỗi khác rỗng, "md5" là 32 ký tự hex thường, "size" là số nguyên không âm).',
        'too_large' => 'tệp lớn hơn trần :max byte.',
    ],

    'import' => [
        'rejected' => 'Biên nhận :file bị từ chối, không tệp nào của nó được ghi nhận: :reason',
        'office_errors' => 'Biên nhận :file: máy văn phòng báo :count lỗi; có thể có tệp bị đổi trên kho. Xem nhật ký office-pull.log trên máy văn phòng.',
        'mismatched' => 'Biên nhận :file: :count tệp khớp tên và thế hệ của chỉ mục nhưng khác md5 hoặc cỡ; có thể có tệp bị đổi trên kho. Chạy vkcrm:storage:verify.',
        'log' => [
            'rejected' => 'Bản thứ hai: biên nhận văn phòng bị từ chối.',
            'imported' => 'Bản thứ hai: đã nhập biên nhận văn phòng.',
        ],
    ],

    // Đầu ra của `vkcrm:storage:office-receipts` cho người vận hành.
    'command' => [
        'not_configured' => 'Chưa cấu hình máy văn phòng (DOCUMENT_OFFICE_RECEIPTS_PATH, GOOGLE_DRIVE_SHARED_DRIVE_ID hoặc GOOGLE_DRIVE_ROOT_FOLDER_ID trống): không có gì để nhập. Vùng đệm trên máy chủ không bao giờ được dọn khi chưa có bản thứ hai.',
        'busy' => 'Một lượt nhập biên nhận khác đang chạy (khoá storage-office-receipts). Thử lại sau ít phút.',
        'imported' => 'Biên nhận đã nhập: :count',
        'rejected' => 'Biên nhận bị từ chối: :count',
        'marked' => 'Tệp được đánh dấu có bản ở văn phòng: :count',
        'already' => 'Tệp đã có biên nhận từ trước: :count',
        'unmatched' => 'Tệp không khớp dòng sống nào của chỉ mục: :count (đã vào thùng rác, thế hệ cũ, hoặc kho khác)',
        'mismatched' => 'Tệp khớp tên và thế hệ nhưng khác md5 hoặc cỡ: :count',
        'unknown' => 'Tên tệp lạ: :count (không phải khoá của thư viện tài liệu)',
        'rclone_failed' => 'Lệnh rclone thất bại; đã gửi thư báo lỗi sao lưu (rclone:office-receipts). Lượt sau đọc lại từ tệp chưa nhập.',
    ],
];
