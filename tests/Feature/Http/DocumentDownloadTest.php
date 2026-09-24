<?php

use App\Actions\Document\RegroupDocument;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Controllers\DocumentDownloadController;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * SPEC §10.4 — route tải tệp có chữ ký, **và controller vẫn kiểm tra policy**.
 *
 * Tệp test rơi vào `Storage::fake('private')` chứ không vào `storage/app/private` thật của máy
 * dev (bài học từ vòng sửa Task 3).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('private');

    // Mỗi test ghi tệp vào một thư mục RIÊNG. `Storage::fake()` dọn sạch một gốc DÙNG CHUNG, còn
    // đường dẫn medialibrary sinh ra là `{media.id}/{file_name}` — mà `RefreshDatabase` trả id về
    // 1 ở đầu mỗi test. Hệ quả: mọi test trong tệp này ghi, xoá rồi ghi lại đúng một đường dẫn,
    // và tệp này là tệp DUY NHẤT của dự án đọc lại tệp đó qua HTTP ngay sau khi ghi. Chuỗi
    // xoá-thư-mục / tạo-lại / ghi / stat đó không ổn định trên bind mount của Docker trên
    // Windows: đo được 2 lần hỏng trên 4 lần chạy liên tiếp, lần nào cũng là test đầu tiên đọc
    // nội dung tệp, và lần nào cũng xanh khi chạy một mình. `media-library.prefix` là cách
    // medialibrary tự đưa ra để đổi gốc đường dẫn, nên mỗi test có một cây thư mục không ai khác
    // đụng tới và không có gì để tranh nhau nữa.
    config(['media-library.prefix' => 'test-'.Str::random(16)]);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->create(['client_id' => $this->client->id]);
    $this->matter = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lawyer->id]);
});

/**
 * Tài liệu luôn có tệp thật trên disk giả: route từ chối một `Document` không tệp, nên một fixture
 * không tệp sẽ làm mọi test khác xanh vì lý do sai.
 */
function downloadableDocument(
    Matter $matter,
    DocumentGroup $group = DocumentGroup::ClientProvided,
    DocumentStatus $status = DocumentStatus::Published,
    bool $view = true,
    bool $download = true,
    string $displayName = 'Giấy chứng nhận quyền sử dụng đất.pdf',
    string $storedName = '01k5g7q8wz0000000000000000.pdf',
): Document {
    $document = Document::factory()->create([
        'matter_id' => $matter->id,
        'group' => $group,
        'status' => $status,
        'client_can_view' => $view,
        'client_can_download' => $download,
    ]);

    $document->addMedia(UploadedFile::fake()->createWithContent('nguon.pdf', '%PDF-1.4 noi dung that'))
        ->usingName($displayName)
        ->usingFileName($storedName)
        ->toMediaCollection('file');

    return $document->refresh();
}

// ---------------------------------------------------------------------------------------------
// Hình dạng route: một đường duy nhất, và tiền đề mà cả milestone dựa lên.
// ---------------------------------------------------------------------------------------------

it('đăng ký đúng một route tải tệp, có middleware signed', function () {
    $route = Route::getRoutes()->getByName('documents.download');

    expect($route)->not->toBeNull()
        ->and($route->methods())->toBe(['GET', 'HEAD'])
        ->and($route->uri())->toBe('documents/{document}/download')
        ->and($route->gatherMiddleware())->toContain('signed');
});

/**
 * Tiền đề của cả M4: route ký này là đường DUY NHẤT tới một tệp. `PrivateDiskTest` giữ phần
 * "không disk nào tự phục vụ `storage/app/private`"; phần còn lại là disk MẶC ĐỊNH — Livewire
 * (`livewire/preview-file/{filename}`) và Filament (`filament/exports/{export}/download`) đều rơi
 * về nó khi không cấu hình riêng, và cả hai route đó chỉ đòi một chữ ký, không hỏi policy nào về
 * tài liệu và không ghi một dòng `document_downloads` nào. Đặt `FILESYSTEM_DISK=private` là mở
 * lại đúng lỗ hổng mà vòng sửa Task 1 vừa đóng.
 */
it('disk mặc định của ứng dụng không phải disk private', function () {
    expect(config('filesystems.default'))->not->toBe('private')
        ->and(config('livewire.temporary_file_upload.disk') ?? config('filesystems.default'))->not->toBe('private')
        ->and(config('filament.default_filesystem_disk') ?? config('filesystems.default'))->not->toBe('private');
});

it('đường dẫn ký hết hạn sau đúng 5 phút và mang mã người nhận (SPEC §10.4)', function () {
    $document = downloadableDocument($this->matter);

    parse_str((string) parse_url($document->downloadUrlFor($this->lawyer), PHP_URL_QUERY), $query);

    expect((int) $query['expires'])->toBe(now()->addMinutes(5)->getTimestamp())
        ->and($query['recipient'])->toBe('user:'.$this->lawyer->id)
        ->and($query)->toHaveKey('signature');
});

// ---------------------------------------------------------------------------------------------
// Đường xanh — mỗi "bị từ chối" ở dưới phải có một cặp sinh đôi khẳng định chiều ngược lại.
// ---------------------------------------------------------------------------------------------

