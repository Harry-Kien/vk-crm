<?php

use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Filament\Portal\Pages\MatterProgress;
use App\Filament\Portal\Pages\SubmitDocument;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\StageLog;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use App\Support\UploadThrottle;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Màn hình nộp giấy tờ của cổng khách hàng — SPEC §8.4.
 *
 * Đây là nửa "nộp được ảnh chụp" của tiêu chí nghiệm thu SPEC §14 mục 4, và là màn hình đầu tiên
 * trong hệ thống mà một người NGOÀI văn phòng ghi dữ liệu vào. Chín bước nghiệp vụ là của M4
 * (`SubmitClientDocument`); tệp này đo đúng ba thứ mà một màn hình phải tự trả lời:
 *
 *  1. **Không có đường nào từ tham số tới dữ liệu của khách khác** — SPEC §14 mục 5. Mọi lần
 *     từ chối là **404**, cùng một câu trả lời cho "không tồn tại" và "không phải của anh/chị"
 *     (SPEC §10.10). Màn hình không được thêm một câu trả lời thứ tư vào ba câu mà Action đã
 *     thống nhất.
 *  2. **Mọi lời từ chối đến được mắt khách bằng tiếng Việt nói rõ việc cần làm** — SPEC §8.4 cấm
 *     kiểu "Upload failed". Hai đích đến: ô chọn tệp (`DomainException` gồm `FileRejected`, và
 *     `ValidationException`) hoặc 404 (`AuthorizationException`), mỗi đích một test.
 *  3. **Giới hạn 20 tệp/giờ/tài khoản** — SPEC §10.3, đo ở CẢ hai cửa: lúc tệp được chọn (nơi
 *     byte thật sự rơi xuống đĩa) và lúc bấm gửi.
 *
 * Mỗi khẳng định âm đi kèm vế dương của nó, thường trong cùng một test: một test nói "thứ X bị
 * chặn" xanh y hệt khi MỌI thứ đều bị chặn, và khi đó nó không còn đo gì nữa.
 *
 * **Không lái được bằng trình duyệt thật.** Ô chọn tệp là FilePond; M4 đã đo rằng công cụ trình
 * duyệt không điều khiển nổi nó. Nên toàn bộ luồng form ở đây đi qua `livewire()` — thứ chạy
 * đúng `mount()`, `_startUpload()`, validation của schema và `submit()` — và một lần đi bộ tay
 * trên điện thoại thật là việc của Task 7.
 */
const SUBMIT_MARKER = 'GHI-CHU-NOI-BO-7P9Q';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    Storage::fake('private');

    // Mỗi test một tiền tố medialibrary riêng — `Storage::fake()` dọn MỘT gốc dùng chung trong
    // khi đường dẫn của medialibrary là `{media.id}/{file_name}` và `RefreshDatabase` đặt id về
    // 1, nên hai tệp test cùng chạy sẽ xoá thư mục của nhau (đo được ở M4: 2 lần hỏng trên 11
    // lần chạy).
    config(['media-library.prefix' => 'test-'.Str::random(16)]);

    Filament::setCurrentPanel('portal');

    $this->client = Client::factory()->create(['name' => 'Khách hàng A']);
    $this->clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $this->matter = Matter::factory()->for($this->client)->create([
        'is_published_to_portal' => true,
        'title' => 'Tranh chấp quyền sử dụng đất',
        'lead_lawyer_id' => $this->lawyer->id,
    ]);

    $this->item = MatterChecklistItem::factory()->for($this->matter)->create([
        'name' => 'Giấy chứng nhận quyền sử dụng đất',
        'status' => ChecklistItemStatus::Missing,
    ]);
});

/** Byte thật của một PDF tối thiểu — `FileGuard` đọc MIME bằng `finfo` trên NỘI DUNG tệp. */
function submitPagePdf(string $name = 'so-do.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%âãÏÓ\n1 0 obj\n<< /Type /Catalog >>\nendobj\n");
}

/** Component đã đăng nhập, đã mount vào đúng hồ sơ của khách. */
function submitPage(array $parameters = []): Testable
{
    return test()->actingAs(test()->clientUser, 'client')
        ->livewire(SubmitDocument::class, array_merge(['record' => test()->matter->getKey()], $parameters));
}

/**
 * Một URL tải lên ĐÃ KÝ THẬT — đúng thứ `_startUpload` cấp cho trình duyệt.
 *
 * `FileUploadConfiguration::storage()` gọi trước để đăng ký đĩa tạm của Livewire (`tmp-for-tests`,
 * chỉ tồn tại khi đang chạy test và chỉ được `Storage::fake()` bởi chính lời gọi này). Không có
 * nó, controller chết ở `Storage::disk()` chứ không trả lời về luật nào cả. Đây là đúng hai dòng
 * mà `Testable::upload()` của Livewire tự làm trước khi gọi `validateAndStore()`.
 */
function submitUploadUrl(): string
{
    FileUploadConfiguration::storage();

    return app(GenerateSignedUploadUrl::class)->forLocal();
}

/**
 * POST một tệp đúng kích thước lên **chính endpoint của Livewire** — không phải `Testable::upload()`,
 * thứ gọi thẳng `validateAndStore()` và vì vậy đi vòng qua cả route lẫn middleware của nó. Trần
 * dung lượng và bộ đếm request nằm ở endpoint, nên chúng chỉ đo được từ đây.
 */
function submitPostBytes(int $megabytes, ?string $url = null): TestResponse
{
    return submitPostBytesAs(test()->clientUser, $megabytes, $url);
}

/**
 * Cùng lần POST ấy nhưng nói rõ AI đang gửi, và **giữ guard mặc định đúng như production**.
 *
 * `actingAs($user, 'client')` gọi `Auth::shouldUse('client')`, tức nó đổi luôn guard MẶC ĐỊNH
 * của cả ứng dụng. Trên máy chủ thật thì `config('auth.defaults.guard')` là `web` và không có gì
 * đổi nó. Khác biệt đó không vô hại: `ThrottleRequests::resolveRequestSignature()` hỏi
 * `$request->user()`, tức guard mặc định — nên dưới `actingAs` nó nhìn thấy khách hàng, còn trên
 * máy chủ thật nó nhìn thấy `null` và rơi về ĐỊA CHỈ. Chính chỗ đó là lỗ hổng mà vòng rà soát
 * phải đo qua HTTP thật mới thấy, vì mọi test trong tệp này che nó đi.
 *
 * Nên helper này trả guard mặc định về `web` sau khi đăng nhập trên guard `client` — phiên của
 * khách vẫn còn nguyên, chỉ có "guard mặc định" trở lại đúng giá trị của production.
 */
function submitPostBytesAs(ClientUser $actor, int $megabytes, ?string $url = null): TestResponse
{
    test()->actingAs($actor, 'client');
    app('auth')->shouldUse('web');

    return test()->post(
        $url ?? submitUploadUrl(),
        ['files' => [UploadedFile::fake()->create('anh-chup.jpg', $megabytes * 1024, 'image/jpeg')]],
        // Thân multipart KÈM `Accept: application/json` — đúng hình dạng XHR mà FilePond gửi.
        // Không có header này, một lời từ chối của validator đi ra bằng 302 "quay lại trang
        // trước" thay vì 422 kèm thân JSON, tức đo nhầm cả mã lẫn nội dung câu trả lời.
        ['Accept' => 'application/json'],
    );
}

/** Chỉ phần trang do task này vẽ ra — phần còn lại là khung của Filament. */
function submitRegion(string $html): string
{
    $start = strpos($html, 'data-portal-page="submit-document"');
    $end = strpos($html, 'data-portal-end="submit-document"');

    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();

    return substr($html, (int) $start, (int) $end - (int) $start);
}

/** @return list<string> giá trị `data-portal-block` theo đúng thứ tự xuất hiện trong HTML. */
function submitBlocksInOrder(string $html): array
{
    preg_match_all('/data-portal-block="([^"]+)"/', $html, $matches);

    return $matches[1];
}

/**
 * Chạy `$callback` trong lúc global scope của `$models` bị thay bằng một scope RỖNG — đúng hình
 * dạng "ai đó quên một câu `where`". Cùng thiết bị mà Task 3 và Task 4 dùng; nó ở đây một bản
 * riêng vì hàm khai báo trong một tệp Pest là hàm toàn cục và hai tệp không dùng chung được.
 *
 * @param  list<class-string<Model>>  $models
 */
function withEmptyPortalScopeForSubmit(array $models, Closure $callback): mixed
{
    foreach ($models as $model) {
        $model::addGlobalScope(ClientPortalScope::class, function (): void {});
    }

    try {
        return $callback();
    } finally {
        foreach ($models as $model) {
            $model::addGlobalScope(new ClientPortalScope);
        }
    }
}

// =========================================================================================
// LUỒNG SPEC §8.4: CHỌN ĐẦU MỤC → TẢI TỆP → XEM TRƯỚC → GỬI
// =========================================================================================

/**
 * Bốn bước của SPEC §8.4 có mặt và **đúng thứ tự**, đo bằng CẤU TRÚC chứ không bằng câu chữ:
 * một bản dịch đổi lời không được làm test này đỏ, còn một lần đổi chỗ hai bước thì phải.
 */
it('renders the four steps of SPEC 8.4 in that exact order', function () {
    $html = submitRegion(submitPage()->html());

    expect(submitBlocksInOrder($html))->toBe(['1', '2', '3', '4']);
});

it('offers the checklist items of this matter as tap targets, not a dropdown', function () {
    MatterChecklistItem::factory()->for($this->matter)->create(['name' => 'Sổ hộ khẩu']);

    $html = submitRegion(submitPage()->html());

    expect($html)->toContain('Giấy chứng nhận quyền sử dụng đất')
        ->and($html)->toContain('Sổ hộ khẩu')
        // Một `<select>` mang theo luật `in:` ngầm của Filament, thứ trả lời một id bịa bằng
        // một lỗi xác thực thay vì bằng 404 — đúng câu trả lời thứ tư mà SPEC §10.10 cấm.
        ->and($html)->not->toContain('<select');
});

it('shows the file field only after an item has been chosen', function () {
    $component = submitPage();

    expect($component->html())->not->toContain('data-portal-field="file"');

    $component->call('chooseItem', $this->item->getKey());

    expect($component->html())->toContain('data-portal-field="file"');
});

/**
 * "Xem trước" của SPEC §8.4 được vẽ **trên máy chủ**, không chỉ bằng ảnh thu nhỏ của FilePond:
 * một khách chụp nhầm trang phải thấy mình sắp gửi cái gì kể cả khi JavaScript xem trước không
 * chạy. Và nó là thứ test đo được — ảnh thu nhỏ của FilePond thì không.
 */
