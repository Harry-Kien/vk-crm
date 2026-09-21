<?php

use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\StageLog;
use App\Models\StageLogView;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;

/**
 * **Test quét cách ly của cổng khách hàng.** SPEC §11 ("Cách ly dữ liệu giữa khách hàng", "Tài
 * liệu nội bộ", "Ghi chú nội bộ") và SPEC §14 mục 5 nói một câu tuyệt đối: không có CÁCH NÀO để
 * khách hàng A chạm tới dữ liệu của khách hàng B, tới một tài liệu nhóm D, hay tới nội dung chưa
 * công bố. "Không có cách nào" chỉ là một lời hứa cho tới khi từng cách được thử.
 *
 * Mỗi `it()` dưới đây là MỘT ĐƯỜNG mà cổng khách hàng diễn đạt được, theo đúng danh sách kế
 * hoạch M5 Task 2 liệt kê: danh sách, trang chi tiết theo id, quan hệ đi xuôi, quan hệ đi ngược,
 * `parent_document_id` và chuỗi version, tải về, thuộc tính đã serialize, ô tìm kiếm,
 * `withCount`, `->count()`, `whereHas` dùng làm máy dò tồn tại, bản ghi đã xoá mềm, và vụ việc
 * có `is_published_to_portal = false`. Danh sách đó là một danh sách TEST, không phải một bình
 * luận — nên mỗi dòng ở đây đỏ được.
 *
 * **Mỗi test mang vế dương của nó, bên trong chính nó.** Một test khẳng định "X bị từ chối" xanh
 * y hệt khi MỌI THỨ đều bị từ chối, kể cả dữ liệu của chính khách — và khi đó nó không còn đo gì
 * nữa. Vì vậy không `it()` nào ở đây chỉ gồm những phép đếm bằng `0`: mỗi cái hoặc so với một
 * danh sách id cụ thể, hoặc kèm ngay cạnh một khẳng định rằng cùng đường đó MỞ cho dữ liệu của
 * khách hàng A.
 *
 * **Tệp này được CHẠY LẠI VÀ MỞ RỘNG sau mỗi task có màn hình của M5** (Task 3, 4, 5, 6), thêm
 * đúng những đường mà màn hình mới vừa tạo ra.
 */

/** Chuỗi đánh dấu duy nhất: nếu nó lọt ra bất cứ đâu thì tìm được bằng một lần `str_contains`. */
const SWEEP_MARKER = 'CHUOI-DANH-DAU-NOI-BO-7Q9Z';

