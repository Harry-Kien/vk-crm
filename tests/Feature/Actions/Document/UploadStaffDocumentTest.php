<?php

use App\Actions\Document\SubmitClientDocument;
use App\Actions\Document\UploadStaffDocument;
use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Exceptions\FileRejected;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Support\Files\VirusScanner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Tệp của test không được rơi vào `storage/app/private` thật của máy dev.
    Storage::fake('private');
});

/**
 * Byte thật của một PDF tối thiểu — `FileGuard` đọc MIME bằng `finfo` trên nội dung, nên một
 * `UploadedFile::fake()->create()` rỗng không qua được cổng. Tên hàm có tiền tố `staffUpload`
 * vì hàm khai báo trong một tệp test Pest là hàm TOÀN CỤC: trùng tên với `validPdfBytes()` của
 * `FileGuardTest` là lỗi "cannot redeclare" khi cả hai tệp cùng được nạp.
 */
function staffUploadPdfBytes(): string
{
    return "%PDF-1.4\n%âãÏÓ\n1 0 obj\n<< /Type /Catalog >>\nendobj\n";
}

function staffUploadPdf(string $name = 'quyet-dinh.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, staffUploadPdfBytes());
}

/** Vụ việc mà `$lawyer` thấy được: `Matter::created` tự thêm lead lawyer vào `matter_user`. */
function staffUploadMatter(User $owner, array $attributes = []): Matter
{
    return Matter::factory()->create([...$attributes, 'lead_lawyer_id' => $owner->id]);
}

function uploadStaffDocument(
    Matter $matter,
    User $actor,
    DocumentGroup $group,
    ?UploadedFile $file = null,
    ?MatterChecklistItem $checklistItem = null,
    DateTimeInterface|string|null $issuedAt = null,
    string $title = 'Tài liệu thử nghiệm',
): Document {
    return app(UploadStaffDocument::class)->handle(
        matter: $matter,
        actor: $actor,
        file: $file ?? staffUploadPdf(),
        group: $group,
        title: $title,
        checklistItem: $checklistItem,
        issuedAt: $issuedAt,
    );
}

// ---------------------------------------------------------------------------------------------
// Bảng quy tắc mặc định SPEC §4.11. Mỗi nhóm có một test riêng: một test gộp "nhóm B và C đều
// internal_draft" sẽ xanh cả khi mã bỏ sót đúng một trong hai nhánh của `match`.
// ---------------------------------------------------------------------------------------------

it('nhóm A nhân viên nộp thay: published, khách xem và tải được', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);

    $document = uploadStaffDocument($matter, $lawyer, DocumentGroup::ClientProvided);

    expect($document->status)->toBe(DocumentStatus::Published)
        ->and($document->client_can_view)->toBeTrue()
        ->and($document->client_can_download)->toBeTrue()
        ->and($document->published_at)->not->toBeNull()
        ->and($document->published_by)->toBe($lawyer->id);
});

it('nhóm B: internal_draft, khách không thấy gì', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);

    $document = uploadStaffDocument($matter, $lawyer, DocumentGroup::Issued);

    expect($document->status)->toBe(DocumentStatus::InternalDraft)
        ->and($document->client_can_view)->toBeFalse()
        ->and($document->client_can_download)->toBeFalse()
        ->and($document->published_at)->toBeNull()
        ->and($document->published_by)->toBeNull();
});

it('nhóm C: internal_draft, khách không thấy gì', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);

    $document = uploadStaffDocument($matter, $lawyer, DocumentGroup::Authority);

    expect($document->status)->toBe(DocumentStatus::InternalDraft)
        ->and($document->client_can_view)->toBeFalse()
        ->and($document->client_can_download)->toBeFalse();
});

it('nhóm D: internal_draft và client_can_download vĩnh viễn false', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);

    $document = uploadStaffDocument($matter, $lawyer, DocumentGroup::Internal);

    expect($document->status)->toBe(DocumentStatus::InternalDraft)
        ->and($document->client_can_view)->toBeFalse()
        ->and($document->client_can_download)->toBeFalse();
});

// ---------------------------------------------------------------------------------------------
// Nhóm nào ra tới khách NGAY LÚC TẠO thì nộp nó là một lần công bố, nên nó đòi `document.publish`
// — cùng cái cổng phân biệt "làm hồ sơ" với "quyết định số phận một tài liệu" mà Task 2 đặt cho
// `DocumentPolicy::delete` và `::publish`. Nhóm không tự ra tới khách thì không đổi: SPEC §4.11
// nói rõ nhân viên nộp thay là một luồng có thật, và trợ lý phải làm được nó.
// ---------------------------------------------------------------------------------------------

