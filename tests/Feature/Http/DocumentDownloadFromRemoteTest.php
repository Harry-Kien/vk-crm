<?php

use App\Actions\Document\RetractDocument;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Exceptions\DocumentStorageUnavailable;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\User;
use App\Support\OfficeProfile;
use App\Support\Scopes\ClientPortalScope;
use App\Support\Storage\DocumentStore;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Support\FakeGoogleDrive;
use Tests\Support\RemoteDocuments;
use Tests\Support\SpyFilesystem;

/*
|--------------------------------------------------------------------------
| M14 Task 4 — route tải đọc tệp trên kho (kế hoạch M14, R3, R9)
|--------------------------------------------------------------------------
|
| Thứ tự của R3: kiểm quyền → mở luồng → ghi nhật ký → stream. Hai cách khoá "không chạm kho trước khi
| kiểm quyền", mỗi cách có cặp dương:
|  (a) đĩa gián điệp (`SpyFilesystem`) làm `documents_remote`: mọi nhánh từ chối đếm 0 lời gọi MỌI loại;
|  (b) adapter Drive THẬT trên `Http::fake()` + `Http::preventStrayRequests()` (`RemoteDocuments::
|      bindRealDriveAdapter()`): mọi nhánh từ chối `Http::assertNothingSent()`.
| `Http::assertNothingSent()` trên đĩa giả trần không đỏ được (đĩa giả không gửi HTTP), nên không có ở
| đây một mình.
|
| URL dựng bằng `$document->downloadUrlFor($recipient)`, không viết cứng tên route (M12 đổi bí danh).
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->create(['client_id' => $this->client->id]);
    $this->matter = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lawyer->id]);
});

const RFR_CONTENT = "%PDF-1.4\n% noi dung tai lieu tren kho\n%%EOF\n";

/** Tài liệu đã công bố, tệp thật trong vùng đệm, tên tệp đúng khuôn khoá R4. */
function rfrDocument(Matter $matter, array $attributes = [], string $extension = 'pdf', string $content = RFR_CONTENT, string $mediaName = 'Ban chup giay to.pdf'): Document
{
    $document = Document::factory()->create([
        'matter_id' => $matter->id,
        'group' => DocumentGroup::ClientProvided,
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
        'title' => 'Giấy chứng nhận quyền sử dụng đất',
        'published_at' => now(),
        ...$attributes,
    ]);

    $document->addMedia(UploadedFile::fake()->createWithContent('nguon.'.$extension, $content))
        ->usingName($mediaName)
        ->usingFileName(Str::lower((string) Str::ulid()).($extension === '' ? '' : '.'.$extension))
        ->toMediaCollection('file');

    return $document->refresh();
}

/**
 * Đặt tệp của tài liệu lên kho theo cách (a) hay (b). Trả đĩa gián điệp ở (a), Drive giả ở (b).
 */
function rfrWire(Document $document, string $way): SpyFilesystem|FakeGoogleDrive
{
    $media = $document->getFirstMedia('file');

    if ($way === 'spy') {
        RemoteDocuments::pushToRemote($media);

        return SpyFilesystem::install();
    }

    return RemoteDocuments::bindRealDriveAdapter([$media]);
}

/**
 * Bảy nhánh từ chối của brief Task 4. Mỗi nhánh dựng tài liệu của nó, gắn kho bằng `$wire`, rồi trả
 * [request, mã trạng thái mong đợi]. Thay đổi SAU khi phát đường dẫn (rút, vô hiệu, hết hạn) xảy ra
 * trong closure request, đúng như ngoài đời.
 *
 * @return array{0: Closure(): TestResponse, 1: int}
 */
