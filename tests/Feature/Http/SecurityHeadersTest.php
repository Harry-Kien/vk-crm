<?php

use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Http\Middleware\SendSecurityHeaders;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\User;
use App\Support\Security\ContentSecurityPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Contracts\Foundation\MaintenanceMode as MaintenanceModeContract;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

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

/**
 * `'unsafe-eval'` là thứ ĐO ĐƯỢC là bắt buộc (docs/research/2026-09-26-csp-khao-sat.md, mục 3):
 * bản Alpine của Livewire dựng mọi biểu thức bằng `Function`, thiếu nó thì trang đăng nhập nhân
 * sự không dùng được; bản Alpine CSP (`livewire.csp_safe`) làm hỏng nút "Chuyển giai đoạn". Test
 * này ghim đúng ba nguồn để không ai thêm một nguồn thứ tư mà không có số đo.
 */
it('§10.2 script-src đúng ba nguồn: self, nonce của request, unsafe-eval (đo ở khảo sát R4)', function () {
    config(['vkcrm.security.csp_mode' => 'enforce']);

    $policy = cspDirectives($this->get('/admin/login')->headers->get('Content-Security-Policy'));

    expect($policy['script-src'])->toHaveCount(3)
        ->and($policy['script-src'][0])->toBe("'self'")
        ->and($policy['script-src'][1])->toStartWith("'nonce-")
        ->and($policy['script-src'][2])->toBe("'unsafe-eval'");
});

it('§10.2 các chỉ thị còn lại đúng chính sách đã duyệt (R4, SPEC §3 Bunny Fonts)', function () {
    config(['vkcrm.security.csp_mode' => 'enforce']);

    $policy = cspDirectives($this->get('/admin/login')->headers->get('Content-Security-Policy'));

    expect(array_keys($policy))->toBe([
        'default-src', 'script-src', 'worker-src', 'style-src', 'font-src', 'img-src',
        'connect-src', 'frame-ancestors', 'base-uri', 'form-action', 'object-src',
    ])
        ->and($policy['default-src'])->toBe(["'self'"])
        ->and($policy['style-src'])->toBe(["'self'", "'unsafe-inline'", 'https://fonts.bunny.net'])
        ->and($policy['font-src'])->toBe(["'self'", 'https://fonts.bunny.net', 'data:'])
        ->and($policy['img-src'])->toBe(["'self'", 'data:', 'blob:'])
        ->and($policy['connect-src'])->toBe(["'self'"])
        ->and($policy['frame-ancestors'])->toBe(["'none'"])
        ->and($policy['base-uri'])->toBe(["'self'"])
        ->and($policy['form-action'])->toBe(["'self'"])
        ->and($policy['object-src'])->toBe(["'none'"]);
});

/**
 * Vòng sửa 1, C1: ô tải lên của Filament (FilePond) dựng bản xem trước ẢNH trong một Web Worker
 * tạo từ `blob:`. Không có `worker-src` thì trình duyệt rơi về `script-src`, nơi `blob:` không
 * khớp — đo được ở cả Chromium lẫn WebKit trên đường chụp ảnh nộp giấy tờ của khách (SPEC §8.4).
 * `blob:` chỉ được mở cho Worker, KHÔNG cho `script-src`: một `<script src="blob:…">` vẫn bị chặn.
 */
