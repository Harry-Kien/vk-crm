<?php

use App\Support\Billing\VietQr;
use App\Support\Billing\VietQrBanks;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/*
|--------------------------------------------------------------------------
| Làn nt Task 1 — chuỗi VietQR (chuẩn EMVCo MPM của NAPAS) và ảnh QR vẽ tại chỗ
|--------------------------------------------------------------------------
|
| Không gọi dịch vụ ngoài nào để vẽ QR (số tiền, số tài khoản không rời máy chủ). Chuỗi được dựng ở
| `App\Support\Billing\VietQr`, ảnh vẽ bằng `chillerlan/php-qrcode` — gói đã có trong `composer.lock`
| vì Filament đòi nó cho xác thực hai lớp, không thêm gói composer nào.
|
| Hình dạng chuỗi được đo bằng cách TÁCH LẠI (TLV: hai chữ số mã, hai chữ số độ dài, giá trị) chứ
| không so với một chuỗi chép tay: một chuỗi chép tay sai CRC thì test cũng sai theo.
*/

/**
 * Tách một chuỗi TLV của EMVCo thành [mã => giá trị]; giữ nguyên thứ tự.
 *
 * @return array<string, string>
 */
function vietQrTlv(string $payload): array
{
    $fields = [];
    $offset = 0;

    while ($offset < strlen($payload)) {
        $id = substr($payload, $offset, 2);
        $length = (int) substr($payload, $offset + 2, 2);
        $fields[$id] = substr($payload, $offset + 4, $length);
        $offset += 4 + $length;
    }

    expect($offset)->toBe(strlen($payload));

    return $fields;
}

it('computes the CRC-16/CCITT-FALSE check value of the EMVCo standard', function () {
    // Giá trị kiểm chuẩn của CRC-16/CCITT-FALSE (đa thức 0x1021, khởi tạo 0xFFFF) trên "123456789".
    expect(VietQr::crc16('123456789'))->toBe('29B1');
});

it('builds a dynamic VietQR payload carrying bank, account, amount, purpose and a valid CRC', function () {
    $payload = VietQr::payload('970436', '0071000123456', 15_000_000, 'VK-2026-DS-0012 DOT 2');

    $fields = vietQrTlv($payload);

    expect(array_map('strval', array_keys($fields)))->toBe(['00', '01', '38', '53', '54', '58', '62', '63'])
        ->and($fields['00'])->toBe('01')
        ->and($fields['01'])->toBe('12')
        ->and($fields['53'])->toBe('704')
        ->and($fields['54'])->toBe('15000000')
        ->and($fields['58'])->toBe('VN');

    $account = vietQrTlv($fields['38']);
    expect($account)->toBe([
        '00' => 'A000000727',
        '01' => '0006970436'.'0113'.'0071000123456',
        '02' => 'QRIBFTTA',
    ]);

    expect(vietQrTlv($account['01']))->toBe(['00' => '970436', '01' => '0071000123456'])
        ->and(vietQrTlv($fields['62']))->toBe(['08' => 'VK-2026-DS-0012 DOT 2']);

    // CRC tính trên toàn chuỗi TỚI HẾT "6304".
    expect($fields['63'])->toBe(VietQr::crc16(substr($payload, 0, -4)))
        ->and(substr($payload, -8, 4))->toBe('6304');
});

it('builds a static payload without amount or purpose for the settings preview', function () {
    $fields = vietQrTlv(VietQr::payload('970415', '123456789', null, null));

    expect(array_map('strval', array_keys($fields)))->toBe(['00', '01', '38', '53', '58', '63'])
        ->and($fields['01'])->toBe('11');
});

/**
 * Mutation probe (báo cáo): đổi "12" thành "11" cho chuỗi có số tiền → test trên đỏ; bỏ trường 54
 * → test trên đỏ. Ở đây: ảnh QR vẽ ra đọc lại được ĐÚNG chuỗi — đo bằng bộ giải mã của chính gói.
 */
it('draws a QR code that decodes back to exactly the payload', function () {
    $payload = VietQr::payload('970436', '0071000123456', 2_500_000, 'VK-2026-DS-0012 DOT 1');

    $png = (new QRCode(new QROptions([
        'outputType' => QROutputInterface::GDIMAGE_PNG,
        'outputBase64' => false,
        'scale' => 8,
    ])))->render($payload);

    expect((string) (new QRCode)->readFromBlob($png))->toBe($payload);

    $svg = VietQr::svgDataUri($payload);
    expect($svg)->toStartWith('data:image/svg+xml;base64,')
        ->and(base64_decode(substr($svg, strlen('data:image/svg+xml;base64,'))))->toContain('<svg');
});

it('makes a transfer note of matter code and instalment number in plain upper-case ASCII, at most 25 characters', function () {
    expect(VietQr::transferNote('VK-2026-DS-0012', 2))->toBe('VK-2026-DS-0012 DOT 2')
        ->and(VietQr::transferNote('vk-2026-đt-0001', 3))->toBe('VK-2026-DT-0001 DOT 3')
        ->and(VietQr::transferNote('VK/2026_HS#1', 1))->toBe('VK2026HS1 DOT 1');

    $long = VietQr::transferNote('VK-2026-TRANHCHAPDATDAI-000123', 12);
    expect(mb_strlen($long))->toBeLessThanOrEqual(VietQr::MAX_NOTE_LENGTH)
        ->and($long)->toEndWith('DOT 12');
});

it('refuses a payload whose parts do not fit the standard', function (string $bin, string $account, ?int $amount, ?string $note) {
    expect(fn () => VietQr::payload($bin, $account, $amount, $note))->toThrow(InvalidArgumentException::class);
})->with([
    'bin not six digits' => ['97043', '0071000123456', 1000, null],
    'account too long' => ['970436', str_repeat('1', 20), 1000, null],
    'account with symbols' => ['970436', '0071-000', 1000, null],
    'amount zero' => ['970436', '0071000123456', 0, null],
    'note too long' => ['970436', '0071000123456', 1000, str_repeat('A', 26)],
    'note with diacritics' => ['970436', '0071000123456', 1000, 'Đợt 1'],
]);

it('lists only six-digit NAPAS bank codes with a name', function () {
    expect(VietQrBanks::NAMES)->not->toBeEmpty();

    foreach (VietQrBanks::NAMES as $bin => $name) {
        expect((string) $bin)->toMatch('/^\d{6}$/')
            ->and($name)->not->toBeEmpty();
    }

    expect(VietQrBanks::NAMES['970436'])->toContain('Vietcombank')
        ->and(VietQrBanks::NAMES['970418'])->toContain('BIDV')
        ->and(VietQrBanks::NAMES['970415'])->toContain('VietinBank');
});