it('trợ lý không có document.publish thì không nộp được tài liệu nhóm A', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = staffUploadMatter($lawyer);
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    expect(fn () => uploadStaffDocument($matter, $assistant, DocumentGroup::ClientProvided))
        ->toThrow(AuthorizationException::class);

    expect(Document::query()->count())->toBe(0)
        ->and(Media::query()->count())->toBe(0);
});

it('trợ lý vẫn nộp được tài liệu vào những nhóm không tự ra tới khách', function (DocumentGroup $group) {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = staffUploadMatter($lawyer);
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    $document = uploadStaffDocument($matter, $assistant, $group);

    expect($document->status)->toBe(DocumentStatus::InternalDraft)
        ->and($document->client_can_view)->toBeFalse();
})->with([
    'nhóm B' => [DocumentGroup::Issued],
    'nhóm C' => [DocumentGroup::Authority],
    'nhóm D' => [DocumentGroup::Internal],
]);

it('luật sư có document.publish thì nộp được tài liệu nhóm A — cặp dương', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);

    expect(uploadStaffDocument($matter, $lawyer, DocumentGroup::ClientProvided)->client_can_view)
        ->toBeTrue();
});

// ---------------------------------------------------------------------------------------------
// Tên tệp: tên trên đĩa là tên sinh ngẫu nhiên (SPEC §6.6 bước 6), tên hiển thị là tên người
// nộp đặt, đã đi qua FileGuard::safeName().
// ---------------------------------------------------------------------------------------------

it('tên tệp lưu trên đĩa là tên sinh ngẫu nhiên, không phải tên người nộp đặt', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);

    $document = uploadStaffDocument(
        $matter,
        $lawyer,
        DocumentGroup::Authority,
        staffUploadPdf('ban-an-so-42.pdf'),
    );

    $media = $document->getFirstMedia('file');

    expect($media)->not->toBeNull()
        ->and($media->file_name)->not->toBe('ban-an-so-42.pdf')
        ->and($media->file_name)->toMatch('/^[0-9a-z]{26}\.pdf$/');
});

it('tên hiển thị giữ đúng tên người nộp đặt, sau khi đi qua safeName', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);

    // Dấu nháy kép và dấu chấm phẩy là mưu đồ tách header Content-Disposition ở Task 5;
    // `FileGuard::check()` KHÔNG từ chối chúng (chỉ ký tự điều khiển mới bị từ chối), nên nếu
    // nơi này không gọi safeName() thì chúng đi thẳng vào cơ sở dữ liệu.
    $document = uploadStaffDocument(
        $matter,
        $lawyer,
        DocumentGroup::Authority,
        staffUploadPdf('bản án "số 42"; rm.pdf'),
    );

    expect($document->getFirstMedia('file')->name)->toBe('bản án số 42 rm.pdf');
});

it('tên tệp tiếng Việt có dấu được giữ nguyên dấu trong tên hiển thị', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);

    $document = uploadStaffDocument(
        $matter,
        $lawyer,
        DocumentGroup::Authority,
        staffUploadPdf('Giấy chứng nhận quyền sử dụng đất.pdf'),
    );

    expect($document->getFirstMedia('file')->name)->toBe('Giấy chứng nhận quyền sử dụng đất.pdf');
});

it('hai tệp cùng tên gốc không đè lên nhau vì tên trên đĩa khác nhau', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);

    $first = uploadStaffDocument($matter, $lawyer, DocumentGroup::Authority, staffUploadPdf('cv.pdf'));
    $second = uploadStaffDocument($matter, $lawyer, DocumentGroup::Authority, staffUploadPdf('cv.pdf'));

    expect($first->getFirstMedia('file')->file_name)
        ->not->toBe($second->getFirstMedia('file')->file_name);
});

// ---------------------------------------------------------------------------------------------
// Cổng tệp và quét virus: cả hai chặn TRƯỚC khi có bất kỳ dòng nào được ghi.
// ---------------------------------------------------------------------------------------------

it('tệp .svg bị từ chối và không để lại bản ghi nào', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);

    $svg = UploadedFile::fake()->createWithContent(
        'so-do.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    );

    expect(fn () => uploadStaffDocument($matter, $lawyer, DocumentGroup::Authority, $svg))
        ->toThrow(FileRejected::class);

    expect(Document::query()->count())->toBe(0)
        ->and(Media::query()->count())->toBe(0);
});