it('shows what is about to be sent before anything is sent', function () {
    $component = submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', submitPagePdf('cccd-mat-truoc.pdf'));

    expect($component->html())->toContain('cccd-mat-truoc.pdf');

    // Vế dương của "trước khi gửi": chưa có tài liệu nào được tạo, và đĩa hồ sơ chưa bị đụng.
    expect(Document::query()->count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([]);
});

it('creates a group A document and leaves the item waiting for the office', function () {
    submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', submitPagePdf())
        ->call('submit')
        ->assertHasNoErrors();

    $document = Document::query()->sole();

    expect($document->matter_id)->toBe($this->matter->getKey())
        ->and($document->matter_checklist_item_id)->toBe($this->item->getKey())
        ->and($document->group)->toBe(DocumentGroup::ClientProvided)
        ->and($document->status)->toBe(DocumentStatus::Published)
        ->and($document->version)->toBe(1)
        ->and($document->parent_document_id)->toBeNull()
        ->and($document->uploader_id)->toBe($this->clientUser->getKey())
        ->and($document->getMedia('file'))->toHaveCount(1);

    expect($this->item->refresh()->status)->toBe(ChecklistItemStatus::PendingReview);

    Storage::disk('private')->assertExists($document->getFirstMedia('file')->getPathRelativeToRoot());
});

/**
 * R10 (M6.5 Task 17, checklist-03): CCCD hai mặt nộp trong MỘT lần qua đúng màn hình thật —
 * `->set('data.file', [tệp1, tệp2])` mô phỏng ô `multiple()` nhận hai tệp cùng lúc rồi bấm Gửi
 * MỘT lần. Cặp âm của bug gốc: hai tài liệu CÙNG version, không tài liệu nào che tài liệu kia.
 */
it('accepts two files in one submission as the same version, and the preview shows both', function () {
    $component = submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', [
            submitPagePdf('cccd-mat-truoc.pdf'),
            submitPagePdf('cccd-mat-sau.pdf'),
        ]);

    // Bước 3 (xem trước) hiện CẢ HAI tên tệp trước khi khách bấm Gửi.
    expect(submitRegion($component->html()))
        ->toContain('cccd-mat-truoc.pdf')
        ->toContain('cccd-mat-sau.pdf');

    $component->call('submit')->assertHasNoErrors();

    expect(Document::query()->count())->toBe(2);

    $documents = Document::query()->orderBy('id')->get();

    expect($documents->pluck('version')->unique()->all())->toBe([1])
        ->and($documents->pluck('parent_document_id')->filter()->all())->toBe([])
        ->and($this->item->refresh()->status)->toBe(ChecklistItemStatus::PendingReview);
});

/**
 * Vòng sửa 1, S1: hai kịch bản R10 ở `SubmitClientDocumentTest` ("Nộp thêm trang 3 khi đang chờ
 * duyệt" và "Bị từ chối rồi nộp lại: version mới") trước đó chỉ được đo ở tầng Action, gọi thẳng
 * `SubmitClientDocument::handle()` — không đi qua `chooseItem()`/`_startUpload()`/`submit()` của
 * chính trang. Hai test dưới đây lặp lại đúng hai kịch bản đó nhưng qua `submitPage()` thật, mỗi
 * lần nộp là MỘT lần mount lại component (đúng hình dạng khách đóng rồi mở lại màn hình, hoặc
 * quay lại từ SPEC §8.3 sau khi văn phòng cập nhật trạng thái) — không tái dùng state Livewire
 * giữa hai lần gửi, vì trang thật cũng không giữ nó qua một lượt tải trang mới.
 */
it('bổ sung trang 3 khi đầu mục đang chờ duyệt qua trang nộp thật, không tạo version mới', function () {
    submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', [submitPagePdf('trang-1.pdf'), submitPagePdf('trang-2.pdf')])
        ->call('submit')
        ->assertHasNoErrors();

    expect($this->item->refresh()->status)->toBe(ChecklistItemStatus::PendingReview);

    submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', submitPagePdf('trang-3.pdf'))
        ->call('submit')
        ->assertHasNoErrors();

    expect(Document::query()->count())->toBe(3)
        ->and(Document::query()->pluck('version')->unique()->all())->toBe([1])
        ->and(Document::query()->pluck('parent_document_id')->filter()->all())->toBe([])
        ->and($this->item->refresh()->status)->toBe(ChecklistItemStatus::PendingReview);
});

it('nộp lại sau khi bị từ chối qua trang nộp thật tạo version mới, không bổ sung vào bản đã bị từ chối', function () {
    submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', submitPagePdf('lan-1.pdf'))
        ->call('submit')
        ->assertHasNoErrors();

    $first = Document::query()->sole();

    $this->item->refresh()->update([
        'status' => ChecklistItemStatus::Rejected,
        'rejection_reason' => 'Ảnh bị mờ ở góc trên nên không đọc được số thửa.',
    ]);

    submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', submitPagePdf('lan-2.pdf'))
        ->call('submit')
        ->assertHasNoErrors();

    expect(Document::query()->count())->toBe(2);

    $second = Document::query()->where('version', 2)->sole();

    expect($second->parent_document_id)->toBe($first->getKey())
        ->and($first->refresh()->version)->toBe(1)
        ->and($first->getMedia('file'))->toHaveCount(1)
        ->and($this->item->refresh()->status)->toBe(ChecklistItemStatus::PendingReview);
});

/** SPEC §8.4 nguyên văn: "Sau khi gửi hiện trạng thái 'Đang chờ văn phòng kiểm tra'". */
it('says the office is now checking the file, in those words', function () {
    // Vế âm đo TRƯỚC, khi đầu mục còn `missing`: câu đó không được đứng sẵn trên trang, nếu
    // không thì khẳng định dương bên dưới xanh mà không đo gì. (Sau khi gửi, chính nhãn trạng
    // thái của đầu mục ở bước 1 cũng là câu này — đúng như nó phải thế — nên phép đo âm chỉ có
    // nghĩa ở thời điểm này.)
    expect(submitRegion(submitPage()->html()))->not->toContain('Đang chờ văn phòng kiểm tra');

    $component = submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', submitPagePdf())
        ->call('submit');

    expect(submitRegion($component->html()))->toContain('Đang chờ văn phòng kiểm tra');
});

/**
 * `capture` trên ô chọn tệp — SPEC §8.4 "hỗ trợ chụp ảnh trực tiếp trên điện thoại".
 *
 * FilePond đọc thuộc tính `capture` của chính thẻ `<input>` nguồn và ánh xạ nó thành
 * `captureMethod` (đo trong `vendor/filament/forms/dist/components/file-upload.js`), nên thuộc
 * tính phải nằm đúng trên thẻ đó — một tuỳ chọn JavaScript viết ở chỗ khác sẽ không tới được nó.
 */
it('puts capture on the file input so a phone can photograph the paper', function () {
    $html = submitPage()->call('chooseItem', $this->item->getKey())->html();

    expect($html)->toMatch('/<input[^>]*capture="environment"[^>]*>/');
});

// =========================================================================================
// CÁCH LY — SPEC §14 mục 5, §10.10: MỌI LỜI TỪ CHỐI LÀ 404
// =========================================================================================

/**
 * **Trang tự hỏi `Gate`, và đây là phép đo của câu đó.**
 * `Filament\Pages\Concerns\CanAuthorizeAccess::canAccess()` mặc định trả `true` cho trang tuỳ
 * chỉnh, nên `AnswerDeniedPanelRequestsWithNotFound` chỉ đổi HÌNH DẠNG của một lời từ chối đã
 * có — nó không bao giờ tự sinh ra một lời từ chối. Cổng tĩnh MỞ, mà request vẫn 404.
 */
it('answers the matter of another client with 404, although the static page gate is open', function () {
    $otherMatter = Matter::factory()->create(['is_published_to_portal' => true]);

    $this->actingAs($this->clientUser, 'client');

    // Cổng tĩnh MỞ cho chính tài khoản này — nó không nhìn thấy `{record}` nên nó không bao giờ
    // là câu trả lời cho "hồ sơ này có phải của anh/chị không".
    expect(SubmitDocument::canAccess())->toBeTrue();

    $this->actingAs($this->clientUser, 'client')
        ->get(SubmitDocument::getUrl(['record' => $otherMatter->getKey()], panel: 'portal'))
        ->assertNotFound();

    // Vế dương: cùng đường, cùng tài khoản, hồ sơ của chính mình thì mở.
    $this->actingAs($this->clientUser, 'client')
        ->get(SubmitDocument::getUrl(['record' => $this->matter->getKey()], panel: 'portal'))
        ->assertOk();
});

it('answers an unpublished or deleted matter and a made up id with 404', function () {
    $hidden = Matter::factory()->for($this->client)->unpublished()->create();
    $deleted = Matter::factory()->for($this->client)->create(['is_published_to_portal' => true]);
    $deleted->delete();

    foreach ([$hidden->getKey(), $deleted->getKey(), 999999] as $key) {
        submitPage(['record' => $key])->assertNotFound();
    }

    // Vế dương trong cùng một test: hồ sơ thật thì mount được.
    submitPage()->assertOk();
});

/**
 * **ĐÂY LÀ TEST QUAN TRỌNG NHẤT CỦA TASK NÀY** — SPEC §14 mục 5, "kể cả sửa tham số URL".
 *
 * `$item` là một thuộc tính CÔNG KHAI của component, nên một client tự chế sửa thẳng được nó
 * giữa hai request mà không đi qua `chooseItem()`. Vì vậy mọi lần dùng phải giải lại bản ghi và
 * hỏi lại `Gate` — và câu trả lời là **404**, không phải 403 và không phải một lỗi xác thực.
 */
it('answers a submission against another clients checklist item with 404, however the id arrives', function () {
    $otherItem = MatterChecklistItem::factory()->create(['name' => 'Giấy tờ của khách B']);

    // (a) qua lời gọi chọn đầu mục
    submitPage()->call('chooseItem', $otherItem->getKey())->assertNotFound();

    // (b) qua chuỗi truy vấn của một lần GET THẬT — đúng hình dạng lối vào từ khối 4, và đúng
    //     chỗ mà bản đầu của test này nhầm: nó truyền `item` làm tham số mount, thứ không tồn
    //     tại trên một request thật. Đường dẫn giữ hồ sơ CỦA CHÍNH KHÁCH, nếu không thì
    //     `resolveMatter()` đã 404 trước và test đo một cổng khác cổng nó nêu tên.
    $this->actingAs($this->clientUser, 'client')
        ->get(SubmitDocument::getUrl([
            'record' => $this->matter->getKey(),
            'item' => $otherItem->getKey(),
        ], panel: 'portal'))
        ->assertNotFound();

    // (c) **Tầng giải lại, đo riêng.** `#[Locked]` chặn lần ghi từ phía trình duyệt (test kế
    //     bên), nhưng nó không nói gì về một giá trị đã nằm sẵn trong component — và đó chính
    //     là hình dạng mà Livewire dựng lại component ở mỗi request: từ cái id, không hơn. Dựng
    //     đúng như vậy bằng tay, và cổng quyền vẫn phải đóng lại.
    $rebuild = function (int|string $itemKey): mixed {
        $page = new SubmitDocument;
        $page->record = $this->matter->getKey();
        $page->item = $itemKey;

        return $page->checklistItem();
    };

    expect(fn () => $rebuild($otherItem->getKey()))->toThrow(NotFoundHttpException::class);

    expect(Document::query()->count())->toBe(0);

    // Vế dương: cùng ba đường, đầu mục của chính mình thì đi được.
    submitPage()->call('chooseItem', $this->item->getKey())->assertSet('item', $this->item->getKey());
    $this->actingAs($this->clientUser, 'client')
        ->get(SubmitDocument::urlForItem($this->item))
        ->assertOk();
    expect($rebuild($this->item->getKey())->is($this->item))->toBeTrue();

    submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', submitPagePdf())
        ->call('submit')
        ->assertHasNoErrors();

    expect(Document::query()->count())->toBe(1);
});