beforeEach(function () {
    $this->clientA = Client::factory()->create(['name' => 'Khách hàng A']);
    $this->userA = ClientUser::factory()->create(['client_id' => $this->clientA->id]);
    // SPEC §4.3: một khách hàng có thể có hai tài khoản portal (ví dụ hai vợ chồng).
    $this->siblingA = ClientUser::factory()->create(['client_id' => $this->clientA->id]);

    $this->clientB = Client::factory()->create(['name' => 'Khách hàng B '.SWEEP_MARKER]);
    $this->userB = ClientUser::factory()->create(['client_id' => $this->clientB->id]);

    $this->matterA = Matter::factory()->for($this->clientA)->create(['is_published_to_portal' => true]);
    // Cùng khách hàng A, nhưng CHƯA được đưa lên portal (SPEC §6.2): mọi con của nó phải vô hình.
    $this->hiddenA = Matter::factory()->for($this->clientA)->create([
        'is_published_to_portal' => false,
        'title' => 'Hồ sơ chưa mở cho khách '.SWEEP_MARKER,
    ]);
    $this->matterB = Matter::factory()->for($this->clientB)->create([
        'is_published_to_portal' => true,
        'title' => 'Hồ sơ của khách hàng B '.SWEEP_MARKER,
    ]);

    // Hồ sơ của chính khách A, đã từng ở trên portal, rồi bị xoá mềm.
    $this->retractedMatter = Matter::factory()->for($this->clientA)->create([
        'is_published_to_portal' => true,
        'title' => 'Hồ sơ đã đóng lại '.SWEEP_MARKER,
    ]);
    $this->retractedMatter->delete();

    // ---- Dòng tiến độ -------------------------------------------------------------------
    $this->visibleLog = StageLog::factory()->for($this->matterA)->published()->create([
        'public_content' => 'Toà đã nhận hồ sơ.',
        'internal_note' => SWEEP_MARKER,
    ]);
    $this->draftLog = StageLog::factory()->for($this->matterA)->internalOnly()->create([
        'public_content' => 'Chưa công bố '.SWEEP_MARKER,
        'internal_note' => SWEEP_MARKER,
    ]);
    $this->hiddenLog = StageLog::factory()->for($this->hiddenA)->published()->create([
        'public_content' => 'Hồ sơ chưa mở '.SWEEP_MARKER,
    ]);
    $this->foreignLog = StageLog::factory()->for($this->matterB)->published()->create([
        'public_content' => 'Của khách B '.SWEEP_MARKER,
    ]);

    // ---- Tài liệu -----------------------------------------------------------------------
    $this->visibleDoc = Document::factory()->for($this->matterA)->group(DocumentGroup::Issued)->create([
        'title' => 'Quyết định của toà',
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
    ]);
    // Nhóm D mang SẴN cả hai cờ khách, ghi thẳng vào bảng (hook `saving` hạ chúng trên mọi dòng
    // nhóm D): nếu để cờ tắt thì mọi khẳng định dưới đây xanh nhờ cái cờ, chứ không nhờ ĐIỀU
    // KIỆN NHÓM mà tệp này có mặt để canh.
    $this->internalDoc = forceClientFlags(
        Document::factory()->for($this->matterA)->group(DocumentGroup::Internal)->create([
            'title' => 'Kế hoạch tranh tụng '.SWEEP_MARKER,
            'status' => DocumentStatus::Published,
        ])
    );
    $this->foreignDoc = Document::factory()->for($this->matterB)->group(DocumentGroup::Issued)->create([
        'title' => 'Tài liệu khách B '.SWEEP_MARKER,
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
    ]);
    $this->hiddenDoc = Document::factory()->for($this->hiddenA)->group(DocumentGroup::Issued)->create([
        'title' => 'Tài liệu hồ sơ chưa mở '.SWEEP_MARKER,
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
    ]);

    // Chuỗi version: bản 1 là nhóm D, bản 2 khách thấy được; và một bản nhóm D treo SAU bản
    // khách thấy được. Hai chiều của `parent_document_id`.
    $this->chainInternalV1 = forceClientFlags(
        Document::factory()->for($this->matterA)->group(DocumentGroup::Internal)->create([
            'title' => 'Bản nháp nội bộ '.SWEEP_MARKER,
            'status' => DocumentStatus::Published,
            'version' => 1,
        ])
    );
    $this->chainVisibleV2 = Document::factory()->for($this->matterA)->group(DocumentGroup::Issued)->create([
        'title' => 'Bản chính thức',
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
        'version' => 2,
        'parent_document_id' => $this->chainInternalV1->id,
    ]);
    $this->chainInternalV3 = forceClientFlags(
        Document::factory()->for($this->matterA)->group(DocumentGroup::Internal)->create([
            'title' => 'Bản sửa nội bộ '.SWEEP_MARKER,
            'status' => DocumentStatus::Published,
            'version' => 3,
            'parent_document_id' => $this->chainVisibleV2->id,
        ])
    );

    // Đã xoá mềm — văn phòng đã rút nó khỏi hồ sơ.
    $this->retractedDoc = Document::factory()->for($this->matterA)->group(DocumentGroup::Issued)->create([
        'title' => 'Tài liệu đã rút '.SWEEP_MARKER,
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
    ]);
    $this->retractedDoc->delete();

    // ---- Danh mục hồ sơ, mốc hạn, yêu cầu, biên bản đã xem --------------------------------
    $this->itemA = MatterChecklistItem::factory()->for($this->matterA)->create(['name' => 'Chứng minh nhân dân']);
    $this->foreignItem = MatterChecklistItem::factory()->for($this->matterB)->create(['name' => 'Của khách B '.SWEEP_MARKER]);
    $this->hiddenItem = MatterChecklistItem::factory()->for($this->hiddenA)->create(['name' => 'Hồ sơ chưa mở '.SWEEP_MARKER]);

    $this->visibleDeadline = Deadline::factory()->for($this->matterA)->published()->create(['name' => 'Nộp bổ sung']);
    $this->draftDeadline = Deadline::factory()->for($this->matterA)->create(['is_published' => false, 'name' => 'Nội bộ '.SWEEP_MARKER]);
    $this->foreignDeadline = Deadline::factory()->for($this->matterB)->published()->create(['name' => 'Của khách B '.SWEEP_MARKER]);

    $this->requestA = ClientRequest::factory()->for($this->matterA)->create([
        'client_user_id' => $this->userA->id,
        'subject' => 'Hỏi về lịch hẹn',
    ]);
    // Yêu cầu do TÀI KHOẢN KHÁC của cùng khách hàng A gửi — vế dương của phán quyết "theo Client".
    $this->requestSibling = ClientRequest::factory()->for($this->matterA)->create([
        'client_user_id' => $this->siblingA->id,
        'subject' => 'Người nhà hỏi về án phí',
    ]);
    $this->foreignRequest = ClientRequest::factory()->for($this->matterB)->create([
        'client_user_id' => $this->userB->id,
        'subject' => 'Của khách B '.SWEEP_MARKER,
    ]);
    $this->hiddenRequest = ClientRequest::factory()->for($this->hiddenA)->create([
        'client_user_id' => $this->userA->id,
        'subject' => 'Hồ sơ chưa mở '.SWEEP_MARKER,
    ]);
    $this->retractedRequest = ClientRequest::factory()->for($this->matterA)->create([
        'client_user_id' => $this->userA->id,
        'subject' => 'Yêu cầu đã rút '.SWEEP_MARKER,
    ]);
    $this->retractedRequest->delete();

    $staff = User::factory()->create();
    $this->replyToA = ClientRequestReply::factory()->create([
        'request_id' => $this->requestA->id,
        'author_type' => $staff->getMorphClass(),
        'author_id' => $staff->id,
        'content' => 'Văn phòng đã nhận, mời anh/chị tới vào thứ Ba.',
    ]);
    $this->replyToSibling = ClientRequestReply::factory()->create([
        'request_id' => $this->requestSibling->id,
        'author_type' => $staff->getMorphClass(),
        'author_id' => $staff->id,
        'content' => 'Án phí đã nộp đủ.',
    ]);
    $this->foreignReply = ClientRequestReply::factory()->create([
        'request_id' => $this->foreignRequest->id,
        'author_type' => $staff->getMorphClass(),
        'author_id' => $staff->id,
        'content' => 'Trả lời khách B '.SWEEP_MARKER,
    ]);

    $this->receiptSibling = StageLogView::factory()->create([
        'stage_log_id' => $this->visibleLog->id,
        'client_user_id' => $this->siblingA->id,
    ]);
    $this->foreignReceipt = StageLogView::factory()->create([
        'stage_log_id' => $this->foreignLog->id,
        'client_user_id' => $this->userB->id,
    ]);
});

