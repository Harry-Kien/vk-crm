<?php

use App\Actions\Document\UploadStaffDocument;
use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Tab "Tài liệu" (SPEC §7.2): nhóm A/B/C/D, nhãn nhóm D, và bốn thao tác.
 *
 * `Storage::fake('private')` CỘNG một `media-library.prefix` riêng cho từng test: `Storage::fake()`
 * dọn một gốc DÙNG CHUNG trong khi đường dẫn medialibrary là `{media.id}/{file_name}` và
 * `RefreshDatabase` trả id về 1 ở đầu mỗi test, nên mọi test ghi, xoá rồi ghi lại đúng một đường
 * dẫn — chuỗi đó không ổn định trên bind mount của Docker trên Windows (Task 5 đo được 2 lần hỏng
 * trên 11 lần chạy).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Storage::fake('private');
    config(['media-library.prefix' => 'test-'.Str::random(16)]);
});

function documentsManager(Matter $matter)
{
    return test()->livewire(DocumentsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ]);
}

/** Một tài liệu có tệp thật trên disk giả — nút tải và `PublishDocument` đều đòi tệp. */
function documentWithFile(Matter $matter, DocumentGroup $group, array $attributes = []): Document
{
    $document = Document::factory()->for($matter)->group($group)->create($attributes);

    $document->addMedia(UploadedFile::fake()->createWithContent('nguon.pdf', '%PDF-1.4 noi dung that'))
        ->usingName('Ban sao ho khau.pdf')
        ->usingFileName('01k5g7q8wz0000000000000000.pdf')
        ->toMediaCollection('file');

    return $document->refresh();
}

/**
 * Tiêu đề của MỌI thông báo đã gửi, đọc đúng MỘT lần.
 *
 * `Notification::assertNotified()` và `::assertNotNotified()` đều `mount()` một component
 * `Notifications` mới, và việc đó kéo thông báo RA KHỎ session — đọc một lần là MẤT. Gọi
 * liên tiếp hai hàm đó trong cùng một `it()` vì vậy làm hàm thứ hai nhìn vào một danh sách
 * rỗng và xanh mà không kiểm tra gì (bài học đã ghi ở `ViewMatterTest`). Test nào cần khẳng
 * định cả "có câu này" lẫn "KHÔNG có câu kia" phải đọc một lần rồi so trên chính danh sách đó.
 *
 * @return array<int, string|null>
 */
function sentNotificationTitles(): array
{
    $component = new Notifications;
    $component->mount();

    return $component->notifications
        ->map(fn (Notification $notification): ?string => $notification->getTitle())
        ->all();
}

/** Tệp hợp lệ cho `FileGuard`: PDF thật, đuôi khớp MIME thật. */
function validPdf(string $name = 'quyet-dinh.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
    );
}

// ---------------------------------------------------------------------------------------------
// Nhóm D — SPEC §7.2 và §11 "Tài liệu nội bộ".
// ---------------------------------------------------------------------------------------------

/**
 * SPEC §7.2 in đậm: nhóm D hiện trên nền khác màu rõ rệt VÀ có nhãn "Chỉ nội bộ — không bao giờ
 * hiện cho khách". Nhãn được khẳng định NGUYÊN VĂN ở đây, không qua khoá dịch: đó là một câu SPEC
 * viết ra, và một lần "sửa cho gọn" phải đỏ.
 */
it('marks a group D row with the verbatim SPEC label and leaves every other group unmarked', function () {
    expect(__('documents.tab.internal_marker'))
        ->toBe('Chỉ nội bộ — không bao giờ hiện cho khách')
        ->and(DocumentsRelationManager::internalMarkerLabel(DocumentGroup::Internal))
        ->toBe(__('documents.tab.internal_marker'));

    foreach ([DocumentGroup::ClientProvided, DocumentGroup::Issued, DocumentGroup::Authority] as $group) {
        expect(DocumentsRelationManager::internalMarkerLabel($group))->toBeNull();
    }
});