it('nhân sự trong đội ngũ tải được tệp', function () {
    $document = downloadableDocument($this->matter, DocumentGroup::Authority);

    $response = $this->actingAs($this->lawyer, 'web')->get($document->downloadUrlFor($this->lawyer));

    $response->assertOk();
    expect($response->streamedContent())->toBe('%PDF-1.4 noi dung that');
});

it('khách tải được tài liệu đã công bố của chính mình', function () {
    $document = downloadableDocument($this->matter);

    $this->actingAs($this->clientUser, 'client')
        ->get($document->downloadUrlFor($this->clientUser))
        ->assertOk();
});

// ---------------------------------------------------------------------------------------------
// Chữ ký nói về ĐƯỜNG DẪN, không về một bản ghi, nên nó trả 403 và không mâu thuẫn với luật 404
// của SPEC §10.10: chưa có bản ghi nào được đọc lúc nó trả lời.
// ---------------------------------------------------------------------------------------------

it('đường dẫn hết hạn trả 403 chứ không phải 404', function () {
    $document = downloadableDocument($this->matter);
    $url = $document->downloadUrlFor($this->lawyer);

    $this->travel(6)->minutes();

    $this->actingAs($this->lawyer, 'web')->get($url)->assertForbidden();
});

/**
 * **Đường dẫn hết hạn là chuyện dùng bình thường, không phải một lần tấn công.** Href tải tệp
 * được nướng vào trang chi tiết hồ sơ lúc render và hết hạn sau 5 phút
 * ({@see Document::DOWNLOAD_LINK_MINUTES}); một khách đọc lịch sử vụ việc của mình trong sáu
 * phút rồi bấm tải là gặp đúng cảnh này. Trước khi có
 * `resources/views/errors/403.blade.php`, thứ họ nhận được là trang 403 mặc định của Laravel:
 * "Forbidden", "Invalid signature", tiếng Anh, không số điện thoại, không đường quay lại.
 *
 * Trang 403 nói về ĐƯỜNG DẪN chứ không về một bản ghi, nên nói thẳng "liên kết đã hết hạn"
 * không rò rỉ gì dưới SPEC §10.10: lúc `signed` trả lời thì chưa có bản ghi nào được đọc, và câu
 * ấy đúng như nhau cho một tài liệu có thật lẫn một id bịa — test ngay dưới ghim đúng điều đó
 * bằng phép so từng byte.
 */
it('trang 403 của đường dẫn hết hạn nói tiếng Việt, bảo bấm lại, và mang số điện thoại', function () {
    $document = downloadableDocument($this->matter);
    $url = $document->downloadUrlFor($this->lawyer);

    $this->travel(6)->minutes();

    $expired = $this->actingAs($this->lawyer, 'web')->get($url)->assertForbidden()->getContent();

    expect($expired)->toContain(__('portal_progress.link_expired.heading'))
        ->toContain(__('portal_progress.link_expired.body'))
        ->toContain(__('portal_progress.link_expired.retry'))
        // Đường đi tiếp KHÔNG qua một trang, cùng luật với trang 404.
        ->toContain(config('vkcrm.brand.hotline'))
        ->toContain('tel:')
        // Không một chữ nào của trang mặc định Laravel.
        ->not->toContain('Forbidden')
        ->not->toContain('Invalid signature')
        // Và không một chữ nào về tài liệu vừa bị từ chối. Tên hiển thị là tiền đề của phép đo
        // này, nên nó được khẳng định là không rỗng trước khi được dùng làm chuỗi phải vắng mặt.
        ->and($displayName = (string) $document->getFirstMedia('file')?->name)->not->toBe('')
        ->and($expired)->not->toContain($displayName)
        ->not->toContain((string) $this->matter->code);
});

it('trang 403 giống hệt nhau cho một tài liệu có thật và cho một id không tồn tại', function () {
    $document = downloadableDocument($this->matter);
    $real = $document->downloadUrlFor($this->lawyer);
    $fake = URL::temporarySignedRoute('documents.download', now()->addMinutes(5), [
        'document' => 999999,
        'recipient' => Document::recipientToken($this->lawyer),
    ]);

    $this->travel(6)->minutes();

    $realBody = $this->actingAs($this->lawyer, 'web')->get($real)->assertForbidden()->getContent();
    $fakeBody = $this->actingAs($this->lawyer, 'web')->get($fake)->assertForbidden()->getContent();

    expect($realBody)->toBe($fakeBody);
});

it('đường dẫn không có chữ ký trả 403', function () {
    $document = downloadableDocument($this->matter);

    $this->actingAs($this->lawyer, 'web')
        ->get(route('documents.download', ['document' => $document->id, 'recipient' => Document::recipientToken($this->lawyer)]))
        ->assertForbidden();
});

it('đổi id tài liệu trong đường dẫn đã ký làm hỏng chữ ký, trả 403', function () {
    $mine = downloadableDocument($this->matter);
    $other = downloadableDocument($this->matter);

    $url = str_replace("documents/{$mine->id}/download", "documents/{$other->id}/download", $mine->downloadUrlFor($this->lawyer));

    $this->actingAs($this->lawyer, 'web')->get($url)->assertForbidden();
});

