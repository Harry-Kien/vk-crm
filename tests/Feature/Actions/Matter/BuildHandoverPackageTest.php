<?php

use App\Actions\Document\PublishDocument;
use App\Actions\Matter\BuildHandoverPackage;
use App\Actions\Matter\ReassignMatter;
use App\Actions\Matter\RenderHandoverIndex;
use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\HandoverPackageStatus;
use App\Enums\Role;
use App\Events\DocumentPublished;
use App\Exceptions\HandoverPackageFailed;
use App\Jobs\SendHandoverPackageReady;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\MatterChecklistItem;
use App\Models\StageLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Support\PdfText;

/**
 * `BuildHandoverPackage` (M7 Task 4, R1/R2/R3/R8). Mọi khẳng định về nội dung gói đọc lại TỆP THẬT
 * đã sinh — mở zip bằng `ZipArchive`, liệt kê mọi entry, trích chữ từ `MUC-LUC.pdf` — chứ không
 * khẳng định trên mảng mà mã vừa dựng trước khi nén (R2: đầu vào của hàm nén không nhìn thấy lỗi
 * của hàm nén).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Cùng lý do với tests/Feature/Http/DocumentDownloadTest.php: mỗi test một cây thư mục riêng.
    config(['media-library.prefix' => 'test-'.Str::random(16)]);

    Queue::fake();

    // Thư mục tạm RIÊNG của mỗi test: bộ test chạy song song, nên một thư mục dùng chung sẽ có lúc
    // chứa thư mục làm việc của một tiến trình khác đang dựng gói giữa chừng.
    $this->workRoot = storage_path('framework/testing/handover-'.Str::random(16));
    config(['vkcrm.handover.work_dir' => $this->workRoot]);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Nguyễn Thị Hương']);
    $this->client = Client::factory()->create(['name' => 'Đặng Văn Ưu']);
    $this->matter = Matter::factory()->for($this->client)->create([
        'lead_lawyer_id' => $this->lawyer->id,
        'title' => 'Tranh chấp quyền sử dụng đất ở Đà Nẵng',
        'closed_at' => now()->subDay()->toDateString(),
    ]);

    $this->archive = MatterArchive::factory()->create([
        'matter_id' => $this->matter->id,
        'archived_by' => $this->lawyer->id,
        'handover_status' => HandoverPackageStatus::Generating,
        'handover_requested_at' => now()->startOfSecond(),
        'handover_requested_by' => $this->lawyer->id,
    ]);

    $this->token = $this->archive->fresh()->handover_requested_at->getTimestamp();
});

afterEach(fn () => File::deleteDirectory($this->workRoot));

/**
 * Tài liệu có tệp thật (nội dung tự đặt để tìm lại trong zip).
 */
function hpDocument(
    Matter $matter,
    DocumentGroup $group,
    DocumentStatus $status,
    string $title,
    string $content = 'noi dung',
    string $fileName = 'tep.pdf',
    array $attributes = [],
): Document {
    $document = Document::factory()->create([
        'matter_id' => $matter->id,
        'group' => $group,
        'status' => $status,
        'title' => $title,
        ...$attributes,
    ]);

    $document->addMediaFromString($content)
        ->usingName($title)
        ->usingFileName(Str::lower((string) Str::ulid()).'-'.$fileName)
        ->toMediaCollection('file');

    return $document->refresh();
}

/** Đầu mục đã được chấp nhận, kèm các tệp của MỘT lần nộp ở `$version`. */
function hpItem(Matter $matter, ChecklistItemStatus $status, string $name = 'Bản sao CCCD'): MatterChecklistItem
{
    return MatterChecklistItem::factory()->status($status)->create(['matter_id' => $matter->id, 'name' => $name]);
}

function hpSubmission(MatterChecklistItem $item, int $version, string $content, ?Document $parent = null, string $file = 'a.pdf'): Document
{
    return hpDocument(
        $item->matter,
        DocumentGroup::ClientProvided,
        DocumentStatus::Published,
        $item->name,
        $content,
        $file,
        ['matter_checklist_item_id' => $item->id, 'version' => $version, 'parent_document_id' => $parent?->id],
    );
}

/** Đặt dòng lưu trữ về generating với một dấu yêu cầu MỚI — mô phỏng người bấm sinh lại. */
function hpRequestAgain(object $test, int $seconds): void
{
    MatterArchive::query()->whereKey($test->archive->id)->update([
        'handover_status' => HandoverPackageStatus::Generating->value,
        'handover_requested_at' => now()->addSeconds($seconds)->startOfSecond(),
    ]);

    $test->token = $test->archive->fresh()->handover_requested_at->getTimestamp();
}

function hpBuild(object $test): ?Document
{
    return app(BuildHandoverPackage::class)->handle($test->matter->id, $test->token);
}

function hpZipPath(Document $document): string
{
    return $document->getFirstMedia('file')->getPath();
}

/** @return list<string> */
function hpZipNames(Document $document): array
{
    $zip = new ZipArchive;
    expect($zip->open(hpZipPath($document), ZipArchive::RDONLY))->toBeTrue();

    $names = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }

    $zip->close();
    sort($names);

    return $names;
}

function hpZipEntry(Document $document, string $name): string
{
    $zip = new ZipArchive;
    $zip->open(hpZipPath($document), ZipArchive::RDONLY);
    $content = $zip->getFromName($name);
    $zip->close();

    expect($content)->not->toBeFalse();

    return $content;
}

function hpIndexText(Document $document): string
{
    return PdfText::extract(hpZipEntry($document, 'MUC-LUC.pdf'));
}

/**
 * Đọc bit 11 (UTF-8) của general purpose flag trong central directory của zip, bằng byte thô —
 * không qua `ZipArchive`, thứ tự đoán tên theo codepage riêng của nó.
 *
 * @return array<string, bool> tên entry (byte thô) => bit 11 có bật
 */
