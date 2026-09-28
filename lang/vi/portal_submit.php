<?php

/**
 * Chuỗi của màn hình nộp giấy tờ trên cổng khách hàng — SPEC §8.4.
 *
 * **Tệp riêng, không phải `lang/vi/portal.php`.** Bốn agent cùng làm M5 trên một nhánh; phán
 * quyết của người điều phối giao `portal.php` cho Task 1, `portal_matters.php` cho Task 3,
 * `portal_progress.php` cho Task 4 và tệp này cho Task 5, để không ai phải sửa tệp của người
 * khác — đúng chỗ M3 đã va nhau.
 *
 * **Người đọc là một khách hàng đang đứng ở sân uỷ ban phường, cầm điện thoại, vừa chụp xong một
 * tờ giấy.** SPEC §8 cấm thuật ngữ kỹ thuật và từ viết tắt; SPEC §8.4 đi xa hơn và cấm đích danh
 * kiểu thông điệp "Upload failed": **mỗi câu từ chối phải nói ra việc cần làm tiếp theo.** Đó là
 * lý do những câu ở `errors.*` dưới đây dài hơn một thông báo lỗi thường thấy — một câu ngắn gọn
 * mà người đọc không biết làm gì với nó thì bằng không có câu nào.
 *
 * **Những câu từ chối của tầng tệp KHÔNG nằm ở đây.** `FileGuard` và `VirusScanner` đã có bộ câu
 * riêng ở `lang/vi/documents.php` (`file_guard.*`), viết cho đúng người đọc này, và màn hình này
 * hiển thị thẳng chúng. Chép lại một bản thứ hai ở đây là dựng sẵn hai câu trả lời sẽ lệch nhau.
 * Cùng lý lẽ đó, `errors.too_large` KHÔNG tồn tại: ô chọn tệp mượn thẳng
 * `documents.file_guard.too_large` làm thông điệp cho luật `max:` của mình, nên khách đọc **cùng
 * một câu** dù lời từ chối đến từ luật của ô hay từ `FileGuard` phía sau.
 *
 * `errors.upload_failed` là ngoại lệ duy nhất, và nó không phải một bản chép: nó trả lời một
 * tình huống mà tới lúc ấy KHÔNG CÒN BIẾT lý do — một lời từ chối của endpoint tải lên Livewire,
 * đã dịch xong, không còn tên luật nào để đọc. `too_large` khẳng định một điều; câu kia nêu hai
 * khả năng. Hai câu khác nhau vì hai mức chắc chắn khác nhau, không vì hai người viết khác nhau.
 *
 * Trạng thái đầu mục cũng không được chép lại: `portal_progress.checklist.status.*` là bản viết
 * cho khách của Task 4, và hai màn hình cạnh nhau gọi cùng một thứ bằng hai cái tên là cách chắc
 * chắn nhất làm khách tưởng đó là hai thứ.
 *
 * Xưng hô giữ đúng giọng của các tệp portal khác: "anh/chị" cho người đọc, "chúng tôi" cho văn
 * phòng.
 */
