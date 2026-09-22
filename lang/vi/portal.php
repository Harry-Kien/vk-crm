<?php

/**
 * Chuỗi cổng khách hàng (SPEC §8). Người đọc những câu này là khách hàng của văn phòng, đang lo
 * về vụ việc của mình, thường đọc trên điện thoại, và không phải dân công nghệ — SPEC §8 cấm
 * thuật ngữ kỹ thuật lẫn từ viết tắt, và §8.4 nêu thẳng "Upload failed" là kiểu câu không được
 * nói. Nên mỗi câu ở đây phải trả lời được "giờ tôi phải làm gì".
 *
 * Xưng hô: "anh/chị" cho người đọc, "chúng tôi" cho văn phòng — giống cách trợ lý nói qua điện
 * thoại, không phải giọng của một phần mềm.
 */
return [

    'login' => [

        /*
         * Ba câu khác nhau cho ba tình huống khác nhau, vì Filament mặc định gộp "gõ nhầm mã" và
         * "mã đã cũ" vào CÙNG một câu (`login_form.code.messages.invalid`) — người dùng đọc xong
         * không biết nên gõ lại hay nên xin mã mới.
         */
        'code' => [
            'label' => 'Nhập mã 6 số chúng tôi vừa gửi tới email của anh/chị',
            'validation_attribute' => 'mã',

            /*
             * Mã gắn với PHIÊN TRÌNH DUYỆT đã nhập mật khẩu (mã được băm rồi cất trong phiên —
             * xem PortalEmailAuthentication). Xin mã trên điện thoại rồi gõ trên máy tính sẽ
             * không vào được, và đó là hành vi đúng về mặt an toàn. Nhưng nó chỉ đúng nếu màn
             * hình nói ra, nên câu này nằm ngay dưới ô nhập.
             */
            'hint' => 'Anh/chị nhập mã ngay trên màn hình vừa đăng nhập này. Mã xin trên điện thoại thì nhập trên điện thoại, không dùng được ở máy khác.',

            'resend' => 'Gửi lại mã',
            'resent' => 'Chúng tôi vừa gửi một mã mới tới email của anh/chị.',
            'resend_throttled' => 'Anh/chị vừa xin mã xong. Xin đợi khoảng một phút rồi bấm "Gửi lại mã" lần nữa.',

            'invalid' => 'Mã chưa đúng. Mã gồm 6 chữ số; anh/chị xem lại thư mới nhất chúng tôi gửi rồi gõ lại giúp.',
            'expired' => 'Mã này không còn dùng được nữa, vì đã quá hạn hoặc đã dùng rồi. Anh/chị bấm "Gửi lại mã" để nhận mã mới.',
        ],

        /*
         * SPEC §10.3: khoá 15 phút. Người đang không vào được tài khoản cần một con đường KHÔNG
         * đi qua tài khoản, nên câu này kèm số điện thoại văn phòng. Cố ý không nói vì sao bị
         * khoá theo email hay theo địa chỉ mạng — người bị khoá không cần biết cơ chế, và người
         * đang dò mật khẩu thì càng không.
         */
        'throttled' => 'Anh/chị đã thử quá nhiều lần. Xin đợi :minutes phút rồi thử lại. Nếu cần vào ngay, anh/chị gọi giúp văn phòng theo số :phone.',
    ],

    /*
     * SPEC §10.9. Cố ý KHÔNG nói vì sao tài khoản không vào được: lý do có thể là hồ sơ đã kết
     * thúc, là một việc nội bộ, hoặc là một chuyện cần nói riêng — đó là một cuộc điện thoại của
     * văn phòng, không phải một dòng chữ trên màn hình đăng nhập.
     */
    'inactive' => 'Tài khoản này hiện chưa đăng nhập được. Anh/chị gọi giúp văn phòng theo số :phone để chúng tôi hỗ trợ ngay.',

    'change_password' => [
        'title' => 'Đặt mật khẩu mới',
        'navigation_label' => 'Đổi mật khẩu',
        'heading' => 'Anh/chị đặt mật khẩu mới trước khi vào xem hồ sơ',
        'description' => 'Đây là lần đầu anh/chị đăng nhập, nên hệ thống cần một mật khẩu do chính anh/chị đặt. Mật khẩu cũ chúng tôi gửi sẽ không dùng được nữa.',
        'fields' => [
            'password' => 'Mật khẩu mới',
            'password_confirmation' => 'Nhập lại mật khẩu mới',
        ],
        'submit' => 'Lưu mật khẩu mới',
        'saved' => 'Xong rồi. Từ giờ anh/chị đăng nhập bằng mật khẩu mới này.',
        'reuse' => 'Mật khẩu mới phải khác mật khẩu cũ chúng tôi đã gửi cho anh/chị.',
    ],

    'email' => [
        'otp' => [
            'subject' => 'Mã đăng nhập cổng hồ sơ Luật Vũ Khang',
            'greeting' => 'Kính gửi anh/chị :name,',
            'line' => 'Mã đăng nhập của anh/chị là:',
            'expiry' => 'Mã dùng được trong :minutes phút, và chỉ dùng được một lần.',
            'ignore' => 'Nếu không phải anh/chị vừa đăng nhập, xin bỏ qua thư này và gọi giúp văn phòng theo số :phone.',
            'salutation' => 'Trân trọng, :office',
        ],
    ],
];