it('tệp bị VirusScanner từ chối thì không để lại bản ghi nào', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);

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

    expect(fn () => uploadStaffDocument($matter, $lawyer, DocumentGroup::Authority))
        ->toThrow(FileRejected::class);

    expect(Document::query()->count())->toBe(0)
        ->and(Media::query()->count())->toBe(0);
});

it('VirusScanner thật sự được gọi trên tệp vừa nộp', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);

    $scanner = new class implements VirusScanner
    {
        /** @var list<string> nội dung ĐỌC ĐƯỢC tại thời điểm quét, không phải đường dẫn suông. */
        public array $scanned = [];

        public function scan(string $path): void
        {
            // Đọc ngay ở đây: một đường dẫn ghi lại rồi kiểm tra sau khi Action chạy xong đã bị
            // medialibrary di chuyển đi, nên khẳng định "tệp tồn tại" sẽ luôn sai — hoặc tệ hơn,
            // được viết lỏng cho qua và không còn kiểm được gì.
            $this->scanned[] = is_file($path) ? (string) file_get_contents($path) : '<không đọc được>';
        }

        public function isActive(): bool
        {
            return true;
        }
    };

    app()->instance(VirusScanner::class, $scanner);

    uploadStaffDocument($matter, $lawyer, DocumentGroup::Authority);

    expect($scanner->scanned)->toBe([staffUploadPdfBytes()]);
});

// ---------------------------------------------------------------------------------------------
// Quyền và toàn vẹn dữ liệu.
// ---------------------------------------------------------------------------------------------

it('người không xem được vụ việc thì không nộp tệp vào đó được', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);

    expect(fn () => uploadStaffDocument($matter, $outsider, DocumentGroup::Authority))
        ->toThrow(AuthorizationException::class);

    expect(Document::query()->count())->toBe(0)
        ->and(Media::query()->count())->toBe(0);
});

it('kế toán không nộp được tệp dù đang đăng nhập', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $matter = staffUploadMatter($lawyer);
    $this->actingAs($accountant, 'web');

    expect(fn () => uploadStaffDocument($matter, $accountant, DocumentGroup::Authority))
        ->toThrow(AuthorizationException::class);

    expect(Document::query()->count())->toBe(0);
});

it('không nộp được tệp vào một vụ việc đã xoá mềm', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = staffUploadMatter($admin);
    $matter->delete();

    expect(fn () => uploadStaffDocument($matter->fresh(), $admin, DocumentGroup::Authority))
        ->toThrow(AuthorizationException::class);

    expect(Document::query()->count())->toBe(0);
});

it('không gắn được tài liệu vào đầu mục danh mục của một vụ việc khác', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);
    $other = staffUploadMatter($lawyer);
    $foreignItem = MatterChecklistItem::factory()->create(['matter_id' => $other->id]);

    expect(fn () => uploadStaffDocument($matter, $lawyer, DocumentGroup::ClientProvided, null, $foreignItem))
        ->toThrow(ValidationException::class);

    expect(Document::query()->count())->toBe(0);
});

it('gắn được tài liệu vào đầu mục danh mục của chính vụ việc đó', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);
    $item = MatterChecklistItem::factory()->create(['matter_id' => $matter->id]);

    $document = uploadStaffDocument($matter, $lawyer, DocumentGroup::ClientProvided, null, $item);

    expect($document->matter_checklist_item_id)->toBe($item->id);
});

it('ghi nhật ký kiểm toán đúng người nộp, kể cả khi phiên đăng nhập là người khác', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = staffUploadMatter($lawyer);

    // Phiên `web` thuộc về admin, nhưng actor tường minh là lawyer: dòng nhật ký phải ghi lawyer.
    $this->actingAs($admin, 'web');

    $document = uploadStaffDocument($matter, $lawyer, DocumentGroup::Authority);

    $activity = Activity::query()->where('event', 'document_uploaded')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer?->is($lawyer))->toBeTrue()
        ->and($activity->subject?->is($document))->toBeTrue()
        ->and($activity->properties->get('group'))->toBe(DocumentGroup::Authority->value);
});

