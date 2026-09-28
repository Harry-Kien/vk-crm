<?php

use App\Actions\Document\PublishDocument;
use App\Actions\Document\RegroupDocument;
use App\Actions\Document\UploadStaffDocument;
use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Filament\Portal\Pages\MatterProgress;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Support\Files\VirusScanner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Mime\MimeTypes;

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
 * Tiêu đề VÀ THÂN của MỌI thông báo đã gửi, đọc đúng MỘT lần.
 *
 * `Notification::assertNotified()` và `::assertNotNotified()` đều `mount()` một component
 * `Notifications` mới, và việc đó kéo thông báo RA KHỎ session — đọc một lần là MẤT. Gọi
 * liên tiếp hai hàm đó trong cùng một `it()` vì vậy làm hàm thứ hai nhìn vào một danh sách
 * rỗng và xanh mà không kiểm tra gì (bài học đã ghi ở `ViewMatterTest`). Test nào cần khẳng
 * định cả "có câu này" lẫn "KHÔNG có câu kia" phải đọc một lần rồi so trên chính danh sách đó.
 *
 * @return array<int, array{title: string|null, body: string}>
 */
function sentNotifications(): array
{
    $component = new Notifications;
    $component->mount();

    return $component->notifications
        ->map(fn (Notification $notification): array => [
            'title' => $notification->getTitle(),
            'body' => (string) $notification->getBody(),
        ])
        ->all();
}

/**
 * Tiêu đề của mọi thông báo đã gửi. Đi qua {@see sentNotifications()} nên vẫn chỉ MỘT lần rút.
 *
 * @return array<int, string|null>
 */
function sentNotificationTitles(): array
{
    return array_column(sentNotifications(), 'title');
}

/**
 * Hai bản thế chỗ của `PublishDocument` và `RegroupDocument` ném đúng họ exception mà `Gate`
 * ném. Chúng KẾ THỪA Action thật, nên chữ ký `handle()` không trôi đi lặng lẽ khi Action đổi:
 * một tham số mới ở lớp cha làm hai lớp này thành lỗi PHP ngay lần chạy đầu.
 *
 * Vì sao phải tiêm thay vì dựng một người dùng thiếu quyền: xem docblock của test dùng chúng.
 */
class RefusingPublishDocument extends PublishDocument
{
    public function handle(
        Document $document,
        User $actor,
        bool $clientCanView,
        bool $clientCanDownload,
        bool $expectedClientCanView,
        bool $expectedClientCanDownload,
        bool $expectedIsReleased,
    ): Document {
        throw new AuthorizationException;
    }
}

class RefusingRegroupDocument extends RegroupDocument
{
    public function handle(Document $document, User $actor, DocumentGroup $group, ?string $reason = null): Document
    {
        throw new AuthorizationException;
    }
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

    expect($style)->toStartWith('<style>')->toContain('background-color')
        // `--danger-500` phải là một sắc độ `FilamentColor` thật sự đăng ký, nếu không
        // `color-mix()` nhận một `var()` rỗng và cái nền SPEC §7.2 đòi lại không hiện ra — đúng
        // hạng lỗi mà `bg-gray-100` đã gây ra ở M3.
        ->and(colourVariablesIn($style))->toContain('danger-500')
        ->and(unregisteredColourVariables($style))->toBe([]);

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
 * **Ghim cho danh sách "nhóm ra tới khách ngay lúc tạo".** Danh sách đó KHÔNG còn là một bản chép
 * tay trong màn hình: `DocumentsRelationManager::releasedAtCreation()` hỏi thẳng
 * `StoresDocumentFile::groupsReleasedToClientAtCreation()`, thứ suy ra từ chính bảng SPEC §4.11.
 * Test này vẫn đo chứ không tin: với một TRỢ LÝ, chạy thật `UploadStaffDocument` cho cả bốn nhóm
 * và khẳng định rằng đúng những nhóm mà ô chọn KHÔNG mời là những nhóm Action từ chối.
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

/**
 * **Sửa lại ở vòng sửa 2 (Minor 4).** Tên và điều kiện cũ ("never offers D to someone without
 * document.viewInternal") không còn đúng: phán quyết (a) mở rộng nói D được mời cho bất kỳ ai có
 * `document.update`, BẤT KỂ nguồn — bản vòng sửa 1 chỉ áp dụng ngoại lệ đó cho nguồn B.
 * `regroupOptions()` giờ hỏi `document.update`, không còn hỏi "nguồn có phải B không", nên phép
 * đo đúng cho lằn ranh CÒN LẠI phải chọn một actor thiếu CẢ HAI quyền (`document.update` VÀ
 * `document.viewInternal`) — một trợ lý NGOÀI đội ngũ (không có `document.update` trên vụ việc
 * này), không phải một trợ lý trong đội ngũ như bản cũ (thứ giờ ĐÚNG là phải thấy D, xem test
 * riêng ngay bên dưới).
 */
it('never offers group D to someone without document.update or document.viewInternal', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $document = Document::factory()->for($matter)->group(DocumentGroup::Authority)->create();

    // Trợ lý NGOÀI đội ngũ: không `document.update` trên vụ việc này (chưa từng thêm vào), và
    // không vai trò Assistant nào có `document.viewInternal` — thiếu cả hai lối vào D.
    $outsiderAssistant = User::factory()->withRole(Role::Assistant)->create();

    $this->actingAs($outsiderAssistant, 'web');

    expect(array_keys(DocumentsRelationManager::groupOptions($matter)))->not->toContain('D')
        ->and(array_keys(DocumentsRelationManager::regroupOptions($document)))->not->toContain('D');

    $this->actingAs($lawyer, 'web');

    expect(array_keys(DocumentsRelationManager::regroupOptions($document)))->toContain('D');
});

/**
 * Cặp bổ sung (vòng sửa 2, Minor 4): một trợ lý TRONG đội ngũ — có `document.update` trên chính
 * vụ việc này — giờ thấy được nhóm D dù không có `document.viewInternal`, BẤT KỂ nguồn (ở đây là
 * nhóm C, không phải B, đúng phạm vi mà bản vòng sửa 1 còn bỏ sót). Mutation probe cho điều kiện
 * này nằm ở test màn hình đầy đủ ("trợ lý rút được một tài liệu nhóm A...") phía trên.
 */
it('trợ lý trong đội ngũ (có document.update) vẫn thấy nhóm D dù không có document.viewInternal, bất kể nguồn', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $document = Document::factory()->for($matter)->group(DocumentGroup::Authority)->create();

    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($assistant, 'web');

    expect(array_keys(DocumentsRelationManager::regroupOptions($document)))->toContain('D');
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
 * `docs/docs-4`: mở lại hộp "Công bố cho khách" của một tài liệu ĐÃ công bố "chỉ xem, không tải"
 * phải hiện ĐÚNG hai cờ hiện có trên bản ghi, không phải bộ mặc định "bật cả hai" cũ. Bấm xác
 * nhận trên một form gợi ý sai sẽ lặng lẽ MỞ LẠI quyền tải mà không ai chủ ý — đúng hậu quả
 * `docs/docs-4` ghi lại.
 */
it('reopens the publish dialog of a view-only document with "cho tải" already off', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $document = documentWithFile($matter, DocumentGroup::Authority, [
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => false,
    ]);

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)
        ->mountAction(TestAction::make('publish')->table($document))
        ->assertActionDataSet([
            'client_can_view' => true,
            'client_can_download' => false,
        ]);
});