function hpUtf8Flags(string $zipPath): array
{
    $bytes = file_get_contents($zipPath);
    $end = strrpos($bytes, "PK\x05\x06");
    $directoryOffset = unpack('V', substr($bytes, $end + 16, 4))[1];
    $count = unpack('v', substr($bytes, $end + 10, 2))[1];

    $flags = [];
    $offset = $directoryOffset;

    for ($i = 0; $i < $count; $i++) {
        expect(substr($bytes, $offset, 4))->toBe("PK\x01\x02");

        $flag = unpack('v', substr($bytes, $offset + 8, 2))[1];
        [$nameLength, $extraLength, $commentLength] = array_values(unpack('v3', substr($bytes, $offset + 28, 6)));
        $name = substr($bytes, $offset + 46, $nameLength);

        $flags[$name] = ($flag & 0x0800) !== 0;
        $offset += 46 + $nameLength + $extraLength + $commentLength;
    }

    return $flags;
}

function hpWorkDirectoryIsEmpty(): bool
{
    $directory = config('vkcrm.handover.work_dir');

    return ! is_dir($directory) || count(File::directories($directory)) === 0;
}

// ---------------------------------------------------------------------------------------------
// R1: gói là một Document nhóm B signed_filed, tệp trên đĩa private, qua medialibrary.
// ---------------------------------------------------------------------------------------------

it('tạo gói là một Document nhóm B ở signed_filed, chưa công bố, tệp trên đĩa private', function () {
    hpDocument($this->matter, DocumentGroup::Issued, DocumentStatus::SignedFiled, 'Đơn khởi kiện');

    $document = hpBuild($this);

    expect($document)->toBeInstanceOf(Document::class)
        ->and($document->group)->toBe(DocumentGroup::Issued)
        ->and($document->status)->toBe(DocumentStatus::SignedFiled)
        ->and($document->client_can_view)->toBeFalse()
        ->and($document->client_can_download)->toBeFalse()
        ->and($document->published_at)->toBeNull()
        ->and($document->version)->toBe(1)
        ->and($document->parent_document_id)->toBeNull()
        ->and($document->matter_id)->toBe($this->matter->id)
        ->and($document->title)->toBe('Gói bàn giao hồ sơ '.$this->matter->code);

    $media = $document->getFirstMedia('file');

    expect($media->disk)->toBe('private')
        ->and($media->collection_name)->toBe('file')
        ->and($media->name)->toBe('goi-ban-giao-'.$this->matter->code.'.zip')
        ->and(Storage::disk('private')->exists($media->getPathRelativeToRoot()))->toBeTrue();

    $archive = $this->archive->fresh();

    expect($archive->handover_document_id)->toBe($document->id)
        ->and($archive->handover_status)->toBe(HandoverPackageStatus::Ready)
        ->and($archive->handover_generated_at)->not->toBeNull()
        ->and($archive->handover_error)->toBeNull();

    // Đã dựng trong thư mục tạm cấu hình được (`vkcrm.handover.work_dir`), và dọn sạch sau đó.
    expect(is_dir($this->workRoot))->toBeTrue()
        ->and(hpWorkDirectoryIsEmpty())->toBeTrue();
});

it('dùng người bấm làm người đứng tên, không có thì luật sư phụ trách', function () {
    $requester = User::factory()->withRole(Role::Manager)->create();
    $this->archive->update(['handover_requested_by' => $requester->id]);

    $document = hpBuild($this);

    expect($document->uploader_id)->toBe($requester->id);

    // Lần yêu cầu tự động (không có người bấm) → luật sư phụ trách, KHÔNG phải người lập bản ghi
    // lưu trữ (người đó chỉ là chỗ dựa cuối cùng).
    $archiver = User::factory()->withRole(Role::Admin)->create();
    hpRequestAgain($this, 5);
    MatterArchive::query()->whereKey($this->archive->id)->update(['handover_requested_by' => null, 'archived_by' => $archiver->id]);

    expect(hpBuild($this)->uploader_id)->toBe($this->lawyer->id);

    // Luật sư phụ trách đã bị xoá mềm (không còn đứng tên được) mà vẫn còn người lập bản ghi lưu trữ.
    hpRequestAgain($this, 10);
    $this->lawyer->delete();

    expect(hpBuild($this)->uploader_id)->toBe($archiver->id);
});

it('không nạp cột internal_note của dòng tiến độ vào bộ nhớ khi dựng mục lục', function () {
    StageLog::factory()->create([
        'matter_id' => $this->matter->id, 'to_stage' => 'accepted',
        'internal_note' => 'DANH-DAU-KHONG-NAP', 'is_published' => true,
    ]);

    $selects = [];
    DB::listen(function ($query) use (&$selects): void {
        if (preg_match('/from ["`]?stage_logs["`]?/i', $query->sql) === 1) {
            $selects[] = $query->sql;
        }
    });

    hpBuild($this);

    expect($selects)->not->toBeEmpty();

    foreach ($selects as $sql) {
        expect($sql)->not->toContain('internal_note')
            ->and($sql)->not->toContain('*');
    }
});

it('không tự công bố: công bố gói đi qua đúng PublishDocument và phát DocumentPublished', function () {
    $document = hpBuild($this);

    Event::fake([DocumentPublished::class]);

    app(PublishDocument::class)->handle(
        $document,
        $this->lawyer,
        clientCanView: true,
        clientCanDownload: true,
        expectedClientCanView: false,
        expectedClientCanDownload: false,
        expectedIsReleased: false,
    );

    $document->refresh();

    expect($document->status)->toBe(DocumentStatus::Published)
        ->and($document->isReleasedToPortal())->toBeTrue();

    Event::assertDispatched(DocumentPublished::class, fn (DocumentPublished $event): bool => $event->document->is($document));
});

// ---------------------------------------------------------------------------------------------
// R2/R8: nội dung — giải nén tệp thật.
// ---------------------------------------------------------------------------------------------

