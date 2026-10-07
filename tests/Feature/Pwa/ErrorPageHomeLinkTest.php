<?php

use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\Clients\Pages\EditClient;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| M12 Task 3 vòng sửa 1 — trang lỗi 403/404 dẫn về ĐẦU APP mà request thuộc về
|--------------------------------------------------------------------------
|
| Từ Task 3, liên kết tải của cả hai app mở trong CÙNG cửa sổ và nằm TRONG scope (route bí danh
| `/admin/documents/{id}/download`, `/portal/documents/{id}/download`). Một liên kết đã hết hạn (quá
| `Document::DOWNLOAD_LINK_MINUTES` phút kể từ lúc trang vẽ nó — cách dùng bình thường) hay bị từ
| chối vì thế mở trang lỗi NGAY TRONG cửa sổ hiện tại, kể cả cửa sổ app đã cài. Trước vòng sửa này
| lối ra duy nhất của trang là `url('/')` → `/portal`: trang đăng nhập của KHÁCH, ngoài scope
| `/admin` (và `/` cũng ngoài scope `/portal`). Trên iPhone, cửa sổ standalone không có nút quay
| lại hay tải lại, nên một luật sư chạm "Tải tệp" sau sáu phút bị đẩy ra một tấm Safari ngoài app
| và chỉ còn cách tắt hẳn app.
|
| Luật mới: nút về của trang lỗi trỏ `start_url` của app mà request thuộc về — `/admin` cho mọi
| path dưới `/admin` (và cho request Livewire của một trang admin), `/portal` cho mọi thứ khác — và
| câu chữ bảo bấm chính nút đó thay vì "quay lại, tải lại trang". IP ngoài `ADMIN_IP_ALLOWLIST`
| nhận đúng trang của một path lạ bất kỳ (M8 R7: không được biết `/admin` có gì đặc biệt).
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['media-library.prefix' => 'test-'.Str::random(16)]);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);
    $this->matter = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lawyer->id]);
});

function pwaErrorPageDocument(Matter $matter, DocumentGroup $group = DocumentGroup::ClientProvided): Document
{
    $document = Document::factory()->create([
        'matter_id' => $matter->id,
        'group' => $group,
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
    ]);

    $document->addMedia(UploadedFile::fake()->createWithContent('nguon.pdf', '%PDF-1.4 noi dung that'))
        ->usingFileName('01k5g7q8wz0000000000000000.pdf')
        ->toMediaCollection('file');

    return $document->refresh();
}

/**
 * Mọi lối ra của trang lỗi trừ số điện thoại (`tel:`) — tức đúng những chỗ một cú chạm đưa người
 * dùng ĐI TỚI một trang. Trả về cả danh sách (không chỉ cái đầu) để một nút thứ hai trỏ ra ngoài
 * app cũng làm test đỏ.
 *
 * @return list<string>
 */
function pwaErrorPageExits(string $html): array
{
    preg_match_all('/<a\b[^>]*\bhref="([^"]*)"/', $html, $matches);

    return array_values(array_filter(
        array_map(fn (string $href): string => html_entity_decode($href, ENT_QUOTES), $matches[1]),
        fn (string $href): bool => ! str_starts_with($href, 'tel:'),
    ));
}

it('sends a lawyer whose internal download link expired back to the start of the internal app, with copy that names that button', function () {
    $document = pwaErrorPageDocument($this->matter);
    $url = $document->downloadUrlFor($this->lawyer);
    expect(parse_url($url, PHP_URL_PATH))->toBe("/admin/documents/{$document->id}/download");

    $this->travel(Document::DOWNLOAD_LINK_MINUTES + 1)->minutes();

    $html = $this->actingAs($this->lawyer, 'web')->get($url)->assertForbidden()->getContent();

    expect(pwaErrorPageExits($html))->toBe([url('/admin')])
        // Nút đó mang đúng nhãn mà câu chữ nhắc tới...
        ->and($html)->toMatch('#<a href="'.preg_quote(url('/admin'), '#').'"[^>]*>\s*'.preg_quote(e(__('portal_progress.link_expired.home')), '#').'\s*</a>#')
        // ...và câu chữ chỉ vào nút TRÊN TRANG, không vào nút quay lại / tải lại của trình duyệt —
        // cửa sổ standalone của iPhone không có hai nút đó.
        ->and($html)->toContain(e(__('portal_progress.link_expired.retry', ['home' => __('portal_progress.link_expired.home')])))
        ->and(__('portal_progress.link_expired.retry'))->toContain(':home')
        ->not->toContain('tải lại trang')
        ->not->toContain('quay lại');
});

