<?php

use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Filament\Portal\Pages\MatterProgress;
use App\Http\Controllers\DocumentDownloadController;
use App\Http\Middleware\RestrictAdminIpAllowlist;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| M12 Task 3 — route tải bí danh TRONG scope của từng app (phán quyết tạm 1 của Task 1)
|--------------------------------------------------------------------------
|
| Trên iPhone, một app đã cài mở URL ngoài scope trong một trình duyệt trong app — tài liệu không
| đủ chắc nó mang cookie phiên theo (khảo sát Task 1, mục 1.1, 2.5), và thiếu cookie thì route tải
| trả 404 cho MỌI tài liệu. Phán quyết tạm: thêm `/portal/documents/{document}/download` và
| `/admin/documents/{document}/download`, CÙNG controller, CÙNG middleware (`signed`,
| `throttle:document-download`), và `Document::downloadUrlFor()` — nơi ký URL duy nhất — chọn route
| theo KIỂU người nhận (`ClientUser` → cổng, `User` → nội bộ), không theo panel hiện hành: URL còn
| có thể được dựng ngoài một request của panel.
|
| Mọi luật của route gốc (SPEC §10.4, §10.10, §4.12) phải đứng nguyên trên route bí danh —
| `tests/Feature/Http/DocumentDownloadTest.php` giờ đi qua route bí danh ở mọi test dựng URL bằng
| `downloadUrlFor()`; tệp này ghim phần riêng của bí danh.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['media-library.prefix' => 'test-'.Str::random(16)]);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->create(['client_id' => $this->client->id]);
    $this->matter = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lawyer->id]);
});

function pwaAliasDocument(Matter $matter): Document
{
    $document = Document::factory()->create([
        'matter_id' => $matter->id,
        'group' => DocumentGroup::ClientProvided,
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
        'title' => 'Giấy chứng nhận quyền sử dụng đất',
    ]);

    $document->addMedia(UploadedFile::fake()->createWithContent('nguon.pdf', '%PDF-1.4 noi dung that'))
        ->usingName('Ban sao.pdf')
        ->usingFileName('01k5g7q8wz0000000000000000.pdf')
        ->toMediaCollection('file');

    return $document->refresh();
}

it('signs a client link on the portal alias and a staff link on the internal alias', function () {
    $document = pwaAliasDocument($this->matter);

    expect(parse_url($document->downloadUrlFor($this->clientUser), PHP_URL_PATH))->toBe("/portal/documents/{$document->id}/download")
        ->and(parse_url($document->downloadUrlFor($this->lawyer), PHP_URL_PATH))->toBe("/admin/documents/{$document->id}/download");
});

/**
 * Việc sau gộp M12 (làn fu4, mục 4): bí danh mang giới hạn IP của admin (M8 R7) khi và chỉ khi panel
 * của nó mang — cùng luật `$ipGate` của `routes/pwa.php` — và ĐỨNG TRƯỚC `signed`, để một IP ngoài
 * danh sách nhận 404 như mọi path khác dưới `/admin`, không phải trang 403 "đường dẫn hết hạn". Ngoài
 * giới hạn đó, middleware của bí danh đúng bằng của route gốc.
 */
it('registers the two aliases with the same controller and the same middleware as the original route, outside the panels', function (string $panel) {
    $original = Route::getRoutes()->getByName('documents.download');
    $alias = Route::getRoutes()->getByName("documents.download.{$panel}");
    $ipGate = in_array(RestrictAdminIpAllowlist::class, Filament::getPanel($panel)->getMiddleware(), true);

    expect($alias)->not->toBeNull()
        ->and($ipGate)->toBe($panel === 'admin')
        ->and($alias->uri())->toBe("{$panel}/documents/{document}/download")
        ->and($alias->methods())->toBe(['GET', 'HEAD'])
        ->and($alias->getActionName())->toBe(DocumentDownloadController::class)
        ->and($alias->getActionName())->toBe($original->getActionName())
        ->and($alias->gatherMiddleware())->toBe($ipGate
            ? ['web', RestrictAdminIpAllowlist::class, 'signed', 'throttle:document-download']
            : $original->gatherMiddleware())
        ->and($original->gatherMiddleware())->toBe(['web', 'signed', 'throttle:document-download']);
})->with(['admin', 'portal']);

/**
 * Việc sau gộp M12 (làn fu4, mục 4) — "máy lạ không biết `/admin` tồn tại" (M8 R7). Từ một IP ngoài
 * `ADMIN_IP_ALLOWLIST`, bí danh nội bộ trả 404 — có chữ ký hay không — đúng như trang đăng nhập
 * `/admin/login`; trước đó nó trả trang 403 riêng của `signed`. Từ IP trong danh sách, đường dẫn ký
 * cho luật sư vẫn tải được. Bí danh của cổng (không có allowlist) và route gốc `/documents/…` (đường
 * dẫn đã phát trước khi triển khai) không đổi.
 *
 * Mutation probe (báo cáo fu4): bỏ `->middleware($ipGate)` ở nhóm bí danh → hai vế 404 ĐỎ (403 và 200).
 */