it('giải nén: nhóm D, tài liệu đã xoá mềm và nhóm B còn nháp đều vắng mặt, phần còn lại có mặt', function () {
    // Nhóm B xếp theo ngày ban hành: công văn (ngày sớm hơn) đứng trước đơn khởi kiện.
    hpDocument($this->matter, DocumentGroup::Issued, DocumentStatus::SignedFiled, 'Đơn khởi kiện', 'NOIDUNG-B-KY', attributes: ['issued_at' => '2026-03-01']);
    hpDocument($this->matter, DocumentGroup::Issued, DocumentStatus::Published, 'Công văn trả lời', 'NOIDUNG-B-CONGBO', attributes: ['issued_at' => '2026-02-01']);
    hpDocument($this->matter, DocumentGroup::Authority, DocumentStatus::Published, 'Bản án sơ thẩm', 'NOIDUNG-C');
    // Nhóm D ở MỌI trạng thái, kể cả những trạng thái mà nhóm B/C được đưa vào gói: điều kiện nhóm
    // phải đứng độc lập với điều kiện trạng thái.
    hpDocument($this->matter, DocumentGroup::Internal, DocumentStatus::InternalDraft, 'Chiến lược nội bộ', 'NOIDUNG-D-MAT');
    hpDocument($this->matter, DocumentGroup::Internal, DocumentStatus::SignedFiled, 'Chiến lược đã ký', 'NOIDUNG-D-KY');
    hpDocument($this->matter, DocumentGroup::Internal, DocumentStatus::Published, 'Chiến lược đã công bố', 'NOIDUNG-D-CONGBO');
    hpDocument($this->matter, DocumentGroup::ClientProvided, DocumentStatus::Published, 'Ảnh khách đã xoá', 'NOIDUNG-A-XOA')->delete();
    hpDocument($this->matter, DocumentGroup::Issued, DocumentStatus::InternalDraft, 'Bản nháp đơn', 'NOIDUNG-NHAP');
    hpDocument($this->matter, DocumentGroup::Issued, DocumentStatus::PendingApproval, 'Bản chờ duyệt', 'NOIDUNG-CHODUYET');
    hpDocument($this->matter, DocumentGroup::Issued, DocumentStatus::Published, 'Văn bản đã xoá', 'NOIDUNG-XOA')->delete();
    hpDocument(Matter::factory()->create(), DocumentGroup::Issued, DocumentStatus::Published, 'Của vụ khác', 'NOIDUNG-VUKHAC');

    $document = hpBuild($this);
    $names = hpZipNames($document);

    expect($names)->toBe([
        'B/01-Công văn trả lời.pdf',
        'B/02-Đơn khởi kiện.pdf',
        'C/03-Bản án sơ thẩm.pdf',
        'MUC-LUC.pdf',
    ]);

    // Đọc lại từng entry: không entry nào mang nội dung của nhóm D/nháp/đã xoá.
    $all = implode('|', array_map(fn (string $name): string => hpZipEntry($document, $name), $names));

    foreach (['NOIDUNG-D-MAT', 'NOIDUNG-D-KY', 'NOIDUNG-D-CONGBO', 'NOIDUNG-A-XOA', 'NOIDUNG-NHAP', 'NOIDUNG-CHODUYET', 'NOIDUNG-XOA', 'NOIDUNG-VUKHAC'] as $absent) {
        expect($all)->not->toContain($absent);
    }

    expect(hpZipEntry($document, 'B/02-Đơn khởi kiện.pdf'))->toBe('NOIDUNG-B-KY')
        ->and(hpZipEntry($document, 'C/03-Bản án sơ thẩm.pdf'))->toBe('NOIDUNG-C');

    // Và chuỗi tiêu đề nhóm D không lọt vào cả mục lục.
    expect(PdfText::squash(hpIndexText($document)))->not->toContain(PdfText::squash('Chiến lược nội bộ'))
        ->and(PdfText::squash(hpIndexText($document)))->not->toContain(PdfText::squash('Bản nháp đơn'));
});

it('nhóm A: mọi tệp của version mới nhất đã chấp nhận; bỏ version bị từ chối, bị thay và đầu mục chưa duyệt', function () {
    $accepted = hpItem($this->matter, ChecklistItemStatus::Accepted, 'Bản sao CCCD');
    $v1 = hpSubmission($accepted, 1, 'A-V1-BI-THAY', file: 'mat-truoc.pdf');
    hpSubmission($accepted, 2, 'A-V2-MAT-TRUOC', $v1, 'mat-truoc.pdf');
    hpSubmission($accepted, 2, 'A-V2-MAT-SAU', $v1, 'mat-sau.pdf');

    $pending = hpItem($this->matter, ChecklistItemStatus::PendingReview, 'Sổ hộ khẩu');
    hpSubmission($pending, 1, 'A-CHUA-DUYET');

    $rejected = hpItem($this->matter, ChecklistItemStatus::Rejected, 'Hợp đồng thuê');
    hpSubmission($rejected, 1, 'A-BI-TU-CHOI');

    $notApplicable = hpItem($this->matter, ChecklistItemStatus::NotApplicable, 'Giấy uỷ quyền');
    hpSubmission($notApplicable, 1, 'A-KHONG-AP-DUNG');

    $document = hpBuild($this);
    $names = hpZipNames($document);

    expect($names)->toBe([
        'A/01-Bản sao CCCD.pdf',
        'A/02-Bản sao CCCD.pdf',
        'MUC-LUC.pdf',
    ]);

    $contents = [hpZipEntry($document, $names[0]), hpZipEntry($document, $names[1])];
    sort($contents);

    expect($contents)->toBe(['A-V2-MAT-SAU', 'A-V2-MAT-TRUOC']);
});

it('tài liệu nhóm A nhân sự nộp thay, không gắn đầu mục, vào gói như một bản của khách', function () {
    hpDocument($this->matter, DocumentGroup::ClientProvided, DocumentStatus::Published, 'Ảnh hiện trạng', 'A-KHONG-DAU-MUC');

    expect(hpZipNames(hpBuild($this)))->toBe(['A/01-Ảnh hiện trạng.pdf', 'MUC-LUC.pdf']);
});