/**
 * Cặp sinh đôi của test trên: một tài liệu CHƯA từng ra tới khách vẫn gợi ý bộ mặc định tiện lợi
 * cũ (cả hai cờ bật) — `PublishDocument` từ chối công bố với `client_can_view` tắt, nên gợi ý
 * tắt sẵn cho một lần công bố ĐẦU TIÊN không phải một thao tác, nó là một lời từ chối đã biết
 * trước. Không có test này, một cài đặt luôn đọc từ bản ghi (bỏ nhánh `isReleasedToPortal()`)
 * cũng làm test trên xanh trong khi bắt mọi lần công bố đầu tiên phải tự bật lại cả hai ô.
 */
it('still offers the convenient defaults for a document that has never been released before', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $document = documentWithFile($matter, DocumentGroup::Authority);

    expect($document->isReleasedToPortal())->toBeFalse();

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)
        ->mountAction(TestAction::make('publish')->table($document))
        ->assertActionDataSet([
            'client_can_view' => true,
            'client_can_download' => true,
        ]);
});

/**
 * "Hai tab" qua Livewire (vòng sửa 1, "Also fix — Stale publish form"). Tab 1 mở hộp thoại công
 * bố của một tài liệu ĐÃ công bố (view=true/download=true) — `fillForm()` chụp đúng hai cờ đó
 * vào các ô ẩn `mounted_*`. Trong lúc tab 1 còn mở, tài liệu được công bố lại (mô phỏng tab 2)
 * với download=false. Tab 1 xác nhận KHÔNG SỬA GÌ (gửi lại đúng dữ liệu nó đã thấy lúc mount) —
 * phải bị từ chối, và cờ tải phải GIỮ NGUYÊN false (không bị tab 1 ghi đè ngược lại).
 */
it('hai tab công bố cùng lúc: tab thứ hai (đã lỗi thời) bị từ chối qua màn hình, và quyền tải giữ nguyên đã tắt', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $document = documentWithFile($matter, DocumentGroup::Authority, [
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
    ]);

    $this->actingAs($lawyer, 'web');

    // "Tab 1": mở hộp thoại, đọc đúng ảnh chụp lúc mount qua chính dữ liệu đã điền sẵn.
    $tab1 = documentsManager($matter)->mountAction(TestAction::make('publish')->table($document));
    $tab1->assertActionDataSet([
        'client_can_view' => true,
        'client_can_download' => true,
        'mounted_client_can_view' => true,
        'mounted_client_can_download' => true,
        'mounted_is_released' => true,
    ]);

    // "Tab 2": công bố lại trước, tắt quyền tải — một request/Livewire component khác.
    documentsManager($matter)
        ->callAction(TestAction::make('publish')->table($document), data: [
            'client_can_view' => true,
            'client_can_download' => false,
        ])
        ->assertHasNoActionErrors();

    expect($document->fresh()->client_can_download)->toBeFalse();

    // "Tab 1" xác nhận với đúng dữ liệu nó đã mount (không đổi gì) — phải bị từ chối.
    $tab1->callMountedAction();

    expect(sentNotificationTitles())->toContain(__('actions.failed_title'))
        ->and($document->fresh()->client_can_download)->toBeFalse();
});

/**
 * **Vòng đầy đủ qua Livewire — Task 16, sửa `docs/docs-1` (critical).** Trước Task 16 không có
 * Action, nút hay ô nào ghi được `pending_approval`/`signed_filed`: mọi tài liệu nhóm B mãi kẹt ở
 * `internal_draft`, và mọi test phủ nhánh "công bố được sau khi signed_filed" dựng trạng thái
 * bằng `forceFill()`/factory — đường KHÔNG tồn tại ngoài đời — nên cả bộ test vẫn xanh dù nút
 * "Công bố cho khách" không có đường nào tới. Test này đi đúng con đường một luật sư thật đi,
 * từng bước, không gọi thẳng Action nào: tải tệp lên qua nút "Đưa tài liệu vào hồ sơ" → "Trình
 * duyệt" → "Đánh dấu đã ký, đã nộp" → "Công bố cho khách" → rồi đọc lại dưới guard `client` để
 * xác nhận khách thật sự thấy được.
 */
it('walks a group B document through Livewire from internal_draft to published, and the client sees it', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $clientUser = ClientUser::factory()->activated()->create(['client_id' => $client->id]);
    $matter = Matter::factory()->for($client)->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)
        ->callAction(TestAction::make('upload')->table(), data: [
            'file' => validPdf('don-khoi-kien.pdf'),
            'title' => 'Đơn khởi kiện',
            'group' => DocumentGroup::Issued->value,
        ])
        ->assertHasNoActionErrors();

    $document = $matter->documents()->firstOrFail();

    expect($document->status)->toBe(DocumentStatus::InternalDraft);

    // "Khách CHƯA thấy" — cặp sinh đôi ÂM của khẳng định cuối test, đo TRƯỚC khi công bố. Log
    // out guard `web` trước khi vào guard `client`: hai panel dùng chung cookie phiên
    // (`ClientPortalScope::isActive()`), nên nếu KHÔNG log out, guard `web` của luật sư vẫn còn
    // xác thực và `isActive()` trả `false` ở NGOÀI ngữ cảnh panel portal — vòng sửa 1 sửa đúng
    // lỗi này: một bản trước gọi `Document::find()` trong khi guard `web` vẫn mở, nên phép đo
    // "khách thấy" không hề chạm tới global scope portal thật, và sẽ xanh dù chưa công bố gì.
    auth('web')->logout();
    $this->actingAs($clientUser, 'client')
        ->get(MatterProgress::getUrl(['record' => $matter->getKey()], panel: 'portal'))
        ->assertOk()
        ->assertDontSee('Đơn khởi kiện');
    auth('client')->logout();

    // Request portal vừa rồi đổi panel HIỆN TẠI của Filament sang `portal` — đặt lại `admin`
    // trước khi tiếp tục gọi `documentsManager()` (Livewire component của panel admin), nếu
    // không `Filament::getCurrentPanel()` trỏ sai panel và component không mount được.
    Filament::setCurrentPanel('admin');
    $this->actingAs($lawyer, 'web');

    documentsManager($matter)
        ->callAction(TestAction::make('submitForApproval')->table($document))
        ->assertHasNoActionErrors();

    expect($document->refresh()->status)->toBe(DocumentStatus::PendingApproval);

    documentsManager($matter)
        ->callAction(TestAction::make('markSignedFiled')->table($document))
        ->assertHasNoActionErrors();

    expect($document->refresh()->status)->toBe(DocumentStatus::SignedFiled);

    documentsManager($matter)
        ->callAction(TestAction::make('publish')->table($document), data: [
            'client_can_view' => true,
            'client_can_download' => true,
        ])
        ->assertHasNoActionErrors();

    expect($document->refresh()->status)->toBe(DocumentStatus::Published);

    // "Khách thấy" — ĐI QUA HTTP thật, đúng trang portal (`MatterProgress`) và đúng route tải
    // có chữ ký, dưới guard `client` THẬT SỰ (guard `web` đã log out ở trên, và log out lại ở
    // đây phòng một request khác trong cùng test rơi lại vào nhánh "nhân sự thắng"). Đây là
    // phép đo THẬT: nếu `ClientPortalScope` hay `DocumentPolicy` không mở đúng, request này
    // trả 404/không thấy tiêu đề, không phải một `Gate::allows()` gọi trong tiến trình PHP.
    auth('web')->logout();
    $this->actingAs($clientUser, 'client');

    $portalHtml = $this->get(MatterProgress::getUrl(['record' => $matter->getKey()], panel: 'portal'))
        ->assertOk()
        ->getContent();

    expect($portalHtml)->toContain('Đơn khởi kiện');

    $this->get($document->downloadUrlFor($clientUser))->assertOk();
});

