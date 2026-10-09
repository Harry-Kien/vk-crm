<?php

use App\Exceptions\OfficeReceiptRejected;
use App\Support\Storage\OfficeReceipt;
use Carbon\CarbonImmutable;
use Tests\Support\FakeGoogleDrive;
use Tests\Support\OfficeReceiptFixtures as Receipts;

/*
|--------------------------------------------------------------------------
| M14 Task 7 — `OfficeReceipt::parse()`: khuôn của biên nhận văn phòng (kế hoạch R10)
|--------------------------------------------------------------------------
|
| Khuôn sai là lỗi của chính máy văn phòng (script hỏng, tệp bị cắt, tệp lạ) và làm CẢ tệp bị từ
| chối: không dòng nào của nó được tin. Tên tệp lạ thì KHÔNG phải lỗi khuôn — tên đến từ Drive, và
| kho có thể có tệp không thuộc thư viện media (tệp thăm dò của preflight) — dòng đó chỉ được đếm.
*/

function t7Parse(string $json): OfficeReceipt
{
    return OfficeReceipt::parse($json, FakeGoogleDrive::DRIVE_ID, FakeGoogleDrive::ROOT_FOLDER_ID, CarbonImmutable::parse('2026-10-07T08:00:00+07:00'));
}

function t7Valid(array $overrides = [], ?array $files = null): string
{
    return Receipts::receipt($files ?? [['name' => '1834~01k6xq0f9m2y7c4w8r3t5v6n1b~g2.pdf', 'md5' => str_repeat('a', 32), 'size' => 0]], array_merge([
        'started_at' => '2026-10-06T18:00:00Z',
    ], $overrides));
}

it('đọc biên nhận hợp lệ: số lỗi, giờ bắt đầu, và từng dòng với khoá + thế hệ đọc ngược', function () {
    $receipt = t7Parse(t7Valid(['errors' => 2], [
        ['name' => '1834~01k6xq0f9m2y7c4w8r3t5v6n1b~g2.pdf', 'md5' => str_repeat('a', 32), 'size' => 0],
        ['name' => 'preflight~01k6xq0f9m2y7c4w8r3t5v6n1b.txt', 'md5' => str_repeat('b', 32), 'size' => 7],
    ]));

    expect($receipt->errors)->toBe(2)
        ->and($receipt->startedAt->equalTo(CarbonImmutable::parse('2026-10-06T18:00:00Z')))->toBeTrue()
        ->and($receipt->files)->toBe([
            ['name' => '1834~01k6xq0f9m2y7c4w8r3t5v6n1b~g2.pdf', 'key' => '1834/01k6xq0f9m2y7c4w8r3t5v6n1b.pdf', 'generation' => 2, 'md5' => str_repeat('a', 32), 'size' => 0],
            ['name' => 'preflight~01k6xq0f9m2y7c4w8r3t5v6n1b.txt', 'key' => null, 'generation' => null, 'md5' => str_repeat('b', 32), 'size' => 7],
        ]);
});

