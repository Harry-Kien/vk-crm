<?php

use App\Actions\Document\RetractDocument;

/**
 * M7 Task 7 — rút lại một tài liệu đã công bố cho khách ({@see RetractDocument}). Tệp lang RIÊNG
 * của làn M7 (luật giảm xung đột merge: `documents.php`, `portal_progress.php` là tệp dùng chung).
 *
 * Lý do rút là chữ KHÁCH ĐỌC ĐƯỢC: nó hiện nguyên văn trên cổng, ở chỗ tài liệu từng hiện. Các câu
 * hướng dẫn ở màn hình nội bộ phải nói thẳng điều đó.
 */
return [
    // Nút và hộp thoại trên tab "Tài liệu" của trang vụ việc (DocumentsRelationManager).
    'action' => [
        'label' => 'Rút lại',
        'modal_heading' => 'Rút lại tài liệu đã công bố cho khách',
        'modal_description' => 'Khách sẽ không còn xem hay tải được tài liệu này. Tệp và nhật ký các lượt khách đã tải được giữ nguyên. Thao tác này không hoàn tác được: muốn đưa lại cho khách thì tải lên một bản mới.',
        'submit' => 'Rút lại',
        'success' => 'Đã rút lại tài liệu khỏi cổng khách hàng.',
    ],
    'fields' => [
        'retraction_reason' => 'Lý do rút lại',
        'retraction_reason_help' => 'KHÁCH SẼ ĐỌC ĐƯỢC lý do này trên cổng khách hàng, ở chỗ tài liệu từng hiện. Tối thiểu :min ký tự.',
    ],

    // Tab "Tài liệu": cột "Khách thấy" của một dòng đã rút, và chú thích của nhãn trạng thái.
    'tab' => [
        'client_access' => 'Đã rút khỏi cổng khách',
        'status_tooltip' => 'Rút lại lúc :date bởi :by. Lý do (khách đọc được): :reason',
        'unknown_actor' => 'tài khoản đã xoá',
    ],

    // Dòng khách nhìn thấy trên trang chi tiết hồ sơ (cổng), ở khối "Tài liệu".
    'portal' => [
        'notice' => 'Văn phòng đã rút lại tài liệu này. Lý do: :reason',
        'retracted_on' => 'Rút lại ngày :date',
    ],

    'validation' => [
        'reason_min' => 'Lý do rút lại cần ít nhất :min ký tự để khách hiểu vì sao tài liệu không còn trên cổng.',
        'reason_max' => 'Lý do rút lại không được dài quá :max ký tự.',
    ],

    // App\Exceptions\DocumentNotRetractable — câu nói về TRẠNG THÁI của tài liệu, không về quyền.
    'exceptions' => [
        'missing' => 'Tài liệu này không còn tồn tại. Tải lại trang để xem danh sách mới nhất.',
        'trashed' => 'Tài liệu này đã bị xoá khỏi hồ sơ nên không rút lại được.',
        'matter_unavailable' => 'Vụ việc của tài liệu này đã bị xoá nên không thao tác được.',
        'already_retracted' => 'Tài liệu này đã được rút lại trước đó. Tải lại trang để xem trạng thái mới nhất.',
        'not_released' => 'Tài liệu này hiện không hiển thị cho khách nên không có gì để rút lại.',
    ],

    // Hai đường rút tạm của M4 nay chỉ tới nút "Rút lại" (một đường rút duy nhất).
    'blocked' => [
        'regroup_to_internal' => 'Tài liệu này đang hiển thị cho khách nên không chuyển vào nhóm D được. Để đưa nó ra khỏi cổng khách hàng, dùng nút "Rút lại" trên dòng tài liệu (cần quyền công bố tài liệu — luật sư trong vụ việc, trưởng phòng hoặc quản trị).',
        'republish' => 'Tài liệu này đã được rút lại khỏi cổng khách hàng và không công bố lại được. Nếu cần đưa lại cho khách, hãy tải lên một bản mới.',
        'regroup_retracted_to_internal' => 'Tài liệu này đã được rút lại và khách đang thấy dòng giải thích lý do rút ở chỗ tài liệu từng hiện. Chuyển vào nhóm D sẽ xoá dòng đó khỏi cổng khách hàng, nên không được phép.',
    ],
];