/** Đọc một bảng không qua `ClientPortalScope` — để dựng fixture và để ĐẾM THẬT. */
function sweepAll(string $model): Builder
{
    return $model::query()->withoutGlobalScope(ClientPortalScope::class);
}

// =========================================================================================
// 1. DANH SÁCH
// =========================================================================================

it('lists only the rows of the own client, of published matters, and never group D', function () {
    $this->actingAs($this->userA, 'client');

    expect(Matter::pluck('id')->all())->toBe([$this->matterA->id])
        ->and(StageLog::pluck('id')->all())->toBe([$this->visibleLog->id])
        ->and(Document::pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->visibleDoc->id, $this->chainVisibleV2->id])->sort()->values()->all())
        ->and(Deadline::pluck('id')->all())->toBe([$this->visibleDeadline->id])
        ->and(MatterChecklistItem::pluck('id')->all())->toBe([$this->itemA->id])
        ->and(ClientRequest::pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->requestA->id, $this->requestSibling->id])->sort()->values()->all())
        ->and(ClientRequestReply::pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->replyToA->id, $this->replyToSibling->id])->sort()->values()->all())
        ->and(StageLogView::pluck('id')->all())->toBe([$this->receiptSibling->id])
        ->and(Client::pluck('id')->all())->toBe([$this->clientA->id]);
});

// =========================================================================================
// 2. TRANG CHI TIẾT THEO ID — cả tầng truy vấn lẫn tầng policy
// =========================================================================================

it('never resolves a row of another client by its id, on either layer', function () {
    $this->actingAs($this->userA, 'client');

    expect(Matter::find($this->matterB->id))->toBeNull()
        ->and(StageLog::find($this->foreignLog->id))->toBeNull()
        ->and(Document::find($this->foreignDoc->id))->toBeNull()
        ->and(Deadline::find($this->foreignDeadline->id))->toBeNull()
        ->and(MatterChecklistItem::find($this->foreignItem->id))->toBeNull()
        ->and(ClientRequest::find($this->foreignRequest->id))->toBeNull()
        ->and(ClientRequestReply::find($this->foreignReply->id))->toBeNull()
        ->and(StageLogView::find($this->foreignReceipt->id))->toBeNull()
        // Tầng policy hỏi lại một cách độc lập, trên đúng những đối tượng đó.
        ->and($this->userA->can('view', $this->matterB))->toBeFalse()
        ->and($this->userA->can('view', $this->foreignLog))->toBeFalse()
        ->and($this->userA->can('view', $this->foreignDoc))->toBeFalse()
        ->and($this->userA->can('download', $this->foreignDoc))->toBeFalse()
        ->and($this->userA->can('view', $this->foreignRequest))->toBeFalse()
        ->and($this->userA->can('view', $this->foreignReply))->toBeFalse()
        ->and($this->userA->can('view', $this->foreignReceipt))->toBeFalse()
        ->and($this->userA->can('create', [StageLogView::class, $this->foreignLog]))->toBeFalse();
});

/** Vế dương: chính những đường trên phải MỞ cho dữ liệu của khách A. */
it('resolves the own rows by id on both layers', function () {
    $this->actingAs($this->userA, 'client');

    expect(Matter::find($this->matterA->id))->not->toBeNull()
        ->and(StageLog::find($this->visibleLog->id))->not->toBeNull()
        ->and(Document::find($this->visibleDoc->id))->not->toBeNull()
        ->and(ClientRequest::find($this->requestA->id))->not->toBeNull()
        ->and(ClientRequestReply::find($this->replyToA->id))->not->toBeNull()
        ->and(StageLogView::find($this->receiptSibling->id))->not->toBeNull()
        ->and($this->userA->can('view', $this->matterA))->toBeTrue()
        ->and($this->userA->can('view', $this->visibleLog))->toBeTrue()
        ->and($this->userA->can('view', $this->visibleDoc))->toBeTrue()
        ->and($this->userA->can('download', $this->visibleDoc))->toBeTrue()
        ->and($this->userA->can('view', $this->requestA))->toBeTrue()
        ->and($this->userA->can('view', $this->replyToA))->toBeTrue()
        ->and($this->userA->can('view', $this->receiptSibling))->toBeTrue()
        ->and($this->userA->can('create', [StageLogView::class, $this->visibleLog]))->toBeTrue();
});

