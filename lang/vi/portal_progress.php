<?php

/**
 * Chuỗi của trang chi tiết hồ sơ trên cổng khách hàng — SPEC §8.3.
 *
 * **Tệp riêng, không phải `lang/vi/portal.php`.** Bốn agent cùng làm M5 trên một nhánh; phán
 * quyết của người điều phối (19/09/2026) giao `portal.php` cho Task 1, `portal_matters.php` cho
 * Task 3 và tệp này cho Task 4, để ba người không cùng sửa một tệp — đúng chỗ M3 đã va nhau.
 *
 * **Người đọc là khách hàng, không phải nhân viên văn phòng.** SPEC §8 cấm thuật ngữ kỹ thuật và
 * từ viết tắt, nên mấy chỗ dưới đây cố ý KHÔNG dùng lại nhãn nội bộ ở `lang/vi/enums.php`:
 *
 *  - "Dòng thời gian" (chữ của SPEC §8.3 mục 3) là một từ của người làm giao diện, không phải
 *    của người đang lo vụ kiện của mình. Ở đây nó là **"Diễn biến vụ việc"**. Thứ tự và nội dung
 *    khối giữ nguyên như SPEC liệt kê; chỉ cái tên hiển thị đổi, và nó đổi theo đúng câu mở đầu
 *    §8 ("không dùng thuật ngữ kỹ thuật").
 *  - Trạng thái đầu mục ở `enums.checklist_item_status` được viết cho bảng của nhân sự ("Chờ
 *    kiểm tra", "Cần nộp lại"). Bản của khách nói thành câu đủ ý, vì với khách thì "Cần nộp lại"
 *    mà không kèm "vì sao" là một lời trách chứ không phải một hướng dẫn.
 *
 * Xưng hô giữ đúng giọng của `lang/vi/portal.php`: "anh/chị" cho người đọc, "chúng tôi" cho văn
 * phòng.
 */
return [

    'title' => 'Hồ sơ :code',
    'subheading' => 'Mã hồ sơ :code',

    'blocks' => [

        'status' => [
            'heading' => 'Tình trạng hiện tại',
            // Khi loại vụ việc chưa cấu hình giai đoạn này (dữ liệu cũ, hoặc quản trị viên vừa
            // xoá một giai đoạn còn hồ sơ đang đứng ở đó). Không để trống: một ô trống ở khối
            // đầu tiên là chỗ khách đọc ra "hệ thống hỏng".
            'unknown' => 'Văn phòng đang cập nhật tình trạng hồ sơ này.',
        ],

        'todo' => [
            'heading' => 'Việc anh/chị cần làm',
            'documents_lead' => 'Giấy tờ chúng tôi còn chờ ở anh/chị:',
            /*
             * Câu này KHÔNG BAO GIỜ được hiển thị, và nó có mặt ở đây đúng vì lý do đó: SPEC
             * §8.3 mục 2 nói khối này "chỉ hiện khi có", nên khi không có việc thì cả khối biến
             * mất chứ không hiện một dòng "không có việc gì". Test
             * `MatterProgressTest` khẳng định chuỗi này vắng mặt trong HTML — tức nó là một
             * NHÂN CHỨNG cho luật ấy, không phải một chuỗi bị quên. Xoá nó đi là bỏ mất phép đo.
             */
            'empty' => 'Hiện không có việc gì cần anh/chị làm.',
        ],

        'timeline' => [
            'heading' => 'Diễn biến vụ việc',
            'empty' => 'Chưa có cập nhật nào. Ngay khi có tin, chúng tôi sẽ ghi vào đây và báo cho anh/chị.',
        ],

        'checklist' => [
            'heading' => 'Hồ sơ giấy tờ',
            'empty' => 'Hồ sơ này chưa cần giấy tờ nào từ anh/chị. Nếu cần, chúng tôi sẽ ghi vào đây.',
        ],

        'documents' => [
            'heading' => 'Tài liệu',
            'empty' => 'Chưa có tài liệu nào được gửi cho anh/chị. Khi có, chúng tôi sẽ đưa lên đây.',
        ],

        'deadlines' => [
            'heading' => 'Mốc thời hạn sắp tới',
            'empty' => 'Hiện chưa có mốc thời hạn nào anh/chị cần nhớ.',
        ],

        'requests' => [
            'heading' => 'Gửi yêu cầu',
            'lead' => 'Anh/chị có điều gì chưa rõ về hồ sơ này?',
            /*
             * Lối vào màn hình gửi yêu cầu là của Task 6 (SPEC §8.3 mục 7). Cho tới khi màn hình
             * đó có mặt, khối này đưa ra con đường CÓ THẬT hôm nay — gọi điện tới văn phòng —
             * thay vì một cái nút dẫn tới một trang chưa tồn tại. Xem docblock
             * `App\Filament\Portal\Pages\MatterProgress::requestEntryPoint()`.
             */
            'call' => 'Anh/chị gọi cho chúng tôi theo số :hotline, hoặc nhắn qua Zalo cùng số này.',
            // CHƯA ĐƯỢC PHỤC VỤ HÔM NAY, và điều đó là cố ý: nhãn của cái nút mà
            // `requestEntryPoint()` sẽ bật lên khi Task 6 có màn hình. Để sẵn ở đây để Task 6 chỉ
            // phải đổi MỘT thân hàm, không phải đi tìm cả câu chữ.
            'open' => 'Gửi yêu cầu cho văn phòng',
        ],

    ],

    'timeline' => [
        'moved_to' => 'Hồ sơ chuyển sang giai đoạn: :stage',
        'next_step' => 'Tiếp theo sẽ là:',
        'client_action' => 'Anh/chị cần làm:',
        'expected' => 'Dự kiến có tin tiếp theo trước ngày :date',
        'occurred_at' => 'Ngày :date',
    ],

    'checklist' => [
        'progress' => 'Đã nộp :submitted / :total giấy tờ',
        'required' => 'Bắt buộc',
        'optional' => 'Không bắt buộc',
        'rejection_lead' => 'Vì sao chúng tôi chưa nhận được:',

        /*
         * Bản của khách, viết thành câu. Đối chiếu với `enums.checklist_item_status` (bản của
         * nhân sự) ở docblock đầu tệp.
         */
        'status' => [
            'missing' => 'Chúng tôi đang chờ anh/chị gửi',
            'pending_review' => 'Đang chờ văn phòng kiểm tra',
            'accepted' => 'Đã nhận đủ',
            'rejected' => 'Cần anh/chị gửi lại',
            'not_applicable' => 'Không cần cho hồ sơ này',
        ],
    ],

    'documents' => [
        'download' => 'Tải về',
        /*
         * Hai cờ độc lập (SPEC §6.5 bước 3): có tài liệu văn phòng cho khách BIẾT là đã có nhưng
         * chưa cho tải. Câu này nói ra điều đó, vì một dòng không có nút bấm mà không có lời giải
         * thích sẽ bị đọc là hỏng.
         */
        'view_only' => 'Tài liệu này anh/chị xem tại văn phòng; chúng tôi chưa mở tải về.',
        'issued_at' => 'Ngày ban hành :date',
    ],

    'deadlines' => [
        'due' => 'Hạn ngày :date',
        'overdue' => 'Đã quá hạn',
        'today' => 'Hạn hôm nay',
        'in_days' => 'Còn :count ngày',
    ],

];
