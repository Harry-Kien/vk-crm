<?php

use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Filament\Portal\Pages\MatterProgress;
use App\Filament\Portal\Pages\MyMatters;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientUser;
use App\Models\CommunicationLog;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\StageLog;
use App\Models\StageLogView;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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

    // Gắn tài liệu vào mục danh mục. Không có bước này thì `checklistItem()` trả `null` chỉ vì
    // factory để khoá ngoại rỗng, và `itemA->documents()` trả rỗng chỉ vì chưa ai gắn gì — hai
    // khẳng định xanh mà không đo một điều kiện nào. Gắn xong thì chúng đo thật: một mục danh
    // mục khách thấy được vẫn KHÔNG kéo theo tài liệu nhóm D treo dưới nó, và một tài liệu của
    // khách khác cầm sẵn trong tay vẫn không đi ngược lên mục của nó.
    $this->visibleDoc->update(['matter_checklist_item_id' => $this->itemA->id]);
    $this->internalDoc->forceFill(['matter_checklist_item_id' => $this->itemA->id])->saveQuietly();
    $this->foreignDoc->update(['matter_checklist_item_id' => $this->foreignItem->id]);

    // Đã xoá mềm — văn phòng đã rút mục này khỏi danh mục hồ sơ.
    $this->retractedItem = MatterChecklistItem::factory()->for($this->matterA)->create(['name' => 'Mục đã rút '.SWEEP_MARKER]);
    $this->retractedItem->delete();

    $this->visibleDeadline = Deadline::factory()->for($this->matterA)->published()->create(['name' => 'Nộp bổ sung']);
    $this->draftDeadline = Deadline::factory()->for($this->matterA)->create(['is_published' => false, 'name' => 'Nội bộ '.SWEEP_MARKER]);
    $this->foreignDeadline = Deadline::factory()->for($this->matterB)->published()->create(['name' => 'Của khách B '.SWEEP_MARKER]);
    $this->retractedDeadline = Deadline::factory()->for($this->matterA)->published()->create(['name' => 'Hạn đã rút '.SWEEP_MARKER]);
    $this->retractedDeadline->delete();

    // ---- Nhật ký liên lạc ---------------------------------------------------------------
    // SPEC §5 KHÔNG liệt kê nhật ký liên lạc trong cổng khách, và phán quyết 3 của M5 giữ nguyên
    // như vậy: không màn hình nào của M5 đọc bảng này. Nhưng scope và policy của nó vẫn trả lời
    // câu hỏi, nên chúng vẫn phải trả lời ĐÚNG — một bảng không có màn hình hôm nay là một bảng
    // có màn hình vào ngày ai đó viết nó.
    $this->visibleCommLog = CommunicationLog::factory()->for($this->matterA)->create([
        'is_visible_to_client' => true,
        'summary' => 'Đã gọi điện báo lịch hẹn.',
    ]);
    $this->internalCommLog = CommunicationLog::factory()->for($this->matterA)->create([
        'is_visible_to_client' => false,
        'summary' => 'Nội bộ '.SWEEP_MARKER,
    ]);
    $this->retractedCommLog = CommunicationLog::factory()->for($this->matterA)->create([
        'is_visible_to_client' => true,
        'summary' => 'Đã rút '.SWEEP_MARKER,
    ]);
    $this->retractedCommLog->delete();
    $this->foreignCommLog = CommunicationLog::factory()->for($this->matterB)->create([
        'is_visible_to_client' => true,
        'summary' => 'Của khách B '.SWEEP_MARKER,
    ]);

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
        ->and(CommunicationLog::pluck('id')->all())->toBe([$this->visibleCommLog->id])
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
        ->and(CommunicationLog::find($this->foreignCommLog->id))->toBeNull()
        // Tầng policy hỏi lại một cách độc lập, trên đúng những đối tượng đó.
        ->and($this->userA->can('view', $this->matterB))->toBeFalse()
        ->and($this->userA->can('view', $this->foreignLog))->toBeFalse()
        ->and($this->userA->can('view', $this->foreignDoc))->toBeFalse()
        ->and($this->userA->can('download', $this->foreignDoc))->toBeFalse()
        ->and($this->userA->can('view', $this->foreignRequest))->toBeFalse()
        ->and($this->userA->can('view', $this->foreignReply))->toBeFalse()
        ->and($this->userA->can('view', $this->foreignReceipt))->toBeFalse()
        ->and($this->userA->can('view', $this->foreignCommLog))->toBeFalse()
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
        ->and($this->itemA->documents()->pluck('id')->all())->toBe([$this->visibleDoc->id])
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
    $foreignDocRow = sweepAll(Document::class)->findOrFail($this->foreignDoc->id);
    $foreignReply = sweepAll(ClientRequestReply::class)->findOrFail($this->foreignReply->id);
    $internalDocRow = sweepAll(Document::class)->findOrFail($this->internalDoc->id);

    $this->actingAs($this->userA, 'client');

    expect($foreignDoc->matter()->first())->toBeNull()
        ->and($foreignReply->request()->first())->toBeNull()
        ->and($foreignDocRow->checklistItem()->first())->toBeNull()
        // Tài liệu nhóm D thì đi ngược lên ĐƯỢC — mục danh mục là thứ khách vốn thấy (SPEC §8.3),
        // và cái bị chặn là tài liệu, không phải mục. Ghim để không ai đọc nhầm chiều của luật.
        ->and($internalDocRow->checklistItem()->first()?->id)->toBe($this->itemA->id)
        // Vế dương: đi ngược từ một bản ghi khách A thấy được thì tới nơi.
        ->and($this->visibleDoc->matter()->first()?->id)->toBe($this->matterA->id)
        ->and($this->visibleDoc->checklistItem()->first()?->id)->toBe($this->itemA->id)
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