it('nộp tệp vào nhóm ra thẳng tới khách ghi CẢ dòng nhật ký công bố', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);

    $document = uploadStaffDocument($matter, $lawyer, DocumentGroup::ClientProvided);

    // Ai dựng lại danh sách "những gì đã ra tới khách" đều lọc theo tên sự kiện. Một lần nộp
    // nhóm A LÀ một lần công bố, nên nếu nó chỉ để lại `document_uploaded` thì truy vấn đó im
    // lặng bỏ sót — và bỏ sót đúng nhóm tài liệu ra tới khách mà không ai bấm nút công bố.
    $published = Activity::query()->where('event', 'document_published')->latest('id')->first();

    expect($published)->not->toBeNull()
        ->and($published->subject?->is($document))->toBeTrue()
        ->and($published->causer?->is($lawyer))->toBeTrue()
        ->and($published->properties->get('group'))->toBe(DocumentGroup::ClientProvided->value)
        ->and($published->properties->get('client_id'))->toBe($matter->client_id)
        ->and($published->properties->get('republished'))->toBeFalse()
        ->and(Activity::query()->where('event', 'document_uploaded')->count())->toBe(1);
});

it('nộp tệp vào nhóm chưa ra tới khách KHÔNG ghi dòng nhật ký công bố nào', function (DocumentGroup $group) {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);

    uploadStaffDocument($matter, $lawyer, $group);

    expect(Activity::query()->where('event', 'document_published')->count())->toBe(0);
})->with([
    'nhóm B' => [DocumentGroup::Issued],
    'nhóm C' => [DocumentGroup::Authority],
    'nhóm D' => [DocumentGroup::Internal],
]);

// ---------------------------------------------------------------------------------------------
// Ngày ban hành: một chuỗi người dùng gõ vào không được biến thành lỗi 500.
// ---------------------------------------------------------------------------------------------

it('ngày ban hành không đọc được là lỗi xác thực, không phải lỗi 500', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);

    // `Carbon::parse()` ném `InvalidFormatException` — một `InvalidArgumentException`, nằm
    // NGOÀI hợp đồng `DomainException`/`ValidationException` mà mọi màn hình M4 được dặn bắt.
    expect(fn () => uploadStaffDocument($matter, $lawyer, DocumentGroup::Authority, null, null, 'hôm kia'))
        ->toThrow(ValidationException::class);

    expect(Document::query()->count())->toBe(0);
});

it('ngày ban hành nhận cả chuỗi hợp lệ lẫn đối tượng ngày', function (DateTimeInterface|string $issuedAt) {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);

    $document = uploadStaffDocument($matter, $lawyer, DocumentGroup::Authority, null, null, $issuedAt);

    expect($document->issued_at->toDateString())->toBe('2026-03-01');
})->with([
    'chuỗi ISO' => ['2026-03-01'],
    'đối tượng DateTimeImmutable' => [new DateTimeImmutable('2026-03-01')],
]);

it('ngày ban hành rỗng được hiểu là không có ngày', function (?string $issuedAt) {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);

    expect(uploadStaffDocument($matter, $lawyer, DocumentGroup::Authority, null, null, $issuedAt)->issued_at)
        ->toBeNull();
})->with(['null' => [null], 'chuỗi rỗng' => [''], 'chuỗi toàn khoảng trắng' => ['   ']]);

// ---------------------------------------------------------------------------------------------
// Tên tài liệu phải vừa cột `varchar(250)`. SQLite không bao giờ phàn nàn, MariaDB ở chế độ
// strict thì trả lỗi 500.
// ---------------------------------------------------------------------------------------------

it('tên tài liệu dài quá 250 ký tự là lỗi xác thực, không phải lỗi cơ sở dữ liệu', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);

    // 251 ký tự tiếng Việt có dấu: vừa vượt cột, vừa chứng minh phép đếm là mb_strlen —
    // strlen() sẽ báo chuỗi này dài 502 byte và một ngưỡng đếm byte sẽ chặn oan từ 126 ký tự.
    expect(fn () => uploadStaffDocument($matter, $lawyer, DocumentGroup::Authority, null, null, null, str_repeat('đ', 251)))
        ->toThrow(ValidationException::class);

    expect(Document::query()->count())->toBe(0);
});

it('tên tài liệu đúng 250 ký tự tiếng Việt thì lưu được — cặp dương của phép đếm', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);
    $title = str_repeat('đ', 250);

    expect(uploadStaffDocument($matter, $lawyer, DocumentGroup::Authority, null, null, null, $title)->title)
        ->toBe($title);
});

