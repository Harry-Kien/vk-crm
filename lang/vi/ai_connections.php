<?php

/**
 * M11 Task 15 — hai trang "Kết nối AI" (quản trị, `App\Filament\Admin\Pages\AiConnections`) và "Kết
 * nối AI của tôi" (nhân sự, `App\Filament\Admin\Pages\MyAiConnections`), cùng lỗi kiểm tra của
 * `App\Actions\Mcp\UpdateAiSettings`. Tệp riêng (không trộn vào `mcp.php` hay `ai_access.php`) để làn
 * tool chạy song song không đụng cùng một khối.
 *
 * `policy.items` là bản tóm tắt TRONG APP của chính sách dùng AI phiên bản
 * `config('vkcrm.mcp.policy_version')` (R12 mục 1–2, R4, R5): nhân sự đọc nó rồi mới tích ô cam kết.
 * Văn bản đầy đủ là `docs/CHINH-SACH-AI.md` (Task 16); sửa văn bản thì đổi phiên bản VÀ sửa các mục
 * dưới đây cùng lúc, để lời cam kết luôn gắn với đúng điều người đó đã đọc.
 */
return [
    'admin' => [
        'navigation_label' => 'Kết nối AI',
        'title' => 'Kết nối AI',
        'intro' => 'Bật trợ lý AI cho từng nhân sự, xem và thu hồi các kết nối đang có, đọc nhật ký mọi lần trợ lý AI dùng một chức năng (tool) của hệ thống. Nhân sự dùng tài khoản AI của chính mình; trợ lý hành động với danh nghĩa và quyền của người đó.',

        'columns' => [
            'name' => 'Nhân sự',
            'ai_access' => 'Chế độ',
            'acknowledged' => 'Cam kết chính sách',
            'connections' => 'Kết nối',
            'last_used_at' => 'Lần dùng cuối',
        ],
        'acknowledged_on' => ':date (phiên bản :version)',
        'acknowledged_outdated' => ':date (phiên bản cũ :version — cần cam kết lại)',
        'not_acknowledged' => 'Chưa cam kết',
        'never_used' => 'Chưa dùng',
        'inactive' => 'Đang bị vô hiệu hoá',

        'actions' => [
            'set_ai_access' => 'Đổi chế độ',
            'set_ai_access_heading' => 'Đổi chế độ truy cập qua AI của :name',
            'set_ai_access_hint' => 'Hạ về "Tắt" thì mọi kết nối AI của người này bị thu hồi ngay. Hạ "Đọc và ghi" về "Chỉ đọc" thì kết nối còn, nhưng không ghi được gì nữa.',
            'set_ai_access_hint_locked' => 'Người này không có quyền xem nội dung vụ việc (ví dụ Kế toán), hoặc tài khoản đang bị vô hiệu hoá, nên chỉ đặt được "Tắt".',
            'show_connections' => 'Kết nối',
        ],
        'notifications' => [
            'ai_access_saved' => 'Đã đổi chế độ truy cập qua AI.',
        ],

        'detail' => [
            'heading' => 'Kết nối AI của :name',
            'empty' => 'Người này không có kết nối AI nào đang sống.',
            'close' => 'Đóng',
        ],

        'switches' => [
            'heading' => 'Công tắc toàn hệ thống',
            // Làn ai (2026-10-15): đoạn dài này nay nằm trong "Chi tiết kỹ thuật", gọi công tắc theo nhãn mới.
            'description' => 'Tắt "Cho phép trợ lý AI đọc dữ liệu" (máy chủ AI) thì mọi trợ lý AI của mọi nhân sự bị từ chối ngay ở lần gọi kế tiếp; tắt không thu hồi kết nối nào, nên bật lại thì kết nối cũ dùng tiếp được (có ứng dụng AI vẫn có thể đòi kết nối lại). Tắt "Cho phép trợ lý AI ghi" thì không ai ghi được gì qua AI, kể cả người ở chế độ "Đọc và ghi".',
            // Làn ai: nhãn tiếng thường; tên khoá kỹ thuật chỉ còn trong "Chi tiết kỹ thuật".
            'enabled' => 'Cho phép trợ lý AI đọc dữ liệu',
            'write_enabled' => 'Cho phép trợ lý AI ghi (tạo nháp, ghi chú…)',
            'write_needs_enabled' => 'Quyền ghi chỉ có tác dụng khi máy chủ AI cũng bật.',
            'save' => 'Lưu công tắc',
            'saved' => 'Đã lưu công tắc.',
            'unchanged' => 'Không có gì thay đổi.',

            // làn ai
            'enabled_help' => 'Công tắc chính (máy chủ AI). Tắt thì mọi trợ lý AI của mọi nhân sự bị từ chối ngay.',
            'write_help' => 'Chỉ cho người ở chế độ "Đọc và ghi", và chỉ khi công tắc đọc cũng bật. Nháp không tự tới khách.',
            'technical' => 'Chi tiết kỹ thuật',
            'technical_keys' => 'Hai công tắc lưu trong bảng cấu hình với tên khoá :enabled (đọc — máy chủ AI) và :write (ghi). Mặc định của hệ thống: cả hai TẮT.',
            'confirm' => [
                'heading' => 'Lưu công tắc trợ lý AI?',
                'submit' => 'Lưu',
                'server_off' => 'Tắt đọc: mọi trợ lý AI của mọi nhân sự bị từ chối ngay ở lần gọi kế tiếp. Không kết nối nào bị thu hồi, nên bật lại thì kết nối cũ dùng tiếp được (có ứng dụng AI vẫn có thể đòi kết nối lại).',
                'server_on' => 'Bật đọc: nhân sự đã được bật chế độ, đã cam kết chính sách và đã nối tài khoản AI của mình hỏi được trợ lý AI ngay, trên các vụ việc đã bật "cho phép AI" (vụ hạn chế không bao giờ hiện với AI).',
                'write_on' => 'Bật ghi: người ở chế độ "Đọc và ghi" tạo được nháp dòng tiến độ, nháp trả lời khách, mốc nội bộ và nhật ký liên lạc qua AI. Nháp không bao giờ tự tới khách.',
                'write_off' => 'Tắt ghi: không ai ghi được gì qua AI, kể cả người ở chế độ "Đọc và ghi". Đọc vẫn như cũ.',
                'write_without_server' => 'Công tắc ghi được lưu bật nhưng chưa có tác dụng, vì công tắc đọc đang tắt.',
                'unchanged' => 'Không có gì thay đổi.',
            ],
        ],

        'audit' => [
            'heading' => 'Nhật ký MCP',
            'description' => ':limit dòng gần nhất: mỗi lần trợ lý AI gọi công cụ, mỗi lần đồng ý hay từ chối kết nối, làm mới kết nối, thu hồi, đổi chế độ, đổi công tắc. Không ghi câu hỏi hay nội dung trả về; văn bản tự do chỉ còn độ dài.',
            'filter_user' => 'Nhân sự',
            'filter_tool' => 'Công cụ',
            'filter_all' => 'Tất cả',
            'empty' => 'Chưa có dòng nhật ký nào.',
            'columns' => [
                'at' => 'Thời điểm',
                'person' => 'Người',
                'event' => 'Sự kiện',
                'tool' => 'Công cụ',
                'outcome' => 'Kết quả',
                'platform' => 'Nền tảng',
                'ip' => 'IP',
                'details' => 'Chi tiết',
            ],
            'system' => 'Hệ thống',
        ],
    ],

    'actions' => [
        'revoke' => 'Thu hồi',
        'revoke_heading' => 'Thu hồi kết nối này?',
        'revoke_description' => 'Trợ lý AI trên kết nối này bị từ chối ngay ở lần gọi kế tiếp và không làm mới được nữa. Muốn dùng lại thì kết nối lại từ ứng dụng AI. Các kết nối khác giữ nguyên.',
        'revoke_all' => 'Thu hồi tất cả',
        'revoke_all_heading' => 'Thu hồi mọi kết nối AI của người này?',
        'revoke_all_description' => 'Mọi trợ lý AI của người này bị từ chối ngay ở lần gọi kế tiếp. Chế độ truy cập giữ nguyên: người này kết nối lại được. Muốn chặn hẳn thì đổi chế độ về "Tắt".',
        'revoked' => 'Đã thu hồi.',
        'nothing_revoked' => 'Không còn gì để thu hồi.',
    ],

    'connections' => [
        'platform' => 'Nền tảng',
        'host' => 'Địa chỉ chuyển hướng',
        'connected_at' => 'Kết nối từ',
        'last_used_at' => 'Lần dùng cuối',
        'never_used' => 'Chưa dùng',
    ],

    'assessment' => [
        'banner_title' => 'Việc pháp lý của chủ văn phòng trước khi dùng trợ lý AI trên dữ liệu thật',
        'banner_intro' => 'Dữ liệu trợ lý AI nhận từ hệ thống (Claude, ChatGPT…) là chuyển dữ liệu cá nhân ra nước ngoài theo Điều 20 Luật Bảo vệ dữ liệu cá nhân. Đây là thông tin tham khảo, không phải tư vấn pháp lý; chủ văn phòng quyết định.',
        'items' => [
            'transfer_assessment' => 'Lập và nộp hồ sơ đánh giá tác động chuyển dữ liệu cá nhân ra nước ngoài cho A05 (Bộ Công an) trong 60 ngày kể từ lần chuyển đầu tiên, cập nhật 6 tháng một lần. Văn phòng gọi là "Mẫu 10"; tra cứu chưa xác nhận số mẫu là 09 hay 10.',
            'client_consent' => 'Có đồng ý bằng văn bản của khách cho từng vụ việc được bật cho AI (điều khoản riêng, không đánh dấu sẵn).',
            'protection_officer' => 'Chỉ định người hoặc bộ phận bảo vệ dữ liệu cá nhân.',
            'incident_procedure' => 'Có quy trình báo sự cố dữ liệu trong 72 giờ.',
        ],
        'banner_hint' => 'Dải này ẩn khi quản trị ghi ngày đã nộp hồ sơ. Ghi ngày chỉ ẩn dải cảnh báo, không bật hay tắt gì khác.',
        'filed_on' => 'Ngày đã nộp hồ sơ đánh giá tác động',
        'filed_note' => 'Hồ sơ đánh giá tác động chuyển dữ liệu ra nước ngoài đã nộp ngày :date. Nhớ cập nhật 6 tháng một lần.',
        'save' => 'Lưu ngày nộp',
        'saved' => 'Đã lưu ngày nộp hồ sơ.',
    ],

    'mine' => [
        'navigation_label' => 'Kết nối AI của tôi',
        'title' => 'Kết nối AI của tôi',
        'intro' => 'Dùng tài khoản AI của chính anh/chị (Claude, ChatGPT…) để hỏi hồ sơ và soạn nháp. Trợ lý AI hành động với danh nghĩa và quyền của anh/chị: chỉ thấy những gì anh/chị thấy trên web, trừ các loại dữ liệu không bao giờ đi qua kênh này.',
        'mode_heading' => 'Chế độ của tôi',
        'mode' => 'Chế độ truy cập qua AI: :mode',
        'status_ready' => 'Trợ lý AI của anh/chị đang dùng được.',
        'status_refused' => 'Chưa dùng được: :reason',
        'mode_hint' => 'Chế độ do quản trị bật; hỏi quản trị nếu anh/chị cần.',
        'url_heading' => 'Địa chỉ máy chủ để dán vào ứng dụng AI',
        'url_hint' => 'Dán địa chỉ này vào mục thêm kết nối (connector) của ứng dụng AI, rồi đăng nhập và đồng ý trên trang của văn phòng. Không dán mật khẩu hay mã nào vào ứng dụng AI.',
        'guide' => 'Hướng dẫn từng nền tảng (Claude, Claude Code, ChatGPT, VS Code, Cursor) nằm trong tài liệu "Kết nối trợ lý AI" (docs/KET-NOI-AI.md) mà quản trị gửi kèm.',
        'connections_heading' => 'Kết nối của tôi',
        'connections_empty' => 'Anh/chị chưa có kết nối AI nào đang sống.',
    ],

    'policy' => [
        'heading' => 'Chính sách dùng AI (phiên bản :version)',
        'intro' => 'Đọc trước khi kết nối lần đầu, và đọc lại mỗi khi chính sách đổi phiên bản. Đây là cam kết của anh/chị với văn phòng; nó không thay đồng ý của khách hàng.',
        'items' => [
            'own_account' => 'Chỉ dùng tài khoản AI của chính mình; không chia sẻ kết nối hay tài khoản.',
            'training_off' => 'Tắt tuỳ chọn cho phép dùng hội thoại để huấn luyện mô hình trên tài khoản cá nhân (ChatGPT: "Improve the model for everyone"; Claude: tuỳ chọn tương ứng), và kiểm lại sau mỗi lần ứng dụng cập nhật.',
            'never_exposed' => 'Số CCCD/MST, số điện thoại đầy đủ, ghi chú nội bộ, tài liệu nhóm D, kết quả kiểm tra xung đột và nội dung tệp không bao giờ đi qua kênh này. Không tự dán chúng vào khung chat, không tải tệp hồ sơ lên ứng dụng AI.',
            'drafts_only' => 'Trợ lý AI chỉ tạo bản nháp và ghi chép nội bộ; không gì tới khách hàng nếu chưa có người duyệt trên web. Với các công cụ ghi, đặt chế độ "hỏi trước khi chạy" (Needs approval), không "luôn cho phép".',
            'verify' => 'Luôn kiểm tra lại kết quả của trợ lý AI trước khi dùng.',
            'logged' => 'Mọi lần trợ lý AI dùng một chức năng (tool) của hệ thống đều được ghi nhật ký với tên anh/chị, cùng các lần kết nối, làm mới và thu hồi kết nối.',
            'incident' => 'Nghi lộ dữ liệu, mất máy, hay trợ lý AI làm điều lạ: báo quản trị ngay (văn phòng phải báo cơ quan có thẩm quyền trong 72 giờ).',
        ],
        'checkbox' => 'Tôi đã đọc và cam kết tuân thủ chính sách dùng AI phiên bản này.',
        'submit' => 'Cam kết',
        'acknowledged' => 'Anh/chị đã cam kết chính sách phiên bản :version lúc :date.',
        'saved' => 'Đã ghi lời cam kết.',
        'unavailable' => 'Chính sách dùng AI chưa được cấu hình phiên bản, nên chưa cam kết được. Báo quản trị.',
    ],

    'validation' => [
        'filed_on_format' => 'Ngày nộp hồ sơ phải có dạng năm-tháng-ngày.',
        'filed_on_future' => 'Ngày nộp hồ sơ không được ở tương lai.',
    ],

    // ---------------------------------------------------------------------------------------------
    // làn ai (2026-10-15) — hai trang "Kết nối AI" dễ hiểu như CRM quốc tế: thẻ trạng thái tổng, các
    // bước bắt đầu dùng, mục pháp lý thu gọn, bảng nhân sự, hoạt động của trợ lý AI, và hướng dẫn từng
    // bước của "Kết nối AI của tôi". Các bước trong ứng dụng AI (`guide.claude`, `guide.chatgpt`) lấy
    // đúng từ docs/KET-NOI-AI.md; đường menu đặt trong «…» được test đối chiếu nguyên văn với tài liệu.
    // ---------------------------------------------------------------------------------------------

    'status' => [
        'heading' => 'Tình trạng trợ lý AI',
        'off' => 'Trợ lý AI đang TẮT cho toàn văn phòng',
        'read' => 'Trợ lý AI đang BẬT — chỉ đọc',
        'read_write' => 'Trợ lý AI đang BẬT — đọc và ghi',
        'hints' => [
            'off' => 'Không nhân sự nào dùng được trợ lý AI cho tới khi bật "Cho phép trợ lý AI đọc dữ liệu" ở mục Công tắc.',
            'read' => 'Nhân sự được bật, đã cam kết chính sách và đã nối tài khoản AI của mình hỏi được các vụ việc đã bật "cho phép AI" (vụ hạn chế không bao giờ hiện với AI); không ai ghi được gì qua AI.',
            'read_write' => 'Người ở chế độ "Đọc và ghi" tạo được nháp và ghi chép nội bộ qua AI; nháp không tự tới khách.',
        ],
        'assessment_missing' => 'Chưa ghi ngày nộp hồ sơ đánh giá tác động chuyển dữ liệu ra nước ngoài. Xem mục "Việc pháp lý trước khi dùng trên dữ liệu thật" bên dưới.',
        'stats' => [
            'enabled' => 'Nhân sự được bật',
            'acknowledged' => 'Đã cam kết chính sách',
            'connected' => 'Đang có kết nối',
            // Nhãn của con số lượt gọi là `activity.calls` (cùng chữ với khối "Hoạt động", n2); đây là dòng nhỏ dưới số.
            'calls_breakdown' => ':ok thành công, :other không thành công (bị từ chối, lỗi…)',
        ],
    ],

    'setup' => [
        'heading' => 'Các bước để bắt đầu dùng',
        'intro' => 'Mỗi bước tự đánh dấu ✓ theo tình trạng thật của hệ thống, không cần tích tay.',
        'done' => 'Đã xong',
        'todo' => 'Chưa xong',
        'steps' => [
            'legal' => 'Chủ văn phòng làm các việc pháp lý và ghi ngày nộp hồ sơ đánh giá tác động',
            'server' => 'Bật trợ lý AI cho văn phòng (công tắc "Cho phép trợ lý AI đọc dữ liệu")',
            'staff_enabled' => 'Bật AI cho từng nhân sự cần dùng (nút "Đổi chế độ" ở bảng nhân sự)',
            'staff_acknowledged' => 'Nhân sự được bật đọc và cam kết chính sách dùng AI (trên trang "Kết nối AI của tôi" của họ)',
            'staff_connected' => 'Nhân sự nối tài khoản AI của mình (Claude, ChatGPT…) theo trang "Kết nối AI của tôi"',
            'matters' => 'Bật "cho phép AI" trên từng vụ việc cần dùng, sau khi khách đồng ý bằng văn bản (docs/CHINH-SACH-AI.md). Vụ hạn chế không bao giờ hiện với AI nên không được đếm',
        ],
        'progress' => [
            'filed' => 'Đã nộp ngày :date',
            'people' => ':count người',
            'ratio' => ':done/:total người',
            'matters' => ':count vụ việc',
        ],
        'links' => [
            'legal' => 'Mở mục pháp lý',
            'server' => 'Tới công tắc',
            'staff_enabled' => 'Tới bảng nhân sự',
            'matters' => 'Mở danh sách vụ việc',
        ],
    ],

    'legal' => [
        'summary' => 'Việc pháp lý trước khi dùng trên dữ liệu thật',
    ],

    'staff' => [
        'filters' => [
            'enabled' => 'Đang bật',
            'not_acknowledged' => 'Được bật, chưa cam kết',
            'connected' => 'Có kết nối',
        ],
        'bulk' => [
            'label' => 'Đổi chế độ',
            'heading' => 'Đổi chế độ truy cập qua AI cho những người đã chọn',
            'hint' => 'Mỗi người được đặt riêng, như bấm "Đổi chế độ" từng dòng. Người không bật được (tài khoản bị vô hiệu hoá, hay không có quyền xem nội dung vụ việc như Kế toán) bị bỏ qua. Hạ về "Tắt" thì mọi kết nối AI của người đó bị thu hồi ngay.',
            'done' => 'Đã đặt chế độ cho :count người.',
            'skipped' => 'Bỏ qua :count người không bật được: :names.',
        ],
    ],

    'activity' => [
        'heading' => 'Hoạt động của trợ lý AI',
        'intro' => 'Đếm từ nhật ký MCP: mỗi lần trợ lý AI dùng một chức năng (tool) của hệ thống, kể cả lần bị từ chối. Không có câu hỏi hay nội dung trả về.',
        'period' => ':days ngày qua',
        'calls' => 'Lượt gọi (:days ngày)',
        'refused_hint' => '"Bị từ chối" gồm: không đủ quyền, chế độ "Chỉ đọc" gọi chức năng ghi, công tắc đang tắt, chưa cam kết chính sách (cùng là "Bị từ chối" trong nhật ký), và vượt giới hạn tần suất.',
        'awaiting_review' => 'Lần ghi của AI chờ người duyệt',
        'awaiting_review_hint' => 'Gồm nháp dòng tiến độ và nháp trả lời khách do AI soạn (chờ người dùng hay bỏ) và mốc "Tạo qua AI, chưa xác nhận", trên vụ việc chưa bị xoá. Không gì tới khách nếu chưa có người duyệt.',
        'by_day' => 'Theo ngày',
        'by_tool' => 'Theo chức năng',
        'by_person' => 'Theo người',
        'columns' => [
            'day' => 'Ngày',
            'tool' => 'Chức năng',
            'person' => 'Người',
            'calls' => 'Lượt gọi',
            'refused' => 'Bị từ chối',
            'last' => 'Lần gần nhất',
        ],
        'unknown_tool' => '(chức năng không có trên máy chủ)',
        'empty' => 'Chưa có lượt gọi nào trong :days ngày qua.',
    ],

    'guide' => [
        'status_heading' => 'Tình trạng của tôi',
        'status' => [
            'not_enabled' => 'Chưa được bật — liên hệ quản trị viên nếu anh/chị cần dùng trợ lý AI.',
            'server_off' => 'Trợ lý AI của văn phòng đang tắt — quản trị viên bật lại thì anh/chị dùng được.',
            'not_acknowledged' => 'Chưa cam kết chính sách dùng AI — làm Bước 1 bên dưới.',
            'not_connected' => 'Sẵn sàng — anh/chị chưa nối ứng dụng AI nào. Làm Bước 2, 3, 4 bên dưới.',
            'connected' => 'Đã kết nối — trợ lý AI của anh/chị đang dùng được.',
        ],
        'connected_count' => ':count kết nối đang sống.',
        'steps_heading' => 'Các bước kết nối',
        'steps' => [
            'policy' => 'Đọc và cam kết chính sách dùng AI',
            'url' => 'Sao chép địa chỉ máy chủ',
            'app' => 'Thêm kết nối trong ứng dụng AI',
            'consent' => 'Đăng nhập, bấm "Đồng ý" — xong',
        ],
        'policy_hint' => 'Mục "Chính sách dùng AI" ngay dưới các bước. Chính sách đổi phiên bản thì phải cam kết lại.',
        'copy' => 'Sao chép',
        'copied' => 'Đã sao chép',
        'tabs' => [
            'claude' => 'Claude',
            'chatgpt' => 'ChatGPT',
            'other' => 'Ứng dụng khác',
        ],
        'claude' => [
            'steps' => [
                'Trong Claude: «Customize → Connectors → Add custom connector».',
                'Dán địa chỉ máy chủ ở Bước 2. Để Claude tự đăng ký: không điền client ID hay secret.',
                'Claude mở trang đăng nhập của văn phòng: đăng nhập, nhập mã hai lớp, bấm "Đồng ý".',
                'Đặt bốn chức năng ghi ở «Needs approval» (hỏi trước khi chạy).',
            ],
            'note' => 'Gói Free chỉ được 1 custom connector. Gói Team, Enterprise: chỉ Owner của tổ chức thêm connector; sau đó từng người tự bấm Connect và đăng nhập bằng tài khoản của chính mình.',
        ],
        'chatgpt' => [
            'steps' => [
                'Bật «Settings → Security and login → Developer mode» (giao diện ChatGPT đổi thường xuyên).',
                'Tạo app ở «chatgpt.com/plugins», dán địa chỉ máy chủ ở Bước 2, chọn xác thực OAuth.',
                'ChatGPT mở trang đăng nhập của văn phòng: đăng nhập, nhập mã hai lớp, bấm "Đồng ý".',
                'Khi ChatGPT hỏi duyệt một chức năng ghi, không chọn ghi nhớ lựa chọn.',
            ],
            'note' => 'Developer mode có cho Plus, Pro, Business, Enterprise, Edu; không có cho Free. Trang đồng ý ghi tên miền chatgpt.com. Sau khi quản trị đổi chế độ của anh/chị, vào trang chi tiết của app và bấm Refresh.',
            'write_note' => 'Ghi qua ChatGPT (tạo nháp…): theo tài liệu "Kết nối trợ lý AI", chỉ gói Business, Enterprise, Edu ghi được; gói Pro chỉ đọc; với Plus các nguồn nói khác nhau. Dùng ChatGPT cá nhân thì coi như chỉ đọc.',
        ],
        'other' => 'Claude Code, VS Code (GitHub Copilot), Cursor, Antigravity: làm theo tài liệu "Kết nối trợ lý AI" (docs/KET-NOI-AI.md) mà quản trị gửi kèm — chọn kiểu kết nối HTTP và để ứng dụng tự đăng ký.',
        'consent_hint' => 'Trang đồng ý ghi tên miền sẽ nhận mã đăng nhập (ví dụ claude.ai với Claude, localhost với ứng dụng chạy trên máy anh/chị), không ghi cái tên ứng dụng tự khai. Không đúng ứng dụng đang nối thì bấm "Từ chối" và báo quản trị viên.',
    ],
];