/**
 * **Bốn fixture này PHẢI có tệp thật.** `DocumentDownloadController` trả 404 cho một `Document`
 * không có media (`getFirstMedia('file') === null`), và nó trả 404 đó dù `Gate` có nói gì — nên
 * với những fixture không tệp, bốn dòng dưới đây xanh y hệt khi cổng quyền bị xoá đi. Đo được:
 * vòng đầu của Task 2 dựng chúng không media, và xoá hẳn `Gate::allows('download', ...)` khỏi
 * controller vẫn để cả bốn xanh. Vế dương ngay dưới KHÔNG bù được chỗ đó — nó gắn media trước,
 * tức nó đi một đường khác.
 *
 * Gắn tệp vào cả bốn đưa chúng về đúng nhánh mà tệp thật đi qua: chữ ký hợp lệ, tệp có mặt, và
 * thứ duy nhất còn đứng giữa khách và tệp của khách khác là `DocumentPolicy::download`.
 */
it('answers a signed download url of a hidden document with 404 and serves the own one', function () {
    $hostile = collect([$this->foreignDoc, $this->internalDoc, $this->hiddenDoc, $this->chainInternalV1])
        ->map(function (Document $document): Document {
            $document->addMedia(UploadedFile::fake()->create('tep-that.pdf', 10, 'application/pdf'))
                ->toMediaCollection('file');

            return $document->fresh();
        });

    // Tiền đề của phép đo, khẳng định chứ không giả định: cả bốn đều có tệp, nên một lần 404
    // dưới đây đến từ cổng quyền chứ không từ một `Document` rỗng.
    expect($hostile->every(fn (Document $document): bool => $document->getFirstMedia('file') !== null))
        ->toBeTrue();

    $this->actingAs($this->userA, 'client');

    $hostile->each(fn (Document $document) => $this->get($document->downloadUrlFor($this->userA))->assertNotFound());
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
        ->and(CommunicationLog::where('summary', 'like', $needle)->count())->toBe(0)
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
        ->and($this->userA->can('view', $this->retractedRequest))->toBeFalse()
        // Ba model soft-delete còn lại của cổng. Vòng đầu của Task 2 chỉ đặt `whereNull` lên
        // `Matter`, `Document` và `ClientRequest`, nên ba bảng dưới đây trả lại dòng đã rút ngay
        // khi một màn hình gọi `withTrashed()` — và Task 3, Task 4 render đúng hai trong số đó.
        ->and(Deadline::withTrashed()->pluck('id')->all())->toBe([$this->visibleDeadline->id])
        ->and(Deadline::withTrashed()->find($this->retractedDeadline->id))->toBeNull()
        ->and(MatterChecklistItem::withTrashed()->pluck('id')->all())->toBe([$this->itemA->id])
        ->and(MatterChecklistItem::withTrashed()->find($this->retractedItem->id))->toBeNull()
        ->and(CommunicationLog::withTrashed()->pluck('id')->all())->toBe([$this->visibleCommLog->id])
        ->and(CommunicationLog::withTrashed()->find($this->retractedCommLog->id))->toBeNull()
        // `onlyTrashed()` là cùng một cái công tắc, nhìn từ phía kia: nó KHÔNG được biến thành
        // một danh sách "những thứ văn phòng vừa rút đi".
        ->and(Deadline::onlyTrashed()->count())->toBe(0)
        ->and(MatterChecklistItem::onlyTrashed()->count())->toBe(0)
        ->and(CommunicationLog::onlyTrashed()->count())->toBe(0)
        ->and($this->userA->can('view', $this->retractedDeadline))->toBeFalse();

    // `withCount` mang `withTrashed()` xuống TRUY VẤN CON — cùng một công tắc, ở một chỗ mà một
    // phép đếm dễ được coi là vô hại. Một phép đếm cũng là một lần đọc.
    $counted = Matter::withCount([
        'deadlines' => fn ($q) => $q->withTrashed(),
        'checklistItems' => fn ($q) => $q->withTrashed(),
    ])->findOrFail($this->matterA->id);

    expect($counted->deadlines_count)->toBe(1)
        ->and($counted->checklist_items_count)->toBe(1);
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
    foreach ([Matter::class, StageLog::class, Document::class, Deadline::class, ClientRequest::class, ClientRequestReply::class, StageLogView::class, MatterChecklistItem::class, CommunicationLog::class] as $model) {
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
            // `is_published` phải được hỏi lại BÊN TRONG policy, không chỉ qua scope. Dòng trên
            // (`foreignLog`) xanh được nhờ điều kiện KHÁC — vụ việc của khách hàng khác — nên nó
            // không nhìn thấy được chỗ này; chỉ một dòng nháp thuộc CHÍNH vụ việc của khách A
            // mới cô lập đúng điều kiện "chưa công bố". Nếu thiếu, `RecordStageLogView` ghi một
            // biên bản nói rằng khách đã được cho xem một cập nhật văn phòng CHƯA công bố.
            ->and($this->userA->can('create', [StageLogView::class, $this->draftLog]))->toBeFalse()
            ->and($this->userA->can('create', [Document::class, $this->foreignItem]))->toBeFalse()
            // Nhật ký liên lạc: KHÔNG có màn hình portal nào đọc bảng này (phán quyết 3 của M5),
            // nên đây là một phép đo ghi lại, không một cửa đang mở. Trước vòng sửa này nhánh
            // khách của `CommunicationLogPolicy::view` còn TỆ HƠN một bản sao của scope: scope
            // rỗng thì nó trả `true` cho một dòng `is_visible_to_client = false`, tức cho đúng
            // cột mà SPEC §4.17 dựng lên để giữ nhật ký liên lạc ở trong nhà.
            ->and($this->userA->can('view', $this->internalCommLog))->toBeFalse()
            ->and($this->userA->can('view', $this->foreignCommLog))->toBeFalse()
            ->and($this->userA->can('view', $this->retractedCommLog))->toBeFalse()
            // Vế dương trong CÙNG ngữ cảnh thủng: policy không từ chối tất cả.
            ->and($this->userA->can('view', $this->matterA))->toBeTrue()
            ->and($this->userA->can('view', $this->visibleDoc))->toBeTrue()
            ->and($this->userA->can('view', $this->requestA))->toBeTrue()
            ->and($this->userA->can('view', $this->replyToA))->toBeTrue()
            ->and($this->userA->can('view', $this->receiptSibling))->toBeTrue()
            ->and($this->userA->can('view', $this->visibleDeadline))->toBeTrue()
            ->and($this->userA->can('view', $this->visibleCommLog))->toBeTrue();
    } finally {
        foreach ([Matter::class, StageLog::class, Document::class, Deadline::class, ClientRequest::class, ClientRequestReply::class, StageLogView::class, MatterChecklistItem::class, CommunicationLog::class] as $model) {
            $model::addGlobalScope(new ClientPortalScope);
        }
    }
});

