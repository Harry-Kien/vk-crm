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

    // Task 6 (làn m13b) — trang "Hiệu suất theo kỳ": cột P1–P7, P9, P10, "Lĩnh vực chính", lời giải
    // thích (R6), kỳ (R16), tỉ lệ (R7), thời lượng (R17), dòng "Chung" (R8).
    //
    // GỘP với Task 4/5 của làn A: `columns` và `explain` là MỘT mảng mỗi khoá cho cả ba trang — khoá
    // `n*` của làn A và khoá `p*`, `main_areas`, `reference`, `closed_period` dưới đây nằm chung trong
    // hai mảng đó. `not_applicable`, `how_computed`, `columns.name` và `explain.not_applicable` hai làn
    // cùng có: giữ MỘT bản (câu `explain.not_applicable` ở đây nói "trong các vụ việc anh/chị được xem",
    // đúng R4 — minor m3 của rà soát Task 4). Đừng để hai khối `'columns' => [...]` cùng cấp: PHP giữ
    // khối sau, nửa số câu biến mất.

    // Cột chỉ dành cho người phụ trách vụ khi người được xem không đứng tên phụ trách vụ được (R6).
    'not_applicable' => 'Không áp dụng',

    // Tiêu đề khối thu gọn liệt kê câu giải thích của mọi con số trên trang (R6).
    'how_computed' => 'Cách tính các con số',

    'columns' => [
        'name' => 'Nhân sự',
        'main_areas' => 'Lĩnh vực chính',
        'p1' => 'Mốc đúng hạn',
        'p2' => 'Mốc đã gỡ',
        'p3' => 'Trả lời yêu cầu của khách',
        'p10' => 'Yêu cầu đóng không trả lời',
        'p4' => 'Chuyển giai đoạn',
        'p5' => 'Vụ kết thúc trong kỳ',
        'p6' => 'Giấy tờ đã duyệt',
        'p7' => 'Doanh thu đã thu',
        'p9' => 'Hoàn thành việc đến hạn',
    ],

    'explain' => [
        'main_areas' => 'Hai lĩnh vực có nhiều vụ nhất trong số vụ người này có việc trong kỳ (mốc đến hạn, yêu cầu của khách, chuyển giai đoạn, vụ kết thúc), kèm số vụ — để đọc các tỉ lệ trong đúng bối cảnh của kỳ đó, không phải để so người này với người kia.',
        'p1' => 'Trong các mốc đến hạn trong kỳ: số mốc được đánh dấu xong trước khi hết ngày đến hạn, trên tổng. "Trễ" là xong sau ngày đến hạn nhưng trước khi hết kỳ; xong sau khi hết kỳ vẫn là "lỡ" của kỳ đó, nên mốc đến hạn đúng ngày cuối kỳ chỉ có thể đúng hạn hoặc lỡ. Mốc tính cho người giữ nó vào ngày đến hạn; với các lần bàn giao trước ngày triển khai tính năng này, tính cho người giữ hiện tại. Mốc ghi vào hệ thống sau ngày đến hạn, và mốc của vụ đã kết thúc trước khi hết ngày đến hạn, không tính.',
        'p2' => 'Mốc người này giữ đã bị gỡ (xoá kèm lý do) trong kỳ. Không tính vào tỉ lệ; hiện ra để tỉ lệ không đẹp lên nhờ gỡ mốc.',
        'p3' => 'Yêu cầu khách gửi trong kỳ: số đã trả lời tới hết kỳ trên tổng (không tính yêu cầu văn phòng đóng mà không trả lời). Thời gian từ lúc khách gửi tới lần trả lời đầu tiên của văn phòng, trung vị và trung bình, tính theo giờ lịch (kể cả đêm và ngày nghỉ). Luồng tính cho người đang giữ nó lúc văn phòng trả lời, hoặc lúc hết kỳ nếu chưa trả lời; luồng giao đích danh rồi được chuyển cùng vụ trước ngày triển khai tính năng này tính cho người được giao hiện tại.',
        'p10' => 'Yêu cầu khách gửi trong kỳ mà văn phòng đóng lại không trả lời (trùng, khách rút, đã giải quyết ngoài hệ thống). Không tính vào tỉ lệ; hiện ra để tỉ lệ không đẹp lên nhờ đóng luồng chưa trả lời.',
        'p4' => 'Số lần người này đưa một vụ sang giai đoạn mới trong kỳ, theo ngày ghi trên dòng tiến độ, và số vụ khác nhau đã được đưa đi. Dòng cập nhật không đổi giai đoạn và dòng bàn giao nội bộ không tính. Không chia "tiến" hay "lùi". Dòng ghi lùi ngày làm đổi số của kỳ đã qua.',
        'p5' => 'Vụ người này phụ trách lúc vụ kết thúc đã vào giai đoạn kết thúc trong kỳ. Bàn giao một vụ đã kết thúc không chuyển con số này. Ngày kết thúc không mang giờ, nên vụ tính cho người phụ trách vào cuối ngày kết thúc.',
        'p6' => 'Số lần người này bấm duyệt hoặc từ chối một đầu mục giấy tờ trong kỳ, theo nhật ký hệ thống. Một đầu mục khách nộp lại rồi được duyệt lại tính hai lần: đó là hai lần duyệt. Văn phòng tải giấy tờ lên thay khách không phải một lần duyệt.',
        'p7' => 'Tiền khách đã trả trong kỳ, tính cho luật sư phụ trách vụ tại lúc ghi khoản thu. Khoản thu đã huỷ không tính. Cùng con số trên trang Doanh thu.',
        'p9' => 'Mốc đến hạn đã xong tới hết kỳ (đúng hạn hoặc trễ) cộng yêu cầu khách đã trả lời tới hết kỳ, chia cho tổng mốc đến hạn và yêu cầu nhận trong kỳ (trừ yêu cầu đóng không trả lời). Mỗi việc một đơn vị, không trọng số. Giấy tờ khách nộp không tính vào đây, vì phần lớn không nằm trong tay nhân sự. Dưới 5 việc thì không tính tỉ lệ.',
        'reference' => 'Dòng "Chung" tính mọi việc trong các vụ việc anh/chị được xem theo cùng công thức, không lọc theo người giữ: gồm cả việc của quản trị viên, của người đã nghỉ việc hay không còn tài khoản, và việc không quy được về ai. Vì vậy nó không bằng tổng các dòng bên dưới. Dòng này để so một người với chính văn phòng, không với từng người khác.',
        'closed_period' => 'Kỳ đã đóng không trôi: hoàn thành mốc, trả lời yêu cầu hay bàn giao vụ sau khi hết kỳ không làm đổi số của kỳ đó. Sáu thao tác vẫn làm đổi được, vì mỗi thao tác là văn phòng nói lại điều đã xảy ra và đều có dòng nhật ký: ghi lùi ngày một dòng tiến độ, mở lại một vụ đã kết thúc, gỡ một mốc, dời ngày đến hạn của một mốc, mở lại một mốc đã xong, đóng một yêu cầu chưa trả lời.',
        'not_applicable' => '"Không áp dụng": cột chỉ dành cho người phụ trách vụ việc, mà vai trò của người này không đứng tên phụ trách vụ (ví dụ trợ lý). Số 0 nghĩa là không có việc nào trong các vụ việc anh/chị được xem.',
    ],

    // R16 — kỳ của trang "Hiệu suất theo kỳ" (App\Support\Performance\PerformancePeriod).
    'period' => [
        'field' => 'Kỳ',
        'date_from' => 'Từ ngày',
        'date_to' => 'Đến ngày',
        'apply' => 'Xem số liệu',
        'options' => [
            'last_month' => 'Tháng trước',
            'this_month' => 'Tháng này',
            'last_quarter' => 'Quý trước',
            'this_quarter' => 'Quý này',
            'custom' => 'Tuỳ chọn (từ – đến)',
        ],
        // Kỳ cố định của trang một người (Task 7), không có trên ô chọn.
        'trailing' => ':days ngày gần nhất',
        'label' => ':name (:from – :to)',
        'running' => '(kỳ đang chạy — số còn thay đổi)',
        'errors' => [
            'unknown' => 'Kỳ đã chọn không hợp lệ.',
            'dates_required' => 'Hãy chọn đủ ngày bắt đầu và ngày kết thúc.',
            'invalid_date' => 'Ngày không hợp lệ.',
            'order' => 'Ngày bắt đầu phải trước hoặc trùng ngày kết thúc.',
            'future' => 'Ngày kết thúc không được sau hôm nay.',
            'too_long' => 'Kỳ tuỳ chọn dài tối đa :max ngày; kỳ đã chọn dài :days ngày.',
        ],
    ],

    // R7 — mọi tỉ lệ của M13 (App\Support\Performance\Ratio).
    'ratio' => [
        'label' => ':percent% (:numerator/:denominator)',
        'insufficient' => 'Chưa đủ dữ liệu (n = :n)',
    ],

    // R17 — thời lượng (App\Support\Performance\ResponseTime).
    'duration' => [
        'hours' => ':hours giờ',
        'days' => ':days ngày',
        'days_hours' => ':days ngày :hours giờ',
    ],

    'period_page' => [
        'reference_name' => 'Chung — các vụ anh/chị được xem',
        'include_inactive' => 'Gồm người đã nghỉ việc',
        'inactive' => 'Đã nghỉ việc',
        'empty' => 'Không có nhân sự nào trong kỳ này.',
        // Nhãn của câu `explain.closed_period` trong khối "Cách tính các con số".
        'closed_period_label' => 'Kỳ đã đóng',
        'p1_breakdown' => 'Đúng hạn :on_time · trễ :late · lỡ :missed',
        'p3_state' => ':answered/:received đã trả lời',
        'p3_response' => 'Trung vị :median · trung bình :mean (giờ lịch)',
        'p4_state' => ':entries lần · :matters vụ',
        'p9_breakdown' => ':deadlines_done/:deadlines mốc · :answered/:received yêu cầu',
        'no_ranking' => [
            'heading' => 'Vì sao không có bảng xếp hạng',
            'intro' => 'Trang này giúp đánh giá từng người trên chính việc của họ, không xếp người này với người kia. Bảng xếp theo tên; không cột số nào sắp xếp được.',
            'reasons' => [
                'Cơ cấu vụ khác nhau: một luật sư hình sự có nhiều mốc dày, khách khó liên lạc; một luật sư doanh nghiệp có ít mốc. So thẳng tỉ lệ của hai người là so hai loại việc. Cột "Lĩnh vực chính" cho biết bối cảnh của từng dòng.',
                'Mẫu nhỏ: mỗi người có vài chục mốc mỗi quý, nên hai điểm phần trăm chênh nhau là nhiễu. Dưới 5 việc thì trang không tính tỉ lệ.',
                'Xếp hạng dạy người ta làm đẹp con số: bấm "xong" sớm, gỡ mốc khó, đóng yêu cầu chưa trả lời, tránh nhận vụ khó. Vì vậy mốc đã gỡ và yêu cầu đóng không trả lời hiện ở cột riêng.',
                'Vai trò khác nhau: trợ lý và luật sư không làm cùng loại việc.',
            ],
        ],
    ],
];