// =========================================================================================
// 3. QUAN HỆ ĐI XUÔI (cha → con)
// =========================================================================================

it('never reaches a hidden row through a relation going down from a matter', function () {
    $foreignMatter = sweepAll(Matter::class)->findOrFail($this->matterB->id);
    $hiddenMatter = sweepAll(Matter::class)->findOrFail($this->hiddenA->id);

    $this->actingAs($this->userA, 'client');

    expect($this->matterA->stageLogs()->pluck('id')->all())->toBe([$this->visibleLog->id])
        ->and($this->matterA->documents()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->visibleDoc->id, $this->chainVisibleV2->id])->sort()->values()->all())
        ->and($this->matterA->deadlines()->pluck('id')->all())->toBe([$this->visibleDeadline->id])
        ->and($this->matterA->clientRequests()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->requestA->id, $this->requestSibling->id])->sort()->values()->all())
        ->and($this->itemA->documents()->pluck('id')->all())->toBe([])
        ->and($this->requestA->replies()->pluck('id')->all())->toBe([$this->replyToA->id])
        ->and($this->visibleLog->views()->pluck('id')->all())->toBe([$this->receiptSibling->id])
        // Cầm sẵn đối tượng vụ việc của khách B trong tay vẫn không đi xuống được.
        ->and($foreignMatter->stageLogs()->count())->toBe(0)
        ->and($foreignMatter->documents()->count())->toBe(0)
        ->and($foreignMatter->deadlines()->count())->toBe(0)
        ->and($foreignMatter->clientRequests()->count())->toBe(0)
        ->and($hiddenMatter->stageLogs()->count())->toBe(0)
        ->and($hiddenMatter->documents()->count())->toBe(0)
        ->and($hiddenMatter->clientRequests()->count())->toBe(0)
        ->and($this->clientA->matters()->pluck('id')->all())->toBe([$this->matterA->id]);
});

// =========================================================================================
// 4. QUAN HỆ ĐI NGƯỢC (con → cha)
// =========================================================================================

it('never reaches a hidden row through a relation going back up from a child', function () {
    $foreignDoc = sweepAll(Document::class)->findOrFail($this->foreignDoc->id);
    $foreignReply = sweepAll(ClientRequestReply::class)->findOrFail($this->foreignReply->id);
    $internalDoc = sweepAll(Document::class)->findOrFail($this->internalDoc->id);

    $this->actingAs($this->userA, 'client');

    expect($foreignDoc->matter()->first())->toBeNull()
        ->and($foreignReply->request()->first())->toBeNull()
        ->and($internalDoc->checklistItem()->first())->toBeNull()
        // Vế dương: đi ngược từ một bản ghi khách A thấy được thì tới nơi.
        ->and($this->visibleDoc->matter()->first()?->id)->toBe($this->matterA->id)
        ->and($this->replyToA->request()->first()?->id)->toBe($this->requestA->id)
        ->and($this->receiptSibling->stageLog()->first()?->id)->toBe($this->visibleLog->id);
});

// =========================================================================================
// 5. `parent_document_id` VÀ CHUỖI VERSION
// =========================================================================================

it('never reaches a group D document through the version chain in either direction', function () {
    $this->actingAs($this->userA, 'client');

    $visibleV2 = Document::findOrFail($this->chainVisibleV2->id);

    expect($visibleV2->parent()->first())->toBeNull()
        ->and($visibleV2->newerVersions()->pluck('id')->all())->toBe([])
        ->and($visibleV2->parent_document_id)->toBe($this->chainInternalV1->id)
        // Biết id của bản cha cũng không mở được nó ra — cả hai tầng.
        ->and(Document::find($this->chainInternalV1->id))->toBeNull()
        ->and(Document::find($this->chainInternalV3->id))->toBeNull()
        ->and($this->userA->can('view', $this->chainInternalV1))->toBeFalse()
        ->and($this->userA->can('view', $this->chainInternalV3))->toBeFalse()
        ->and($this->userA->can('download', $this->chainInternalV3))->toBeFalse();
});

/** Vế dương: một chuỗi version mà CẢ HAI bản khách thấy được thì đi lại được cả hai chiều. */
it('follows the version chain when both versions are released to the portal', function () {
    $v1 = Document::factory()->for($this->matterA)->group(DocumentGroup::ClientProvided)->create([
        'status' => DocumentStatus::Published, 'client_can_view' => true, 'version' => 1,
    ]);
    $v2 = Document::factory()->for($this->matterA)->group(DocumentGroup::ClientProvided)->create([
        'status' => DocumentStatus::Published, 'client_can_view' => true, 'version' => 2,
        'parent_document_id' => $v1->id,
    ]);

    $this->actingAs($this->userA, 'client');

    expect(Document::findOrFail($v2->id)->parent()->first()?->id)->toBe($v1->id)
        ->and(Document::findOrFail($v1->id)->newerVersions()->pluck('id')->all())->toBe([$v2->id]);
});