/**
 * SPEC §5: trợ lý không có `document.publish`. Cặp sinh đôi dương ngay trong cùng test: cùng
 * người, "Trình duyệt" (đòi `document.update`) VẪN hiện và bấm được — ranh giới nằm đúng giữa
 * hai bước, không phải trước cả hai.
 */
it('hides "Đánh dấu đã ký, đã nộp" from an assistant while letting them submit the same draft for approval', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $document = documentWithFile($matter, DocumentGroup::Issued, ['status' => DocumentStatus::InternalDraft]);

    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($assistant, 'web');

    documentsManager($matter)
        ->assertActionVisible(TestAction::make('submitForApproval')->table($document))
        ->assertActionHidden(TestAction::make('markSignedFiled')->table($document))
        ->callAction(TestAction::make('submitForApproval')->table($document))
        ->assertHasNoActionErrors();

    expect($document->refresh()->status)->toBe(DocumentStatus::PendingApproval);

    // Nút vẫn ẩn sau khi tài liệu đã sang `pending_approval` — trợ lý không có `document.publish`
    // ở BẤT KỲ trạng thái nào của tài liệu. `MarkDocumentSignedFiledTest` ghim thêm rằng gọi
    // THẲNG Action (bỏ qua màn hình) cũng bị từ chối, cùng lý do các test Gate khác của tệp này
    // (`RefusingPublishDocument`/`RefusingRegroupDocument`) không gọi được action ẩn qua Livewire:
    // Filament không cho một action `isAuthorized() === false` chạy tới `action()` — nó dừng ở
    // `isDisabled()`/`isHidden()`, IM LẶNG, trước khi kịp mở modal hay gửi bất kỳ thông báo nào.
    documentsManager($matter)->assertActionHidden(TestAction::make('markSignedFiled')->table($document));

    expect($document->refresh()->status)->toBe(DocumentStatus::PendingApproval);

    // Và cặp sinh đôi dương: luật sư có `document.publish` thì thấy nút VÀ bấm được, trên cùng
    // dòng, cùng tài liệu.
    $this->actingAs($lawyer, 'web');

    documentsManager($matter)
        ->assertActionVisible(TestAction::make('markSignedFiled')->table($document))
        ->callAction(TestAction::make('markSignedFiled')->table($document))
        ->assertHasNoActionErrors();

    expect($document->refresh()->status)->toBe(DocumentStatus::SignedFiled);
});

/**
 * `docs/docs-2`, phán quyết R9: chuyển nhóm RA KHỎI B trước khi nó `signed_filed` là đúng đường
 * "giặt" một bản nháp đơn thành nhóm C rồi công bố thẳng — và trước Task 16, một trợ lý làm được
 * bước chuyển đó. Test này khẳng định NGAY CẢ một luật sư có đủ `document.publish` cũng không đi
 * vòng được, và tài liệu ở nguyên nhóm B.
 *
 * **Đổi kiểu lỗi ở vòng sửa 1 (R9 mở rộng).** Trước vòng sửa 1, cổng B→C/A không có đường lý do
 * nên MỌI lần gọi thiếu điều kiện đều rơi xuống `DomainException` của Action → một `Notification`
 * trôi nổi. Nay ô "Lý do chuyển nhóm" tự bật `->required()` đúng tình huống này (xem
 * `regroupReasonNeeded()`), nên một lần gọi không kèm `reason` bị CHÍNH FORM chặn trước khi tới
 * Action — một lỗi gắn vào ô `reason`, không còn là thông báo trôi nổi nữa. Cặp test đầy đủ hơn
 * (kèm/không kèm lý do) nằm ngay bên dưới; test này giữ lại vì nó ghim thêm rằng không tài liệu
 * nào lọt được sang nhóm C dù có `document.publish`.
 */
