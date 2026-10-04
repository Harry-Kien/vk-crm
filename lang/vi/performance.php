<?php

/**
 * M13 — theo dõi đội ngũ và hiệu suất (kế hoạch `docs/superpowers/plans/2026-10-04-m13-team-performance.md`).
 *
 * Tệp này hai làn M13 cùng sửa (làn A: Task 1, 2, 4, 5, 7, 8; làn m13b: Task 3, 6). Mỗi task thêm
 * khoá theo KHỐI liền nhau, mỗi khối mở bằng một chú thích `// Task N`, để người gộp giữ cả hai bên
 * mà không phải đọc lại cả tệp.
 */
return [
    // Task 1 — câu phạm vi cố định (R4) và tên ba trang.

    // R4: MỌI trang in đúng câu này cho MỌI người xem, kể cả admin — luôn có mặt nên sự có mặt của
    // nó không mang tín hiệu nào về vụ `restricted`. Kế hoạch viết "bạn"; panel nội bộ xưng
    // "anh/chị" (cùng câu phạm vi của M9, `billing.receivables.scope_note`), nên câu theo panel.
    'scope_note' => 'Mọi con số tính trên các vụ việc anh/chị được xem.',

    'pages' => [
        'team_overview' => [
            'navigation_label' => 'Theo dõi đội ngũ',
            'title' => 'Theo dõi đội ngũ',
        ],
        'team_member' => [
            // Mục điều hướng của người KHÔNG có `performance.viewAny`: trỏ tới trang của chính họ.
            'navigation_label' => 'Việc của tôi',
            'title_self' => 'Việc của tôi',
            'title' => 'Công việc của :name',
        ],
        'performance' => [
            'navigation_label' => 'Hiệu suất',
            'title' => 'Hiệu suất theo kỳ',
        ],
    ],

    // Task 2 — nhãn của App\Enums\DeadlineOutcome (kết quả của Deadline::outcomeAt(), cột P1).
    'outcomes' => [
        'on_time' => 'Đúng hạn',
        'late' => 'Trễ hạn',
        'missed' => 'Lỡ hạn',
    ],

    // Task 4 — số "bây giờ" N1–N11: tên cột, lời giải thích (R6), "Không áp dụng". N1–N10 trên trang
    // "Theo dõi đội ngũ"; N11 chỉ trên trang của một người (phán quyết N11, docblock BuildTeamWorkload).
    //
    // GỘP: `columns` và `explain` là MỘT mảng mỗi khoá cho cả ba trang — khoá `n*` của Task 4 và khoá
    // `p*`, `reference`, `closed_period` của Task 6 (làn m13b) nằm chung trong hai mảng này. Đừng để
    // hai khối `'columns' => [...]` cùng cấp: PHP giữ khối sau, nửa số câu biến mất
    // (`TeamOverviewPageTest` canh: mỗi khoá cấp một khai báo đúng một lần).

    // Cột chỉ dành cho người phụ trách vụ khi người được xem không đứng tên phụ trách vụ được (R6).
    'not_applicable' => 'Không áp dụng',

    // Tiêu đề khối thu gọn liệt kê câu giải thích của mọi con số trên trang (R6).
    'how_computed' => 'Cách tính các con số',

    'columns' => [
        'name' => 'Nhân sự',
        'n1' => 'Vụ đang phụ trách',
        'n2' => 'Vụ đang tham gia',
        'n3' => 'Vụ đã kết thúc',
        'n4' => 'Quá hạn cập nhật cho khách',
        'n5' => 'Mốc quá hạn',
        'n6' => 'Mốc 7 ngày tới',
        'n7' => 'Chờ giấy tờ của khách',
        'n8' => 'Giấy tờ chờ duyệt',
        'n9' => 'Yêu cầu chờ trả lời',
        'n10' => 'Hoàn thiện danh mục',
        'n11' => 'Thao tác hồ sơ gần nhất',
    ],

    'explain' => [
        'n1' => 'Số vụ việc đang mở mà người này là luật sư phụ trách.',
        'n2' => 'Vụ đang mở mà người này có tên trong đội ngũ với vai luật sư cộng sự hoặc trợ lý. Không tính vai theo dõi.',
        'n3' => 'Tổng số vụ đã kết thúc đang đứng tên người này, từ trước tới nay. Một vụ đã kết thúc rồi mới bàn giao thì tính cho người nhận; số theo kỳ trên trang Hiệu suất thì tính cho người phụ trách lúc vụ kết thúc.',
        'n4' => 'Vụ đang mở, đã bật cổng khách, mà lần cập nhật gần nhất cho khách đã quá 14 ngày. Cùng luật với cảnh báo trên trang chủ. Luật này không áp cho vụ chưa bật cổng; số vụ đó hiện riêng ("chưa bật cổng").',
        'n5' => 'Mốc người này đang giữ, chưa đánh dấu xong, ngày đến hạn đã qua, trên vụ còn mở. Gồm cả mốc tạo qua trợ lý AI chưa xác nhận.',
        'n6' => 'Mốc người này đang giữ, chưa xong, đến hạn từ hôm nay tới hết ngày thứ 7 kể từ hôm nay, trên vụ còn mở.',
        'n7' => 'Vụ (đã bật cổng khách) còn đầu mục bắt buộc khách chưa nộp hoặc bị từ chối. Trong ngoặc: số vụ đã chờ quá 14 ngày.',
        'n8' => 'Đầu mục khách đã nộp mà văn phòng chưa duyệt, trên vụ người này phụ trách.',
        'n9' => 'Yêu cầu của khách ở trạng thái Mới hoặc Đang xử lý mà người này đang giữ: được giao, hoặc là luật sư phụ trách khi chưa giao ai (hoặc khi người được giao không còn tài khoản).',
        'n10' => 'Trên các vụ đang phụ trách: số đầu mục đã xong (đã duyệt, hoặc không cần nộp) trên số đầu mục phải có. Cùng cách tính thanh "Đã nộp X/Y" của từng vụ.',
        'n11' => 'Lần gần nhất người này ghi một thay đổi vào một vụ việc anh/chị được xem, theo nhật ký hệ thống. Đăng nhập không tính.',
        'not_applicable' => '"Không áp dụng": cột chỉ dành cho người phụ trách vụ việc, mà vai trò của người này không đứng tên phụ trách vụ (ví dụ trợ lý). Số 0 nghĩa là không có việc nào.',
    ],

    'team_overview' => [
        'include_inactive' => 'Gồm người đã nghỉ việc',
        'inactive' => 'Đã nghỉ việc',
        'not_measurable' => 'chưa bật cổng: :count',
        'empty' => 'Chưa có nhân sự nào được theo dõi.',
    ],

    // Task 5 — trang của một người (`/team/{user}`): đầu trang, cơ cấu lĩnh vực, ba danh sách ngắn,
    // bảng "Vụ việc". Tên và câu giải thích của N1–N11 dùng lại `columns`/`explain` của Task 4; trạng
    // thái "Đã nghỉ việc" dùng lại `team_overview.inactive`.
    'team_member' => [
        'position' => 'Chức danh',
        'status' => 'Trạng thái',
        'active' => 'Đang làm việc',
        'workload_heading' => 'Việc đang giữ',
        // N11 rỗng: người này chưa ghi thay đổi nào vào một vụ việc người xem thấy được.
        'no_activity' => 'Chưa có',
        'mix' => [
            'label' => 'Cơ cấu lĩnh vực',
            'heading_lead' => 'Cơ cấu lĩnh vực — vụ đang phụ trách',
            'heading_supporting' => 'Cơ cấu lĩnh vực — vụ đang tham gia (luật sư cộng sự hoặc trợ lý)',
            'type' => 'Loại vụ việc',
            'matters' => 'Số vụ',
            'empty' => 'Không có vụ việc đang mở nào.',
            'explain' => 'Cơ cấu lĩnh vực: các vụ của cột "Vụ đang phụ trách" chia theo loại vụ việc; với người không đứng tên phụ trách vụ (ví dụ trợ lý), các vụ của cột "Vụ đang tham gia". Bảng này cho bối cảnh để đọc các con số, không để so người này với người khác.',
        ],
        'lists' => [
            'label' => 'Ba danh sách việc',
            'deadlines' => 'Mốc quá hạn và 7 ngày tới',
            'requests' => 'Yêu cầu của khách chờ trả lời',
            'reviews' => 'Giấy tờ chờ duyệt',
            'empty' => 'Không có việc nào.',
            // Danh sách dài hơn TeamMember::LIST_LIMIT chỉ in phần đầu; tổng là con số đầu trang.
            'more' => 'Hiện :shown việc gấp nhất trên tổng số :total.',
            'explain' => 'Ba danh sách "Mốc quá hạn và 7 ngày tới", "Yêu cầu của khách chờ trả lời" và "Giấy tờ chờ duyệt" là chính các việc được đếm ở các cột Mốc quá hạn cùng Mốc 7 ngày tới, Yêu cầu chờ trả lời và Giấy tờ chờ duyệt. Mốc và giấy tờ là các dòng của bảng "Mốc thời hạn 7 ngày tới" và "Tài liệu chờ duyệt" trên trang chủ của anh/chị, chỉ giữ việc của người này. Mỗi danh sách in tối đa :limit việc gấp nhất (mốc quá hạn lâu nhất, yêu cầu chờ lâu nhất, giấy tờ nộp sớm nhất) và nói tổng số.',
            'columns' => [
                'subject' => 'Chủ đề',
                'status' => 'Trạng thái',
                'sent_at' => 'Khách gửi lúc',
            ],
        ],
        'matters' => [
            'heading' => 'Vụ việc',
            'role' => 'Vai của người này',
            'empty' => 'Không có vụ việc nào.',
            'filters' => [
                'state' => 'Tình trạng',
                'open' => 'Đang mở',
                'closed' => 'Đã kết thúc',
                'role' => 'Vai',
                'lead' => 'Phụ trách',
                'supporting' => 'Tham gia (luật sư cộng sự hoặc trợ lý)',
            ],
        ],
    ],
];