// =========================================================================================
// 6. TẢI VỀ
// =========================================================================================

it('answers a signed download url of a hidden document with 404 and serves the own one', function () {
    $this->actingAs($this->userA, 'client');

    $this->get($this->foreignDoc->downloadUrlFor($this->userA))->assertNotFound();
    $this->get($this->internalDoc->downloadUrlFor($this->userA))->assertNotFound();
    $this->get($this->hiddenDoc->downloadUrlFor($this->userA))->assertNotFound();
    $this->get($this->chainInternalV1->downloadUrlFor($this->userA))->assertNotFound();
});

/**
 * Vế dương của test trên, và nó là điều kiện để test kia có nghĩa: nếu mọi lần tải đều 404 thì
 * bốn dòng bên trên không đo gì cả.
 */
it('serves the download the client is entitled to', function () {
    $this->visibleDoc
        ->addMedia(UploadedFile::fake()->create('quyet-dinh.pdf', 10, 'application/pdf'))
        ->toMediaCollection('file');

    $this->actingAs($this->userA, 'client');

    $this->get($this->visibleDoc->fresh()->downloadUrlFor($this->userA))->assertOk();
});

// =========================================================================================
// 7. THUỘC TÍNH ĐÃ SERIALIZE
// =========================================================================================

it('never serializes an internal column or a hidden row into html or json', function () {
    $this->actingAs($this->userA, 'client');

    $payload = json_encode([
        'matters' => Matter::with(['stageLogs', 'documents', 'deadlines', 'checklistItems', 'clientRequests.replies'])->get()->toArray(),
        'logs' => StageLog::get()->toArray(),
        'documents' => Document::get()->toArray(),
        'requests' => ClientRequest::with('replies')->get()->toArray(),
        'receipts' => StageLogView::get()->toArray(),
        'client' => Client::get()->toArray(),
    ], JSON_UNESCAPED_UNICODE);

    expect($payload)->not->toContain(SWEEP_MARKER)
        ->and(StageLog::findOrFail($this->visibleLog->id)->toArray())->not->toHaveKey('internal_note')
        // Vế dương: nội dung khách ĐƯỢC đọc vẫn có mặt, nếu không thì phép đo trên vô nghĩa.
        ->and($payload)->toContain('Toà đã nhận hồ sơ.')
        ->and($payload)->toContain('Quyết định của toà');
});

// =========================================================================================
// 8. Ô TÌM KIẾM
// =========================================================================================

it('never turns a search box into a way of reading someone elses row', function () {
    $this->actingAs($this->userA, 'client');

    $needle = '%'.SWEEP_MARKER.'%';

    expect(Document::where('title', 'like', $needle)->count())->toBe(0)
        ->and(ClientRequest::where('subject', 'like', $needle)->count())->toBe(0)
        ->and(ClientRequestReply::where('content', 'like', $needle)->count())->toBe(0)
        ->and(StageLog::where('public_content', 'like', $needle)->count())->toBe(0)
        ->and(Matter::where('title', 'like', $needle)->count())->toBe(0)
        ->and(MatterChecklistItem::where('name', 'like', $needle)->count())->toBe(0)
        ->and(Deadline::where('name', 'like', $needle)->count())->toBe(0)
        ->and(Client::where('name', 'like', $needle)->count())->toBe(0)
        // Vế dương: ô tìm kiếm vẫn tìm được thứ của chính khách.
        ->and(Document::where('title', 'like', '%Quyết định%')->count())->toBe(1);
});

// =========================================================================================
// 9 & 10. `withCount` VÀ `->count()` — đếm cũng là đọc
// =========================================================================================

it('never lets a count reveal a row the client cannot read', function () {
    $this->actingAs($this->userA, 'client');

    $counted = Matter::withCount(['stageLogs', 'documents', 'deadlines', 'checklistItems', 'clientRequests'])
        ->findOrFail($this->matterA->id);

    expect($counted->stage_logs_count)->toBe(1)
        ->and($counted->documents_count)->toBe(2)
        ->and($counted->deadlines_count)->toBe(1)
        ->and($counted->checklist_items_count)->toBe(1)
        ->and($counted->client_requests_count)->toBe(2)
        ->and(Matter::count())->toBe(1)
        ->and(Document::count())->toBe(2)
        ->and(StageLog::count())->toBe(1)
        ->and(Deadline::count())->toBe(1)
        ->and(ClientRequest::count())->toBe(2)
        ->and(ClientRequestReply::count())->toBe(2)
        ->and(StageLogView::count())->toBe(1)
        // Không đếm được gì của khách B, kể cả khi cầm đối tượng vụ việc của họ.
        ->and(Matter::withCount('documents')->find($this->matterB->id))->toBeNull();
});

// =========================================================================================
// 11. `whereHas` DÙNG LÀM MÁY DÒ TỒN TẠI
// =========================================================================================

