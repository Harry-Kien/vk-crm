<?php

/**
 * Làn tl (2026-10-15) — trang "Tài liệu" chung cho nhân sự (`App\Filament\Admin\Pages\DocumentLibrary`):
 * mọi tài liệu người xem được phép thấy, xuyên các vụ. Tệp RIÊNG (phán quyết của controller: khoá mới
 * của làn vào tệp mới hoặc khối `// làn tl`).
 */
return [
    'navigation_label' => 'Tài liệu',
    'title' => 'Tài liệu',
    'intro' => 'Mọi tài liệu bạn được phép xem, của mọi vụ việc, mới nhất ở trên. Tìm theo tên tài liệu, mã hồ sơ hoặc tên khách; lọc theo vụ, loại vụ, nhóm, trạng thái, người tải lên hay ngày. Muốn tải lên, sửa, công bố hay rút lại một tài liệu: bấm "Mở vụ" và làm ở tab Tài liệu của vụ đó.',
    'empty_heading' => 'Không có tài liệu nào',
    'empty_description' => 'Chưa có tài liệu nào bạn được xem khớp với tìm kiếm hoặc bộ lọc.',

    'columns' => [
        'title' => 'Tên tài liệu',
        'matter_code' => 'Mã hồ sơ',
        'client' => 'Khách hàng',
        'group' => 'Nhóm',
        'status' => 'Trạng thái',
        'version' => 'Bản',
        'uploader' => 'Người tải lên',
        'uploaded_at' => 'Ngày tải lên',
        'size' => 'Dung lượng',
    ],

    'uploader' => [
        'client' => ':name (khách hàng)',
        'unknown' => 'Không rõ',
    ],

    'size' => [
        'bytes' => ':size B',
        'kilobytes' => ':size KB',
        'megabytes' => ':size MB',
        'gigabytes' => ':size GB',
    ],

    'filters' => [
        'matter' => 'Vụ việc',
        'matter_type' => 'Loại vụ',
        'group' => 'Nhóm',
        'status' => 'Trạng thái',
        'uploader' => 'Người tải lên',
        'uploader_client' => 'Khách hàng (nộp qua cổng)',
        'involvement' => 'Vụ của tôi',
        'involvement_lead' => 'Vụ tôi phụ trách',
        'involvement_team' => 'Vụ tôi có tên trong đội',
        'uploaded_between' => 'Ngày tải lên',
        'uploaded_from' => 'Từ ngày',
        'uploaded_until' => 'Đến ngày',
        'indicator_from' => 'Tải lên từ :date',
        'indicator_until' => 'Tải lên đến :date',
    ],

    'actions' => [
        'download' => 'Tải về',
        'open_matter' => 'Mở vụ',
    ],

    'download_denied' => 'Bạn không tải được tệp này: có thể quyền xem vừa thay đổi, hoặc đây là gói bàn giao mang bảng kê thanh toán mà chỉ người được xem tiền của vụ mới tải được. Tải lại trang để cập nhật danh sách.',
];
