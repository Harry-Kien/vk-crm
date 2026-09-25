<?php

use App\Actions\Document\ReviewChecklistItem;
use App\Actions\Document\SubmitClientDocument;
use App\Actions\Document\UploadStaffDocument;
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
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

/**
 * Helper một-tệp: gói `$file` (hoặc PDF mặc định) thành mảng MỘT phần tử rồi trả về tài liệu DUY
 * NHẤT — giữ nguyên hình dạng cho hơn 30 test một-tệp đã có trong tệp này. R10 (M6.5 Task 17)
 * đổi chữ ký thật của Action thành `files: array` (một lần nộp có thể gồm nhiều tệp, cùng
 * version — xem `submitClientDocuments()` bên dưới cho helper nhiều tệp).
 */
function submitClientDocument(
    MatterChecklistItem $checklistItem,
    ClientUser $actor,
    ?UploadedFile $file = null,
): Document {
    return submitClientDocuments($checklistItem, $actor, [$file ?? clientSubmitPdf()])->first();
}

/**
 * Helper nhiều-tệp thật của R10: một lần nộp gồm N tệp, tất cả cùng một version.
 *
 * @param  list<UploadedFile>  $files
 * @return Collection<int, Document>
 */
function submitClientDocuments(
    MatterChecklistItem $checklistItem,
    ClientUser $actor,
    array $files,
): Collection {
    return app(SubmitClientDocument::class)->handle(
        checklistItem: $checklistItem,
        actor: $actor,
        files: $files,
    );
}

/**
 * Một lần nộp bị từ chối vì đầu mục "không dùng được" — SPEC §10.10.
 *
 * Khẳng định ĐÚNG CÂU chứ không chỉ đúng LỚP exception. Lớp là thứ mã nguồn nhìn thấy; câu chữ
 * mới là thứ người đứng trước màn hình nhìn thấy, và ba tình huống mà §10.10 đòi phải không phân
 * biệt được (không tồn tại, đã xoá khỏi danh mục, của người khác) đi ra từ HAI chỗ ném khác nhau
 * trong Action. Một test chỉ kiểm lớp vẫn xanh khi hai chỗ đó nói hai câu khác nhau — đúng chuyện
 * đã xảy ra: chỗ thứ hai trả về "This action is unauthorized." của Laravel, tiếng Anh, cho một
 * khách hàng đang đứng ở sân uỷ ban phường.
 */
/** Duyệt đạt một đầu mục, qua đúng Action của SPEC §6.7 chứ không bằng một lệnh `update()`. */
function reviewChecklistItemForSubmitTest(MatterChecklistItem $item, User $actor): void
{
    app(ReviewChecklistItem::class)->handle($item, $actor, ChecklistItemStatus::Accepted);
}