it('never answers an existence probe built out of whereHas', function () {
    $this->actingAs($this->userA, 'client');

    expect(Matter::whereHas('documents', fn ($q) => $q->whereKey($this->internalDoc->id))->count())->toBe(0)
        ->and(Matter::whereHas('documents', fn ($q) => $q->whereKey($this->foreignDoc->id))->count())->toBe(0)
        ->and(Matter::whereHas('stageLogs', fn ($q) => $q->whereKey($this->draftLog->id))->count())->toBe(0)
        ->and(Matter::whereHas('clientRequests', fn ($q) => $q->whereKey($this->foreignRequest->id))->count())->toBe(0)
        ->and(ClientRequest::whereHas('matter', fn ($q) => $q->whereKey($this->matterB->id))->count())->toBe(0)
        ->and(StageLogView::whereHas('stageLog', fn ($q) => $q->whereKey($this->foreignLog->id))->count())->toBe(0)
        // Vế dương: cùng cái máy dò đó trả lời ĐÚNG về bản ghi của chính khách, nên một câu trả
        // lời `0` ở trên là một lời từ chối chứ không phải một truy vấn hỏng.
        ->and(Matter::whereHas('documents', fn ($q) => $q->whereKey($this->visibleDoc->id))->count())->toBe(1)
        ->and(Matter::whereHas('stageLogs', fn ($q) => $q->whereKey($this->visibleLog->id))->count())->toBe(1);
});

// =========================================================================================
// 12. BẢN GHI ĐÃ XOÁ MỀM
// =========================================================================================

/**
 * `withTrashed()` gỡ `SoftDeletingScope` ra, KHÔNG gỡ `ClientPortalScope`. Nhưng một tài liệu đã
 * bị văn phòng rút khỏi hồ sơ vẫn là tài liệu của chính khách hàng đó, nên nếu điều kiện "chưa
 * xoá" chỉ do `SoftDeletingScope` giữ thì một màn hình gọi `withTrashed()` sẽ trả nó về —
 * và đó không phải một rò rỉ giữa hai khách hàng, mà là một lần đưa lại thứ vừa được rút.
 */
it('keeps a soft deleted row out of every portal query, even with withTrashed', function () {
    $this->actingAs($this->userA, 'client');

    expect(Document::withTrashed()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->visibleDoc->id, $this->chainVisibleV2->id])->sort()->values()->all())
        ->and(ClientRequest::withTrashed()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->requestA->id, $this->requestSibling->id])->sort()->values()->all())
        ->and(Document::withTrashed()->find($this->retractedDoc->id))->toBeNull()
        ->and(ClientRequest::withTrashed()->find($this->retractedRequest->id))->toBeNull()
        ->and(Matter::withTrashed()->pluck('id')->all())->toBe([$this->matterA->id])
        ->and(Matter::withTrashed()->find($this->retractedMatter->id))->toBeNull()
        ->and($this->userA->can('view', $this->retractedMatter))->toBeFalse()
        ->and($this->userA->can('view', $this->retractedDoc))->toBeFalse()
        ->and($this->userA->can('view', $this->retractedRequest))->toBeFalse();
});

/** Vế dương: xoá mềm một vụ việc thì nó biến mất khỏi portal, chứ không phải mọi thứ đều biến mất. */
it('drops a matter from the portal the moment it is soft deleted', function () {
    $this->actingAs($this->userA, 'client');

    // Vế dương, đo TRƯỚC: đúng những đường dưới đây đang mở.
    expect(Matter::count())->toBe(1)
        ->and(StageLog::count())->toBe(1)
        ->and(Document::count())->toBe(2)
        ->and(ClientRequest::count())->toBe(2);

    sweepAll(Matter::class)->findOrFail($this->matterA->id)->delete();

    expect(Matter::count())->toBe(0)
        ->and(StageLog::count())->toBe(0)
        ->and(Document::count())->toBe(0)
        ->and(ClientRequest::count())->toBe(0);
});

// =========================================================================================
// 13. VỤ VIỆC CÓ `is_published_to_portal = false`
// =========================================================================================

it('hides every child of a matter that is not published to the portal', function () {
    $this->actingAs($this->userA, 'client');

    expect(Matter::find($this->hiddenA->id))->toBeNull()
        ->and(StageLog::find($this->hiddenLog->id))->toBeNull()
        ->and(Document::find($this->hiddenDoc->id))->toBeNull()
        ->and(MatterChecklistItem::find($this->hiddenItem->id))->toBeNull()
        ->and(ClientRequest::find($this->hiddenRequest->id))->toBeNull()
        ->and($this->userA->can('view', $this->hiddenA))->toBeFalse()
        ->and($this->userA->can('view', $this->hiddenLog))->toBeFalse()
        ->and($this->userA->can('view', $this->hiddenDoc))->toBeFalse()
        ->and($this->userA->can('view', $this->hiddenRequest))->toBeFalse()
        ->and($this->userA->can('create', [Document::class, $this->hiddenItem]))->toBeFalse()
        ->and($this->userA->can('create', [StageLogView::class, $this->hiddenLog]))->toBeFalse();
});

