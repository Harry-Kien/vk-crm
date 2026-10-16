<?php

/**
 * Kho tài liệu Google Drive (M14), phần vận hành của làn m14b: kiểm tra sẵn sàng
 * (`App\Actions\Storage\StorageReadiness`, `vkcrm:storage:check`), kiểm chia sẻ
 * (`InspectDriveSharing`), kiểm tra sức khoẻ mỗi giờ (`CheckDocumentStoreHealth`), thư
 * `staff.document_store_alert.*`, trang "Kho tài liệu", dòng kho trên dải sức khoẻ, `vkcrm:storage:init`.
 *
 * Tệp RIÊNG, không nối vào `lang/vi/storage.php`: làn m14 nối thêm vào tệp đó cùng lúc (phán quyết
 * của controller cho làn m14b: khoá mới vào tệp mới hoặc khối tách bạch). Cùng luật với tệp đó: không
 * mã tệp Drive, không bí mật, không tiêu đề tài liệu, mã hồ sơ hay tên khách. Email của một thành viên
 * lạ trên Shared Drive có thể xuất hiện trong dòng kiểm và dòng sức khoẻ (người vận hành cần biết ai để
 * gỡ), không bao giờ trong thư.
 */
$noOffice = 'chưa có máy văn phòng: vùng đệm trên máy chủ giữ mọi tệp';