it('nhóm A cũng qua danh sách trắng trạng thái: bản đổi nhóm còn nháp và version mới nhất chưa phát hành đều vắng mặt', function () {
    // `RegroupDocument` chỉ đổi `group`, giữ `status`: một bản từ nhóm D sang A vẫn `internal_draft`
    // (khách chưa từng được thấy). Dựng thẳng trạng thái đó — Action đổi nhóm không phải thứ đang test.
    hpDocument($this->matter, DocumentGroup::ClientProvided, DocumentStatus::InternalDraft, 'Bản đổi nhóm chưa phát hành', 'A-NHAP-KHONG-DAU-MUC');
    hpDocument($this->matter, DocumentGroup::ClientProvided, DocumentStatus::Published, 'Ảnh hiện trạng', 'A-CONG-BO-KHONG-DAU-MUC');

    // Đầu mục đã duyệt nhưng version MỚI NHẤT không ở trạng thái phát hành: không lùi về v1 (đã bị thay).
    $item = hpItem($this->matter, ChecklistItemStatus::Accepted, 'Giấy khai sinh');
    $v1 = hpSubmission($item, 1, 'A-V1-DA-BI-THAY');
    hpDocument($this->matter, DocumentGroup::ClientProvided, DocumentStatus::PendingApproval, 'Giấy khai sinh', 'A-V2-CHUA-PHAT-HANH', attributes: [
        'matter_checklist_item_id' => $item->id, 'version' => 2, 'parent_document_id' => $v1->id,
    ]);

    $document = hpBuild($this);

    expect(hpZipNames($document))->toBe(['A/01-Ảnh hiện trạng.pdf', 'MUC-LUC.pdf'])
        ->and(hpZipEntry($document, 'A/01-Ảnh hiện trạng.pdf'))->toBe('A-CONG-BO-KHONG-DAU-MUC');
});

it('không đưa chính gói vào gói sau, ở bất kỳ version nào', function () {
    hpDocument($this->matter, DocumentGroup::Issued, DocumentStatus::SignedFiled, 'Đơn khởi kiện');

    $first = hpBuild($this);

    // Gói version 1 là nhóm B signed_filed — nếu không loại thì nó lọt vào gói sau.
    expect($first->group)->toBe(DocumentGroup::Issued)->and($first->status)->toBe(DocumentStatus::SignedFiled);

    hpRequestAgain($this, 5);

    $second = hpBuild($this);

    expect(hpZipNames($second))->toBe(['B/01-Đơn khởi kiện.pdf', 'MUC-LUC.pdf']);

    hpRequestAgain($this, 10);

    // Version 3: cả v1 lẫn v2 đều không được vào (chuỗi parent_document_id).
    expect(hpZipNames(hpBuild($this)))->toBe(['B/01-Đơn khởi kiện.pdf', 'MUC-LUC.pdf']);
});

// ---------------------------------------------------------------------------------------------
// Tên entry.
// ---------------------------------------------------------------------------------------------

it('hai tài liệu cùng tiêu đề không đè nhau, và tiêu đề chứa ../ không thoát khỏi thư mục nhóm', function () {
    hpDocument($this->matter, DocumentGroup::Issued, DocumentStatus::SignedFiled, 'Đơn khởi kiện', 'BAN-MOT', attributes: ['issued_at' => '2026-01-01']);
    hpDocument($this->matter, DocumentGroup::Issued, DocumentStatus::SignedFiled, 'Đơn khởi kiện', 'BAN-HAI', attributes: ['issued_at' => '2026-01-02']);
    hpDocument($this->matter, DocumentGroup::Issued, DocumentStatus::SignedFiled, '../../etc/passwd', 'BAN-BA', attributes: ['issued_at' => '2026-01-03']);
    hpDocument($this->matter, DocumentGroup::Issued, DocumentStatus::SignedFiled, '..\\..\\windows\\system32', 'BAN-BON', attributes: ['issued_at' => '2026-01-04']);
    hpDocument($this->matter, DocumentGroup::Issued, DocumentStatus::SignedFiled, '..', 'BAN-NAM', attributes: ['issued_at' => '2026-01-05']);

    $document = hpBuild($this);
    $names = hpZipNames($document);

    expect($names)->toHaveCount(6)
        ->and($names)->toContain('B/01-Đơn khởi kiện.pdf')
        ->and($names)->toContain('B/02-Đơn khởi kiện.pdf')
        ->and(hpZipEntry($document, 'B/01-Đơn khởi kiện.pdf'))->toBe('BAN-MOT')
        ->and(hpZipEntry($document, 'B/02-Đơn khởi kiện.pdf'))->toBe('BAN-HAI');

    foreach ($names as $name) {
        expect($name)->toMatch('#^(MUC-LUC\.pdf|B/\d{2}-[^/\\\\]+)$#')
            ->and($name)->not->toContain('../')
            ->and($name)->not->toContain('..\\')
            ->and(str_starts_with($name, '/'))->toBeFalse();
    }
});