it('tên tài liệu rỗng bị từ chối', function (string $title) {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);

    expect(fn () => uploadStaffDocument($matter, $lawyer, DocumentGroup::Authority, null, null, null, $title))
        ->toThrow(ValidationException::class);
})->with(['chuỗi rỗng' => [''], 'toàn khoảng trắng' => ['   ']]);

// ---------------------------------------------------------------------------------------------
// Danh mục hồ sơ: một đầu mục đã xoá mềm không nhận tài liệu, và một lần nộp thay khách ở nhóm A
// đóng luôn đầu mục đó.
// ---------------------------------------------------------------------------------------------

it('không gắn được tài liệu vào một đầu mục danh mục đã xoá mềm', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);
    $item = MatterChecklistItem::factory()->create(['matter_id' => $matter->id]);
    $item->delete();

    expect(fn () => uploadStaffDocument($matter, $lawyer, DocumentGroup::ClientProvided, null, $item))
        ->toThrow(ValidationException::class);

    expect(Document::query()->count())->toBe(0);
});

it('nộp thay khách ở nhóm A thì đầu mục danh mục chuyển sang accepted kèm người duyệt', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);
    $item = MatterChecklistItem::factory()->create(['matter_id' => $matter->id]);

    uploadStaffDocument($matter, $lawyer, DocumentGroup::ClientProvided, null, $item);

    $item->refresh();

    expect($item->status)->toBe(ChecklistItemStatus::Accepted)
        ->and($item->reviewed_by)->toBe($lawyer->id)
        ->and($item->reviewed_at)->not->toBeNull();
});

it('nộp thay khách ở nhóm A xoá luôn lý do từ chối cũ của đầu mục', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);
    $item = MatterChecklistItem::factory()->create([
        'matter_id' => $matter->id,
        'status' => ChecklistItemStatus::Rejected,
        'rejection_reason' => 'Ảnh bị mờ ở góc trên nên không đọc được số thửa.',
    ]);

    uploadStaffDocument($matter, $lawyer, DocumentGroup::ClientProvided, null, $item);

    expect($item->refresh()->status)->toBe(ChecklistItemStatus::Accepted)
        ->and($item->rejection_reason)->toBeNull();
});

it('nộp tệp nhóm B, C hoặc D vào một đầu mục KHÔNG đụng tới trạng thái đầu mục', function (DocumentGroup $group) {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);
    $item = MatterChecklistItem::factory()->create(['matter_id' => $matter->id]);

    uploadStaffDocument($matter, $lawyer, $group, null, $item);

    expect($item->refresh()->status)->toBe(ChecklistItemStatus::Missing)
        ->and($item->reviewed_by)->toBeNull();
})->with([
    'nhóm B' => [DocumentGroup::Issued],
    'nhóm C' => [DocumentGroup::Authority],
    'nhóm D' => [DocumentGroup::Internal],
]);

it('ghi uploader là actor tường minh, không phải phiên đăng nhập', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = staffUploadMatter($lawyer);

    // `documents` không có cột created_by/updated_by (xem migration), nên `uploader_*` LÀ dấu vết
    // duy nhất trong bảng về người đưa tệp vào. Phiên `web` cố ý thuộc về admin để chứng minh nó
    // không phải nguồn của giá trị này.
    $this->actingAs($admin, 'web');

    $document = uploadStaffDocument($matter, $lawyer, DocumentGroup::Authority);

    expect($document->uploader_id)->toBe($lawyer->id)
        ->and($document->uploader_type)->toBe($lawyer->getMorphClass());
});

// ---------------------------------------------------------------------------------------------
// SPEC §6.6 bước 7: chuỗi version của một đầu mục danh mục. Nhóm A nghĩa là "khách cung cấp" bất
// kể ai bấm nút tải lên (SPEC §4.11), nên một lần nhân viên nộp thay nằm TRONG chuỗi đó — phía
// GHI phải đồng ý với phía ĐỌC (`StoresDocumentFile::nextInSubmissionChain()`).
// ---------------------------------------------------------------------------------------------

/** Một vụ việc thuộc đúng khách hàng có tài khoản portal, để khách nộp được vào danh mục của nó. */
function staffUploadClientMatter(User $owner, ClientUser $clientUser): Matter
{
    return Matter::factory()->create([
        'client_id' => $clientUser->client_id,
        'lead_lawyer_id' => $owner->id,
    ]);
}

function staffUploadClientSubmission(MatterChecklistItem $item, ClientUser $clientUser, string $name): Document
{
    return app(SubmitClientDocument::class)->handle(
        checklistItem: $item,
        actor: $clientUser,
        file: staffUploadPdf($name),
    );
}

