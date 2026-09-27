<?php

use App\Http\Middleware\SendSecurityHeaders;
use App\Models\ClientUser;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;

/**
 * SPEC §10 mục 2 — header bảo mật và Content-Security-Policy (M8a Task 4, 5; phán quyết R4).
 *
 * Chế độ CSP đọc từ `config('vkcrm.security.csp_mode')` MỖI request, nên test đổi cấu hình rồi
 * gọi thẳng HTTP — không có gì phải dựng lại.
 */

/**
 * Tách một chính sách CSP thành [chỉ thị => [nguồn...]].
 *
 * @return array<string, list<string>>
 */
function cspDirectives(string $policy): array
{
    $directives = [];

    foreach (array_filter(array_map('trim', explode(';', $policy))) as $directive) {
        $parts = preg_split('/\s+/', $directive);
        $directives[array_shift($parts)] = $parts;
    }

    return $directives;
}

it('§10.2 chế độ off không gửi header CSP nào', function () {
    config(['vkcrm.security.csp_mode' => 'off']);

    $response = $this->get('/admin/login')->assertOk();

    expect($response->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();
});

it('§10.2 chế độ report gửi đúng header Content-Security-Policy-Report-Only, không gửi bản thi hành', function () {
    config(['vkcrm.security.csp_mode' => 'report']);

    $response = $this->get('/admin/login')->assertOk();

    expect($response->headers->get('Content-Security-Policy-Report-Only'))->toBeString()->not->toBeEmpty()
        ->and($response->headers->has('Content-Security-Policy'))->toBeFalse();
});

it('§10.2 chế độ enforce gửi Content-Security-Policy, không gửi bản Report-Only', function () {
    config(['vkcrm.security.csp_mode' => 'enforce']);

    $response = $this->get('/portal/login')->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toBeString()->not->toBeEmpty()
        ->and($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();
});

it('§10.2 một giá trị CSP_MODE gõ sai rơi về enforce, không lặng lẽ tắt CSP', function () {
    config(['vkcrm.security.csp_mode' => 'enforced']);

    $response = $this->get('/admin/login')->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toBeString()->not->toBeEmpty();
});

it('§10.2 CSP_MODE không phân biệt hoa thường và khoảng trắng thừa', function () {
    config(['vkcrm.security.csp_mode' => ' Report ']);

    $response = $this->get('/admin/login')->assertOk();

    expect($response->headers->has('Content-Security-Policy-Report-Only'))->toBeTrue()
        ->and($response->headers->has('Content-Security-Policy'))->toBeFalse();
});

it('§10.2 script-src không chứa unsafe-inline và mang nonce của request', function () {
    config(['vkcrm.security.csp_mode' => 'enforce']);

    $policy = cspDirectives($this->get('/admin/login')->headers->get('Content-Security-Policy'));

    expect($policy['script-src'])->not->toContain("'unsafe-inline'")
        ->and($policy['script-src'])->toContain("'self'")
        ->and(collect($policy['script-src'])->filter(fn (string $source) => str_starts_with($source, "'nonce-")))
        ->toHaveCount(1);
});

it('§10.2 các chỉ thị còn lại đúng chính sách đã duyệt (R4, SPEC §3 Bunny Fonts)', function () {
    config(['vkcrm.security.csp_mode' => 'enforce']);

    $policy = cspDirectives($this->get('/admin/login')->headers->get('Content-Security-Policy'));

    expect($policy['default-src'])->toBe(["'self'"])
        ->and($policy['style-src'])->toBe(["'self'", "'unsafe-inline'", 'https://fonts.bunny.net'])
        ->and($policy['font-src'])->toBe(["'self'", 'https://fonts.bunny.net', 'data:'])
        ->and($policy['img-src'])->toBe(["'self'", 'data:', 'blob:'])
        ->and($policy['connect-src'])->toBe(["'self'"])
        ->and($policy['frame-ancestors'])->toBe(["'none'"])
        ->and($policy['base-uri'])->toBe(["'self'"])
        ->and($policy['form-action'])->toBe(["'self'"])
        ->and($policy['object-src'])->toBe(["'none'"]);
});

it('§10.2 nonce khác nhau giữa hai request', function () {
    config(['vkcrm.security.csp_mode' => 'enforce']);

    $nonceOf = function (): string {
        $policy = cspDirectives($this->get('/portal/login')->headers->get('Content-Security-Policy'));

        return collect($policy['script-src'])->first(fn (string $source) => str_starts_with($source, "'nonce-"));
    };

    $first = $nonceOf();
    $second = $nonceOf();

    expect($first)->not->toBe($second)
        ->and(strlen($first))->toBeGreaterThan(strlen("'nonce-'") + 16);
});

it('§10.2 chế độ report gửi cùng chính sách với enforce, chỉ khác tên header', function () {
    config(['vkcrm.security.csp_mode' => 'report']);
    $report = cspDirectives($this->get('/admin/login')->headers->get('Content-Security-Policy-Report-Only'));

    config(['vkcrm.security.csp_mode' => 'enforce']);
    $enforce = cspDirectives($this->get('/admin/login')->headers->get('Content-Security-Policy'));

    $withoutNonce = fn (array $policy) => array_map(
        fn (array $sources) => array_values(array_filter($sources, fn (string $s) => ! str_starts_with($s, "'nonce-"))),
        $policy,
    );

    expect($withoutNonce($report))->toBe($withoutNonce($enforce));
});

it('§10.2 thẻ script của Livewire mang đúng nonce trong header', function () {
    config(['vkcrm.security.csp_mode' => 'enforce']);

    $response = $this->get('/portal/login')->assertOk();
    $policy = cspDirectives($response->headers->get('Content-Security-Policy'));
    $nonce = substr(collect($policy['script-src'])->first(fn (string $s) => str_starts_with($s, "'nonce-")), 7, -1);

    expect($response->getContent())->toMatch('#<script src="[^"]*livewire[^"]*"\s+nonce="'.preg_quote($nonce, '#').'"#');
});

it('§10.2 middleware là middleware toàn cục, không phụ thuộc danh sách của từng panel', function () {
    expect(app(Kernel::class)->hasMiddleware(SendSecurityHeaders::class))->toBeTrue();
});

it('§10.2 trang đã đăng nhập của cả hai panel cũng mang CSP', function () {
    config(['vkcrm.security.csp_mode' => 'enforce']);

    $staff = User::factory()->create();
    $client = ClientUser::factory()->activated()->create();

    expect($this->actingAs($staff, 'web')->get('/admin')->assertOk()->headers->get('Content-Security-Policy'))
        ->toBeString()->not->toBeEmpty();

    auth('web')->logout();

    expect($this->actingAs($client, 'client')->get('/portal')->assertOk()->headers->get('Content-Security-Policy'))
        ->toBeString()->not->toBeEmpty();
});