return [
    'check' => [
        'readiness_heading' => '== Dòng sẵn sàng (kho dùng được không) ==',
        'state_heading' => '== Dòng trạng thái (kho đang chạy ra sao) ==',
        'summary_ok' => 'Kho tài liệu: mọi dòng đều XANH.',
    ],

    'readiness' => [
        'driver_ok' => 'DOCUMENT_STORAGE=:driver là giá trị hợp lệ.',
        'driver_invalid' => 'DOCUMENT_STORAGE có giá trị lạ :value. Hệ thống coi như "local": tệp ở lại máy chủ, KHÔNG lên kho, trong khi người vận hành có thể đang tin là có. Đặt đúng local hoặc google_drive rồi chạy php artisan optimize và chmod 600 bootstrap/cache/config.php.',

        'credentials_missing' => 'Chưa cấu hình GOOGLE_DRIVE_CREDENTIALS_PATH (đường dẫn TUYỆT ĐỐI tới tệp khoá JSON của tài khoản dịch vụ, không dùng ~).',
        'credentials_not_found' => 'Không có tệp khoá ở :path. Kiểm lại GOOGLE_DRIVE_CREDENTIALS_PATH (đường dẫn tuyệt đối, không dùng ~) rồi chạy php artisan optimize và chmod 600 bootstrap/cache/config.php.',
        'credentials_unreadable' => 'PHP không đọc được tệp khoá :path. Chủ sở hữu tệp phải là người dùng chạy PHP (shared hosting), hoặc nhóm của tệp là nhóm của PHP-FPM (VPS).',
        'credentials_inside_app' => 'Tệp khoá :path nằm bên trong :root — một lần git add hay một lỗi cấu hình máy chủ web là lộ khoá. Chuyển tệp ra ngoài thư mục mã nguồn (VPS: /etc/vkcrm/; shared hosting: /home/<tài khoản>/.config/vkcrm/).',
        'credentials_web_root' => 'Tệp khoá :path nằm dưới một thư mục :directory — thư mục mà máy chủ web thường phục vụ công khai. Chuyển tệp ra ngoài gốc web.',
        'credentials_world' => 'Tệp khoá :path có quyền :mode: người dùng KHÁC trên máy chủ đọc hoặc ghi được. Đặt chmod 0400 (shared hosting) hoặc 0440 với nhóm của PHP-FPM (VPS).',
        'credentials_group_writable' => 'Tệp khoá :path có quyền :mode: nhóm của tệp GHI được (thay được khoá). Đặt chmod 0400 hoặc 0440.',
        'credentials_not_service_account' => 'Tệp :path không phải khoá JSON của một tài khoản dịch vụ Google (cần type = service_account, client_email và private_key). Tải lại khoá theo Phụ lục A bước 5.',
        'credentials_group_unproven' => 'Tệp khoá :path có quyền :mode: nhóm của tệp đọc được, và :reason Trên shared hosting nhóm thường gồm nhiều tài khoản: đặt chmod 0400. Trên VPS: chủ root, nhóm PHP-FPM riêng, chmod 0440.',
        'credentials_ok' => 'Tệp khoá :path ngoài mã nguồn và gốc web, quyền :mode, đúng khoá của một tài khoản dịch vụ.',
        'group_no_posix' => 'PHP thiếu extension posix nên không chứng minh được nhóm đó là riêng.',
        'group_not_process_group' => 'nhóm của tệp không phải nhóm của tiến trình PHP, nên người trong nhóm đó (không phải PHP) đọc được khoá.',
        'group_members_unknown' => 'không đọc được danh sách thành viên của nhóm, nên không chứng minh được nhóm đó là riêng.',
        'group_has_others' => 'nhóm có thành viên khác ngoài người dùng chạy PHP (:members), và họ đọc được khoá.',

        'http_missing' => 'PHP không có cách nào gửi HTTP tới Google: thiếu cả extension curl lẫn allow_url_fopen. Bật một trong hai (khuyên dùng curl).',
        'http_ok' => 'PHP gửi được HTTP tới Google (:transports).',

        'skipped_no_http' => 'Chưa kiểm được: PHP không gửi được HTTP (xem drive_http_client).',
        'skipped_unreachable' => 'Chưa kiểm được: Shared Drive chưa tới được (xem drive_reachable).',
        'skipped_root' => 'Chưa thử ghi–đọc: thư mục gốc chưa đạt (xem drive_root_folder).',

        'reachable_not_configured' => 'Chưa cấu hình :missing. Mã Shared Drive là phần cuối URL drive.google.com/drive/folders/<MÃ> của Shared Drive.',
        'reachable_failed' => 'Không tới được Shared Drive kho (:error). Kiểm theo thứ tự: đồng hồ máy chủ đúng giờ (NTP — lệch giờ làm Google từ chối khoá), tường lửa hay hosting không chặn oauth2.googleapis.com và www.googleapis.com, GOOGLE_DRIVE_SHARED_DRIVE_ID đúng và tài khoản dịch vụ là thành viên của Shared Drive.',
        'reachable_ok' => 'Tới được Shared Drive ":name".',

        'sharing_failed' => 'Không đọc được cài đặt chia sẻ của Shared Drive (:error).',
        'sharing_ok' => 'Shared Drive chỉ cho thành viên; thành viên đúng danh sách GOOGLE_DRIVE_ALLOWED_MEMBERS và đúng vai; tài khoản dịch vụ ở vai Người quản lý nội dung.',

        'root_failed' => 'Không đọc được thư mục gốc (:error).',
        'root_ok' => 'Thư mục gốc GOOGLE_DRIVE_ROOT_FOLDER_ID thuộc đúng Shared Drive kho và không ở thùng rác.',

        'roundtrip_ok' => 'Ghi một tệp thăm dò :bytes byte dưới preflight/, md5 do Google tính khớp, đọc lại đúng nội dung, đã cho vào thùng rác.',
        'roundtrip_failed' => 'Thử ghi–đọc một tệp thăm dò thất bại (:error).',
        'roundtrip_checksum_mismatch' => 'Google báo md5 của tệp thăm dò khác bản đã gửi.',
        'roundtrip_content_mismatch' => 'Nội dung tệp thăm dò đọc lại khác bản đã ghi.',
        'roundtrip_cleanup_failed' => 'Không cho được tệp thăm dò vào thùng rác (:error). Tài khoản dịch vụ phải ở đúng vai "Người quản lý nội dung": vai Contributor không cho tệp vào thùng rác được, và mỗi lần xoá tài liệu sẽ để lại một tệp mồ côi trên Drive.',
        'roundtrip_cleanup_log' => 'Kiểm tra sẵn sàng kho: không cho được tệp thăm dò vào thùng rác.',

        'enabled_local' => 'DOCUMENT_STORAGE không phải google_drive: tệp mới nằm trên máy chủ như trước, kho không bật.',
        'enabled_missing' => 'DOCUMENT_STORAGE=google_drive nhưng kho CHƯA BẬT: tệp mới vẫn chỉ nằm trên máy chủ trong khi người vận hành tin là chúng ở trên kho. Chạy php artisan vkcrm:storage:enable (sau php artisan optimize và chmod 600 bootstrap/cache/config.php).',
        'enabled_ok' => 'Kho tài liệu đã bật từ :at: tệp mới tự lên kho.',

        'items_warn' => 'Shared Drive kho có khoảng :count mục, gần giới hạn :limit mục của Google (tính cả thùng rác trong 30 ngày). Cần chuẩn bị Shared Drive thứ hai.',
        'items_ok' => 'Shared Drive kho có khoảng :count mục (giới hạn của Google: :limit).',

        'backlog_warn' => ':count tệp mới chờ đẩy lên kho quá :minutes phút (tệp chờ lâu nhất tạo lúc :oldest). Xem hàng đợi storage và log. Ngoài ra :legacy tệp cũ ở máy chủ chờ chuyển bằng vkcrm:storage:migrate.',
        'backlog_ok' => 'Không tệp mới nào chờ đẩy quá hạn. :legacy tệp cũ ở máy chủ chờ chuyển bằng vkcrm:storage:migrate (không phải tồn đọng).',

        'office_unreceipted' => ':count media trên kho chưa có biên nhận bản thứ hai.',
        'office_not_configured_short' => $noOffice,
        'office_not_configured' => 'Bản thứ hai: '.$noOffice.' (DOCUMENT_OFFICE_RECEIPTS_PATH trống), không bản nào được dọn. :unreceipted',
        'office_error' => 'Biên nhận gần nhất của máy văn phòng báo lỗi: :error :unreceipted',
        'office_never' => 'Đã cấu hình máy văn phòng nhưng CRM chưa nhập được biên nhận nào. :unreceipted',
        'office_stale' => 'Biên nhận gần nhất của máy văn phòng lúc :at, cũ hơn :hours giờ. :unreceipted',
        'office_ok' => 'Biên nhận gần nhất của máy văn phòng lúc :at. :unreceipted',

        'dossier_filed' => 'Đã ghi ngày hồ sơ chuyển dữ liệu cá nhân ra nước ngoài: :date.',
        'dossier_overdue' => 'QUÁ HẠN nộp hồ sơ chuyển dữ liệu cá nhân ra nước ngoài: lần chuyển đầu tiên lúc :first, đã :days ngày (hạn 60 ngày). Báo luật sư của văn phòng ngay; ghi ngày hồ sơ trên trang "Kho tài liệu" khi đã nộp.',
        'dossier_blocked' => 'Chưa có ngày hồ sơ chuyển dữ liệu cá nhân ra nước ngoài, cũng chưa có ý kiến luật sư cho chuyển trước khi nộp hồ sơ. Không bật được kho trên production cho tới khi một trong hai được ghi trên trang "Kho tài liệu" (Luật 91/2025, Nghị định 356/2025).',
        'dossier_due' => 'Sắp tới hạn nộp hồ sơ chuyển dữ liệu cá nhân ra nước ngoài: lần chuyển đầu tiên lúc :first, còn :days_left ngày.',
        'dossier_clock' => 'Đồng hồ 60 ngày nộp hồ sơ đang chạy: lần chuyển đầu tiên lúc :first, còn :days_left ngày.',
        'dossier_opinion' => 'Có ý kiến luật sư cho chuyển trước khi nộp hồ sơ (ngày :date); chưa có lần chuyển nào.',
        'dossier_idle' => 'Chưa có lần chuyển dữ liệu nào lên kho.',

        'remote_while_local' => 'DOCUMENT_STORAGE không phải google_drive mà còn :count media trên kho. Chúng vẫn tải được, nhưng nếu đang quay lui thì chạy php artisan vkcrm:storage:rollback để kéo hết về máy chủ.',
        'remote_while_local_ok' => ':count media trên kho, khớp với chế độ hiện tại.',

        'free_space_unknown' => 'PHP không đo được chỗ trống trên đĩa (disk_free_space bị tắt): gói bàn giao bỏ bước kiểm chỗ trống trước khi dựng.',
        'free_space_ok' => 'Đo được chỗ trống trên đĩa của vùng đệm: còn :free.',
    ],

    'sharing' => [
        'members_only_off' => 'Shared Drive cho người KHÔNG phải thành viên truy cập tệp (driveMembersOnly tắt): bật lại "chỉ thành viên" (Phụ lục A bước 2).',
        'allowed_member_invalid' => 'GOOGLE_DRIVE_ALLOWED_MEMBERS có mục sai dạng ":entry" (cần email:vai, vai là organizer, fileOrganizer, writer, commenter hoặc reader).',
        'service_account_unknown' => 'Không đọc được email tài khoản dịch vụ từ tệp khoá, nên không phân biệt được nó với thành viên khác (xem drive_credentials).',
        'anyone' => 'Có quyền chia sẻ cho BẤT KỲ AI có đường link (vai :role): gỡ ngay.',
        'domain' => 'Có quyền chia sẻ cho CẢ TÊN MIỀN :domain (vai :role): gỡ ngay.',
        'service_account_role' => 'Tài khoản dịch vụ mang vai :role; phải đúng vai "Người quản lý nội dung" (fileOrganizer), không vai nào khác.',
        'service_account_missing' => 'Tài khoản dịch vụ :email không có trong danh sách thành viên của Shared Drive.',
        'stranger' => 'Thành viên ngoài danh sách được phép: :email (vai :role). Gỡ khỏi Shared Drive, hoặc thêm vào GOOGLE_DRIVE_ALLOWED_MEMBERS nếu đúng là người được phép.',
        'no_email' => '(không rõ email)',
        'wrong_role' => 'Thành viên :email mang vai :role, khác vai đã khai :expected.',
        'folders_organizer_off' => 'Người quản lý nội dung được chia sẻ thư mục (sharingFoldersRequiresOrganizerPermission tắt): tắt "Cho phép người quản lý nội dung chia sẻ thư mục" (Phụ lục A bước 2).',
        'domain_users_off' => 'Shared Drive cho người ngoài tổ chức truy cập (domainUsersOnly tắt). Tài khoản dịch vụ có đuôi gserviceaccount.com nên chính nó là người ngoài tổ chức: nếu Google chỉ cho thêm nó khi bật công tắc này, giữ bật cho RIÊNG Shared Drive kho. Hàng rào thật khi đó là "chỉ thành viên" cộng danh sách thành viên được kiểm mỗi giờ.',
    ],

    'root' => [
        'missing' => 'Chưa cấu hình GOOGLE_DRIVE_ROOT_FOLDER_ID: chạy php artisan vkcrm:storage:init để tạo thư mục gốc và in mã của nó.',
        'not_found' => 'Không có thư mục nào mang mã GOOGLE_DRIVE_ROOT_FOLDER_ID trên Drive (hoặc tài khoản dịch vụ không thấy nó).',
        'not_folder' => 'Mã GOOGLE_DRIVE_ROOT_FOLDER_ID là một tệp, không phải thư mục.',
        'other_drive' => 'Thư mục gốc không thuộc Shared Drive GOOGLE_DRIVE_SHARED_DRIVE_ID.',
        'trashed' => 'Thư mục gốc đang ở thùng rác: lấy lại từ thùng rác của Shared Drive.',
    ],

    'health' => [
        'detail' => [
            'sharing_drift' => 'Chia sẻ của Shared Drive kho lệch luật: :problems',
            'unavailable' => 'Kho Google Drive tạm thời không truy cập được: :error',
            'misconfigured' => 'Kho Google Drive báo lỗi cấu hình: :error',
            'not_enabled' => 'DOCUMENT_STORAGE=google_drive nhưng chưa chạy vkcrm:storage:enable: tệp mới vẫn chỉ nằm trên máy chủ.',
            'push_backlog' => ':count tệp mới chờ đẩy lên kho quá :minutes phút.',
            'office_copy_stale' => 'Biên nhận bản thứ hai của máy văn phòng đã cũ (gần nhất: :at).',
            'office_copy_error' => 'Biên nhận của máy văn phòng báo lỗi: :error',
            'transfer_dossier_due' => 'Hạn nộp hồ sơ chuyển dữ liệu cá nhân ra nước ngoài: còn :days_left ngày.',
            'transfer_dossier_overdue' => 'QUÁ HẠN nộp hồ sơ chuyển dữ liệu cá nhân ra nước ngoài :days ngày.',
            'transfer_blocked' => 'Kho đang bật nhưng không còn ngày hồ sơ chuyển dữ liệu ra nước ngoài lẫn ý kiến luật sư cho chuyển trước: tệp mới không được đẩy lên kho, lệnh chuyển tệp cũ bị từ chối.',
        ],
        'log' => [
            'mail_failed' => 'Kiểm tra sức khoẻ kho: không xếp được thư cảnh báo.',
            'no_recipients' => 'Kiểm tra sức khoẻ kho: không có người nhận thư cảnh báo (BACKUP_NOTIFY_EMAIL trống và không có quản trị viên nào đang hoạt động).',
            'drive_failed' => 'Kiểm tra sức khoẻ kho: lỗi không phân loại được khi hỏi Google Drive.',
        ],
    ],

    'alert' => [
        'subject' => [
            'sharing_drift' => 'Kho tài liệu: chia sẻ của Shared Drive lệch luật',
            'unavailable' => 'Kho tài liệu: tạm thời không truy cập được',
            'misconfigured' => 'Kho tài liệu: lỗi cấu hình',
            'not_enabled' => 'Kho tài liệu: đã chọn Google Drive nhưng chưa bật',
            'push_backlog' => 'Kho tài liệu: tệp mới chờ đẩy quá lâu',
            'office_copy_stale' => 'Kho tài liệu: máy văn phòng chưa gửi biên nhận',
            'office_copy_error' => 'Kho tài liệu: biên nhận của máy văn phòng báo lỗi',
            'transfer_dossier_due' => 'Kho tài liệu: sắp tới hạn nộp hồ sơ chuyển dữ liệu ra nước ngoài',
            'transfer_blocked' => 'Kho tài liệu: đã dừng chuyển dữ liệu vì thiếu căn cứ pháp lý',
        ],
        'heading' => [
            'sharing_drift' => 'Chia sẻ của Shared Drive kho tài liệu lệch luật: :count điều cần sửa (thành viên, vai hoặc cài đặt chia sẻ).',
            'unavailable' => 'Kho tài liệu Google Drive tạm thời không truy cập được. Ai tải tài liệu đang nằm trên kho sẽ thấy trang "Chưa tải được tài liệu lúc này" (mời thử lại sau ít phút); tệp mới vẫn được lưu trên máy chủ.',
            'misconfigured' => 'Kho tài liệu Google Drive báo lỗi cấu hình (khoá, quyền, Shared Drive hoặc hạn mức). Lỗi này không tự hết.',
            'not_enabled' => 'Công tắc DOCUMENT_STORAGE là google_drive nhưng kho chưa được bật (vkcrm:storage:enable chưa chạy): tệp mới vẫn chỉ nằm trên máy chủ.',
            'push_backlog' => ':count tệp mới chờ đẩy lên kho quá thời gian cho phép. Tệp vẫn an toàn trên máy chủ.',
            'office_copy_stale' => 'Máy văn phòng chưa gửi biên nhận bản thứ hai đúng hạn. :count tệp trên kho chưa có bản ngoài Google được xác nhận; vùng đệm trên máy chủ vẫn giữ chúng.',
            'office_copy_error' => 'Biên nhận gần nhất của máy văn phòng báo lỗi. :count tệp trên kho chưa có bản ngoài Google được xác nhận; vùng đệm trên máy chủ vẫn giữ chúng.',
            'transfer_dossier_due' => 'Hồ sơ chuyển dữ liệu cá nhân ra nước ngoài phải nộp trong 60 ngày kể từ lần chuyển đầu tiên. Số ngày còn lại: :days_left (số âm là đã quá hạn). Ghi ngày hồ sơ trên trang "Kho tài liệu" khi đã nộp.',
            'transfer_blocked' => 'Kho tài liệu Google Drive đang bật, nhưng trang "Kho tài liệu" không còn ngày lập/nộp hồ sơ chuyển dữ liệu cá nhân ra nước ngoài lẫn ngày ý kiến luật sư cho chuyển trước. Hệ thống đã dừng đẩy tệp mới lên kho và từ chối lệnh chuyển tệp cũ; tệp mới vẫn được lưu trên máy chủ. Ghi lại một trong hai ngày đó trên trang "Kho tài liệu", hoặc quay lui kho theo sổ tay. Lỗi này không tự hết.',
        ],
        'action' => 'Chạy php artisan vkcrm:storage:check trên máy chủ để xem chi tiết, hoặc mở trang "Kho tài liệu" trong /admin.',
        'salutation' => 'Hệ thống VK-CRM — :office',
    ],

    'page' => [
        'title' => 'Kho tài liệu',
        'navigation_label' => 'Kho tài liệu',
        // Làn tl (2026-10-15): nhãn của trang viết lại bằng tiếng thường; tên biến môi trường và lệnh
        // chỉ còn trong mục "Chi tiết kỹ thuật" (khối `overview.technical` bên dưới).
        'sections' => [
            'status' => 'Kiểm tra tự động',
            'files' => 'Tệp',
            'office' => 'Bản sao ở máy văn phòng',
            'dossier' => 'Hồ sơ chuyển dữ liệu cá nhân ra nước ngoài',
        ],
        'mode' => [
            'local' => 'Lưu trên máy chủ (local)',
            'google_drive' => 'Google Drive',
            'invalid' => 'Giá trị lạ: :value (tệp ở lại máy chủ)',
        ],
        'figures' => [
            'mode' => 'Chế độ (DOCUMENT_STORAGE)',
            'enabled_at' => 'Bật Google Drive lúc',
            'status' => 'Kết quả lần kiểm tra gần nhất',
            'checked_at' => 'Kiểm lúc',
            'detail' => 'Chi tiết',
            'pending_new' => 'Tệp mới chờ đẩy lên Drive',
            'oldest_pending' => 'Tệp mới chờ lâu nhất, tạo lúc',
            'legacy' => 'Tệp cũ chưa chuyển lên Drive',
            'local_copies' => 'Bản tạm còn giữ trên máy chủ',
            'unreceipted' => 'Tệp trên Drive chưa có bản sao ở máy văn phòng',
            'last_receipt' => 'Máy văn phòng xác nhận bản sao gần nhất lúc',
            'receipt_error' => 'Lỗi gần nhất của máy văn phòng',
            'items' => 'Số mục đang dùng trên Shared Drive',
            'first_transfer' => 'Lần đầu chuyển dữ liệu lên Drive',
            'dossier_clock' => 'Hạn 60 ngày nộp hồ sơ',
        ],
        'values' => [
            'never' => 'Chưa có',
            'not_enabled' => 'Chưa bật',
            'not_checked' => 'Chưa kiểm lần nào',
            'items' => ':count / :limit mục',
            'clock_days_left' => 'Còn :days ngày',
            'clock_overdue' => 'Quá hạn :days ngày',
            'clock_stopped' => 'Đã ghi ngày hồ sơ (:date)',
            'clock_idle' => 'Chưa có lần chuyển nào',
        ],
        'dossier_intro' => 'Việc của luật sư và quản trị viên, chỉ cần khi văn phòng lưu tài liệu lên Google Drive (máy chủ của Google đặt ở nước ngoài). Trước khi bật Drive trên máy chủ thật, phải ghi ngày lập/nộp hồ sơ, HOẶC ngày luật sư có ý kiến bằng văn bản cho chuyển trước khi nộp. Dàn ý hồ sơ: tệp docs/PHAP-LY-LUU-TRU-NUOC-NGOAI.md. Đây là thông tin vận hành, không phải tư vấn pháp lý.',
        'fields' => [
            'transfer_dossier_on' => 'Ngày lập/nộp hồ sơ',
            'transfer_dossier_reference' => 'Số hoặc mã hồ sơ',
            'dpa_accepted_on' => 'Ngày chấp nhận điều khoản xử lý dữ liệu của Google (DPA, trong Google Admin)',
            'transfer_before_dossier_on' => 'Ngày ý kiến luật sư cho chuyển trước khi nộp hồ sơ',
            'transfer_before_dossier_basis' => 'Căn cứ ý kiến luật sư (số và ngày văn bản)',
        ],
        'validation' => [
            'not_in_future' => ':attribute không được là ngày trong tương lai: chỉ ghi ngày đã xảy ra (hôm nay hoặc trước đó). Ngày dự kiến sẽ mở cổng chuyển dữ liệu khi hồ sơ chưa có.',
        ],
        'submit' => 'Lưu',
        'notifications' => [
            'saved' => 'Đã lưu hồ sơ chuyển dữ liệu.',
            'unchanged' => 'Không có gì thay đổi.',
        ],

        // làn tl (2026-10-15) — dải trạng thái, các bước bật Drive, bốn ô số, "Chi tiết kỹ thuật",
        // tiêu đề mục hồ sơ. Logic chọn câu ở `DocumentStorePage::overview()`/`figures()`.
        'dossier_form_heading' => 'Hồ sơ chuyển dữ liệu cá nhân ra nước ngoài (luật sư, quản trị viên)',
        'overview' => [
            'intro' => 'Đây là trang kỹ thuật cho quản trị viên: tài liệu của văn phòng đang được lưu ở đâu, và kho Google Drive (nếu bật) có chạy ổn không.',
            'intro_documents_before' => 'Nhân sự xem và tìm tài liệu ở trang',
            'intro_documents_link' => 'Tài liệu',
            'intro_documents_after' => 'hoặc ở tab "Tài liệu" của từng vụ việc.',
            'next_label' => 'Cần làm gì tiếp:',
            'headline' => [
                'local' => 'Tài liệu đang lưu trên máy chủ CRM. Google Drive chưa bật.',
                'remote_while_local' => 'Tài liệu mới lưu trên máy chủ CRM, nhưng còn :count tệp đang nằm trên Google Drive.',
                'driver_invalid' => 'Cấu hình chế độ lưu tài liệu có giá trị lạ: tài liệu mới đang ở lại máy chủ CRM.',
                'not_enabled' => 'Đã chọn Google Drive nhưng kho chưa được bật: tài liệu mới vẫn lưu trên máy chủ CRM.',
                'check_failed' => 'Lần kiểm tra kho Google Drive gần nhất báo lỗi. Tài liệu mới vẫn được lưu trên máy chủ CRM.',
                'transfer_blocked' => 'Đã dừng chuyển tài liệu lên Google Drive: trang này không còn ngày hồ sơ chuyển dữ liệu cá nhân ra nước ngoài lẫn ngày ý kiến luật sư cho chuyển trước. Tài liệu mới đang ở lại máy chủ CRM.',
                'degraded' => 'Google Drive đang chạy nhưng có việc cần xem.',
                'not_checked' => 'Google Drive đã bật nhưng chưa có lần kiểm tra tự động nào.',
                'legacy_pending' => 'Tài liệu mới tự lưu lên Google Drive. Còn :count tệp cũ trên máy chủ chưa chuyển lên.',
                'remote_ok' => 'Tài liệu đang lưu trên Google Drive của văn phòng; tệp cũ đã chuyển hết.',
                'dossier_overdue' => 'Quá hạn nộp hồ sơ chuyển dữ liệu cá nhân ra nước ngoài :days ngày.',
                'dossier_due' => 'Sắp tới hạn nộp hồ sơ chuyển dữ liệu cá nhân ra nước ngoài: còn :days ngày.',
            ],
            'next' => [
                'local' => 'Không cần làm gì. Khi văn phòng muốn lưu tài liệu lên Google Drive, làm lần lượt các bước bên dưới.',
                'remote_while_local' => 'Nếu văn phòng đang thôi dùng Google Drive, báo người cài đặt kéo các tệp đó về máy chủ (xem "Chi tiết kỹ thuật"). Trong lúc chờ, các tệp đó vẫn tải được.',
                'driver_invalid' => 'Báo người cài đặt sửa cấu hình chế độ lưu (xem "Chi tiết kỹ thuật").',
                'not_enabled' => 'Khi các bước bên dưới đã xong, báo người cài đặt chạy lệnh bật kho (xem "Chi tiết kỹ thuật").',
                'check_failed' => 'Đọc dòng "Chi tiết" ở mục Kiểm tra tự động bên dưới và báo người cài đặt. Lỗi này không tự hết.',
                'transfer_blocked' => 'Ghi lại ngày hồ sơ, hoặc ngày ý kiến luật sư kèm căn cứ, ở mục cuối trang. Trước khi ghi, đừng chạy lệnh chuyển tệp cũ: lệnh sẽ từ chối. Lỗi này không tự hết.',
                'degraded' => 'Đọc dòng "Chi tiết" ở mục Kiểm tra tự động bên dưới. Trong lúc chờ, tài liệu vẫn an toàn trên máy chủ CRM.',
                'not_checked' => 'Kiểm tra tự động chạy mỗi giờ; quay lại trang này sau.',
                'legacy_pending' => 'Báo người cài đặt chạy lệnh chuyển tệp cũ, ngoài giờ làm việc, cho tới khi số tệp cũ về 0 (xem "Chi tiết kỹ thuật").',
                'remote_ok' => 'Không cần làm gì.',
                'dossier_overdue' => 'Báo luật sư của văn phòng ngay; khi đã nộp, ghi ngày hồ sơ ở mục cuối trang.',
                'dossier_due' => 'Báo luật sư lập và nộp hồ sơ; khi đã nộp, ghi ngày hồ sơ ở mục cuối trang.',
            ],
            'steps_heading' => 'Các bước bật Google Drive',
            'steps_intro' => 'Chỉ cần khi văn phòng muốn lưu tài liệu lên Google Drive. Làm theo thứ tự; trang tự đánh dấu bước đã xong.',
            'step_done' => 'Đã xong',
            'step_pending' => 'Chưa xong',
            'steps' => [
                'server' => 'Người cài đặt đặt khoá của tài khoản dịch vụ Google lên máy chủ CRM.',
                'shared_drive' => 'Chủ văn phòng tạo một Shared Drive riêng cho CRM và mời tài khoản dịch vụ vào; người cài đặt ghi mã Shared Drive và thư mục gốc vào cấu hình.',
                'dossier' => 'Luật sư lập hồ sơ chuyển dữ liệu cá nhân ra nước ngoài; ghi ngày nộp hồ sơ, hoặc ngày luật sư cho chuyển trước, ở mục cuối trang.',
                'enabled_checked' => 'Người cài đặt kiểm tra kho (mọi dòng xanh) rồi bật kho; lần kiểm tra tự động mỗi giờ báo hoạt động bình thường.',
                'migrated' => 'Chuyển tệp cũ từ máy chủ lên Google Drive, ngoài giờ làm việc.',
            ],
            'guides' => [
                'server' => 'Hướng dẫn: docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md, Phụ lục A, bước 3–5 và 9.',
                'shared_drive' => 'Hướng dẫn: docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md, Phụ lục A, bước 0–2, 6–8, 10–11.',
                'dossier' => 'Hướng dẫn: docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md, Phụ lục A, bước 12; dàn ý hồ sơ: docs/PHAP-LY-LUU-TRU-NUOC-NGOAI.md.',
                'enabled_checked' => 'Hướng dẫn: docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md, Phụ lục C, bước 3 và 6.',
                'migrated' => 'Hướng dẫn: docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md, Phụ lục C, bước 5, 7 và 8.',
            ],
            'tiles_heading' => 'Tệp tài liệu',
            'tile_hints' => [
                'pending_new' => 'Tệp vừa tải lên, thường về 0 sau vài phút. Đỏ khi có tệp chờ quá lâu.',
                'legacy' => 'Tệp có từ trước khi bật Drive. Chỉ cần chuyển khi đã bật Drive.',
                'local_copies' => 'Bản trên máy chủ được giữ thêm một thời gian sau khi tệp đã lên Drive, cho an toàn.',
                'unreceipted' => 'Tệp mà máy tính ở văn phòng chưa xác nhận đã sao về.',
            ],
            'technical_heading' => 'Chi tiết kỹ thuật (cho người cài đặt)',
            'technical_intro' => 'Chi tiết từng dòng kiểm: chạy các lệnh dưới đây trên máy chủ, bằng đúng người dùng chạy PHP. Hướng dẫn đầy đủ: docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md.',
            'technical' => [
                'check' => 'Kiểm tra kho: php artisan vkcrm:storage:check',
                'enable' => 'Bật kho (sau khi đặt DOCUMENT_STORAGE=google_drive, chạy php artisan optimize rồi chmod 600 bootstrap/cache/config.php): php artisan vkcrm:storage:enable',
                'migrate' => 'Chuyển tệp cũ: php artisan vkcrm:storage:migrate --max-minutes=240 (thử trước bằng --dry-run)',
                'rollback' => 'Quay lui về máy chủ (sau khi đặt DOCUMENT_STORAGE=local, chạy php artisan optimize rồi chmod 600 bootstrap/cache/config.php): php artisan vkcrm:storage:rollback',
                'env' => 'Biến cấu hình: DOCUMENT_STORAGE, GOOGLE_DRIVE_CREDENTIALS_PATH, GOOGLE_DRIVE_SHARED_DRIVE_ID, GOOGLE_DRIVE_ROOT_FOLDER_ID, GOOGLE_DRIVE_ALLOWED_MEMBERS.',
            ],
        ],
    ],

    'widget' => [
        'heading' => 'Kho tài liệu: :status',
        'checked_at' => 'Kiểm lúc :at.',
        'hint' => 'Xem trang "Kho tài liệu", hoặc chạy php artisan vkcrm:storage:check trên máy chủ.',
    ],

    'init' => [
        'not_configured' => 'Chưa cấu hình :missing. Điền vào .env, chạy php artisan optimize và chmod 600 bootstrap/cache/config.php, rồi chạy lại lệnh này.',
        'created' => 'Đã tạo thư mục gốc ":name" trong Shared Drive kho.',
        'env_line' => 'Điền dòng sau vào .env, rồi chạy php artisan optimize, chmod 600 bootstrap/cache/config.php và php artisan vkcrm:storage:check:',
        'exists' => 'Shared Drive đã có :count thư mục tên ":name". Không tạo thêm (Drive cho trùng tên, và hai thư mục gốc là hai kho).',
        'exists_item' => '  - mã :id',
        'exists_hint' => 'Nếu đúng là thư mục của môi trường này, điền mã của nó vào GOOGLE_DRIVE_ROOT_FOLDER_ID. Nếu có nhiều hơn một, hỏi người quản trị Workspace trước khi chọn.',
        'failed' => 'Không tạo được thư mục gốc: :error',
    ],
];
