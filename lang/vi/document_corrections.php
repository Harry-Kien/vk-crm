<?php

/*
 * Làn fa (kiểm tra nghiệp vụ 2026-10-09, mục A4–A7) — sửa sai trên tài liệu và danh mục hồ sơ: sửa
 * thông tin, xoá tài liệu tải nhầm, đưa đầu mục về "Còn thiếu", sửa đầu mục, giữ bản đã nhận khi khách
 * gửi thêm, và rút lại một tài liệu nhóm A đang là bản hiện tại của đầu mục. Tệp riêng để không chạm
 * khối câu của `documents.php`/`checklist.php` mà các làn khác cũng sửa.
 */
return [
    'edit' => [
        'action' => 'Sửa thông tin',
        'heading' => 'Sửa tiêu đề, ngày ban hành, đầu mục',
        'description' => 'Không đổi tệp. Tài liệu khách đang xem được thì chỉ người có quyền công bố mới sửa được.',
        'title' => 'Tiêu đề',
        'issued_at' => 'Ngày ban hành',
        'checklist_item' => 'Đầu mục danh mục',
        'checklist_item_none' => 'Không gắn đầu mục nào',
        'checklist_item_help' => 'Tài liệu khách cung cấp (nhóm A) thuộc chuỗi nộp của đầu mục nên không chuyển sang đầu mục khác được.',
        'success' => 'Đã sửa thông tin tài liệu.',
        'errors' => [
            'title_required' => 'Nhập tiêu đề.',
            'title_too_long' => 'Tiêu đề không được dài hơn :max ký tự.',
            'checklist_item_other_matter' => 'Đầu mục này không thuộc hồ sơ của tài liệu.',
            'checklist_item_chain' => 'Tài liệu khách cung cấp (nhóm A) thuộc chuỗi nộp của đầu mục hiện tại và không chuyển sang đầu mục khác được. Nếu tải nhầm đầu mục, rút lại tài liệu (hoặc xoá nếu khách chưa từng thấy) rồi tải lên lại vào đúng đầu mục.',
            'unavailable' => 'Tài liệu không còn sửa được: nó đã bị xoá, hoặc hồ sơ chứa nó đã bị xoá.',
        ],
    ],

    'delete' => [
        'action' => 'Xoá',
        'heading' => 'Xoá tài liệu tải lên nhầm',
        'description' => 'Chỉ xoá được tài liệu khách chưa từng thấy. Tài liệu biến khỏi hồ sơ và khỏi mọi màn hình; tệp và dữ liệu vẫn được giữ lại, nhưng chưa có màn hình nào khôi phục — muốn lấy lại phải nhờ người quản trị kỹ thuật khôi phục trực tiếp trên cơ sở dữ liệu. Lý do xoá được ghi vào nhật ký.',
        'reason' => 'Lý do xoá',
        'reason_help' => 'Ví dụ: tải nhầm vào hồ sơ của khách khác. Tối thiểu :min ký tự.',
        'success' => 'Đã xoá tài liệu.',
        'errors' => [
            'reason_min' => 'Lý do xoá cần ít nhất :min ký tự.',
            'reason_max' => 'Lý do xoá không được dài hơn :max ký tự.',
            'not_deletable' => 'Tài liệu này không xoá được: khách đang xem được, đã bị rút lại, hoặc anh/chị không có quyền xoá. Tài liệu khách đã thấy chỉ rời tầm mắt khách bằng nút "Rút lại".',
        ],
    ],

    'reopen' => [
        'action' => 'Đưa về "Còn thiếu"',
        'heading' => 'Đưa đầu mục về "Còn thiếu"',
        'description' => 'Dùng khi lỡ bấm "Không cần nộp" hay "Đã nhận". Đầu mục lại tính là giấy tờ khách phải nộp: thanh tiến độ và thư nhắc tính lại. Khách không nhận thư từ chối nào.',
        'success' => 'Đã đưa đầu mục về "Còn thiếu".',
        'errors' => [
            'not_reopenable' => 'Chỉ đưa về "Còn thiếu" được một đầu mục đang "Đã nhận" hoặc "Không cần nộp".',
        ],
    ],

    'edit_item' => [
        'action' => 'Sửa đầu mục',
        'heading' => 'Sửa đầu mục cho riêng hồ sơ này',
        'description' => 'Không đổi mẫu danh mục. Khách thấy tên và mô tả mới trên cổng khách.',
        'name' => 'Tên đầu mục',
        'description_field' => 'Mô tả (khách đọc được)',
        'is_required' => 'Bắt buộc nộp',
        'name_too_long' => 'Tên đầu mục không được dài hơn :max ký tự.',
        'success' => 'Đã sửa đầu mục.',
    ],

    'keep_accepted' => [
        'action' => 'Từ chối bản mới, giữ bản đã nhận',
        'heading' => 'Từ chối bản khách gửi thêm, giữ bản đã nhận trước đó',
        'description' => 'Bản gửi thêm được rút khỏi cổng khách kèm lý do dưới đây (khách đọc được). Đầu mục trở lại "Đã nhận" với bản văn phòng đã nhận; khách không nhận thư từ chối, và gói bàn giao mang bản đã nhận.',
        'reason' => 'Lý do (khách đọc được)',
        'reason_help' => 'Ví dụ: ảnh gửi thêm bị mờ, văn phòng giữ bản đã nhận trước đó. Tối thiểu :min ký tự.',
        'success' => 'Đã giữ bản đã nhận và rút bản gửi thêm khỏi cổng khách.',
        'errors' => [
            'not_available' => 'Không còn bản đã nhận nào để giữ: đầu mục không đang chờ duyệt, hoặc chưa từng có bản được nhận, hoặc bản đã nhận không còn trên cổng khách.',
        ],
    ],

    'retract' => [
        'checklist_note' => 'Nếu tài liệu là bản hiện tại của một đầu mục danh mục (nhóm A) và không còn tệp nào khác của bản đó trên cổng khách, đầu mục trở về "Còn thiếu" để khách nộp lại — hoặc về bản văn phòng đã nhận trước đó, nếu tài liệu bị rút là bản khách gửi thêm chưa ai nhận.',
    ],

    'portal' => [
        'already_accepted' => 'Giấy này văn phòng đã nhận. Gửi thêm sẽ cần văn phòng kiểm tra lại bản mới; bản đã nhận vẫn được giữ cho tới khi văn phòng quyết định.',
        'confirm_resubmission' => 'Tôi vẫn muốn gửi thêm bản mới cho giấy này',
        'confirm_required' => 'Giấy này văn phòng đã nhận. Đánh dấu ô xác nhận nếu anh/chị vẫn muốn gửi thêm bản mới.',
    ],
];
