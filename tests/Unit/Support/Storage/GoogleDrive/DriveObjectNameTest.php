<?php

use App\Support\Storage\GoogleDrive\DriveObjectName;

/*
|--------------------------------------------------------------------------
| M14 Task 2 — tên tệp trên Drive (kế hoạch M14, R4)
|--------------------------------------------------------------------------
|
| Tên = khoá với `/` thay bằng `~`; thế hệ 1 không hậu tố, thế hệ N ≥ 2 thêm `~g<N>` trước đuôi.
| Tên đảo ngược được (dựng lại chỉ mục, nhập biên nhận văn phòng chỉ có tên trong tay), nên
| `parse()` chỉ nhận ĐÚNG khuôn của khoá thư viện media; tên lạ → null, không đoán.
*/

const DRIVE_NAME_ULID = '01k6xq0f9m2y7c4w8r3t5v6n1b';

it('fromKey: / thành ~, thế hệ 1 không hậu tố', function () {
    expect(DriveObjectName::fromKey('1834/'.DRIVE_NAME_ULID.'.pdf'))->toBe('1834~'.DRIVE_NAME_ULID.'.pdf')
        ->and(DriveObjectName::fromKey('1834/'.DRIVE_NAME_ULID.'.pdf', 1))->toBe('1834~'.DRIVE_NAME_ULID.'.pdf');
});

it('fromKey: thế hệ N ≥ 2 thêm ~g<N> trước đuôi', function () {
    expect(DriveObjectName::fromKey('1834/'.DRIVE_NAME_ULID.'.pdf', 2))->toBe('1834~'.DRIVE_NAME_ULID.'~g2.pdf')
        ->and(DriveObjectName::fromKey('1834/'.DRIVE_NAME_ULID.'.pdf', 17))->toBe('1834~'.DRIVE_NAME_ULID.'~g17.pdf')
        ->and(DriveObjectName::fromKey('1834/'.DRIVE_NAME_ULID.'.pdf', 999))->toBe('1834~'.DRIVE_NAME_ULID.'~g999.pdf');
});

it('fromKey: khoá không đuôi thì hậu tố ở cuối; khoá nhiều tầng chỉ lấy đuôi của tầng cuối', function () {
    expect(DriveObjectName::fromKey('18/'.DRIVE_NAME_ULID, 3))->toBe('18~'.DRIVE_NAME_ULID.'~g3')
        ->and(DriveObjectName::fromKey('18/conversions/x.jpg', 2))->toBe('18~conversions~x~g2.jpg')
        ->and(DriveObjectName::fromKey('ho.so/'.DRIVE_NAME_ULID, 2))->toBe('ho.so~'.DRIVE_NAME_ULID.'~g2')
        ->and(DriveObjectName::fromKey('preflight/a1b2.txt'))->toBe('preflight~a1b2.txt');
});

it('fromKey từ chối khoá chứa ~ và thế hệ ngoài 1–999 (tên phải đảo ngược được)', function (string $key, int $generation) {
    expect(fn () => DriveObjectName::fromKey($key, $generation))->toThrow(InvalidArgumentException::class);
})->with([
    'khoá có ~' => ['18/a~b.pdf', 1],
    'thế hệ 0' => ['18/'.DRIVE_NAME_ULID.'.pdf', 0],
    'thế hệ âm' => ['18/'.DRIVE_NAME_ULID.'.pdf', -1],
    'thế hệ 1000' => ['18/'.DRIVE_NAME_ULID.'.pdf', 1000],
]);

it('parse đọc ngược đúng khoá và thế hệ', function (string $name, string $key, int $generation) {
    expect(DriveObjectName::parse($name))->toBe(['key' => $key, 'generation' => $generation]);
})->with([
    'thế hệ 1, có đuôi' => ['1834~'.DRIVE_NAME_ULID.'.pdf', '1834/'.DRIVE_NAME_ULID.'.pdf', 1],
    'thế hệ 2' => ['1834~'.DRIVE_NAME_ULID.'~g2.pdf', '1834/'.DRIVE_NAME_ULID.'.pdf', 2],
    'thế hệ 9' => ['7~'.DRIVE_NAME_ULID.'~g9.zip', '7/'.DRIVE_NAME_ULID.'.zip', 9],
    'thế hệ 10' => ['7~'.DRIVE_NAME_ULID.'~g10.zip', '7/'.DRIVE_NAME_ULID.'.zip', 10],
    'thế hệ 999' => ['7~'.DRIVE_NAME_ULID.'~g999.zip', '7/'.DRIVE_NAME_ULID.'.zip', 999],
    'không đuôi' => ['18~'.DRIVE_NAME_ULID, '18/'.DRIVE_NAME_ULID, 1],
    'không đuôi, thế hệ 3' => ['18~'.DRIVE_NAME_ULID.'~g3', '18/'.DRIVE_NAME_ULID, 3],
    'đuôi 8 ký tự' => ['18~'.DRIVE_NAME_ULID.'.abcdefgh', '18/'.DRIVE_NAME_ULID.'.abcdefgh', 1],
]);

it('parse trả null cho tên lạ', function (string $name) {
    expect(DriveObjectName::parse($name))->toBeNull();
})->with([
    'thăm dò preflight' => ['preflight~x.txt'],
    'ULID viết hoa' => ['18~'.strtoupper(DRIVE_NAME_ULID).'.pdf'],
    'đuôi viết hoa' => ['18~'.DRIVE_NAME_ULID.'.PDF'],
    'thế hệ g1 (thế hệ 1 không hậu tố)' => ['18~'.DRIVE_NAME_ULID.'~g1.pdf'],
    'thế hệ g01' => ['18~'.DRIVE_NAME_ULID.'~g01.pdf'],
    'thế hệ g0' => ['18~'.DRIVE_NAME_ULID.'~g0.pdf'],
    'thế hệ g1000' => ['18~'.DRIVE_NAME_ULID.'~g1000.pdf'],
    'ULID 25 ký tự' => ['18~'.substr(DRIVE_NAME_ULID, 1).'.pdf'],
    'ULID 27 ký tự' => ['18~'.DRIVE_NAME_ULID.'a.pdf'],
    'đuôi 9 ký tự' => ['18~'.DRIVE_NAME_ULID.'.abcdefghi'],
    'mã media không phải số' => ['ab~'.DRIVE_NAME_ULID.'.pdf'],
    'thiếu mã media' => ['~'.DRIVE_NAME_ULID.'.pdf'],
    'thư mục con' => ['18~conversions~x.jpg'],
    'dấu / còn trong tên' => ['18/'.DRIVE_NAME_ULID.'.pdf'],
    'thừa ký tự đầu' => ['x18~'.DRIVE_NAME_ULID.'.pdf'],
    'xuống dòng cuối' => ['18~'.DRIVE_NAME_ULID.".pdf\n"],
    'rỗng' => [''],
]);

it('fromKey rồi parse trả lại đúng khoá và thế hệ', function (int $generation) {
    $key = '1834/'.DRIVE_NAME_ULID.'.pdf';

    expect(DriveObjectName::parse(DriveObjectName::fromKey($key, $generation)))
        ->toBe(['key' => $key, 'generation' => $generation]);
})->with([1, 2, 9, 10, 99, 100, 999]);