function expectSubmitRefusal(Closure $call): void
{
    expect($call)->toThrow(fn (AuthorizationException $exception) => expect($exception->getMessage())
        ->toBe(__('checklist.submit.item_unavailable')));
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
// R10 (M6.5 Task 17, checklist-03/checklist-07): một lần nộp gồm NHIỀU tệp, cùng một version.
// ---------------------------------------------------------------------------------------------

/**
 * checklist-03: CCCD hai mặt nộp trong MỘT lần phải là hai tài liệu CÙNG version — không phải
 * "mặt sau thay thế mặt trước" (bug gốc: trang 2 thành version 2, trỏ parent về trang 1, và cổng
 * chỉ còn vẽ bản mới nhất mỗi chuỗi nên mặt trước biến mất).
 */
it('nộp CCCD hai mặt trong một lần tạo hai tài liệu cùng version, không bản nào là cha của bản kia', function () {
    $documents = submitClientDocuments($this->item, $this->clientUser, [
        clientSubmitPdf('cccd-mat-truoc.pdf'),
        clientSubmitPdf('cccd-mat-sau.pdf'),
    ]);

    expect($documents)->toHaveCount(2);

    [$front, $back] = $documents->all();

    expect($front->version)->toBe(1)
        ->and($back->version)->toBe(1)
        ->and($front->parent_document_id)->toBeNull()
        ->and($back->parent_document_id)->toBeNull()
        ->and($front->getFirstMedia('file')->name)->toBe('cccd-mat-truoc.pdf')
        ->and($back->getFirstMedia('file')->name)->toBe('cccd-mat-sau.pdf')
        ->and(Document::query()->count())->toBe(2);
});

/**
 * "Nộp thêm trang 3 khi đang chờ duyệt" (R10): đầu mục đã `pending_review` từ lần nộp trước, và
 * một lần nộp MỚI trong lúc đó là BỔ SUNG vào version đang chờ, không phải một lần nộp lại mới —
 * khác hẳn "nộp lại sau khi bị từ chối" (test ở dưới), nơi mỗi lần nộp LÀ một version mới.
 */
it('nộp thêm trang khi đầu mục đang chờ duyệt là bổ sung vào version đang chờ, không phải version mới', function () {
    $first = submitClientDocuments($this->item, $this->clientUser, [
        clientSubmitPdf('trang-1.pdf'),
        clientSubmitPdf('trang-2.pdf'),
    ]);

    expect($this->item->fresh()->status)->toBe(ChecklistItemStatus::PendingReview);

    $third = submitClientDocument($this->item->fresh(), $this->clientUser, clientSubmitPdf('trang-3.pdf'));

    expect($third->version)->toBe(1)
        ->and(Document::query()->count())->toBe(3)
        ->and(Document::query()->pluck('version')->unique()->all())->toBe([1]);
});

/**
 * Cặp sinh đôi âm: một khi đầu mục ĐÃ bị từ chối (không còn `pending_review`), lần nộp tiếp theo
 * KHÔNG bổ sung vào version cũ — nó là một lần nộp lại thật, mang version mới, đúng luật đã có từ
 * M4 (test "nộp lại tạo bản version 2..." bên dưới). Không có test này, một cài đặt luôn "bổ
 * sung vào version mới nhất" (bỏ qua trạng thái đầu mục) cũng làm test "nộp thêm trang" ở trên
 * xanh.
 */
it('nộp lại sau khi bị từ chối là version mới, không bổ sung vào version đã bị từ chối', function () {
    $first = submitClientDocument($this->item, $this->clientUser, clientSubmitPdf('lan-1.pdf'));

    $this->item->update([
        'status' => ChecklistItemStatus::Rejected,
        'rejection_reason' => 'Ảnh bị mờ ở góc trên nên không đọc được số thửa.',
    ]);

    $second = submitClientDocument($this->item->fresh(), $this->clientUser, clientSubmitPdf('lan-2.pdf'));

    expect($second->version)->toBe(2)
        ->and($second->parent_document_id)->toBe($first->id);
});

/**
 * Bổ sung trang khi đang chờ duyệt vẫn phải qua đúng chuỗi bước 2-6 của SPEC §6.6 cho TỪNG tệp —
 * mọi tệp trong một lần nộp nhiều tệp đều bị `FileGuard` soi riêng, không phải chỉ tệp đầu tiên.
 * Không có test này, một cài đặt chỉ quét tệp đầu của mảng cũng làm các test "nhiều tệp" ở trên
 * xanh.
 */
it('mỗi tệp trong một lần nộp nhiều tệp đều đi qua FileGuard, không chỉ tệp đầu', function () {
    $bad = UploadedFile::fake()->createWithContent('chu-ky.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

    expect(fn () => app(SubmitClientDocument::class)->handle(
        checklistItem: $this->item,
        actor: $this->clientUser,
        files: [clientSubmitPdf('trang-1.pdf'), $bad],
    ))->toThrow(FileRejected::class);

    expect(Document::query()->count())->toBe(0)
        ->and($this->item->refresh()->status)->toBe(ChecklistItemStatus::Missing);
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

    // R10 (M6.5 Task 17): nộp thêm khi đầu mục còn `pending_review` là BỔ SUNG vào version đang
    // chờ (xem test riêng ở nhóm R10), không phải một lần "nộp lại". Muốn version 2 thật thì
    // phiên nộp trước phải đã KẾT THÚC — ở đây bằng một lần từ chối, cùng hình dạng SPEC §6.6
    // bước 8 mô tả cho "nộp lại sau khi bị từ chối".
    $this->item->update([
        'status' => ChecklistItemStatus::Rejected,
        'rejection_reason' => 'Ảnh bị mờ ở góc trên nên không đọc được số thửa.',
    ]);

    $second = submitClientDocument($this->item->fresh(), $this->clientUser, clientSubmitPdf('lan-2.pdf'));

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

it('nộp lại được trên một đầu mục ĐÃ ĐƯỢC DUYỆT ĐẠT — quyết định, không phải sơ suất', function () {
    // SPEC §8.3 chỉ vẽ nút nộp ở hai trạng thái `missing` và `rejected`, nhưng đó là một chuyện
    // của MÀN HÌNH, không phải một điều kiện phân quyền — xem docblock `SubmitClientDocument`,
    // mục "Trạng thái đầu mục không phải một cái cổng". Ghim bằng test để một lần siết vô tình
    // về sau không âm thầm cắt mất đường sửa sai duy nhất của khách.
    $first = submitClientDocument($this->item, $this->clientUser);
    reviewChecklistItemForSubmitTest($this->item->fresh(), $this->lawyer);

    $second = submitClientDocument($this->item->fresh(), $this->clientUser);
    $this->item->refresh();

    expect($second->version)->toBe(2)
        ->and($second->parent_document_id)->toBe($first->id)
        // Và đầu mục quay lại hàng chờ: bản vừa nộp chưa ai xem, nên dòng dữ liệu không được
        // tiếp tục khai tên người đã duyệt bản CŨ.
        ->and($this->item->status)->toBe(ChecklistItemStatus::PendingReview)
        ->and($this->item->reviewed_by)->toBeNull();
});

/** Đẩy đầu mục ra khỏi `pending_review` giữa hai lần nộp — xem test "nộp lại tạo bản version 2". */
function rejectItemBetweenSubmissions(MatterChecklistItem $item): void
{
    $item->update([
        'status' => ChecklistItemStatus::Rejected,
        'rejection_reason' => 'Ảnh bị mờ ở góc trên nên không đọc được số thửa.',
    ]);
}

it('nộp lần thứ ba nối tiếp chuỗi version chứ không quay lại 2', function () {
    $first = submitClientDocument($this->item, $this->clientUser);
    rejectItemBetweenSubmissions($this->item->fresh());
    $second = submitClientDocument($this->item->fresh(), $this->clientUser);
    // `->fresh()` TRƯỚC lần `update()` thứ hai, không phải chép lại `$this->item` cũ: giữa hai
    // lần `rejectItemBetweenSubmissions()` có một lần nộp đổi `status` ở CSDL qua một đối tượng
    // KHÁC ($locked` bên trong Action). `$this->item` trong bộ nhớ vẫn còn `Rejected` từ lần gọi
    // trước, nên `update()` với cùng giá trị đó không có gì THAY ĐỔI để ghi — Eloquent bỏ qua
    // câu UPDATE khi không có thuộc tính "dirty", và lần từ chối thứ hai lặng lẽ không xảy ra.
    rejectItemBetweenSubmissions($this->item->fresh());
    $third = submitClientDocument($this->item->fresh(), $this->clientUser);

    expect([$first->version, $second->version, $third->version])->toBe([1, 2, 3])
        ->and($third->parent_document_id)->toBe($second->id);
});

it('một bản đã xoá mềm vẫn tính vào chuỗi version: số version không bao giờ dùng lại', function () {
    $first = submitClientDocument($this->item, $this->clientUser);
    $first->delete();
    rejectItemBetweenSubmissions($this->item);

    $second = submitClientDocument($this->item->fresh(), $this->clientUser);

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
    //
    // Bản cha được dựng bằng CHÍNH `UploadStaffDocument`, không bằng một hàng factory đặt tay:
    // câu đang được kiểm là "hai Action đồng ý với nhau về chuỗi version", và một fixture dựng
    // tay trả lời thay cho phía ghi — nó vẫn xanh kể cả khi phía ghi không bao giờ sinh ra được
    // cái hàng đó. Đúng chuyện đã xảy ra: phía ghi từng hardcode `version = 1`.
    $byStaff = app(UploadStaffDocument::class)->handle(
        matter: $this->matter,
        actor: $this->lawyer,
        file: clientSubmitPdf('ban-chung-thuc.pdf'),
        group: DocumentGroup::ClientProvided,
        title: $this->item->name,
        checklistItem: $this->item,
    );

    $document = submitClientDocument($this->item->fresh(), $this->clientUser);

    expect($byStaff->version)->toBe(1)
        ->and($document->version)->toBe(2)
        ->and($document->parent_document_id)->toBe($byStaff->id);
});

it('bản cũ mà khách không còn xem được vẫn tính vào chuỗi version', function () {
    // Chuỗi version phải đọc được từ DỮ LIỆU THẬT, không qua phạm vi của khách đang đăng nhập:
    // `ClientPortalScope` giấu một bản nhóm A bị tắt cờ `client_can_view`, và nếu Action hỏi qua
    // phạm vi đó thì bản mới lại mang số 1 lần nữa.
    $first = submitClientDocument($this->item, $this->clientUser);
    $first->update(['client_can_view' => false]);
    rejectItemBetweenSubmissions($this->item);

    $this->actingAs($this->clientUser, 'client');

    expect(submitClientDocument($this->item->fresh(), $this->clientUser)->version)->toBe(2);
});

// ---------------------------------------------------------------------------------------------
// Khách là người NGOÀI hệ thống: mọi thứ họ gửi lên đều bị nghi ngờ cho tới khi chứng minh
// ngược lại — kể cả id của đầu mục danh mục.
// ---------------------------------------------------------------------------------------------

it('không nộp được vào đầu mục của một khách hàng khác', function () {
    $otherMatter = Matter::factory()->create();
    $otherItem = MatterChecklistItem::factory()->for($otherMatter)->create();

    // Khẳng định ĐÚNG CÂU, không chỉ đúng lớp exception — xem `expectSubmitRefusal()`.
    expectSubmitRefusal(fn () => submitClientDocument($otherItem, $this->clientUser));

    expect(Document::query()->count())->toBe(0)
        ->and(Media::query()->count())->toBe(0)
        ->and($otherItem->refresh()->status)->toBe(ChecklistItemStatus::Missing);
});

it('không nộp được vào đầu mục của khách hàng khác kể cả khi phiên portal đang mở của chính mình', function () {
    $otherMatter = Matter::factory()->create();
    $otherItem = MatterChecklistItem::factory()->for($otherMatter)->create();

    $this->actingAs($this->clientUser, 'client');

    expectSubmitRefusal(fn () => submitClientDocument($otherItem, $this->clientUser));

    expect(Document::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('không nộp được vào một hồ sơ chưa công bố lên portal', function () {
    $this->matter->update(['is_published_to_portal' => false]);

    expectSubmitRefusal(fn () => submitClientDocument($this->item, $this->clientUser));

    expect(Document::query()->count())->toBe(0);
});

it('không nộp được vào một hồ sơ đã xoá mềm', function () {
    $this->matter->delete();

    expectSubmitRefusal(fn () => submitClientDocument($this->item->fresh(), $this->clientUser));

    expect(Document::query()->count())->toBe(0);
});

it('không nộp được vào một đầu mục đã bị xoá khỏi danh mục', function () {
    $this->item->delete();

    expectSubmitRefusal(fn () => submitClientDocument($this->item, $this->clientUser));

    expect(Document::query()->count())->toBe(0);
});

it('một đầu mục không còn tồn tại bị từ chối y như một đầu mục của người khác', function () {
    // SPEC §10.10: không tồn tại và không có quyền phải trả lời GIỐNG NHAU. Hai loại exception
    // khác nhau ở đây là một cái máy dò: gửi một id bất kỳ, đọc loại lỗi, biết id đó có thật hay
    // không — cho đúng người không được biết.
    $this->item->forceDelete();

    expectSubmitRefusal(fn () => submitClientDocument($this->item, $this->clientUser));

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

    expectSubmitRefusal(fn () => submitClientDocument($item, $this->clientUser));

    expect(Document::query()->count())->toBe(0)
        ->and(Media::query()->count())->toBe(0);
});

it('hồ sơ bị gỡ khỏi portal trong lúc quét virus thì lần nộp đó dừng lại', function (string $sabotage) {
    // Cùng cửa sổ 30 giây với test trên, nhưng ở phía HỒ SƠ chứ phía đầu mục. Gỡ một hồ sơ khỏi
    // portal là một cái nút có người cố ý bấm vì một lý do nào đó; xoá mềm một hồ sơ cũng vậy.
    // Nếu quyết định phân quyền chỉ được lấy MỘT LẦN, trước lúc quét, thì lần nộp vẫn hạ cánh:
    // một tài liệu nhóm A `published`, `client_can_view = true`, nằm trên một hồ sơ khách không
    // còn được thấy — cộng một đầu mục `pending_review` và một thông báo gọi đội ngũ vào xem.
    $matter = $this->matter;

    app()->instance(VirusScanner::class, new class($matter, $sabotage) implements VirusScanner
    {
        public function __construct(private Matter $matter, private string $sabotage) {}

        public function scan(string $path): void
        {
            $this->sabotage === 'unpublish'
                ? $this->matter->update(['is_published_to_portal' => false])
                : $this->matter->delete();
        }

        public function isActive(): bool
        {
            return true;
        }
    });

    expectSubmitRefusal(fn () => submitClientDocument($this->item, $this->clientUser));

    expect(Document::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(Media::query()->count())->toBe(0)
        ->and($this->item->refresh()->status)->toBe(ChecklistItemStatus::Missing);
})->with([
    'hồ sơ bị gỡ khỏi portal' => ['unpublish'],
    'hồ sơ bị xoá mềm' => ['soft-delete'],
]);

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

    expectSubmitRefusal(fn () => submitClientDocument($this->item, $intruder));

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

it('tài khoản portal đã bị vô hiệu hoá thì không nộp được', function () {
    // SPEC §10.9: `client_users.is_active = false` phải chặn ngay ở request kế tiếp. Hôm qua điều
    // đó chỉ được thi hành bởi `canAccessPanel()`, tức chỉ đúng khi lời gọi đi qua panel Filament
    // — và chính Action này khai trong docblock rằng nó không được phép đúng theo kiểu đó. Một
    // job chạy lại hay một lệnh console truyền thẳng `$actor` vào đây không qua panel nào cả.
    $this->clientUser->update(['is_active' => false]);

    expectSubmitRefusal(fn () => submitClientDocument($this->item, $this->clientUser->fresh()));

    expect(Document::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and($this->item->refresh()->status)->toBe(ChecklistItemStatus::Missing);
});

it('một tài khoản portal THỨ HAI của cùng khách hàng vẫn nộp được', function () {
    // Cặp dương của test trên, và câu trả lời cho một câu hỏi chưa ai hỏi thành lời: phạm vi đọc
    // của portal là theo KHÁCH HÀNG (`clients.id`), không theo tài khoản đăng nhập. Một doanh
    // nghiệp có kế toán và giám đốc cùng đăng nhập là chuyện bình thường, và người thứ hai phải
    // nộp được vào đúng danh mục đó.
    $sibling = ClientUser::factory()->create(['client_id' => $this->client->id]);

    expect(submitClientDocument($this->item, $sibling)->uploader_id)->toBe($sibling->id);
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

// ---------------------------------------------------------------------------------------------
// checklist-07 — khoá thật, chỉ đo được trên MariaDB. `bin/dev test` (SQLite) không sinh khoá nào
// nên bỏ qua có lý do, cùng thành ngữ `requirePortalMariadb()` của `LoginTest`.
// ---------------------------------------------------------------------------------------------

/**
 * Bắt buộc phải là CHÍNH cơ sở dữ liệu của ứng dụng: `nextInSubmissionChain()` khoá hàng
 * `matter_checklist_items` qua kết nối MẶC ĐỊNH mà `SubmitClientDocument` dùng, nên phép đo dưới
 * đây phải chạy trên đúng kết nối đó, không phải một kết nối phụ chỉ để hỏi driver.
 */
function requireSubmitMariadb(): void
{
    $driver = DB::connection()->getDriverName();

    if (in_array($driver, ['mysql', 'mariadb'], true)) {
        return;
    }

    test()->markTestSkipped(
        'Cần chạy trên chính MariaDB: lockForUpdate() không sinh khoá nào trên SQLite trong bộ '
        .'nhớ, nơi bộ test mặc định chạy. Chạy lại với `wt-dev <lane> test:mariadb`. Đang chạy '
        ."trên: [{$driver}]."
    );
}

/**
 * checklist-07: `nextInSubmissionChain()` khoá HÀNG đầu mục (`lockForUpdate()` trong
 * `findChecklistItem(..., lock: true)`) trước khi đọc chuỗi version — docblock của nó thừa nhận
 * thẳng "không test nào trong dự án chứng minh được phần khoá của câu này", vì SQLite của bộ test
 * không sinh khoá. Test này đo đúng phần đó, TRÊN MariaDB thật, bằng một kết nối THỨ HAI giữ khoá
 * hàng trong khi kết nối mặc định (đường mà `SubmitClientDocument` đi qua) cố lấy cùng khoá đó.
 *
 * **Không dùng `pcntl_fork` hay hai tiến trình thật** — bộ test của dự án không có hạ tầng đó.
 * Thay vào đó: kết nối B mở một transaction RIÊNG, khoá đúng hàng đó bằng chính câu SQL mà
 * `findChecklistItem(lock: true)` chạy, rồi CỐ Ý không commit ngay. Kết nối mặc định (một luồng
 * PHP đơn, như mọi test khác) gọi `SubmitClientDocument::handle()` thật — vì MariaDB thấy hai
 * PHIÊN riêng đang tranh cùng một hàng, InnoDB chặn phiên đến sau đúng như nó sẽ chặn hai REQUEST
 * THẬT chạy song song. Đặt `innodb_lock_wait_timeout` ngắn để phép chặn đó lộ ra thành một
 * `QueryException` "Lock wait timeout exceeded" thay vì treo bộ test mãi mãi — CHÍNH exception đó
 * là bằng chứng khoá có tác dụng: nếu `lockForUpdate()` không sinh khoá (bug), lần gọi sẽ THÀNH
 * CÔNG ngay lập tức, và test dưới đây đỏ ở đúng chỗ khẳng định "bị chặn".
 *
 * Đầu mục bắt đầu ở `rejected` sẵn một bản version 1, không phải `missing`: tránh nhánh "bổ sung
 * vào version đang chờ" của R10 (chạy khi đầu mục `pending_review`), thứ không liên quan gì tới
 * cái đang được đo ở đây — hai tài khoản cùng tranh SỐ VERSION TIẾP THEO của một chuỗi đã có sẵn
 * ít nhất một bản, đúng hình dạng "cấp lại số 1" mà chính docblock `nextInSubmissionChain()` mô
 * tả cho trường hợp chuỗi RỖNG (ở đây là "chuỗi đã có 1, cả hai cùng tranh số 2" — cùng một lớp
 * lỗi, gap lock hay hàng đã có đều cần cùng một khoá để nối tiếp).
 */
it('hai tài khoản cùng khách nộp song song vào cùng đầu mục thì version và parent đúng, không trùng số', function () {
    requireSubmitMariadb();

    $sibling = ClientUser::factory()->create(['client_id' => $this->client->id]);

    $first = submitClientDocument($this->item, $this->clientUser, clientSubmitPdf('lan-1.pdf'));

    $this->item->update([
        'status' => ChecklistItemStatus::Rejected,
        'rejection_reason' => 'Ảnh bị mờ ở góc trên nên không đọc được số thửa.',
    ]);

    $itemId = $this->item->getKey();
    $matterId = $this->matter->getKey();
    $clientId = $this->client->getKey();
    $siblingId = $sibling->getKey();
    $primaryClientUserId = $this->clientUser->getKey();

    // **`RefreshDatabase` bọc CẢ TEST này trong một transaction trên kết nối mặc định** — nên
    // khoá hàng mà chính `SubmitClientDocument` sắp lấy hôm nay đã bị GIỮ SẴN bởi chính phiên
    // đang chạy test này (từ lúc tạo `$this->item`/`$first`), và một kết nối THỨ HAI xin cùng
    // khoá đó sẽ luôn phải CHỜ TRANSACTION CỦA TEST kết thúc — chờ tới hết test, không phải chờ
    // một lần nộp khác. `COMMIT` tường minh ở đây kết thúc SỚM transaction đó, đúng thời điểm để
    // phép đo bên dưới đo được cái nó cần đo: một phiên KHÁC thật sự tranh chấp với phiên đang
    // chạy `SubmitClientDocument`, không tranh chấp với việc dựng fixture của chính test này.
    //
    // Cái giá của việc này: dữ liệu không tự rollback ở cuối test như mọi test khác trong dự án
    // — `finally` bên dưới tự xoá sạch, theo đúng thứ tự khoá ngoại.
    DB::commit();

    try {
        // Kết nối THỨ HAI, trỏ về ĐÚNG cơ sở dữ liệu MariaDB đang chạy — nhân bản cấu hình của
        // kết nối mặc định, không hardcode host/port/tên CSDL (những giá trị đó đổi theo lane,
        // xem `wt-dev test:mariadb`).
        config(['database.connections.submit_race' => config('database.connections.'.config('database.default'))]);
        $race = DB::connection('submit_race');

        // Kết nối B vào TRƯỚC, khoá đúng hàng đầu mục — mô phỏng "tài khoản kia đã bắt đầu nộp
        // và đang giữ khoá đọc chuỗi version", đúng bước đầu của `findChecklistItem(lock: true)`.
        $race->beginTransaction();
        $race->select('select * from matter_checklist_items where id = ? for update', [$itemId]);

        // Trần chờ NGẮN trên kết nối MẶC ĐỊNH — không phải trên `$race` — vì đây là kết nối mà
        // lần nộp thật sắp chạy qua.
        DB::statement('set session innodb_lock_wait_timeout = 2');

        $blocked = false;

        try {
            submitClientDocument($this->item->fresh(), $sibling, clientSubmitPdf('lan-2.pdf'));
        } catch (QueryException $exception) {
            $blocked = str_contains($exception->getMessage(), 'Lock wait timeout exceeded');
        }

        expect($blocked)->toBeTrue(
            'Lần nộp của tài khoản thứ hai phải BỊ CHẶN bởi khoá hàng của kết nối kia trong khi '
            .'nó còn giữ — nếu nó chạy xong ngay lập tức, lockForUpdate() không thật sự khoá gì '
            .'cả và hai lần nộp đồng thời có thể cùng đọc chuỗi RỖNG rồi cùng ghi cùng một số '
            .'version.'
        );

        // Lần thử bị chặn không để lại gì: `QueryException` huỷ transaction của Action giữa
        // chừng, `DB::transaction()` tự rollback.
        expect(Document::query()->where('matter_checklist_item_id', $itemId)->count())->toBe(1);

        // Kết nối kia giải phóng khoá — không ghi gì vào `documents` (nó chỉ giữ khoá để mô
        // phỏng), nên chuỗi vẫn đúng y như trước khi cuộc đua bắt đầu: một bản version 1.
        $race->rollBack();

        // Giờ hết tranh chấp, tài khoản thứ hai nộp lại — đi tiếp đúng chuỗi, không cấp lại số 1.
        $second = submitClientDocument($this->item->fresh(), $sibling, clientSubmitPdf('lan-2.pdf'));

        expect($second->version)->toBe(2)
            ->and($second->parent_document_id)->toBe($first->id)
            ->and(Document::query()->where('matter_checklist_item_id', $itemId)->pluck('version')->sort()->values()->all())
            ->toBe([1, 2]);
    } finally {
        // Dọn tay, đúng thứ tự khoá ngoại — `COMMIT` ở trên đưa dữ liệu ra khỏi transaction mà
        // `RefreshDatabase` sẽ rollback, nên nó không tự biến mất ở cuối test như mọi test khác.
        Document::query()->where('matter_id', $matterId)->forceDelete();
        MatterChecklistItem::query()->whereKey($itemId)->forceDelete();
        Matter::query()->whereKey($matterId)->forceDelete();
        ClientUser::query()->whereIn('id', [$siblingId, $primaryClientUserId])->forceDelete();
        Client::query()->whereKey($clientId)->forceDelete();

        // Mở lại một transaction trên kết nối mặc định để `RefreshDatabase::tearDown()` có đúng
        // thứ nó mong đợi khi gọi `rollBack()` — không có dòng này, tearDown gặp một kết nối
        // KHÔNG có transaction nào đang mở.
        DB::beginTransaction();
    }
});