it('answers 404 on the internal alias outside the admin allowlist and still serves it inside', function () {
    config(['vkcrm.security.admin_ip_allowlist' => '10.0.0.1']);
    $document = pwaAliasDocument($this->matter);
    $staffUrl = $document->downloadUrlFor($this->lawyer);
    $unsigned = "/admin/documents/{$document->id}/download";

    $outside = fn () => $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50']);
    $inside = fn () => $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1']);

    // Cổng khách không có allowlist: bí danh của cổng tới mọi IP.
    $clientUrl = $document->downloadUrlFor($this->clientUser);
    $outside()->actingAs($this->clientUser, 'client')->get($clientUrl)->assertOk();
    auth('client')->logout();

    $outside()->get('/admin/login')->assertNotFound();
    // Từng byte như trang 404 của một path lạ (cùng luật `ErrorPageHomeLinkTest`): không nút nào trỏ `/admin`.
    expect($outside()->get($unsigned)->assertNotFound()->getContent())
        ->toBe($outside()->get('/khong-co')->assertNotFound()->getContent());
    $outside()->actingAs($this->lawyer, 'web')->get($staffUrl)->assertNotFound();

    $inside()->get($unsigned)->assertForbidden();
    $response = $inside()->actingAs($this->lawyer, 'web')->get($staffUrl);
    $response->assertOk();
    expect($response->streamedContent())->toBe('%PDF-1.4 noi dung that')
        ->and(DocumentDownload::withoutGlobalScopes()->where('document_id', $document->id)->where('downloader_type', 'user')->count())->toBe(1);
});

it('serves the file to the client through the portal alias and records exactly one download', function () {
    $document = pwaAliasDocument($this->matter);

    $response = $this->actingAs($this->clientUser, 'client')->get($document->downloadUrlFor($this->clientUser));

    $response->assertOk();
    expect($response->streamedContent())->toBe('%PDF-1.4 noi dung that')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->and(DocumentDownload::withoutGlobalScopes()->where('document_id', $document->id)->count())->toBe(1)
        ->and(DocumentDownload::withoutGlobalScopes()->first()->downloader_type)->toBe('client_user');
});

it('serves the file to the lawyer through the internal alias and records exactly one download', function () {
    $document = pwaAliasDocument($this->matter);

    $response = $this->actingAs($this->lawyer, 'web')->get($document->downloadUrlFor($this->lawyer));

    $response->assertOk();
    expect($response->streamedContent())->toBe('%PDF-1.4 noi dung that')
        ->and(DocumentDownload::withoutGlobalScopes()->where('document_id', $document->id)->count())->toBe(1);
});

/**
 * Chữ ký phủ cả PATH: một URL ký cho cổng mà bị đổi tiền tố sang `/admin` (hay về route gốc) là
 * một URL không do hệ thống phát ra — 403 của `signed`, trước khi đọc bản ghi nào.
 */
it('breaks the signature when the panel prefix of a signed link is swapped', function () {
    $document = pwaAliasDocument($this->matter);
    $url = $document->downloadUrlFor($this->clientUser);

    $this->actingAs($this->clientUser, 'client')->get(str_replace('/portal/documents/', '/admin/documents/', $url))->assertForbidden();
    $this->actingAs($this->clientUser, 'client')->get(str_replace('/portal/documents/', '/documents/', $url))->assertForbidden();
    $this->actingAs($this->clientUser, 'client')->get($url)->assertOk();
});

it('keeps the 403 of an expired alias link', function () {
    $document = pwaAliasDocument($this->matter);
    $url = $document->downloadUrlFor($this->clientUser);

    $this->travel(Document::DOWNLOAD_LINK_MINUTES + 1)->minutes();

    $this->actingAs($this->clientUser, 'client')->get($url)->assertForbidden();
});

it('keeps the 404 of the original route for every refusal on the alias', function () {
    $document = pwaAliasDocument($this->matter);
    $otherClientUser = ClientUser::factory()->create(['client_id' => Client::factory()->create()->id]);
    $outsider = User::factory()->withRole(Role::Lawyer)->create();

    // Người khác mở đường dẫn ký cho khách này.
    $this->actingAs($otherClientUser, 'client')->get($document->downloadUrlFor($this->clientUser))->assertNotFound();
    // Khách khác, đường dẫn ký cho chính họ, tài liệu không phải của họ.
    $this->actingAs($otherClientUser, 'client')->get($document->downloadUrlFor($otherClientUser))->assertNotFound();
    // Nhân sự ngoài đội ngũ, nhóm A.
    $internal = pwaAliasDocument($this->matter);
    $internal->update(['group' => DocumentGroup::Authority]);
    $this->actingAs($outsider, 'web')->get($internal->downloadUrlFor($outsider))->assertNotFound();
    // Không đăng nhập: 404, không chuyển hướng về trang đăng nhập (alias KHÔNG thuộc panel).
    $this->get($document->downloadUrlFor($this->clientUser))->assertNotFound();

    expect(DocumentDownload::withoutGlobalScopes()->count())->toBe(0);
});

