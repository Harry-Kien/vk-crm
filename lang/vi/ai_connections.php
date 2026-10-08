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
        'intro' => 'Bật trợ lý AI cho từng nhân sự, xem và thu hồi các kết nối đang có, đọc nhật ký mọi lần trợ lý AI gọi vào hệ thống. Nhân sự dùng tài khoản AI của chính mình; trợ lý hành động với danh nghĩa và quyền của người đó.',

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
            'description' => 'Tắt "Máy chủ AI" thì mọi trợ lý AI của mọi nhân sự bị từ chối ngay ở lần gọi kế tiếp; tắt không thu hồi kết nối nào, nên bật lại thì kết nối cũ dùng tiếp được (có ứng dụng AI vẫn có thể đòi kết nối lại). Tắt "Cho phép ghi" thì không ai ghi được gì qua AI, kể cả người ở chế độ "Đọc và ghi".',
            'enabled' => 'Máy chủ AI (mcp.enabled)',
            'write_enabled' => 'Cho phép ghi qua AI (mcp.write_enabled)',
            'write_needs_enabled' => 'Quyền ghi chỉ có tác dụng khi máy chủ AI cũng bật.',
            'save' => 'Lưu công tắc',
            'saved' => 'Đã lưu công tắc.',
            'unchanged' => 'Không có gì thay đổi.',
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
            'logged' => 'Mọi lần trợ lý AI gọi vào hệ thống đều được ghi nhật ký với tên anh/chị.',
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
];