/**
 * **Lớp thứ hai trên cùng thuộc tính: trình duyệt không ghi được nó.**
 *
 * Rà soát Task 4 đã đo trên `MatterProgress::$record` rằng một request cập nhật tự chế mang
 * `updates: {...}` ghi thẳng vào một thuộc tính công khai không khoá. Ở trang này, thuộc tính
 * duy nhất trình duyệt có việc phải ghi là `data` (trạng thái ô chọn tệp); ba cái còn lại là
 * kết quả của những quyết định đã được gác ở phía máy chủ.
 */
it('lets the browser write the file state and nothing else', function () {
    $otherItem = MatterChecklistItem::factory()->create();

    foreach (['item' => $otherItem->getKey(), 'record' => 999999, 'submitted' => true] as $property => $value) {
        expect(fn () => submitPage()->set($property, $value))
            ->toThrow(CannotUpdateLockedPropertyException::class);
    }

    // Vế dương: ô chọn tệp thì ghi được, nếu không test trên chỉ chứng minh mọi thứ đều khoá.
    submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', submitPagePdf())
        ->assertHasNoErrors();
});

/**
 * Một đầu mục của **chính khách này** nhưng thuộc **hồ sơ khác** cũng là 404.
 *
 * `DocumentPolicy::create()` cho qua (đầu mục đó khách được xem, hồ sơ đó khách được xem), nên
 * điều kiện duy nhất chặn được là "đầu mục phải thuộc đúng hồ sơ mà trang này đang mở". Không có
 * nó, một tệp nộp vào hồ sơ A hạ cánh xuống danh mục của hồ sơ B — vẫn của khách, nhưng sai hồ
 * sơ, và số version của chuỗi nộp lại đếm trên một đầu mục người ta không mở.
 */
it('answers an item of another matter of the same client with 404', function () {
    $siblingMatter = Matter::factory()->for($this->client)->create(['is_published_to_portal' => true]);
    $siblingItem = MatterChecklistItem::factory()->for($siblingMatter)->create(['name' => 'Giấy tờ hồ sơ khác']);

    // Tiền đề: chính sách KHÔNG chặn — nếu nó chặn thì test này đo một điều kiện khác.
    expect(Gate::forUser($this->clientUser)
        ->allows('create', [Document::class, $siblingItem]))->toBeTrue();

    submitPage()->call('chooseItem', $siblingItem->getKey())->assertNotFound();

    // Vế dương: mở đúng hồ sơ của nó thì chọn được.
    $this->matter = $siblingMatter;
    submitPage()->call('chooseItem', $siblingItem->getKey())->assertSet('item', $siblingItem->getKey());
});

/**
 * Nghi thức ba tầng, tầng 2: thay global scope bằng một scope RỖNG, khẳng định tầng truy vấn đã
 * thủng, rồi khẳng định trang **vẫn** từ chối.
 *
 * **Nói đúng tầng nào đang giữ nó, vì test này một mình không phân biệt được hai tầng.** Đo
 * bằng đột biến: xoá điều kiện "đầu mục phải thuộc hồ sơ đang mở" ở `SubmitDocument::resolveItem()`
 * thì test này vẫn XANH — `Gate` giữ. Xoá cả nó lẫn lần hỏi `Gate` thì test này ĐỎ, và đầu mục
 * của khách khác đi thẳng vào màn hình. Xoá riêng `Gate` thì cả bộ test xanh, vì điều kiện kia
 * chặn trước. Ba phép đo đó nằm ở docblock của `resolveItem()`.
 */
it('still answers another clients item with 404 when the checklist scope forgets its rule', function () {
    $otherItem = MatterChecklistItem::factory()->create(['name' => 'Của khách B '.SUBMIT_MARKER]);

    withEmptyPortalScopeForSubmit([MatterChecklistItem::class], function () use ($otherItem) {
        // Tầng truy vấn đã thủng — nếu không thì khẳng định dưới không đo tầng nào cả.
        expect(MatterChecklistItem::find($otherItem->getKey()))->not->toBeNull();

        submitPage()->call('chooseItem', $otherItem->getKey())->assertNotFound();

        // Vế dương TRONG CÙNG ngữ cảnh thủng: trang không từ chối tất cả.
        submitPage()->call('chooseItem', $this->item->getKey())->assertSet('item', $this->item->getKey());
    });
});

/**
 * Danh sách đầu mục cũng đi qua `Gate` từng dòng, không chỉ qua quan hệ — cùng lý lẽ đã đo ở
 * `MatterProgress::checklistItems()`.
 */
it('keeps another clients item out of the chooser when the checklist scope forgets its rule', function () {
    $otherItem = MatterChecklistItem::factory()->create(['name' => 'Của khách B '.SUBMIT_MARKER]);

    $html = withEmptyPortalScopeForSubmit([MatterChecklistItem::class], function () use ($otherItem) {
        expect(MatterChecklistItem::find($otherItem->getKey()))->not->toBeNull();

        return submitRegion(submitPage()->html());
    });

    expect($html)->not->toContain(SUBMIT_MARKER)
        // Vế dương: đầu mục của chính khách vẫn được vẽ trong cùng ngữ cảnh thủng.
        ->and($html)->toContain('Giấy chứng nhận quyền sử dụng đất');
});

/**
 * SPEC §11 "Ghi chú nội bộ": chuỗi trong `stage_logs.internal_note` không được xuất hiện ở bất
 * kỳ đâu trong response của cổng. Trang này không vẽ dòng tiến độ nào, và test tồn tại để nó
 * tiếp tục không vẽ — `HidesInternalAttributesFromPortal` chỉ chặn ở tầng serialize, nên một
 * `{{ $log->internal_note }}` thêm vào view ngày mai sẽ in ra chuỗi thật.
 */
it('never prints an internal note', function () {
    StageLog::factory()->for($this->matter)->published()->create([
        'public_content' => 'Toà đã nhận đơn khởi kiện của anh/chị.',
        'internal_note' => 'Ghi chú riêng '.SUBMIT_MARKER,
    ]);

    expect(submitPage()->html())->not->toContain(SUBMIT_MARKER);
});

// =========================================================================================
// THÔNG ĐIỆP LỖI — BA HỌ EXCEPTION, BA ĐƯỜNG, TẤT CẢ BẰNG TIẾNG VIỆT (SPEC §8.4)
// =========================================================================================

/**
 * Một gói ZIP tuỳ ý — dùng để dựng một tệp "đội lốt" `.docx` không phải Office thật. Tiền tố
 * `submitDocx` vì hàm khai báo ở đây là hàm TOÀN CỤC của Pest, và `FileGuardTest.php` đã có
 * `zipBytes()`/`docxPackageBytes()` cùng vai trò dưới tên khác.
 */
function submitDocxZipBytes(array $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);

    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }

    $zip->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

/** Gói Office Open XML thật của Word: mang cả ba mục mà một gói `.docx` thật luôn có. */
function submitDocxPackageBytes(): string
{
    return submitDocxZipBytes([
        '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>',
        '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>',
        'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>',
    ]);
}

/**
 * `application/zip` phải có mặt trong `acceptedFileTypes()` — không chỉ trong `FileGuard::ALLOWED`
 * — vì một hệ điều hành không có sẵn bộ nhận diện OOXML để trình duyệt khai đúng, hoặc một bản
 * `libmagic` cụ thể (xem docblock `FileGuard`), có thể báo `Content-Type: application/zip` cho
 * một tệp `.docx` THẬT. Thiếu nó, luật `mimetypes` của CHÍNH Ô NÀY — chạy TRƯỚC `FileGuard`, xem
 * docblock lớp — chặn một tệp thật trước khi `FileGuard` có cơ hội mở gói ra kiểm tra ruột.
 *
 * `->mimeType('application/zip')` ghi đè Content-Type CLIENT KHAI trên tệp giả của test — đúng
 * điều kiện đang được đo (client khai sai, chứ không phải nội dung sai): nội dung vẫn là một gói
 * Word thật, và `Illuminate\Http\UploadedFile::fake()->createWithContent()` mặc định suy luôn
 * MIME từ ĐUÔI tệp (không đọc nội dung), nên không có ghi đè này thì test sẽ luôn thấy đúng MIME
 * OOXML — không đo được nhánh mà bản sửa này thêm vào.
 */
it('accepts a real .docx package the client declares as application/zip', function () {
    $file = UploadedFile::fake()
        ->createWithContent('don-khoi-kien.docx', submitDocxPackageBytes())
        ->mimeType('application/zip');

    $component = submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', $file)
        ->call('submit');

    $component->assertHasNoErrors();

    expect(Document::query()->count())->toBe(1)
        ->and($this->item->refresh()->status)->toBe(ChecklistItemStatus::PendingReview);
});

/**
 * Cặp sinh đôi âm của test trên: nới rộng `accept` để nhận `application/zip` không mở lỗ cho một
 * ZIP tuỳ ý đội tên `.docx`. `FileGuard::verifyOfficePackage()` (không đụng ở task này) vẫn mở
 * gói ra và đòi đúng mục bắt buộc của OOXML, nên một gói KHÔNG có `word/document.xml` vẫn bị chặn
 * — dù MIME đã qua được luật của ô chọn tệp, đúng như chính client thật khai `application/zip`
 * cho một ZIP thật (không cần ghi đè: một ZIP trần vốn đã mang MIME đó).
 */
it('still refuses a plain zip named .docx once application/zip is accepted', function () {
    $component = submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', UploadedFile::fake()->createWithContent('bang-ke.docx', submitDocxZipBytes([
            'payload.txt' => 'không phải một gói Office',
        ]))->mimeType('application/zip'))
        ->call('submit');

    $component->assertHasErrors('data.file');

    expect(Document::query()->count())->toBe(0)
        ->and($this->item->refresh()->status)->toBe(ChecklistItemStatus::Missing);
});

