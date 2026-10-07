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
            'modal_description' => 'Xoá xác thực ứng dụng hiện tại của :name, đăng xuất mọi phiên đang mở của họ và gỡ mọi máy đang nhận thông báo đẩy của họ. Lần đăng nhập kế tiếp, họ sẽ phải cài lại 2FA từ đầu. Dùng khi họ mất điện thoại và không còn mã khôi phục.',
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
            // Final review I2: :name có thể bị khoá CHỈ vì lần hỏng của đồng nghiệp cùng NAT văn
            // phòng — lần thử bị chặn không ghi dòng nào, nên hệ thống không biết họ đang ở địa chỉ
            // nào. Còn một địa chỉ như vậy bị khoá thì "đăng nhập lại được ngay" là hứa suông. Xem
            // App\Actions\Concerns\ClearsNatSafeIpLocks.
            'success_other_address_locked' => 'Đã xoá khoá đếm của :name. Nhưng đang có địa chỉ mạng bị khoá tạm vì người khác gõ sai nhiều lần (ví dụ wifi văn phòng dùng chung) — nếu :name đang dùng mạng đó thì vẫn bị chặn thêm tối đa :minutes phút, hoặc thử từ một mạng khác (ví dụ 4G) để vào ngay.',
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
        // M7 Task 2: liên kết tới màn hình "Bàn giao hàng loạt" đi kèm lời chặn, khi người bị chặn
        // còn dẫn ít nhất một vụ việc (mảnh 'open_work_lead_matters' ở trên) — App\Filament\Admin\
        // Pages\BulkReassign::offboardingLinkAction(), gọi từ EditUser/DeleteStaffMember.
        'bulk_reassign_notice' => 'Có thể dùng màn hình "Bàn giao hàng loạt" để chuyển hết các vụ việc :name đang dẫn cho một luật sư khác cùng lúc.',
    ],

    // M8 Task 7: lệnh `vkcrm:create-admin` (App\Console\Commands\CreateAdminCommand,
    // App\Actions\User\CreateAdminFromConsole) — người đọc là người vận hành máy chủ, qua SSH.
    'create_admin' => [
        'non_interactive' => 'Lệnh này chỉ chạy tương tác: nó hỏi họ tên, email và mật khẩu (nhập ẩn). Không có cách truyền mật khẩu qua tham số dòng lệnh — tham số nằm lại trong lịch sử shell và hiện trong danh sách tiến trình của máy chủ. Chạy lại lệnh không kèm --no-interaction, trong một phiên SSH.',
        'admins_exist' => 'Hệ thống đã có :count quản trị viên (tính cả người đang bị vô hiệu hoá). Tạo thêm nhân sự trong /admin, màn hình Nhân sự. Chỉ khi không còn quản trị viên nào đăng nhập được, chạy lại lệnh với --additional.',
        'additional_notice' => 'Hệ thống đã có :count quản trị viên — tạo thêm một người vì có --additional. Việc này được ghi vào Nhật ký hệ thống.',
        'ask_name' => 'Họ tên quản trị viên',
        'ask_email' => 'Email đăng nhập',
        'ask_password' => 'Mật khẩu (nhập ẩn — không hiện ký tự nào khi gõ)',
        'ask_password_confirmation' => 'Nhập lại mật khẩu',
        'attributes' => [
            'name' => 'Họ tên',
            'email' => 'Email',
            'password' => 'Mật khẩu',
        ],
        'email_taken' => 'Email :email đã thuộc về một nhân sự trong hệ thống. Dùng một email khác.',
        'email_taken_trashed' => 'Email :email thuộc về một nhân sự đã bị xoá (tài khoản đó vẫn được giữ lại để tra lịch sử, nên email không dùng lại được). Dùng một email khác.',
        'password_mismatch' => 'Hai lần nhập mật khẩu không khớp.',
        'nothing_created' => 'Chưa tạo tài khoản nào — sửa lại rồi chạy lại lệnh.',
        'created' => 'Đã tạo quản trị viên :name (:email). Đăng nhập tại :url — ở lần đăng nhập đầu tiên hệ thống buộc cài xác thực hai lớp (2FA) bằng một app xác thực trên điện thoại, rồi hiện mã khôi phục ĐÚNG MỘT LẦN: cất các mã đó ngay, ở nơi khác điện thoại.',
    ],
];