return [

    'title' => 'Gửi giấy tờ — hồ sơ :code',
    'heading' => 'Gửi giấy tờ',
    'subheading' => ':title',

    'back' => 'Quay lại hồ sơ',

    /*
     * Bốn bước của SPEC §8.4, đúng thứ tự: chọn đầu mục → tải tệp lên → xem trước → gửi.
     */
    'steps' => [

        'item' => [
            'heading' => 'Bước 1 — Chọn giấy tờ anh/chị muốn gửi',
            'lead' => 'Anh/chị bấm vào tên giấy tờ bên dưới.',
            'chosen' => 'Anh/chị đang gửi:',
            'change' => 'Chọn giấy tờ khác',
            /*
             * Không bao giờ là một danh sách rỗng không lời giải thích (tài liệu bộ công cụ §4).
             * Một hồ sơ chưa có đầu mục nào là chuyện bình thường ở giai đoạn đầu.
             */
            'empty' => 'Hồ sơ này chưa cần giấy tờ nào từ anh/chị. Khi cần, chúng tôi sẽ ghi vào hồ sơ và báo cho anh/chị.',
        ],

        'file' => [
            'heading' => 'Bước 2 — Chụp ảnh hoặc chọn tệp',
            'label' => 'Ảnh chụp hoặc tệp giấy tờ',
            /*
             * Câu hướng dẫn nói ra CẢ HAI đường vào (chụp ngay, hoặc chọn tệp có sẵn) vì trên
             * điện thoại thuộc tính `capture` chỉ là một GỢI Ý cho trình duyệt: có máy mở thẳng
             * máy ảnh, có máy mở bộ chọn tệp kèm nút máy ảnh. Khách không cần biết chuyện đó,
             * nhưng họ cần biết rằng cả hai cách đều được.
             */
            'help' => 'Anh/chị chụp thẳng bằng điện thoại, hoặc chọn một tệp có sẵn. Nhận ảnh (JPG, PNG), tệp PDF, hoặc tệp Word/Excel, mỗi tệp tối đa :max MB. Chụp đủ cả bốn góc trang, dưới ánh sáng tự nhiên thì chữ rõ nhất.',
            // Bước 2 khi bước 1 chưa xong. Không dùng lại câu của bước 1 ("bấm vào tên giấy tờ
            // bên dưới"): ở đây danh sách nằm PHÍA TRÊN, nên chữ "bên dưới" chỉ sai chỗ.
            'choose_item_first' => 'Anh/chị chọn giấy tờ ở bước 1 phía trên, rồi ô chụp ảnh sẽ hiện ra ở đây.',
        ],

        'preview' => [
            'heading' => 'Bước 3 — Xem lại trước khi gửi',
            /*
             * SPEC §8.4 đặt bước này TRƯỚC bước gửi vì một lý do rất cụ thể: một khách chụp nhầm
             * trang phải thấy điều đó bây giờ, không phải ba ngày sau khi bị từ chối.
             */
            'lead' => 'Anh/chị xem lại ảnh vừa chụp: có đúng trang giấy cần gửi không, có đọc được chữ không?',
            'none' => 'Anh/chị chưa chọn tệp nào.',
            'file' => 'Tệp sẽ gửi: :name (:size)',
        ],

        'send' => [
            'heading' => 'Bước 4 — Gửi cho văn phòng',
            'button' => 'Gửi cho văn phòng',
            /*
             * SPEC §10.9: tài khoản bị vô hiệu hoá. Người đang không dùng được tài khoản cần một
             * con đường KHÔNG đi qua tài khoản, nên câu này chỉ tới điện thoại văn phòng.
             */
            'locked' => 'Tài khoản này đang tạm ngưng nên chưa gửi giấy tờ được. Anh/chị liên hệ văn phòng theo số :hotline để được mở lại.',
        ],

    ],

    /*
     * Sau khi gửi — SPEC §8.4 nguyên văn: 'hiện trạng thái "Đang chờ văn phòng kiểm tra"'.
     * Câu chữ giữ đúng bản của `portal_progress.checklist.status.pending_review`, vì khách sẽ
     * nhìn thấy đúng dòng đó ở khối "Hồ sơ giấy tờ" ngay sau khi quay lại.
     */
    'done' => [
        'heading' => 'Chúng tôi đã nhận được',
        'status' => 'Đang chờ văn phòng kiểm tra',
        // M6 Task 3: thư `client.document_rejected` giờ CÓ THẬT — câu cũ chỉ hứa "văn phòng sẽ
        // liên hệ khi cần" (mơ hồ, không nói bằng cách nào); giờ nói đúng: một email sẽ tới nếu
        // có vấn đề, cùng lý do hiện trên trang tiến độ.
        'body' => 'Chúng tôi đã nhận ":name" và sẽ kiểm tra trong thời gian sớm nhất. Nếu có gì chưa ổn, chúng tôi sẽ gửi email nêu rõ lý do, và lý do đó cũng hiện trên trang tiến độ hồ sơ để anh/chị gửi lại.',
        'another' => 'Gửi thêm giấy tờ khác',
    ],

    /*
     * Lối vào từ khối "Hồ sơ giấy tờ" của trang chi tiết (SPEC §8.3 mục 4). Hai nhãn khác nhau
     * vì hai việc khác nhau: gửi lần đầu, và gửi lại sau khi văn phòng đã nói rõ vì sao chưa
     * nhận được.
     */
    'entry' => [
        'submit' => 'Gửi giấy tờ này',
        'resubmit' => 'Gửi lại giấy tờ này',
    ],

    'errors' => [

        'no_item' => 'Anh/chị chọn giấy tờ muốn gửi ở bước 1 trước đã.',

        /*
         * Vòng sửa 1 (Minor): một LÔ (một lần bấm Gửi) không được vượt quá đúng mức 20 tệp/giờ
         * mà SPEC §10.3 đã đặt — một lô lớn hơn thế không bao giờ gửi trót lọt dù có chờ bao
         * lâu, nên chặn ngay ở `SubmitDocument::submit()`, trước khi đọc/ghi tệp nào, thay vì để
         * khách chờ rồi mới nghe "đã dùng hết mức 20 tệp/giờ" — câu đó đúng nhưng trả lời sai
         * câu hỏi: khách chưa dùng suất nào cả, họ chỉ chọn quá nhiều tệp trong MỘT lần.
         */
        'too_many_files_per_submission' => 'Một lần gửi chỉ nhận tối đa :limit tệp. Anh/chị bớt bớt tệp trong lần này, và gửi phần còn lại ở một lần khác.',

        /*
         * Lời từ chối của luật `mimetypes` ở ô chọn tệp, tức TRƯỚC khi `FileGuard` được hỏi.
         * Câu mặc định của framework ("The file field must be a file of type: …") nói tên MIME
         * cho một người không bao giờ cần biết MIME là gì. Nội dung câu này giữ đúng nghĩa với
         * `documents.file_guard.extension_not_allowed`, chỉ khác ở chỗ nó không nêu được đuôi
         * tệp — ở thời điểm đó chưa có gì để nêu.
         */
        'file_type' => 'Tệp này không gửi lên được. Chỉ nhận ảnh (JPG, JPEG, PNG), tệp PDF, hoặc tệp Word/Excel (DOC, DOCX, XLS, XLSX). Nếu đây là ảnh chụp màn hình hoặc ảnh từ ứng dụng khác, anh/chị lưu lại thành JPG hoặc PNG rồi gửi lại.',

        'file_required' => 'Anh/chị chụp ảnh hoặc chọn một tệp ở bước 2 trước khi gửi.',

        /*
         * Lời từ chối đến từ ENDPOINT TẢI LÊN của Livewire — một chặng mà màn hình này không
         * bọc, nên câu mặc định của nó ("data.file không được lớn hơn 20480 kilobyte") vừa đọc
         * ra một tên thuộc tính, vừa đếm bằng kilobyte, vừa đứng ngay dưới dòng chữ nói "tối đa
         * 20 MB". `SubmitDocument::_uploadErrored()` chặn đường đó lại và thay bằng câu này.
         *
         * Câu này KHÔNG đoán lý do, và đó là một quyết định chứ không phải một chỗ lười: tới
         * đây chỉ còn một thân JSON đã dịch, không còn tên luật nào để đọc. Nên nó nói ra cả
         * hai lý do có thật theo đúng thứ tự khả năng, mỗi lý do kèm việc phải làm — và kết
         * bằng một con đường không đi qua màn hình này.
         *
         * Dải trên :max MB thì không tới được đây: {@see SubmitDocument::_startUpload()} đã trả
         * lời bằng `documents.file_guard.too_large` trước khi một byte nào rời khỏi điện thoại.
         */
        'upload_failed' => 'Tệp này chưa lên được. Thường là do tệp lớn hơn :max MB, hoặc do sóng bị gián đoạn giữa chừng. Anh/chị thử chụp lại ở chế độ ảnh thường thay vì HDR, gửi từng trang một, hoặc chờ sóng ổn định rồi chọn lại tệp. Nếu vẫn không được, anh/chị gọi cho văn phòng theo số :hotline.',

        /*
         * SPEC §10.3: 20 tệp / giờ / tài khoản. Câu này phải nói ra CẢ con số CẢ đường đi tiếp,
         * vì người gặp nó thường đang gửi một xấp giấy tờ thật chứ không phải đang phá hệ thống.
         *
         * **HAI câu cho HAI cửa, và đó là điều kiện để mỗi câu nói thật.** Hai bộ đếm đo hai
         * việc khác nhau (xem docblock `SubmitDocument`): một cái đếm số tệp được CHỌN, một cái
         * đếm số lần bấm GỬI. Dùng chung một câu thì cửa thứ nhất nói với khách rằng họ "đã gửi
         * 20 tệp" trong khi chưa tệp nào được gửi — và người đọc câu đó sẽ đi tìm xem mình vừa
         * gửi những gì, ở một màn hình không có gì để tìm.
         *
         * `:limit` chứ không viết số ra: `SubmitDocument::FILES_PER_HOUR` là chỗ duy nhất giữ
         * con số ấy.
         */
        'rate_limited_upload' => 'Anh/chị đã chọn :limit tệp trong một giờ vừa rồi — đây là mức tối đa hệ thống nhận. Anh/chị chờ khoảng :minutes phút rồi chọn tệp tiếp, hoặc gọi cho văn phòng theo số :hotline nếu cần gửi gấp.',

        'rate_limited' => 'Anh/chị đã bấm gửi :limit lần trong một giờ vừa rồi — đây là mức tối đa hệ thống nhận. Anh/chị chờ khoảng :minutes phút rồi gửi tiếp, hoặc gọi cho văn phòng theo số :hotline nếu cần gửi gấp.',

    ],

];
