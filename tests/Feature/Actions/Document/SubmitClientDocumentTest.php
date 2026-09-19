<?php

use App\Actions\Document\SubmitClientDocument;
use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Events\ClientDocumentSubmitted;
use App\Exceptions\FileRejected;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Support\Files\VirusScanner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Tệp của test không được rơi vào `storage/app/private` thật của máy dev.
    Storage::fake('private');

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->create(['client_id' => $this->client->id]);
    $this->matter = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lawyer->id]);
    $this->item = MatterChecklistItem::factory()->for($this->matter)->create(['name' => 'Giấy chứng nhận quyền sử dụng đất']);
});

/**
 * Byte thật của một PDF tối thiểu — `FileGuard` đọc MIME bằng `finfo` trên nội dung, nên một
 * `UploadedFile::fake()->create()` rỗng không qua được cổng. Tiền tố `clientSubmit` vì hàm khai
 * báo trong một tệp test Pest là hàm TOÀN CỤC, và `FileGuardTest`/`UploadStaffDocumentTest` đã
 * có hàm cùng vai trò.
 */
function clientSubmitPdfBytes(): string
{
    return "%PDF-1.4\n%âãÏÓ\n1 0 obj\n<< /Type /Catalog >>\nendobj\n";
}

function clientSubmitPdf(string $name = 'so-do.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, clientSubmitPdfBytes());
}

function submitClientDocument(
    MatterChecklistItem $checklistItem,
    ClientUser $actor,
    ?UploadedFile $file = null,
): Document {
    return app(SubmitClientDocument::class)->handle(
        checklistItem: $checklistItem,
        actor: $actor,
        file: $file ?? clientSubmitPdf(),
    );
}

// ---------------------------------------------------------------------------------------------
// SPEC §6.6 bước 7: tài liệu nhóm A, và bộ mặc định của nhóm A ở bảng SPEC §4.11.
// ---------------------------------------------------------------------------------------------

it('tạo tài liệu nhóm A đã công bố, khách xem và tải được', function () {
    $document = submitClientDocument($this->item, $this->clientUser);

    expect($document->group)->toBe(DocumentGroup::ClientProvided)
        ->and($document->status)->toBe(DocumentStatus::Published)
        ->and($document->client_can_view)->toBeTrue()
        ->and($document->client_can_download)->toBeTrue()
        ->and($document->matter_id)->toBe($this->matter->id)
        ->and($document->matter_checklist_item_id)->toBe($this->item->id);
});

it('ghi người nộp là chính tài khoản khách hàng, không phải một nhân sự', function () {
    $document = submitClientDocument($this->item, $this->clientUser);

    expect($document->uploader_id)->toBe($this->clientUser->id)
        ->and($document->uploader_type)->toBe($this->clientUser->getMorphClass())
        ->and($document->uploader)->not->toBeNull()
        ->and($document->uploader->is($this->clientUser))->toBeTrue();
});

it('tên tài liệu lấy từ tên đầu mục danh mục', function () {
    expect(submitClientDocument($this->item, $this->clientUser)->title)
        ->toBe('Giấy chứng nhận quyền sử dụng đất');
});

it('đặt published_at nhưng để trống published_by vì người đưa ra không phải nhân sự', function () {
    $document = submitClientDocument($this->item, $this->clientUser);

    expect($document->published_at)->not->toBeNull()
        ->and($document->published_by)->toBeNull();
});

it('không đặt ngày ban hành: khách không khai ngày nào', function () {
    expect(submitClientDocument($this->item, $this->clientUser)->issued_at)->toBeNull();
});

// ---------------------------------------------------------------------------------------------
// SPEC §6.6 bước 8.
// ---------------------------------------------------------------------------------------------

it('đặt đầu mục danh mục sang pending_review', function () {
    submitClientDocument($this->item, $this->clientUser);

    expect($this->item->refresh()->status)->toBe(ChecklistItemStatus::PendingReview);
});