it('sends a client whose portal download link expired back to the start of the client app', function () {
    $document = pwaErrorPageDocument($this->matter);
    $url = $document->downloadUrlFor($this->clientUser);
    expect(parse_url($url, PHP_URL_PATH))->toBe("/portal/documents/{$document->id}/download");

    $this->travel(Document::DOWNLOAD_LINK_MINUTES + 1)->minutes();

    $html = $this->actingAs($this->clientUser, 'client')->get($url)->assertForbidden()->getContent();

    expect(pwaErrorPageExits($html))->toBe([url('/portal')]);
});

it('sends a refused download back to the app it was refused in — internal alias to /admin, portal alias to /portal', function () {
    $internal = pwaErrorPageDocument($this->matter, DocumentGroup::Authority);
    $outsider = User::factory()->withRole(Role::Lawyer)->create();

    $staffHtml = $this->actingAs($outsider, 'web')
        ->get($internal->downloadUrlFor($outsider))
        ->assertNotFound()
        ->getContent();

    $published = pwaErrorPageDocument($this->matter);
    $otherClientUser = ClientUser::factory()->activated()->create(['client_id' => Client::factory()->create()->id]);

    $clientHtml = $this->actingAs($otherClientUser, 'client')
        ->get($published->downloadUrlFor($otherClientUser))
        ->assertNotFound()
        ->getContent();

    expect(pwaErrorPageExits($staffHtml))->toBe([url('/admin')])
        ->and(pwaErrorPageExits($clientHtml))->toBe([url('/portal')]);
});

it('sends a refused admin page back to the internal app, not to the client login', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    $html = $this->actingAs($accountant, 'web')
        ->get(ClientResource::getUrl('index', panel: 'admin'))
        ->assertNotFound()
        ->getContent();

    expect(pwaErrorPageExits($html))->toBe([url('/admin')]);
});

/**
 * Request cập nhật Livewire đi tới `/livewire-…/update`, không nằm dưới `/admin` — panel của nó chỉ
 * biết qua middleware BỀN `SetUpPanel` mà Livewire chạy lại theo route gốc của component. Livewire
 * vẽ trang lỗi vào hộp lỗi của nó và đặt `target="_top"` cho mọi liên kết, nên nút về điều hướng
 * cả cửa sổ app. Khuôn dựng request thật: `tests/Feature/Authorization/DenialCodeTest.php`.
 *
 * `Filament::setCurrentPanel(null)` giữa lượt GET và lượt POST: trong test, hai request dùng chung
 * một instance ứng dụng, nên panel mà lượt GET đặt sẽ còn đó và làm test xanh giả — xoá nó đi thì
 * chỉ `SetUpPanel` của chính lượt POST mới làm test xanh, đúng như trên máy chủ thật (mỗi request
 * một tiến trình).
 */
