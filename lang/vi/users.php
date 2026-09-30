<?php

use App\Actions\User\ResetStaffTwoFactor;

/**
 * Nhãn tiếng Việt cho resource User (SPEC §7.4) — quản trị nhân sự nội bộ, gated bằng
 * settings.manage. Không có trường nào cho mật khẩu hiện tại: mật khẩu chỉ nhập mới (không hiện
 * lại). Hai lớp xác thực (2FA) do chính người dùng tự quản lý ở trang hồ sơ của họ (cài đặt, tạo
 * lại mã khôi phục) — **đính chính M8 Task 2 (R2)**: "tự quản lý" không còn đúng tuyệt đối, một
 * admin KHÁC đặt lại được 2FA của họ khi mất điện thoại (`actions.reset_two_factor` dưới đây),
 * nhưng không ai — kể cả chính người đó — TẮT được 2FA.
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

    /**
     * Fix round 1 (ruling "the staff profile page"): trang hồ sơ cá nhân
     * (App\Filament\Admin\Pages\Auth\EditProfile) khoá ô email — đổi email đăng nhập của một nhân
     * sự là việc của admin khác, qua trang Nhân sự (EditUser), không phải việc tự làm ở đây.
     */
    'profile' => [
        'email_readonly_hint' => 'Liên hệ quản trị viên để đổi email đăng nhập.',
    ],

    /**
     * "Đặt lại 2FA" (R2, kế hoạch M8 Task 2) — nút trên `EditUser` VÀ lệnh `vkcrm:reset-2fa`
     * ({@see ResetStaffTwoFactor}). Không phải "tắt 2FA": người bị đặt lại vẫn
     * bị buộc cài lại ở lần đăng nhập kế tiếp, chỉ mất secret CŨ.
     */
    'actions' => [
        'reset_two_factor' => [
            'label' => 'Đặt lại 2FA',
            'modal_heading' => 'Đặt lại 2FA của :name?',
            'modal_description' => 'Xoá xác thực ứng dụng hiện tại của :name và đăng xuất mọi phiên đang mở của họ. Lần đăng nhập kế tiếp, họ sẽ phải cài lại 2FA từ đầu. Dùng khi họ mất điện thoại và không còn mã khôi phục.',
            'success' => 'Đã đặt lại 2FA của :name — họ sẽ phải cài lại ở lần đăng nhập kế tiếp.',
            'console_not_found' => 'Không tìm thấy nhân sự với email :email.',
            'console_done' => 'Đã đặt lại 2FA của :email — họ sẽ phải cài lại ở lần đăng nhập kế tiếp.',
        ],
        // M8 Task 3 (SPEC §10.3): `UnlockStaffLogin` — nút trên `EditUser`.
        'unlock_login' => [
            'label' => 'Mở khoá đăng nhập',
            'modal_heading' => 'Mở khoá đăng nhập của :name?',
            'modal_description' => 'Xoá bộ đếm lần đăng nhập sai (cả bước mật khẩu lẫn bước mã) của :name để họ thử lại ngay. Chỉ dùng khi chắc chắn chính họ bị khoá, không phải một người lạ đang dò mật khẩu.',
            'success' => 'Đã xoá khoá đếm của :name. Họ đăng nhập lại được ngay.',
            'success_ip_still_locked' => 'Đã xoá khoá đếm của :name. Nhưng địa chỉ mạng liên quan tới lần khoá này vẫn còn bị khoá tạm — xin đợi thêm :minutes phút, hoặc thử từ một mạng khác (ví dụ 4G) để vào ngay.',
        ],
    ],

    /*
     * M8 Task 3 (SPEC §10.3): câu hiện ngay dưới ô đang nhập ở trang đăng nhập nội bộ khi chạm
     * trần 5 lần / 15 phút (theo email hoặc theo IP; cả bước mật khẩu lẫn bước mã). Nhân sự không
     * có "số điện thoại văn phòng" để gọi — con đường nhanh là nhờ MỘT quản trị viên KHÁC mở khoá
     * (nút "Mở khoá đăng nhập" ở trang sửa nhân sự). Câu nói thẳng rằng nếu đang dùng chung mạng
     * với lần gõ sai thì mở khoá tài khoản chưa chắc đủ: chiều IP có thể còn khoá.
     */
    'login' => [
        'throttled' => 'Đã thử đăng nhập quá nhiều lần. Xin đợi :minutes phút rồi thử lại, hoặc nhờ một quản trị viên khác mở khoá tài khoản này (trang Nhân sự, nút "Mở khoá đăng nhập") — nếu vẫn đang dùng chung mạng với lần gõ sai, có thể phải đợi hết :minutes phút dù tài khoản đã được mở khoá.',
    ],

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
        // Fix round 3, finding 4: đổi chức danh KHÔNG phải vô hiệu hoá/xoá — câu mở đầu riêng, dùng
        // bởi `demotionBlockedByLeadMattersReason()` (đích Trợ lý) VÀ
        // `demotionBlockedByAnyOpenWorkReason()` (đích Kế toán, ruling round 3 mục 5).
        'demotion_intro' => 'Không thể đổi chức danh :name sang chức danh này: người này còn',
        'open_work_lead_matters' => ':count vụ việc đang mở với vai luật sư phụ trách — dùng "Bàn giao" trên từng vụ việc',
        'open_work_deadlines' => ':count mốc hạn chưa xong — dùng "Đổi người phụ trách" trên tab Mốc thời hạn của từng vụ việc',
        'open_work_client_requests' => ':count yêu cầu khách chưa đóng — dùng "Giao việc" trên tab Yêu cầu từ khách của từng vụ việc',
        'open_work_outro' => 'Hãy xử lý xong rồi thử lại.',
        'demotion_from_admin_restricted' => 'Không thể đổi :name khỏi chức danh Quản trị viên: người này còn ở đội ngũ, hoặc còn giữ mốc hạn/yêu cầu khách chưa xong, trong :count vụ việc hạn chế mà người này không phụ trách — sau khi đổi, họ sẽ không mở được các vụ đó nữa. Hãy gỡ họ khỏi đội ngũ và chuyển việc cho người khác trước.',
        'last_admin_blocked' => 'Không thể thực hiện: đây là quản trị viên đang hoạt động cuối cùng của hệ thống. Hãy chỉ định thêm ít nhất một quản trị viên khác trước khi đổi chức danh, vô hiệu hoá hoặc xoá tài khoản này.',
        'is_active_hint' => 'Sẽ bị chặn nếu nhân sự này còn là luật sư phụ trách một vụ việc đang mở, còn đứng tên mốc hạn hoặc yêu cầu khách chưa xong, hoặc là quản trị viên đang hoạt động cuối cùng của hệ thống.',
        'position_hint' => 'Đổi chức danh sang Trợ lý hoặc Kế toán sẽ bị chặn nếu người này còn việc dở dang (cùng luật vô hiệu hoá). Đổi chức danh khỏi Quản trị viên sẽ bị chặn nếu đây là quản trị viên đang hoạt động cuối cùng của hệ thống.',
    ],
];