it('nộp lại sau khi bị từ chối thì xoá lý do từ chối cũ', function () {
    $this->item->update([
        'status' => ChecklistItemStatus::Rejected,
        'rejection_reason' => 'Ảnh bị mờ ở góc trên nên không đọc được số thửa.',
    ]);

    submitClientDocument($this->item, $this->clientUser);

    // Lý do từ chối hiện THẲNG cho khách (SPEC §6.7). Một đầu mục đang chờ văn phòng kiểm tra mà
    // vẫn treo câu "ảnh bị mờ" nói với khách rằng lần nộp vừa rồi đã bị từ chối.
    expect($this->item->refresh()->rejection_reason)->toBeNull();
});

it('nộp lại xoá luôn dấu vết của lần duyệt trước', function () {
    $this->item->update([
        'status' => ChecklistItemStatus::Rejected,
        'rejection_reason' => 'Bản này là bản photo chưa chứng thực nên chưa dùng được.',
        'reviewed_by' => $this->lawyer->id,
        'reviewed_at' => now()->subDay(),
    ]);

    submitClientDocument($this->item, $this->clientUser);
    $this->item->refresh();

    // `reviewed_by`/`reviewed_at` nói "ai đã xem, lúc nào". Sau một lần nộp mới thì chưa ai xem
    // bản mới cả, nên giữ lại hai giá trị cũ là để dòng dữ liệu tự khai một lần duyệt chưa xảy ra.
    expect($this->item->reviewed_by)->toBeNull()
        ->and($this->item->reviewed_at)->toBeNull();
});

// ---------------------------------------------------------------------------------------------
// SPEC §6.6 bước 7 và SPEC §11 "Nghiệp vụ": nộp lại tạo version 2, bản version 1 vẫn còn.
// ---------------------------------------------------------------------------------------------

it('nộp lần đầu là version 1 và không có bản cha', function () {
    $document = submitClientDocument($this->item, $this->clientUser);

    expect($document->version)->toBe(1)
        ->and($document->parent_document_id)->toBeNull();
});

it('nộp lại tạo bản version 2 trỏ về bản cũ, và bản cũ còn nguyên cả tệp', function () {
    $first = submitClientDocument($this->item, $this->clientUser, clientSubmitPdf('lan-1.pdf'));
    $second = submitClientDocument($this->item, $this->clientUser, clientSubmitPdf('lan-2.pdf'));

    $first->refresh();

    expect($second->version)->toBe(2)
        ->and($second->parent_document_id)->toBe($first->id)
        ->and($first->exists)->toBeTrue()
        ->and($first->trashed())->toBeFalse()
        ->and($first->version)->toBe(1)
        ->and($first->getFirstMedia('file'))->not->toBeNull()
        ->and($first->getFirstMedia('file')->name)->toBe('lan-1.pdf')
        ->and($second->getFirstMedia('file')->name)->toBe('lan-2.pdf')
        ->and(Document::query()->count())->toBe(2);
});

it('nộp lần thứ ba nối tiếp chuỗi version chứ không quay lại 2', function () {
    $first = submitClientDocument($this->item, $this->clientUser);
    $second = submitClientDocument($this->item, $this->clientUser);
    $third = submitClientDocument($this->item, $this->clientUser);

    expect([$first->version, $second->version, $third->version])->toBe([1, 2, 3])
        ->and($third->parent_document_id)->toBe($second->id);
});

it('một bản đã xoá mềm vẫn tính vào chuỗi version: số version không bao giờ dùng lại', function () {
    $first = submitClientDocument($this->item, $this->clientUser);
    $first->delete();

    $second = submitClientDocument($this->item, $this->clientUser);

    // `document_published`/`document_submitted` mang theo `version` (SPEC §10.6). Cấp lại số 1
    // cho một tệp khác biến mọi dòng nhật ký cũ thành câu không còn chỉ đúng bản nào.
    expect($second->version)->toBe(2)
        ->and($second->parent_document_id)->toBe($first->id);
});