/**
 * Cặp thứ hai của cùng lỗ hổng, ở phía OLE2 (`.doc`/`.xls` cũ): một hệ điều hành không phân biệt
 * được container OLE2 cụ thể có thể khai bất kỳ MIME nào trong ba MIME mà `FileGuard::ALLOWED`
 * chấp nhận cho `doc` — `application/x-ole-storage`, `application/x-cfb`, `application/CDFV2` —
 * và cả ba phải có mặt ở `acceptedFileTypes()` cho cùng lý do đã nói ở test `.docx` phía trên.
 */
it('accepts a real .doc package the client declares under any OLE2 MIME variant', function (string $declaredMime) {
    $file = UploadedFile::fake()
        ->createWithContent('hop-dong.doc', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 504))
        ->mimeType($declaredMime);

    $component = submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', $file)
        ->call('submit');

    $component->assertHasNoErrors();

    expect(Document::query()->count())->toBe(1);
})->with([
    'application/x-ole-storage',
    'application/x-cfb',
    'application/CDFV2',
]);

/**
 * Vòng sửa 1 (Minor): docblock này bị đặt lạc chỗ ở bản trước — nó đứng trên
 * `submitDocxZipBytes()` (một hàm phụ trợ, không phải test) thay vì đứng trên chính test nó tả.
 * Chuyển lại đây: nội dung tệp giả bên dưới (`"MZ\x90\x00\x03\x00\x00\x00"`, chữ ký DOS/PE) đúng
 * là "đuôi `.pdf` nhưng nội dung thật là một tệp thực thi DOS" mà đoạn văn này mô tả.
 *
 * Họ `FileRejected` (một `DomainException`): đuôi `.pdf` nhưng nội dung thật là một tệp thực thi
 * DOS — trường hợp SPEC §11 "Tải tệp" nêu đích danh.
 *
 * **Tệp phải đi lọt luật của chính schema rồi mới tới `FileGuard`.** Bài học M4: một `.svg` bị
 * `acceptedFileTypes()` loại từ trước, nên lần từ chối là của Filament và nhánh `FileRejected`
 * của màn hình không hề chạy — mutation probe gỡ nhánh đó đi vẫn để test xanh. `Content-Type` do
 * client gửi suy ra từ đuôi nên nó qua được `acceptedFileTypes()`; `finfo` thì không.
 */
it('binds a file guard refusal to the file field and creates nothing', function () {
    $component = submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', UploadedFile::fake()->createWithContent('so-do.pdf', "MZ\x90\x00\x03\x00\x00\x00"))
        ->call('submit');

    $component->assertHasErrors('data.file');

    expect($component->errors()->first('data.file'))
        ->toContain('Nội dung tệp không khớp với đuôi');

    expect(Document::query()->count())->toBe(0)
        ->and($this->item->refresh()->status)->toBe(ChecklistItemStatus::Missing);
});

/**
 * SPEC §11 "Tải tệp": một `.svg` bị từ chối. Ở màn hình này lời từ chối đến từ luật của ô chọn
 * tệp (`acceptedFileTypes`), tức TRƯỚC `FileGuard` — nên câu chữ phải do chúng ta viết, không
 * phải câu mặc định của framework. Đó là lý do ô có `validationMessages()`.
 */
it('refuses an svg with a sentence a client can act on', function () {
    $component = submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', UploadedFile::fake()->createWithContent('chu-ky.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'))
        ->call('submit');

    $component->assertHasErrors('data.file');

    expect($component->errors()->first('data.file'))
        ->toContain('Chỉ nhận')
        ->not->toContain('mimetypes');

    expect(Document::query()->count())->toBe(0);
});

/**
 * SPEC §8.4 cho sẵn câu này, nguyên văn. Nó phải tới được mắt khách, không phải "Upload failed".
 *
 * Đo ở `_startUpload`, tức **trước khi một byte nào rời khỏi điện thoại** — và đó là chỗ duy
 * nhất câu NÀY tới được khách. Trên một tệp quá cỡ đi đúng đường của trình duyệt, cửa này trả
 * lời trước; nếu một client tự chế khai sai kích thước ở `fileInfo` để đi vòng qua nó, endpoint
 * từ chối, và lời từ chối ấy đi ra bằng câu của {@see SubmitDocument::_uploadErrored()} chứ
 * không bằng câu của framework (có test riêng bên dưới).
 */
it('refuses a file over 20 MB with the sentence SPEC 8.4 wrote, before any bytes move', function () {
    $component = submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->call('_startUpload', 'data.file', [[
            'name' => 'anh-hdr.jpg',
            'size' => 21 * 1024 * 1024,
            'type' => 'image/jpeg',
        ]], false);

    $component->assertHasErrors('data.file');

    expect($component->errors()->first('data.file'))
        ->toContain('vượt quá 20 MB')
        ->toContain('chụp lại ở chế độ ảnh thường');

    // Vế dương: một tệp vừa cỡ đi qua cùng lời gọi đó mà không có lỗi nào.
    submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->call('_startUpload', 'data.file', [[
            'name' => 'so-do.jpg',
            'size' => 2 * 1024 * 1024,
            'type' => 'image/jpeg',
        ]], false)
        ->assertHasNoErrors();

    expect(Document::query()->count())->toBe(0);
});

/**
 * Không chọn đầu mục mà bấm gửi: một câu nói rõ phải làm gì, KHÔNG phải 404 (không có gì bị từ
 * chối ở đây — chỉ là chưa đủ thông tin) và KHÔNG phải một lỗi 500.
 */
it('asks for an item instead of failing when nothing has been chosen', function () {
    $component = submitPage()
        ->set('data.file', submitPagePdf())
        ->call('submit');

    $component->assertHasErrors('item');

    expect(Document::query()->count())->toBe(0);
});

/**
 * **Action từ chối GIỮA CHỪNG đi ra bằng đúng 404, không thêm một câu trả lời thứ tư.**
 *
 * `SubmitClientDocument` gộp ba tình huống của SPEC §10.10 vào một `AuthorizationException` duy
 * nhất. Nếu màn hình đổi nó thành một thông báo đỏ trong khi mọi lời từ chối khác của cổng là
 * 404, thì chính màn hình dựng lại cái máy dò mà Action vừa dẹp: gửi id bất kỳ, đọc HÌNH DẠNG
 * câu trả lời, biết bản ghi có thật hay không.
 *
 * Dựng cảnh bằng cách rút hồ sơ khỏi cổng SAU khi trang đã mở — đúng việc một trợ lý làm có chủ
 * đích, và đúng cửa sổ mà lần hỏi quyền thứ hai của Action tồn tại để canh.
 */
it('answers a refusal from inside the action with 404 too', function () {
    $component = submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', submitPagePdf());

    // **Tài khoản bị vô hiệu hoá giữa hai request** — cảnh duy nhất mà mọi cổng CỦA TRANG còn
    // mở mà Action vẫn từ chối, nên nó là cảnh duy nhất đo được nhánh `AuthorizationException`.
    // Bản đầu của test này rút hồ sơ khỏi cổng thay vì vậy, và nó XANH vì một lý do khác:
    // `resolveMatter()` đã 404 trước khi Action kịp chạy — đo được, vì đổi nhánh
    // `AuthorizationException` thành một lỗi trên ô tệp vẫn để test đó xanh. Đúng hình dạng
    // "một test chết ở cổng sớm hơn cái nó nêu tên".
    //
    // `Gate` không hỏi `is_active` (`MatterPolicy::view` và `DocumentPolicy::create` đều không),
    // nên cả `resolveMatter()` lẫn `resolveItem()` vẫn cho qua; `ChecksAccountActive` bên trong
    // Action mới là thứ từ chối. Sửa trên chính đối tượng mà guard đang giữ, vì đó là đối tượng
    // Action nhận làm `$actor`.
    $this->clientUser->is_active = false;

    $component->call('submit')->assertNotFound();

    expect(Document::query()->count())->toBe(0);
});

/**
 * Vế còn lại của cùng câu chuyện, và nó đo một tầng KHÁC: văn phòng rút hồ sơ khỏi cổng giữa hai
 * request thì lời từ chối đến từ chính {@see SubmitDocument::resolveMatter()}, trước khi Action
 * kịp chạy. Hai test này cố ý tách ra — gộp lại thì cái nọ che cái kia.
 */
it('answers with 404 when the office pulls the matter off the portal between two requests', function () {
    $component = submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', submitPagePdf());

    $this->matter->update(['is_published_to_portal' => false]);

    $component->call('submit')->assertNotFound();

    expect(Document::query()->count())->toBe(0);
});

/**
 * M7 Task 3 — vế THỨ BA, cố ý tách khỏi hai test 404 ngay trên (gộp lại thì cái này che cái kia,
 * cùng lý lẽ ngay phía trên). "Vụ đã đóng" đi ra bằng một câu HIỆN TRÊN Ô TỆP, không phải 404:
 * `SubmitClientDocument` ném `MatterClosedForSubmission` — một `DomainException` riêng, KHÔNG
 * `AuthorizationException` — chính xác để trang này KHÔNG đổi nó thành `abort(404)` (xem
 * docblock `SubmitClientDocument`, mục "M7 Task 3", và docblock lớp exception đó). Khách đã có
 * quyền hợp lệ trên đúng đầu mục này; câu cần đọc là lời mời gọi hotline, không phải một trang
 * trống.
 */
it('shows a sentence inviting the client to call the office when the matter has closed, not a 404', function () {
    $component = submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', submitPagePdf());

    $this->matter->update(['closed_at' => now()->subDay()]);

    $component->call('submit');

    $component->assertHasErrors('data.file');

    expect($component->errors()->first('data.file'))
        ->toBe(__('checklist.submit.matter_closed', ['hotline' => config('vkcrm.brand.hotline')]));

    expect(Document::query()->count())->toBe(0)
        ->and($this->item->fresh()->status)->toBe(ChecklistItemStatus::Missing);
});

// =========================================================================================
// NỘP LẠI SAU KHI BỊ TỪ CHỐI — SPEC §6.6 bước 7, §11 "Nghiệp vụ"
// =========================================================================================

