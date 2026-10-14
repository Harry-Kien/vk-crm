<?php

/**
 * Làn nt — "Nhắc lịch thanh toán cho khách" (công tắc mặc định TẮT; chủ văn phòng 2026-10-10). Tệp
 * riêng của làn để không đụng tệp ngôn ngữ chung của các làn song song:
 *  - `settings`: trang `App\Filament\Admin\Pages\ClientPaymentSettingsPage` và Action
 *    `App\Actions\Settings\UpdateClientPaymentSettings`;
 *  - `portal`: phần "Lộ trình thanh toán" trong khối "Hợp đồng và thanh toán" của trang hồ sơ trên cổng
 *    (`App\Filament\Portal\Pages\MatterProgress::paymentPlan()`);
 *  - `mail`: thư `client.payment_received` (`App\Mail\Client\PaymentReceived`);
 *  - `failed_notification`: chuông cho luật sư khi thư đó hỏng hẳn (`App\Jobs\SendPaymentReceivedMail`);
 *  - (Task 2) `reminder_mail`: thư báo trước hạn `client.payment_reminder.due_soon` và thư nhắc đợt quá
 *    hạn `client.payment_reminder.overdue` (`App\Mail\Client\PaymentReminder`);
 *  - (Task 2) `remind_action`: nút "Gửi thư nhắc" trên dòng đợt quá hạn của tab "Hợp đồng và thanh toán"
 *    (`BillingRelationManager::remindClientAction()`, Action `App\Actions\Billing\RemindClientOfOverdueInstalment`),
 *    dòng sổ liên lạc nó ghi, và chuông khi thư nhắc hỏng hẳn (`App\Jobs\SendPaymentOverdueReminderMail`).
 */