function rfrDenial(object $test, string $case, Closure $wire): array
{
    switch ($case) {
        case 'chữ ký hỏng':
            $document = rfrDocument($test->matter);
            $wire($document);
            $url = $document->downloadUrlFor($test->lawyer).'0';

            return [fn () => $test->actingAs($test->lawyer, 'web')->get($url), 403];

        case 'chữ ký hết hạn':
            $document = rfrDocument($test->matter);
            $wire($document);
            $url = $document->downloadUrlFor($test->lawyer);

            return [function () use ($test, $url) {
                $test->travel(Document::DOWNLOAD_LINK_MINUTES * 60 + 1)->seconds();

                return $test->actingAs($test->lawyer, 'web')->get($url);
            }, 403];

        case 'sai người nhận':
            // Người mở cũng có quyền (admin) — chỉ đường dẫn không phải phát cho họ.
            $admin = User::factory()->withRole(Role::Admin)->create();
            $document = rfrDocument($test->matter);
            $wire($document);
            $url = $document->downloadUrlFor($test->lawyer);

            return [fn () => $test->actingAs($admin, 'web')->get($url), 404];

        case 'khách xin tài liệu nhóm D':
            // Cờ khách bật thẳng trong CSDL: chỉ policy (nhóm D không bao giờ tới cổng) chặn.
            $document = rfrDocument($test->matter, ['group' => DocumentGroup::Internal]);
            $wire($document);
            $url = $document->downloadUrlFor($test->clientUser);

            return [fn () => $test->actingAs($test->clientUser, 'client')->get($url), 404];

        case 'nhân sự ngoài đội ngũ, vụ restricted':
            // Trưởng phòng xem được mọi vụ thường, nhưng không vụ `restricted` mình không phụ trách.
            $manager = User::factory()->withRole(Role::Manager)->create();
            $restricted = Matter::factory()->restricted()->for($test->client)->create(['lead_lawyer_id' => $test->lawyer->id]);
            $document = rfrDocument($restricted, ['group' => DocumentGroup::Authority]);
            $wire($document);
            $url = $document->downloadUrlFor($manager);

            return [fn () => $test->actingAs($manager, 'web')->get($url), 404];

        case 'khách xin tài liệu của khách khác':
            $other = ClientUser::factory()->create(['client_id' => Client::factory()->create()->id]);
            $document = rfrDocument($test->matter);
            $wire($document);
            $url = $document->downloadUrlFor($other);

            return [fn () => $test->actingAs($other, 'client')->get($url), 404];

        case 'tài liệu đã rút':
            $document = rfrDocument($test->matter);
            $wire($document);
            $url = $document->downloadUrlFor($test->clientUser);

            return [function () use ($test, $document, $url) {
                app(RetractDocument::class)->handle($document->refresh(), $test->lawyer, 'Văn phòng gửi nhầm bản, sẽ gửi lại bản đúng.');

                return $test->actingAs($test->clientUser, 'client')->get($url);
            }, 404];

        case 'khách đã bị vô hiệu hoá':
            $document = rfrDocument($test->matter);
            $wire($document);
            $url = $document->downloadUrlFor($test->clientUser);

            return [function () use ($test, $url) {
                $test->clientUser->update(['is_active' => false]);

                return $test->actingAs($test->clientUser->refresh(), 'client')->get($url);
            }, 404];
    }

    throw new LogicException($case);
}

dataset('rfr denials', [
    'chữ ký hỏng',
    'chữ ký hết hạn',
    'sai người nhận',
    'khách xin tài liệu nhóm D',
    'nhân sự ngoài đội ngũ, vụ restricted',
    'khách xin tài liệu của khách khác',
    'tài liệu đã rút',
    'khách đã bị vô hiệu hoá',
]);

// ---------------------------------------------------------------------------------------------
// R3 — không chạm kho trước khi kiểm quyền
// ---------------------------------------------------------------------------------------------

it('(a) đĩa gián điệp: nhánh từ chối không gọi kho lần nào, mọi loại lời gọi', function (string $case) {
    $spy = null;
    [$request, $status] = rfrDenial($this, $case, function (Document $document) use (&$spy) {
        $spy = rfrWire($document, 'spy');
    });

    $request()->assertStatus($status);

    expect($spy->methods())->toBe([])
        ->and(DocumentDownload::query()->withoutGlobalScope(ClientPortalScope::class)->count())->toBe(0);
})->with('rfr denials');