it('chữ ký hết hạn trả 403 kể cả khi tài liệu không tồn tại — không phân biệt được hai trường hợp', function () {
    $url = URL::temporarySignedRoute('documents.download', now()->addMinutes(5), [
        'document' => 999999,
        'recipient' => Document::recipientToken($this->lawyer),
    ]);

    $this->travel(6)->minutes();

    $this->actingAs($this->lawyer, 'web')->get($url)->assertForbidden();
});

// ---------------------------------------------------------------------------------------------
// Chữ ký KHÔNG thay thế quyền: mọi từ chối ở tầng controller là 404 (SPEC §10.10).
// ---------------------------------------------------------------------------------------------

it('chữ ký hợp lệ nhưng người dùng không có quyền trả 404, không phải 403', function () {
    $document = downloadableDocument($this->matter, DocumentGroup::Authority);
    $outsider = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($outsider, 'web')
        ->get($document->downloadUrlFor($outsider))
        ->assertNotFound();
});

it('tài liệu không tồn tại trả 404 — cùng mã với không có quyền', function () {
    $url = URL::temporarySignedRoute('documents.download', now()->addMinutes(5), [
        'document' => 999999,
        'recipient' => Document::recipientToken($this->lawyer),
    ]);

    $this->actingAs($this->lawyer, 'web')->get($url)->assertNotFound();
});

it('không đăng nhập thì trả 404 chứ không chuyển hướng về trang đăng nhập', function () {
    $document = downloadableDocument($this->matter);

    $this->get($document->downloadUrlFor($this->lawyer))->assertNotFound();
});

/*
 * SPEC §10.9 — tài khoản bị vô hiệu mất hiệu lực ngay ở request kế tiếp. Route này nằm NGOÀI hai
 * panel nên nó không đi qua `canAccessPanel()`, chỗ duy nhất còn lại thi hành luật đó.
 */
it('tài khoản khách bị vô hiệu không tải được bằng đường dẫn ký trước lúc bị vô hiệu', function () {
    $document = downloadableDocument($this->matter);
    $url = $document->downloadUrlFor($this->clientUser);

    $this->clientUser->update(['is_active' => false]);

    $this->actingAs($this->clientUser->fresh(), 'client')->get($url)->assertNotFound();
});

/*
 * Task 2, vòng sửa 1 (Important #2, phần đường tải tệp) — `portal/portal-3` lặp lại ở đây. Route
 * này nằm NGOÀI cả hai panel nên `ClientUser::canAccessPanel()` không bao giờ chạy cho nó; điều
 * kiện "khách hàng chưa xoá mềm" phải lặp lại độc lập ở `DocumentDownloadController::actor()`.
 */
it('khách hàng bị xoá mềm không tải được bằng đường dẫn ký trước đó, dù tài khoản cổng vẫn is_active', function () {
    $document = downloadableDocument($this->matter);
    $url = $document->downloadUrlFor($this->clientUser);

    $this->client->delete();

    $this->actingAs($this->clientUser->fresh(), 'client')->get($url)->assertNotFound();
});

/**
 * Test trên đi qua CẢ HAI tầng cùng lúc (actor() VÀ MatterPolicy::releasedToPortal(), cả hai đều
 * đã sửa ở vòng này) — không phân biệt được tầng nào thật sự chặn, vì cả hai cùng từ chối cho
 * đúng điều kiện này. Đo THẲNG `actor()` qua reflection, bỏ qua toàn bộ phần còn lại của
 * controller (kể cả `Gate::forUser($actor)->allows('download', ...)`), để mutation probe của
 * riêng nó không lẫn với mutation probe của `releasedToPortal()` (xem `MatterPolicyTest`).
 */
it('actor() itself refuses a client user whose client has been soft deleted, independent of the policy layer', function () {
    $this->client->delete();

    $this->actingAs($this->clientUser->fresh(), 'client');

    $controller = new DocumentDownloadController;
    $method = new ReflectionMethod($controller, 'actor');
    $method->setAccessible(true);

    expect($method->invoke($controller))->toBeNull();
});

/** Vế dương của test trên: cùng đường, khách hàng CHƯA xoá thì actor() vẫn trả về tài khoản đó. */
it('actor() returns the client user when the client has not been deleted', function () {
    $this->actingAs($this->clientUser, 'client');

    $controller = new DocumentDownloadController;
    $method = new ReflectionMethod($controller, 'actor');
    $method->setAccessible(true);

    $actor = $method->invoke($controller);

    expect($actor)->not->toBeNull()
        ->and($actor->is($this->clientUser))->toBeTrue();
});

it('tài khoản nhân sự bị vô hiệu không tải được', function () {
    $document = downloadableDocument($this->matter, DocumentGroup::Authority);
    $url = $document->downloadUrlFor($this->lawyer);

    $this->lawyer->update(['is_active' => false]);

    $this->actingAs($this->lawyer->fresh(), 'web')->get($url)->assertNotFound();
});