return [
    'settings' => [
        'navigation_label' => 'Nhắc thanh toán cho khách',
        'page_title' => 'Nhắc thanh toán cho khách',

        'intro_off' => 'Khi TẮT (mặc định): hệ thống chạy như trước — chỉ nhắc nội bộ cho nhân sự; khách không nhận thư nào về tiền và trang hồ sơ trên cổng khách không có phần "Lộ trình thanh toán".',
        'intro_on' => 'Khi BẬT: trang hồ sơ trên cổng khách có thêm phần "Lộ trình thanh toán" (đã thanh toán, còn lại, thông tin chuyển khoản, nội dung chuyển khoản và mã QR cho từng đợt đã có ngày hạn mà còn phải trả), khách nhận thư xác nhận mỗi khi văn phòng ghi một khoản thu, và thư báo trước hạn mỗi sáng (08:15) cho đợt sắp đến hạn. Đợt quá hạn KHÔNG tự gửi thư đòi cho khách: luật sư hoặc quản lý bấm "Gửi thư nhắc" trên dòng đợt đó ở tab "Hợp đồng và thanh toán" của vụ, xem trước nội dung rồi mới gửi.',
        'audit_hint' => 'Mỗi lần bật hay tắt được ghi vào Nhật ký hệ thống (ai, lúc nào).',

        'sections' => [
            'switch' => 'Công tắc',
            'bank' => 'Thông tin chuyển khoản của văn phòng',
        ],

        'fields' => [
            'enabled' => [
                'label' => 'Nhắc lịch thanh toán cho khách',
                'hint' => 'Muốn bật thì cần điền đủ ngân hàng, số tài khoản và chủ tài khoản bên dưới.',
            ],
            'days_before_due' => [
                'label' => 'Báo trước hạn (số ngày)',
                'hint' => 'Mỗi sáng, khách nhận thư báo trước hạn cho đợt còn phải trả có ngày hạn trong bấy nhiêu ngày tới (từ 1 đến 30, mặc định 3) — mỗi đợt một thư, không gửi lại.',
            ],
            'bank_bin' => [
                'label' => 'Ngân hàng',
                'hint' => 'Chọn từ danh sách để mã QR chuyển đúng ngân hàng.',
            ],
            'account_number' => [
                'label' => 'Số tài khoản',
                'hint' => 'Hệ thống tự bỏ khoảng trắng, dấu chấm và gạch ngang.',
            ],
            'account_holder' => [
                'label' => 'Chủ tài khoản',
                'hint' => 'Viết đúng như ngân hàng in, thường là chữ hoa không dấu.',
            ],
            'bank_branch' => [
                'label' => 'Chi nhánh (không bắt buộc)',
            ],
        ],

        'preview' => [
            'heading' => 'Mã QR xem thử',
            'body' => 'Quét bằng ứng dụng ngân hàng của anh/chị để kiểm tra mã QR mở đúng tài khoản trên trước khi bật công tắc. Mã xem thử không có số tiền và không có nội dung chuyển khoản.',
        ],

        'submit' => 'Lưu',

        'validation' => [
            'bank_bin' => 'Hãy chọn một ngân hàng trong danh sách.',
            'account_number' => 'Số tài khoản gồm :min đến :max chữ số (hoặc chữ cái không dấu).',
            'required_when_enabled' => 'Cần điền ô này trước khi bật nhắc lịch thanh toán cho khách.',
        ],

        'notifications' => [
            'saved' => 'Đã lưu.',
            'unchanged' => 'Không có gì thay đổi.',
        ],
    ],

    'portal' => [
        'heading' => 'Lộ trình thanh toán',
        'contract_value' => 'Giá trị hợp đồng: :amount',
        'collected' => 'Đã thanh toán: :amount',
        'waived' => 'Văn phòng đã miễn: :amount',
        'outstanding' => 'Còn phải thanh toán: :amount',
        'settled' => 'Anh/chị đã thanh toán đủ. Xin cảm ơn.',

        'transfer_heading' => 'Thông tin chuyển khoản',
        'bank' => 'Ngân hàng: :name',
        'account_number' => 'Số tài khoản: :number',
        'account_holder' => 'Chủ tài khoản: :holder',
        'bank_branch' => 'Chi nhánh: :branch',

        'payables_heading' => 'Các đợt cần thanh toán',
        'payable_amount' => 'Số tiền cần chuyển: :amount',
        'transfer_note' => 'Nội dung chuyển khoản: :note',
        'qr_alt' => 'Mã QR chuyển khoản :amount cho :name',
        'qr_hint' => 'Mở ứng dụng ngân hàng, chọn quét mã QR: số tiền và nội dung được điền sẵn.',
        'note_hint' => 'Anh/chị vui lòng ghi đúng nội dung chuyển khoản để văn phòng nhận ra khoản tiền của hồ sơ này.',
        'no_payables' => 'Hiện chưa có đợt nào tới hạn. Văn phòng sẽ báo khi một đợt tới hạn.',
    ],

    'mail' => [
        'subject' => 'Văn phòng đã nhận thanh toán cho hồ sơ :code',
        'greeting' => 'Kính gửi anh/chị :name,',
        'line' => 'Văn phòng xác nhận đã nhận khoản thanh toán của anh/chị cho hồ sơ :code:',
        'amount' => 'Số tiền: :amount',
        'paid_on' => 'Ngày nhận: :date',
        'outstanding' => 'Số tiền còn phải thanh toán theo hợp đồng: :amount',
        'settled' => 'Anh/chị đã thanh toán đủ theo hợp đồng.',
        'restricted_line' => 'Văn phòng xác nhận đã ghi nhận một khoản thanh toán cho hồ sơ :code. Anh/chị vui lòng đăng nhập cổng khách hàng để xem chi tiết.',
        'open' => 'Mở cổng khách hàng',
        'help' => 'Nếu thông tin trên chưa đúng, xin gọi giúp văn phòng theo số :phone.',
        'salutation' => 'Trân trọng, :office',
    ],

    'failed_notification' => [
        'title' => 'Thư xác nhận đã nhận tiền chưa tới được khách',
        'body' => 'Hệ thống đã thử nhiều lần mà không gửi được thư xác nhận khoản thu của hồ sơ :code cho khách. Hãy báo khách bằng kênh khác nếu cần.',
    ],

    // =============================================================================================
    // Task 2 — thư báo trước hạn, thư nhắc đợt quá hạn, nút "Gửi thư nhắc".
    // =============================================================================================

    'reminder_mail' => [
        'due_soon' => [
            'subject' => 'Đợt thanh toán sắp đến hạn — hồ sơ :code',
            'line' => 'Văn phòng xin thông báo đợt thanh toán sau đây của hồ sơ :code sắp đến hạn:',
            'restricted_line' => 'Văn phòng có thông báo về lịch thanh toán của hồ sơ :code. Anh/chị vui lòng đăng nhập cổng khách hàng để xem chi tiết.',
        ],
        'overdue' => [
            'subject' => 'Nhắc thanh toán đợt đã quá hạn — hồ sơ :code',
            'line' => 'Văn phòng xin nhắc anh/chị đợt thanh toán sau đây của hồ sơ :code đã quá hạn:',
            'restricted_line' => 'Văn phòng có lời nhắc về lịch thanh toán của hồ sơ :code. Anh/chị vui lòng đăng nhập cổng khách hàng để xem chi tiết.',
        ],
        'greeting' => 'Kính gửi anh/chị :name,',
        'instalment' => 'Đợt: :name',
        'amount' => 'Số tiền cần thanh toán: :amount',
        'due' => 'Hạn thanh toán: :date',
        'transfer_heading' => 'Thông tin chuyển khoản',
        'bank' => 'Ngân hàng: :name',
        'account_number' => 'Số tài khoản: :number',
        'account_holder' => 'Chủ tài khoản: :holder',
        'bank_branch' => 'Chi nhánh: :branch',
        'transfer_note' => 'Nội dung chuyển khoản: :note',
        'note_hint' => 'Anh/chị vui lòng ghi đúng nội dung chuyển khoản để văn phòng nhận ra khoản tiền của hồ sơ này.',
        'qr_alt' => 'Mã QR chuyển khoản :amount',
        'qr_hint' => 'Mở ứng dụng ngân hàng, chọn quét mã QR: số tiền và nội dung được điền sẵn.',
        'already_paid' => 'Nếu anh/chị đã thanh toán khoản này, xin bỏ qua thư này. Xin cảm ơn.',
        'open' => 'Mở cổng khách hàng',
        'help' => 'Nếu thông tin trên chưa đúng, xin gọi giúp văn phòng theo số :phone.',
        'salutation' => 'Trân trọng, :office',
    ],

    'remind_action' => [
        'label' => 'Gửi thư nhắc',
        'heading' => 'Gửi thư nhắc đợt quá hạn cho khách',
        'submit' => 'Gửi thư',
        'success' => 'Đã xếp hàng gửi thư nhắc cho khách. Kết quả từng thư xem ở tab "Thư đã gửi".',
        'intro' => 'Thư dưới đây sẽ được gửi tới các tài khoản cổng khách hàng đang hoạt động của khách. Mỗi đợt chỉ gửi được một lần mỗi ngày.',
        'instalment' => 'Đợt: :name — còn phải trả :amount — hạn :date',
        'recipients' => 'Người nhận',
        'subject' => 'Tiêu đề',
        'body' => 'Nội dung thư',
        'with_qr' => 'Bản thư HTML kèm mã QR chuyển khoản của đúng số tiền và nội dung trên.',
        'already_sent_today' => 'Thư nhắc đợt này đã được gửi hôm nay lúc :time (:name bấm gửi). Mỗi đợt chỉ gửi một lần mỗi ngày — hãy thử lại vào ngày mai.',
        'no_recipients' => 'Không có tài khoản cổng khách hàng nào nhận được thư này: khách chưa có tài khoản đã kích hoạt, hoặc vụ việc không còn trên cổng khách. Hãy liên hệ khách bằng kênh khác.',
        'not_overdue' => 'Đợt này không còn quá hạn (đã thu đủ, đã miễn, đã dời hạn hoặc hợp đồng không còn hiệu lực), nên không gửi thư nhắc.',
        'switch_off' => 'Nhắc lịch thanh toán cho khách đang tắt, nên không gửi thư nào cho khách.',
        'communication_summary' => 'Gửi thư nhắc thanh toán đợt quá hạn qua hệ thống: :instalment, còn phải trả :amount, hạn :date. Người nhận: :recipients. Kết quả từng thư xem ở tab "Thư đã gửi".',
        'failed_notification' => [
            'title' => 'Thư nhắc thanh toán chưa tới được khách',
            'body' => 'Hệ thống đã thử nhiều lần mà không gửi được thư nhắc đợt quá hạn của hồ sơ :code cho khách. Hãy báo khách bằng kênh khác nếu cần.',
        ],
    ],
];