it('một tài liệu nội bộ gắn cùng đầu mục KHÔNG trở thành bản cha của lần khách nộp', function (DocumentGroup $group) {
    // Một ghi chú công việc nội bộ (nhóm D) hay một văn bản toà (nhóm C) gắn vào cùng đầu mục là
    // việc hợp lệ — kế hoạch Task 6 nói thẳng điều đó. Nhưng chuỗi version ở SPEC §6.6 bước 7 là
    // chuỗi các LẦN KHÁCH NỘP cùng một giấy tờ; nối bản nộp của khách vào một ghi chú nội bộ sẽ
    // đánh số nó là "bản thứ hai của ghi chú đó".
    Document::factory()->for($this->matter)->group($group)->create([
        'matter_checklist_item_id' => $this->item->id,
        'version' => 7,
    ]);

    $document = submitClientDocument($this->item, $this->clientUser);

    expect($document->version)->toBe(1)
        ->and($document->parent_document_id)->toBeNull();
})->with([
    'nhóm B' => [DocumentGroup::Issued],
    'nhóm C' => [DocumentGroup::Authority],
    'nhóm D' => [DocumentGroup::Internal],
]);

it('một bản nhóm A nhân viên nộp thay VẪN là bản cha của lần khách nộp tiếp theo', function () {
    // Cặp dương của test trên. Nhóm A là "khách cung cấp" bất kể ai bấm nút tải lên (SPEC §4.11),
    // nên một lần nộp thay khách và một lần khách tự nộp là hai bản của cùng một giấy tờ.
    $byStaff = Document::factory()->for($this->matter)->group(DocumentGroup::ClientProvided)->create([
        'matter_checklist_item_id' => $this->item->id,
        'version' => 1,
    ]);

    $document = submitClientDocument($this->item, $this->clientUser);

    expect($document->version)->toBe(2)
        ->and($document->parent_document_id)->toBe($byStaff->id);
});

it('bản cũ mà khách không còn xem được vẫn tính vào chuỗi version', function () {
    // Chuỗi version phải đọc được từ DỮ LIỆU THẬT, không qua phạm vi của khách đang đăng nhập:
    // `ClientPortalScope` giấu một bản nhóm A bị tắt cờ `client_can_view`, và nếu Action hỏi qua
    // phạm vi đó thì bản mới lại mang số 1 lần nữa.
    $first = submitClientDocument($this->item, $this->clientUser);
    $first->update(['client_can_view' => false]);

    $this->actingAs($this->clientUser, 'client');

    expect(submitClientDocument($this->item, $this->clientUser)->version)->toBe(2);
});

// ---------------------------------------------------------------------------------------------
// Khách là người NGOÀI hệ thống: mọi thứ họ gửi lên đều bị nghi ngờ cho tới khi chứng minh
// ngược lại — kể cả id của đầu mục danh mục.
// ---------------------------------------------------------------------------------------------

it('không nộp được vào đầu mục của một khách hàng khác', function () {
    $otherMatter = Matter::factory()->create();
    $otherItem = MatterChecklistItem::factory()->for($otherMatter)->create();

    expect(fn () => submitClientDocument($otherItem, $this->clientUser))
        ->toThrow(AuthorizationException::class);

    expect(Document::query()->count())->toBe(0)
        ->and(Media::query()->count())->toBe(0)
        ->and($otherItem->refresh()->status)->toBe(ChecklistItemStatus::Missing);
});