/**
 * SPEC §7.2 đòi "nền khác màu RÕ RỆT" cho nhóm D, và trong dự án không có bước dựng CSS thì một
 * lớp Tailwind viết tay KHÔNG tô được gì (xem docblock lớp). Test này ghim ba thứ mà nếu thiếu
 * một trong ba thì cái nền đó biến mất trong khi mã vẫn trông như đã đặt nó:
 *
 *  - luật CSS thật sự đi kèm bảng (nó nằm trong `->description()`, in ra một lần);
 *  - bộ chọn đủ dài để thắng luật nền mặc định của Filament;
 *  - và `recordClasses()` gắn đúng cái lớp mà luật đó nói tới, chỉ trên dòng nhóm D.
 */
it('ships a CSS rule for the group D row background that can actually win', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    Document::factory()->for($matter)->group(DocumentGroup::Internal)->create();
    Document::factory()->for($matter)->group(DocumentGroup::Authority)->create();

    $style = (string) DocumentsRelationManager::internalRowStyle();

    expect($style)->toStartWith('<style>')->toContain('background-color');

    $this->actingAs($lawyer, 'web');

    $html = documentsManager($matter)->assertSee($style, escape: false)->html();

    // **Đếm trên THẺ `<tr>`, không tìm chuỗi trong cả trang.** Bản đầu dùng
    // `assertSeeHtml('vk-internal-document')`, và chuỗi đó có sẵn trong chính khối `<style>` vừa
    // khẳng định ở trên — nên một mutation probe gỡ `recordClasses()` đi vẫn để test xanh, và
    // cái nền mà SPEC §7.2 đòi biến mất trong khi mã trông như đã đặt nó.
    preg_match_all('/<tr\b[^>]*\bvk-internal-document\b[^>]*>/', $html, $rows);

    expect($rows[0])->toHaveCount(1);

    /*
     * **Và đây là câu hỏi thật sự: bộ chọn có chạm tới cái nó nhắm vào không.** Bản trước của
     * test này khẳng định bộ chọn CHỨA chuỗi `.fi-ta-content-ctn .fi-ta-content .fi-ta-record`,
     * và nó xanh suốt trong khi trên trình duyệt dòng nhóm D không hề đổi màu: bảng thường của
     * Filament render `<tr class="fi-ta-row">` bên trong `.fi-ta-content-ctn` mà KHÔNG có
     * `.fi-ta-content` ở giữa, nên bộ chọn ấy không khớp một dòng nào. Một khẳng định về một
     * chuỗi không phải một phép đo.
     *
     * Ở đây lấy TỪNG lớp CSS mà bộ chọn nhắc tới rồi đối chiếu với HTML mà bảng thật sự sinh ra.
     * Ai đổi tên lớp ở Filament, hay viết lại bộ chọn theo trí nhớ, đều đỏ tại đây.
     *
     * **Nói thẳng chỗ test này KHÔNG với tới.** Một mutation probe bỏ `>.fi-ta-cell` đi — tức là
     * quay về tô nền cho chính `<tr>` — vẫn để test này xanh, vì ba lớp còn lại đều có thật trong
     * HTML. Sự thật rằng cái `<tr>` của bảng Filament không nhận được `background-color` (đo được:
     * `red !important` trên nó vẫn cho `oklab(0 0 0 / 0)`, các ô `<td>` phủ kín hàng) chỉ quan sát
     * được trong một trình duyệt có bố cục thật, và PHP không có bố cục. Phép đo đó nằm ở docblock
     * `internalRowStyle()` cùng con số đo lại sau khi sửa, chứ không giả vờ là một test.
     */
    preg_match('/<style>([^{]+)\{/', $style, $selector);
    preg_match_all('/\.([a-zA-Z0-9_-]+)/', $selector[1], $classes);

    expect($classes[1])->not->toBeEmpty();

    $missing = array_values(array_filter(
        array_unique($classes[1]),
        fn (string $class): bool => ! preg_match('/\bclass="[^"]*\b'.preg_quote($class, '/').'\b/', $html),
    ));

    expect($missing)->toBe([]);
});

/**
 * **Khẳng định trên CỘT, không trên chuỗi HTML.** Bản đầu của test này chỉ `assertSee()` câu
 * nhãn, và câu đó cũng xuất hiện trong phần MÔ TẢ NHÓM (`getDescriptionFromRecordUsing`) — nên
 * một mutation probe gỡ sạch cột nhãn khỏi bảng vẫn để test xanh. `assertTableColumnStateSet()`
 * hỏi đúng cột đó về đúng dòng đó, nên nó không nhầm được với một lớp hiển thị khác.
 *
 * Cặp sinh đôi âm nằm ngay trong cùng test: một dòng nhóm C trên cùng bảng có ô đó RỖNG.
 */
