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
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    // (b) qua tham số truy vấn của lần mount
    submitPage(['item' => $otherItem->getKey()])->assertNotFound();

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
    submitPage(['item' => $this->item->getKey()])->assertSet('item', $this->item->getKey());
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
 * Đo ở `_startUpload`, tức **trước khi một byte nào rời khỏi điện thoại** — không phải để tiện,
 * mà vì đó là chỗ duy nhất câu ấy tới được khách: Livewire tự từ chối ở 12 MB tại endpoint tải
 * lên của chính nó, nơi màn hình này không có mặt (xem docblock lớp, mục "trần 12 MB"). Nên một
 * tệp 21 MB gửi qua `set()` chỉ nhận được câu của Livewire, còn cùng tệp đó đi đúng đường của
 * trình duyệt thật thì nhận đúng câu của SPEC.
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

    expect($component->errors()->first('data.file'))->toContain('20 tệp')
        ->and(Document::query()->count())->toBe(1);
});

/**
 * Một lần bị từ chối KHÔNG để lại dòng nào trong `documents` lẫn trong nhật ký, nên bộ đếm phải
 * tự đếm số LẦN THỬ — dựng lại nó từ dữ liệu đã ghi sẽ đếm thiếu đúng những lần đáng đếm nhất.
 */
it('counts a refused submission as an attempt', function () {
    $key = SubmitDocument::submissionLimiterKey($this->clientUser);

    submitPage()
        ->call('chooseItem', $this->item->getKey())
        ->set('data.file', UploadedFile::fake()->createWithContent('so-do.pdf', "MZ\x90\x00\x03\x00\x00\x00"))
        ->call('submit')
        ->assertHasErrors('data.file');

    expect(Document::query()->count())->toBe(0)
        ->and(RateLimiter::attempts($key))->toBe(1);
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

/** Lối vào đó mở đúng đầu mục đã bấm, không bắt khách chọn lại. */
it('opens on the item the client tapped', function () {
    $this->actingAs($this->clientUser, 'client')
        ->get(SubmitDocument::urlForItem($this->item))
        ->assertOk()
        ->assertSee('Giấy chứng nhận quyền sử dụng đất');

    expect(submitPage(['item' => $this->item->getKey()])->get('item'))->toBe($this->item->getKey());
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

    expect($component->instance()->pendingFile())->toBeNull();
});