it('đệm số thứ tự theo tổng số tài liệu, và tên entry không chứa ký tự Windows cấm', function () {
    foreach (range(1, 10) as $i) {
        hpDocument($this->matter, DocumentGroup::Issued, DocumentStatus::SignedFiled, $i === 10 ? 'Bản án số 12/2024/DS-ST: "kết luận"?' : "Tài liệu $i", "N$i", attributes: ['issued_at' => '2026-01-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
    }

    $names = hpZipNames(hpBuild($this));

    expect($names)->toContain('B/01-Tài liệu 1.pdf')
        ->and($names)->toContain('B/10-Bản án số 12-2024-DS-ST- kết luận-.pdf');
});

it('bật cờ UTF-8 cho tên entry và tên tiếng Việt có dấu còn nguyên khi đọc lại', function () {
    hpDocument($this->matter, DocumentGroup::Issued, DocumentStatus::SignedFiled, 'Đơn khởi kiện ly hôn — Nguyễn Thị Hương', 'X');

    $document = hpBuild($this);
    $flags = hpUtf8Flags(hpZipPath($document));

    expect($flags)->toHaveKey('B/01-Đơn khởi kiện ly hôn — Nguyễn Thị Hương.pdf')
        ->and($flags['B/01-Đơn khởi kiện ly hôn — Nguyễn Thị Hương.pdf'])->toBeTrue()
        ->and(hpZipNames($document))->toContain('B/01-Đơn khởi kiện ly hôn — Nguyễn Thị Hương.pdf');
});

// ---------------------------------------------------------------------------------------------
// R3: MUC-LUC.pdf — chữ có dấu, nội dung, chân trang, không lộ ghi chú nội bộ.
// ---------------------------------------------------------------------------------------------

it('MUC-LUC.pdf trích lại được chữ có dấu đúng như chuỗi gốc', function () {
    hpDocument($this->matter, DocumentGroup::Issued, DocumentStatus::SignedFiled, 'Đơn khởi kiện yêu cầu ly hôn', 'X');

    $text = PdfText::squash(hpIndexText(hpBuild($this)));

    foreach ([
        'MỤC LỤC HỒ SƠ BÀN GIAO',
        'Tranh chấp quyền sử dụng đất ở Đà Nẵng',
        'Đặng Văn Ưu',
        'Luật sư Nguyễn Thị Hương',
        'Đơn khởi kiện yêu cầu ly hôn',
        'B/01-Đơn khởi kiện yêu cầu ly hôn.pdf',
        'Văn bản đã phát hành',
        $this->matter->code,
    ] as $expected) {
        expect($text)->toContain(PdfText::squash($expected));
    }
});

it('MUC-LUC.pdf nhúng font DejaVu, không rơi về font mặc định mất dấu', function () {
    $pdf = hpZipEntry(hpBuild($this), 'MUC-LUC.pdf');

    expect($pdf)->toStartWith('%PDF')
        ->and($pdf)->toContain('DejaVuSans')
        ->and($pdf)->not->toContain('/BaseFont /Helvetica');
});

it('danh sách tài liệu trong mục lục khớp số thứ tự và tên entry của zip', function () {
    hpDocument($this->matter, DocumentGroup::Issued, DocumentStatus::SignedFiled, 'Đơn khởi kiện', 'X', attributes: ['issued_at' => '2026-01-01']);
    hpDocument($this->matter, DocumentGroup::Authority, DocumentStatus::Published, 'Thông báo thụ lý', 'Y');

    $document = hpBuild($this);
    $text = PdfText::squash(hpIndexText($document));

    foreach (hpZipNames($document) as $name) {
        if ($name !== 'MUC-LUC.pdf') {
            expect($text)->toContain(PdfText::squash($name));
        }
    }
});

it('mục lục gồm TOÀN BỘ dòng tiến độ đã công bố và không gì khác', function () {
    StageLog::factory()->create([
        'matter_id' => $this->matter->id, 'occurred_at' => '2026-01-10 09:00:00',
        'to_stage' => 'accepted', 'public_content' => 'Toà đã thụ lý đơn khởi kiện của anh Ưu.',
        'next_step' => 'Chờ giấy triệu tập.', 'client_action' => 'Mang bản gốc CCCD.',
        'internal_note' => 'GHI-CHU-NOI-BO-MOT', 'is_published' => true,
    ]);
    StageLog::factory()->create([
        'matter_id' => $this->matter->id, 'occurred_at' => '2026-02-10 09:00:00',
        'to_stage' => 'closed_won', 'public_content' => 'Vụ việc đã kết thúc thắng lợi.',
        'next_step' => null, 'client_action' => null,
        'internal_note' => 'GHI-CHU-NOI-BO-HAI', 'is_published' => true,
    ]);
    StageLog::factory()->internalOnly()->create([
        'matter_id' => $this->matter->id, 'occurred_at' => '2026-01-20 09:00:00',
        'public_content' => 'CHUA-CONG-BO-KHONG-DUOC-IN', 'internal_note' => 'GHI-CHU-NOI-BO-BA',
    ]);

    $document = hpBuild($this);
    $text = PdfText::squash(hpIndexText($document));

    foreach (['Toà đã thụ lý đơn khởi kiện của anh Ưu.', 'Chờ giấy triệu tập.', 'Mang bản gốc CCCD.', 'Vụ việc đã kết thúc thắng lợi.', '10/01/2026', '10/02/2026'] as $expected) {
        expect($text)->toContain(PdfText::squash($expected));
    }

    // Cũ trước, mới sau.
    expect(strpos($text, PdfText::squash('Toà đã thụ lý')))->toBeLessThan(strpos($text, PdfText::squash('Vụ việc đã kết thúc thắng lợi')));

    foreach (['CHUA-CONG-BO-KHONG-DUOC-IN', 'GHI-CHU-NOI-BO-MOT', 'GHI-CHU-NOI-BO-HAI', 'GHI-CHU-NOI-BO-BA'] as $absent) {
        expect($text)->not->toContain($absent);
    }
});

it('chuỗi đánh dấu trong internal_note và trong dòng bàn giao nội bộ của ReassignMatter không lọt vào PDF lẫn zip', function () {
    // Dòng tiến độ ĐÃ công bố mang internal_note có chuỗi đánh dấu.
    StageLog::factory()->create([
        'matter_id' => $this->matter->id, 'to_stage' => 'accepted',
        'public_content' => 'Nội dung công khai hợp lệ.', 'internal_note' => 'DANH-DAU-INTERNAL-NOTE-42', 'is_published' => true,
    ]);

    // Dòng bàn giao nội bộ do ReassignMatter thật sự ghi (SPEC §6.11 bước 2). Vụ phải còn mở
    // lúc bàn giao — dựng vụ thứ hai chưa đóng rồi gán vào cùng chuỗi kiểm tra.
    $admin = User::factory()->withRole(Role::Admin)->create();
    $newLead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Mới']);
    $open = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lawyer->id]);

    app(ReassignMatter::class)->handle($open, $admin, $newLead, 'DANH-DAU-LY-DO-BAN-GIAO-77', true, sendDigest: false);

    // Bàn giao xong mới đóng vụ, và dùng vụ đó làm vụ sinh gói.
    $open->update(['closed_at' => now()->subDay()->toDateString()]);
    MatterArchive::factory()->create([
        'matter_id' => $open->id, 'archived_by' => $admin->id,
        'handover_status' => HandoverPackageStatus::Generating, 'handover_requested_at' => now()->startOfSecond(),
    ]);
    $token = MatterArchive::query()->where('matter_id', $open->id)->firstOrFail()->handover_requested_at->getTimestamp();

    // Điều kiện tiên quyết của test: dòng nội bộ có thật và mang chuỗi đánh dấu.
    expect(StageLog::query()->where('matter_id', $open->id)->where('is_published', false)->value('internal_note'))
        ->toContain('DANH-DAU-LY-DO-BAN-GIAO-77');

    // Cả hai vụ: gói của vụ hiện tại VÀ gói của vụ đã bàn giao.
    $packages = [
        app(BuildHandoverPackage::class)->handle($open->id, $token),
        hpBuild($this),
    ];

    foreach ($packages as $package) {
        $text = PdfText::extract(hpZipEntry($package, 'MUC-LUC.pdf'));
        $zipBytes = file_get_contents(hpZipPath($package));

        foreach (['DANH-DAU-INTERNAL-NOTE-42', 'DANH-DAU-LY-DO-BAN-GIAO-77'] as $marker) {
            expect($text)->not->toContain($marker)
                ->and(PdfText::squash($text))->not->toContain($marker)
                ->and($zipBytes)->not->toContain($marker);

            foreach (hpZipNames($package) as $name) {
                expect(hpZipEntry($package, $name))->not->toContain($marker);
            }
        }
    }

    // Đối chứng: nội dung công khai CÓ mặt — bộ trích không rỗng.
    expect(PdfText::squash(PdfText::extract(hpZipEntry($packages[1], 'MUC-LUC.pdf'))))
        ->toContain(PdfText::squash('Nội dung công khai hợp lệ.'));
});