/** Vế dương: bật cờ lên thì đúng những đường đó mở ra. */
it('shows the same matter the moment it is published to the portal', function () {
    $this->hiddenA->update(['is_published_to_portal' => true]);

    $this->actingAs($this->userA, 'client');

    expect(Matter::find($this->hiddenA->id))->not->toBeNull()
        ->and(StageLog::find($this->hiddenLog->id))->not->toBeNull()
        ->and(Document::find($this->hiddenDoc->id))->not->toBeNull()
        ->and($this->userA->can('view', $this->hiddenA))->toBeTrue()
        ->and($this->userA->can('create', [Document::class, $this->hiddenItem]))->toBeTrue();
});

// =========================================================================================
// NGHI THỨC BA TẦNG — tầng 2: thay global scope bằng một scope rỗng, policy VẪN phải từ chối
// =========================================================================================

/**
 * Đúng hình dạng "ai đó quên một câu `where`". Nếu policy chỉ chạy lại tầng truy vấn thì nó
 * không phải một tầng riêng — nó sụp xuống thành chính tầng kia, và một lần quên là một vụ rò rỉ.
 */
it('still refuses on the policy layer when every portal scope forgets its rule', function () {
    foreach ([Matter::class, StageLog::class, Document::class, Deadline::class, ClientRequest::class, ClientRequestReply::class, StageLogView::class, MatterChecklistItem::class] as $model) {
        $model::addGlobalScope(ClientPortalScope::class, function (): void {});
    }

    try {
        $this->actingAs($this->userA, 'client');

        // Tầng truy vấn đã thủng — nếu không thì khẳng định bên dưới không đo tầng policy.
        expect(Matter::find($this->matterB->id))->not->toBeNull()
            ->and(Document::find($this->internalDoc->id))->not->toBeNull()
            ->and(ClientRequest::find($this->foreignRequest->id))->not->toBeNull();

        expect($this->userA->can('view', $this->matterB))->toBeFalse()
            ->and($this->userA->can('view', $this->foreignLog))->toBeFalse()
            ->and($this->userA->can('view', $this->foreignDoc))->toBeFalse()
            ->and($this->userA->can('view', $this->internalDoc))->toBeFalse()
            ->and($this->userA->can('view', $this->draftLog))->toBeFalse()
            ->and($this->userA->can('view', $this->draftDeadline))->toBeFalse()
            ->and($this->userA->can('view', $this->retractedDoc))->toBeFalse()
            ->and($this->userA->can('view', $this->hiddenA))->toBeFalse()
            ->and($this->userA->can('view', $this->retractedMatter))->toBeFalse()
            ->and($this->userA->can('view', $this->foreignRequest))->toBeFalse()
            ->and($this->userA->can('view', $this->foreignReply))->toBeFalse()
            ->and($this->userA->can('view', $this->foreignReceipt))->toBeFalse()
            ->and($this->userA->can('create', [StageLogView::class, $this->foreignLog]))->toBeFalse()
            ->and($this->userA->can('create', [Document::class, $this->foreignItem]))->toBeFalse()
            // Vế dương trong CÙNG ngữ cảnh thủng: policy không từ chối tất cả.
            ->and($this->userA->can('view', $this->matterA))->toBeTrue()
            ->and($this->userA->can('view', $this->visibleDoc))->toBeTrue()
            ->and($this->userA->can('view', $this->requestA))->toBeTrue()
            ->and($this->userA->can('view', $this->replyToA))->toBeTrue()
            ->and($this->userA->can('view', $this->receiptSibling))->toBeTrue()
            ->and($this->userA->can('view', $this->visibleDeadline))->toBeTrue();
    } finally {
        foreach ([Matter::class, StageLog::class, Document::class, Deadline::class, ClientRequest::class, ClientRequestReply::class, StageLogView::class, MatterChecklistItem::class] as $model) {
            $model::addGlobalScope(new ClientPortalScope);
        }
    }
});

// =========================================================================================
// PHẠM VI "CỦA CHÍNH MÌNH": THEO `Client`, KHÔNG THEO `ClientUser` — phán quyết 19/09/2026
// =========================================================================================

/**
 * Ghim một PHÁN QUYẾT, không một hành vi tình cờ. SPEC §5 viết "Tạo và xem `ClientRequest` của
 * chính mình" và câu đó đọc được theo cả hai nghĩa; chủ văn phòng chốt ngày 19/09/2026 là **theo
 * khách hàng**, nên hai tài khoản portal của cùng một khách hàng (SPEC §4.3: hai vợ chồng) đọc
 * được yêu cầu — và câu trả lời của văn phòng — của nhau.
 *
 * Test này tồn tại để một ngày nào đó có người đổi cách đọc thì họ phải đổi cả nó, tức phải BIẾT
 * mình đang đổi một quyết định của văn phòng chứ không sửa một chi tiết kỹ thuật.
 */