it('refuses to regroup a group B document out of B while it is still internal_draft, even for a lawyer', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $document = documentWithFile($matter, DocumentGroup::Issued, ['status' => DocumentStatus::InternalDraft]);

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)
        ->callAction(TestAction::make('regroup')->table($document), data: [
            'group' => DocumentGroup::Authority->value,
        ])
        ->assertHasActionErrors(['reason']);

    expect($document->refresh()->group)->toBe(DocumentGroup::Issued);

    // Đường công bố vòng qua (docs-2 mô tả): nếu cổng trên bị gỡ, bước này sẽ thành công và tài
    // liệu (giờ mang nhãn nhóm C) sẽ công bố thẳng được từ một bản nháp chưa ai ký — chính hậu
    // quả mà cổng mới ngăn.
    expect($matter->documents()->where('group', DocumentGroup::Authority)->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------
// I1 (vòng sửa 1): màn hình "Chuyển nhóm" phải phản ánh đúng ba cổng của Action — bốn kịch bản
// finding I1 nêu đích danh, đi qua ĐÚNG màn hình (`callAction('regroup', ...)`), không gọi thẳng
// `RegroupDocument` (đã có test riêng, chi tiết hơn, ở `RegroupDocumentTest`).
// ---------------------------------------------------------------------------------------------

/**
 * **"Biến mất khỏi cổng khách" đo bằng HTTP thật — vòng sửa 2, mục nhỏ.** Bản vòng sửa 1 chỉ gọi
 * `isReleasedToPortal()` sau khi chuyển nhóm — hàm đó đọc lại đúng những cột mà CHÍNH test này vừa
 * ghi, không đi qua `ClientPortalScope`/`DocumentPolicy`/route tải có chữ ký nào cả, nên nó không
 * đo được gì hơn "cột đã đổi giá trị". Test này lấy đường dẫn tải có chữ ký TRƯỚC khi thu hồi
 * (đúng thứ một khách đã mở trang trước đó có thể còn giữ), rồi đo lại CẢ trang portal LẪN đường
 * dẫn đó SAU khi thu hồi, dưới guard `client` thật — cùng kỹ thuật với walk test S2.
 */
it('trợ lý chuyển được một tài liệu nhóm B ĐÃ CÔNG BỐ vào nhóm D qua màn hình, không cần document.publish, và nó biến mất khỏi cổng khách', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $clientUser = ClientUser::factory()->activated()->create(['client_id' => $client->id]);
    $matter = Matter::factory()->for($client)->create(['lead_lawyer_id' => $lawyer->id]);
    $document = documentWithFile($matter, DocumentGroup::Issued, [
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
    ]);

    // Khách thấy TRƯỚC khi thu hồi — cả trang portal lẫn đường dẫn tải có chữ ký, đo qua HTTP
    // thật dưới guard `client`. Giữ lại đường dẫn ký: nó phải hết tác dụng SAU khi thu hồi, dù
    // chữ ký (còn hạn 5 phút) vẫn hợp lệ — cổng phải nằm ở policy, không phải ở chữ ký.
    $this->actingAs($clientUser, 'client');
    $this->get(MatterProgress::getUrl(['record' => $matter->getKey()], panel: 'portal'))
        ->assertOk()
        ->assertSee($document->title);
    $signedDownloadUrl = $document->downloadUrlFor($clientUser);
    $this->get($signedDownloadUrl)->assertOk();
    auth('client')->logout();

    // Request portal vừa rồi đổi panel HIỆN TẠI sang `portal` — đặt lại `admin` trước khi gọi
    // `documentsManager()` (Livewire component của panel admin), cùng lý do với walk test S2.
    Filament::setCurrentPanel('admin');

    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($assistant, 'web');

    // Ô chọn nhóm chỉ được offer B (giữ nguyên) và D — không phải A/C, thứ Action luôn từ chối
    // trợ lý — xem docblock `regroupOptions()`.
    expect(array_keys(DocumentsRelationManager::regroupOptions($document)))->toBe(['B', 'D']);

    documentsManager($matter)
        ->callAction(TestAction::make('regroup')->table($document), data: [
            'group' => DocumentGroup::Internal->value,
        ])
        ->assertHasNoActionErrors();

    expect($document->refresh()->group)->toBe(DocumentGroup::Internal)
        ->and($document->isReleasedToPortal())->toBeFalse();

    // Và khách THẬT SỰ không còn thấy nó — cùng trang, cùng đường dẫn ký, đo lại SAU khi thu hồi.
    auth('web')->logout();
    $this->actingAs($clientUser, 'client');
    $this->get(MatterProgress::getUrl(['record' => $matter->getKey()], panel: 'portal'))
        ->assertOk()
        ->assertDontSee($document->title);
    $this->get($signedDownloadUrl)->assertNotFound();
});

/**
 * Vòng sửa 2, mục nhỏ: phán quyết (a) nói "vào D luôn được phép với `document.update`, BẤT KỂ
 * nhóm NGUỒN" — nhưng `regroupOptions()` (vòng sửa 1) chỉ mời D cho ai không có
 * `document.viewInternal` khi nguồn LÀ B. Một tài liệu nhóm A (`ClientProvided`) công bố nhầm
 * cũng cần rút được, và một trợ lý cũng phải làm được việc đó — cùng lý lẽ với nhóm B.
 */
it('trợ lý rút được một tài liệu nhóm A ĐÃ CÔNG BỐ vào nhóm D qua màn hình, không cần document.viewInternal', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $document = documentWithFile($matter, DocumentGroup::ClientProvided, [
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
    ]);

    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($assistant, 'web');

    expect(array_keys(DocumentsRelationManager::regroupOptions($document)))->toContain('D');

    documentsManager($matter)
        ->callAction(TestAction::make('regroup')->table($document), data: [
            'group' => DocumentGroup::Internal->value,
        ])
        ->assertHasNoActionErrors();

    expect($document->refresh()->group)->toBe(DocumentGroup::Internal)
        ->and($document->isReleasedToPortal())->toBeFalse();
});

it('luật sư chuyển một bản nháp B chưa ký sang C mà KHÔNG nhập lý do thì bị từ chối qua màn hình', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $document = documentWithFile($matter, DocumentGroup::Issued, ['status' => DocumentStatus::InternalDraft]);

    $this->actingAs($lawyer, 'web');

    // Ô "Lý do chuyển nhóm" tự đặt `->required()` khi tình huống này cần nó (xem
    // `regroupReasonNeeded()`), nên một lần gửi lý do rỗng bị CHÍNH form chặn — một lỗi gắn vào
    // ô, không phải một thông báo trôi nổi. Đây là lớp phòng thủ ĐẦU (UX); lớp phòng thủ THỨ HAI
    // (Action tự kiểm tra lại) được ghim riêng ở `RegroupDocumentTest`, gọi thẳng Action với một
    // lý do rỗng để không bị lớp UX này che mất.
    documentsManager($matter)
        ->callAction(TestAction::make('regroup')->table($document), data: [
            'group' => DocumentGroup::Authority->value,
            'reason' => '',
        ])
        ->assertHasActionErrors(['reason']);

    expect($document->refresh()->group)->toBe(DocumentGroup::Issued);
});

/** Cặp dương của test trên: cùng tài liệu, cùng người, kèm một lý do hợp lệ thì thành công. */
it('luật sư chuyển một bản nháp B chưa ký sang C kèm lý do hợp lệ thì thành công, và lý do được ghi vào nhật ký', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $document = documentWithFile($matter, DocumentGroup::Issued, ['status' => DocumentStatus::InternalDraft]);

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)
        ->callAction(TestAction::make('regroup')->table($document), data: [
            'group' => DocumentGroup::Authority->value,
            'reason' => 'Nộp nhầm nhóm — đây là bản ghi chú nội bộ, không phải văn bản phát hành.',
        ])
        ->assertHasNoActionErrors();

    expect($document->refresh()->group)->toBe(DocumentGroup::Authority);

    $activity = Activity::query()->where('event', 'document_regrouped')->latest('id')->first();

    expect($activity->properties->get('misfiling_reason'))
        ->toBe('Nộp nhầm nhóm — đây là bản ghi chú nội bộ, không phải văn bản phát hành.');
});

it('trợ lý không chuyển được một tài liệu B sang C qua màn hình, và ô chọn nhóm còn không mời A/C', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $document = documentWithFile($matter, DocumentGroup::Issued, ['status' => DocumentStatus::SignedFiled]);

    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($assistant, 'web');

    // Cái bẫy `I1` mô tả: trước vòng sửa 1, ô này mời cả A và C cho một trợ lý đứng trên một
    // dòng nhóm B — hai lựa chọn Action luôn từ chối họ.
    expect(array_keys(DocumentsRelationManager::regroupOptions($document)))
        ->not->toContain('A')->not->toContain('C');

    // `setActionData()` VẪN đi qua luật `in:` mà chính ô chọn tự cài từ `regroupOptions()` (khác
    // `binds a checklist item from another matter` phía trên, nơi ô đó KHÔNG có luật `in:` nào —
    // `matter_checklist_item_id` chỉ giới hạn ĐỘNG DANH SÁCH, không tự sinh luật xác thực). Nên
    // 'C' bị chặn ngay ở CHÍNH Ô — bằng chứng trực tiếp rằng việc lọc `regroupOptions()` không chỉ
    // là một gợi ý giao diện, nó là một luật `in:` thật ở tầng máy chủ.
    documentsManager($matter)
        ->mountAction(TestAction::make('regroup')->table($document))
        ->setActionData(['group' => DocumentGroup::Authority->value])
        ->callMountedAction()
        ->assertHasActionErrors(['group']);

    expect($document->refresh()->group)->toBe(DocumentGroup::Issued);
});