it('chân trang in bốn thông tin pháp lý khi có, và bỏ hẳn dòng lẫn nhãn khi còn trống', function () {
    config(['vkcrm.brand.office_address' => null, 'vkcrm.brand.tax_code' => null,
        'vkcrm.brand.bar_association' => null, 'vkcrm.brand.licence_number' => null]);

    $empty = PdfText::squash(hpIndexText(hpBuild($this)));

    foreach (['Mã số thuế', 'Đoàn Luật sư', 'Giấy đăng ký hoạt động'] as $label) {
        expect($empty)->not->toContain(PdfText::squash($label));
    }

    expect($empty)->toContain(PdfText::squash('Công ty Luật TNHH Vũ Khang'));

    hpRequestAgain($this, 5);

    config(['vkcrm.brand.office_address' => '12 Nguyễn Huệ, Quận 1', 'vkcrm.brand.tax_code' => '0312345678',
        'vkcrm.brand.bar_association' => 'Đoàn Luật sư TP. Hồ Chí Minh', 'vkcrm.brand.licence_number' => '41.01/TP-ĐKHĐ']);

    $full = PdfText::squash(hpIndexText(hpBuild($this)));

    foreach (['12 Nguyễn Huệ, Quận 1', 'Mã số thuế: 0312345678', 'Đoàn Luật sư: Đoàn Luật sư TP. Hồ Chí Minh', 'Giấy đăng ký hoạt động số: 41.01/TP-ĐKHĐ'] as $line) {
        expect($full)->toContain(PdfText::squash($line));
    }
});

it('vụ không có tài liệu nào vẫn sinh được gói chỉ gồm mục lục, và nói rõ là chưa có tài liệu', function () {
    $document = hpBuild($this);

    expect(hpZipNames($document))->toBe(['MUC-LUC.pdf'])
        ->and(PdfText::squash(hpIndexText($document)))->toContain(PdfText::squash('Hồ sơ chưa có tài liệu nào đủ điều kiện đưa vào gói.'))
        ->and(PdfText::squash(hpIndexText($document)))->toContain(PdfText::squash('Hồ sơ chưa có cập nhật tiến độ nào được công bố.'));
});

// ---------------------------------------------------------------------------------------------
// Audit.
// ---------------------------------------------------------------------------------------------

it('ghi data_exported khi sinh gói, kèm vụ, phiên bản và số tệp', function () {
    hpDocument($this->matter, DocumentGroup::Issued, DocumentStatus::SignedFiled, 'Đơn khởi kiện');

    $document = hpBuild($this);

    $log = Activity::query()->where('event', 'data_exported')->sole();

    expect($log->subject_id)->toBe($document->id)
        ->and($log->properties['matter_id'])->toBe($this->matter->id)
        ->and($log->properties['client_id'])->toBe($this->client->id)
        ->and($log->properties['kind'])->toBe('handover_package')
        ->and($log->properties['action'])->toBe('generated')
        ->and($log->properties['version'])->toBe(1)
        ->and($log->properties['files'])->toBe(1)
        ->and($log->causer_id)->toBe($this->lawyer->id);
});

// ---------------------------------------------------------------------------------------------
// Báo kết quả.
// ---------------------------------------------------------------------------------------------

it('xếp hàng việc báo luật sư sau khi gói sinh xong', function () {
    $document = hpBuild($this);

    Queue::assertPushed(SendHandoverPackageReady::class, fn (SendHandoverPackageReady $job): bool => $job->matterId === $this->matter->id
        && $job->documentId === $document->id);
});