it('shows the internal marker on the rendered table for someone who may read group D', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $internal = Document::factory()->for($matter)->group(DocumentGroup::Internal)->create([
        'title' => 'Đánh giá nội bộ khả năng thắng kiện',
    ]);
    $authority = Document::factory()->for($matter)->group(DocumentGroup::Authority)->create();

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)
        ->assertSee('Đánh giá nội bộ khả năng thắng kiện')
        ->assertTableColumnExists('internal_marker')
        ->assertTableColumnStateSet('internal_marker', __('documents.tab.internal_marker'), $internal)
        ->assertTableColumnStateSet('internal_marker', null, $authority);
});

/**
 * SPEC §5: `document.viewInternal` là ranh giới của nhóm D, và trợ lý không có nó. Cặp sinh đôi
 * dương nằm trong cùng test: cùng bảng, cùng vụ việc, trợ lý ĐỌC ĐƯỢC ba nhóm kia.
 *
 * Lọc này KHÔNG do relation manager làm — `Document`'s policy và `ScopesToVisibleMatters` không
 * loại nhóm D cho nhân sự; Filament tự hỏi `DocumentPolicy::view` cho từng dòng khi dựng bảng.
 * Test khẳng định KẾT QUẢ mà người dùng thấy, vì đó là thứ phải đúng dù cơ chế nằm ở đâu.
 */
it('hides group D from an assistant while showing them the other three groups', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    Document::factory()->for($matter)->group(DocumentGroup::Internal)->create(['title' => 'GHICHUNOIBODANHRIENG']);
    Document::factory()->for($matter)->group(DocumentGroup::Authority)->create(['title' => 'Thông báo thụ lý của toà']);

    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($assistant, 'web');

    documentsManager($matter)
        ->assertSee('Thông báo thụ lý của toà')
        ->assertDontSee('GHICHUNOIBODANHRIENG')
        ->assertDontSee(__('documents.tab.internal_marker'));

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)->assertSee('GHICHUNOIBODANHRIENG');
});

/**
 * "Thao tác của nhóm D không bao giờ được mời công bố." Cặp sinh đôi dương trong cùng test: cùng
 * người, cùng bảng, một dòng nhóm C có nút đó.
 */
it('never offers publish on a group D row, and does offer it on a group C row', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $internal = documentWithFile($matter, DocumentGroup::Internal);
    $authority = documentWithFile($matter, DocumentGroup::Authority);

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)
        ->assertActionHidden(TestAction::make('publish')->table($internal))
        ->assertActionVisible(TestAction::make('publish')->table($authority));
});

/**
 * Đường DUY NHẤT ra khỏi nhóm D là `RegroupDocument`, và nó ghi lại nhóm cũ. Thao tác phải có mặt
 * trên chính dòng nhóm D — nếu ẩn cả nó thì một tài liệu xếp nhầm nhóm nằm lại đó mãi.
 */
it('moves a document out of group D through RegroupDocument and records the old group', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $internal = documentWithFile($matter, DocumentGroup::Internal);

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)
        ->callAction(TestAction::make('regroup')->table($internal), data: [
            'group' => DocumentGroup::Authority->value,
        ])
        ->assertHasNoActionErrors();

    expect($internal->refresh()->group)->toBe(DocumentGroup::Authority);

    $regrouped = Activity::query()->where('event', 'document_regrouped')->first();

    expect($regrouped)->not->toBeNull()
        ->and($regrouped->properties['from_group'] ?? null)->toBe(DocumentGroup::Internal->value);
});

// ---------------------------------------------------------------------------------------------
// Ai thấy gì — SPEC §5, §11 "Quyền nội bộ".
// ---------------------------------------------------------------------------------------------

it('gives an accountant no documents tab at all, while a lawyer on the team reads it', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->actingAs($accountant, 'web')
        ->get(MatterResource::getUrl('view', ['record' => $matter], panel: 'admin'))
        ->assertNotFound();

    $this->actingAs($lawyer, 'web')
        ->get(MatterResource::getUrl('view', ['record' => $matter], panel: 'admin'))
        ->assertOk()
        ->assertSee(__('matters.tabs.documents'));
});