/**
 * Hai nút mới chỉ hiện đúng MỘT ô của bảng nhóm×trạng thái: "Trình duyệt" chỉ ở B/`internal_
 * draft`, "Đánh dấu đã ký, đã nộp" chỉ ở B/`pending_approval`. Bốn dòng dữ liệu, một luật sư có
 * đủ mọi quyền — nên bất kỳ ô nào hiện sai đều lộ ra ở đây, không lẫn được với một lời từ chối
 * quyền (đã ghim riêng ở `MarkDocumentSignedFiledTest`/`SubmitDocumentForApprovalTest`).
 */
it('offers "Trình duyệt" and "Đánh dấu đã ký, đã nộp" on exactly the group/status cell each belongs to', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $draft = documentWithFile($matter, DocumentGroup::Issued, ['status' => DocumentStatus::InternalDraft]);
    $pending = documentWithFile($matter, DocumentGroup::Issued, ['status' => DocumentStatus::PendingApproval]);
    $signed = documentWithFile($matter, DocumentGroup::Issued, ['status' => DocumentStatus::SignedFiled]);
    $authorityDraft = documentWithFile($matter, DocumentGroup::Authority, ['status' => DocumentStatus::InternalDraft]);

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)
        ->assertActionVisible(TestAction::make('submitForApproval')->table($draft))
        ->assertActionHidden(TestAction::make('submitForApproval')->table($pending))
        ->assertActionHidden(TestAction::make('submitForApproval')->table($signed))
        ->assertActionHidden(TestAction::make('submitForApproval')->table($authorityDraft))
        ->assertActionHidden(TestAction::make('markSignedFiled')->table($draft))
        ->assertActionVisible(TestAction::make('markSignedFiled')->table($pending))
        ->assertActionHidden(TestAction::make('markSignedFiled')->table($signed))
        ->assertActionHidden(TestAction::make('markSignedFiled')->table($authorityDraft))
        // "Trả về bản nháp" (vòng sửa 1, ruling): cùng ô nhóm×trạng thái với "Đánh dấu đã ký, đã
        // nộp" (B/`pending_approval`), vì đó chính là bước nó đi NGƯỢC lại.
        ->assertActionHidden(TestAction::make('returnToDraft')->table($draft))
        ->assertActionVisible(TestAction::make('returnToDraft')->table($pending))
        ->assertActionHidden(TestAction::make('returnToDraft')->table($signed))
        ->assertActionHidden(TestAction::make('returnToDraft')->table($authorityDraft));
});

/** "Trả về bản nháp" qua Livewire (ruling, vòng sửa 1): đi đúng đường màn hình, không gọi Action. */
it('trả một văn bản đang chờ duyệt về bản nháp qua màn hình', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $document = documentWithFile($matter, DocumentGroup::Issued, ['status' => DocumentStatus::PendingApproval]);

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)
        ->callAction(TestAction::make('returnToDraft')->table($document))
        ->assertHasNoActionErrors();

    expect($document->refresh()->status)->toBe(DocumentStatus::InternalDraft);
});

/** Trợ lý có `document.update` (đủ để "Trình duyệt") nhưng không thấy nút "Trả về bản nháp". */
it('ẩn "Trả về bản nháp" khỏi trợ lý dù họ đọc và sửa được tài liệu', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $document = documentWithFile($matter, DocumentGroup::Issued, ['status' => DocumentStatus::PendingApproval]);

    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($assistant, 'web');

    documentsManager($matter)->assertActionHidden(TestAction::make('returnToDraft')->table($document));
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

// ---------------------------------------------------------------------------------------------
// Họ exception thứ tư: `Gate` bên trong Action từ chối (SPEC §8.4, §10.10).
// ---------------------------------------------------------------------------------------------

/**
 * **Đường THẬT, không dựng cảnh.** `UploadStaffDocument` hỏi lại quyền SAU cửa sổ quét virus (tới
 * 30 giây theo `config('vkcrm.clamav.timeout')`), và đây đúng là tình huống lần hỏi lại ấy tồn
 * tại để bắt: hồ sơ bị xoá mềm trong lúc quét. Cổng `->authorize()` của cái nút đã trả lời
 * "được" từ trước — lúc đó hồ sơ còn sống — nên không có gì chặn trước Action.
 *
 * Trước bản sửa này, `AuthorizationException` (kế thừa `\Exception`, không phải `DomainException`)
 * thoát khỏi cả ba nhánh `catch` của `ReportsActionFailures` và đi lên thành 403 mang nguyên văn
 * "This action is unauthorized." trên một request `update` của Livewire — đúng trường hợp mà
 * `AnswerDeniedPanelRequestsWithNotFound` tự ghi là nó không phủ được. Người dùng mất luôn tệp
 * vừa quét xong và không đọc được một chữ tiếng Việt nào.
 *
 * Khẳng định trên THÂN thông báo chứ không chỉ trên tiêu đề: tiêu đề giống nhau ở mọi lời từ
 * chối, nên một test chỉ đọc tiêu đề sẽ xanh cả khi thân vẫn là chuỗi tiếng Anh của framework.
 */
it('turns a re-gate refusal after the virus scan into a Vietnamese notification, not an English 403', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    app()->instance(VirusScanner::class, new class implements VirusScanner
    {
        public function scan(string $path): void
        {
            Matter::query()->delete();
        }

        public function isActive(): bool
        {
            return true;
        }
    });

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)->callAction(TestAction::make('upload')->table(), data: [
        'file' => validPdf(),
        'title' => 'Thông báo thụ lý nộp trong lúc hồ sơ bị xoá',
        'group' => DocumentGroup::Authority->value,
    ]);

    $notifications = sentNotifications();

    expect(array_column($notifications, 'title'))->toContain(__('actions.failed_title'))
        ->and(array_column($notifications, 'body'))->toContain(__('actions.unauthorized'))
        ->and(array_column($notifications, 'title'))->not->toContain(__('documents.tab.actions.upload_success'))
        // Câu tiếng Anh của framework không được xuất hiện ở bất cứ thông báo nào.
        ->and(implode("\n", array_column($notifications, 'body')))->not->toContain('This action is unauthorized')
        ->and(Document::query()->withTrashed()->count())->toBe(0);
});

