<?php

use App\Actions\Document\UploadStaffDocument;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Exceptions\FileRejected;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Support\Files\VirusScanner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
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
): Document {
    return app(UploadStaffDocument::class)->handle(
        matter: $matter,
        actor: $actor,
        file: $file ?? staffUploadPdf(),
        group: $group,
        title: 'Tài liệu thử nghiệm',
        checklistItem: $checklistItem,
        issuedAt: null,
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
