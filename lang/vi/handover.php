<?php

use App\Actions\Matter\BuildHandoverPackage;

/**
 * Gói bàn giao hồ sơ (M7 Task 4, SPEC §6.12): zip các tài liệu của vụ việc đã kết thúc kèm tệp
 * `MUC-LUC.pdf`, đưa cho khách sau khi luật sư xem lại và công bố. Mọi chữ hiển thị của tính năng
 * — kể cả chữ IN TRONG PDF MỤC LỤC — đi qua tệp này (`__('handover.…')`), không có chuỗi tiếng
 * Việt nào nằm trong mã hay trong Blade của PDF.
 *
 * Nhóm `pdf.*` là chữ mà KHÁCH đọc: không nhắc tới "nhóm A/B/C/D", "signed_filed" hay bất kỳ
 * thuật ngữ nội bộ nào. Tên nhóm dùng nhãn của `DocumentGroup::label()` (`enums.document_group`),
 * không phải chữ cái của nhóm.
 *
 * @see BuildHandoverPackage
 */
return [
    // Tên tài liệu gói (cột `documents.title`, varchar(250)): mã vụ việc ngắn nên không cần cắt.
    'package' => [
        'document_title' => 'Gói bàn giao hồ sơ :code',
        // Tên tệp khách thấy khi tải về. ASCII thuần: tên này đi vào `Content-Disposition` và được
        // mở trên đủ loại máy khách; mã vụ việc (VK-2026-DS-0001) là ASCII sẵn.
        'file_name' => 'goi-ban-giao-:code.zip',
    ],

    'exceptions' => [
        'not_closed' => 'Vụ việc này chưa kết thúc nên chưa sinh được gói bàn giao. Gói chỉ sinh cho vụ đã chuyển sang giai đoạn kết thúc.',
        'no_archive' => 'Vụ việc này chưa có bản ghi lưu trữ nên chưa sinh được gói bàn giao. Chuyển giai đoạn của vụ sang giai đoạn kết thúc để hệ thống lập bản ghi lưu trữ trước.',
        'busy' => 'Gói bàn giao đang được sinh — anh/chị chưa cần bấm lại. Khi xong, hệ thống sẽ báo ở đây và bằng thư.',
        'missing_file' => 'Không đọc được tệp của tài liệu ":title" trên kho lưu trữ nên gói chưa sinh được. Kiểm tra tài liệu đó (hoặc rút nó khỏi hồ sơ) rồi bấm sinh lại.',
        'zip_failed' => 'Hệ thống không nén được các tệp thành gói. Kiểm tra dung lượng trống của máy chủ rồi bấm sinh lại.',
        'index_failed' => 'Hệ thống không dựng được tệp mục lục MUC-LUC.pdf. Bấm sinh lại; nếu vẫn lỗi, báo quản trị hệ thống.',
        'no_uploader' => 'Không xác định được người đứng tên tài liệu gói (vụ chưa có luật sư phụ trách và không có ai bấm yêu cầu). Gán luật sư phụ trách cho vụ rồi bấm sinh lại.',
        'matter_gone' => 'Vụ việc không còn nữa nên gói bàn giao không sinh được.',
        // Vòng sửa 1: ba lỗi đĩa/kích thước — lỗi CÓ TÊN, job không thử lại; câu nói người vận hành
        // cần làm gì trước khi bấm sinh lại.
        'work_dir_failed' => 'Hệ thống không ghi được vào thư mục tạm dựng gói trên máy chủ (đầy đĩa hoặc không có quyền ghi). Báo quản trị hệ thống kiểm tra dung lượng trống và quyền ghi của thư mục này (biến HANDOVER_WORK_DIR) rồi bấm sinh lại.',
        'too_large' => 'Gói bàn giao nặng :size MB, vượt trần :limit MB cho một tệp của kho hồ sơ nên chưa lưu được. Báo quản trị hệ thống nâng trần này (biến MEDIA_MAX_FILE_SIZE_MB trong tệp .env) rồi bấm sinh lại.',
        'store_failed' => 'Gói đã dựng xong nhưng hệ thống không lưu được vào kho hồ sơ (đĩa lưu trữ không ghi được). Báo quản trị hệ thống kiểm tra dung lượng trống và quyền ghi của kho hồ sơ trên máy chủ rồi bấm sinh lại.',
        // Lỗi không thuộc loại nào ở trên: chi tiết kỹ thuật chỉ ở nhật ký máy chủ.
        'unknown' => 'Gói bàn giao chưa sinh được vì một lỗi hệ thống. Bấm sinh lại; nếu vẫn lỗi, báo quản trị hệ thống (chi tiết ở nhật ký máy chủ).',
        // Rà soát cuối M7, I2 ("một đường rút duy nhất"): sinh lại không bao giờ tự gỡ gói đang công
        // bố khỏi cổng khách. `released` là lời từ chối lúc bấm nút; `previous_released` là lỗi có
        // tên của job khi gói được công bố trong lúc gói mới đang chờ sinh.
        'released' => 'Gói bàn giao hiện tại đang công bố cho khách nên chưa sinh lại được. Muốn thay bằng gói mới, trước hết rút gói hiện tại bằng nút "Rút lại" trên dòng của nó ở tab Tài liệu (khách sẽ đọc được lý do rút), rồi bấm sinh lại.',
        'previous_released' => 'Gói bàn giao hiện tại đã được công bố cho khách trong lúc gói mới đang chờ sinh, nên gói mới không được lưu. Muốn thay gói, rút gói hiện tại bằng nút "Rút lại" ở tab Tài liệu, rồi bấm sinh lại.',
    ],

    /*
     * Khối "Gói bàn giao" trên trang vụ việc (tab Tổng quan) và nút "Sinh gói"/"Sinh lại gói".
     */
    'section' => [
        'heading' => 'Gói bàn giao hồ sơ',
        'description' => 'Bản zip gồm các tài liệu của vụ việc kèm mục lục, để đưa cho khách khi vụ kết thúc. Sau khi sinh xong, anh/chị xem lại rồi công bố ở tab Tài liệu — khách chỉ tải được sau khi công bố.',
        'fields' => [
            'status' => 'Trạng thái',
            'requested_at' => 'Yêu cầu lúc',
            'requested_by' => 'Người yêu cầu',
            'generated_at' => 'Sinh xong lúc',
            'document' => 'Tài liệu gói',
            'error' => 'Lỗi',
        ],
        'not_requested' => 'Chưa sinh',
        'automatic' => 'Tự động khi vụ kết thúc',
        'stuck_hint' => 'Lần sinh này chạy quá lâu bất thường. Anh/chị có thể bấm sinh lại.',
        'document_value' => ':title — phiên bản :version',
    ],

    'action' => [
        'generate' => 'Sinh gói bàn giao',
        'regenerate' => 'Sinh lại gói bàn giao',
        'modal_heading' => 'Sinh gói bàn giao hồ sơ',
        'modal_description' => 'Hệ thống sẽ dựng lại gói từ các tài liệu hiện có. Gói mới là một phiên bản mới của cùng tài liệu và chỉ tới tay khách sau khi anh/chị công bố. Tệp của phiên bản cũ được xoá, trừ khi phiên bản đó đã bị rút lại hoặc khách đã tải về (khi ấy tệp được giữ làm bằng chứng). Gói đang công bố cho khách thì phải rút lại trước khi sinh lại.',
        'submit' => 'Sinh gói',
        'queued' => 'Đã xếp hàng sinh gói bàn giao. Anh/chị sẽ được báo khi xong.',
    ],

    /*
     * Thông báo trong hệ thống (Filament, kênh database) cho luật sư phụ trách.
     */
    'notification' => [
        'ready_title' => 'Gói bàn giao đã sẵn sàng',
        'ready_body' => 'Gói bàn giao của vụ việc :code đã sinh xong. Xem lại nội dung rồi công bố ở tab Tài liệu để khách tải được.',
        'failed_title' => 'Không sinh được gói bàn giao',
        'failed_body' => 'Gói bàn giao của vụ việc :code chưa sinh được: :reason',
    ],

    /*
     * Thư `staff.handover_ready` (mẫu thư mới của M7) — gửi nhân sự, nên được phép mang mã hồ sơ.
     */
    'email' => [
        'subject' => 'Gói bàn giao hồ sơ :code đã sẵn sàng',
        'greeting' => 'Chào :name,',
        'intro' => 'Gói bàn giao của vụ việc :code — :title đã sinh xong, gồm các tài liệu của hồ sơ kèm tệp mục lục MUC-LUC.pdf.',
        // Việc sau gộp M7 (làn fu2): công bố gói nay gửi thư `client.document_published` (biến thể gói
        // bàn giao) — người bấm công bố cần biết trước điều đó, và biết thư chỉ tới tài khoản cổng đã
        // kích hoạt khi vụ còn trên cổng (chưa quá hạn tra cứu).
        'action' => 'Anh/chị xem lại nội dung gói, rồi công bố ở tab Tài liệu của vụ việc để khách tải được. Khách chỉ thấy gói sau khi anh/chị công bố. Công bố xong, hệ thống gửi thư báo kèm hạn tải tới các tài khoản cổng đã kích hoạt của khách, nếu vụ việc còn trên cổng khách hàng.',
        'link' => 'Mở vụ việc: :url',
        'salutation' => 'Trân trọng, :office',
    ],

    /*
     * Chữ IN TRONG MUC-LUC.pdf — khách đọc, nên không thuật ngữ nội bộ.
     */
    'pdf' => [
        'title' => 'MỤC LỤC HỒ SƠ BÀN GIAO',
        'matter_heading' => 'Thông tin vụ việc',
        'matter' => [
            'code' => 'Mã hồ sơ',
            'title' => 'Tên vụ việc',
            'client' => 'Khách hàng',
            'type' => 'Loại vụ việc',
            'lead_lawyer' => 'Luật sư phụ trách',
            'opened_at' => 'Ngày mở',
            'closed_at' => 'Ngày kết thúc',
            'stage' => 'Giai đoạn cuối',
        ],
        'documents_heading' => 'Danh sách tài liệu',
        'documents_intro' => 'Số thứ tự dưới đây trùng với số đầu tên tệp trong gói (ví dụ "01-…"), và mỗi nhóm tài liệu nằm trong một thư mục riêng.',
        'documents_empty' => 'Hồ sơ chưa có tài liệu nào đủ điều kiện đưa vào gói.',
        'columns' => [
            'number' => 'STT',
            'title' => 'Tài liệu',
            'group' => 'Nhóm',
            'file' => 'Tên tệp trong gói',
            'date' => 'Ngày',
        ],
        'timeline_heading' => 'Tiến độ đã thông báo cho khách',
        'timeline_empty' => 'Hồ sơ chưa có cập nhật tiến độ nào được công bố.',
        'timeline' => [
            'next_step' => 'Bước tiếp theo:',
            'client_action' => 'Việc khách cần làm:',
        ],
        'generated_at' => 'Lập ngày :date',
        'page' => 'Trang',
    ],

    // M14 Task 4 (kế hoạch R12): hai lý do mới của HandoverPackageFailed khi tệp nằm trên kho Google
    // Drive. Câu cho luật sư (lưu vào matter_archives.handover_error), không đường dẫn máy chủ.
    'storage_failures' => [
        'insufficient_work_space' => 'Máy chủ không đủ chỗ trống để dựng gói: cần khoảng :needed MB, còn :free MB. Báo quản trị hệ thống dọn ổ đĩa (hoặc trỏ biến HANDOVER_WORK_DIR tới ổ rộng hơn) rồi bấm sinh lại.',
        'unavailable' => 'Không tải được tài liệu từ kho tài liệu (kho tạm thời chưa truy cập được, hoặc bản tải về không khớp bản đã lưu). Tài liệu vẫn được lưu an toàn; vui lòng bấm sinh lại sau ít phút.',
    ],
];