/**
 * Cặp sinh đôi dương: CÙNG thao tác, cùng người, không có ai phá gì trong lúc quét — nó đi lọt và
 * không có thông báo lỗi nào. Không có test này, một cài đặt từ chối MỌI lần nộp cũng xanh ở test
 * trên.
 */
it('still lets the same upload through when nothing changes during the scan', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)->callAction(TestAction::make('upload')->table(), data: [
        'file' => validPdf(),
        'title' => 'Thông báo thụ lý vụ án số 43/2026',
        'group' => DocumentGroup::Authority->value,
    ])->assertHasNoActionErrors();

    expect(array_column(sentNotifications(), 'body'))->not->toContain(__('actions.unauthorized'))
        ->and($matter->documents()->count())->toBe(1);
});

/**
 * Hai thao tác còn lại có `Gate::authorize()` trong Action: `PublishDocument` (`:115`) và
 * `RegroupDocument` (`:55`/`:58`).
 *
 * **Lời từ chối được TIÊM vào, và nói thẳng vì sao.** Đi qua màn hình thì cổng `->authorize()`
 * của chính cái nút hỏi CÙNG một câu `Gate` với Action, trên cùng bản ghi, trong cùng một
 * request — nên không dựng được một tình huống thật nào mà cái nút cho qua còn Action từ chối
 * (khác với lần nộp tệp, nơi cửa sổ quét virus tạo ra đúng khoảng đó). Việc Action THẬT SỰ ném
 * `AuthorizationException` ở hai chỗ ấy đã được ghim ở `PublishDocumentTest` và
 * `RegroupDocumentTest` bằng những người dùng thật thiếu quyền thật.
 *
 * Thứ test này ghim là nửa còn lại, nửa chưa ai ghim: khi một Action ném họ exception đó, màn
 * hình phải vẽ ra một câu tiếng Việt chứ không để nó bay lên thành 403.
 */
it('turns a Gate refusal from PublishDocument or RegroupDocument into the same Vietnamese sentence', function (string $action, string $class, string $stub) {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $document = documentWithFile($matter, DocumentGroup::Authority);

    app()->instance($class, new $stub);

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)->callAction(
        TestAction::make($action)->table($document),
        data: $action === 'publish'
            ? ['client_can_view' => true, 'client_can_download' => true]
            : ['group' => DocumentGroup::Issued->value],
    );

    $notifications = sentNotifications();

    expect(array_column($notifications, 'title'))->toContain(__('actions.failed_title'))
        ->and(array_column($notifications, 'body'))->toContain(__('actions.unauthorized'))
        ->and(implode("\n", array_column($notifications, 'body')))->not->toContain('This action is unauthorized')
        ->and($document->refresh()->group)->toBe(DocumentGroup::Authority)
        ->and($document->status)->toBe(DocumentStatus::InternalDraft);
})->with([
    'publish' => ['publish', PublishDocument::class, RefusingPublishDocument::class],
    'regroup' => ['regroup', RegroupDocument::class, RefusingRegroupDocument::class],
]);

// ---------------------------------------------------------------------------------------------
// `docs/docs-7`: ô tệp và `FileGuard` phải đồng ý về docx bị libmagic nhận là `application/zip`.
// ---------------------------------------------------------------------------------------------

/**
 * Một gói ZIP thật, đúng cấu trúc OOXML tối thiểu của Word (`[Content_Types].xml`,
 * `_rels/.rels`, `word/document.xml` — cùng ba mục `FileGuard::OFFICE_PACKAGE_ENTRIES` đòi cho
 * `docx`). **KHÁC thứ tự của `FileGuardTest::docxPackageBytes()`, có chủ đích** (xem chú thích
 * ngay trong thân hàm dưới đây cho thứ tự chính xác và bằng chứng đo được): trên bản libmagic của
 * container này, thứ tự của `docxPackageBytes()` (`[Content_Types].xml` đứng ĐẦU) cho MIME OOXML
 * cụ thể, còn thứ tự ở đây (`[Content_Types].xml` đứng CUỐI) mới cho `application/zip` — đúng thứ
 * test này cần để đo lỗi `docs/docs-7`. Hai bản libmagic khác nhau cho hai kết quả khác nhau trên
 * CÙNG một cấu trúc gói, đúng điều `FileGuard::verifyOfficePackage()` đã cảnh báo — nên con số
 * "thứ tự nào cho MIME nào" không phải một hằng số của định dạng, nó là một hằng số của MÁY ĐANG
 * CHẠY, và test bên dưới tự khẳng định lại tiền đề đó bằng `finfo` thật trước khi dùng nó. Không
 * đặt tên hàm trùng `docxPackageBytes()`/`zipBytes()` của `FileGuardTest.php`: Pest nạp mọi tệp
 * test vào CHUNG một tiến trình PHP, nên hai hàm toàn cục trùng tên ở hai tệp khác nhau là một
 * lỗi khai báo lại.
 */
