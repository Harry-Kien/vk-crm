<?php

/**
 * Nhãn tiếng Việt cho resource User (SPEC §7.4) — quản trị nhân sự nội bộ, gated bằng
 * settings.manage. Không có trường nào cho mật khẩu hiện tại hay hai lớp xác thực: mật khẩu chỉ
 * nhập mới (không hiện lại), hai lớp xác thực do người dùng tự quản lý ở hồ sơ của họ.
 */
return [
    'label' => 'Nhân sự',
    'plural_label' => 'Nhân sự',
    'fields' => [
        'name' => 'Họ tên',
        'email' => 'Email đăng nhập',
        'phone' => 'Điện thoại',
        'position' => 'Chức danh',
        'bar_number' => 'Số thẻ luật sư',
        'is_active' => 'Đang hoạt động',
        'password' => 'Mật khẩu',
        'last_login_at' => 'Đăng nhập gần nhất',
    ],
    'password_hint' => 'Để trống khi sửa nếu không muốn đổi mật khẩu.',

    // R7 (M6.5 Task 4, kéo lên từ M7 R6) — chặn nghỉ việc khi còn việc dở dang, hoặc khi là quản
    // trị viên đang hoạt động cuối cùng. Dùng bởi UserPolicy::delete() và
    // EditUser::handleRecordUpdate() (App\Actions\User\Concerns\GuardsStaffOffboarding), và làm
    // câu giải thích tĩnh trên form (UserForm).
    //
    // Fix round 1 (finding CRITICAL): bốn khoá `open_work_*` GHÉP LẠI thành một câu, mỗi loại việc
    // một mảnh CHỈ khi loại đó còn > 0 (GuardsStaffOffboarding::offboardingOpenWorkReason() build),
    // và mỗi mảnh nêu đúng MÀN HÌNH thật xử lý được loại việc đó — không còn chỉ nói "Bàn giao" cho
    // cả ba loại như bản trước, thứ không có đường ra cho mốc hạn/yêu cầu khách của một người
    // không phải lead.
    'offboarding' => [
        'open_work_intro' => 'Không thể vô hiệu hoá hoặc xoá :name: người này còn',
        'open_work_lead_matters' => ':count vụ việc đang mở với vai luật sư phụ trách — dùng "Bàn giao" trên từng vụ việc',
        'open_work_deadlines' => ':count mốc hạn chưa xong — dùng "Đổi người phụ trách" trên tab Mốc thời hạn của từng vụ việc',
        'open_work_client_requests' => ':count yêu cầu khách chưa đóng — dùng "Giao việc" trên tab Yêu cầu từ khách của từng vụ việc',
        'open_work_outro' => 'Hãy xử lý xong rồi thử lại.',
        'last_admin_blocked' => 'Không thể thực hiện: đây là quản trị viên đang hoạt động cuối cùng của hệ thống. Hãy chỉ định thêm ít nhất một quản trị viên khác trước khi đổi chức danh, vô hiệu hoá hoặc xoá tài khoản này.',
        'is_active_hint' => 'Sẽ bị chặn nếu nhân sự này còn là luật sư phụ trách một vụ việc đang mở, còn đứng tên mốc hạn hoặc yêu cầu khách chưa xong, hoặc là quản trị viên đang hoạt động cuối cùng của hệ thống.',
        'position_hint' => 'Đổi chức danh sang Trợ lý hoặc Kế toán sẽ bị chặn nếu người này còn việc dở dang (cùng luật vô hiệu hoá). Đổi chức danh khỏi Quản trị viên sẽ bị chặn nếu đây là quản trị viên đang hoạt động cuối cùng của hệ thống.',
    ],
];
