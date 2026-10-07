<?php

use App\Actions\Matter\CollectHandoverEntries;

/**
 * Phần `<tên an toàn>.<đuôi>` của tên entry trong gói bàn giao (M7 Task 4, R8) — vòng sửa 1 (I1).
 * `BuildHandoverPackageTest` khẳng định trên zip THẬT; tệp này phủ những đầu vào không đi qua DB
 * được (UTF-8 hỏng — MariaDB strict từ chối nó ở cột `title`) và các luật biên của hàm đặt tên.
 */
it('đặt tên entry từ tiêu đề và đuôi của tệp thật', function (string $title, string $storedFileName, string $expected) {
    expect(CollectHandoverEntries::entryName($title, $storedFileName))->toBe($expected);
})->with([
    'ngày có dấu chấm, phần sau dấu chấm cuối dài hơn 20 byte' => [
        'Biên bản làm việc ngày 05.3.2026 với Toà án nhân dân quận Hải Châu', 'x.pdf',
        'Biên bản làm việc ngày 05.3.2026 với Toà án nhân dân quận Hải Châu.pdf',
    ],
    'byte thứ 20 sau dấu chấm cuối rơi giữa chữ "ư"' => [
        'Đơn. Yêu cầu bồi thường thiệt hại', 'x.pdf',
        'Đơn. Yêu cầu bồi thường thiệt hại.pdf',
    ],
    '"TP." và "v.v." — dấu chấm cuối bị gọt, không còn hai dấu chấm liền' => [
        'Công văn của UBND TP. Đà Nẵng, v.v.', 'x.pdf',
        'Công văn của UBND TP. Đà Nẵng, v.v.pdf',
    ],
    'tiêu đề tự mang đúng đuôi của tệp thì không lặp đuôi' => ['Đơn khởi kiện.PDF', 'x.pdf', 'Đơn khởi kiện.pdf'],
    'gạch chéo, nháy kép, ký tự Windows cấm' => [
        'Bản án số 12/2024/DS-ST: "kết luận"?', 'x.pdf',
        'Bản án số 12-2024-DS-ST- kết luận-.pdf',
    ],
    'tổ hợp NFD được dựng lại NFC' => ["Đơn kho\u{031B}\u{0309}i kiện", 'x.docx', 'Đơn khởi kiện.docx'],
    'UTF-8 hỏng: byte lạc thành "-", phần còn lại vẫn được dựng NFC' => [
        "Đơn\xC6 kho\u{031B}\u{0309}i kiện", 'x.pdf',
        'Đơn- khởi kiện.pdf',
    ],
    'tệp thật không có đuôi: tiêu đề có dấu chấm vẫn nguyên' => ['Biên bản ngày 05.3.2026', 'khong-duoi', 'Biên bản ngày 05.3.2026'],
    'tiêu đề chỉ có dấu chấm' => ['..', 'x.pdf', 'tep-tai-len.pdf'],
]);

it('tiêu đề dài: cắt ở ranh giới ký tự, phần còn lại là phần ĐẦU của tiêu đề, tối đa 100 ký tự', function () {
    // 202 ký tự, 254 byte; dấu chấm cuối ở "TP." của lần lặp thứ tư.
    $title = rtrim(str_repeat('Biên bản hoà giải ngày 05.3.2026 tại TP. Đà Nẵng, ', 4), ', ');

    $name = CollectHandoverEntries::entryName($title, 'x.pdf');
    $stem = substr($name, 0, -strlen('.pdf'));

    expect($name)->toEndWith('.pdf')
        ->and(mb_check_encoding($name, 'UTF-8'))->toBeTrue()
        ->and(mb_strlen($stem))->toBeLessThanOrEqual(100)
        ->and(str_starts_with($title, $stem))->toBeTrue();
});