function adminDocxRecognizedAsZipBytes(): string
{
    $path = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    // Thứ tự mục THẬT SỰ khiến libmagic của container này đọc ra `application/zip` thay vì MIME
    // OOXML cụ thể — ĐO ĐƯỢC bằng một probe chạy trên chính `bin/dev` (không phải suy đoán, xem
    // báo cáo Task 16): `[Content_Types].xml` PHẢI đứng CUỐI. Đảo thứ tự (mục đó đứng đầu, như ở
    // `FileGuardTest::docxPackageBytes()`) khiến finfo đọc đúng MIME OOXML trên bản libmagic này
    // — hai bản libmagic khác nhau cho hai kết quả khác nhau trên CÙNG một cấu trúc gói, đúng
    // điều `FileGuard::verifyOfficePackage()` đã cảnh báo.
    $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>');
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
    $zip->close();

    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

/**
 * **`UploadedFile::fake()` KHÔNG dùng được cho test này, và đây là lý do (`docs/docs-7`).**
 * `Illuminate\Http\Testing\File::getMimeType()` không đọc nội dung tệp: nó trả
 * `MimeType::from($this->name)` — MIME suy THẲNG từ đuôi tên tệp. Vòng thử nghiệm meta của
 * Livewire (`Testable::upload()` → `FileUploadConfiguration::storeTemporaryFile()`) ghi đúng giá
 * trị `getMimeType()` đó vào tệp `.json` đi kèm, nên một `UploadedFile::fake()->createWithContent
 * ('a.docx', <byte ZIP thật>)` VẪN báo MIME là MIME OOXML (suy từ đuôi `.docx`) — chưa từng chạm
 * tới nội dung tệp, và vì vậy chưa từng đo được lỗi mà `docs/docs-7` ghi lại.
 *
 * Một `Illuminate\Http\UploadedFile` TRẦN (không phải lớp con `Testing\File`) thì khác:
 * `getMimeType()` của nó không bị ghi đè, nên nó rơi xuống `Symfony\Component\HttpFoundation\
 * File\File::getMimeType()` — `MimeTypes::getDefault()->guessMimeType()`, đọc THẬT nội dung tệp
 * bằng bộ đoán MIME của hệ thống (cùng cơ chế `finfo` mà `FileGuard::realMimeType()` dùng, và
 * cùng cơ chế production dùng qua `TemporaryUploadedFile::detectMimeTypeFromContents()`).
 *
 * Nói cho đúng với chính thân hàm dưới đây: nó KHÔNG dùng `tempnam()` (một đường dẫn) mà dùng
 * `tmpfile()` (một RESOURCE, tự xoá khi đóng) — cùng nguyên liệu mà chính `Testing\File` gốc của
 * Laravel dùng cho `createWithContent()`, nên lớp con nặc danh bên dưới thừa hưởng đúng vòng đời
 * tệp tạm đó thay vì tự quản lý một đường dẫn riêng. Điểm chung với `UploadedFile::fake()` chỉ có
 * MỘT: `$test = true` để bỏ qua kiểm `is_uploaded_file()` — cờ đó là lý do chính đáng để không cần
 * một request HTTP multipart thật, không phải lý do để dùng `Testing\File` nguyên bản (thứ đoán
 * MIME từ đuôi, xem đoạn trên).
 */
function realDocxRecognizedAsZipUpload(string $name = 'hop-dong-that.docx'): UploadedFile
{
    // Lớp con nặc danh của CHÍNH `Testing\File` — giữ nguyên mọi thứ Livewire cần từ nó
    // (thuộc tính `$name` công khai mà `Testable::upload()` đọc trực tiếp, `tempFilePath()`),
    // và ghi đè đúng MỘT hàm: `getMimeType()`. Bản gốc của `Testing\File::getMimeType()` trả
    // `MimeType::from($this->name)` — suy MIME từ ĐUÔI tên tệp, không đọc nội dung — nên nó
    // không đo được lỗi `docs/docs-7`. Bản ghi đè ở đây gọi thẳng bộ đoán MIME thật của Symfony
    // (`MimeTypes::guessMimeType()`, cùng cơ chế `finfo`/`FileGuard::realMimeType()` dùng, và
    // cùng cơ chế production `TemporaryUploadedFile::detectMimeTypeFromContents()` dùng) trên
    // ĐƯỜNG DẪN TẠM THẬT, không suy từ tên.
    $tmp = tmpfile();
    fwrite($tmp, adminDocxRecognizedAsZipBytes());

    return new class($name, $tmp) extends File
    {
        public function getMimeType(): string
        {
            return MimeTypes::getDefault()->guessMimeType($this->tempFilePath());
        }
    };
}

/**
 * Trước bản sửa này, `DocumentsRelationManager::acceptedMimeTypes()` không có `application/zip`,
 * nên luật `mimetypes:` của CHÍNH Ô CHỌN TỆP từ chối gói docx thật ở trên TRƯỚC KHI
 * `UploadStaffDocument`/`FileGuard` kịp chạy — một docx hợp lệ bị chặn oan ngay tại ô. Test này đi
 * đúng đường Livewire thật (`callAction('upload', ...)`), không gọi thẳng `FileGuard::check()`
 * (đã có test riêng ở `FileGuardTest`), vì lỗi nằm ở TẦNG Ô CHỌN TỆP, không ở `FileGuard`.
 */
it('tải lên được một docx thật mà libmagic nhận là application/zip, qua ô admin', function () {
    // Tiền đề của cả test: bằng chứng rằng libmagic của MÁY ĐANG CHẠY thật sự đọc gói này ra
    // `application/zip`, không phải một giả định. Nếu một bản libmagic khác đọc ra MIME OOXML
    // cụ thể, test này phải đỏ Ở ĐÂY trước, chỉ thẳng ra rằng fixture cần dựng lại — không lặng
    // lẽ xanh vì một lý do khác (nhóm B vẫn nhận MIME OOXML sẵn có trong danh sách).
    expect((new finfo(FILEINFO_MIME_TYPE))->buffer(adminDocxRecognizedAsZipBytes()))->toBe('application/zip');

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)
        ->callAction(TestAction::make('upload')->table(), data: [
            'file' => realDocxRecognizedAsZipUpload(),
            'title' => 'Hợp đồng dịch vụ pháp lý',
            'group' => DocumentGroup::Issued->value,
        ])
        ->assertHasNoActionErrors();

    $document = $matter->documents()->firstOrFail();

    expect($document->title)->toBe('Hợp đồng dịch vụ pháp lý')
        ->and($document->getMedia('file'))->toHaveCount(1);
});

/**
 * Một ZIP thật, KHÔNG mang cấu trúc gói Office Open XML nào — chỉ một tệp text bên trong. Dùng
 * cho ba test I2 (vòng sửa 1) bên dưới: `docs/docs-7` đòi cả hai chiều được đo tại đúng tầng
 * admin thật (Livewire), không chỉ ở tầng đơn vị `FileGuard` (đã có ở `FileGuardTest`) — chiều
 * "docx thật bị nhận nhầm application/zip vẫn tải lên được" đã có ở trên, chiều "một ZIP tuỳ ý
 * đội tên .docx/.zip vẫn bị chặn" thì chưa, và đó là lỗ hổng review vòng 1 chỉ ra.
 */
function nonOfficeZipBytesForAdminUpload(): string
{
    $path = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('readme.txt', 'khong phai tai lieu Word');
    $zip->close();

    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

/**
 * Bản tổng quát của `realDocxRecognizedAsZipUpload()` phía trên — cùng kỹ thuật (`tmpfile()` +
 * ghi đè `getMimeType()` bằng bộ đoán MIME thật của Symfony), cho một cặp tên/nội dung bất kỳ.
 * Ba test I2 bên dưới cộng test MIME kế thừa đều cần MIME đọc từ NỘI DUNG thật, không suy từ
 * đuôi tên tệp — xem docblock `realDocxRecognizedAsZipUpload()` cho lý do đầy đủ vì sao
 * `UploadedFile::fake()` không dùng được ở đây.
 */
function realUploadWithBytes(string $name, string $bytes): UploadedFile
{
    $tmp = tmpfile();
    fwrite($tmp, $bytes);

    return new class($name, $tmp) extends File
    {
        public function getMimeType(): string
        {
            return MimeTypes::getDefault()->guessMimeType($this->tempFilePath());
        }
    };
}

/**
 * `docs/docs-7` (I2, vòng sửa 1): một ZIP bất kỳ — không mang cấu trúc OOXML nào — đặt tên
 * `.docx` phải bị chặn ở đúng tầng admin thật (Livewire, đường `callAction('upload', ...)`,
 * không gọi thẳng `FileGuard::check()`). Byte thật đọc ra `application/zip`, nên nó ĐI LỌT
 * được luật `mimetypes:` của ô (bắt buộc phải chấp nhận `application/zip` cho `docx`/`xlsx`, xem
 * test phía trên) — cấu trúc bên trong (`FileGuard::verifyOfficePackage()`) mới là cổng chặn nó.
 */
it('rejects một ZIP bất kỳ đặt tên .docx qua ô admin, dù MIME thật là application/zip', function () {
    $bytes = nonOfficeZipBytesForAdminUpload();
    // Tiền đề: byte thật của fixture này phải đọc ra application/zip trên máy đang chạy, cùng lý
    // do với `adminDocxRecognizedAsZipBytes()` phía trên — nếu không, test phải đỏ Ở ĐÂY.
    expect((new finfo(FILEINFO_MIME_TYPE))->buffer($bytes))->toBe('application/zip');

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)
        ->callAction(TestAction::make('upload')->table(), data: [
            'file' => realUploadWithBytes('gia-mao.docx', $bytes),
            'title' => 'Giả mạo văn bản Word',
            'group' => DocumentGroup::Issued->value,
        ])
        ->assertHasActionErrors([
            // Chuỗi mong đợi có DẤU HAI CHẤM ("...thật: bên trong thiếu..."), và
            // `Livewire\Features\SupportValidation\TestsValidation::assertErrorMatchesRuleOrMessage()`
            // coi mọi ':' trong giá trị so sánh là ranh giới tham số kiểu "same:field" rồi CẮT
            // NGẮN chuỗi tại đó trước khi so — dùng chuỗi thẳng ở đây làm assertion luôn xanh SAI
            // (so một chuỗi bị cắt cụt với chính nó). Một Closure nhận `$messages` thật, tự so
            // bằng `in_array(..., true)`, đi vòng qua đúng chỗ cắt đó.
            'file' => fn (array $rules, array $messages): bool => in_array(
                __('documents.file_guard.not_office_package', ['extension' => 'docx']),
                $messages,
                true,
            ),
        ]);

    expect($matter->documents()->count())->toBe(0);
});