/**
 * **Nghi thức ba tầng, vòng hai: gỡ CẢ `SoftDeletingScope`.**
 *
 * Nghi thức ngay trên làm rỗng `ClientPortalScope` và hỏi policy. Nhưng điều kiện "đã rút thì
 * không quay lại" có một chỗ nấp mà nghi thức đó không soi tới: `visibleToPortal()` chạy
 * `$record->newQuery()`, và truy vấn ấy vẫn còn `SoftDeletingScope` — một scope KHÁC. Nên một
 * policy KHÔNG nói gì về `deleted_at` vẫn xanh, và cái giữ nó là thứ mà đúng một lần
 * `withTrashed()` trong một màn hình sẽ gỡ ra. Đó là hình dạng mà vòng sửa này lên án ở ba model
 * khác; test này là chỗ nó không sống sót được ở tầng policy.
 *
 * Gỡ cả hai scope dựng lại đúng cảnh đó: màn hình gọi `withTrashed()`, ai đó quên một câu
 * `where`, và thứ duy nhất còn lại là policy đọc thuộc tính trên bản ghi trong tay.
 */
it('still refuses a retracted row when the soft delete scope is lifted as well, not only the portal one', function () {
    $models = [Matter::class, Document::class, ClientRequest::class, CommunicationLog::class];

    foreach ($models as $model) {
        $model::addGlobalScope(ClientPortalScope::class, function (): void {});
        $model::addGlobalScope(SoftDeletingScope::class, function (): void {});
    }

    try {
        $this->actingAs($this->userA, 'client');

        // Cả hai tầng truy vấn đã thủng — nếu không thì khẳng định bên dưới không đo tầng policy.
        expect(Matter::find($this->retractedMatter->id))->not->toBeNull()
            ->and(Document::find($this->retractedDoc->id))->not->toBeNull()
            ->and(ClientRequest::find($this->retractedRequest->id))->not->toBeNull()
            ->and(CommunicationLog::find($this->retractedCommLog->id))->not->toBeNull();

        expect($this->userA->can('view', $this->retractedMatter))->toBeFalse()
            ->and($this->userA->can('view', $this->retractedDoc))->toBeFalse()
            ->and($this->retractedDoc->isReleasedToPortal())->toBeFalse()
            ->and($this->userA->can('download', $this->retractedDoc))->toBeFalse()
            ->and($this->userA->can('view', $this->retractedRequest))->toBeFalse()
            ->and($this->userA->can('view', $this->retractedCommLog))->toBeFalse()
            // Vế dương trong CÙNG ngữ cảnh thủng: những bản ghi CHƯA rút vẫn đọc được, nên sáu
            // dòng trên là lời từ chối về `deleted_at` chứ không một lần từ chối tất cả.
            ->and($this->userA->can('view', $this->matterA))->toBeTrue()
            ->and($this->userA->can('view', $this->visibleDoc))->toBeTrue()
            ->and($this->visibleDoc->isReleasedToPortal())->toBeTrue()
            ->and($this->userA->can('view', $this->requestA))->toBeTrue()
            ->and($this->userA->can('view', $this->visibleCommLog))->toBeTrue();
    } finally {
        foreach ($models as $model) {
            $model::addGlobalScope(new ClientPortalScope);
            $model::addGlobalScope(new SoftDeletingScope);
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
        ->and(CommunicationLog::withTrashed()->count())->toBe(4)
        ->and(Client::count())->toBe(2);
});

// =========================================================================================
// RANH GIỚI ĐÃ BIẾT: NHỮNG BỀ MẶT KHÔNG HỀ CÓ `ClientPortalScope`
// =========================================================================================

/**
 * **Năm bề mặt dưới đây KHÔNG được cắt theo khách hàng, và sẽ không được cắt ở M5.** Mỗi test
 * dưới đây ghim HÀNH VI HÔM NAY, để một ngày ai đó đổi nó thì phải đổi cả test — tức phải biết
 * mình đang đổi một quyết định chứ không sửa một chi tiết. Và quan trọng hơn: **Task 3–6 thừa
 * hưởng một LUẬT, không một chỗ hở.**
 *
 * Luật đó, viết một lần cho cả M5: **không màn hình portal nào được truy vấn trực tiếp các bề
 * mặt này.** Mọi thứ màn hình portal đọc phải đi qua một model mang `RestrictedToClientPortal`
 * (`PortalCoverageTest` canh danh sách đó), hoặc qua một `Gate` hỏi đúng policy.
 *
 * Vì sao KHÔNG tự cắt chúng ở vòng sửa này — mỗi cái một lý do khác nhau, và không cái nào là
 * "chưa kịp làm":
 *
 *  - `ClientUser` CỐ Ý không mang scope: gọi `auth()` trong global scope của chính model xác
 *    thực thì guard nạp người dùng từ session sẽ đệ quy vô hạn (docblock `ClientUser`). Một
 *    quyết định của M2, không một chỗ quên.
 *  - `Activity` và `Media` là model của spatie, nằm trong `vendor/`. Gắn global scope vào model
 *    của một gói là một thay đổi có bán kính rộng hơn M5 — nó đổi hành vi của mọi màn hình nội
 *    bộ, mọi job, và của chính gói đó. `PortalCoverageTest` quét `app/Models`, nên hai model này
 *    nằm ngoài lưới ấy; đây là chỗ điều đó được nói ra thay vì được ngầm hiểu.
 *    `NotificationChannels\WebPush\PushSubscription` (M12 R8 — thiết bị nhận thông báo đẩy, của
 *    `laravel-notification-channels/webpush`) cùng hoàn cảnh: trong `vendor/`, không global scope,
 *    và mỗi dòng mang một endpoint — URL có quyền gửi thông báo tới máy đó. Lưới của nó là
 *    `tests/Feature/Push/PushSubscriptionAccessTest.php`: ngoài vài Action được liệt kê, mã trong
 *    `app/`, `routes/` và `resources/views/` không dùng lớp ấy qua `::` (kể cả `::class`), `new` hay
 *    `extends`, không viết tên lớp, tên bảng hay tên quan hệ thành chuỗi, và không route nào bind
 *    nó theo id (gợi ý kiểu, `instanceof` thì được) — còn lại chỉ `$user->pushSubscriptions()`.
 *    Gọi quan hệ ấy trên một người KHÁC người đang đăng nhập thì cú pháp không phân biệt được: test
 *    màn hình của trang thiết bị canh việc đó.
 *  - `DB::table()` đi thẳng xuống query builder: không có model thì không có global scope nào để
 *    chạy. Không một thiết kế nào chặn được nó; chỉ có luật "không dùng nó trong portal".
 *  - Quan hệ tới `User` trả về nhân sự, và nhân sự KHÔNG phải dữ liệu của một khách hàng nào để
 *    mà cắt theo khách hàng. Thứ cần canh ở đây là CỘT nào được đưa ra màn hình.
 */
it('names ClientUser as a surface that is NOT scoped: no portal screen may query it directly', function () {
    $this->actingAs($this->userA, 'client');

    $foreign = ClientUser::firstWhere('email', $this->userB->email);

    // Tầng truy vấn KHÔNG cắt — ghim lại hành vi, không tán thành nó.
    expect($foreign)->not->toBeNull()
        ->and($foreign->id)->toBe($this->userB->id)
        ->and($foreign->phone)->toBe($this->userB->phone)
        ->and(ClientUser::count())->toBe(3)
        // Tầng policy thì cắt, và ở đây nó là tầng DUY NHẤT: `ClientUserPolicy` từ chối sạch mọi
        // `ClientUser`, kể cả chính mình. Một màn hình hỏi `Gate` thì an toàn; một màn hình viết
        // `ClientUser::where(...)` thì không.
        ->and($this->userA->can('view', $this->userB))->toBeFalse()
        ->and($this->userA->can('view', $this->userA))->toBeFalse()
        ->and($this->userA->can('viewAny', ClientUser::class))->toBeFalse();
});

it('names the spatie activity log as a surface that is NOT scoped: no portal screen may query it directly', function () {
    $this->actingAs($this->userA, 'client');

    $payload = json_encode(Activity::query()->get()->toArray(), JSON_UNESCAPED_UNICODE);

    // Nhật ký hoạt động giữ cả tiêu đề vụ việc của khách hàng khác lẫn tiêu đề tài liệu nhóm D.
    expect(Activity::query()->count())->toBeGreaterThan(0)
        ->and($payload)->toContain(SWEEP_MARKER)
        // Và không có policy nào: một lần `Gate` trả `false` ở đây sẽ chỉ vì không tìm thấy
        // policy, không vì một lời từ chối có suy nghĩ.
        ->and(Gate::getPolicyFor(Activity::class))->toBeNull();
});

it('names the spatie media table as a surface that is NOT scoped: no portal screen may query it directly', function () {
    $this->internalDoc
        ->addMedia(UploadedFile::fake()->create('ke-hoach-'.SWEEP_MARKER.'.pdf', 10, 'application/pdf'))
        ->toMediaCollection('file');

    $this->actingAs($this->userA, 'client');

    expect(Media::query()->pluck('file_name')->implode(' '))->toContain(SWEEP_MARKER)
        ->and(Gate::getPolicyFor(Media::class))->toBeNull();
});

it('names the raw query builder as a surface that is NOT scoped: no portal screen may query it directly', function () {
    $this->actingAs($this->userA, 'client');

    // `DB::table()` không đi qua model, nên không một global scope nào chạy: mọi vụ việc, kể cả
    // vụ đã xoá mềm và vụ của khách hàng khác.
    expect(DB::table('matters')->count())->toBe(4)
        ->and(DB::table('matters')->pluck('title')->implode(' '))->toContain(SWEEP_MARKER)
        // Vế dương: cùng bảng đó, đọc qua model thì bị cắt đúng như mọi test bên trên.
        ->and(Matter::count())->toBe(1);
});

it('names the staff rows behind team and author as a surface that is NOT scoped: no portal screen may print their columns', function () {
    $this->actingAs($this->userA, 'client');

    $staff = $this->matterA->team()->first();
    $author = $this->replyToA->author()->first();

    // Quan hệ tới nhân sự mở hoàn toàn, và bản ghi trả về mang đủ cột — email, điện thoại, số
    // thẻ luật sư. Không cắt được theo khách hàng, vì nhân sự không thuộc về khách hàng nào;
    // luật nằm ở màn hình: portal chỉ được in TÊN, không cột nào khác.
    expect($staff)->not->toBeNull()
        ->and($staff->email)->not->toBeEmpty()
        ->and($author)->not->toBeNull()
        ->and($author->getAttributes())->toHaveKeys(['email', 'phone', 'bar_number'])
        // `UserPolicy` từ chối một `ClientUser`, nên một màn hình hỏi `Gate` vẫn an toàn.
        ->and($this->userA->can('view', $author))->toBeFalse();
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

// =========================================================================================
// 14. MỘT MÀN HÌNH THẬT: DANH SÁCH HỒ SƠ (M5 Task 3)
// =========================================================================================

/**
 * Mười ba mục trên đo các ĐƯỜNG TRUY VẤN. Mục này đo thứ cuối cùng người ta thật sự nhìn thấy:
 * **HTML đã kết xuất**, vì SPEC §11 viết lời hứa của mình bằng đúng chữ đó ("Response JSON và
 * HTML của portal không chứa...").
 *
 * Khoảng cách giữa hai thứ không phải lý thuyết. Một truy vấn đúng vẫn ra một màn hình sai nếu
 * màn hình ấy tự hỏi thêm một câu — `withoutGlobalScope()` cho tiện, một `Matter::find()` theo
 * tham số của request, một cột nội bộ in ra vì view cầm model trong tay. `SWEEP_MARKER` nằm sẵn
 * trong tiêu đề của vụ việc khách B, của vụ việc chưa công bố, của vụ việc đã rút, và trong
 * `internal_note` của cả hai dòng tiến độ — nên MỘT lần `toContain` phủ hết các đường ấy cùng
 * lúc.
 *
 * Mở rộng ở đây, không ở `MyMattersTest`: tệp này là lưới quét của cả cổng, và kế hoạch M5 Task 2
 * đã giao cho nó việc được chạy lại và nới rộng sau mỗi task có màn hình.
 */
it('never paints a hidden row into the rendered matter list', function () {
    Filament::setCurrentPanel('portal');

    $html = $this->actingAs($this->userA, 'client')
        // `showAll`: khách A có đúng một hồ sơ nhìn thấy được, nên không có cờ này thì trang
        // chuyển thẳng sang trang chi tiết và không còn gì để quét.
        ->livewire(MyMatters::class, ['showAll' => true])
        ->html();

    // Vế dương trước: nếu trang không vẽ gì thì khẳng định bên dưới xanh mà không đo gì cả.
    expect($html)->toContain($this->matterA->code)
        ->and($html)->not->toContain(SWEEP_MARKER)
        ->and($html)->not->toContain($this->matterB->code)
        ->and($html)->not->toContain($this->hiddenA->code)
        ->and($html)->not->toContain($this->retractedMatter->code);
});

// =========================================================================================
// ĐƯỜNG MÀ M5 TASK 4 VỪA TẠO RA: HTML THẬT CỦA TRANG CHI TIẾT HỒ SƠ
// =========================================================================================

/**
 * Mở rộng bắt buộc sau mỗi task có màn hình (kế hoạch M5 Task 2 mục 6): thêm **đúng** đường mà
 * màn hình mới vừa mở ra. Mọi `it()` phía trên đo các truy vấn và các cổng quyền; đường này đo
 * thứ khác hẳn — **những byte thật sự được gửi tới trình duyệt của khách**.
 *
 * Nó không thừa so với `MatterProgressTest`: tệp kia dựng fixture riêng cho từng điều kiện, còn
 * ở đây trang chi tiết được vẽ ra giữa **toàn bộ** vườn thú của tệp này (hồ sơ khách B, hồ sơ
 * chưa mở cho khách, hồ sơ đã rút, chuỗi version nhóm D, mốc hạn chưa công bố, yêu cầu của khách
 * khác, và một `internal_note` mang chuỗi đánh dấu) — một lần quét bằng `str_contains` trên một
 * chuỗi duy nhất, đúng cách SPEC §11 mô tả phép thử ghi chú nội bộ.
 */
it('never renders a hidden row into the html of the portal matter detail page', function () {
    // `must_change_password` còn bật thì `RequirePortalPasswordChange` chặn mọi trang cổng
    // (SPEC §8.1) và test sẽ chết ở một cổng SỚM HƠN điều kiện nó nêu tên.
    $this->userA->forceFill(['must_change_password' => false])->save();

    $html = $this->actingAs($this->userA, 'client')
        ->get(MatterProgress::getUrl(['record' => $this->matterA->id], panel: 'portal'))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain(SWEEP_MARKER)
        // Vế dương: trang KHÔNG trắng — đúng những gì khách A được đọc vẫn ở đó.
        ->and($html)->toContain('Toà đã nhận hồ sơ.')
        ->and($html)->toContain('Quyết định của toà')
        ->and($html)->toContain('Chứng minh nhân dân')
        ->and($html)->toContain('Nộp bổ sung')
        // Và bản nhóm D của chuỗi version không lên trang dù bản kề nó thì có.
        ->and($html)->toContain('Bản chính thức');
});

it('answers the detail page of every matter the client may not read with 404', function () {
    $this->userA->forceFill(['must_change_password' => false])->save();

    foreach ([$this->matterB, $this->hiddenA, $this->retractedMatter] as $matter) {
        $this->actingAs($this->userA, 'client')
            ->get(MatterProgress::getUrl(['record' => $matter->id], panel: 'portal'))
            ->assertNotFound();
    }

    // Vế dương: cùng đường, cùng tài khoản, hồ sơ của chính mình thì mở.
    $this->actingAs($this->userA, 'client')
        ->get(MatterProgress::getUrl(['record' => $this->matterA->id], panel: 'portal'))
        ->assertOk();
});
