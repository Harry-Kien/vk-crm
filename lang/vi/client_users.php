<?php

/**
 * Nhãn tiếng Việt cho resource ClientUser (SPEC §7.4) — tài khoản đăng nhập portal của khách,
 * gated bằng clientUser.manage (ClientUserPolicy).
 */
return [
    'label' => 'Tài khoản portal',
    'plural_label' => 'Tài khoản portal',
    'fields' => [
        'client' => 'Khách hàng',
        'name' => 'Họ tên',
        'email' => 'Email đăng nhập',
        'phone' => 'Điện thoại',
        // Task 3: ô mật khẩu đã gỡ khỏi cả hai form (tạo/sửa) — nhãn này không còn nơi nào dùng.
        'is_active' => 'Đang hoạt động',
        'must_change_password' => 'Bắt buộc đổi mật khẩu lần đầu',
        'activated_at' => 'Ngày kích hoạt',
        'last_login_at' => 'Đăng nhập gần nhất',
    ],
    // Task 7 (R12, phát hiện `portal/portal-4`): nút "Mở khoá đăng nhập" trên trang sửa tài
    // khoản cổng — xem App\Actions\Portal\UnlockPortalLogin.
    'actions' => [
        'unlock_login' => 'Mở khoá đăng nhập',
        'unlock_login_success' => 'Đã xoá khoá đếm của tài khoản này. Khách đăng nhập lại được ngay.',
        // Task 3: ô mật khẩu đã gỡ khỏi form (App\Actions\Client\IssuePortalAccess sinh mật khẩu
        // tạm và gửi qua thư `client.activation`, không còn ai gõ tay mật khẩu nữa). Nút này là
        // đường DUY NHẤT nhân sự cấp lại quyền truy cập trên trang sửa.
        'reissue_access' => 'Cấp lại mật khẩu',
        'reissue_access_heading' => 'Cấp một mật khẩu tạm mới và gửi qua email cho khách',
        // Fix round 1 (finding Important 1): "đã gửi" là một lời hứa QUÁ SỚM — thư đi qua hàng
        // đợi (R2), nằm chờ tới lượt `queue:work` (routes/console.php), không rời máy chủ ngay
        // lúc bấm nút. "Sẽ được gửi trong ít phút" nói đúng những gì vừa THẬT SỰ xảy ra: một job
        // vừa được xếp hàng, không phải một email vừa rời khỏi máy chủ.
        'reissue_access_success' => 'Email chứa mật khẩu tạm mới sẽ được gửi cho khách trong ít phút.',
        // Fix round 1 (finding Important 1): lớp phòng thủ THỨ HAI, không phải đường đi bình
        // thường — nút đã bị `disabled()` (xem docblock `EditClientUser::reissueAccessAction()`)
        // nên Filament tự chặn cú bấm trước khi `action()` chạy, và khoá đó là lớp chặn CHÍNH.
        // Khoá này chỉ hiện nếu một bản sửa sau này gỡ `->disabled()` mà quên gỡ luôn nhánh
        // `action()` đọc `$result->issued` — khi đó toast nói THẬT rằng không có gì được gửi,
        // thay vì "Đã gửi" giả.
        'reissue_access_not_eligible' => 'Chưa gửi được: tài khoản này đang tắt hoạt động, hoặc khách hàng sở hữu đã bị xoá mềm. Bật lại tài khoản (hoặc khôi phục khách hàng) rồi bấm lại.',
        // Hiện dưới dạng tooltip khi nút bị `disabled()` — cùng lý do với toast ở trên, nói trước
        // khi bấm thay vì để nhân sự bấm rồi mới biết.
        'reissue_access_disabled_hint' => 'Tài khoản đang tắt hoạt động, hoặc khách hàng sở hữu đã bị xoá mềm — bật lại tài khoản (hoặc khôi phục khách hàng) trước khi cấp lại mật khẩu.',
        // Fix round 1: hai sửa so với vòng đầu.
        // (1) M1 — "địa chỉ mạng khách vừa dùng" giả định người gõ sai là chính khách; sau khi
        //     UnlockPortalLogin xét NAT-an toàn (I2), địa chỉ vẫn còn khoá đúng là địa chỉ CÓ
        //     người khác (có thể không phải khách) cũng gõ sai — chữ "liên quan" không giả định
        //     ai đã gõ.
        // (2) Thêm :minutes — trước đây chỉ nói "hết giờ khoá" chung chung; giờ có con số thật từ
        //     UnlockPortalLoginResult::$minutesRemaining, cùng thành ngữ portal.login.throttled.
        'unlock_login_success_ip_still_locked' => 'Đã xoá khoá đếm của tài khoản này. Nhưng địa chỉ mạng liên quan tới lần khoá này vẫn còn bị khoá tạm — xin đợi thêm :minutes phút, hoặc thử từ một mạng khác (ví dụ 4G) để vào ngay.',
    ],
    // Việc sau gộp M6 (làn fu, mục 6 — N2 của rà soát cuối làn m6): hộp xác nhận trước mọi lần lưu
    // SẼ gửi mật khẩu tạm (tạo tài khoản; đổi email hay bật lại tài khoản chưa từng kích hoạt ở
    // trang sửa) — thư đó mang mật khẩu, và mọi mã đăng nhập sau đó cũng về đúng địa chỉ ấy.
    // :email được trang in đậm (App\Filament\Admin\Resources\ClientUsers\Pages\Concerns\
    // ConfirmsPortalAccessIssue); câu chữ không chứa HTML nào khác. `*_inactive`: tài khoản sẽ ở
    // trạng thái TẮT sau lần lưu, nên lúc đó chưa thư nào đi.
    'issue_confirmation' => [
        'create_heading' => 'Tạo tài khoản và gửi mật khẩu tạm tới địa chỉ này?',
        'create_heading_inactive' => 'Tạo tài khoản đang tắt với địa chỉ này?',
        'create_description' => 'Thư kích hoạt kèm mật khẩu tạm sẽ gửi tới :email. Ai đọc được hộp thư này sẽ vào được cổng thông tin của khách (mã đăng nhập cũng gửi về đó), nên hãy kiểm tra lại từng ký tự của địa chỉ.',
        'create_description_inactive' => 'Tài khoản đang tắt nên lúc này chưa có thư nào được gửi. Khi tài khoản được bật, thư kích hoạt kèm mật khẩu tạm sẽ gửi tới :email — hãy kiểm tra lại từng ký tự của địa chỉ.',
        'create_submit' => 'Tạo tài khoản',
        'edit_heading' => 'Gửi mật khẩu tạm mới tới địa chỉ này?',
        'edit_heading_inactive' => 'Lưu địa chỉ mới cho tài khoản đang tắt?',
        'edit_description' => 'Lưu thay đổi này sẽ gửi thư kích hoạt kèm mật khẩu tạm mới tới :email, và mật khẩu cũ sẽ không dùng được nữa. Ai đọc được hộp thư này sẽ vào được cổng thông tin của khách (mã đăng nhập cũng gửi về đó), nên hãy kiểm tra lại từng ký tự của địa chỉ.',
        'edit_description_inactive' => 'Tài khoản đang tắt nên lúc lưu chưa có thư nào được gửi. Khi tài khoản được bật lại, thư kích hoạt kèm mật khẩu tạm mới sẽ gửi tới :email, và mật khẩu cũ sẽ không dùng được nữa — hãy kiểm tra lại từng ký tự của địa chỉ.',
        'edit_submit' => 'Lưu thay đổi',
    ],
    // Thông báo SAU lần lưu đã gọi App\Actions\Client\IssuePortalAccess — đọc kết quả thật
    // (IssuePortalAccessResult), không đoán. "Sẽ được gửi": thư đi qua hàng đợi (R2), cùng lý do
    // với `actions.reissue_access_success`.
    'issue_notice' => [
        'queued' => 'Thư kích hoạt kèm mật khẩu tạm sẽ được gửi tới :email trong ít phút.',
        'not_sent_inactive' => 'Chưa gửi thư kích hoạt: tài khoản đang tắt. Khi tài khoản được bật, thư sẽ gửi tới :email.',
        'not_sent_client_deleted' => 'Chưa gửi thư kích hoạt tới :email: khách hàng sở hữu tài khoản này đã bị xoá. Khôi phục khách hàng rồi bấm "Cấp lại mật khẩu".',
    ],
    // App\Jobs\SendPortalActivationMail::failed() — job cấp quyền truy cập hỏng hẳn sau hết lượt
    // thử; không ai có mật khẩu để đăng nhập, và không ai biết trừ khi báo ở đây.
    'activation_failed_notification' => [
        'title' => 'Chưa cấp được quyền truy cập cổng khách hàng',
        'body' => 'Thư kích hoạt gửi tới :email đã thử nhiều lần nhưng không tới được. Hãy mở lại tài khoản này và bấm "Cấp lại mật khẩu" một lần nữa.',
    ],
];