/**
 * `docs/docs-7` (I2, vòng sửa 1): một `.zip` TRẦN (không đội tên `docx`/`xlsx`) vẫn phải bị chặn
 * dù `application/zip` đã buộc phải có trong `acceptedMimeTypes()` cho hai đuôi kia — chặn ở
 * tầng ĐUÔI TỆP của `FileGuard::ALLOWED` (`zip` không phải một khoá trong đó), không phải tầng
 * MIME của ô. Thêm `application/zip` vào danh sách MIME của ô không mở cửa cho ZIP trần.
 */
it('rejects một tệp .zip trần qua ô admin dù application/zip đã có trong danh sách MIME cho phép', function () {
    $bytes = nonOfficeZipBytesForAdminUpload();

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)
        ->callAction(TestAction::make('upload')->table(), data: [
            'file' => realUploadWithBytes('du-lieu.zip', $bytes),
            'title' => 'Dữ liệu nén',
            'group' => DocumentGroup::Issued->value,
        ])
        ->assertHasActionErrors(['file' => __('documents.file_guard.extension_not_allowed', ['extension' => 'zip'])]);

    expect($matter->documents()->count())->toBe(0);
});

/**
 * `docs/docs-7` (I2, vòng sửa 1): một tệp có ĐUÔI khai báo không khớp NỘI DUNG thật, đo bằng kỹ
 * thuật nội dung thật (`realUploadWithBytes()`), không phải `UploadedFile::fake()`. Khác test
 * "binds a rejected file to the file field and creates nothing" phía trên (dùng
 * `fake()->createWithContent()` với byte DOS-exe — vẫn hợp lệ vì `Testing\File::getMimeType()`
 * suy MIME từ đuôi `.pdf`, còn byte THẬT ghi xuống đĩa mới là DOS-exe, và `FileGuard` đọc byte
 * thật nên vẫn bắt được): ở đây dùng ảnh PNG thật đội tên `.pdf`, cùng lớp lỗi `content_mismatch`
 * nhưng qua đúng con đường I2 đòi — MIME của `Testing\File` KHÔNG can thiệp được nữa vì lớp con
 * ở đây ghi đè `getMimeType()` bằng bộ đoán nội dung thật ngay từ vòng kiểm của Ô, không chỉ ở
 * FileGuard.
 */
it('rejects một tệp .pdf mà nội dung thật là ảnh PNG, qua ô admin (nội dung thật, không dùng fake())', function () {
    $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    expect((new finfo(FILEINFO_MIME_TYPE))->buffer($bytes))->toBe('image/png');

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)
        ->callAction(TestAction::make('upload')->table(), data: [
            'file' => realUploadWithBytes('bao-cao.pdf', $bytes),
            'title' => 'Báo cáo giả dạng PDF',
            'group' => DocumentGroup::Issued->value,
        ])
        ->assertHasActionErrors(['file' => __('documents.file_guard.content_mismatch', ['extension' => 'pdf'])]);

    expect($matter->documents()->count())->toBe(0);
});

/**
 * Vòng sửa 1 thêm ba MIME kế thừa (`application/x-ole-storage`, `application/x-cfb`,
 * `application/CDFV2`) cho `doc`/`xls` — nhưng CỔNG ĐẦU của luồng thật là ô admin
 * (`acceptedMimeTypes()`), không phải `FileGuard`. Nếu ô chưa thêm ba MIME đó, một `.doc` OLE2
 * thật bị chặn TẠI Ô trước khi `FileGuard` kịp chạy — y hệt hình dạng lỗi `docs/docs-7` đã ghi
 * cho `application/zip`. Byte OLE2 dưới đây đọc ra `application/x-ole-storage` trên container
 * này (đo trực tiếp, xem `expect()` đầu tiên).
 */
it('tải lên được một .doc OLE2 thật mà libmagic nhận là application/x-ole-storage, qua ô admin', function () {
    $bytes = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 504);
    expect((new finfo(FILEINFO_MIME_TYPE))->buffer($bytes))->toBe('application/x-ole-storage');

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)
        ->callAction(TestAction::make('upload')->table(), data: [
            'file' => realUploadWithBytes('hop-dong-cu.doc', $bytes),
            'title' => 'Hợp đồng định dạng cũ',
            'group' => DocumentGroup::Issued->value,
        ])
        ->assertHasNoActionErrors();

    $document = $matter->documents()->firstOrFail();

    expect($document->title)->toBe('Hợp đồng định dạng cũ')
        ->and($document->getMedia('file'))->toHaveCount(1);
});

/**
 * Final review X7 (C-I2), màn hình: ô "Lý do chuyển nhóm" cũng hiện (và bắt buộc) cho một bản nháp
 * đã đi B → D, khi nó rời D sang C — cùng luật `RegroupDocument` áp dưới khoá.
 */
it('requires the misfiling reason on screen for a draft that went B → D and now leaves D for C', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $document = documentWithFile($matter, DocumentGroup::Issued, ['status' => DocumentStatus::InternalDraft]);

    $this->actingAs($lawyer, 'web');

    documentsManager($matter)
        ->callAction(TestAction::make('regroup')->table($document), data: ['group' => DocumentGroup::Internal->value])
        ->assertHasNoActionErrors();

    documentsManager($matter)
        ->callAction(TestAction::make('regroup')->table($document->fresh()), data: [
            'group' => DocumentGroup::Authority->value,
            'reason' => '',
        ])
        ->assertHasActionErrors(['reason' => 'required']);

    expect($document->fresh()->group)->toBe(DocumentGroup::Internal);
});