it('keeps a matter outside the actors scope out of the relation managers own query', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    Document::factory()->for($matter)->group(DocumentGroup::Authority)->create(['title' => 'Quyết định của toà số 42']);

    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($outsider, 'web');

    documentsManager($matter)->assertDontSee('Quyết định của toà số 42');

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)->assertSee('Quyết định của toà số 42');
});

/**
 * `document.publish` (SPEC §5) là ranh giới giữa "làm hồ sơ" và "quyết định số phận một tài
 * liệu". Trợ lý không có nó, nên nút công bố phải ẩn — kể cả trên một tài liệu nhóm C mà họ đọc
 * được và sửa được. Cặp sinh đôi dương: luật sư trong đội ngũ thấy đúng cái nút đó trên đúng dòng
 * đó.
 */
it('hides the publish button from an assistant and shows it to a lawyer on the team', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $document = documentWithFile($matter, DocumentGroup::Authority);

    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($assistant, 'web');

    documentsManager($matter)
        // Dương trong cùng một lượt: trợ lý ĐỌC ĐƯỢC dòng này và tải được tệp của nó — lời từ
        // chối bên dưới nói về quyền công bố, không về khả năng thấy tài liệu.
        ->assertSee($document->title)
        ->assertActionVisible(TestAction::make('download')->table($document))
        ->assertActionHidden(TestAction::make('publish')->table($document));

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)->assertActionVisible(TestAction::make('publish')->table($document));
});

// ---------------------------------------------------------------------------------------------
// Ô chọn nhóm — và cái ghim giữ cho nó không lệch khỏi Action.
// ---------------------------------------------------------------------------------------------

/**
 * **Ghim cho `DocumentsRelationManager::RELEASED_AT_CREATION`.** Danh sách đó là bản sao của một
 * sự thật sống trong `StoresDocumentFile` (`protected`, màn hình không hỏi được), nên nó được đo
 * chứ không được tin: với một TRỢ LÝ, chạy thật `UploadStaffDocument` cho cả bốn nhóm và khẳng
 * định rằng đúng những nhóm mà ô chọn KHÔNG mời là những nhóm Action từ chối.
 *
 * Nếu SPEC §4.11 đổi bảng mặc định, test này đỏ ngay — và nó đỏ ở cả hai hướng: một nhóm bị loại
 * thừa cũng làm nó đỏ.
 */
it('offers exactly the groups an assistant can actually upload into', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($assistant, 'web');

    $offered = array_keys(DocumentsRelationManager::groupOptions($matter));

    $accepted = [];

    foreach (DocumentGroup::cases() as $group) {
        try {
            app(UploadStaffDocument::class)->handle(
                matter: $matter,
                actor: $assistant,
                file: validPdf(),
                group: $group,
                title: 'Thử nhóm '.$group->value,
            );

            $accepted[] = $group->value;
        } catch (Throwable) {
            // Nhóm này Action từ chối — đúng nhóm mà ô chọn không được mời.
        }
    }

    // Đo được: trợ lý nộp được vào B, C và D; nhóm A bị từ chối vì nó ra tới khách ngay lúc tạo
    // và họ không có `document.publish`.
    expect($accepted)->toBe(['B', 'C', 'D'])
        ->and($offered)->toBe(['B', 'C']);

    // Hai hướng của cùng một cái ghim. Nhóm D là nhóm DUY NHẤT mà Action cho qua còn ô chọn
    // không mời, và nó bị loại vì một lý do thứ hai, độc lập: trợ lý không đọc lại được nó
    // (`document.viewInternal`). Không có nhóm nào theo chiều ngược lại — một nhóm được mời mà
    // Action từ chối là đúng cái bẫy 403 tiếng Anh mà `RELEASED_AT_CREATION` tồn tại để chặn.
    expect(array_values(array_diff($accepted, $offered)))->toBe([DocumentGroup::Internal->value])
        ->and(array_values(array_diff($offered, $accepted)))->toBe([]);
});

it('offers every group including D to a lawyer, who may both publish and read internal files', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    expect(array_keys(DocumentsRelationManager::groupOptions($matter)))
        ->toBe(['A', 'B', 'C', 'D']);
});

