<?php

/*
|--------------------------------------------------------------------------
| Mốc thời hạn — tab "Mốc thời hạn" của trang chi tiết vụ việc (SPEC §7.2)
|--------------------------------------------------------------------------
|
| Người đọc màn hình này là luật sư và trợ lý, nên từ vựng ở đây là từ vựng làm việc của văn
| phòng: ngắn, đọc lướt được trong một cột. Nó KHÁC hẳn câu chữ mà cùng dữ liệu ấy mang ra cổng
| khách (`lang/vi/portal_progress.php`, khoá `deadlines`), và đó không phải hai bản dịch của cùng
| một thứ: hai bên bàn cần biết hai điều khác nhau về cùng một dòng. Luật sư cần biết *còn mấy
| ngày và ai đang giữ*; khách cần biết *có phải việc của tôi không*.
|
| `severity` không được dịch lại ở đây. Nhãn của nó thuộc về enum (`lang/vi/enums.php`, khoá
| `deadline_severity`) vì cổng khách và các thư nhắc của Task 6 cũng đọc đúng nhãn ấy — một mức
| độ mang hai cái tên tuỳ chỗ hiện ra là một cách để hai người trong cùng văn phòng nói về hai
| thứ khác nhau.
*/

return [

    'label' => 'mốc thời hạn',
    'plural_label' => 'mốc thời hạn',

    'tab' => [
        'title' => 'Mốc thời hạn',
        'empty_state' => 'Chưa có mốc thời hạn nào trên hồ sơ này.',

        'columns' => [
            'due_date' => 'Ngày đến hạn',
            'name' => 'Nội dung',
            'severity' => 'Mức độ',
            'responsible' => 'Người phụ trách',
            'is_published' => 'Đã gửi khách',
            'completed_at' => 'Hoàn thành lúc',
        ],

        'fields' => [
            'name' => 'Cần làm gì',
            'name_help' => 'Viết như khi nói với đồng nghiệp: "Nộp đơn kháng cáo", "Phiên hoà giải lần 2".',
            'due_date' => 'Ngày đến hạn',
            'severity' => 'Mức độ',
            'severity_help' => 'Chọn "Không thể gia hạn" cho những hạn tố tụng không xin gia hạn được; hệ thống nhắc nhóm này sớm hơn.',
            'responsible' => 'Người phụ trách',
            'responsible_help' => 'Mặc định là luật sư phụ trách hồ sơ. Chỉ chọn được người trong đội ngũ vụ việc.',
            'is_published' => 'Hiện mốc này cho khách',
            'is_published_help' => 'Tắt thì mốc chỉ nằm trong văn phòng. Bật thì khách thấy trên cổng.',
            'is_published_disabled_hint' => 'Hồ sơ này chưa được công bố lên cổng khách hàng, nên chưa gửi mốc nào cho khách được.',
        ],

        'actions' => [
            'add' => 'Thêm mốc thời hạn',
            'add_heading' => 'Thêm mốc thời hạn',
            'add_submit' => 'Lưu mốc',
            'add_success' => 'Đã thêm mốc thời hạn.',

            'complete' => 'Đã xong',
            'complete_heading' => 'Đánh dấu mốc này đã hoàn thành?',
            'complete_description' => 'Hệ thống sẽ thôi nhắc mốc này. Nếu bấm nhầm, dùng nút "Mở lại" để quay về.',
            'complete_success' => 'Đã đánh dấu hoàn thành.',

            'reopen' => 'Mở lại',
            'reopen_heading' => 'Mở lại mốc này?',
            'reopen_description' => 'Hệ thống nhắc lại mốc này như trước. Những lần nhắc đã gửi thì không gửi lại.',
            'reopen_success' => 'Đã mở lại mốc thời hạn.',

            'publish' => 'Gửi cho khách',
            'publish_heading' => 'Hiện mốc này trên cổng khách hàng?',
            'publish_description' => 'Khách sẽ thấy tên mốc và ngày đến hạn trong phần "Mốc thời hạn sắp tới".',
            'publish_success' => 'Khách đã thấy mốc này trên cổng.',

            'unpublish' => 'Thôi gửi khách',
            'unpublish_heading' => 'Gỡ mốc này khỏi cổng khách hàng?',
            'unpublish_description' => 'Khách sẽ không còn thấy mốc này. Mốc vẫn nằm trong hồ sơ của văn phòng.',
            'unpublish_success' => 'Đã gỡ mốc khỏi cổng khách hàng.',

            // Fix round 1, CRITICAL — App\Actions\Deadline\ChangeDeadlineResponsible: đường ghi
            // thứ hai vào responsible_user_id, sau khi mốc đã tạo. Không có nút này thì một
            // người không phải lead còn đứng tên mốc chưa xong không bao giờ nghỉ việc được.
            'change_responsible' => 'Đổi người phụ trách',
            'change_responsible_heading' => 'Chuyển mốc này cho ai?',
            'change_responsible_submit' => 'Lưu',
            'change_responsible_success' => 'Đã đổi người phụ trách.',
        ],

        /*
         * Cách một dòng TỰ NÓI nó gấp tới mức nào. Đọc được trong một cái liếc, không phải sau
         * một phép trừ ngày trong đầu — đó là toàn bộ việc của cột ngày đến hạn.
         */
        'timing' => [
            'overdue' => 'Quá hạn :count ngày',
            'due_today' => 'Hết hạn hôm nay',
            'due_tomorrow' => 'Ngày mai',
            'due_in_days' => 'Còn :count ngày',
            'done' => 'Đã xong',
        ],
    ],

    'validation' => [
        'name_required' => 'Hãy ghi mốc này là việc gì.',
        'name_too_long' => 'Nội dung mốc thời hạn tối đa :max ký tự.',
        'due_date_required' => 'Hãy chọn ngày đến hạn.',
        'responsible_cannot_open' => 'Người này không mở được hồ sơ, hoặc tài khoản đã ngừng hoạt động. Hãy chọn một người trong đội ngũ vụ việc.',
    ],

    /*
     * MỘT câu cho mọi lý do (SPEC §10.10): mốc không tồn tại, hồ sơ người hỏi không mở được, hồ
     * sơ đã bị xoá, tài khoản đã bị vô hiệu hoá. Không câu nào trong số đó được phân biệt với
     * những câu còn lại, vì phân biệt được chính là một máy dò sự tồn tại.
     */
    'unavailable' => 'Không mở được mốc thời hạn này.',

    /*
     * Mẫu thư `staff.deadline_reminder` (SPEC §9). Thư gửi NHÂN SỰ nên được phép mang mã hồ sơ và
     * nói bằng ngôn ngữ nghề nghiệp — khác hẳn thư gửi khách. Tiêu đề đổi theo bậc, để người mở
     * hộp thư lúc 7 giờ sáng phân biệt được "còn bảy ngày" với "đã quá hạn" mà không cần mở thư.
     */
    'email' => [
        'subject' => [
            'd14' => 'Còn 14 ngày: :name (:code)',
            'd7' => 'Còn 7 ngày: :name (:code)',
            'd3' => 'Còn 3 ngày: :name (:code)',
            'd1' => 'Sắp hết hạn: :name (:code)',
            'overdue' => 'ĐÃ QUÁ HẠN: :name (:code)',
        ],
        'greeting' => 'Kính gửi :name,',
        'headline' => [
            'upcoming' => 'Còn :days ngày nữa là tới hạn.',
            'overdue' => 'Mốc này đã quá hạn :days ngày.',
        ],
        'due' => 'Hạn: :date',
        'matter' => 'Hồ sơ: :code — :title',
        'action' => 'Anh/chị mở hồ sơ trên hệ thống để xem chi tiết và đánh dấu đã xong khi hoàn tất.',
        'salutation' => ':office',
    ],
];
