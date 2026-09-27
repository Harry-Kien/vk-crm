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
            // M6.5 Task 14 (R14): xoá một mốc là xoá mềm kèm lý do bắt buộc.
            'delete_reason' => 'Vì sao xoá mốc này',
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
            // M6.5 Task 14: người giữ mốc không còn hợp lệ nên lần mở lại giao mốc cho luật sư
            // phụ trách hồ sơ — xem docblock App\Actions\Deadline\SetDeadlineCompletion.
            'reopen_reassigned_title' => 'Mốc đã được giao cho :name',
            'reopen_reassigned_body' => 'Người phụ trách trước không còn giữ được mốc này (đã nghỉ việc, bị vô hiệu hoá, không còn trong đội ngũ hoặc không còn xem được hồ sơ), nên mốc chuyển cho luật sư phụ trách hồ sơ. Muốn giao cho người khác, dùng nút "Đổi người phụ trách".',

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

            // M6.5 Task 14 (`deadlines/F7`) — trước bản sửa này, phiên toà hoãn không sửa được
            // ngày: cách duy nhất là đánh dấu "hoàn thành" sai sự thật rồi thêm mốc mới.
            'edit' => 'Sửa',
            'edit_heading' => 'Sửa mốc thời hạn',
            'edit_submit' => 'Lưu',
            'edit_success' => 'Đã lưu thay đổi.',

            'delete' => 'Xoá',
            'delete_heading' => 'Xoá mốc thời hạn này?',
            'delete_description' => 'Mốc sẽ không còn hiện ở đâu và không được nhắc nữa. Ghi rõ lý do bên dưới.',
            'delete_success' => 'Đã xoá mốc thời hạn.',
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
        // Minor (fix round 2): mốc đã hoàn thành không còn "việc" nào để đổi người phụ trách nữa.
        'already_completed' => 'Mốc này đã hoàn thành, không đổi người phụ trách được nữa. Hãy mở lại mốc (nút "Mở lại") trước, nếu thật sự cần đổi.',
        // M6.5 Task 14 (carried từ rà soát Task 3): mở lại một mốc mà cả người giữ mốc lẫn luật
        // sư phụ trách hồ sơ đều không còn giữ được — SetDeadlineCompletion không có ai để giao.
        'reopen_without_holder' => 'Chưa mở lại được: người phụ trách mốc này và luật sư phụ trách hồ sơ đều không còn giữ được mốc (đã nghỉ việc, bị vô hiệu hoá, không còn trong đội ngũ hoặc không còn xem được hồ sơ). Hãy bàn giao hồ sơ cho một luật sư khác trước, rồi mở lại mốc.',
        // R14: gỡ một mốc là xoá mềm kèm lý do bắt buộc.
        'delete_reason_required' => 'Hãy ghi vì sao xoá mốc này.',
    ],

    /*
     * MỘT câu cho mọi lý do (SPEC §10.10): mốc không tồn tại, hồ sơ người hỏi không mở được, hồ
     * sơ đã bị xoá, tài khoản đã bị vô hiệu hoá. Không câu nào trong số đó được phân biệt với
     * những câu còn lại, vì phân biệt được chính là một máy dò sự tồn tại.
     */
    'unavailable' => 'Không mở được mốc thời hạn này.',

    /*
     * Mẫu thư `staff.deadline_reminder` (SPEC §9). Thư gửi NHÂN SỰ nên được phép mang mã hồ sơ và
     * nói bằng ngôn ngữ nghề nghiệp — khác hẳn thư gửi khách.
     *
     * M6.5 Task 12 (`deadlines/F3`): tiêu đề (và câu đầu thân thư) đọc theo SỐ NGÀY THẬT CÒN LẠI
     * (`today()->diffInDays($deadline->due_date, false)`), KHÔNG theo con số của bậc nhắc
     * (`$tierKey`). Trước bản sửa này, khoá tra là `subject.d14`/`d7`/`d3` — CHUỖI CỐ ĐỊNH không
     * có tham số `:days` — nên một mốc `critical` xen giữa hai bậc (rất thường: luật sư ghi hạn
     * vào một ngày bất kỳ, không đúng lúc còn 14/7/3 ngày tròn) nhận tiêu đề ghi NHIỀU thời gian
     * hơn thực tế (còn 10 ngày mà tiêu đề "Còn 14 ngày"). Ba khoá dưới đây thay thế NĂM khoá cũ
     * (`d14`/`d7`/`d3`/`d1`/`overdue`), phân biệt theo DẤU của số ngày còn lại — không theo bậc —
     * nên áp dụng cho MỌI bậc như nhau: `upcoming` (còn > 0 ngày), `due_today` (đúng 0 ngày — câu
     * riêng "Hết hạn hôm nay", KHÔNG viết "Còn 0 ngày": không ai nói "còn 0 ngày nữa"), `overdue`
     * (< 0 ngày, mang trị tuyệt đối của số ngày đã trôi qua hạn).
     */
    'email' => [
        'subject' => [
            'upcoming' => 'Còn :days ngày: :name (:code)',
            'due_today' => 'Hết hạn hôm nay: :name (:code)',
            'overdue' => 'Đã quá hạn :days ngày: :name (:code)',
        ],
        'greeting' => 'Kính gửi :name,',
        'headline' => [
            'upcoming' => 'Còn :days ngày nữa là tới hạn.',
            'due_today' => 'Hết hạn hôm nay.',
            'overdue' => 'Mốc này đã quá hạn :days ngày.',
        ],
        'due' => 'Hạn: :date',
        'matter' => 'Hồ sơ: :code — :title',
        'action' => 'Anh/chị mở hồ sơ trên hệ thống để xem chi tiết và đánh dấu đã xong khi hoàn tất.',
        'salutation' => ':office',
    ],

    /*
     * M6.5 Task 11, vòng sửa 1 (C1): thông báo trong ứng dụng khi job gửi thư nhắc mốc thất bại
     * HẲN (hết mọi lượt thử) — xem docblock `App\Jobs\SendDeadlineReminderMail::failed()`.
     */
    'reminder_failed_notification' => [
        'title' => 'Không gửi được thư nhắc mốc thời hạn',
        'body' => 'Đã thử lại nhiều lần nhưng không gửi được thư nhắc bậc :tier cho mốc ":name" (hồ sơ :code). Cần kiểm tra thủ công.',
    ],

    /*
     * M6.5 Task 14 (`deadlines/F6`, `spec-gap-05`): SPEC §6.8 phân biệt bậc 7/3/1 là "Email" và
     * bậc quá hạn là "Đánh dấu quá hạn, TẠO THÔNG BÁO CẢNH BÁO" — trước bản sửa này chỉ có email.
     * Xem docblock `App\Actions\Schedule\CheckDeadlines` và
     * `App\Notifications\Staff\DeadlineOverdueAlert` cho lý do thông báo này KHÔNG dựng bằng
     * `Filament\Notifications\Notification` (App\Actions không được phụ thuộc Filament).
     */
    'overdue_notification' => [
        'title' => 'Mốc thời hạn đã quá hạn',
        'body' => 'Mốc ":name" (hồ sơ :code) đã quá hạn. Cần xử lý ngay.',
    ],
];