it('never offers group D to someone without document.viewInternal', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($assistant, 'web');

    expect(array_keys(DocumentsRelationManager::groupOptions($matter)))->not->toContain('D')
        ->and(array_keys(DocumentsRelationManager::regroupOptions()))->not->toContain('D');

    $this->actingAs($lawyer, 'web');

    expect(array_keys(DocumentsRelationManager::regroupOptions()))->toContain('D');
});

// ---------------------------------------------------------------------------------------------
// Đưa tài liệu vào hồ sơ — mọi lần ghi đi qua UploadStaffDocument.
// ---------------------------------------------------------------------------------------------

it('uploads a staff document through UploadStaffDocument, with the file on the private disk', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $item = MatterChecklistItem::factory()->for($matter)->create();

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)
        ->callAction(TestAction::make('upload')->table(), data: [
            'file' => validPdf('thong-bao-thu-ly.pdf'),
            'title' => 'Thông báo thụ lý vụ án số 42/2026',
            'group' => DocumentGroup::Authority->value,
            'matter_checklist_item_id' => $item->id,
            'issued_at' => '2026-03-01',
        ])
        ->assertHasNoActionErrors();

    $document = $matter->documents()->firstOrFail();

    expect($document->title)->toBe('Thông báo thụ lý vụ án số 42/2026')
        ->and($document->group)->toBe(DocumentGroup::Authority)
        // Bộ mặc định SPEC §4.11 cho nhóm C: chưa ra tới khách.
        ->and($document->status)->toBe(DocumentStatus::InternalDraft)
        ->and($document->client_can_view)->toBeFalse()
        ->and($document->matter_checklist_item_id)->toBe($item->id)
        ->and($document->issued_at->toDateString())->toBe('2026-03-01')
        ->and($document->getMedia('file'))->toHaveCount(1);

    Storage::disk('private')->assertExists($document->getFirstMedia('file')->getPathRelativeToRoot());
});

/**
 * Họ `FileRejected` (một `DomainException`): một `.svg` bị `FileGuard` từ chối (SPEC §11 "Tải
 * tệp"). Câu từ chối phải bám vào ô TỆP — đó là ô người dùng phải làm gì đó với — chứ không biến
 * mất, và không có tài liệu nào được tạo.
 */
it('binds a rejected file to the file field and creates nothing', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)
        ->callAction(TestAction::make('upload')->table(), data: [
            // **Tệp phải ĐI LỌT luật của chính schema rồi mới bị `FileGuard` từ chối.** Bản
            // đầu gửi một `.svg`, và `acceptedFileTypes()` ở ô chọn tệp đã loại nó từ trước — nên
            // lần từ chối là của Filament, `UploadStaffDocument` không hề chạy, và mutation probe
            // gỡ nhánh `FileRejected` đi vẫn để test xanh. Đây là đúng trường hợp SPEC §11 "Tải
            // tệp" nêu: đuôi `.pdf` nhưng nội dung thật không phải PDF. `Content-Type` do client
            // gửi lên suỷ ra từ đuôi nên nó qua được `acceptedFileTypes()`; `finfo` thì không.
            'file' => UploadedFile::fake()->createWithContent('so-do.pdf', "MZ\x90\x00\x03\x00\x00\x00"),
            'title' => 'Sơ đồ thửa đất',
            'group' => DocumentGroup::Authority->value,
        ])
        ->assertHasActionErrors(['file']);

    expect($matter->documents()->count())->toBe(0);
});

/**
 * Họ `ValidationException`: `UploadStaffDocument` bước 2 từ chối một đầu mục thuộc HỒ SƠ KHÁC, và
 * khoá nó gắn (`matter_checklist_item_id`) phải tới được đúng cái ô cùng tên trong modal. Ô chọn
 * chỉ liệt kê đầu mục của hồ sơ đang mở, nên đây là lớp phòng thủ thứ hai — và nó chỉ có giá trị
 * nếu đỏ được khi biến mất, nên test gọi thẳng action với một id ngoài danh sách.
 */