it('tài liệu đã xoá mềm không tải được dù đường dẫn ký trước lúc xoá', function () {
    $document = downloadableDocument($this->matter, DocumentGroup::Authority);
    $url = $document->downloadUrlFor($this->lawyer);

    $document->delete();

    $this->actingAs($this->lawyer, 'web')->get($url)->assertNotFound();
});

it('tài liệu không có tệp trả 404', function () {
    $document = Document::factory()->create([
        'matter_id' => $this->matter->id,
        'group' => DocumentGroup::Authority,
        'status' => DocumentStatus::InternalDraft,
    ]);

    $this->actingAs($this->lawyer, 'web')
        ->get($document->downloadUrlFor($this->lawyer))
        ->assertNotFound();
});

it('dòng media còn nhưng tệp đã biến mất khỏi đĩa thì trả 404 và KHÔNG ghi dòng tải nào', function () {
    $document = downloadableDocument($this->matter, DocumentGroup::Authority);
    $media = $document->getFirstMedia('file');

    Storage::disk('private')->delete($media->getPathRelativeToRoot());

    $this->actingAs($this->lawyer, 'web')
        ->get($document->downloadUrlFor($this->lawyer))
        ->assertNotFound();

    expect(DocumentDownload::count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------
// SPEC §11 "Cách ly dữ liệu giữa khách hàng": khách A tải URL tài liệu của khách B → 404.
// ---------------------------------------------------------------------------------------------

it('khách A gọi thẳng URL tài liệu của khách B nhận 404', function () {
    $otherClient = Client::factory()->create();
    $otherMatter = Matter::factory()->for($otherClient)->create();
    $document = downloadableDocument($otherMatter);

    // Đường dẫn được ký ĐÚNG cho khách A, để thứ bị kiểm ở đây là quyền chứ không phải chữ ký.
    // Cặp sinh đôi — chính tài liệu này, tải được bởi người của khách B — là test ngay bên dưới.
    $this->actingAs($this->clientUser, 'client')
        ->get($document->downloadUrlFor($this->clientUser))
        ->assertNotFound();
});

it('cặp sinh đôi — khách của chính hồ sơ đó tải được tài liệu vừa bị từ chối ở trên', function () {
    $otherClient = Client::factory()->create();
    $otherMatter = Matter::factory()->for($otherClient)->create();
    $otherUser = ClientUser::factory()->create(['client_id' => $otherClient->id]);
    $document = downloadableDocument($otherMatter);

    $this->actingAs($otherUser, 'client')
        ->get($document->downloadUrlFor($otherUser))
        ->assertOk();
});

// ---------------------------------------------------------------------------------------------
// Người nhận được ký kèm: một URL đã ký không phải một tấm vé vô danh.
// ---------------------------------------------------------------------------------------------

it('đường dẫn ký cho người này mà người khác mở thì trả 404, dù người kia cũng có quyền', function () {
    $colleague = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->team()->attach($colleague, ['role_in_matter' => MatterRole::Associate->value]);
    $document = downloadableDocument($this->matter, DocumentGroup::Authority);

    $this->actingAs($colleague, 'web')
        ->get($document->downloadUrlFor($this->lawyer))
        ->assertNotFound();
});

it('cặp sinh đôi — chính đồng nghiệp đó tải được bằng đường dẫn ký cho chính họ', function () {
    $colleague = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->team()->attach($colleague, ['role_in_matter' => MatterRole::Associate->value]);
    $document = downloadableDocument($this->matter, DocumentGroup::Authority);

    $this->actingAs($colleague, 'web')
        ->get($document->downloadUrlFor($colleague))
        ->assertOk();
});

it('mã người nhận không lẫn giữa hai guard dù trùng id', function () {
    expect(Document::recipientToken($this->lawyer))->toBe('user:'.$this->lawyer->id)
        ->and(Document::recipientToken($this->clientUser))->toBe('client_user:'.$this->clientUser->id)
        ->and(Document::recipientToken($this->lawyer))->not->toBe(Document::recipientToken($this->clientUser));
});

// ---------------------------------------------------------------------------------------------
// Nhóm D: khách không bao giờ tải được, bằng mọi đường (SPEC §4.11, §11 "Tài liệu nội bộ").
// ---------------------------------------------------------------------------------------------

it('khách không tải được tài liệu nhóm D dù cờ trong CSDL bị bật thẳng', function () {
    $document = downloadableDocument($this->matter, DocumentGroup::Internal, DocumentStatus::Published);
    // Ghi thẳng, bỏ qua cả Action lẫn hook `saving` của model.
    DB::table('documents')->where('id', $document->id)
        ->update(['client_can_view' => true, 'client_can_download' => true]);

    $this->actingAs($this->clientUser, 'client')
        ->get($document->fresh()->downloadUrlFor($this->clientUser))
        ->assertNotFound();
});

it('khách không tải được bằng đường dẫn ký lúc tài liệu còn nhóm C rồi bị chuyển sang nhóm D', function () {
    $publisher = User::factory()->withRole(Role::Manager)->create();
    $this->matter->team()->attach($publisher, ['role_in_matter' => MatterRole::Associate->value]);
    $document = downloadableDocument($this->matter, DocumentGroup::Authority);

    $url = $document->downloadUrlFor($this->clientUser);

    app(RegroupDocument::class)->handle(
        document: $document,
        actor: $publisher,
        group: DocumentGroup::Internal,
    );

    $this->actingAs($this->clientUser, 'client')->get($url)->assertNotFound();
});

it('cặp sinh đôi — chính đường dẫn đó tải được khi tài liệu vẫn còn ở nhóm C', function () {
    $document = downloadableDocument($this->matter, DocumentGroup::Authority);

    $this->actingAs($this->clientUser, 'client')
        ->get($document->downloadUrlFor($this->clientUser))
        ->assertOk();
});

it('nhân sự không có quyền đọc tài liệu nội bộ cũng không tải được tài liệu nhóm D', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->team()->attach($assistant, ['role_in_matter' => MatterRole::Associate->value]);
    $document = downloadableDocument($this->matter, DocumentGroup::Internal, DocumentStatus::InternalDraft, false, false);

    expect($assistant->can(Permission::DocumentViewInternal->value))->toBeFalse();

    $this->actingAs($assistant, 'web')
        ->get($document->downloadUrlFor($assistant))
        ->assertNotFound();
});

it('cặp sinh đôi — chính trợ lý đó tải được một tài liệu nhóm C trên cùng vụ việc', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->team()->attach($assistant, ['role_in_matter' => MatterRole::Associate->value]);
    $document = downloadableDocument($this->matter, DocumentGroup::Authority, DocumentStatus::InternalDraft, false, false);

    $this->actingAs($assistant, 'web')
        ->get($document->downloadUrlFor($assistant))
        ->assertOk();
});

// ---------------------------------------------------------------------------------------------
// Hai cờ khách hàng là ĐỘC LẬP (SPEC §6.5 bước 3).
// ---------------------------------------------------------------------------------------------

it('client_can_download = false thì khách xem được mà không tải được', function () {
    $document = downloadableDocument($this->matter, download: false);

    expect($document->isReleasedToPortal())->toBeTrue()
        ->and(Gate::forUser($this->clientUser)->allows('view', $document))->toBeTrue();

    $this->actingAs($this->clientUser, 'client')
        ->get($document->downloadUrlFor($this->clientUser))
        ->assertNotFound();
});

it('nhân sự vẫn tải được tài liệu mà khách bị tắt quyền tải', function () {
    $document = downloadableDocument($this->matter, download: false);

    $this->actingAs($this->lawyer, 'web')
        ->get($document->downloadUrlFor($this->lawyer))
        ->assertOk();
});

it('khách không tải được tài liệu chưa công bố dù cờ tải đã bật', function () {
    $document = downloadableDocument($this->matter, DocumentGroup::Issued, DocumentStatus::SignedFiled);

    $this->actingAs($this->clientUser, 'client')
        ->get($document->downloadUrlFor($this->clientUser))
        ->assertNotFound();
});

it('khách không tải được tài liệu của một hồ sơ chưa công bố lên portal', function () {
    $hidden = Matter::factory()->for($this->client)->unpublished()->create();
    $document = downloadableDocument($hidden);

    $this->actingAs($this->clientUser, 'client')
        ->get($document->downloadUrlFor($this->clientUser))
        ->assertNotFound();
});

// ---------------------------------------------------------------------------------------------
// Content-Disposition (SPEC: tên tệp phải mở được ở đầu bên kia).
// ---------------------------------------------------------------------------------------------

it('tên tệp tiếng Việt có cả bản ASCII dự phòng lẫn bản RFC 5987', function () {
    $document = downloadableDocument($this->matter, displayName: 'Giấy chứng nhận 50% quyền sử dụng đất.pdf');

    $disposition = $this->actingAs($this->lawyer, 'web')
        ->get($document->downloadUrlFor($this->lawyer))
        ->headers->get('Content-Disposition');

    // Khẳng định CHÍNH XÁC cả header, không `toContain`: một `toContain('.pdf')` từng sống sót
    // qua một mutation probe ở Task 3 vì nó vẫn đúng khi phần tên đã biến mất.
    expect($disposition)->toBe(
        'attachment; filename="Giay chung nhan 50 quyen su dung dat.pdf"; '
        ."filename*=utf-8''Gi%E1%BA%A5y%20ch%E1%BB%A9ng%20nh%E1%BA%ADn%2050%25%20quy%E1%BB%81n%20s%E1%BB%AD%20d%E1%BB%A5ng%20%C4%91%E1%BA%A5t.pdf"
    );
});

it('tên hiển thị không có đuôi vẫn tải về kèm đuôi lấy từ tên tệp trên đĩa', function () {
    // Đúng thứ `addMedia()` trơn sinh ra: medialibrary mặc định đặt `name` là tên tệp BỎ đuôi.
    $document = downloadableDocument($this->matter, displayName: 'bang-ke-chi-phi', storedName: '01k5g7q8wz0000000000000001.xlsx');

    $disposition = $this->actingAs($this->lawyer, 'web')
        ->get($document->downloadUrlFor($this->lawyer))
        ->headers->get('Content-Disposition');

    expect($disposition)->toBe('attachment; filename=bang-ke-chi-phi.xlsx');
});

it('tên hiển thị mang mưu đồ tách header bị gỡ trước khi vào Content-Disposition', function () {
    $document = downloadableDocument($this->matter, displayName: 'a";X-Injected: 1.pdf');

    $disposition = $this->actingAs($this->lawyer, 'web')
        ->get($document->downloadUrlFor($this->lawyer))
        ->headers->get('Content-Disposition');

    // Thứ nguy hiểm không phải chuỗi `X-Injected: 1` — nằm gọn trong một giá trị có dấu nháy thì
    // nó không tách được gì. Thứ nguy hiểm là `"` và `;`, hai ký tự KẾT THÚC được giá trị đó và
    // mở ra một tham số mới; `safeName()` gỡ đúng hai ký tự ấy. Khẳng định trọn header để thấy rõ:
    // đúng một dấu `;` (dấu ngăn sau `attachment`), và không byte xuống dòng nào.
    expect($disposition)->toBe('attachment; filename="aX-Injected: 1.pdf"')
        ->and(substr_count((string) $disposition, ';'))->toBe(1)
        ->and(preg_match('/[\r\n]/', (string) $disposition))->toBe(0);
});

it('phản hồi không được lưu lại và không được đoán kiểu nội dung', function () {
    $document = downloadableDocument($this->matter);

    $response = $this->actingAs($this->lawyer, 'web')->get($document->downloadUrlFor($this->lawyer));

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

// ---------------------------------------------------------------------------------------------
// `document_downloads` là chứng cứ (SPEC §4.12, §10.6).
// ---------------------------------------------------------------------------------------------

it('mỗi lượt tải thành công sinh đúng một dòng document_downloads kèm IP và user agent', function () {
    $document = downloadableDocument($this->matter);

    $this->actingAs($this->clientUser, 'client')
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Linux; Android 14)'])
        ->get($document->downloadUrlFor($this->clientUser))
        ->assertOk();

    $rows = DocumentDownload::withoutGlobalScopes()->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->document_id)->toBe($document->id)
        ->and($rows[0]->downloader_type)->toBe('client_user')
        ->and($rows[0]->downloader_id)->toBe($this->clientUser->id)
        ->and($rows[0]->ip)->toBe('203.0.113.7')
        ->and($rows[0]->user_agent)->toBe('Mozilla/5.0 (Linux; Android 14)')
        ->and($rows[0]->downloaded_at)->not->toBeNull();
});

it('lượt tải của nhân sự cũng được ghi — SPEC §4.12 nói mọi lượt tải, cả nội bộ', function () {
    $document = downloadableDocument($this->matter, DocumentGroup::Authority);

    $this->actingAs($this->lawyer, 'web')->get($document->downloadUrlFor($this->lawyer))->assertOk();

    $row = DocumentDownload::withoutGlobalScopes()->sole();

    expect($row->downloader_type)->toBe('user')
        ->and($row->downloader_id)->toBe($this->lawyer->id);
});

it('tải hai lần sinh hai dòng — hai lần mở là hai sự kiện, không khử trùng lặp', function () {
    $document = downloadableDocument($this->matter);

    $this->actingAs($this->clientUser, 'client')->get($document->downloadUrlFor($this->clientUser))->assertOk();
    $this->get($document->downloadUrlFor($this->clientUser))->assertOk();

    expect(DocumentDownload::withoutGlobalScopes()->count())->toBe(2);
});

it('một lần từ chối không ghi dòng nào', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $document = downloadableDocument($this->matter, DocumentGroup::Authority);

    $this->actingAs($outsider, 'web')->get($document->downloadUrlFor($outsider))->assertNotFound();

    expect(DocumentDownload::withoutGlobalScopes()->count())->toBe(0);
});