it('(b) adapter Drive thật: nhánh từ chối không gửi request nào tới Google', function (string $case) {
    [$request, $status] = rfrDenial($this, $case, fn (Document $document) => rfrWire($document, 'drive'));

    $request()->assertStatus($status);

    Http::assertNothingSent();
    expect(DocumentDownload::query()->withoutGlobalScope(ClientPortalScope::class)->count())->toBe(0);
})->with('rfr denials');

it('(a) cặp dương: người có quyền tải, kho bị đọc đúng một lần (readStream), không hỏi cỡ, kiểu hay md5', function (string $who) {
    $document = rfrDocument($this->matter);
    $spy = rfrWire($document, 'spy');
    $actor = $who === 'client' ? $this->clientUser : $this->lawyer;

    $response = $this->actingAs($actor, $who === 'client' ? 'client' : 'web')->get($document->downloadUrlFor($actor));

    $response->assertOk();
    expect($response->streamedContent())->toBe(RFR_CONTENT)
        ->and($spy->count('readStream'))->toBe(1)
        ->and($spy->count('fileSize') + $spy->count('mimeType') + $spy->count('checksum') + $spy->count('read'))->toBe(0);
})->with(['client', 'staff']);

it('(b) cặp dương: người có quyền tải, đúng MỘT request tới Google và đó là GET alt=media', function (string $who) {
    $document = rfrDocument($this->matter);
    rfrWire($document, 'drive');
    $actor = $who === 'client' ? $this->clientUser : $this->lawyer;

    $response = $this->actingAs($actor, $who === 'client' ? 'client' : 'web')->get($document->downloadUrlFor($actor));

    $response->assertOk();
    expect($response->streamedContent())->toBe(RFR_CONTENT);

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(1);

    /** @var HttpRequest $request */
    $request = $recorded[0][0];

    expect($request->method())->toBe('GET')
        ->and(parse_url($request->url(), PHP_URL_QUERY))->toContain('alt=media');
})->with(['client', 'staff']);

// ---------------------------------------------------------------------------------------------
// HEAD — header từ dòng media, không mở luồng, không nhật ký
// ---------------------------------------------------------------------------------------------

it('HEAD của người có quyền: chỉ hỏi tồn tại (chỉ mục), không readStream, không dòng nhật ký', function () {
    $document = rfrDocument($this->matter);
    $spy = rfrWire($document, 'spy');
    $media = $document->getFirstMedia('file')->refresh();

    $response = $this->actingAs($this->lawyer, 'web')->head($document->downloadUrlFor($this->lawyer));

    $response->assertOk();
    expect($response->streamedContent())->toBe('')
        ->and($spy->methods())->toBe(['fileExists'])
        ->and($response->headers->get('Content-Length'))->toBe((string) $media->size)
        ->and($response->headers->get('Content-Type'))->toBe($media->mime_type)
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->and(DocumentDownload::query()->withoutGlobalScope(ClientPortalScope::class)->count())->toBe(0)
        ->and(Activity::query()->where('event', 'document_downloaded')->count())->toBe(0);
});