it('binds a checklist item from another matter to its own field', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $otherItem = MatterChecklistItem::factory()->create();

    expect(DocumentsRelationManager::checklistItemOptions($matter))->not->toHaveKey($otherItem->id);

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)
        ->mountAction(TestAction::make('upload')->table())
        ->setActionData([
            'file' => validPdf(),
            'title' => 'Tài liệu gắn nhầm hồ sơ',
            'group' => DocumentGroup::Authority->value,
            'matter_checklist_item_id' => $otherItem->id,
        ])
        ->callMountedAction()
        ->assertHasActionErrors(['matter_checklist_item_id']);

    expect($matter->documents()->count())->toBe(0);
});

it('refuses an upload for someone who cannot update the matter, and hides the button from them', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    // Vụ việc đã xoá mềm: `MatterPolicy::update` từ chối MỌI vai trò, kể cả admin — nên nút phải
    // ẩn ngay cả với người có nhiều quyền nhất. Đây là nửa chứng minh rằng `->authorize()` thật
    // sự truyền hồ sơ vào `DocumentPolicy::create`, chứ không hỏi nhánh không-ngữ-cảnh (nhánh đó
    // chỉ đọc `matter.update` và sẽ CHO QUA).
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter->delete();

    $this->actingAs($admin, 'web');

    documentsManager($matter)->assertActionHidden(TestAction::make('upload')->table());

    $matter->restore();

    documentsManager($matter)->assertActionVisible(TestAction::make('upload')->table());
});

// ---------------------------------------------------------------------------------------------
// Công bố — SPEC §6.5.
// ---------------------------------------------------------------------------------------------

it('publishes a group C document through PublishDocument with the two independent flags', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $document = documentWithFile($matter, DocumentGroup::Authority);

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)
        ->callAction(TestAction::make('publish')->table($document), data: [
            'client_can_view' => true,
            'client_can_download' => false,
        ])
        ->assertHasNoActionErrors();

    $document->refresh();

    expect($document->status)->toBe(DocumentStatus::Published)
        ->and($document->client_can_view)->toBeTrue()
        // SPEC §6.5 bước 3: cho khách biết đã có tài liệu mà chưa cho tải là hợp lệ.
        ->and($document->client_can_download)->toBeFalse()
        ->and($document->published_by)->toBe($lawyer->id)
        ->and(DocumentsRelationManager::clientAccessLabel($document))
        ->toBe(__('documents.tab.client_access.view_only'));
});

/**
 * Họ `DomainException`: vòng đời nhóm B (SPEC §4.11) từ chối một cú nhảy thẳng vào `published`.
 * Lời từ chối là một câu tiếng Việt nói ra việc cần làm tiếp theo — nó phải tới được màn hình,
 * không thành lỗi 500 và không thành một mã HTTP riêng (SPEC §10.10, xem docblock
 * `ReportsActionFailures`).
 *
 * Cặp sinh đôi dương: cùng tài liệu, cùng người, sau khi nó đã ở `signed_filed` thì công bố được.
 */
it('turns a refused group B publication into a Vietnamese notification, then lets it through once signed and filed', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $document = documentWithFile($matter, DocumentGroup::Issued, ['status' => DocumentStatus::PendingApproval]);

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)->callAction(TestAction::make('publish')->table($document), data: [
        'client_can_view' => true,
        'client_can_download' => true,
    ]);

    Notification::assertNotified(__('actions.failed_title'));

    expect($document->refresh()->status)->toBe(DocumentStatus::PendingApproval);

    $document->forceFill(['status' => DocumentStatus::SignedFiled])->save();

    documentsManager($matter)
        ->callAction(TestAction::make('publish')->table($document), data: [
            'client_can_view' => true,
            'client_can_download' => true,
        ])
        ->assertHasNoActionErrors();

    expect($document->refresh()->status)->toBe(DocumentStatus::Published);
});

// ---------------------------------------------------------------------------------------------
// Tải tệp — SPEC §10.4, và tiền đề "chỉ một đường duy nhất tới tệp".
// ---------------------------------------------------------------------------------------------

/**
 * Nút tải trỏ vào route đã KÝ của Task 5, không vào một URL của disk. Khẳng định ba việc: URL
 * thuộc route `documents.download`, nó mang chữ ký, và nó thật sự tải được tệp về — tức chữ ký
 * được ký cho đúng người đang đăng nhập.
 */