/** SPEC §10.9: tài khoản bị vô hiệu sau khi đường dẫn được ký không tải được qua bí danh. */
it('refuses a client deactivated after the alias link was signed', function () {
    $document = pwaAliasDocument($this->matter);
    $url = $document->downloadUrlFor($this->clientUser);

    $this->clientUser->update(['is_active' => false]);

    $this->actingAs($this->clientUser->fresh(), 'client')->get($url)->assertNotFound();
});

/**
 * M8 R7 cho route GỐC đứng nguyên: `documents.download` (`/documents/…`, không dưới `/admin`) không
 * nằm sau allowlist IP — một đường dẫn đã phát cho nhân sự trước lúc triển khai vẫn tải được từ ngoài
 * dải. Việc sau gộp M12 (làn fu4, mục 4) chỉ đổi bí danh `/admin/…` (test "answers 404 on the internal
 * alias…" ở trên); ca này giữ ranh giới đó.
 */
it('keeps the original route open to a staff link from outside the admin allowlist', function () {
    config(['vkcrm.security.admin_ip_allowlist' => '10.0.0.1']);
    $document = pwaAliasDocument($this->matter);
    $rootUrl = URL::temporarySignedRoute('documents.download', now()->addMinutes(Document::DOWNLOAD_LINK_MINUTES), [
        'document' => $document->id,
        Document::DOWNLOAD_RECIPIENT_PARAMETER => Document::recipientToken($this->lawyer),
    ]);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
        ->actingAs($this->lawyer, 'web')
        ->get($rootUrl)
        ->assertOk();

    expect(DocumentDownload::withoutGlobalScopes()->count())->toBe(1);
});

/**
 * Giới hạn lượt tải đếm theo TÀI KHOẢN, CHUNG cho route gốc và hai bí danh (cùng bộ đếm có tên
 * `throttle:document-download`): nửa đầu đi qua route gốc (URL đã phát trước lúc triển khai), nửa
 * sau qua bí danh, và lượt kế tiếp chạm trần.
 */
it('counts downloads through the alias against the same per-account limit as the original route', function () {
    $document = pwaAliasDocument($this->matter);
    $this->actingAs($this->clientUser, 'client');

    $original = URL::temporarySignedRoute('documents.download', now()->addMinutes(Document::DOWNLOAD_LINK_MINUTES), [
        'document' => $document->id,
        Document::DOWNLOAD_RECIPIENT_PARAMETER => Document::recipientToken($this->clientUser),
    ]);
    expect(parse_url($original, PHP_URL_PATH))->toBe("/documents/{$document->id}/download");

    $half = intdiv(DocumentDownloadController::DOWNLOADS_PER_MINUTE, 2);

    foreach (range(1, $half) as $attempt) {
        $this->get($original)->assertOk();
    }

    foreach (range($half + 1, DocumentDownloadController::DOWNLOADS_PER_MINUTE) as $attempt) {
        $this->get($document->downloadUrlFor($this->clientUser))->assertOk();
    }

    $this->get($document->downloadUrlFor($this->clientUser))->assertTooManyRequests();
    $this->get($original)->assertTooManyRequests();
});

/**
 * Màn hình thật của khách (SPEC §8.3, khối Tài liệu): nút tải trỏ vào bí danh `/portal/…` — tức
 * TRONG scope của app cổng khách — và mở trong cùng cửa sổ (không `target`), nên trên iPhone lượt
 * tải ở lại cửa sổ app đã cài, cùng cookie phiên.
 */
it('links the download button of the client progress page to the portal alias, in the same window', function () {
    $activated = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);
    $this->matter->update(['is_published_to_portal' => true]);
    $document = Document::factory()->for($this->matter)->group(DocumentGroup::Issued)->create([
        'title' => 'Quyết định của toà',
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
    ]);

    $html = $this->actingAs($activated, 'client')
        ->get(MatterProgress::getUrl(['record' => $this->matter->getKey()], panel: 'portal'))
        ->assertOk()
        ->getContent();

    preg_match_all('/<a\b[^>]*\/documents\/\d+\/download[^>]*>/', $html, $links);

    expect($links[0])->toHaveCount(1)
        ->and($links[0][0])->toMatch('#href="[^"]*/portal/documents/'.$document->id.'/download\?#')
        ->and($links[0][0])->not->toContain('target=');
});