// ---------------------------------------------------------------------------------------------
// Sinh lại = version mới của cùng tài liệu.
// ---------------------------------------------------------------------------------------------

it('sinh lại là version mới của cùng tài liệu; tệp cũ bị xoá, dòng tài liệu và lượt tải cũ giữ nguyên', function () {
    $first = hpBuild($this);
    $firstMedia = $first->getFirstMedia('file');
    $firstPath = $firstMedia->getPathRelativeToRoot();

    DocumentDownload::factory()->create(['document_id' => $first->id]);

    hpRequestAgain($this, 5);

    $second = hpBuild($this);

    expect($second->id)->not->toBe($first->id)
        ->and($second->version)->toBe(2)
        ->and($second->parent_document_id)->toBe($first->id)
        ->and($this->archive->fresh()->handover_document_id)->toBe($second->id)
        // Tệp cũ đã bị xoá, tệp mới còn.
        ->and(Storage::disk('private')->exists($firstPath))->toBeFalse()
        ->and(Media::query()->where('model_id', $first->id)->where('model_type', $first->getMorphClass())->count())->toBe(0)
        ->and(Storage::disk('private')->exists($second->getFirstMedia('file')->getPathRelativeToRoot()))->toBeTrue()
        // Dòng tài liệu cũ và lượt tải của nó còn nguyên.
        ->and(Document::query()->whereKey($first->id)->exists())->toBeTrue()
        ->and(DocumentDownload::query()->where('document_id', $first->id)->count())->toBe(1);
});

it('gỡ version cũ khỏi cổng khách khi nó đang được công bố, vì tệp của nó đã bị xoá', function () {
    $first = hpBuild($this);
    $first->update(['status' => DocumentStatus::Published, 'client_can_view' => true, 'client_can_download' => true, 'published_at' => now()]);

    expect($first->refresh()->isReleasedToPortal())->toBeTrue();

    hpRequestAgain($this, 5);

    $second = hpBuild($this);

    $first->refresh();

    expect($first->isReleasedToPortal())->toBeFalse()
        ->and($first->status)->toBe(DocumentStatus::SignedFiled)
        ->and($first->client_can_view)->toBeFalse()
        ->and($first->client_can_download)->toBeFalse()
        // Version mới KHÔNG tự công bố.
        ->and($second->refresh()->isReleasedToPortal())->toBeFalse();
});

// ---------------------------------------------------------------------------------------------
// Dấu của lần yêu cầu.
// ---------------------------------------------------------------------------------------------

it('một job cũ (dấu yêu cầu không còn khớp) thoát lặng lẽ, không tạo gì', function () {
    $documents = Document::query()->count();

    expect(app(BuildHandoverPackage::class)->handle($this->matter->id, $this->token - 30))->toBeNull()
        ->and(Document::query()->count())->toBe($documents)
        ->and($this->archive->fresh()->handover_status)->toBe(HandoverPackageStatus::Generating)
        ->and(hpWorkDirectoryIsEmpty())->toBeTrue();
});

it('không sinh khi dòng lưu trữ không còn ở trạng thái generating', function () {
    $this->archive->update(['handover_status' => HandoverPackageStatus::Ready]);

    expect(hpBuild($this))->toBeNull()
        ->and(Document::query()->where('title', 'like', 'Gói bàn giao%')->count())->toBe(0);
});

it('vụ mở lại trong lúc job chờ hàng thì job ném lỗi có tên, không tạo gói — và không tốn công dựng PDF', function () {
    $this->matter->update(['closed_at' => null]);

    // Kiểm tra SỚM (trước khi dựng gì): một vụ đã mở lại không đáng một lượt dựng PDF + nén zip.
    $renders = new ArrayObject;
    $this->app->bind(RenderHandoverIndex::class, fn () => new class($renders) extends RenderHandoverIndex
    {
        public function __construct(private ArrayObject $renders) {}

        public function handle(Matter $matter, Collection $entries): string
        {
            $this->renders[] = $matter->id;

            return parent::handle($matter, $entries);
        }
    });

    expect(fn () => hpBuild($this))->toThrow(HandoverPackageFailed::class, 'chưa kết thúc')
        ->and($renders)->toHaveCount(0)
        ->and(Document::query()->where('title', 'like', 'Gói bàn giao%')->count())->toBe(0);
});