it('points the download button at the signed route and actually serves the file through it', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $document = documentWithFile($matter, DocumentGroup::Authority);

    $this->actingAs($lawyer, 'web');

    // Thời gian đóng băng: URL đã ký mang cả hạn hết hiệu lực, nên hai lần sinh cách nhau đúng
    // một ranh giới giây sẽ cho hai chuỗi khác nhau và test này hỏng ngẫu nhiên.
    $this->freezeTime();

    $url = $document->downloadUrlFor($lawyer);

    documentsManager($matter)->assertActionHasUrl(TestAction::make('download')->table($document), $url);

    expect($url)->toContain('/documents/'.$document->getKey().'/download')
        ->toContain('signature=');

    $this->actingAs($lawyer, 'web')->get($url)->assertOk();
});

/** Một tài liệu chưa có tệp không được mời tải: route trả 404 cho nó. */
it('hides the download button on a document with no file', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $withoutFile = Document::factory()->for($matter)->group(DocumentGroup::Authority)->create();
    $withFile = documentWithFile($matter, DocumentGroup::Authority);

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)
        ->assertActionHidden(TestAction::make('download')->table($withoutFile))
        ->assertActionVisible(TestAction::make('download')->table($withFile));
});

/**
 * **Tiền đề của cả milestone, kiểm ở đúng lớp này.** Ô chọn tệp phải KHÔNG bao giờ sinh một URL
 * trên đĩa `private`. `FileUpload` với `storeFiles(false)` giữ trạng thái là một
 * `TemporaryUploadedFile`, và `BaseFileUpload::getUploadedFiles()` trả `null` cho mọi
 * `TemporaryUploadedFile` — nó không chạm tới `getDisk()`. Khẳng định bằng CẤU HÌNH thật của
 * component (không phải bằng một bình luận): đĩa của ô không phải `private`, và nó không tự lưu
 * tệp đi đâu cả.
 */
it('never lets the upload field touch the private disk', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $component = documentsManager($matter)
        ->mountAction(TestAction::make('upload')->table())
        ->instance();

    $file = $component
        ->getSchema($component->getMountedActionSchemaName())
        ->getFlatFields(withHidden: true)['file'];

    expect($file)->toBeInstanceOf(FileUpload::class)
        ->and($file->shouldStoreFiles())->toBeFalse()
        ->and($file->getDiskName())->not->toBe('private');
});
// ---------------------------------------------------------------------------------------------
// Ba nhánh mà mutation probe chỉ ra là chưa có test, cộng cổng trạng thái mới của Action.
// ---------------------------------------------------------------------------------------------

/**
 * **Một đầu mục vừa bị xoá khỏi danh mục KHÔNG được lặng lẽ thành "không gắn vào đâu cả".**
 * `MatterChecklistItem` dùng `SoftDeletes`, nên `find()` trả `null` cho một đầu mục đã xoá — và
 * đường tới đây là một trang mở từ trước, tình huống thật. Nếu màn hình nuốt cái `null` đó thì
 * tài liệu vẫn được lưu, nằm ngoài danh mục, và người dùng nhận một thông báo THÀNH CÔNG cho
 * một việc họ không yêu cầu.
 *
 * Gọi thẳng qua `setActionData()` có chủ đích: `Select::options()` tự cài một luật `in:` dựng từ
 * danh sách tuỳ chọn, nên qua đường form bình thường id đó bị chặn ở bước xác thực và không bao
 * giờ tới được nhánh này — đây là lớp phòng thủ thứ hai, và nó chỉ có giá trị nếu có test đỏ
 * được khi nó biến mất (cùng thành ngữ với test id khách hàng giả mạo ở `ViewMatterTest`).
 */
