<?php

/**
 * Kho tài liệu (M14): Google Drive (Shared Drive của văn phòng) làm kho phía sau CRM. Mọi chữ hiển
 * thị của tính năng — thông điệp ngoại lệ, trang 503, thư cảnh báo, dòng kiểm sẵn sàng, đầu ra lệnh
 * Artisan cho người vận hành — đi qua tệp này (`__('storage.…')`).
 *
 * Luật cho mọi chuỗi ở đây: không mã tệp Drive, không bí mật, không tiêu đề tài liệu, mã hồ sơ hay
 * tên khách. Chỉ số đếm, mã `media`/`document` và khoá mờ (`<media_id>/<ulid>.<đuôi>`).
 */
return [
    'exceptions' => [
        // App\Exceptions\DocumentStorageUnavailable — lỗi TẠM THỜI; người tải thấy trang 503.
        'unavailable' => 'Kho tài liệu tạm thời chưa truy cập được. Tài liệu vẫn được lưu an toàn; vui lòng thử lại sau ít phút.',
        // App\Exceptions\StoredFileMissing — chỉ mục nói có, kho nói không. Chỉ đi vào log.
        'stored_file_missing' => 'Kho tài liệu không còn tệp có khoá :key, dù chỉ mục của CRM vẫn ghi là có.',

        // App\Exceptions\DocumentStorageMisconfigured — lỗi CẤU HÌNH; thử lại không giúp gì (R9).
        'not_configured' => 'Kho tài liệu Google Drive chưa cấu hình đủ: thiếu :missing. Báo người quản trị hệ thống.',
        'credentials_missing' => 'Chưa cấu hình đường dẫn khoá tài khoản dịch vụ Google (GOOGLE_DRIVE_CREDENTIALS_PATH).',
        'credentials_unusable' => 'Không dùng được khoá tài khoản dịch vụ Google (GOOGLE_DRIVE_CREDENTIALS_PATH): tệp không có, không đọc được, hoặc không phải khoá JSON hợp lệ của một tài khoản dịch vụ.',
        'token_rejected' => 'Google từ chối cấp quyền truy cập cho tài khoản dịch vụ (:error). Kiểm khoá JSON còn hiệu lực, và đồng hồ máy chủ đúng giờ (NTP).',
        'credentials_rejected' => 'Google Drive từ chối thông tin đăng nhập của tài khoản dịch vụ, kể cả sau khi đã lấy access token mới.',
        'container_not_found' => 'Không tìm thấy Shared Drive hoặc thư mục của kho trên Google Drive. Kiểm GOOGLE_DRIVE_SHARED_DRIVE_ID, GOOGLE_DRIVE_ROOT_FOLDER_ID, và tài khoản dịch vụ còn là thành viên của Shared Drive.',
        'drive_rejected' => 'Google Drive từ chối yêu cầu (mã :status, lý do :reason). Thử lại không giúp gì; báo người quản trị hệ thống.',
        'drive_reasons' => [
            'storageQuotaExceeded' => 'Kho Google Drive đã hết dung lượng lưu trữ.',
            'teamDriveFileLimitExceeded' => 'Shared Drive của kho đã chạm giới hạn 400.000 mục của Google.',
            'numChildrenInNonRootLimitExceeded' => 'Một thư mục của kho đã chạm giới hạn 500.000 mục con của Google.',
            'insufficientFilePermissions' => 'Tài khoản dịch vụ không đủ quyền trên kho: cần đúng vai "Người quản lý nội dung" của Shared Drive.',
            'teamDriveMembershipRequired' => 'Tài khoản dịch vụ không còn là thành viên của Shared Drive kho.',
        ],
    ],

    // App\Support\Storage\GoogleDrive — chỉ đi vào log và ngoại lệ nội bộ, không bao giờ tới người dùng.
    'drive' => [
        'api_error' => 'Google Drive :operation: HTTP :status, lý do :reason, lần thử :attempt.',
        'checksum_mismatch' => 'Google Drive :operation: tệp vừa lên kho lệch md5 hoặc kích thước so với bản gửi đi.',
        'stream_size_mismatch' => 'Luồng tải lên không đúng kích thước đã khai (khai :declared byte, đọc được :read byte).',
        'upload_protocol' => 'Google Drive trả về vị trí tải lên không hợp lệ cho phiên resumable.',
        'immutable_key' => 'Khoá đã có tệp trên kho; tệp hồ sơ là bất biến, không ghi đè (R8).',
        'index_write_failed' => 'Đã tải tệp lên kho nhưng không ghi được chỉ mục; bản vừa tải đã được cho vào thùng rác.',
        'not_indexed' => 'Khoá không có trong chỉ mục kho.',
        'trashed' => 'Tệp đã nằm trong thùng rác của kho.',
        'checksum_algo' => 'Kho chỉ cung cấp md5 do Google tính.',
        'root_directory' => 'Không xoá cả thư mục gốc của kho.',
        'log' => [
            'request_failed' => 'Google Drive: lệnh gọi thất bại.',
            'token_failed' => 'Google Drive: lấy access token thất bại.',
            'credentials_unusable' => 'Google Drive: không dùng được khoá tài khoản dịch vụ.',
            'breaker_opened' => 'Google Drive: ngắt mạch mở, tạm ngừng gọi kho.',
            'trash_after_failure' => 'Google Drive: không cho được bản tải lên hỏng vào thùng rác; tệp mồ côi, vkcrm:storage:orphans sẽ báo.',
        ],
    ],

    // M14 Task 3: đẩy tệp từ vùng đệm lên kho, dọn vùng đệm (App\Actions\Storage\PushDocumentFileToRemote,
    // App\Actions\Schedule\PurgeStagedDocumentCopies, App\Listeners\DiscardStagedCopyOnMediaDeleted).
    // Chỉ đi vào log và ngoại lệ nội bộ; ngữ cảnh log chỉ mang mã media và khoá mờ.
    'push' => [
        // App\Exceptions\StoredFileMissing::staged()
        'staged_file_missing' => 'Vùng đệm của máy chủ không còn tệp có khoá :key, dù media vẫn ghi tệp nằm ở máy chủ.',
        'checksum_mismatch' => 'Bản trên kho của khoá :key lệch md5 hoặc kích thước so với bản trong vùng đệm; đã cho bản trên kho vào thùng rác, lượt sau tải lại.',
        'write_failed' => 'Kho tài liệu không nhận bản ghi của khoá :key.',
        'log' => [
            'key_rejected' => 'Đẩy tệp lên kho: không đẩy media này — khoá không đúng khuôn <media_id>/<ULID>.<đuôi> của kho, hoặc media không nằm ở vùng đệm. Tệp ở lại máy chủ.',
            'staged_missing' => 'Đẩy tệp lên kho: media còn ghi tệp ở máy chủ mà vùng đệm không còn tệp. Không đổi đĩa.',
            'checksum_mismatch' => 'Đẩy tệp lên kho: bản trên kho lệch md5 hoặc kích thước; đã cho vào thùng rác.',
            'gone_trash_failed' => 'Đẩy tệp lên kho: media đã bị xoá nhưng không cho được bản vừa tải vào thùng rác; tệp mồ côi, vkcrm:storage:orphans sẽ báo.',
            'staged_discard_failed' => 'Không xoá được bản trong vùng đệm của một media; lượt sau thử lại hoặc người vận hành xoá tay.',
        ],
    ],

    // M14 Task 4: đọc qua CRM (App\Actions\Storage\OpenStoredFile, App\Actions\Storage\MaterialiseStoredFile).
    // Chỉ đi vào log và ngoại lệ nội bộ; ngữ cảnh log chỉ mang mã media, đĩa và khoá mờ.
    'read' => [
        'checksum_mismatch' => 'Bản tải về từ kho của khoá :key lệch md5 hoặc kích thước so với dòng media.',
        'log' => [
            'missing' => 'Đọc tệp hồ sơ: dòng media ghi tệp có mà nơi chứa không còn (kho trả 404, chỉ mục không có khoá, hay đĩa không mở được). Trả 404 cho người tải.',
            'checksum_mismatch' => 'Tải tệp từ kho về máy chủ: lệch md5 hoặc kích thước; không dùng bản tải về.',
            'read_failed' => 'Tải tệp từ kho về máy chủ: kết nối tới kho hỏng giữa lúc đọc tệp; đã xoá bản tải dở.',
            'free_space_unknown' => 'Gói bàn giao: không đo được chỗ trống của ổ đĩa (hàm disk_free_space bị tắt hoặc không trả lời); bỏ qua bước kiểm chỗ trống.',
        ],
    ],

    // M14 Task 4: trang 503 khi kho tài liệu tạm thời chưa truy cập được
    // (resources/views/errors/storage-unavailable.blade.php), cho cả nhân sự lẫn khách (R9).
    // Câu chính là `exceptions.unavailable` ở trên. Không chi tiết kỹ thuật.
    'unavailable_page' => [
        'title' => 'Kho tài liệu tạm thời chưa truy cập được',
        'heading' => 'Chưa tải được tài liệu lúc này',
        'call_lead' => 'Nếu cần gấp, anh/chị gọi văn phòng:',
        'call' => 'Gọi :hotline',
        'home' => 'Về trang chủ',
        // Rà soát cuối M14 vòng sửa 1 (I7): DocumentStorageMisconfigured — không tự hết, không "thử lại sau ít phút".
        'misconfigured_staff' => 'Kho tài liệu Google Drive đang lỗi cấu hình nên chưa tải được tài liệu. Tài liệu không mất, nhưng lỗi này không tự hết: anh/chị báo quản trị hệ thống.',
        'misconfigured_client' => 'Hệ thống lưu trữ tài liệu đang gặp sự cố nên chưa tải được tài liệu. Tài liệu không mất; văn phòng đã được báo để khắc phục.',
    ],

    // M14 Task 6: App\Exceptions\StoredFileTrashed — chỉ mục còn dòng sống mà Google báo tệp ở thùng rác.
    // Chỉ đi vào log và đầu ra `vkcrm:storage:verify`.
    'stored_file_trashed' => 'Tệp của khoá :key đang nằm trong thùng rác của Shared Drive, dù chỉ mục của CRM vẫn ghi là sống.',

    // M14 Task 6: lệnh vận hành kho (`vkcrm:storage:enable|migrate|rollback|verify|reindex|orphans|
    // destruction-list`) — App\Console\Commands\Storage*Command và các Action trong app/Actions/Storage.
    // Chỉ số đếm, mã media (#…), khoá và tên Drive mờ; không tiêu đề, không mã hồ sơ, không tên khách,
    // không mã tệp Drive.
    'commands' => [
        'invalid_option' => 'Tuỳ chọn không hợp lệ: --limit, --max-minutes, --keep-local-days và --sample phải là số nguyên dương.',

        // Lý do lỗi của một media (#id: …).
        'reasons' => [
            'staged_missing' => 'vùng đệm trên máy chủ không còn tệp',
            'rejected' => 'khoá không đúng khuôn của kho, hoặc media không nằm ở vùng đệm (tệp ở lại máy chủ)',
            'store_rejected' => 'kho từ chối bản ghi, hoặc bản trên kho lệch md5/kích thước (đã cho vào thùng rác; chạy lại sẽ tải với thế hệ mới)',
            'unavailable' => 'kho tạm thời không tới được — dừng lượt chạy',
            'misconfigured' => 'cấu hình kho hỏng (xem vkcrm:storage:check) — dừng lượt chạy',
            'missing' => 'kho không còn tệp',
            'download_failed' => 'tải về hỏng: kho đứt giữa chừng, hoặc cấu hình kho hỏng (chi tiết trong log); không đổi gì',
            'changed' => 'bản trên kho khác bản đã lưu (lệch md5 hoặc kích thước): có thể đã bị sửa trên Google Drive; không đổi gì',
            'error' => 'lỗi không lường trước (chi tiết trong log)',
        ],

        'enable' => [
            'enabled' => 'Đã bật kho tài liệu lúc :at. Từ giờ tệp MỚI tự lên kho; tệp cũ chỉ đi qua vkcrm:storage:migrate.',
            'already' => 'Kho tài liệu đã bật từ :at. Không dời mốc (dời mốc làm tệp tạo giữa hai mốc không bao giờ được đẩy).',
            'not_google_drive' => 'Không bật: công tắc DOCUMENT_STORAGE (đọc sau php artisan optimize) chưa là google_drive. Đặt DOCUMENT_STORAGE=google_drive, chạy php artisan optimize và chmod 600 bootstrap/cache/config.php, rồi chạy lại.',
            'not_ready' => 'Không bật: kiểm tra sẵn sàng của kho còn dòng ĐỎ (chạy php artisan vkcrm:storage:check để xem đủ):',
            'dossier_missing' => 'Không bật: production chưa có ngày lập/nộp hồ sơ chuyển dữ liệu ra nước ngoài, cũng chưa có ý kiến luật sư cho chuyển trước. Ghi một trong hai trên trang "Kho tài liệu" (admin), rồi chạy lại.',
        ],

        'migrate' => [
            'not_enabled' => 'Không chuyển: kho chưa bật. Đặt DOCUMENT_STORAGE=google_drive, chạy php artisan optimize, chmod 600 bootstrap/cache/config.php và php artisan vkcrm:storage:enable trước. (Chạy thử --dry-run không cần bật.)',
            'not_ready' => 'Không chuyển: kiểm tra sẵn sàng của kho còn dòng ĐỎ:',
            'dossier_missing' => 'Không chuyển: production không còn ngày lập/nộp hồ sơ chuyển dữ liệu ra nước ngoài, cũng không còn ý kiến luật sư cho chuyển trước. Ghi lại một trong hai trên trang "Kho tài liệu" (admin), rồi chạy lại.',
            'pushed' => 'Đã chuyển lên kho: :count tệp, :bytes.',
            'skipped' => 'Bỏ qua (đã ở kho, hoặc media đã bị xoá): :count.',
            'locked' => 'Đang được job khác đẩy (lượt sau sẽ thấy đã ở kho): :count.',
            'remaining' => 'Còn ở máy chủ: :count tệp. Thời gian chạy: :seconds giây.',
            'failed' => 'Không chuyển được :count tệp (vẫn ở máy chủ, không mất gì):',
            'stopped_limit' => 'Dừng sau :limit media (--limit). Chạy lại để làm tiếp phần còn lại.',
            'stopped_time' => 'Dừng vì đã quá :minutes phút (--max-minutes). Chạy lại đêm sau để làm tiếp.',
            'stopped_unavailable' => 'Dừng: kho không tới được hoặc cấu hình hỏng. Kiểm php artisan vkcrm:storage:check rồi chạy lại.',
            'stopped_disabled' => 'Dừng: kho đã bị tắt giữa chừng (công tắc hoặc mốc bật kho).',
            'done' => 'Xong: không còn tệp cũ nào chờ chuyển.',
            'dry_run_files' => 'Chạy thử — chưa chuyển gì. Tệp trên máy chủ chờ chuyển: :count tệp, :bytes.',
            'dry_run_speed' => 'Ước tính thời gian chuyển: khoảng :minutes phút (tốc độ đo bằng một tệp thăm dò 1 MiB: :speed mỗi giây).',
            'dry_run_speed_unknown' => 'Ước tính thời gian: không đo được tốc độ (kho chưa cấu hình, hoặc tệp thăm dò không tải lên được).',
            'dry_run_quota' => 'Hạn mức tải lên của Google: :quota mỗi ngày cho tài khoản dịch vụ; chuyển hết cần ít nhất :days ngày theo hạn mức đó.',
            'dry_run_free' => 'Chỗ trống trên ổ của máy chủ: :free. Bản trên máy chủ được giữ ít nhất 30 ngày sau khi chuyển, và tới khi có biên nhận văn phòng.',
            'dry_run_free_unknown' => 'Chỗ trống trên ổ của máy chủ: không đo được (hàm disk_free_space bị tắt).',
            'dry_run_nothing_written' => 'Không media nào đổi, không ghi gì vào chỉ mục.',
            'dry_run_probe_trashed' => 'Tệp thăm dò tốc độ (preflight~…) đã được cho vào thùng rác của Shared Drive.',
            'log' => [
                'store_failed' => 'Chuyển tệp cũ lên kho: kho không tới được hoặc cấu hình hỏng; dừng lượt chạy.',
                'push_failed' => 'Chuyển tệp cũ lên kho: kho từ chối hoặc bản trên kho lệch; media ở lại máy chủ.',
                'probe_failed' => 'Chạy thử chuyển tệp: không tải lên được tệp thăm dò tốc độ.',
                'probe_trash_failed' => 'Chạy thử chuyển tệp: không cho được tệp thăm dò preflight~ vào thùng rác; vkcrm:storage:orphans sẽ báo.',
            ],
        ],

        'rollback' => [
            'not_local' => 'Không quay lui, không đổi gì: công tắc chưa là DOCUMENT_STORAGE=local. Đặt DOCUMENT_STORAGE=local, chạy php artisan optimize và chmod 600 bootstrap/cache/config.php TRƯỚC — nếu không, tác vụ quét đẩy lại mọi tệp vừa quay lui trong 15 phút.',
            'local' => 'Đổi về máy chủ bằng bản còn trong vùng đệm (không cần Drive): :count tệp.',
            'downloaded' => 'Tải về từ kho, đã kiểm md5, rồi đổi về máy chủ: :count tệp, :bytes.',
            'unreachable' => 'Chưa kéo về được :count tệp cần tải từ kho vì Drive không dùng được:',
            'locked' => 'Đang bị job đẩy giữ khoá, chưa kéo về: :count tệp.',
            'failed' => 'Không kéo về được :count tệp (vẫn ở kho, không đổi gì):',
            'incomplete_retry' => 'Còn tệp ở kho chưa kéo về được vì Drive không tới được, đang bị khoá, hay tải về bị đứt. Mốc bật kho đã xoá; chạy lại lệnh này khi Drive tới được.',
            'incomplete_manual' => 'Còn tệp ở kho mà chạy lại không giúp: kho không còn tệp, hoặc bản trên kho đã bị đổi (lý do ở từng dòng trên). Kiểm bằng php artisan vkcrm:storage:verify; tệp vào thùng rác thì Manager lấy lại từ thùng rác của Shared Drive, còn lại lấy bản ở máy văn phòng theo Phụ lục D của docs/SAO-LUU-KHOI-PHUC.md.',
            'done' => 'Xong: mọi tệp đã về máy chủ. Bản trên kho và chỉ mục còn nguyên; bật lại sau này không tải lên lần hai.',
            'rename_failed' => 'Không đặt được tệp vừa tải về vào chỗ của khoá :key.',
            'log' => [
                'download_failed' => 'Quay lui: tải tệp từ kho về hỏng (lệch md5 hoặc kích thước, hoặc kho đứt); media ở lại kho.',
            ],
        ],

        'verify' => [
            'checked' => 'Đã kiểm :checked trên :total tệp đang ở kho.',
            'ok' => 'Tệp khớp md5 và kích thước: :count',
            'changed' => 'Tệp bị đổi trên kho (md5 hoặc kích thước khác dòng media): :ids',
            'trashed' => 'Tệp đã vào thùng rác của Shared Drive: :ids',
            'missing' => 'Tệp thiếu trên kho: :ids',
            'failed' => 'Không kiểm được (kho không tới được, hoặc media thiếu checksum_md5): :ids',
            'destroyed' => 'Tệp của vụ đã ghi quyết định huỷ (nhóm riêng, không tính là lỗi): :ids',
            'problems' => 'Có tệp cần xem. Tệp vào thùng rác: Manager lấy lại từ thùng rác của Shared Drive. Tệp thiếu hay bị đổi: không có lệnh khôi phục riêng từng tệp — lấy bản ở máy văn phòng theo Phụ lục D của docs/SAO-LUU-KHOI-PHUC.md và báo người cài đặt đặt lại đúng khoá trên kho.',
            'clean' => 'Sạch: mọi tệp đã kiểm đều khớp.',
        ],

        'reindex' => [
            'mismatch' => 'Không dựng lại: --drive và --root phải bằng GOOGLE_DRIVE_SHARED_DRIVE_ID và GOOGLE_DRIVE_ROOT_FOLDER_ID đang cấu hình. Đổi .env, chạy php artisan optimize và chmod 600 bootstrap/cache/config.php trước.',
            'unreachable' => 'Không liệt kê được kho trên Drive: :error',
            'heading' => 'Đã dựng lại chỉ mục từ :files tệp trên Drive.',
            'heading_dry_run' => 'Chạy thử — không ghi gì. Danh sách Drive có :files tệp.',
            'created' => 'Dòng chỉ mục thêm mới: :count',
            'revived' => 'Dòng đã rời được đưa lại vào chỉ mục (tệp được lấy lại từ thùng rác): :count',
            'superseded' => 'Dòng cũ nhường chỗ (superseded): :count',
            'unchanged' => 'Dòng chỉ mục giữ nguyên: :count',
            'folders_added' => 'Thư mục tháng thêm vào drive_folders: :count',
            'duplicate_folders' => 'Thư mục tháng trùng tên trên Drive (giữ một): :count',
            'other_folders' => 'Thư mục khác khuôn tháng (không duyệt): :count',
            'unknown' => 'Tên lạ, không đọc ngược được (chỉ đếm, không in tên): :count',
            'probes' => 'Tệp thăm dò preflight (bỏ qua): :count',
            'no_media' => 'Tệp không còn media (không ghi; xem vkcrm:storage:orphans): :count',
            'no_checksum' => 'Media không có checksum_md5 để so (không chọn): :count',
            'md5_mismatch' => 'Không tệp nào khớp md5 của media (không ghi): :count',
            'not_chosen' => 'Tệp không được chọn (trùng tên, hay thế hệ khác; không ghi, không xoá): :count',
        ],

        'orphans' => [
            'not_configured' => 'kho chưa cấu hình GOOGLE_DRIVE_SHARED_DRIVE_ID/GOOGLE_DRIVE_ROOT_FOLDER_ID',
            'index_without_media' => 'Dòng chỉ mục sống không có media (:count)',
            'media_without_file' => 'Media trên kho mà chỉ mục không có tệp (:count)',
            'drive_unindexed' => 'Tệp trên Drive không có trong chỉ mục (:count)',
            'drive_unindexed_unknown' => 'Tệp trên Drive tên lạ, không có trong chỉ mục (chỉ đếm, không in tên): :count',
            'duplicates' => 'Tệp trùng tên trên Drive (:count)',
            'duplicates_unknown' => 'Tên lạ trùng trên Drive (chỉ đếm): :count',
            'staged_orphans' => 'Thư mục vùng đệm không còn media, trên máy chủ (:count)',
            'probes' => 'Tệp thăm dò preflight còn sống — chỉ là thông tin, cho vào thùng rác tay (:count)',
            'destroyed' => 'Media của vụ đã ghi quyết định huỷ, thiếu bản trên kho — không tính là lỗi (:count)',
            'listing_failed' => 'Không liệt kê được kho trên Drive (:error); các nhóm cần danh sách Drive chưa được kiểm.',
            'problems' => 'Có tệp lệch cần xem. Lệnh này không xoá gì: xem docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md.',
            'clean' => 'Sạch: không tệp mồ côi, không tệp thiếu, không tên trùng.',
        ],

        'destruction' => [
            'not_found' => 'Không tìm thấy vụ việc có mã đã nhập.',
            'not_destroyed' => 'Không liệt kê: vụ này chưa ghi quyết định tiêu huỷ (trang vụ việc, hành động "Ghi quyết định tiêu huỷ").',
            'forbidden' => 'Không liệt kê: --by phải là email của một quản trị viên đang hoạt động.',
            'drive_names' => 'Tên tệp trên Shared Drive cần xoá vĩnh viễn, kể cả trong thùng rác (:count):',
            'staged_paths' => 'Tệp còn trong vùng đệm trên máy chủ web cần xoá (:count):',
            'office_paths' => 'Đường trong kho mã hoá của máy văn phòng cần rclone deletefile (:count):',
            'footer' => 'CRM không xoá gì. Biên bản huỷ ghi đủ bốn nơi (Drive, máy văn phòng, máy chủ web, archive sao lưu) — xem docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md.',
        ],
    ],
];