it('HEAD không trả nội dung nên không ghi một dòng tải nào', function () {
    $document = downloadableDocument($this->matter);

    $response = $this->actingAs($this->clientUser, 'client')
        ->head($document->downloadUrlFor($this->clientUser));

    $response->assertOk();

    expect($response->streamedContent())->toBe('')
        ->and(DocumentDownload::withoutGlobalScopes()->count())->toBe(0);
});

it('header Purpose: prefetch KHÔNG tắt được việc ghi chứng cứ', function () {
    $document = downloadableDocument($this->matter);

    $this->actingAs($this->clientUser, 'client')
        ->withHeaders(['Purpose' => 'prefetch', 'Sec-Purpose' => 'prefetch'])
        ->get($document->downloadUrlFor($this->clientUser))
        ->assertOk();

    expect(DocumentDownload::withoutGlobalScopes()->count())->toBe(1);
});

it('Range bị bỏ qua: phản hồi luôn là trọn tệp, không có 206 để phải đếm hai lần', function () {
    $document = downloadableDocument($this->matter);

    $response = $this->actingAs($this->lawyer, 'web')
        ->withHeaders(['Range' => 'bytes=0-4'])
        ->get($document->downloadUrlFor($this->lawyer));

    $response->assertOk();

    expect($response->headers->get('Accept-Ranges'))->toBeNull()
        ->and($response->streamedContent())->toBe('%PDF-1.4 noi dung that')
        ->and(DocumentDownload::withoutGlobalScopes()->count())->toBe(1);
});