it('không nộp được vào đầu mục của khách hàng khác kể cả khi phiên portal đang mở của chính mình', function () {
    $otherMatter = Matter::factory()->create();
    $otherItem = MatterChecklistItem::factory()->for($otherMatter)->create();

    $this->actingAs($this->clientUser, 'client');

    expect(fn () => submitClientDocument($otherItem, $this->clientUser))
        ->toThrow(AuthorizationException::class);

    expect(Document::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('không nộp được vào một hồ sơ chưa công bố lên portal', function () {
    $this->matter->update(['is_published_to_portal' => false]);

    expect(fn () => submitClientDocument($this->item, $this->clientUser))
        ->toThrow(AuthorizationException::class);

    expect(Document::query()->count())->toBe(0);
});

it('không nộp được vào một hồ sơ đã xoá mềm', function () {
    $this->matter->delete();

    expect(fn () => submitClientDocument($this->item->fresh(), $this->clientUser))
        ->toThrow(AuthorizationException::class);

    expect(Document::query()->count())->toBe(0);
});

it('không nộp được vào một đầu mục đã bị xoá khỏi danh mục', function () {
    $this->item->delete();

    expect(fn () => submitClientDocument($this->item, $this->clientUser))
        ->toThrow(AuthorizationException::class);

    expect(Document::query()->count())->toBe(0);
});

it('một đầu mục không còn tồn tại bị từ chối y như một đầu mục của người khác', function () {
    // SPEC §10.10: không tồn tại và không có quyền phải trả lời GIỐNG NHAU. Hai loại exception
    // khác nhau ở đây là một cái máy dò: gửi một id bất kỳ, đọc loại lỗi, biết id đó có thật hay
    // không — cho đúng người không được biết.
    $this->item->forceDelete();

    expect(fn () => submitClientDocument($this->item, $this->clientUser))
        ->toThrow(AuthorizationException::class);

    expect(Document::query()->count())->toBe(0);
});

it('đầu mục bị xoá trong lúc quét virus thì lần nộp đó dừng lại, không ghi gì', function () {
    // Cổng tệp chạy NGOÀI transaction và `config('vkcrm.clamav.timeout')` cho lần quét tới 30
    // giây (xem docblock `StoresDocumentFile`). Ba mươi giây là thừa để một trợ lý xoá một đầu
    // mục khỏi danh mục hồ sơ. Đây là lý do Action đọc lại bản ghi BÊN TRONG transaction thay vì
    // tin vào bản đã đọc trước lúc quét — không có lần đọc đó, tệp này rơi vào một dòng danh mục
    // không còn tồn tại và biến mất khỏi mọi màn hình.
    $item = $this->item;

    app()->instance(VirusScanner::class, new class($item) implements VirusScanner
    {
        public function __construct(private MatterChecklistItem $item) {}

        public function scan(string $path): void
        {
            $this->item->delete();
        }

        public function isActive(): bool
        {
            return true;
        }
    });

    expect(fn () => submitClientDocument($item, $this->clientUser))
        ->toThrow(AuthorizationException::class);

    expect(Document::query()->count())->toBe(0)
        ->and(Media::query()->count())->toBe(0);
});

it('tin dòng dữ liệu thật chứ không tin đối tượng caller cầm trong tay', function () {
    // Đối tượng Eloquent là thứ ai cũng gán thuộc tính được, và ở đây caller là một màn hình
    // portal nhận tham số từ trình duyệt. Đầu mục THẬT thuộc $this->matter; đối tượng trong bộ
    // nhớ khai rằng nó thuộc một hồ sơ khác của cùng khách hàng.
    $otherMatter = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lawyer->id]);
    $tampered = $this->item->replicate();
    $tampered->id = $this->item->id;
    $tampered->exists = true;
    $tampered->matter_id = $otherMatter->id;

    $document = submitClientDocument($tampered, $this->clientUser);

    expect($document->matter_id)->toBe($this->matter->id);
});

it('không dùng phiên đăng nhập làm nguồn danh tính người nộp', function () {
    // Phiên portal thuộc về một khách hàng KHÁC; actor tường minh mới là người quyết định.
    $intruder = ClientUser::factory()->create();
    $this->actingAs($intruder, 'client');

    expect(fn () => submitClientDocument($this->item, $intruder))
        ->toThrow(AuthorizationException::class);

    expect(Document::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('nộp được khi actor là chủ hồ sơ dù phiên đăng nhập là người khác — cặp dương', function () {
    $intruder = ClientUser::factory()->create();
    $this->actingAs($intruder, 'client');

    expect(submitClientDocument($this->item, $this->clientUser)->matter_id)->toBe($this->matter->id);
});

it('nộp được khi không có phiên đăng nhập nào — cặp dương của actor tường minh', function () {
    expect(submitClientDocument($this->item, $this->clientUser)->version)->toBe(1);
});

// ---------------------------------------------------------------------------------------------
// SPEC §6.6 bước 2-6: cổng tệp dùng chung với nhân sự, không có bản nới lỏng cho khách.
// ---------------------------------------------------------------------------------------------

it('tệp .svg của khách bị từ chối và không để lại bản ghi nào', function () {
    $svg = UploadedFile::fake()->createWithContent(
        'so-do.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    );

    expect(fn () => submitClientDocument($this->item, $this->clientUser, $svg))
        ->toThrow(FileRejected::class);

    expect(Document::query()->count())->toBe(0)
        ->and(Media::query()->count())->toBe(0)
        ->and($this->item->refresh()->status)->toBe(ChecklistItemStatus::Missing);
});

it('tệp bị VirusScanner từ chối thì không để lại bản ghi nào', function () {
    app()->instance(VirusScanner::class, new class implements VirusScanner
    {
        public function scan(string $path): void
        {
            throw FileRejected::virusDetected();
        }

        public function isActive(): bool
        {
            return true;
        }
    });

    expect(fn () => submitClientDocument($this->item, $this->clientUser))
        ->toThrow(FileRejected::class);

    expect(Document::query()->count())->toBe(0)
        ->and(Media::query()->count())->toBe(0)
        ->and($this->item->refresh()->status)->toBe(ChecklistItemStatus::Missing);
});

it('tên tệp trên đĩa sinh ngẫu nhiên, tên hiển thị giữ tên khách đặt', function () {
    $document = submitClientDocument(
        $this->item,
        $this->clientUser,
        clientSubmitPdf('Sổ đỏ nhà "bố mẹ"; bản chụp.pdf'),
    );

    $media = $document->getFirstMedia('file');

    expect($media)->not->toBeNull()
        ->and($media->file_name)->toMatch('/^[0-9a-z]{26}\.pdf$/')
        ->and($media->name)->toBe('Sổ đỏ nhà bố mẹ bản chụp.pdf');
});

// ---------------------------------------------------------------------------------------------
// Nhật ký kiểm toán và thông báo.
// ---------------------------------------------------------------------------------------------

it('ghi dòng nhật ký document_submitted với causer là tài khoản khách hàng', function () {
    // Đây là lần ĐẦU TIÊN trong hệ thống một `ClientUser` là causer của một dòng
    // `Audit::record()` do Action ghi ra. `Relation::enforceMorphMap()` đang bật, nên nếu
    // `client_user` không có trong bản đồ thì lời gọi này ném `ClassMorphViolationException`.
    $staff = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($staff, 'web');

    $document = submitClientDocument($this->item, $this->clientUser);

    $activity = Activity::query()->where('event', 'document_submitted')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_type)->toBe('client_user')
        ->and($activity->causer?->is($this->clientUser))->toBeTrue()
        ->and($activity->subject?->is($document))->toBeTrue()
        ->and($activity->properties->get('matter_id'))->toBe($this->matter->id)
        // `matters.client_id` sửa được, nên "bản này của khách nào" phải nằm thẳng trong dòng
        // nhật ký chứ không suy qua hồ sơ — cùng lý lẽ với `PublishDocument`.
        ->and($activity->properties->get('client_id'))->toBe($this->client->id)
        ->and($activity->properties->get('matter_checklist_item_id'))->toBe($this->item->id)
        ->and($activity->properties->get('group'))->toBe(DocumentGroup::ClientProvided->value)
        ->and($activity->properties->get('version'))->toBe(1);
});

it('KHÔNG ghi dòng nhật ký công bố: văn phòng không công bố gì cả', function () {
    submitClientDocument($this->item, $this->clientUser);

    // `document_published` trả lời câu "văn phòng đã đưa thứ gì ra trước mặt khách". Một tệp do
    // chính khách gửi lên không phải một lần văn phòng đưa ra thứ gì, và đếm nó vào đó sẽ khiến
    // một lần rà soát các lần công bố báo về những tài liệu không ai trong văn phòng quyết định.
    expect(Activity::query()->where('event', 'document_published')->count())->toBe(0);
});

it('dispatch sự kiện báo cho đội ngũ biết có tệp mới cần kiểm tra', function () {
    Event::fake([ClientDocumentSubmitted::class]);

    $document = submitClientDocument($this->item, $this->clientUser);

    Event::assertDispatched(
        ClientDocumentSubmitted::class,
        fn (ClientDocumentSubmitted $event) => $event->document->is($document),
    );
});