it('nhân viên nộp thay ở nhóm A nối tiếp chuỗi version của khách chứ không cấp lại số 1', function () {
    // Hại cụ thể nếu phía ghi hardcode `version = 1`: trên MỘT đầu mục có hai dòng nhóm A cùng
    // mang số 1, và kể từ giây đó `document_submitted{version:1}` với `document_published
    // {version:1}` chỉ vào hai tài liệu khác nhau. Không nhật ký nào đọc lại được nữa.
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $clientUser = ClientUser::factory()->create();
    $matter = staffUploadClientMatter($lawyer, $clientUser);
    $item = MatterChecklistItem::factory()->create(['matter_id' => $matter->id]);

    staffUploadClientSubmission($item, $clientUser, 'lan-1.pdf');
    $second = staffUploadClientSubmission($item->fresh(), $clientUser, 'lan-2.pdf');

    // Văn phòng nhận bản sao có chứng thực tận tay và nộp thay vào đúng đầu mục đó.
    $byStaff = uploadStaffDocument(
        $matter,
        $lawyer,
        DocumentGroup::ClientProvided,
        staffUploadPdf('ban-chung-thuc.pdf'),
        $item->fresh(),
    );

    expect($byStaff->version)->toBe(3)
        ->and($byStaff->parent_document_id)->toBe($second->id)
        ->and(Document::query()->where('matter_checklist_item_id', $item->id)->pluck('version')->all())
        ->toBe([1, 2, 3]);
});

it('nhân viên nộp thay ở nhóm A vào một đầu mục chưa có gì là version 1 không bản cha', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);
    $item = MatterChecklistItem::factory()->create(['matter_id' => $matter->id]);

    $document = uploadStaffDocument($matter, $lawyer, DocumentGroup::ClientProvided, null, $item);

    expect($document->version)->toBe(1)
        ->and($document->parent_document_id)->toBeNull();
});

it('nhóm B, C và D gắn vào một đầu mục đã có chuỗi nhóm A vẫn là version 1 không bản cha', function (DocumentGroup $group) {
    // Cặp âm: chuỗi ở bước 7 là chuỗi các lần nộp CÙNG MỘT TỜ GIẤY. Một bản đơn văn phòng soạn
    // hay một ghi chú nội bộ gắn vào cùng đầu mục không phải lần nộp tiếp theo của tờ giấy đó.
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $clientUser = ClientUser::factory()->create();
    $matter = staffUploadClientMatter($lawyer, $clientUser);
    $item = MatterChecklistItem::factory()->create(['matter_id' => $matter->id]);

    staffUploadClientSubmission($item, $clientUser, 'lan-1.pdf');

    $document = uploadStaffDocument($matter, $lawyer, $group, null, $item->fresh());

    expect($document->version)->toBe(1)
        ->and($document->parent_document_id)->toBeNull();
})->with([
    'nhóm B' => [DocumentGroup::Issued],
    'nhóm C' => [DocumentGroup::Authority],
    'nhóm D' => [DocumentGroup::Internal],
]);

it('tài liệu nhóm A không gắn vào đầu mục nào luôn là version 1: không có chuỗi để nối', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);

    $first = uploadStaffDocument($matter, $lawyer, DocumentGroup::ClientProvided);
    $second = uploadStaffDocument($matter, $lawyer, DocumentGroup::ClientProvided);

    expect([$first->version, $second->version])->toBe([1, 1])
        ->and($second->parent_document_id)->toBeNull();
});

it('chuỗi version chỉ đọc trong cùng một hồ sơ', function () {
    // `documents.matter_id` không bị ràng buộc phải khớp `matter_checklist_items.matter_id`
    // (chính bước 2 của Action tồn tại vì khoảng trống đó), nên một dòng đã hỏng theo cách ấy
    // không được phép kéo theo số version của một hồ sơ khác.
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = staffUploadMatter($lawyer);
    $other = staffUploadMatter($lawyer);
    $item = MatterChecklistItem::factory()->create(['matter_id' => $matter->id]);

    Document::factory()->for($other)->group(DocumentGroup::ClientProvided)->create([
        'matter_checklist_item_id' => $item->id,
        'version' => 9,
    ]);

    expect(uploadStaffDocument($matter, $lawyer, DocumentGroup::ClientProvided, null, $item)->version)
        ->toBe(1);
});
