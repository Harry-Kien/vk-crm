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
    // R11, lối thoát cuối (rà soát cuối làn M13, I1): số của "Hiệu suất theo kỳ" và đầu trang của một người
    // giữ tạm theo người xem (`App\Support\Performance\PerformanceCache`); in trong "Cách tính các con số"
    // khi bộ nhớ tạm đang bật.
    'cache_note_label' => 'Số liệu giữ tạm',
    'cache_note' => 'Để trang mở nhanh, các con số trên trang này được giữ tạm cho riêng anh/chị tối đa :minutes phút. Việc vừa làm (hoàn thành một mốc, trả lời khách, bàn giao một vụ) có thể cần tới :minutes phút mới hiện ở đây.',

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

    // Task 4 (làn A) — số "bây giờ" N1–N11: tên cột, lời giải thích (R6), "Không áp dụng". N1–N10 trên
    // trang "Theo dõi đội ngũ"; N11 chỉ trên trang của một người (phán quyết N11, docblock
    // BuildTeamWorkload). Task 6 (làn m13b) — trang "Hiệu suất theo kỳ": cột P1–P7, P9, P10, "Lĩnh vực
    // chính", lời giải thích (R6), dòng "Chung" (R8), kỳ đã đóng; kỳ (R16), tỉ lệ (R7), thời lượng
    // (R17) ở các khối sau Task 5.
    //
    // Đã gộp hai làn: `columns` và `explain` là MỘT mảng mỗi khoá cho cả ba trang — khoá `n*` của
    // Task 4 rồi khoá `main_areas`, `p*`, `reference`, `closed_period` của Task 6. `not_applicable`,
    // `how_computed`, `columns.name` và `explain.not_applicable` hai làn cùng có: mỗi khoá giữ MỘT bản
    // (`explain.not_applicable` theo câu của làn m13b, nói "trong các vụ việc anh/chị được xem" — R4,
    // minor m3 của rà soát Task 4). Đừng để hai khối `'columns' => [...]` cùng cấp: PHP giữ khối sau,
    // nửa số câu biến mất (`TeamOverviewPageTest` canh: mỗi khoá cấp một khai báo đúng một lần).

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
        // Task 7 — xu hướng từ ảnh chụp hằng ngày (R10).
        'p8' => 'Xu hướng (đầu kỳ → cuối kỳ)',
    ],

    'explain' => [
        'n1' => 'Số vụ việc đang mở mà người này là luật sư phụ trách.',
        'n2' => 'Vụ đang mở mà người này có tên trong đội ngũ với vai luật sư cộng sự hoặc trợ lý. Không tính vai theo dõi.',
        'n3' => 'Tổng số vụ đã kết thúc đang đứng tên người này, từ trước tới nay. Một vụ đã kết thúc rồi mới bàn giao thì tính cho người nhận; số theo kỳ trên trang Hiệu suất thì tính cho người phụ trách lúc vụ kết thúc.',
        'n4' => 'Vụ đang mở, đã bật cổng khách, mà lần cập nhật gần nhất cho khách đã quá 14 ngày. Cùng luật với cảnh báo trên trang chủ. Luật này không áp cho vụ chưa bật cổng; số vụ đó hiện riêng ("chưa bật cổng").',
        'n5' => 'Mốc người này đang giữ, chưa đánh dấu xong, ngày đến hạn đã qua, trên vụ còn mở.',
        'n6' => 'Mốc người này đang giữ, chưa xong, đến hạn từ hôm nay tới hết ngày thứ 7 kể từ hôm nay, trên vụ còn mở.',
        'n7' => 'Vụ (đã bật cổng khách) còn đầu mục bắt buộc khách chưa nộp hoặc bị từ chối. Trong ngoặc: số vụ đã chờ quá 14 ngày.',
        'n8' => 'Đầu mục khách đã nộp mà văn phòng chưa duyệt, trên vụ người này phụ trách.',
        'n9' => 'Yêu cầu của khách ở trạng thái Mới hoặc Đang xử lý mà người này đang giữ: được giao, hoặc là luật sư phụ trách khi chưa giao ai (hoặc khi người được giao không còn tài khoản).',
        'n10' => 'Trên các vụ đang phụ trách: số đầu mục đã xong (đã duyệt, hoặc không cần nộp) trên số đầu mục phải có. Cùng cách tính thanh "Đã nộp X/Y" của từng vụ.',
        'n11' => 'Lần gần nhất người này ghi một thay đổi vào một vụ việc anh/chị được xem, theo nhật ký hệ thống. Đăng nhập không tính.',
        'main_areas' => 'Hai lĩnh vực có nhiều vụ nhất trong số vụ người này có việc trong kỳ (mốc đến hạn, yêu cầu của khách, chuyển giai đoạn, vụ kết thúc), kèm số vụ — để đọc các tỉ lệ trong đúng bối cảnh của kỳ đó, không phải để so người này với người kia.',
        'p1' => 'Trong các mốc đến hạn trong kỳ: số mốc được đánh dấu xong trước khi hết ngày đến hạn, trên tổng. "Trễ" là xong sau ngày đến hạn nhưng trước khi hết kỳ; xong sau khi hết kỳ vẫn là "lỡ" của kỳ đó, nên mốc đến hạn đúng ngày cuối kỳ chỉ có thể đúng hạn hoặc lỡ. Mốc tính cho người giữ nó vào ngày đến hạn; với các lần bàn giao trước ngày triển khai tính năng này, tính cho người giữ hiện tại. Mốc ghi vào hệ thống sau ngày đến hạn, và mốc của vụ đã kết thúc trước khi hết ngày đến hạn, không tính.',
        'p2' => 'Mốc người này giữ đã bị gỡ (xoá kèm lý do) trong kỳ. Không tính vào tỉ lệ; hiện ra để tỉ lệ không đẹp lên nhờ gỡ mốc.',
        'p3' => 'Yêu cầu khách gửi trong kỳ: số đã trả lời tới hết kỳ trên tổng (không tính yêu cầu văn phòng đóng mà không trả lời). Thời gian từ lúc khách gửi tới lần trả lời đầu tiên của văn phòng, trung vị và trung bình, tính theo giờ làm việc của văn phòng (cùng giờ làm việc mà màn hình Tiếp nhận dùng: chỉ các ngày và khung giờ làm việc; đêm và ngày nghỉ không tính; ngày lễ chưa được trừ). Luồng tính cho người đang giữ nó lúc văn phòng trả lời, hoặc lúc hết kỳ nếu chưa trả lời; luồng giao đích danh rồi được chuyển cùng vụ trước ngày triển khai tính năng này tính cho người được giao hiện tại.',
        'p10' => 'Yêu cầu khách gửi trong kỳ mà văn phòng đóng lại không trả lời (trùng, khách rút, đã giải quyết ngoài hệ thống). Không tính vào tỉ lệ; hiện ra để tỉ lệ không đẹp lên nhờ đóng luồng chưa trả lời.',
        'p4' => 'Số lần người này đưa một vụ sang giai đoạn mới trong kỳ, theo ngày ghi trên dòng tiến độ, và số vụ khác nhau đã được đưa đi. Dòng cập nhật không đổi giai đoạn và dòng bàn giao nội bộ không tính. Không chia "tiến" hay "lùi". Dòng ghi lùi ngày làm đổi số của kỳ đã qua.',
        'p5' => 'Vụ người này phụ trách lúc vụ kết thúc đã vào giai đoạn kết thúc trong kỳ. Bàn giao một vụ đã kết thúc không chuyển con số này. Ngày kết thúc không mang giờ, nên vụ tính cho người phụ trách vào cuối ngày kết thúc.',
        'p6' => 'Số lần người này bấm duyệt hoặc từ chối một đầu mục giấy tờ trong kỳ, theo nhật ký hệ thống. Một đầu mục khách nộp lại rồi được duyệt lại tính hai lần: đó là hai lần duyệt. Văn phòng tải giấy tờ lên thay khách không phải một lần duyệt.',
        'p7' => 'Tiền khách đã trả trong kỳ, tính cho luật sư phụ trách vụ tại lúc ghi khoản thu. Khoản thu đã huỷ không tính. Cùng con số trên trang Doanh thu.',
        'p9' => 'Mốc đến hạn đã xong tới hết kỳ (đúng hạn hoặc trễ) cộng yêu cầu khách đã trả lời tới hết kỳ, chia cho tổng mốc đến hạn và yêu cầu nhận trong kỳ (trừ yêu cầu đóng không trả lời). Mỗi việc một đơn vị, không trọng số. Giấy tờ khách nộp không tính vào đây, vì phần lớn không nằm trong tay nhân sự. Dưới 5 việc thì không tính tỉ lệ.',
        // Task 7 — P8, xu hướng từ ảnh chụp hằng ngày (R10).
        'p8' => 'Số mốc quá hạn và số vụ quá hạn cập nhật cho khách của người này vào cuối ngày đầu kỳ và cuối ngày cuối kỳ (không muộn hơn hôm qua), theo ảnh chụp hệ thống ghi lúc 23:50 mỗi ngày bằng đúng luật của trang "Theo dõi đội ngũ". Ảnh chụp chỉ có từ ngày triển khai tính năng này; ngày không có ảnh chụp để trống ("—"), không phải 0. Khác các cột khác của trang, cột này đọc số đã chụp: số của một ngày đã qua giữ nguyên như lúc chụp, nên một vụ được bàn giao hay bị huỷ sau ngày đó vẫn nằm trong số của ngày đó. Số của hôm nay luôn tính trực tiếp, trên trang của từng người (và trang "Theo dõi đội ngũ" với người được xem cả đội). Dòng "Chung" không có cột này.',
        'reference' => 'Dòng "Chung" tính mọi việc trong các vụ việc anh/chị được xem theo cùng công thức, không lọc theo người giữ: gồm cả việc của quản trị viên, của người đã nghỉ việc hay không còn tài khoản, và việc không quy được về ai. Vì vậy nó không bằng tổng các dòng bên dưới. Dòng này để so một người với chính văn phòng, không với từng người khác.',
        'closed_period' => 'Kỳ đã đóng không trôi: hoàn thành mốc hay trả lời yêu cầu sau khi hết kỳ không làm đổi số của kỳ đó, và bàn giao một vụ sau kỳ không chuyển việc của kỳ đó sang người nhận. Số của kỳ đã đóng chỉ đổi khi văn phòng nói lại điều đã xảy ra, và mỗi lần đều có dòng nhật ký: ghi lùi ngày một dòng tiến độ, mở lại một vụ đã kết thúc, gỡ một mốc, dời ngày đến hạn của một mốc, mở lại một mốc đã xong, đóng một yêu cầu chưa trả lời, huỷ một vụ việc. Ngoài ra, mọi con số chỉ tính trên các vụ việc anh/chị đang được xem: khi anh/chị không còn được xem một vụ (ví dụ một vụ hạn chế đã bàn giao cho người khác), việc trên vụ đó không còn trong số anh/chị đọc, kể cả ở kỳ đã qua.',
        'not_applicable' => '"Không áp dụng": cột chỉ dành cho người phụ trách vụ việc, mà vai trò của người này không đứng tên phụ trách vụ (ví dụ trợ lý). Số 0 nghĩa là không có việc nào trong các vụ việc anh/chị được xem.',
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
        'p3_response' => 'Trung vị :median · trung bình :mean (giờ làm việc)',
        'p4_state' => ':entries lần · :matters vụ',
        'p9_breakdown' => ':deadlines_done/:deadlines mốc · :answered/:received yêu cầu',
        // Task 7 — cột P8: ảnh chụp ngày đầu kỳ → ngày cuối kỳ; "—" = ngày đó không có ảnh chụp.
        'p8_overdue' => 'Mốc quá hạn: :start → :end',
        'p8_stale' => 'Quá hạn cập nhật: :start → :end',
        'p8_stale_not_applicable' => 'Quá hạn cập nhật: Không áp dụng',
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

    // Task 7 — hai widget xu hướng trên trang của một người (App\Filament\Admin\Widgets\Performance).
    'trend' => [
        'stale_heading' => 'Xu hướng: vụ quá hạn cập nhật cho khách',
        'overdue_heading' => 'Xu hướng: mốc quá hạn',
        'stale_series' => 'Vụ quá hạn cập nhật',
        'overdue_series' => 'Mốc quá hạn',
        // Mô tả dưới tiêu đề: khoảng ngày (PerformancePeriod::label()).
        'description' => ':period. Mỗi điểm là số cuối ngày; ngày không có ảnh chụp để trống.',
        // Bảng số của widget quá hạn cập nhật: kèm mức hoàn thiện danh mục (khác đơn vị, không vẽ chung).
        'stale_row' => ':stale vụ · danh mục :checklist',
        'overdue_row' => ':overdue mốc',
        'missing' => '—',
        // Khối "Cách tính các con số" của trang một người.
        'label' => 'Xu hướng',
        'explain' => 'Hai biểu đồ cuối trang: số vụ quá hạn cập nhật cho khách và số mốc quá hạn của người này vào cuối mỗi ngày, trong :days ngày gần nhất tính tới hôm qua, theo ảnh chụp hệ thống ghi lúc 23:50 mỗi ngày bằng đúng luật của các cột cùng tên ở đầu trang. Bảng số của biểu đồ vụ quá hạn cập nhật kèm mức hoàn thiện danh mục (đầu mục đã xong trên đầu mục phải có) của cùng ngày. Ảnh chụp chỉ có từ ngày triển khai tính năng này; ngày không có ảnh chụp để trống, không phải 0. Số của hôm nay là các con số ở đầu trang.',
    ],
];
