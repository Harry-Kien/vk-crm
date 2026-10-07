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
            // Vòng sửa cuối I3: mỗi chủ đề đẩy của nhân sự có tên ở đây (`PushDevicesPageTest` ghim) — kế
            // toán chỉ bao giờ nhận "khoản thu quá hạn", nên câu nói rõ là tuỳ việc người đó phụ trách.
            'admin' => 'Bật thông báo trên điện thoại để biết ngay khi có việc cần anh/chị chú ý, tuỳ việc '
                .'anh/chị phụ trách: mốc thời hạn sắp đến, khách gửi giấy tờ hay câu hỏi, khoản thu quá hạn, '
                .'gói bàn giao hồ sơ đã sẵn sàng. Thư điện tử vẫn gửi như trước và không tắt được.',
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

    /*
     * M12 Task 7 (R11) — câu của thông báo đẩy, chỉ {@see \App\Enums\PushTopic} đọc (test cấu trúc).
     * Một câu CHUNG cho mỗi chủ đề: không mã hồ sơ, không tên, không tiêu đề, không nội dung — điện
     * thoại nằm trên bàn và người nhà đọc được màn hình khoá. Tiêu đề thông báo là tên văn phòng
     * (`vkcrm.brand.short_name`), không ở đây. Mức khẩn của mốc hạn được phép: nó không chỉ ra khách nào.
     */
    'alerts' => [
        'client' => [
            'stage_update' => 'Hồ sơ của anh/chị có cập nhật mới. Chạm để xem.',
            'document_published' => 'Văn phòng vừa gửi tài liệu mới trong hồ sơ của anh/chị. Chạm để xem.',
            'document_rejected' => 'Có giấy tờ trong hồ sơ của anh/chị cần nộp lại. Chạm để xem.',
            'request_answered' => 'Văn phòng đã trả lời câu hỏi của anh/chị. Chạm để xem.',
        ],
        'staff' => [
            // Theo bậc của `CheckDeadlines::tierFor()`: d14/d7/d3 → upcoming, d1 → imminent, quá hạn → overdue.
            'deadline' => [
                'upcoming' => 'Có mốc thời hạn sắp đến cần chuẩn bị. Chạm để xem.',
                'imminent' => 'Có mốc thời hạn đến hạn hôm nay hoặc ngày mai. Chạm để xem.',
                'overdue' => 'Có mốc thời hạn đã quá hạn, cần xử lý ngay. Chạm để xem.',
            ],
            // Cũng là câu của lần khách hỏi tiếp vào một luồng cũ (`REQ-2`, Task 9) — cùng chủ đề.
            'new_client_request' => 'Khách vừa gửi yêu cầu hoặc câu hỏi mới. Chạm để xem.',
            'new_client_document' => 'Khách vừa nộp giấy tờ mới cần xem. Chạm để xem.',
            // Task 9 (phán quyết (e)): không số tiền, không tên khách, không mã hợp đồng.
            'instalment_overdue' => 'Có khoản thu đã quá hạn cần theo dõi. Chạm để xem.',
            // Vòng sửa cuối I5 (M7 Task 4): không mã hồ sơ — chỉ thư nội bộ mới mang mã.
            'handover_ready' => 'Gói bàn giao hồ sơ đã sẵn sàng để xem và công bố. Chạm để xem.',
        ],
        'test' => 'Thông báo thử: máy này đã nhận được thông báo của văn phòng.',
    ],

    /*
     * M12 Task 7 — nút "Gửi thử" trên trang "Thông báo trên điện thoại"
     * ({@see \App\Actions\Push\SendTestPush}). Hàng đợi `push` được rút mỗi phút nên câu nói "một, hai
     * phút", không nói "ngay".
     */
    'test' => [
        'hint' => 'Gửi một thông báo thử tới mọi máy trong danh sách để kiểm tra máy có nhận được không.',
        'button' => 'Gửi thông báo thử',
        'sent' => 'Đã gửi thử tới :count máy. Thông báo thường tới trong một, hai phút.',
        'none' => 'Chưa có máy nào nhận thông báo để gửi thử. Hãy bật trên máy này trước.',
    ],

    /*
     * M12 Task 7 (R11) — câu dự phòng của service worker (`resources/views/pwa/sw-js.blade.php`) khi một
     * lần đẩy tới mà không đọc được nội dung: trình duyệt bắt buộc hiện MỘT thông báo cho mỗi lần đẩy,
     * và câu mặc định của Chrome ("trang này đã cập nhật ở nền") là tiếng Anh.
     */
    'service_worker' => [
        'fallback' => 'Có thông báo mới. Chạm để xem.',
    ],

    // M12 Task 5 (R8) — {@see \App\Actions\Push\RegisterPushDevice}. Không nhắc lại giá trị đã gửi.
    'validation' => [
        'endpoint' => 'Địa chỉ nhận thông báo mà trình duyệt gửi lên không hợp lệ.',
        'keys' => 'Khoá thông báo mà trình duyệt gửi lên không hợp lệ.',
    ],
];