it('từ chối cả tệp khi khuôn sai', function (string $json) {
    expect(fn () => t7Parse($json))->toThrow(OfficeReceiptRejected::class);
})->with([
    'không phải JSON' => fn () => '{"format": 1,',
    'JSON không phải object' => fn () => '[1, 2]',
    'JSON là một số' => fn () => '5',
    'JSON là null' => fn () => 'null',
    'format 2' => fn () => t7Valid(['format' => 2]),
    'format "1" (chuỗi)' => fn () => t7Valid(['format' => '1']),
    'thiếu format' => fn () => json_encode(array_diff_key(json_decode(t7Valid(), true), ['format' => 1])),
    'kho không phải object' => fn () => t7Valid(['kho' => 'x']),
    'team_drive khác' => fn () => t7Valid(['kho' => ['team_drive' => '0AKhac', 'root_folder_id' => FakeGoogleDrive::ROOT_FOLDER_ID]]),
    'thiếu team_drive' => fn () => t7Valid(['kho' => ['root_folder_id' => FakeGoogleDrive::ROOT_FOLDER_ID]]),
    'root_folder_id khác' => fn () => t7Valid(['kho' => ['team_drive' => FakeGoogleDrive::DRIVE_ID, 'root_folder_id' => '1Khac']]),
    'thiếu started_at' => fn () => json_encode(array_diff_key(json_decode(t7Valid(), true), ['started_at' => 1])),
    'started_at không phải ISO-8601' => fn () => t7Valid(['started_at' => 'hôm qua']),
    'started_at thiếu múi giờ' => fn () => t7Valid(['started_at' => '2026-10-06T18:00:00']),
    'started_at ở tương lai quá 5 phút' => fn () => t7Valid(['started_at' => '2026-10-07T01:05:01Z']),
    'errors âm' => fn () => t7Valid(['errors' => -1]),
    'errors là chuỗi' => fn () => t7Valid(['errors' => '0']),
    'files không phải danh sách' => fn () => t7Valid(['files' => ['a' => ['name' => 'x', 'md5' => str_repeat('a', 32), 'size' => 1]]]),
    'thiếu files' => fn () => json_encode(array_diff_key(json_decode(t7Valid(), true), ['files' => 1])),
    'dòng không phải object' => fn () => t7Valid(files: ['1834~01k6xq0f9m2y7c4w8r3t5v6n1b.pdf']),
    'tên rỗng' => fn () => t7Valid(files: [['name' => '', 'md5' => str_repeat('a', 32), 'size' => 1]]),
    'tên không phải chuỗi' => fn () => t7Valid(files: [['name' => 5, 'md5' => str_repeat('a', 32), 'size' => 1]]),
    'md5 chữ hoa' => fn () => t7Valid(files: [['name' => 'x', 'md5' => str_repeat('A', 32), 'size' => 1]]),
    'md5 31 ký tự' => fn () => t7Valid(files: [['name' => 'x', 'md5' => str_repeat('a', 31), 'size' => 1]]),
    'md5 33 ký tự' => fn () => t7Valid(files: [['name' => 'x', 'md5' => str_repeat('a', 33), 'size' => 1]]),
    'thiếu md5' => fn () => t7Valid(files: [['name' => 'x', 'size' => 1]]),
    'cỡ âm' => fn () => t7Valid(files: [['name' => 'x', 'md5' => str_repeat('a', 32), 'size' => -1]]),
    'cỡ là chuỗi' => fn () => t7Valid(files: [['name' => 'x', 'md5' => str_repeat('a', 32), 'size' => '1']]),
    'cỡ là số thực' => fn () => t7Valid(files: [['name' => 'x', 'md5' => str_repeat('a', 32), 'size' => 1.5]]),
]);

it('nhận started_at đúng 5 phút tới (lệch đồng hồ hai máy) và có phần thập phân giây', function () {
    expect(t7Parse(t7Valid(['started_at' => '2026-10-07T01:05:00Z']))->errors)->toBe(0)
        ->and(t7Parse(t7Valid(['started_at' => '2026-10-07T07:59:59.123+07:00']))->errors)->toBe(0);
});

it('JSON hỏng hay không phải object → lý do "không phải JSON hợp lệ", không phải lý do khuôn', function (string $json) {
    expect(fn () => t7Parse($json))->toThrow(OfficeReceiptRejected::class, __('office_copy.receipt.not_json'));
})->with([
    'cắt dở' => '{"format": 1,',
    'một số' => '5',
    'một chuỗi' => '"receipt"',
]);

it('lý do từ chối nói rõ biên nhận thuộc Shared Drive khác, bằng tiếng Việt, không in mã Drive', function () {
    try {
        t7Parse(t7Valid(['kho' => ['team_drive' => '0AKhoBiMat', 'root_folder_id' => FakeGoogleDrive::ROOT_FOLDER_ID]]));
        $this->fail('phải từ chối');
    } catch (OfficeReceiptRejected $exception) {
        expect($exception->getMessage())->toContain('Shared Drive')
            ->not->toContain('0AKhoBiMat')
            ->not->toContain(FakeGoogleDrive::DRIVE_ID);
    }
});