it('§10.2 worker-src cho Worker blob: của FilePond, script-src không có blob:', function () {
    config(['vkcrm.security.csp_mode' => 'enforce']);

    $policy = cspDirectives($this->get('/portal/login')->headers->get('Content-Security-Policy'));

    expect($policy['worker-src'])->toBe(["'self'", 'blob:'])
        ->and($policy['script-src'])->not->toContain('blob:')
        ->and($policy['default-src'])->not->toContain('blob:');
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

// ---------------------------------------------------------------------------------------------
// Task 5 — ba header luôn bật, trên mọi bề mặt, ở mọi chế độ CSP.
// ---------------------------------------------------------------------------------------------

/**
 * Một tài liệu có tệp thật trên đĩa giả và đường tải có chữ ký cho luật sư phụ trách — để khẳng
 * định header có mặt trên phản hồi TẢI TỆP THẬT (200), không chỉ trên một lời từ chối.
 *
 * @return array{0: User, 1: string}
 */
function signedDownloadForHeaders(): array
{
    test()->seed(RolesAndPermissionsSeeder::class);
    config(['media-library.prefix' => 'test-'.Str::random(16)]);

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->for(Client::factory())->create(['lead_lawyer_id' => $lawyer->id]);
    $document = Document::factory()->create([
        'matter_id' => $matter->id,
        'group' => DocumentGroup::Authority,
        'status' => DocumentStatus::Published,
    ]);
    $document->addMedia(UploadedFile::fake()->createWithContent('nguon.pdf', '%PDF-1.4 noi dung'))
        ->usingFileName('01k5g7q8wz0000000000000000.pdf')
        ->toMediaCollection('file');

    return [$lawyer, $document->refresh()->downloadUrlFor($lawyer)];
}

/**
 * Mọi bề mặt mà §10.2 nói tới: hai trang đăng nhập (route của panel), route web chuyển hướng,
 * trang lỗi 404 (dựng bởi exception handler), và route tải tệp có chữ ký (tệp thật, 200).
 *
 * @return array<string, TestResponse>
 */
function responsesOnEverySurface(): array
{
    [$lawyer, $downloadUrl] = signedDownloadForHeaders();

    return [
        'admin/login' => test()->get('/admin/login')->assertOk(),
        'portal/login' => test()->get('/portal/login')->assertOk(),
        'web /' => test()->get('/')->assertRedirect('/portal'),
        '404' => test()->get('/khong-ton-tai')->assertNotFound(),
        'tải tệp có chữ ký' => test()->actingAs($lawyer, 'web')->get($downloadUrl)->assertOk(),
    ];
}

it('§10.2 X-Frame-Options: DENY trên cả hai panel, route web, trang lỗi và route tải tệp có chữ ký', function () {
    foreach (responsesOnEverySurface() as $surface => $response) {
        expect($response->headers->get('X-Frame-Options'))->toBe('DENY', "thiếu ở {$surface}");
    }
});

it('§10.2 X-Content-Type-Options: nosniff trên cả hai panel, route web, trang lỗi và route tải tệp có chữ ký', function () {
    foreach (responsesOnEverySurface() as $surface => $response) {
        expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff', "thiếu ở {$surface}");
    }
});

it('§10.2 Referrer-Policy: strict-origin-when-cross-origin trên cả hai panel, route web, trang lỗi và route tải tệp có chữ ký', function () {
    foreach (responsesOnEverySurface() as $surface => $response) {
        expect($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin', "thiếu ở {$surface}");
    }
});

it('§10.2 ba header luôn bật kể cả khi CSP ở chế độ off', function () {
    config(['vkcrm.security.csp_mode' => 'off']);

    $response = $this->get('/portal/login')->assertOk();

    expect($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
});

// ---------------------------------------------------------------------------------------------
// Task 5 — chế độ mặc định theo phán quyết R4.
// ---------------------------------------------------------------------------------------------

/**
 * Vòng sửa 1, I3: chiều an toàn phải là chiều MẶC ĐỊNH. Để trống chỉ được hạ xuống `report` ở
 * đúng hai môi trường có tên trong danh sách (máy dev, bộ test); mọi môi trường khác — staging,
 * production, một APP_ENV gõ sai — là `enforce`.
 */
it('§10.2 CSP_MODE để trống: enforce ở mọi môi trường không phải local/testing', function (?string $blank, string $environment) {
    config(['vkcrm.security.csp_mode' => $blank]);
    app()->detectEnvironment(fn () => $environment);

    expect(ContentSecurityPolicy::mode())->toBe('enforce');
})->with(['null' => [null], 'chuỗi rỗng' => [''], 'chỉ khoảng trắng' => ['  ']])
    ->with(['production', 'staging', 'prod', 'loacl', 'LOCAL', 'demo']);

it('§10.2 CSP_MODE để trống: report chỉ ở local và testing', function (?string $blank, string $environment) {
    config(['vkcrm.security.csp_mode' => $blank]);
    app()->detectEnvironment(fn () => $environment);

    expect(ContentSecurityPolicy::mode())->toBe('report');
})->with(['null' => [null], 'chuỗi rỗng' => [''], 'chỉ khoảng trắng' => ['  ']])
    ->with(['local', 'testing']);

/**
 * Chiều hạ xuống phải được GỌI TÊN: chỉ một `CSP_MODE=report` hay `off` viết ra mới nới CSP, và nó
 * được tôn trọng ở mọi môi trường (người vận hành cần tắt tạm khi một trang hỏng trên production).
 * Ngược lại `enforce` viết ra thì thi hành cả trên máy dev.
 */
it('§10.2 CSP_MODE viết ra được tôn trọng ở mọi môi trường', function (string $mode, string $environment) {
    config(['vkcrm.security.csp_mode' => $mode]);
    app()->detectEnvironment(fn () => $environment);

    expect(ContentSecurityPolicy::mode())->toBe($mode);
})->with(['off', 'report', 'enforce'])->with(['production', 'staging', 'local', 'testing']);

it('§10.2 CSP_MODE để trống trên production: phản hồi thật mang header thi hành', function () {
    config(['vkcrm.security.csp_mode' => null]);
    app()->detectEnvironment(fn () => 'production');

    $response = $this->get('/portal/login')->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toBeString()->not->toBeEmpty()
        ->and($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();
});

it('§10.2 cấu hình giữ CSP_MODE thô, mặc định theo môi trường nằm ở ContentSecurityPolicy::mode()', function () {
    expect(config('vkcrm.security'))->toHaveKey('csp_mode')
        ->and(config('vkcrm.security.csp_mode'))->toBe(env('CSP_MODE'));
});

// ---------------------------------------------------------------------------------------------
// Task 5 — mọi script nội tuyến mang nonce (3 view Filament giữ riêng + Livewire).
// ---------------------------------------------------------------------------------------------

/**
 * Mọi thẻ `<script>` KHÔNG có `src` của một trang phải mang đúng nonce trong header. Test này nói
 * thay cho trình duyệt: một script nội tuyến thiếu nonce là một script bị chặn ở `enforce` — chế
 * độ tối, thu gọn thanh bên, dữ liệu khởi động của Filament hỏng lặng lẽ.
 */
function assertEveryInlineScriptCarriesTheNonce(TestResponse $response, string $page): void
{
    $policy = cspDirectives($response->headers->get('Content-Security-Policy'));
    $nonce = substr(collect($policy['script-src'])->first(fn (string $s) => str_starts_with($s, "'nonce-")), 7, -1);

    preg_match_all('/<script\b(?![^>]*\bsrc=)[^>]*>/i', $response->getContent(), $tags);

    expect($tags[0])->not->toBeEmpty("{$page}: không thấy script nội tuyến nào — test không còn đo gì");

    foreach ($tags[0] as $tag) {
        expect(str_contains($tag, 'nonce="'.$nonce.'"'))->toBeTrue("{$page}: script nội tuyến thiếu nonce: {$tag}");
    }
}

it('§10.2 mọi script nội tuyến ở trang đăng nhập của hai panel mang nonce của request', function () {
    config(['vkcrm.security.csp_mode' => 'enforce']);

    assertEveryInlineScriptCarriesTheNonce($this->get('/admin/login')->assertOk(), 'admin/login');
    assertEveryInlineScriptCarriesTheNonce($this->get('/portal/login')->assertOk(), 'portal/login');
});

it('§10.2 mọi script nội tuyến ở trang đã đăng nhập (có thanh bên) của hai panel mang nonce của request', function () {
    config(['vkcrm.security.csp_mode' => 'enforce']);

    $staff = User::factory()->create();
    $client = ClientUser::factory()->activated()->create();

    $admin = $this->actingAs($staff, 'web')->get('/admin')->assertOk();
    expect($admin->getContent())->toContain('collapsedGroups');
    assertEveryInlineScriptCarriesTheNonce($admin, '/admin');

    auth('web')->logout();

    $portal = $this->actingAs($client, 'client')->get('/portal')->assertOk();
    expect($portal->getContent())->toContain('collapsedGroups');
    assertEveryInlineScriptCarriesTheNonce($portal, '/portal');
});

// ---------------------------------------------------------------------------------------------
// Task 5 — ảnh đại diện không đi ra bên thứ ba (khảo sát R4: vi phạm img-src ui-avatars.com).
// ---------------------------------------------------------------------------------------------

it('§10.2 ảnh đại diện mặc định của cả hai panel là ảnh data: dựng tại chỗ, không gọi ui-avatars.com', function () {
    $staff = User::factory()->create(['name' => 'Quản trị hệ thống']);
    $client = ClientUser::factory()->activated()->create(['name' => 'Nguyễn Văn An']);

    $admin = $this->actingAs($staff, 'web')->get('/admin')->assertOk()->getContent();
    auth('web')->logout();
    $portal = $this->actingAs($client, 'client')->get('/portal')->assertOk()->getContent();

    expect($admin)->not->toContain('ui-avatars.com')->toContain('src="data:image/svg+xml;base64,')
        ->and($portal)->not->toContain('ui-avatars.com')->toContain('src="data:image/svg+xml;base64,');
});

it('§10.2 ảnh đại diện dựng tại chỗ mang chữ đầu của từ đầu và từ cuối, theo lối gọi tên tiếng Việt', function () {
    expect(InitialsAvatarProvider::initials('Nguyễn Văn An'))->toBe('NA')
        ->and(InitialsAvatarProvider::initials('  quản   trị hệ thống '))->toBe('QT')
        ->and(InitialsAvatarProvider::initials('Ánh'))->toBe('Á')
        ->and(InitialsAvatarProvider::initials('[HỆ THỐNG] Sao lưu'))->toBe('HL')
        ->and(InitialsAvatarProvider::initials(''))->toBe('');

    Filament::setCurrentPanel(Filament::getPanel('portal'));
    $url = (new InitialsAvatarProvider)->get(ClientUser::factory()->make(['name' => 'Trần Văn Bình']));

    expect($url)->toStartWith('data:image/svg+xml;base64,')
        ->and(base64_decode(Str::after($url, 'data:image/svg+xml;base64,')))->toContain('>TB</text>');
});

// ---------------------------------------------------------------------------------------------
// Vòng sửa 1 — header có mặt cả trên phản hồi do middleware toàn cục KHÁC dựng ra, và trên
// endpoint cập nhật Livewire.
// ---------------------------------------------------------------------------------------------

function assertThreeHeaders(TestResponse $response, string $surface): void
{
    expect($response->headers->get('X-Frame-Options'))->toBe('DENY', "thiếu ở {$surface}")
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff', "thiếu ở {$surface}")
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin', "thiếu ở {$surface}");
}

/**
 * Chế độ bảo trì giả qua container — KHÔNG `artisan down`: tệp `storage/framework/down` dùng
 * chung cho mọi tiến trình test song song và cả bản chạy của làn.
 */
it('§10.2 trang bảo trì 503 mang ba header và CSP', function () {
    config(['vkcrm.security.csp_mode' => 'enforce']);

    $this->app->instance(MaintenanceModeContract::class, new class implements MaintenanceModeContract
    {
        public function activate(array $payload): void {}

        public function deactivate(): void {}

        public function active(): bool
        {
            return true;
        }

        public function data(): array
        {
            return ['status' => 503];
        }
    });

    $response = $this->get('/portal/login')->assertStatus(503);

    assertThreeHeaders($response, '503 bảo trì');
    expect($response->headers->get('Content-Security-Policy'))->toBeString()->not->toBeEmpty();
});

it('§10.2 phản hồi 413 của ValidatePostSize mang ba header', function () {
    // Trang lỗi của production, không phải trang gỡ lỗi (vẽ trang gỡ lỗi chậm trên bind
    // mount) — và đó cũng là phản hồi khách thật sẽ nhận.
    config(['app.debug' => false]);

    $response = $this->call('POST', '/portal/login', server: ['CONTENT_LENGTH' => (string) (200 * 1024 * 1024)]);

    $response->assertStatus(413);
    assertThreeHeaders($response, '413');
});

it('§10.2 phản hồi 400 của ValidatePathEncoding mang ba header', function () {
    config(['app.debug' => false]);

    $response = $this->get('/portal/%FF');

    $response->assertStatus(400);
    assertThreeHeaders($response, '400');
});

it('§10.2 SendSecurityHeaders đứng ĐẦU danh sách middleware toàn cục', function () {
    expect(app(Kernel::class)->getGlobalMiddleware()[0])->toBe(SendSecurityHeaders::class);
});

it('§10.2 phản hồi của endpoint cập nhật Livewire mang ba header', function () {
    $page = $this->get('/portal/login')->assertOk()->getContent();

    expect(preg_match('/data-update-uri="([^"]+)"/', $page, $uri))->toBe(1)
        ->and(preg_match('/wire:snapshot="([^"]+)"/', $page, $snapshot))->toBe(1);

    $response = $this->withHeaders(['X-Livewire' => 'true'])->postJson(
        (string) parse_url(html_entity_decode($uri[1]), PHP_URL_PATH),
        ['components' => [['snapshot' => html_entity_decode($snapshot[1]), 'updates' => [], 'calls' => []]]],
    );

    $response->assertOk();
    expect($response->json('components'))->toBeArray()->not->toBeEmpty();
    assertThreeHeaders($response, '/livewire/update');
});