it('sends a refused Livewire update of an admin page back to the internal app', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($admin, 'web');

    $html = $this->get(ClientResource::getUrl('edit', ['record' => $this->client], panel: 'admin'))->assertOk()->getContent();
    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);
    $snapshot = collect($matches[1])
        ->map(fn (string $encoded): string => html_entity_decode($encoded, ENT_QUOTES))
        ->first(fn (string $decoded): bool => (json_decode($decoded, true)['memo']['name'] ?? null) === EditClient::class);
    expect($snapshot)->not->toBeNull();

    $admin->update(['is_active' => false]);
    $this->actingAs($admin->fresh(), 'web');
    Filament::setCurrentPanel(null);

    // Đúng header mà `livewire.js` gửi (`Content-type: application/json`, `X-Livewire: 1`) và KHÔNG
    // `Accept: application/json` — `postJson()` thêm header đó và nhận lỗi dạng JSON, thứ trình
    // duyệt không bao giờ thấy; Livewire nhận HTML của trang lỗi và vẽ nó vào hộp lỗi.
    $response = $this->call('POST', Livewire::getUpdateUri(), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_LIVEWIRE' => '1',
    ], content: json_encode([
        'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]],
    ]));

    $response->assertNotFound();
    expect($response->getContent())->toContain(e(__('portal_progress.not_found.heading')))
        ->and(parse_url(Livewire::getUpdateUri(), PHP_URL_PATH))->not->toStartWith('/admin')
        ->and(pwaErrorPageExits($response->getContent()))->toBe([url('/admin')]);
});

/** Khớp theo ĐOẠN path, không theo tiền tố chuỗi: `/adminxyz` không phải `/admin`. */
it('answers a path that merely starts with a panel name like any other unknown path', function () {
    $lookalike = $this->get('/adminxyz/khong-co')->assertNotFound()->getContent();
    $unknown = $this->get('/khong-co')->assertNotFound()->getContent();

    expect(pwaErrorPageExits($lookalike))->toBe([url('/portal')])
        ->and($lookalike)->toBe($unknown);
});

/**
 * M8 R7: một IP ngoài `ADMIN_IP_ALLOWLIST` không được biết `/admin` có gì đặc biệt — trang 404 nó
 * nhận dưới `/admin` phải giống TỪNG BYTE trang 404 của một path lạ bất kỳ. Trong danh sách thì về
 * `/admin` như mọi nhân sự.
 */
it('does not tell an IP outside the admin allowlist that /admin is special', function () {
    config(['vkcrm.security.admin_ip_allowlist' => '10.0.0.1']);

    $outsideAdmin = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
        ->get('/admin/khong-co')->assertNotFound()->getContent();
    $outsideLogin = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
        ->get('/admin/login')->assertNotFound()->getContent();
    $outsideUnknown = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
        ->get('/khong-co')->assertNotFound()->getContent();
    $inside = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->get('/admin/khong-co')->assertNotFound()->getContent();

    expect($outsideAdmin)->toBe($outsideUnknown)
        ->and($outsideLogin)->toBe($outsideUnknown)
        ->and(pwaErrorPageExits($outsideUnknown))->toBe([url('/portal')])
        ->and(pwaErrorPageExits($inside))->toBe([url('/admin')]);
});

/**
 * Phần máy thật (PENDING OWNER): bước A10 của danh sách kiểm tra cho chủ văn phòng gọi đúng tên
 * trang và tên nút mà người dùng thấy, và đúng thời hạn liên kết — cùng thói quen của
 * `SurveyDocsTest.php` (một lần đổi nhãn làm test đỏ thay vì để chủ văn phòng đi tìm một cái nút
 * không còn tên đó).
 */
it('gives the owner a real-device step for the exit of the expired-link page, in both apps, with the real labels', function () {
    $checklist = str_replace("\r\n", "\n", (string) file_get_contents(base_path('docs/research/2026-10-01-pwa-kiem-tra-may-that.md')));

    expect(preg_match('/^\| A10 \|.*$/m', $checklist, $row))->toBe(1);

    expect($row[0])->toContain(__('portal_progress.link_expired.heading'))
        ->toContain(__('portal_progress.link_expired.home'))
        ->toContain(Document::DOWNLOAD_LINK_MINUTES.' phút')
        ->toContain('cả hai app')
        ->toContain('cửa sổ app')
        ->and($checklist)->toContain('| A10 | | (không áp dụng) | |');
});