it('User-Agent dài hơn cột được cắt còn đúng 500 KÝ TỰ, không phải 500 byte', function () {
    $document = downloadableDocument($this->matter);

    // Tiếng Việt có dấu là 2 byte mỗi ký tự: 600 ký tự = 1200 byte. Cắt theo byte sẽ ra 500 byte
    // = 250 ký tự, và cột `varchar(500)` của MariaDB đếm KÝ TỰ chứ không đếm byte.
    $this->actingAs($this->lawyer, 'web')
        ->withHeaders(['User-Agent' => str_repeat('đ', 600)])
        ->get($document->downloadUrlFor($this->lawyer))
        ->assertOk();

    expect(mb_strlen((string) DocumentDownload::withoutGlobalScopes()->sole()->user_agent))->toBe(500);
});

/*
 * Test này sinh ra từ một MUTATION PROBE SỐNG SÓT: bản đầu gộp "quá dài" và "không phải UTF-8"
 * vào một chuỗi `str_repeat('đ', 600)."\x80"`, và vì byte hỏng nằm ở vị trí thứ 601 nên
 * `mb_substr(..., 0, 500)` đã cắt nó đi TRƯỚC khi nó tới được cơ sở dữ liệu. Xoá
 * `mb_convert_encoding()` khỏi `fitColumn()` mà bộ test vẫn xanh — tức nhánh UTF-8 chưa bao giờ
 * được chạy. Chuỗi ở đây NGẮN, nên byte hỏng không có chỗ nào để trốn.
 */