it('creates version 2 pointing at version 1, and keeps version 1', function () {
    submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', submitPagePdf('lan-1.pdf'))
        ->call('submit')
        ->assertHasNoErrors();

    $first = Document::query()->sole();

    $this->item->refresh()->update([
        'status' => ChecklistItemStatus::Rejected,
        'rejection_reason' => 'Ảnh bị mờ ở góc trên nên không đọc được số thửa. Nhờ anh/chị chụp lại dưới ánh sáng tự nhiên.',
    ]);

    submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', submitPagePdf('lan-2.pdf'))
        ->call('submit')
        ->assertHasNoErrors();

    $second = Document::query()->where('version', 2)->sole();

    expect($second->parent_document_id)->toBe($first->getKey())
        // "Không ghi đè": bản cũ còn nguyên, còn tệp, còn số version của nó.
        ->and(Document::query()->find($first->getKey()))->not->toBeNull()
        ->and($first->refresh()->version)->toBe(1)
        ->and($first->getMedia('file'))->toHaveCount(1)
        ->and($this->item->refresh()->status)->toBe(ChecklistItemStatus::PendingReview)
        // Lần nộp mới xoá lý do từ chối cũ: nó hiện thẳng cho khách, và để lại là nói với khách
        // rằng bản vừa gửi đã bị từ chối trong khi chưa ai mở nó ra.
        ->and($this->item->rejection_reason)->toBeNull();
});

/**
 * **Bản cũ không hiện cho khách như một tài liệu riêng.** Hai dòng cùng tên đầu mục, cùng ngày,
 * một cái là bản đã bị từ chối — khách không có cách nào biết cái nào là cái đang có hiệu lực.
 * Khối 5 của SPEC §8.3 vì thế chỉ vẽ bản mới nhất của mỗi chuỗi nộp lại.
 */
it('does not show the superseded version to the client as a separate document', function () {
    submitPage()->call('chooseItem', $this->item->getKey())->set('data.file', submitPagePdf('lan-1.pdf'))->call('submit');

    // R10 (M6.5 Task 17): nộp thêm khi đầu mục còn `pending_review` là BỔ SUNG vào version đang
    // chờ, không phải version mới (xem nhóm test R10 của `SubmitClientDocumentTest`). Từ chối
    // trước để lần nộp thứ hai thật sự là version 2, đúng cái test này đang đo.
    $this->item->update([
        'status' => ChecklistItemStatus::Rejected,
        'rejection_reason' => 'Ảnh bị mờ ở góc trên nên không đọc được số thửa.',
    ]);

    submitPage()->call('chooseItem', $this->item->getKey())->set('data.file', submitPagePdf('lan-2.pdf'))->call('submit');

    [$first, $second] = Document::query()->orderBy('version')->get()->all();

    $documents = $this->actingAs($this->clientUser, 'client')
        ->livewire(MatterProgress::class, ['record' => $this->matter->getKey()])
        ->instance()
        ->documents();

    expect($documents->pluck('id')->all())->toBe([$second->getKey()])
        ->and($documents->pluck('id')->all())->not->toContain($first->getKey());
});

// =========================================================================================
// GIỚI HẠN 20 TỆP / GIỜ / TÀI KHOẢN — SPEC §10.3
// =========================================================================================

/**
 * Vòng sửa 1 (Minor), sửa lại ở vòng sửa 2: trần MỘT LÔ (một lần bấm Gửi) — cổng `submit()` vừa
 * thêm.
 *
 * **Bản trước viết sai một câu: "không đi qua `_startUpload()`".** SAI — `->set('data.file',
 * $files)` với một MẢNG `UploadedFile` route thẳng qua
 * `Livewire\Features\SupportTesting\Testable::setProperty()`, và hàm đó gọi
 * `$this->upload($name, $files, isMultiple: true)`, thứ TỰ NÓ gọi `$this->call('_startUpload',
 * ...)` trước khi lưu tệp (đọc mã nguồn `vendor/livewire/livewire/src/Features/SupportTesting
 * /Testable.php`) — đúng cơ chế mọi test khác của tệp này dùng để mô phỏng FilePond, không có
 * đường nào trong Testable đi vòng qua nó.
 *
 * Vì `_startUpload()` CŨNG chạy, `guardRate(fileLimiterKey, ...)` của nó (bộ đếm CHỌN tệp, SPEC
 * §10.3) cũng thấy 21 tệp trong một lượt và cũng từ chối — với câu RIÊNG của nó
 * (`rate_limited_upload`, "Anh/chị đã chọn 20 tệp trong một giờ..."). Đo trực tiếp (dump lỗi ngay
 * sau `->set()`, trước khi gọi `submit()`): đúng câu đó đứng trên `data.file`. Test này không đo
 * nhánh đó — nó đã có test riêng ("refuses the twenty first file at the moment it is chosen…").
 *
 * Thứ test NÀY thật sự đo: `->call('submit')` chạy SAU, và lần gọi thành công đó ghi ĐÈ trạng
 * thái lỗi của component (mỗi `call()`/`set()` là một chu trình cập nhật/validate MỚI, không
 * cộng dồn lỗi từ lượt trước) — nên khẳng định cuối cùng, đọc SAU `submit()`, phản ánh ĐÚNG cổng
 * mới trong `submit()`, không phải cổng chọn tệp. Hai mươi mốt tệp — một hơn mức 20 tệp/giờ mà
 * SPEC §10.3 đặt — không cách nào gửi trót lọt dù chờ bao lâu, nên `submit()` chặn, TRƯỚC khi
 * đọc/ghi bất kỳ tệp nào (`Document::count()` vẫn 0), bằng câu RIÊNG của CHÍNH cổng đó — không
 * phải câu `rate_limited`/`rate_limited_upload` của hai bộ đếm giờ (khách chưa dùng suất nào ở
 * cổng GỬI, họ chỉ chọn quá nhiều tệp trong một lần).
 */
it('refuses a batch of more than twenty files in one submission, before any bytes move', function () {
    $files = array_map(
        fn (int $i): UploadedFile => submitPagePdf("trang-{$i}.pdf"),
        range(1, 21),
    );

    $component = submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', $files)
        ->call('submit');

    $component->assertHasErrors('data.file');

    expect($component->errors()->first('data.file'))
        ->toBe(__('portal_submit.errors.too_many_files_per_submission', ['limit' => 20]))
        // Vế âm: KHÔNG phải câu của luật `max` (kích thước) — xem docblock `form()`, mục
        // `maxFiles()`, cho lý do hai luật `max` không thể chung một câu qua field này.
        ->and($component->errors()->first('data.file'))->not->toContain('MB');

    expect(Document::query()->count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([]);
});

/**
 * Cặp dương: đúng hai mươi tệp — mức tối đa — vẫn gửi trót lọt qua đúng cổng vừa thêm ở trên.
 */
it('accepts exactly twenty files in one submission — the positive edge of the new batch cap', function () {
    $files = array_map(
        fn (int $i): UploadedFile => submitPagePdf("trang-{$i}.pdf"),
        range(1, 20),
    );

    submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', $files)
        ->call('submit')
        ->assertHasNoErrors();

    expect(Document::query()->count())->toBe(20);
});

/**
 * **Khoá theo TÀI KHOẢN, không theo IP và không theo khách hàng.** SPEC §10.3 viết "theo tài
 * khoản". Hai người trong cùng một gia đình dùng chung một hồ sơ (SPEC §4.3) có hai bộ đếm
 * riêng; một người đổi mạng di động sang wifi vẫn mang theo bộ đếm của mình.
 */
it('keys the limit on the account, not on the client and not on the address', function () {
    $sibling = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);

    expect(SubmitDocument::fileLimiterKey($this->clientUser))
        ->not->toBe(SubmitDocument::fileLimiterKey($sibling));

    // Cùng tài khoản, hai địa chỉ khác nhau → cùng một bộ đếm.
    $fromHome = SubmitDocument::fileLimiterKey($this->clientUser);
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
    expect(SubmitDocument::fileLimiterKey($this->clientUser))->toBe($fromHome);
});

/**
 * Cửa thứ nhất: lúc khách CHỌN tệp. Đây là nơi byte thật sự rơi xuống đĩa — `_startUpload` của
 * Livewire cấp một URL đã ký rồi trình duyệt tải tệp lên NGAY, trước khi bất kỳ form action nào
 * chạy (đo ở rà soát M4). Một giới hạn chỉ đứng ở lúc bấm gửi không bảo vệ đĩa và không bảo vệ
 * thời gian của clamd.
 */
it('refuses the twenty first file at the moment it is chosen, before any bytes move', function () {
    $key = SubmitDocument::fileLimiterKey($this->clientUser);

    for ($i = 0; $i < 19; $i++) {
        RateLimiter::hit($key, 3600);
    }

    $fileInfo = [['name' => 'anh.jpg', 'size' => 1024, 'type' => 'image/jpeg']];

    // Vế dương: lần thứ 20 vẫn đi qua.
    submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->call('_startUpload', 'data.file', $fileInfo, false)
        ->assertHasNoErrors();

    // Lần thứ 21 bị chặn, kèm câu nói rõ phải làm gì.
    $component = submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->call('_startUpload', 'data.file', $fileInfo, false);

    $component->assertHasErrors('data.file');

    expect($component->errors()->first('data.file'))->toContain('20 tệp');
});

/** Một lần chọn tệp THẬT tăng bộ đếm đúng một lần — không phải hai, không phải không. */
it('counts a real file choice exactly once', function () {
    $key = SubmitDocument::fileLimiterKey($this->clientUser);

    expect(RateLimiter::attempts($key))->toBe(0);

    // Mảng chứ không một đối tượng trần: `Testable::upload()` gọi `collect($files)`, và
    // `collect()` trên một đối tượng không Traversable ép nó thành mảng THUỘC TÍNH — đo được,
    // vì bản đầu của dòng này chết ở `$file->name` bên trong vendor.
    submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->upload('data.file', [submitPagePdf()]);

    expect(RateLimiter::attempts($key))->toBe(1);
});

/**
 * R10 (M6.5 Task 17): SPEC §10.3 viết "20 TỆP/giờ" — chọn hai tệp trong MỘT lượt (CCCD hai mặt)
 * phải tốn ĐÚNG hai đơn vị, không một. Trước bản sửa này `guardRate()` luôn `hit()` một lần cho
 * mỗi lượt gọi `_startUpload`, bất kể `$fileInfo` mang bao nhiêu tệp — nên một khách chọn 20 lô ×
 * 2 tệp lọt qua đúng 40 tệp, gấp đôi trần.
 */
it('counts two files chosen together as two attempts, not one', function () {
    $key = SubmitDocument::fileLimiterKey($this->clientUser);

    submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->upload('data.file', [submitPagePdf('mat-truoc.pdf'), submitPagePdf('mat-sau.pdf')]);

    expect(RateLimiter::attempts($key))->toBe(2);
});

/**
 * Cửa thứ hai: lúc bấm gửi. Nó tồn tại vì một client tự chế dùng lại được **một** URL đã ký cho
 * nhiều lần gửi — và cái hại của việc nộp lại dồn dập là ở phía `documents` và phía thông báo,
 * không chỉ ở phía đĩa.
 */
