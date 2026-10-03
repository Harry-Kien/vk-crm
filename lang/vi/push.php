<?php

use App\Console\Commands\PushResetCommand;

/**
 * Thông báo đẩy trên điện thoại (M12). Nội dung đẩy (R11, Task 7) không bao giờ mang mã hồ sơ, tên
 * khách, tiêu đề vụ việc hay tài liệu — màn hình khoá không phải màn hình của văn phòng.
 */
return [
    // M12 Task 4 (R7) — {@see PushResetCommand}.
    'reset' => [
        'confirm' => 'Xoá :count đăng ký thông báo đẩy trên MỌI điện thoại (nhân sự và khách)? Chỉ '
            .'làm việc này ngay sau khi đổi khoá VAPID — mọi người sẽ phải bật lại thông báo trên '
            .'từng máy.',
        'cancelled' => 'Đã huỷ — không xoá đăng ký nào. Chạy lại với --force để bỏ bước hỏi.',
        'done' => 'Đã xoá :count đăng ký thông báo đẩy. Mọi người phải bật lại thông báo trên '
            .'từng máy của mình.',
    ],

    /*
     * M12 Task 5 (R8, R14) — trang "Thông báo trên điện thoại" của hai panel
     * (`resources/views/pwa/push-devices.blade.php`). Các câu `state.*` được in sẵn vào trang, ẩn;
     * `public/pwa/register.js` chỉ bật/tắt thuộc tính `hidden` — tệp JS không mang chữ nào.
     * `:app` là `short_name` của app đang mở (`pwa.{panel}.short_name`).
     */
    'devices' => [
        'title' => 'Thông báo trên điện thoại',
        'menu' => 'Thông báo trên điện thoại',
        'lead' => [
            'portal' => 'Bật thông báo trên điện thoại để biết ngay khi hồ sơ của anh/chị có cập nhật. '
                .'Thư điện tử vẫn gửi như trước và không tắt được.',
            'admin' => 'Bật thông báo trên điện thoại để biết ngay khi có mốc thời hạn cần chú ý, hay khi '
                .'khách gửi giấy tờ, câu hỏi. Thư điện tử vẫn gửi như trước và không tắt được.',
        ],
        'lock_screen' => 'Thông báo chỉ hiện một câu chung — không tên, không mã hồ sơ, không nội dung. '
            .'Chạm vào để xem trong ứng dụng.',
        'this_device' => 'Máy này',
        'state' => [
            'unsupported' => 'Trình duyệt này chưa nhận được thông báo. Trên điện thoại, hãy mở bằng Chrome '
                .'(Android) hoặc cài ứng dụng vào màn hình chính (iPhone).',
            'ios_install' => 'Chạm nút Chia sẻ → Thêm vào Màn hình chính, rồi mở :app từ màn hình chính để '
                .'bật thông báo.',
            'ios_install_note' => 'Lần đầu mở từ màn hình chính sẽ phải đăng nhập lại một lần.',
            'denied' => 'Máy này đang chặn thông báo của ứng dụng. Mở phần cài đặt thông báo của máy, cho phép '
                .':app, rồi mở lại trang này.',
            'ready' => 'Máy này chưa nhận thông báo.',
            'enabled' => 'Máy này đang nhận thông báo.',
            'failed' => 'Chưa bật được thông báo trên máy này. Kiểm tra mạng rồi thử lại.',
        ],
        'enable' => 'Bật trên máy này',
        'retry' => 'Thử lại',
        'logout_note' => 'Đăng xuất trên máy này sẽ tắt thông báo trên máy này.',
        'off' => 'Hệ thống chưa bật thông báo trên điện thoại. Thư điện tử vẫn gửi như thường.',
        'list_heading' => 'Các máy đang nhận thông báo',
        'empty' => 'Chưa có máy nào nhận thông báo.',
        'current' => 'Máy đang dùng',
        'enabled_at' => 'Bật lúc :date',
        'last_seen' => 'Lần cuối mở ứng dụng: :date',
        'remove' => 'Gỡ',
        'remove_confirm' => 'Gỡ máy này? Máy đó sẽ không nhận thông báo nữa cho tới khi bật lại.',
        'remove_all' => 'Gỡ mọi thiết bị',
        'remove_all_confirm' => 'Gỡ tất cả các máy? Không máy nào nhận thông báo nữa cho tới khi bật lại. '
            .'Thư điện tử vẫn gửi như thường.',
        'removed' => 'Đã gỡ thiết bị.',
        'removed_all' => 'Đã gỡ :count thiết bị.',
        // {@see \App\Support\Push\DeviceLabel} — phần nhãn không phải danh từ riêng.
        'unknown_device' => 'Thiết bị không rõ',
        'installed_app' => 'Ứng dụng đã cài',
    ],

    /*
     * M12 Task 5 (R8) — dải mời trên mọi trang đã đăng nhập (`resources/views/pwa/push-invite.blade.php`),
     * hiện khi trình duyệt này đã có đăng ký push nhưng đăng ký đó không thuộc người đang đăng nhập
     * (máy dùng chung), hoặc khoá của máy chủ đã đổi. Một chạm, không hỏi quyền hệ điều hành lần nữa.
     */
    'invite' => [
        'text' => 'Bật thông báo trên máy này?',
        'enable' => 'Bật',
        'dismiss' => 'Để sau',
    ],

    // M12 Task 5 (R8) — {@see \App\Actions\Push\RegisterPushDevice}. Không nhắc lại giá trị đã gửi.
    'validation' => [
        'endpoint' => 'Địa chỉ nhận thông báo mà trình duyệt gửi lên không hợp lệ.',
        'keys' => 'Khoá thông báo mà trình duyệt gửi lên không hợp lệ.',
    ],
];