it('User-Agent mang byte không hợp lệ UTF-8 vẫn lưu được, không làm mất dòng chứng cứ', function () {
    $document = downloadableDocument($this->matter);

    $this->actingAs($this->lawyer, 'web')
        ->withHeaders(['User-Agent' => "Mozilla/5.0 \x80\xFE (dt)"])
        ->get($document->downloadUrlFor($this->lawyer))
        ->assertOk();

    $userAgent = (string) DocumentDownload::withoutGlobalScopes()->sole()->user_agent;

    expect(mb_check_encoding($userAgent, 'UTF-8'))->toBeTrue()
        ->and($userAgent)->toStartWith('Mozilla/5.0 ')
        ->and($userAgent)->toEndWith('(dt)');
});

it('User-Agent mang ký tự điều khiển được gỡ sạch trước khi vào cuốn sổ chứng cứ', function () {
    $document = downloadableDocument($this->matter);

    $this->actingAs($this->lawyer, 'web')
        ->withHeaders(['User-Agent' => "Mozilla/5.0\r\nX-Injected: 1\x00"])
        ->get($document->downloadUrlFor($this->lawyer))
        ->assertOk();

    expect(DocumentDownload::withoutGlobalScopes()->sole()->user_agent)->toBe('Mozilla/5.0X-Injected: 1');
});

it('không có User-Agent hay IP thì cột để trống chứ không phải chuỗi rỗng', function () {
    $document = downloadableDocument($this->matter);

    $this->actingAs($this->lawyer, 'web')
        ->withServerVariables(['REMOTE_ADDR' => ''])
        ->withHeaders(['User-Agent' => ''])
        ->get($document->downloadUrlFor($this->lawyer))
        ->assertOk();

    $row = DocumentDownload::withoutGlobalScopes()->sole();

    expect($row->ip)->toBeNull()->and($row->user_agent)->toBeNull();
});

it('ghi một dòng activity log document_downloaded với người tải tường minh (SPEC §10.6)', function () {
    $document = downloadableDocument($this->matter);

    $this->actingAs($this->clientUser, 'client')
        ->get($document->downloadUrlFor($this->clientUser))
        ->assertOk();

    $activity = Activity::query()->where('event', 'document_downloaded')->sole();

    expect($activity->subject_type)->toBe('document')
        ->and($activity->subject_id)->toBe($document->id)
        ->and($activity->causer_type)->toBe('client_user')
        ->and($activity->causer_id)->toBe($this->clientUser->id)
        ->and($activity->properties['matter_id'])->toBe($this->matter->id)
        ->and($activity->properties['client_id'])->toBe($this->client->id)
        ->and($activity->properties['group'])->toBe(DocumentGroup::ClientProvided->value)
        ->and($activity->properties['version'])->toBe($document->version);
});