it('refuses the twenty first submission, and lets the twentieth through', function () {
    $key = SubmitDocument::submissionLimiterKey($this->clientUser);

    for ($i = 0; $i < 19; $i++) {
        RateLimiter::hit($key, 3600);
    }

    submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', submitPagePdf())
        ->call('submit')
        ->assertHasNoErrors();

    expect(Document::query()->count())->toBe(1);

    $component = submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', submitPagePdf())
        ->call('submit');

    $component->assertHasErrors('data.file');

    expect($component->errors()->first('data.file'))->toContain('20 lần')
        ->and(Document::query()->count())->toBe(1);
});

/**
 * Final review C-M10 — ĐẢO NGƯỢC test cũ cùng vị trí ("counts a refused submission as an
 * attempt"): cửa của BẢN GHI chỉ tính những tệp Action đã nhận. Một tệp bị `FileGuard` chặn
 * (đuôi `.pdf` nhưng ruột là một tệp thực thi) không tạo bản ghi nào và không tốn suất nào — trần
 * trên byte đã nằm ở cửa chọn tệp và ở `UploadThrottle`.
 */
it('does not charge the send gate for a submission FileGuard refuses', function () {
    $key = SubmitDocument::submissionLimiterKey($this->clientUser);

    submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', UploadedFile::fake()->createWithContent('so-do.pdf', "MZ\x90\x00\x03\x00\x00\x00"))
        ->call('submit')
        ->assertHasErrors('data.file');

    expect(Document::query()->count())->toBe(0)
        ->and(RateLimiter::attempts($key))->toBe(0);
});

/** Vế dương: một lần gửi được nhận tốn đúng số tệp đã nhận. */
it('charges the send gate once the submission is accepted', function () {
    $key = SubmitDocument::submissionLimiterKey($this->clientUser);

    submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', submitPagePdf())
        ->call('submit')
        ->assertHasNoErrors();

    expect(Document::query()->count())->toBe(1)
        ->and(RateLimiter::attempts($key))->toBe(1);
});

/**
 * Cặp sinh đôi R10 của test trên, ở cửa THỨ HAI (lúc bấm Gửi): một lô hai tệp bấm Gửi MỘT lần
 * vẫn tốn đúng HAI đơn vị của bộ đếm này, đúng số tệp thật trong lô — không phải một đơn vị cho
 * cả lô. Cùng SPEC §10.3 "20 tệp/giờ" đã áp cho cửa thứ nhất.
 */
it('counts a two-file submission as two attempts at the send gate too', function () {
    $key = SubmitDocument::submissionLimiterKey($this->clientUser);

    submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', [submitPagePdf('mat-truoc.pdf'), submitPagePdf('mat-sau.pdf')])
        ->call('submit')
        ->assertHasNoErrors();

    expect(RateLimiter::attempts($key))->toBe(2);
});

// =========================================================================================
// NHẬT KÝ — SPEC §10.6, và nhánh guard `client` của `Audit::record`
// =========================================================================================

/**
 * **Causer là `ClientUser`, kể cả khi một phiên nhân sự đang mở trong cùng trình duyệt.**
 *
 * `Audit::record()` ưu tiên `auth('web')` khi không được truyền causer tường minh, nên nếu
 * `SubmitClientDocument` bỏ tham số đó thì lượt nộp của khách được ghi tên một nhân viên. Hai
 * panel dùng chung cookie phiên, nên cảnh này có thật: một buổi demo ở văn phòng, hoặc một máy
 * dùng chung. Đây là hình dạng mà Task 1 đã dùng để phân biệt hai cơ chế — không có phiên nhân
 * sự mở cùng lúc thì test xanh vì lý do sai.
 */
it('credits the submission to the client account although a staff session is open', function () {
    $this->actingAs($this->lawyer, 'web');

    submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', submitPagePdf())
        ->call('submit')
        ->assertHasNoErrors();

    $activity = Activity::query()->where('event', 'document_submitted')->sole();

    expect($activity->causer)->toBeInstanceOf(ClientUser::class)
        ->and($activity->causer_id)->toBe($this->clientUser->getKey())
        // Vế dương của "phiên nhân sự đang mở": nếu nó không mở thì test này không phân biệt
        // được hai cơ chế, nên tiền đề được khẳng định chứ không giả định.
        ->and(auth('web')->id())->toBe($this->lawyer->getKey());
});

// =========================================================================================
// TÊN TỆP — `FileGuard::safeName()` đã có nơi gọi thật (mang sang từ M4 Task 1)
// =========================================================================================

/**
 * Tên HIỂN THỊ đi qua `safeName()` (giữ dấu tiếng Việt, bỏ `"` và `;` — những thứ tách được một
 * header `Content-Disposition`), tên TRÊN ĐĨA sinh ngẫu nhiên. Đây là lần khẳng định từ phía
 * màn hình khách rằng lời gọi đó tồn tại thật ở production, không chỉ trong test của M4.
 */
it('keeps the clients own words in the display name and none of them on disk', function () {
    submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', submitPagePdf('CCCD "mặt; trước".pdf'))
        ->call('submit')
        ->assertHasNoErrors();

    $media = Document::query()->sole()->getFirstMedia('file');

    expect($media->name)->toContain('mặt')
        ->not->toContain('"')
        ->not->toContain(';')
        ->and($media->file_name)->toMatch('/^[0-9a-z]{26}\.pdf$/')
        ->and($media->file_name)->not->toContain('CCCD');
});

// =========================================================================================
// TÀI KHOẢN BỊ KHOÁ — không mời người ta bấm vào một lời từ chối (mang sang từ rà soát M4, I6)
// =========================================================================================

/**
 * `DocumentPolicy::create()` nhánh khách KHÔNG hỏi `is_active`, nên nếu màn hình chỉ hỏi policy
 * thì nó vẫn vẽ nút "Gửi" cho một tài khoản đã bị khoá — bấm vào thì Action từ chối. Không sai
 * về an toàn, sai về việc mời người ta bấm vào một lời từ chối.
 *
 * Lái thẳng component vì `EnsurePortalAccountIsActive` chặn ở tầng HTTP: test này đo câu hỏi của
 * chính màn hình, không đo middleware.
 */
it('draws no send button for a deactivated account', function () {
    $locked = ClientUser::factory()->create(['client_id' => $this->client->id, 'is_active' => false]);

    $html = $this->actingAs($locked, 'client')
        ->livewire(SubmitDocument::class, ['record' => $this->matter->getKey(), 'item' => $this->item->getKey()])
        ->html();

    expect($html)->not->toContain('data-portal-action="send"')
        ->and($html)->toContain('liên hệ văn phòng');

    // Vế dương: cùng trang, tài khoản còn hiệu lực thì có nút.
    expect(submitPage(['item' => $this->item->getKey()])->html())->toContain('data-portal-action="send"');
});

// =========================================================================================
// 375px — CẤU TRÚC LÀM NÊN TÍNH DÙNG ĐƯỢC TRÊN ĐIỆN THOẠI (toolchain §4)
// =========================================================================================

it('lays the screen out in one column with no table', function () {
    $html = submitRegion(submitPage()->call('chooseItem', $this->item->getKey())->html());

    expect($html)->not->toContain('<table')
        ->and($html)->toContain('flex-direction:column');
});

it('gives every tap target at least 44px', function () {
    $html = submitRegion(submitPage()->call('chooseItem', $this->item->getKey())->html());

    preg_match_all('/<(?:a|button)\b[^>]*>/', $html, $matches);

    expect($matches[0])->not->toBeEmpty();

    foreach ($matches[0] as $tag) {
        expect($tag)->toContain('min-height: 44px');
    }
});

/**
 * Trạng thái rỗng không bao giờ là một bảng rỗng: một hồ sơ chưa có đầu mục nào phải nói ra bước
 * tiếp theo, không để khách đứng trước một màn hình trắng (toolchain §4).
 */
it('tells a client with no checklist item what to do instead of showing nothing', function () {
    $this->item->delete();

    $html = submitRegion(submitPage()->html());

    expect($html)->toContain('chưa cần giấy tờ nào')
        ->and($html)->not->toContain('data-portal-field="file"');
});

// =========================================================================================
// LỐI VÀO TỪ KHỐI 4 CỦA SPEC §8.3
// =========================================================================================

/**
 * SPEC §8.3 mục 4: đầu mục `missing` có nút nộp, đầu mục `rejected` có nút **nộp lại** (cạnh lý
 * do đầy đủ). Hai trạng thái đó và không trạng thái nào khác — khối 4 là danh sách "còn thiếu
 * gì", không phải một bảng thao tác.
 */
