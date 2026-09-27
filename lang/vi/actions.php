<?php

/**
 * Thông điệp lỗi xác thực dữ liệu đầu vào của các Action (khác với lang/vi/exceptions.php,
 * dành cho các domain exception có tên riêng).
 */
return [
    // Tiêu đề chung của mọi thông báo "thao tác không thực hiện được" trên panel admin (xem
    // `App\Filament\Admin\Concerns\ReportsActionFailures`). Cố tình KHÔNG nói vì sao ở tiêu đề:
    // lý do nằm ở phần thân, nguyên văn câu mà Action đã viết cho đúng tình huống đó, và một
    // tiêu đề tự tóm tắt lại sẽ luôn tóm tắt sai một trong số chúng.
    'failed_title' => 'Chưa thực hiện được thao tác này',

    // Câu DUY NHẤT cho mọi lần `Gate` từ chối bên trong một Action (`AuthorizationException`).
    // Ba nguyên nhân thật — hồ sơ bị xoá mềm, người nộp bị gỡ khỏi đội ngũ, quyền công bố bị thu
    // hồi — cố ý KHÔNG phân biệt được với nhau ở đây, và câu này cũng không khẳng định bản ghi
    // có tồn tại hay không: SPEC §10.10 cấm mọi thông điệp lỗi tiết lộ sự tồn tại của một bản
    // ghi, và một câu riêng cho từng nguyên nhân chính là một máy dò. Xem
    // `App\Filament\Admin\Concerns\ReportsActionFailures`.
    'unauthorized' => 'Màn hình bạn đang mở không còn khớp với dữ liệu và quyền hiện tại, nên thao tác đã dừng lại và không có gì được lưu. Hãy tải lại trang rồi thử lại; nếu vẫn không được, nhờ người phụ trách hồ sơ hoặc quản trị viên.',

    'transition_matter_stage' => [
        'public_content_too_short' => 'Nội dung công khai cho khách phải có ít nhất 30 ký tự khi công bố tiến độ.',
        'occurred_at_future' => 'Ngày xảy ra không được ở tương lai.',
    ],
    'open_matter' => [
        'client_role_required' => 'Phải chọn vai của khách hàng (nguyên đơn/bị đơn/...) trong vụ việc này trước khi mở vụ việc — không có mặc định, vì mặc định sai sẽ khiến kiểm tra xung đột lợi ích bỏ sót mức đỏ.',
        'client_role_opposing_counsel' => 'Khách hàng của văn phòng không thể mang vai "Luật sư đối phương" trong chính vụ việc mình đang là khách hàng.',
    ],
    'add_team_member' => [
        'lead_role_denied' => 'Không thể thêm ai vào đội ngũ với vai "Luật sư phụ trách" qua màn hình này — vai đó chỉ đổi được qua bàn giao vụ việc.',
        'role_not_eligible' => 'Người được chọn không giữ được vai này: trợ lý cho vai "Trợ lý"; luật sư hoặc trưởng phòng cho vai "Luật sư cộng sự"; mọi nhân sự nội bộ trừ kế toán cho vai "Theo dõi".',
        'member_inactive' => 'Người được chọn đã bị vô hiệu hoá hoặc đã nghỉ việc, không thể thêm vào đội ngũ.',
        'already_member' => 'Người này đã có trong đội ngũ của vụ việc.',
        // Fix round 1, finding I1: vụ việc hạn chế (restricted) chỉ luật sư phụ trách và quản
        // trị viên xem được (SPEC §4.6) — người vừa thêm không thuộc hai nhóm đó thì sẽ không
        // bao giờ mở được vụ việc họ vừa được thêm vào, nên Action từ chối ngay tại đây.
        'restricted_visibility_denied' => 'Không thể thêm người này: vụ việc đang ở chế độ hạn chế, chỉ luật sư phụ trách và quản trị viên xem được. Người được chọn sẽ không thấy được vụ việc này sau khi thêm.',
    ],
    'remove_team_member' => [
        'not_member' => 'Người này không có trong đội ngũ của vụ việc.',
        // Fix round 1, finding S3: vai lead chỉ đổi qua bàn giao vụ việc (ReassignMatter, M7).
        'lead_role_denied' => 'Không thể gỡ luật sư phụ trách khỏi đội ngũ qua đây — vai này chỉ đổi được qua bàn giao vụ việc.',
    ],
    // M6.5 Task 15 — cùng luật `ApplyChecklistTemplate` dùng để không tạo trùng khi áp lại một
    // mẫu: tên đầu mục là duy nhất trong một vụ việc, kể cả với đầu mục đã gỡ (xoá mềm).
    'add_checklist_item' => [
        'duplicate_name' => 'Vụ việc này đã có một đầu mục tên như vậy, kể cả đầu mục đã gỡ khỏi danh mục. Anh/chị đặt tên khác cho rõ, hoặc dùng lại đầu mục cũ nếu nó vẫn còn trong danh mục.',
        'name_required' => 'Phải nhập tên đầu mục.',
    ],
    // M6.5 Task 5.
    'cancel_matter' => [
        'reason_required' => 'Phải nhập lý do huỷ hồ sơ.',
        'already_cancelled' => 'Vụ việc này đã bị huỷ trước đó.',
    ],
];