it('lets two portal accounts of the same client read each others requests and receipts', function () {
    $this->actingAs($this->userA, 'client');

    expect(ClientRequest::find($this->requestSibling->id))->not->toBeNull()
        ->and(ClientRequestReply::find($this->replyToSibling->id))->not->toBeNull()
        ->and(StageLogView::find($this->receiptSibling->id))->not->toBeNull()
        ->and($this->userA->can('view', $this->requestSibling))->toBeTrue()
        ->and($this->userA->can('view', $this->replyToSibling))->toBeTrue()
        ->and($this->userA->can('view', $this->receiptSibling))->toBeTrue();
});

/** Vế âm của cùng phán quyết: "cùng khách hàng" là ranh giới, không phải "bất kỳ ai". */
it('stops the same reading at the boundary of another client', function () {
    $this->actingAs($this->userA, 'client');

    expect(ClientRequest::find($this->foreignRequest->id))->toBeNull()
        ->and(ClientRequestReply::find($this->foreignReply->id))->toBeNull()
        ->and(StageLogView::find($this->foreignReceipt->id))->toBeNull()
        ->and($this->userA->can('view', $this->foreignRequest))->toBeFalse()
        ->and($this->userA->can('view', $this->foreignReply))->toBeFalse()
        ->and($this->userA->can('view', $this->foreignReceipt))->toBeFalse();
});

// =========================================================================================
// NHÂN SỰ KHÔNG BỊ CẮT — nếu không thì mọi khẳng định trên đúng vì KHÔNG AI thấy gì
// =========================================================================================

it('leaves the staff side of every table untouched', function () {
    $this->actingAs(User::factory()->create(), 'web');

    expect(Matter::withTrashed()->count())->toBe(4)
        ->and(Document::withTrashed()->count())->toBe(8)
        ->and(StageLog::count())->toBe(4)
        ->and(ClientRequest::withTrashed()->count())->toBe(5)
        ->and(ClientRequestReply::count())->toBe(3)
        ->and(StageLogView::count())->toBe(2)
        ->and(Client::count())->toBe(2);
});

// =========================================================================================
// RANH GIỚI ĐÃ BIẾT: `internal_note` được canh Ở TẦNG SERIALIZE, không ở tầng truy vấn
// =========================================================================================

/**
 * Ghim một RANH GIỚI, không một lỗ hổng bị bỏ qua — và ghim nó ở đây để Task 4 (dòng thời gian)
 * biết chính xác thứ gì đang bảo vệ mình.
 *
 * `HidesInternalAttributesFromPortal` là lớp thứ ba của SPEC §11 và nó chỉ tác động lên
 * `attributesToArray()` — một quyết định của M2, viết ra trong docblock của chính trait đó:
 * "mã nghiệp vụ đọc thẳng thuộc tính vẫn nhận giá trị thật". Hệ quả đo được, và nó KHÔNG hiển
 * nhiên:
 *
 *  - dòng tiến độ đã công bố là một bản ghi khách ĐƯỢC đọc, và `internal_note` nằm trong cùng
 *    bản ghi đó, nên một câu `where` trên chính cột ấy là một vị từ SQL hợp lệ và trả lời được;
 *  - `$log->internal_note` trong một view Blade cũng trả về chuỗi thật.
 *
 * Thứ ĐƯỢC BẢO ĐẢM là điều SPEC §11 viết: chuỗi đó không có trong response JSON lẫn HTML của
 * portal, vì không đường serialize nào mang nó ra. Thứ KHÔNG được bảo đảm là một màn hình portal
 * tự ý hỏi cột đó — và vì vậy **không màn hình portal nào được phép nhắc tới `internal_note`**,
 * bằng một câu `where`, một `select`, hay một lần in ra. Câu đó là một luật cho Task 3–6, và
 * test này là chỗ nó được viết ra thay vì được ngầm hiểu.
 *
 * Không tự sửa ở Task 2: đổi `HidesInternalAttributesFromPortal` để nó chặn cả `getAttribute()`
 * là đổi một quyết định của M2 có phạm vi rộng hơn task này, và kế hoạch M5 giao việc chứng minh
 * trait đó bằng đột biến cho Task 4. Đã báo lại thay vì làm lặng lẽ.
 */
it('protects internal_note at the serialize layer only, and says so out loud', function () {
    $this->actingAs($this->userA, 'client');

    $log = StageLog::findOrFail($this->visibleLog->id);

    expect($log->toArray())->not->toHaveKey('internal_note')
        ->and(json_encode($log->toArray(), JSON_UNESCAPED_UNICODE))->not->toContain(SWEEP_MARKER)
        // Và đây là ranh giới, ghim để nó không đổi trong im lặng theo cả hai chiều.
        ->and($log->internal_note)->toBe(SWEEP_MARKER)
        ->and(StageLog::where('internal_note', 'like', '%'.SWEEP_MARKER.'%')->count())->toBe(1)
        // Nhưng chỉ trên dòng khách ĐÃ được đọc: cột này không mở thêm một dòng nào.
        ->and(StageLog::where('internal_note', 'like', '%'.SWEEP_MARKER.'%')->pluck('id')->all())
        ->toBe([$this->visibleLog->id]);
});