it('nhật ký tải về không bao giờ hiện ra dưới guard khách', function () {
    $document = downloadableDocument($this->matter);

    $this->actingAs($this->clientUser, 'client')
        ->get($document->downloadUrlFor($this->clientUser))
        ->assertOk();

    expect(DocumentDownload::withoutGlobalScopes()->count())->toBe(1)
        ->and(DocumentDownload::count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------
// Tên tệp không còn một ký tự ASCII nào. `FilesystemAdapter::download()` tự dựng bản dự phòng
// bằng `Str::ascii()` rồi bỏ dấu `%`, và `HeaderUtils::makeDisposition()` NÉM
// `InvalidArgumentException` khi bản dự phòng đó rỗng — tức một cái tên toàn chữ Hán hay emoji,
// không đuôi, là một lỗi 500 cho một lượt tải hoàn toàn hợp lệ.
// ---------------------------------------------------------------------------------------------

it('tên hiển thị không còn ký tự ASCII nào và không có đuôi thì tải về bằng tên dự phòng', function (string $displayName) {
    // `media.name` đọc ra từ cơ sở dữ liệu nên nó có thể do một bản mã cũ, một lần nhập dữ liệu
    // hay một lần sửa tay ghi vào — `downloadName()` phải tự đứng vững trước mọi giá trị.
    $document = downloadableDocument($this->matter, displayName: $displayName, storedName: '01k5g7q8wz0000000000000002');

    $disposition = $this->actingAs($this->lawyer, 'web')
        ->get($document->downloadUrlFor($this->lawyer))
        ->assertOk()
        ->headers->get('Content-Disposition');

    expect($disposition)->toBe('attachment; filename='.__('documents.fallback_file_name'));
})->with([
    'chữ Hán' => ['日本語'],
    'emoji' => ['🙂🙂'],
]);

it('đuôi mượn từ tên trên đĩa cũng đi qua safeName một lần nữa', function () {
    // Đuôi được nối vào SAU `safeName()`, nên nếu nó không đi lại qua đó thì một `media.file_name`
    // do một bản mã cũ ghi vào đưa được dấu `"` và `;` thẳng vào `Content-Disposition` — đúng hai
    // ký tự tách được header mà `safeName()` sinh ra để gỡ.
    $document = downloadableDocument($this->matter, displayName: 'bang-ke', storedName: 'x.pd"f');

    $disposition = $this->actingAs($this->lawyer, 'web')
        ->get($document->downloadUrlFor($this->lawyer))
        ->assertOk()
        ->headers->get('Content-Disposition');

    expect($disposition)->toBe('attachment; filename=bang-ke.pdf')
        ->and(substr_count((string) $disposition, ';'))->toBe(1);
});

// ---------------------------------------------------------------------------------------------
// Đường dẫn ký THIẾU mã người nhận.
// ---------------------------------------------------------------------------------------------

it('đường dẫn ký mà thiếu mã người nhận thì không ai dùng được, kể cả chủ nhân tài liệu', function () {
    // `Document::downloadUrlFor()` luôn ký kèm mã người nhận, nhưng `URL::temporarySignedRoute()`
    // là một hàm công khai của framework: một chỗ gọi nào đó quên tham số sẽ phát ra một đường
    // dẫn có chữ ký HỢP LỆ mà không ai mở được, mãi mãi, không một dòng lỗi nào. Test này ghim
    // hành vi đó để nó là một 404 có chủ đích chứ không phải một điều ngẫu nhiên — và để bất kỳ
    // ai dựng một chỗ mint thứ hai cũng thấy ngay vì sao `downloadUrlFor()` nên là đường duy nhất.
    $document = downloadableDocument($this->matter);

    $url = URL::temporarySignedRoute('documents.download', now()->addMinutes(5), [
        'document' => $document->id,
    ]);

    $this->actingAs($this->lawyer, 'web')->get($url)->assertNotFound();

    expect(DocumentDownload::withoutGlobalScopes()->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------
// Giới hạn số lượt tải. Xem docblock `DocumentDownloadController` cho con số và lý do.
// ---------------------------------------------------------------------------------------------

it('vượt quá giới hạn lượt tải mỗi phút thì bị chặn', function () {
    $document = downloadableDocument($this->matter);
    $limit = DocumentDownloadController::DOWNLOADS_PER_MINUTE;

    for ($i = 0; $i < $limit; $i++) {
        $this->actingAs($this->lawyer, 'web')
            ->get($document->downloadUrlFor($this->lawyer))
            ->assertOk();
    }

    $this->actingAs($this->lawyer, 'web')
        ->get($document->downloadUrlFor($this->lawyer))
        ->assertStatus(429);

    // Cuốn sổ chứng cứ chỉ ghi những lượt thật sự được trao tệp.
    expect(DocumentDownload::withoutGlobalScopes()->count())->toBe($limit);
});

it('giới hạn đếm theo TÀI KHOẢN, nên một người đụng trần không khoá người khác', function () {
    // Đếm theo IP sẽ khoá cả văn phòng vì một người: mọi nhân sự ngồi sau cùng một đường truyền.
    $colleague = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->addTeamMember($colleague, MatterRole::Associate);
    $document = downloadableDocument($this->matter);

    for ($i = 0; $i < DocumentDownloadController::DOWNLOADS_PER_MINUTE; $i++) {
        $this->actingAs($this->lawyer, 'web')->get($document->downloadUrlFor($this->lawyer))->assertOk();
    }

    $this->actingAs($this->lawyer, 'web')->get($document->downloadUrlFor($this->lawyer))->assertStatus(429);
    $this->actingAs($colleague, 'web')->get($document->downloadUrlFor($colleague))->assertOk();
});
