<?php

use App\Models\ClientUser;
use App\Notifications\Client\SendLoginCode;
use App\Support\BrandFooter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Layout thư dùng chung của văn phòng — SPEC §9 ("mọi mẫu email dùng chung một layout có logo và
 * chân trang"), kế hoạch M6 Task 1.
 *
 * Trọng tâm của tệp này là một tình huống đã biết trước và sẽ còn kéo dài: **bốn thông tin pháp
 * lý ở `config/vkcrm.php` cố ý để trống** (mã số thuế, Đoàn Luật sư, số giấy ĐKHĐ, địa chỉ văn
 * phòng) vì chủ văn phòng chưa cung cấp, và website của văn phòng không đăng chúng. Layout phải
 * render đúng trong CẢ HAI trạng thái: hôm nay với bốn chỗ trống, và ngày chúng được điền vào
 * `.env` mà không ai sửa một dòng mã nào.
 *
 * "Đúng" ở đây được đo, không được nhìn: không nhãn nào đứng một mình ("Mã số thuế:" cụt đuôi),
 * không thẻ khối nào rỗng trong bản HTML, và không dòng trắng thừa nào trong bản văn bản thuần.
 */

/** Bốn thông tin pháp lý, đúng như chủ văn phòng sẽ điền vào `.env` khi có.  */
function filledLegalDetails(): void
{
    config()->set('vkcrm.brand.office_address', '123 Đường Lê Lợi, Quận 1, TP.HCM');
    config()->set('vkcrm.brand.tax_code', '0312345678');
    config()->set('vkcrm.brand.bar_association', 'Đoàn Luật sư TP.HCM');
    config()->set('vkcrm.brand.licence_number', '41.02.1234/TP/ĐKHĐ');
}

/** Bản HTML và bản văn bản thuần của thư mã đăng nhập — mẫu thật duy nhất có ở Task 1. */
function renderedOtpParts(): array
{
    $clientUser = ClientUser::factory()->create(['name' => 'Trần Thị B']);

    $clientUser->notify(new SendLoginCode('123456', 5));

    $email = Mail::mailer()->getSymfonyTransport()->innerTransport()->messages()->last()->getOriginalMessage();

    return [(string) $email->getHtmlBody(), (string) $email->getTextBody()];
}

it('đặt logo, tên pháp lý, hotline và website của văn phòng lên mọi thư', function () {
    [$html, $text] = renderedOtpParts();

    // Tên pháp lý có dấu `&`, nên bản HTML mang nó ở dạng đã thoát (`&amp;`) còn bản văn bản
    // thuần thì không. So bằng đúng dạng của từng bản, chứ không hạ khẳng định xuống một mẩu tên
    // cắt trước dấu `&` — mẩu ấy sẽ còn xanh cả khi loại hình doanh nghiệp biến mất khỏi chân thư.
    expect($html)->toContain(e(config('vkcrm.brand.legal_name')))
        ->and($text)->toContain((string) config('vkcrm.brand.legal_name'));

    foreach ([$html, $text] as $body) {
        expect($body)->toContain((string) config('vkcrm.brand.hotline'))
            ->and($body)->toContain((string) config('vkcrm.brand.website'));
    }

    expect($html)->toContain('brand/vk-mark-96.png');
});

it('in bốn thông tin pháp lý khi chủ văn phòng đã điền vào .env', function () {
    filledLegalDetails();

    [$html, $text] = renderedOtpParts();

    foreach ([$html, $text] as $body) {
        expect($body)->toContain('123 Đường Lê Lợi, Quận 1, TP.HCM')
            ->and($body)->toContain('0312345678')
            ->and($body)->toContain('Đoàn Luật sư TP.HCM')
            ->and($body)->toContain('41.02.1234/TP/ĐKHĐ');
    }
});

/**
 * Chủ văn phòng cung cấp địa chỉ trụ sở ngày 2026-10-02, nên địa chỉ là giá trị MẶC ĐỊNH của
 * `config/vkcrm.php` — chân mọi thư mang nó ngay cả khi máy chủ không khai `BRAND_OFFICE_ADDRESS`.
 * Chép cứng chuỗi ở đây là cố ý: đây là một sự thật do chủ văn phòng đưa, không phải câu chữ của
 * giao diện, và một lần sửa nhầm config phải làm bài này đỏ.
 */
it('in địa chỉ trụ sở văn phòng lên mọi thư khi chưa khai BRAND_OFFICE_ADDRESS', function () {
    expect(config('vkcrm.brand.office_address'))
        ->toBe('1808 đường Nguyễn Ái Quốc, phường Trấn Biên, thành phố Đồng Nai');

    [$html, $text] = renderedOtpParts();

    foreach ([$html, $text] as $body) {
        expect($body)->toContain('1808 đường Nguyễn Ái Quốc, phường Trấn Biên, thành phố Đồng Nai');
    }
});

it('không in nhãn cụt đuôi nào khi ba thông tin pháp lý còn lại còn trống', function () {
    expect(config('vkcrm.brand.tax_code'))->toBeNull()
        ->and(config('vkcrm.brand.bar_association'))->toBeNull()
        ->and(config('vkcrm.brand.licence_number'))->toBeNull();

    [$html, $text] = renderedOtpParts();

    // Nhãn lấy từ chính tệp ngôn ngữ, bỏ chỗ dành cho giá trị đi: một khẳng định chép cứng câu
    // tiếng Việt sẽ xanh mãi mãi sau ngày ai đó sửa lại câu chữ trong `lang/vi/emails.php`.
    $label = fn (string $key): string => trim(str_replace(':value', '', __("emails.footer.{$key}")));

    foreach ([$html, $text] as $body) {
        foreach (['tax_code', 'bar_association', 'licence_number'] as $key) {
            expect($body)->not->toContain($label($key));
        }
    }
});

it('không để lại thẻ khối rỗng nào trong bản HTML khi bốn chỗ trống bị bỏ qua', function () {
    [$html] = renderedOtpParts();

    preg_match_all('/<(p|div|td|span)\b[^>]*>\s*<\/\1>/', $html, $matches);

    expect($matches[0])->toBe([]);
});

/**
 * Blade thoát HTML ở mọi view, kể cả view text/plain — nên một `{{ … }}` trong bản văn bản thuần
 * in ra chữ `&amp;` giữa tên pháp lý của văn phòng, đúng vào chỗ khách đọc. Laravel giấu chuyện
 * này ở đường markdown (`Markdown::renderText()` gọi `html_entity_decode()` ở cuối); đường
 * `->view([html, text])` mà Task 1 chuyển sang thì không có bước đó, và lần đầu dựng layout đã
 * vấp đúng vào đấy.
 */
it('không để lẫn thực thể HTML nào vào bản văn bản thuần', function () {
    filledLegalDetails();

    [, $text] = renderedOtpParts();

    expect($text)->toContain('Vũ Khang Solutions & Partners')
        ->and(preg_match('/&(?:amp|quot|#0?39|lt|gt);/', $text))->toBe(0);
});

it('không để lại dòng trắng thừa nào trong bản văn bản thuần', function () {
    [, $text] = renderedOtpParts();

    expect($text)->not->toBeEmpty()
        ->and(preg_match('/\n[ \t]*\n[ \t]*\n/', $text))->toBe(0);
});

it('dựng chân thư chỉ từ những thông tin pháp lý đã có', function () {
    // Chưa khai gì trong .env: chỉ còn địa chỉ trụ sở (mặc định từ 2026-10-02), không dòng nào khác.
    expect(BrandFooter::legalLines())->toBe(['1808 đường Nguyễn Ái Quốc, phường Trấn Biên, thành phố Đồng Nai']);

    filledLegalDetails();

    expect(BrandFooter::legalLines())->toBe([
        '123 Đường Lê Lợi, Quận 1, TP.HCM',
        __('emails.footer.tax_code', ['value' => '0312345678']),
        __('emails.footer.bar_association', ['value' => 'Đoàn Luật sư TP.HCM']),
        __('emails.footer.licence_number', ['value' => '41.02.1234/TP/ĐKHĐ']),
    ]);
});

// ---------------------------------------------------------------------------------------------
// Vòng sửa 1 (minor) — logo thư (`asset()`) đọc theo request hiện tại (hay `APP_URL` khi không có
// request nào, dưới `queue:work`) — cùng lỗi mà `App\Support\PortalUrl` đã sửa cho liên kết cổng.
// Một thư CHO KHÁCH dựng ngay sau một request `/admin` có thể mang logo trỏ vào tên miền QUẢN
// TRỊ nếu APP_URL trỏ về đó.
// ---------------------------------------------------------------------------------------------

/**
 * Mutation probe: xem báo cáo — trả lại `asset('brand/vk-mark-96.png')` (bản trước) ở
 * `emails/layout.blade.php` làm test này đỏ (logo quay về host quản trị).
 */
it('builds the logo URL from the portal domain, not the host of the current (admin) request', function () {
    config([
        'vkcrm.admin_domain' => 'quantri.luatvukhang.test',
        'vkcrm.portal_domain' => 'khachhang.luatvukhang.test',
    ]);

    app()->instance('request', Request::create('https://quantri.luatvukhang.test/admin/matters/1'));

    [$html] = renderedOtpParts();

    $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?? 'https';

    expect($html)->toContain('src="'.$scheme.'://khachhang.luatvukhang.test/brand/vk-mark-96.png"')
        ->and($html)->not->toContain('quantri.luatvukhang.test');
});

/** Cặp dương: một tên miền (không tách ADMIN_DOMAIN/PORTAL_DOMAIN) thì logo vẫn dựng đúng. */
it('still builds a working logo URL when a single domain serves both panels', function () {
    config(['vkcrm.admin_domain' => null, 'vkcrm.portal_domain' => null]);

    [$html] = renderedOtpParts();

    expect($html)->toContain(rtrim(config('app.url'), '/').'/brand/vk-mark-96.png');
});
