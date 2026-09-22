<?php

/**
 * Xưng hô của màn hình nhập mã một lần (SPEC §8.1).
 *
 * Bản `vi` bundled của Filament gọi người dùng là "bạn" ở hai khoá dưới đây, trong khi mọi dòng
 * khác của cổng — `lang/vi/portal.php`, `portal_matters.php`, `portal_progress.php`,
 * `portal_submit.php`, `requests.php` — gọi "anh/chị". Với một văn phòng luật ở Việt Nam thì đó
 * không phải chuyện văn phong mà là chuyện lễ độ với một người vừa đem việc của mình tới, và nó
 * lệch ngay ở màn hình ĐẦU TIÊN của toàn hệ thống.
 *
 * Chỉ chứa hai khoá cần đổi: ghi đè của Laravel TRỘN đệ quy chứ không thay thế cả tệp
 * (`FileLoader::loadNamespaceOverrides()` dùng `array_replace_recursive`), nên phần còn lại của
 * gói vẫn nguyên.
 *
 * **Điểm mù mà khoá này phơi ra.** `LocalizationTest` được viết lại ở mốc này để bắt khoá THIẾU
 * và khoá còn nguyên tiếng Anh: nó đi từ phía `en` của mỗi gói rồi hỏi bộ dịch xem `vi` trả về
 * gì. Cấu trúc ấy KHÔNG THỂ nhìn thấy một chuỗi bundled đã có bản `vi`, dịch đúng nghĩa, nhưng
 * sai xưng hô — với nó, khoá ấy đã xong. Thứ bắt được loại lỗi này là một test RENDER màn hình
 * thật rồi đọc chữ trên đó; `LoginTest` nay có một, và mọi màn hình cổng khác nên có một dòng
 * như vậy khi ai đó đi qua chúng.
 */
return [

    'multi_factor' => [

        'subheading' => 'Để tiếp tục đăng nhập, anh/chị xác minh danh tính giúp chúng tôi.',

        'form' => [

            'provider' => [
                'label' => 'Anh/chị muốn xác minh bằng cách nào?',
            ],

        ],

    ],

];
