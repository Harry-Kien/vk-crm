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
        // App\Exceptions\DocumentStorageMisconfigured — adapter giữ chỗ của M14 Task 1.
        'adapter_not_installed' => 'Kho tài liệu Google Drive chưa dùng được: bản cài đặt này chưa có trình kết nối Google Drive. Báo người quản trị hệ thống.',
        // App\Exceptions\StoredFileMissing — chỉ mục nói có, kho nói không. Chỉ đi vào log.
        'stored_file_missing' => 'Kho tài liệu không còn tệp có khoá :key, dù chỉ mục của CRM vẫn ghi là có.',
    ],
];