it('vụ bị mở lại TRONG LÚC đang dựng gói: kiểm tra lại dưới khoá ném lỗi có tên, không tạo tài liệu, không để tệp', function () {
    hpDocument($this->matter, DocumentGroup::Issued, DocumentStatus::SignedFiled, 'Đơn khởi kiện');

    $filesBefore = Storage::disk('private')->allFiles();

    // Mở lại vụ đúng lúc gói đang được dựng (sau kiểm tra sớm, trước transaction lưu).
    $this->app->bind(RenderHandoverIndex::class, fn () => new class extends RenderHandoverIndex
    {
        public function handle(Matter $matter, Collection $entries): string
        {
            Matter::query()->whereKey($matter->id)->update(['closed_at' => null]);

            return parent::handle($matter, $entries);
        }
    });

    expect(fn () => hpBuild($this))->toThrow(HandoverPackageFailed::class, 'chưa kết thúc')
        ->and(Document::query()->where('title', 'like', 'Gói bàn giao%')->count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe($filesBefore)
        ->and($this->archive->fresh()->handover_document_id)->toBeNull()
        ->and(hpWorkDirectoryIsEmpty())->toBeTrue();
});

// ---------------------------------------------------------------------------------------------
// Nguyên tử: lỗi giữa chừng không để lại tệp dở dang hay version rác.
// ---------------------------------------------------------------------------------------------

it('thiếu tệp nguồn trên đĩa: ném lỗi có tên, không tạo tài liệu, không để lại tệp nào', function () {
    $broken = hpDocument($this->matter, DocumentGroup::Issued, DocumentStatus::SignedFiled, 'Đơn khởi kiện bị mất tệp');
    $before = Storage::disk('private')->allFiles();

    Storage::disk('private')->delete($broken->getFirstMedia('file')->getPathRelativeToRoot());

    $filesAfterDelete = Storage::disk('private')->allFiles();

    expect(fn () => hpBuild($this))->toThrow(HandoverPackageFailed::class, 'Đơn khởi kiện bị mất tệp')
        ->and(Document::query()->where('title', 'like', 'Gói bàn giao%')->count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe($filesAfterDelete)
        ->and($before)->not->toBe($filesAfterDelete)
        ->and($this->archive->fresh()->handover_document_id)->toBeNull()
        ->and($this->archive->fresh()->handover_status)->toBe(HandoverPackageStatus::Generating)
        ->and(hpWorkDirectoryIsEmpty())->toBeTrue();
});

it('không dựng được mục lục: ném lỗi có tên, không để lại gì', function () {
    $this->app->bind(RenderHandoverIndex::class, fn () => new class extends RenderHandoverIndex
    {
        public function handle(Matter $matter, Collection $entries): string
        {
            throw HandoverPackageFailed::indexFailed();
        }
    });

    $before = Storage::disk('private')->allFiles();

    expect(fn () => hpBuild($this))->toThrow(HandoverPackageFailed::class)
        ->and(Storage::disk('private')->allFiles())->toBe($before)
        ->and(Document::query()->where('title', 'like', 'Gói bàn giao%')->count())->toBe(0)
        ->and(hpWorkDirectoryIsEmpty())->toBeTrue();
});

it('lỗi SAU khi tệp đã được gắn vào medialibrary: rollback, và tệp mồ côi bị xoá khỏi đĩa private', function () {
    hpDocument($this->matter, DocumentGroup::Issued, DocumentStatus::SignedFiled, 'Đơn khởi kiện');

    $filesBefore = Storage::disk('private')->allFiles();
    $mediaBefore = Media::query()->count();
    $documentsBefore = Document::query()->count();

    // `matter_archives` được cập nhật SAU khi `addMedia()` đã copy tệp zip vào đĩa.
    Event::listen('eloquent.updating: '.MatterArchive::class, function (): void {
        throw new RuntimeException('hỏng giữa chừng');
    });

    try {
        expect(fn () => hpBuild($this))->toThrow(RuntimeException::class, 'hỏng giữa chừng');
    } finally {
        Event::forget('eloquent.updating: '.MatterArchive::class);
    }

    expect(Storage::disk('private')->allFiles())->toBe($filesBefore)
        ->and(Media::query()->count())->toBe($mediaBefore)
        ->and(Document::query()->count())->toBe($documentsBefore)
        ->and($this->archive->fresh()->handover_document_id)->toBeNull()
        ->and($this->archive->fresh()->handover_status)->toBe(HandoverPackageStatus::Generating)
        ->and(hpWorkDirectoryIsEmpty())->toBeTrue();

    // Bấm sinh lại được: cùng dấu yêu cầu, lần này không hỏng.
    expect(hpBuild($this))->toBeInstanceOf(Document::class);
});

it('lỗi khi sinh lại không đụng tới gói version trước', function () {
    $first = hpBuild($this);
    $firstPath = $first->getFirstMedia('file')->getPathRelativeToRoot();

    hpRequestAgain($this, 5);

    Event::listen('eloquent.updating: '.MatterArchive::class, function (): void {
        throw new RuntimeException('hỏng giữa chừng');
    });

    try {
        expect(fn () => hpBuild($this))->toThrow(RuntimeException::class);
    } finally {
        Event::forget('eloquent.updating: '.MatterArchive::class);
    }

    expect(Storage::disk('private')->exists($firstPath))->toBeTrue()
        ->and($this->archive->fresh()->handover_document_id)->toBe($first->id)
        ->and(Document::query()->where('parent_document_id', $first->id)->count())->toBe(0);
});

/**
 * Một tiến trình bị GIẾT giữa chừng (hết `$timeout` của job, hết bộ nhớ, máy chủ cắt CPU) không
 * chạy tới `finally`. Mô phỏng đúng thứ nó để lại: thư mục tạm của lần yêu cầu đó, với một zip dở.
 */
function hpLeftoverFromKilledRun(int $matterId, int $requestedAt): string
{
    $directory = BuildHandoverPackage::workDirectory($matterId, $requestedAt);

    File::ensureDirectoryExists($directory);
    File::put($directory.DIRECTORY_SEPARATOR.'package.zip', str_repeat('ZIP-DO-DANG', 1000));

    return $directory;
}

it('lần chạy lại của CÙNG yêu cầu sau một tiến trình bị giết giữa chừng dọn thư mục tạm nó để lại', function () {
    hpDocument($this->matter, DocumentGroup::Issued, DocumentStatus::SignedFiled, 'Đơn khởi kiện');

    $leftover = hpLeftoverFromKilledRun($this->matter->id, $this->token);

    $document = hpBuild($this);

    expect($document)->toBeInstanceOf(Document::class)
        ->and(hpZipNames($document))->toBe(['B/01-Đơn khởi kiện.pdf', 'MUC-LUC.pdf'])
        ->and(is_dir($leftover))->toBeFalse()
        ->and(hpWorkDirectoryIsEmpty())->toBeTrue();
});

it('job cũ bị giết giữa chừng rồi được nhặt lại khi yêu cầu đã bị thay: thoát lặng lẽ VÀ dọn thư mục tạm của nó', function () {
    $staleToken = $this->token;
    $leftover = hpLeftoverFromKilledRun($this->matter->id, $staleToken);

    hpRequestAgain($this, 5);

    expect(app(BuildHandoverPackage::class)->handle($this->matter->id, $staleToken))->toBeNull()
        ->and(is_dir($leftover))->toBeFalse()
        ->and(hpWorkDirectoryIsEmpty())->toBeTrue();
});