it('refuses an upload pointed at a checklist item that has been deleted from the matter', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $item = MatterChecklistItem::factory()->for($matter)->create();
    $item->delete();

    $this->actingAs($lawyer, 'web');

    expect(DocumentsRelationManager::checklistItemOptions($matter))->not->toHaveKey($item->id);

    // Gọi THẬNG lớp phòng thủ thứ hai. Đi qua biểu mẫu thì luật `in:` mà `Select::options()` tự
    // cài đã chặn id này từ trước, nên test sẽ xanh kể cả khi nhánh dưới đây bị xoá sạch —
    // đo được bằng một mutation probe. Cùng thành ngữ với test id khách hàng giả mạo ở
    // `ViewMatterTest`, và cùng lý do: một lớp phòng thủ chỉ có giá trị nếu có test đỏ được khi
    // nó biến mất.
    $manager = documentsManager($matter)->instance();

    $resolve = Closure::bind(
        fn (mixed $id) => $this->resolveChecklistItem($id),
        $manager,
        DocumentsRelationManager::class,
    );

    expect(fn () => $resolve($item->id))
        ->toThrow(ValidationException::class, __('documents.upload.checklist_item_deleted'));

    // Và cặp sinh đôi dương: một đầu mục còn trong danh mục đi qua được, nên nhánh trên không
    // phải "từ chối tất cả".
    $alive = MatterChecklistItem::factory()->for($matter)->create();

    expect($resolve($alive->id)?->getKey())->toBe($alive->getKey())
        ->and($resolve(null))->toBeNull()
        ->and($matter->documents()->count())->toBe(0);
});

/**
 * Cổng trạng thái mới của `UploadStaffDocument` (vòng sửa Task 4): nộp thay ở nhóm A vào một đầu
 * mục ĐANG CHỜ DUYỆT bị từ chối, vì `settleChecklistItem()` sẽ ghi `accepted` kèm tên người vừa
 * bấm nút tải lên — khai một lần duyệt mà không ai mở tệp của khách ra xem.
 *
 * Nó là một `DomainException`, nên nếu màn hình không bắt thì nó là lỗi 500. Test khẳng định CẢ
 * HAI nửa trên một lần đọc thông báo duy nhất: câu tiếng Việt có mặt, VÀ câu "đã lưu xong" không
 * có mặt — nửa thứ hai là thứ `halt()` giữ, và không có nó thì người dùng đọc hai dòng nói
 * ngược nhau cạnh nhau.
 */
it('turns the pending-review refusal of a group A staff upload into a Vietnamese notification', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $item = MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::PendingReview)->create();

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)->callAction(TestAction::make('upload')->table(), data: [
        'file' => validPdf(),
        'title' => 'Bản sao hộ khẩu nộp thay khách',
        'group' => DocumentGroup::ClientProvided->value,
        'matter_checklist_item_id' => $item->id,
    ]);

    $titles = sentNotificationTitles();

    expect($titles)->toContain(__('actions.failed_title'))
        ->and($titles)->not->toContain(__('documents.tab.actions.upload_success'))
        ->and($matter->documents()->count())->toBe(0)
        ->and($item->refresh()->status)->toBe(ChecklistItemStatus::PendingReview);
});

/**
 * Cặp sinh đôi dương của test trên: cùng người, cùng nhóm A, cùng đầu mục — chỉ khác là không có
 * tệp nào của khách đang chờ. Không có nó, một cài đặt từ chối MỌI lần nộp thay nhóm A cũng xanh.
 */
it('lets the same group A staff upload through once nothing is waiting on the item', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $item = MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::Missing)->create();

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)
        ->callAction(TestAction::make('upload')->table(), data: [
            'file' => validPdf(),
            'title' => 'Bản sao hộ khẩu nộp thay khách',
            'group' => DocumentGroup::ClientProvided->value,
            'matter_checklist_item_id' => $item->id,
        ])
        ->assertHasNoActionErrors();

    expect($matter->documents()->count())->toBe(1)
        ->and($item->refresh()->status)->toBe(ChecklistItemStatus::Accepted);
});

/**
 * `halt()` sau một lời từ chối của `PublishDocument`: thông báo "Đã công bố tài liệu cho khách"
 * KHÔNG được gửi. Không có nó, một lần công bố bị từ chối hiện ra một dòng đỏ và một dòng xanh
 * cạnh nhau, và người dùng tin dòng xanh.
 */
it('does not claim success when a publication is refused', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $document = documentWithFile($matter, DocumentGroup::Issued, ['status' => DocumentStatus::InternalDraft]);

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)->callAction(TestAction::make('publish')->table($document), data: [
        'client_can_view' => true,
        'client_can_download' => true,
    ]);

    $titles = sentNotificationTitles();

    expect($titles)->toContain(__('actions.failed_title'))
        ->and($titles)->not->toContain(__('documents.tab.actions.publish_success'))
        ->and($document->refresh()->status)->toBe(DocumentStatus::InternalDraft);
});