it('HEAD với adapter Drive thật: không request nào tới Google', function () {
    $document = rfrDocument($this->matter);
    rfrWire($document, 'drive');

    $this->actingAs($this->clientUser, 'client')->head($document->downloadUrlFor($this->clientUser))->assertOk();

    Http::assertNothingSent();
    expect(DocumentDownload::query()->withoutGlobalScope(ClientPortalScope::class)->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------
// R9 — kho sập: 503 tiếng Việt, không nhật ký
// ---------------------------------------------------------------------------------------------

it('kho sập lúc mở luồng: nhân sự và khách đều nhận trang 503 tiếng Việt kèm Retry-After, không một dòng nhật ký', function (string $who) {
    $document = rfrDocument($this->matter);
    RemoteDocuments::pushToRemote($document->getFirstMedia('file'));
    // Tài liệu là gói bàn giao, để "không data_exported" là một khẳng định có thể đỏ.
    MatterArchive::factory()->create(['matter_id' => $this->matter->id, 'handover_document_id' => $document->id]);
    expect(MatterArchive::isHandoverDocument($document))->toBeTrue();

    SpyFilesystem::install(hooks: ['readStream' => fn () => throw DocumentStorageUnavailable::temporarily()]);
    $actor = $who === 'client' ? $this->clientUser : $this->lawyer;

    $response = $this->actingAs($actor, $who === 'client' ? 'client' : 'web')->get($document->downloadUrlFor($actor));

    $response->assertStatus(503)
        ->assertHeader('Retry-After', '120')
        ->assertSee('Kho tài liệu tạm thời chưa truy cập được. Tài liệu vẫn được lưu an toàn; vui lòng thử lại sau ít phút.');

    expect($response->getContent())->toContain('<html lang="vi">')
        ->and(DocumentDownload::query()->withoutGlobalScope(ClientPortalScope::class)->count())->toBe(0)
        ->and(Activity::query()->where('event', 'document_downloaded')->count())->toBe(0)
        ->and(Activity::query()->where('event', 'data_exported')->count())->toBe(0);
})->with(['client', 'staff']);

it('trang 503 của khách mang số hotline văn phòng; của nhân sự thì không', function () {
    $document = rfrDocument($this->matter);
    RemoteDocuments::pushToRemote($document->getFirstMedia('file'));
    SpyFilesystem::install(hooks: ['readStream' => fn () => throw DocumentStorageUnavailable::temporarily()]);
    $hotline = OfficeProfile::current()->hotline();

    expect($hotline)->not->toBe('');

    $this->actingAs($this->clientUser, 'client')->get($document->downloadUrlFor($this->clientUser))
        ->assertStatus(503)
        ->assertSee('tel:'.$hotline, false);

    auth('client')->logout();

    $this->actingAs($this->lawyer, 'web')->get($document->downloadUrlFor($this->lawyer))
        ->assertStatus(503)
        ->assertDontSee('tel:'.$hotline, false);
});

/*
 * Rà soát cuối M14 vòng sửa 1 (I1) — luật M12 Task 3 vòng sửa 1 (tests/Feature/Pwa/ErrorPageHomeLinkTest.php) cho
 * trang 503: liên kết tải là bí danh `/admin|/portal/documents/{id}/download` mở trong CÙNG cửa sổ app
 * đã cài, nên trang 503 hiện NGAY TRONG app. Nút "Về trang chủ" trỏ `start_url` của chính app đó
 * (`PwaPanels::startUrlFor()`), không `url('/')` — `/` chuyển tới đăng nhập của KHÁCH, ngoài scope
 * `/admin`, và trên iPhone mở một tấm Safari không có đường về. Lấy MỌI lối ra trừ `tel:` để một nút
 * thứ hai trỏ ra ngoài app cũng làm test đỏ.
 */
it('trang 503 trong app: nút về trang chủ trỏ đầu CHÍNH app — bí danh nội bộ về /admin, bí danh cổng về /portal', function (string $app) {
    $document = rfrDocument($this->matter);
    RemoteDocuments::pushToRemote($document->getFirstMedia('file'));
    SpyFilesystem::install(hooks: ['readStream' => fn () => throw DocumentStorageUnavailable::temporarily()]);
    $actor = $app === 'portal' ? $this->clientUser : $this->lawyer;
    $url = $document->downloadUrlFor($actor);

    expect(parse_url($url, PHP_URL_PATH))->toBe("/{$app}/documents/{$document->id}/download");

    $html = $this->actingAs($actor, $app === 'portal' ? 'client' : 'web')->get($url)
        ->assertStatus(503)
        ->getContent();

    preg_match_all('/<a\b[^>]*\bhref="([^"]*)"/', $html, $matches);
    $exits = array_values(array_filter(
        array_map(fn (string $href): string => html_entity_decode($href, ENT_QUOTES), $matches[1]),
        fn (string $href): bool => ! str_starts_with($href, 'tel:'),
    ));

    expect($exits)->toBe([url("/{$app}")])
        ->and($html)->toMatch('#<a href="'.preg_quote(url("/{$app}"), '#').'"[^>]*>\s*'.preg_quote(e(__('storage.unavailable_page.home')), '#').'\s*</a>#');
})->with(['admin', 'portal']);

it('kho sập thật (Google trả 503 hết lượt thử): 503 tiếng Việt, không nhật ký', function () {
    $document = rfrDocument($this->matter);
    $drive = rfrWire($document, 'drive');
    $drive->failNext('GET', 'alt=media', 503, times: 5);

    $this->actingAs($this->lawyer, 'web')->get($document->downloadUrlFor($this->lawyer))
        ->assertStatus(503)
        ->assertHeader('Retry-After', '120');

    expect(DocumentDownload::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------
// R3 — chỉ mục nói có, kho nói không: 404 + log critical
// ---------------------------------------------------------------------------------------------

it('kho trả 404 cho một tệp chỉ mục còn ghi: 404, không dòng nhật ký, có log critical', function () {
    $document = rfrDocument($this->matter);
    $drive = rfrWire($document, 'drive');
    $media = $document->getFirstMedia('file')->refresh();

    // Tệp biến mất khỏi Drive (ai đó xoá ngoài CRM); dòng chỉ mục còn sống.
    foreach ($drive->files as $id => $file) {
        if ($file['mimeType'] !== 'application/vnd.google-apps.folder') {
            unset($drive->files[$id]);
        }
    }

    Log::spy();

    $this->actingAs($this->lawyer, 'web')->get($document->downloadUrlFor($this->lawyer))->assertNotFound();

    expect(DocumentDownload::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'document_downloaded')->count())->toBe(0);

    Log::shouldHaveReceived('critical')->withArgs(fn (string $message, array $context = []): bool => ($context['media_id'] ?? null) === $media->id)->once();
});

// ---------------------------------------------------------------------------------------------
// Thành công: byte, header từ dòng media, đúng một dòng sổ
// ---------------------------------------------------------------------------------------------

it('tải thành công từ kho: đúng byte, Content-Length/Content-Type từ dòng media, Cache-Control và nosniff như cũ, đúng một dòng sổ', function () {
    $document = rfrDocument($this->matter);
    $media = RemoteDocuments::pushToRemote($document->getFirstMedia('file'));

    expect($media->disk)->toBe(DocumentStore::REMOTE_DISK)
        ->and(DocumentStore::staging()->exists($media->getPathRelativeToRoot()))->toBeFalse();

    $response = $this->actingAs($this->clientUser, 'client')->get($document->downloadUrlFor($this->clientUser));

    $response->assertOk();
    expect($response->streamedContent())->toBe(RFR_CONTENT)
        ->and($response->headers->get('Content-Length'))->toBe((string) strlen(RFR_CONTENT))
        ->and((int) $response->headers->get('Content-Length'))->toBe((int) $media->size)
        ->and($response->headers->get('Content-Type'))->toBe($media->mime_type)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and(DocumentDownload::query()->withoutGlobalScope(ClientPortalScope::class)->count())->toBe(1);
});

it('header lấy từ dòng media, không từ kho: đổi mime_type và size trên dòng thì header đổi theo', function () {
    $document = rfrDocument($this->matter);
    $media = RemoteDocuments::pushToRemote($document->getFirstMedia('file'));
    Media::query()->toBase()->where('id', $media->id)->update(['mime_type' => 'application/x-test-from-row']);

    $response = $this->actingAs($this->lawyer, 'web')->get($document->downloadUrlFor($this->lawyer));

    expect($response->headers->get('Content-Type'))->toBe('application/x-test-from-row');
});

// ---------------------------------------------------------------------------------------------
// Tên tải trên nhánh kho — một helper Content-Disposition có bản dự phòng ASCII
// ---------------------------------------------------------------------------------------------

it('tên tải trên nhánh kho: tiêu đề tiếng Việt có dấu → filename= ASCII và filename*=UTF-8, không ngoại lệ', function () {
    $document = rfrDocument($this->matter, ['title' => 'Quyết định thụ lý']);
    RemoteDocuments::pushToRemote($document->getFirstMedia('file'));

    $disposition = $this->actingAs($this->clientUser, 'client')
        ->get($document->downloadUrlFor($this->clientUser))
        ->assertOk()
        ->headers->get('Content-Disposition');

    expect($disposition)->toBe('attachment; filename="Quyet dinh thu ly.pdf"; '
        ."filename*=utf-8''Quy%E1%BA%BFt%20%C4%91%E1%BB%8Bnh%20th%E1%BB%A5%20l%C3%BD.pdf");
});

it('tên tải trên nhánh kho: tiêu đề có dấu % → không ngoại lệ, % bỏ ở bản ASCII', function () {
    $document = rfrDocument($this->matter, ['title' => 'Chia 50% di sản']);
    RemoteDocuments::pushToRemote($document->getFirstMedia('file'));

    $disposition = $this->actingAs($this->clientUser, 'client')
        ->get($document->downloadUrlFor($this->clientUser))
        ->assertOk()
        ->headers->get('Content-Disposition');

    expect($disposition)->toBe("attachment; filename=\"Chia 50 di san.pdf\"; filename*=utf-8''Chia%2050%25%20di%20s%E1%BA%A3n.pdf");
});

it('tên tải trên nhánh kho: tiêu đề toàn chữ Hán (bản dự phòng chỉ còn đuôi) → không ngoại lệ', function (string $way) {
    $document = rfrDocument($this->matter, ['title' => '日本語']);
    rfrWire($document, $way);

    $disposition = $this->actingAs($this->clientUser, 'client')
        ->get($document->downloadUrlFor($this->clientUser))
        ->assertOk()
        ->headers->get('Content-Disposition');

    expect($disposition)->toBe("attachment; filename=.pdf; filename*=utf-8''%E6%97%A5%E6%9C%AC%E8%AA%9E.pdf");
})->with(['spy', 'drive']);

// ---------------------------------------------------------------------------------------------
// Rút tài liệu trên kho: tệp không bị chạm
// ---------------------------------------------------------------------------------------------

it('rút tài liệu nằm trên kho: tệp trên Drive còn nguyên, đường tải ký trước lúc rút trả 404 không gọi Drive', function () {
    $document = rfrDocument($this->matter);
    $drive = rfrWire($document, 'drive');
    $url = $document->downloadUrlFor($this->clientUser);
    $before = $drive->files;

    app(RetractDocument::class)->handle($document->refresh(), $this->lawyer, 'Văn phòng gửi nhầm bản, sẽ gửi lại bản đúng.');

    $this->actingAs($this->clientUser, 'client')->get($url)->assertNotFound();

    Http::assertNothingSent();
    expect($drive->files)->toBe($before)
        ->and($document->refresh()->getFirstMedia('file')->disk)->toBe(DocumentStore::REMOTE_DISK);

    // Cặp dương: nhân sự của vụ vẫn tải được bản đã rút — tệp còn, từ chính kho.
    auth('client')->logout();
    $response = $this->actingAs($this->lawyer, 'web')->get($document->downloadUrlFor($this->lawyer));

    $response->assertOk();
    expect($response->streamedContent())->toBe(RFR_CONTENT);
});