it('links from block 4 of the matter detail for a missing and a rejected item only', function () {
    $rejected = MatterChecklistItem::factory()->for($this->matter)->create([
        'name' => 'Hợp đồng chuyển nhượng',
        'status' => ChecklistItemStatus::Rejected,
        'rejection_reason' => 'Bản này là bản photo chưa chứng thực. Anh/chị mang bản gốc ra Uỷ ban phường để chứng thực giúp em.',
    ]);
    $accepted = MatterChecklistItem::factory()->for($this->matter)->create([
        'name' => 'Sổ hộ khẩu',
        'status' => ChecklistItemStatus::Accepted,
    ]);

    $html = $this->actingAs($this->clientUser, 'client')
        ->get(MatterProgress::getUrl(['record' => $this->matter->getKey()], panel: 'portal'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain(SubmitDocument::urlForItem($this->item))
        ->and($html)->toContain(SubmitDocument::urlForItem($rejected))
        ->and($html)->not->toContain(SubmitDocument::urlForItem($accepted));
});

/**
 * Lối vào đó mở đúng đầu mục đã bấm, không bắt khách chọn lại.
 *
 * **Đo trên RESPONSE THẬT của một lần GET, không trên một tham số mount.** `urlForItem()` sinh ra
 * `/portal/nop-giay-to/{record}?item={id}`: `record` là tham số route, `item` KHÔNG — nó đi qua
 * chuỗi truy vấn, và `SubmitDocument::mount()` nhặt nó bằng `request()->query('item')`. Trên một
 * request thật Livewire không có gì để truyền vào tham số `$item` của `mount()`, nên một khẳng
 * định qua `livewire(..., ['item' => ...])` đi một ĐƯỜNG KHÁC và không nói gì về lối vào này.
 *
 * Bản đầu của test này làm đúng như vậy, và nó xanh cả khi lần nhặt chuỗi truy vấn bị xoá hẳn:
 * tên đầu mục vẫn được in ra ở danh sách chọn của bước 1 nên `assertSee` không phân biệt được
 * "đã chọn" với "mời chọn". Nên phép đo phải là thứ CHỈ có khi đã chọn — ô chọn tệp — và nó đi
 * kèm vế âm của chính nó ngay bên dưới.
 */
it('opens on the item the client tapped', function () {
    $tapped = $this->actingAs($this->clientUser, 'client')
        ->get(SubmitDocument::urlForItem($this->item))
        ->assertOk()
        ->getContent();

    expect($tapped)->toContain('data-portal-field="file"')
        ->and($tapped)->toContain(__('portal_submit.steps.item.chosen'))
        ->and($tapped)->toContain('Giấy chứng nhận quyền sử dụng đất');

    // Vế âm: cùng hồ sơ, cùng tài khoản, KHÔNG chuỗi truy vấn → bước 1 mời chọn và không có ô
    // chọn tệp. Không có vế này, khẳng định trên xanh kể cả khi `?item=` bị bỏ qua hoàn toàn.
    $withoutQuery = $this->actingAs($this->clientUser, 'client')
        ->get(SubmitDocument::getUrl(['record' => $this->matter->getKey()], panel: 'portal'))
        ->assertOk()
        ->getContent();

    expect($withoutQuery)->not->toContain('data-portal-field="file"')
        ->and($withoutQuery)->toContain(__('portal_submit.steps.file.choose_item_first'));
});

// =========================================================================================
// TỆP ĐÃ CHỌN KHÔNG ĐI THEO NGƯỜI DÙNG SANG CHỖ KHÁC
// =========================================================================================

/**
 * Đổi đầu mục thì tệp đã chọn bị bỏ — đúng cái bẫy mà bước "xem trước" của SPEC §8.4 tồn tại để
 * tránh, chỉ ở chiều ngược lại: gửi đúng tệp vào nhầm chỗ. Một khách chọn ảnh cho "Sổ hộ khẩu"
 * rồi đổi ý sang "Hợp đồng" mà tệp vẫn nằm đó sẽ bấm Gửi và không hề biết mình vừa nộp gì vào đâu.
 */
it('drops the staged file when the client switches to another item', function () {
    $other = MatterChecklistItem::factory()->for($this->matter)->create(['name' => 'Sổ hộ khẩu']);

    $component = submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', submitPagePdf('cccd.pdf'));

    expect(submitRegion($component->html()))->toContain('cccd.pdf');

    $component->call('chooseItem', $other->getKey());

    expect(submitRegion($component->html()))->not->toContain('cccd.pdf');
});

/**
 * Sau khi gửi, ô chọn tệp trống lại. Không có nó, cú bấm Gửi thứ hai trên cùng màn hình tạo
 * thêm một bản version nữa của cùng một tờ giấy — và nó tiêu một suất trong 20 tệp/giờ, gọi đội
 * ngũ vào xem lần nữa, trong khi khách tưởng mình chỉ bấm nhầm.
 */
it('empties the file field after a successful send', function () {
    $component = submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', submitPagePdf('lan-1.pdf'))
        ->call('submit');

    expect(submitRegion($component->html()))
        ->toContain('Anh/chị chưa chọn tệp nào')
        ->not->toContain('lan-1.pdf');

    expect($component->instance()->pendingFiles())->toBe([]);
});

// =========================================================================================
// ENDPOINT TẢI LÊN CỦA LIVEWIRE — NƠI BYTE THẬT SỰ RƠI XUỐNG, VÀ NƠI TRẦN THẬT ĐỨNG
// =========================================================================================

/**
 * **Con số trên màn hình phải là con số ở endpoint.** Dòng hướng dẫn dưới ô chọn tệp hứa 20 MB và
 * cổng của trang ({@see SubmitDocument::_startUpload()}) cũng cho qua tới 20 MB — nhưng byte không
 * đi qua trang này: trình duyệt POST thẳng lên `livewire.upload-file`, một route mà trang không
 * sở hữu và không bọc. Luật của route ấy là `config('livewire.temporary_file_upload.rules')`, và
 * khi `config/livewire.php` chưa được publish thì nó trả về `max:12288` — 12 MB.
 *
 * Nên test này đi qua **HTTP thật, đúng URL đã ký mà `_startUpload` cấp**. Một bản mô phỏng
 * (`Testable::upload()`) gọi thẳng `validateAndStore()` nên đo được luật mà KHÔNG đo được route —
 * trong khi cả trần lẫn bộ đếm request đều nằm ở route.
 *
 * Dải 13–20 MB là dải mà SPEC §14 mục 4 sống hay chết: một khách chụp sổ đỏ bằng điện thoại đời
 * nay ra khoảng 15 MB.
 *
 * **Bảy lần POST, và con số bảy phụ thuộc vào `throttle:20,60` ở cùng tệp cấu hình** — đo được
 * bằng đột biến: hạ throttle xuống `5,60` thì test này đỏ ở 200/429 chứ không ở kích thước, tức
 * nó tố cáo đúng lỗi nhưng bằng sai câu. Không tách ra được mà vẫn giữ lời hứa "endpoint THẬT":
 * cả hai luật sống trên cùng một route. Nên nó được ghi lại ở đây, và ai hạ mức throttle xuống
 * dưới 8 phải đọc dòng này trước khi đi tìm lỗi ở luật `max`.
 */
it('accepts every size the screen promises, at the endpoint where the bytes actually land', function () {
    foreach ([11, 12, 13, 15, 19, 20] as $megabytes) {
        expect(submitPostBytes($megabytes)->status())->toBe(200, $megabytes.' MB');
    }

    // Vế âm trong cùng một test: trên mức đã hứa thì endpoint VẪN từ chối. Không có nó, khẳng
    // định trên xanh y hệt khi luật `max` biến mất hoàn toàn.
    expect(submitPostBytes(21)->status())->toBe(422);
});

/**
 * **Mọi lời từ chối của endpoint ấy đi ra bằng câu của văn phòng.**
 *
 * `WithFileUploads::_uploadErrored()` mặc định lấy thân JSON của lời từ chối, đổi `files.0` thành
 * tên thuộc tính rồi ném thẳng ra — khách đọc "data.file không được lớn hơn 12288 kilobyte" ngay
 * bên dưới dòng chữ hứa 20 MB. SPEC §8.4 cấm đích danh kiểu thông điệp đó.
 *
 * Thân JSON ở đây là thân THẬT, lấy từ chính lần POST vừa rồi — không phải một chuỗi bịa.
 */
it('answers a refusal from livewire own upload endpoint in the offices own words', function () {
    $refusal = submitPostBytes(21);

    expect($refusal->status())->toBe(422);

    $component = submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->call('_uploadErrored', 'data.file', $refusal->getContent(), false);

    $component->assertHasErrors('data.file');

    expect($component->errors()->first('data.file'))
        ->toContain('văn phòng')
        ->not->toContain('data.file')
        ->not->toContain('files.0')
        ->not->toContain('kilobyte');
});

/**
 * **Cửa của byte phải đứng ở chính endpoint, không chỉ ở màn hình.** SPEC §10.3: 20 tệp / giờ.
 *
 * Bộ đếm trong {@see SubmitDocument::_startUpload()} chặn việc CẤP một URL đã ký; nó không chặn
 * việc DÙNG một URL đã cấp. Một URL còn hạn (5 phút) dùng lại được, và middleware mặc định của
 * Livewire là `throttle:60,1` — 3600 tệp/giờ, gấp 180 lần mức SPEC cho phép, mỗi tệp một lần ghi
 * đĩa mà `VirusScanner` không bao giờ được hỏi tới.
 */
/**
 * **Bộ đếm của endpoint phải đếm theo TÀI KHOẢN, không theo ĐỊA CHỈ.**
 *
 * `throttle:20,60` cắm thẳng vào `config/livewire.php` khoá theo `$request->user()`, tức guard
 * MẶC ĐỊNH (`web`). Trên cổng thì guard là `client`, nên giá trị đó là `null` và
 * `ThrottleRequests` rơi về địa chỉ. Đo được trước vòng sửa này: tài khoản A gửi hết 20 và bị
 * chặn ở 21 — đúng; rồi tài khoản B, người thứ hai của CÙNG khách hàng, bị chặn ngay ở lần gửi
 * ĐẦU TIÊN với 429. SPEC §4.3 nêu đích danh hai tài khoản cho một khách hàng (hai vợ chồng) làm
 * trường hợp được thiết kế, và hai vợ chồng thì dùng chung một wifi.
 *
 * **Tiền đề được ĐO chứ không được giả định:** test thu địa chỉ của từng request đi qua kernel và
 * đòi cả loạt chỉ có MỘT địa chỉ duy nhất. Nếu một ngày nào đó bộ test đổi địa chỉ giữa chừng thì
 * phép đo này mất nghĩa, và nó phải đỏ chứ không được lặng lẽ xanh.
 */
it('does not let one portal account spend the upload limit of the other account on the same address', function () {
    $spouse = ClientUser::factory()->create(['client_id' => $this->client->id]);
    $url = submitUploadUrl();

    /** @var list<string> $addresses */
    $addresses = [];
    Event::listen(function (RequestHandled $event) use (&$addresses): void {
        $addresses[] = (string) $event->request->ip();
    });

    // Vế dương: người thứ nhất tiêu hết đúng 20 suất của mình rồi mới bị chặn.
    for ($attempt = 1; $attempt <= 20; $attempt++) {
        expect(submitPostBytesAs($this->clientUser, 1, $url)->status())->toBe(200, 'lần '.$attempt);
    }

    expect(submitPostBytesAs($this->clientUser, 1, $url)->status())->toBe(429);

    // Và người thứ hai, cùng đường truyền, vẫn còn nguyên mức của mình.
    expect(submitPostBytesAs($spouse, 1, $url)->status())->toBe(200);

    // Tiền đề: cả loạt đi ra từ đúng một địa chỉ, nên khác biệt trên chỉ có thể đến từ tài khoản.
    expect($addresses)->not->toBeEmpty()
        ->and(array_values(array_unique($addresses)))->toHaveCount(1);
});

/**
 * **Khoá mà `ThrottleRequests` ghi vào cache phải là khoá mà màn hình hỏi lại được.**
 *
 * `UploadThrottle::cacheKeyFor()` chép lại một dòng của framework (`md5($tên.$khoá)`). Một bản
 * chép thì sẽ trôi, nên nó được ghim từ phía HTTP thật thay vì được tin: 21 lần POST, rồi hỏi
 * đúng khoá ấy và đòi nó trả `true`. Một `$shouldHashKeys` đổi mặc định, hay một cách dựng khoá
 * khác ở bản Laravel sau, làm test này đỏ.
 */
it('writes the endpoint counter under the key the submit screen asks about', function () {
    $key = UploadThrottle::cacheKeyFor(Document::recipientToken($this->clientUser));

    expect(RateLimiter::tooManyAttempts($key, UploadThrottle::FILES_PER_HOUR))->toBeFalse();

    $url = submitUploadUrl();
    $last = null;
    for ($attempt = 1; $attempt <= 21; $attempt++) {
        $last = submitPostBytes(1, $url);
    }

    expect(RateLimiter::tooManyAttempts($key, UploadThrottle::FILES_PER_HOUR))->toBeTrue()
        // Cửa sổ đếm cũng được đo ở đây, vì docblock của `UploadThrottle` và của
        // `config/livewire.php` đều nói ra con số một giờ: lần thứ 21 trả 429 kèm một
        // `Retry-After` xấp xỉ 3600 giây. Một mức `throttle:20,1` vẫn làm mọi khẳng định khác
        // của tệp này xanh.
        ->and($last?->status())->toBe(429)
        ->and((int) $last?->headers->get('Retry-After'))->toBeGreaterThan(3500)
        ->and((int) $last?->headers->get('Retry-After'))->toBeLessThanOrEqual(3600);
});

/**
 * **Một 429 của endpoint phải đọc ra như một lời từ chối về SỐ LƯỢNG, không như một lời từ chối
 * về cái tệp.**
 *
 * Câu chung `upload_failed` nêu hai khả năng — tệp quá lớn, sóng gián đoạn — và cả hai đều SAI
 * khi cửa vừa đóng là bộ đếm giờ: tệp 5 MB, sóng tốt, và người đọc sẽ đi chụp lại ảnh rồi thử
 * lại suốt một tiếng. Câu đúng đã có sẵn ba dòng bên dưới trong cùng tệp ngôn ngữ
 * (`rate_limited_upload`) nhưng không có đường nào tới được nó trên nhánh này.
 *
 * Lý do phải HỎI LẠI bộ đếm thay vì đọc lý do từ response: JS của Livewire truyền `errors` là
 * `null` cho mọi mã khác 422, nên `_uploadErrored()` không có gì để đọc. Nó hỏi bộ đếm trên
 * ĐÚNG khoá mà middleware vừa ghi.
 */
it('names the hourly limit when the endpoint refuses with 429, instead of blaming the file', function () {
    $url = submitUploadUrl();
    for ($attempt = 1; $attempt <= 20; $attempt++) {
        submitPostBytes(1, $url);
    }

    $refusal = submitPostBytes(1, $url);
    expect($refusal->status())->toBe(429);

    // TRUYỀN `null`, ĐÚNG NHƯ TRÌNH DUYỆT LÀM. Bản đầu của test này đưa nguyên thân response 429
    // vào tham số thứ hai — thứ trình duyệt KHÔNG BAO GIỜ gửi, vì JS của Livewire đặt `errors`
    // là `null` cho mọi mã khác 422. Hệ quả: nếu ai đó "đơn giản hoá" `_uploadErrored()` thành
    // đọc tham số ấy thay vì hỏi lại bộ đếm, cả 49 test trong tệp này vẫn xanh, và khách chạm
    // trần giờ sẽ được báo là tệp quá lớn với sóng yếu — đúng lỗi mà cả vòng sửa này sinh ra để
    // đóng. Phát hiện bởi lượt kiểm chứng độc lập vòng sửa, ngày 2026-09-22.
    $message = submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->call('_uploadErrored', 'data.file', null, false)
        ->errors()->first('data.file');

    expect($message)
        // Con số và số phút phải có mặt.
        ->toContain((string) UploadThrottle::FILES_PER_HOUR)
        ->toContain('phút')
        ->toContain((string) config('vkcrm.brand.hotline'))
        // Và KHÔNG phải câu chung đổ cho dung lượng với sóng.
        ->not->toContain('sóng')
        ->not->toContain('HDR')
        ->not->toContain('kilobyte');
});

/**
 * Vế âm của test trên, và nó là vế giữ cho câu kia không biến thành câu duy nhất: khi bộ đếm CHƯA
 * đầy thì một lời từ chối của endpoint vẫn đi ra bằng câu chung nêu hai khả năng.
 */
it('still answers a refusal that is not about the hourly limit with the general sentence', function () {
    $refusal = submitPostBytes(21);

    expect($refusal->status())->toBe(422);

    $message = submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->call('_uploadErrored', 'data.file', $refusal->getContent(), false)
        ->errors()->first('data.file');

    expect($message)
        ->toContain('sóng')
        ->not->toContain('kilobyte');
});

it('lets one signed upload url be used twenty times an hour and no more', function () {
    $url = submitUploadUrl();

    // Vế dương: đúng 20 lần đầu đi qua, nên test này không xanh vì mọi thứ đều bị chặn.
    for ($attempt = 1; $attempt <= 20; $attempt++) {
        expect(submitPostBytes(1, $url)->status())->toBe(200, 'lần '.$attempt);
    }

    expect(submitPostBytes(1, $url)->status())->toBe(429);
});

// =========================================================================================
// HAI CỬA, HAI CÂU — VÀ BỘ ĐẾM ĐẾM ĐÚNG THỨ DOCBLOCK NÓI NÓ ĐẾM
// =========================================================================================

/**
 * **Câu từ chối phải nói đúng cái cửa vừa đóng.** Bộ đếm BYTE tiêu một suất ngay khi khách CHỌN
 * một tệp — chưa gửi gì cả. Một câu nói "anh/chị đã gửi 20 tệp" ở cửa đó là sai sự thật với
 * chính người đang đọc nó, và người đó thì đang tìm xem mình đã gửi những gì.
 */
it('tells a client which of the two doors closed', function () {
    $fileKey = SubmitDocument::fileLimiterKey($this->clientUser);

    for ($i = 0; $i < 20; $i++) {
        RateLimiter::hit($fileKey, 3600);
    }

    $atTheByteDoor = submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->call('_startUpload', 'data.file', [['name' => 'anh.jpg', 'size' => 1024, 'type' => 'image/jpeg']], false)
        ->errors()->first('data.file');

    expect($atTheByteDoor)->toContain('đã chọn 20 tệp')->not->toContain('đã bấm gửi');

    $sendKey = SubmitDocument::submissionLimiterKey($this->clientUser);

    for ($i = 0; $i < 20; $i++) {
        RateLimiter::hit($sendKey, 3600);
    }

    $atTheRecordDoor = submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', submitPagePdf())
        ->call('submit')
        ->errors()->first('data.file');

    expect($atTheRecordDoor)->toContain('đã bấm gửi 20 lần')
        ->and($atTheRecordDoor)->not->toBe($atTheByteDoor);
});

/**
 * Final review C-M10 — ĐẢO NGƯỢC test cũ cùng vị trí ("counts a submission the field rules refuse
 * as an attempt too"): một đuôi tệp sai bị luật của ô từ chối không tốn suất nào ở cửa của BẢN
 * GHI. Năm lần gửi nhầm định dạng không được trừ năm trong 20 tệp/giờ khách cần cho giấy tờ thật.
 */
it('does not charge the send gate for a submission the field rules refuse', function () {
    $key = SubmitDocument::submissionLimiterKey($this->clientUser);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        submitPage()
            ->call('chooseItem', $this->item->getKey())
            ->set('data.file', UploadedFile::fake()->createWithContent('chu-ky.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'))
            ->call('submit')
            ->assertHasErrors('data.file');
    }

    expect(RateLimiter::attempts($key))->toBe(0)
        ->and(Document::query()->count())->toBe(0);
});

/**
 * **Đổi đầu mục phải quên đầu mục cũ, kể cả bản ghi đã giải.** Livewire mang tới 50 lời gọi trong
 * MỘT request (`livewire.payload.max_calls`), nên hai lần `chooseItem()` rồi một lần `submit()`
 * nằm chung một request là một hình dạng có thật. `$item` đổi, còn bản ghi đã nhớ thì không — và
 * `submit()` đọc bản ghi đã nhớ, nên tệp hạ cánh xuống đầu mục TRƯỚC ĐÓ.
 *
 * Không có đường vượt tuyến ở đây: cả hai id đều đã đi qua `resolveItem()`, tức đều của khách
 * này. Cái hỏng là gửi đúng tệp vào nhầm chỗ — đúng cái bẫy mà bước "xem trước" của SPEC §8.4
 * tồn tại để tránh, chỉ ở chiều ngược lại.
 */
it('forgets the previously resolved item when the client picks another one in the same request', function () {
    $other = MatterChecklistItem::factory()->for($this->matter)->create(['name' => 'Sổ hộ khẩu']);

    $page = submitPage()->instance();

    $page->chooseItem($this->item->getKey());

    expect($page->checklistItem()?->is($this->item))->toBeTrue();

    $page->chooseItem($other->getKey());

    expect($page->item)->toBe($other->getKey())
        ->and($page->checklistItem()?->is($other))->toBeTrue();
});

/**
 * **Bước 1 hỏi `Gate` từng dòng, và `Gate` không được hỏi lại CSDL từng dòng.**
 *
 * `DocumentPolicy::create()` đi qua `$item->matter` — một quan hệ LƯỜI — rồi qua
 * `MatterPolicy::view()` trên chính vụ việc mà trang vừa gác xong ở `resolveMatter()`. Một danh
 * mục hai mươi đầu mục là chuyện thường ở một hồ sơ đất đai, và mỗi lần vẽ bước 1 là một lần vẽ
 * cả danh sách.
 *
 * Đo bằng ĐỘ DỐC chứ không bằng một con số tuyệt đối: số truy vấn của khung Filament không phải
 * việc của test này, còn số truy vấn THÊM cho mỗi đầu mục thì đúng là việc của nó.
 *
 * **Ngân sách là 2 truy vấn mỗi đầu mục, và con số đó được nói ra kèm lý do — đo thật, không
 * suy.** Trước khi sửa: 3. Ba truy vấn ấy là
 *
 *  1. nạp lười `$item->matter` — **đã xoá**, vì `choosableItems()` gắn sẵn chính vụ việc mà
 *     `resolveMatter()` vừa gác xong;
 *  2. `EXISTS` trên `matter_checklist_items` — câu hỏi portal của RIÊNG đầu mục này, tức đúng
 *     tầng mà lần hỏi `Gate` từng dòng tồn tại để hỏi. Xoá nó là xoá chính cái lưới;
 *  3. `EXISTS` trên `matters` — `MatterPolicy::view` hỏi lại portal-visibility của vụ việc, và
 *     nó hỏi lại **y hệt nhau** ở mọi vòng lặp. Gộp nó lại được, nhưng chỗ gộp nằm trong
 *     `MatterPolicy` / `ChecksMatterAccess` — những tệp mà task này không sở hữu, nên nó được
 *     báo lại chứ không sửa lén (cùng hình dạng đường trong bộ nhớ mà nhánh nhân sự đã có khi
 *     `team` đã nạp).
 *
 * Ngưỡng đặt ở ngân sách chứ không ở con số chính xác: một lần gộp được mục 3 ở tệp khác không
 * được làm test này đỏ, còn một lần trả mục 1 về thì phải.
 */
it('does not ask the database again for the matter of every item it offers', function () {
    $measure = function (int $extraItems): int {
        MatterChecklistItem::factory()->count($extraItems)->for($this->matter)->create();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        submitPage()->html();

        return $queries;
    };

    $withOneItem = $measure(0);
    $withElevenItems = $measure(10);

    expect($withElevenItems - $withOneItem)->toBeLessThanOrEqual(2 * 10);
});
