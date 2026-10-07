<?php

/**
 * Nhật ký thư đi ra (SPEC §4.15, §7.4). M6.5 Task 13 (notify-8, spec-gap-07): panel admin trước
 * đây không có màn hình nào đọc `outbound_messages`, dù bảng vẫn ghi đủ, kể cả thư lỗi.
 */
return [
    'label' => 'Thư đã gửi',
    'plural_label' => 'Thư đã gửi',

    'fields' => [
        'created_at' => 'Thời điểm mở',
        'recipient' => 'Người nhận',
        'channel' => 'Kênh',
        'template' => 'Mẫu thư',
        'status' => 'Trạng thái',
        'related' => 'Bản ghi liên quan',
        'sent_at' => 'Đã gửi lúc',
        'error' => 'Lý do lỗi',
        'subject' => 'Tiêu đề thư',
        // "—" khi không có: dòng chưa gửi xong (`sent_at` null) hoặc không lỗi (`error` null).
        'none' => '—',
        'no_related_record' => 'Không gắn vụ việc nào',
    ],

    'filters' => [
        'status' => 'Trạng thái',
        'template' => 'Mẫu thư',
        'matter' => 'Vụ việc',
        'created_from' => 'Từ ngày',
        'created_until' => 'Đến ngày',
        'created_between' => 'Khoảng ngày mở',
    ],

    // Mẫu thư đã có tên khai báo (SPEC §9); một mẫu chưa có ở đây thì cột hiện nguyên khoá thô
    // (OutboundMessage::TEMPLATE_UNDECLARED hoặc một mẫu M6/M7 sau này chưa được thêm vào đây).
    'templates' => [
        'client.otp' => 'Mã OTP đăng nhập cổng',
        'client.stage_update' => 'Cập nhật tiến độ cho khách',
        'staff.deadline_reminder' => 'Nhắc mốc thời hạn cho nhân sự',
        // M10 Task 5 (R5): bản ghi liên quan là một lần tiếp nhận (`intake_request`), không thuộc vụ
        // việc nào — dòng này chỉ admin thấy trên màn hình nhật ký thư (`OutboundMessage::scopeVisibleTo`).
        'staff.intake_unanswered' => 'Nhắc liên hệ chưa ai gọi lại',
        // M6 Task 10: các mẫu M6 đã có thật (Task 3, 4, 7, 8) — trước đây cột hiện khoá thô.
        'client.activation' => 'Kích hoạt tài khoản cổng cho khách',
        'client.document_published' => 'Báo khách có tài liệu mới',
        'client.document_rejected' => 'Báo khách giấy tờ chưa đạt',
        'client.request_answered' => 'Báo khách văn phòng đã trả lời',
        'client.missing_documents' => 'Nhắc khách nộp giấy tờ còn thiếu',
        'staff.new_client_request' => 'Báo nhân sự khách gửi yêu cầu mới',
        'staff.new_client_document' => 'Báo nhân sự khách nộp tệp mới',
        'staff.stale_matter' => 'Nhắc nhân sự hồ sơ quá hạn cập nhật',
        // Việc sau gộp M6 (làn fu): hai họ thư của main — M9 và M8a (hậu tố = loại sự cố, mỗi
        // loại một nhãn vì nhật ký ghi tên mẫu đầy đủ). MailTemplateRegistryTest đòi mọi mẫu của
        // app/Mail có nhãn ở đây.
        'staff.instalment_overdue' => 'Nhắc nhân sự đợt thanh toán quá hạn',
        'staff.backup_alert.backup_failed' => 'Báo nhân sự sao lưu thất bại',
        'staff.backup_alert.cleanup_failed' => 'Báo nhân sự dọn bản sao lưu cũ thất bại',
        'staff.backup_alert.unhealthy' => 'Báo nhân sự bản sao lưu không lành mạnh',
        'undeclared' => 'Chưa khai báo mẫu',
        // M7 Task 4
        'staff.handover_ready' => 'Báo gói bàn giao hồ sơ đã sẵn sàng',
        // M7 Task 1 (nhãn thêm lúc gộp M7 vào `main` — MailTemplateRegistryTest).
        'staff.matter_reassigned' => 'Thư tổng hợp mốc thời hạn khi bàn giao vụ việc',
        // M14 Task 5: App\Mail\Staff\DocumentStoreAlert — hậu tố là loại sự cố của kho tài liệu.
        'staff.document_store_alert.sharing_drift' => 'Báo nhân sự chia sẻ của kho tài liệu lệch luật',
        'staff.document_store_alert.unavailable' => 'Báo nhân sự kho tài liệu tạm thời không truy cập được',
        'staff.document_store_alert.misconfigured' => 'Báo nhân sự kho tài liệu lỗi cấu hình',
        'staff.document_store_alert.not_enabled' => 'Báo nhân sự kho tài liệu chưa được bật',
        'staff.document_store_alert.push_backlog' => 'Báo nhân sự tệp chờ đẩy lên kho quá lâu',
        'staff.document_store_alert.office_copy_stale' => 'Báo nhân sự máy văn phòng chưa gửi biên nhận',
        'staff.document_store_alert.office_copy_error' => 'Báo nhân sự biên nhận của máy văn phòng báo lỗi',
        'staff.document_store_alert.transfer_dossier_due' => 'Báo nhân sự sắp tới hạn nộp hồ sơ chuyển dữ liệu ra nước ngoài',
    ],

    'matter_tab' => [
        'label' => 'Thư đã gửi',
    ],

    // M6 Task 10 — nút "Gửi lại" trên dòng `failed` (App\Actions\Notification\ResendOutboundMessage).
    'resend' => [
        'label' => 'Gửi lại',
        'modal_heading' => 'Gửi lại thư này?',
        'modal_description' => 'Hệ thống tính lại người nhận vào lúc gửi (có thể khác người nhận cũ: người đã nghỉ việc hoặc bị khoá sẽ không nhận). Dòng lỗi này được giữ nguyên làm bằng chứng; thư gửi lại là một dòng mới trong nhật ký.',
        'submit' => 'Gửi lại',
        'success_title' => 'Đã xếp hàng gửi lại',
        // :count = số người nhận đủ điều kiện và chưa nhận được thư này.
        'success' => 'Đã xếp hàng gửi lại thư cho :count người nhận. Kết quả nằm ở dòng mới nhất của nhật ký thư.',

        // Câu từ chối của ResendOutboundMessage (App\Exceptions\OutboundMessageNotResendable).
        // Không câu nào nêu mã vụ việc, tiêu đề hay tên khách.
        'refused' => [
            'template' => 'Loại thư này không gửi lại được từ nhật ký: :reason',
            // Lý do MỖI mục của ResendTargets::NOT_RESENDABLE (khoá = đúng mục đó, kể cả họ
            // `staff.backup_alert.*`); docblock ResendTargets ghi vì sao. Mẫu lạ nhận 'default'.
            'template_reasons' => [
                'client.otp' => 'mã đăng nhập chỉ có hiệu lực 5 phút nên mã cũ đã hết hạn; khách hãy tự bấm gửi mã mới ở cổng.',
                // Việc sau gộp M6 (làn fu, mục 1): câu cũ hứa "thử lại ở lần kiểm tra hạn kế tiếp"
                // — sai từ final review wave 2, I-2 (bậc hỏng hẳn chờ tới lượt đầu ngày hôm sau).
                'staff.deadline_reminder' => 'thư nhắc mốc thời hạn đã hỏng hẳn thì không được xếp lại trong ngày; lượt kiểm tra hạn đầu tiên của ngày hôm sau (07:00) sẽ nhắc lại mốc này, và chuông báo lỗi đã tới người phụ trách mốc, luật sư phụ trách và cấp trên. Mốc gấp thì hãy báo trực tiếp cho người phụ trách.',
                'client.activation' => 'gửi lại thư kích hoạt là cấp mật khẩu tạm mới; hãy dùng nút "Cấp lại mật khẩu" ở màn hình tài khoản cổng khách hàng.',
                'staff.instalment_overdue' => 'lời nhắc đợt thanh toán quá hạn tự được gửi lại ở lượt nhắc công nợ 08:00 kế tiếp nếu đợt vẫn quá hạn, vì chỉ thư đã gửi thành công mới chặn lời nhắc mới.',
                'staff.backup_alert.*' => 'thư báo lỗi sao lưu nói về một lượt sao lưu đã qua, gửi lại là báo một sự kiện cũ; nếu sự cố còn, lượt sao lưu 02:00 hoặc lượt kiểm tra sao lưu 08:00 kế tiếp sẽ tự báo lại.',
                // Gộp M7 vào `main`: hai thư nội bộ của M7 (lý do ở docblock `ResendTargets`).
                'staff.matter_reassigned' => 'thư tổng hợp bàn giao liệt kê các mốc thời hạn ở đúng lúc bàn giao, gửi lại là gửi một danh sách cũ; luật sư nhận bàn giao đã được báo trong hệ thống khi thư hỏng, và các mốc vẫn hiện ở trang chủ, ở tab "Mốc thời hạn" của từng vụ và trong thư nhắc mốc theo lịch.',
                'staff.handover_ready' => 'thư này chỉ báo gói bàn giao đã sinh xong — trạng thái gói luôn hiện ở khối "Gói bàn giao" trên trang vụ việc, và chuông trong hệ thống đã báo cùng lúc; gửi lại là báo một sự kiện đã qua.',
                // M10 (gộp `main` vào làn M10): lý do ở docblock `ResendTargets`.
                'staff.intake_unanswered' => 'lời nhắc liên hệ chưa ai gọi lại tự được gửi lại ở lượt nhắc kế tiếp (mỗi 15 phút trong giờ làm việc; thư đã hỏng hẳn thì từ ngày làm việc hôm sau) nếu bản ghi vẫn còn "Mới", vì chỉ thư đã gửi thành công mới chặn lời nhắc mới; chuông trong hệ thống và widget "Liên hệ chưa ai gọi lại" không phụ thuộc thư.',
                // M14 Task 5 (lý do ở docblock `ResendTargets`).
                'staff.document_store_alert.*' => 'thư cảnh báo kho tài liệu nói về một lần kiểm sức khoẻ đã qua; nếu sự cố còn, lượt kiểm mỗi giờ sẽ tự báo lại (mỗi loại sự cố một thư mỗi ngày, thư lỗi không chặn lần gửi sau). Xem tình trạng hiện tại ở trang "Kho tài liệu".',
                'undeclared' => 'thư này không khai báo mẫu nên không dựng lại được nội dung.',
                'default' => 'hệ thống không biết dựng lại thư này từ nhật ký.',
            ],
            'not_failed' => 'Chỉ dòng thư gửi lỗi mới gửi lại được.',
            'related_gone' => 'Bản ghi mà thư này nói về không còn nữa nên không dựng lại được thư.',
            'superseded' => 'Việc mà thư này báo đã được thay bằng một lần mới hơn (ví dụ giấy tờ đã bị từ chối lại với lý do khác), nên thư cũ không còn đúng. Hãy xem dòng thư của lần mới trong nhật ký.',
            'already_requested' => 'Thư này đã được yêu cầu gửi lại lúc :time. Hãy xem dòng mới nhất của cùng mẫu thư trong nhật ký; nếu lần đó cũng lỗi thì dòng lỗi mới có nút gửi lại riêng.',
            'no_eligible_recipient' => 'Hiện không còn ai đủ điều kiện nhận thư này (vụ việc đã đóng, đã hết hạn tra cứu hoặc tắt công bố trên cổng, tài khoản đã khoá hoặc chưa kích hoạt, hay nhân sự không còn xem được vụ việc). Không có thư nào được gửi.',
            'already_delivered' => 'Mọi người đủ điều kiện nhận thư này đều đã nhận được ở một lần gửi khác. Không có thư nào được gửi.',
        ],

        // Job gửi lại hỏng hẳn (App\Notifications\Staff\OutboundResendFailedAlert) — gửi người đã
        // bấm, hoặc người thay theo chuỗi dự phòng R3 nếu người bấm đã nghỉ; nên câu không nói "bạn".
        'failed_notification' => [
            'title' => 'Gửi lại thư không thành công',
            'body' => 'Một lượt gửi lại thư đã hỏng sau mọi lần thử. Lý do nằm ở các dòng lỗi mới nhất trong Nhật ký thư; quản trị viên bấm "Gửi lại" ở dòng lỗi mới sau khi xử lý nguyên nhân.',
        ],
    ],
];
