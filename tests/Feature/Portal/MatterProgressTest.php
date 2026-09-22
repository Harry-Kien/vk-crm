<?php

use App\Actions\Document\ChecklistProgress;
use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Filament\Portal\Pages\MatterProgress;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\StageLog;
use App\Models\StageLogView;
use App\Support\Scopes\ClientPortalScope;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Trang chi tiết hồ sơ của cổng khách hàng — SPEC §8.3.
 *
 * Đây là màn hình mà cả M0–M4 tồn tại để dẫn tới: một khách hàng, trên điện thoại, đọc vụ việc
 * của mình đang ở đâu, còn phải làm gì, và văn phòng đã nói gì. Nó cũng là nơi **bằng chứng pháp
 * lý** được ghi (`stage_log_views`, SPEC §4.18), nên phần lớn tệp này đo hai thứ khác nhau:
 * *khách đọc được đúng những gì được phép*, và *biên bản đã xem được ghi đúng một lần*.
 *
 * Mỗi khẳng định âm ở đây đi kèm vế dương của nó **trong cùng một test**: một test nói "thứ X
 * không xuất hiện" xanh y hệt khi trang trắng, và khi đó nó không còn đo gì nữa.
 */
const PROGRESS_MARKER = 'GHI-CHU-NOI-BO-4K2X';

beforeEach(function () {
    $this->client = Client::factory()->create(['name' => 'Khách hàng A']);
    $this->clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);

    $this->matter = Matter::factory()->for($this->client)->create([
        'is_published_to_portal' => true,
        'title' => 'Tranh chấp quyền sử dụng đất',
        'stage' => 'collecting_documents',
    ]);
});

/** URL thật của trang, đi qua đúng tên route mà panel đăng ký. */
function progressUrl(Matter|int $matter): string
{
    return MatterProgress::getUrl(
        ['record' => $matter instanceof Matter ? $matter->getKey() : $matter],
        panel: 'portal',
    );
}

/**
 * Chỉ phần trang do Task 4 vẽ ra, cắt bằng hai mốc trong chính view.
 *
 * Cần cắt vì phần còn lại của tài liệu là khung của Filament (thanh điều hướng, script, chân
 * trang) — nó có `<a>` riêng và biến CSS riêng, nên một phép đo trên cả trang sẽ đo cả thứ task
 * này không viết ra và không sửa được.
 */
/** Chỉ khối 2 — "Việc anh/chị cần làm" — cắt ra khỏi phần còn lại của trang. */
function progressTodoBlock(string $html): string
{
    $start = strpos($html, 'data-portal-block="2"');
    $end = strpos($html, 'data-portal-block="3"');

    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();

    return substr($html, (int) $start, (int) $end - (int) $start);
}

function progressRegion(string $html): string
{
    $start = strpos($html, 'data-portal-page="matter-progress"');
    $end = strpos($html, 'data-portal-end="matter-progress"');

    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();

    return substr($html, (int) $start, (int) $end - (int) $start);
}

/** @return list<string> giá trị `data-portal-block` theo đúng thứ tự xuất hiện trong HTML. */
function blocksInOrder(string $html): array
{
    preg_match_all('/data-portal-block="([^"]+)"/', $html, $matches);

    return $matches[1];
}

// =========================================================================================
// BẢY KHỐI, ĐÚNG THỨ TỰ SPEC §8.3 LIỆT KÊ
// =========================================================================================

/**
 * Thứ tự được đo bằng CẤU TRÚC (`data-portal-block`), không bằng câu chữ: một bản dịch đổi lời
 * không được làm test này đỏ, còn một lần đổi chỗ hai khối thì phải.
 */
it('renders the seven blocks of SPEC 8.3 in that exact order', function () {
    MatterChecklistItem::factory()->for($this->matter)->create([
        'name' => 'Giấy chứng nhận quyền sử dụng đất',
        'is_required' => true,
        'status' => ChecklistItemStatus::Missing,
    ]);

    $html = $this->actingAs($this->clientUser, 'client')
        ->get(progressUrl($this->matter))
        ->assertOk()
        ->getContent();

    expect(blocksInOrder($html))->toBe(['1', '2', '3', '4', '5', '6', '7']);
});

/** Các tiêu đề là tiếng Việt thật, không phải khoá dịch chưa có tệp — nếu không thì test trên vô nghĩa. */
it('serves a real Vietnamese heading for each of the seven blocks', function () {
    MatterChecklistItem::factory()->for($this->matter)->create(['status' => ChecklistItemStatus::Missing, 'is_required' => true]);

    $response = $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk();

    foreach (['status', 'todo', 'timeline', 'checklist', 'documents', 'deadlines', 'requests'] as $block) {
        $heading = __("portal_progress.blocks.{$block}.heading");

        expect($heading)->not->toContain('portal_progress.');

        $response->assertSee($heading, escape: false);
    }
});

// =========================================================================================
// KHỐI 1 — TÌNH TRẠNG HIỆN TẠI
// =========================================================================================

it('shows the client label and the client description of the current stage, never the internal label', function () {
    $stage = $this->matter->matterType->stage('collecting_documents');

    $this->actingAs($this->clientUser, 'client')
        ->get(progressUrl($this->matter))
        ->assertOk()
        ->assertSee($stage->client_label, escape: false)
        ->assertSee($stage->client_description, escape: false)
        // `label` nội bộ ("Thu thập hồ sơ") khác `client_label` ("Đang thu thập giấy tờ") ở bộ
        // giai đoạn mẫu, nên vắng mặt của nó là một phép đo chứ không phải một trùng hợp.
        ->assertDontSee($stage->label, escape: false);
});

// =========================================================================================
// KHỐI 2 — "VIỆC ANH/CHỊ CẦN LÀM": BIẾN MẤT HOÀN TOÀN KHI KHÔNG CÓ VIỆC
// =========================================================================================

it('drops the whole "what you need to do" block when there is nothing to do', function () {
    MatterChecklistItem::factory()->for($this->matter)->create([
        'name' => 'Chứng minh nhân dân',
        'status' => ChecklistItemStatus::Accepted,
        'is_required' => true,
    ]);
    StageLog::factory()->for($this->matter)->published()->create(['client_action' => null]);

    $html = $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk()->getContent();

    expect(blocksInOrder($html))->toBe(['1', '3', '4', '5', '6', '7'])
        ->and($html)->not->toContain(__('portal_progress.blocks.todo.heading'))
        // Không phải một dòng "không có việc gì": khối này BIẾN MẤT (SPEC §8.3 mục 2).
        ->and($html)->not->toContain(__('portal_progress.blocks.todo.empty'))
        // Vế dương: trang vẫn dựng, sáu khối kia vẫn ở đó.
        ->and($html)->toContain(__('portal_progress.blocks.timeline.heading'));
});

it('brings the block back when a required document is still missing', function () {
    MatterChecklistItem::factory()->for($this->matter)->create([
        'name' => 'Giấy chứng nhận quyền sử dụng đất',
        'status' => ChecklistItemStatus::Missing,
        'is_required' => true,
    ]);
    StageLog::factory()->for($this->matter)->published()->create(['client_action' => null]);

    $html = $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk()->getContent();

    expect(blocksInOrder($html))->toBe(['1', '2', '3', '4', '5', '6', '7'])
        ->and($html)->toContain(__('portal_progress.blocks.todo.heading'))
        ->and($html)->toContain('Giấy chứng nhận quyền sử dụng đất');
});

/** Vế thứ hai của cùng điều kiện: `client_action` của dòng cập nhật MỚI NHẤT, một mình, cũng đủ. */
it('brings the block back for the client_action of the newest published update alone', function () {
    MatterChecklistItem::factory()->for($this->matter)->create([
        'status' => ChecklistItemStatus::Accepted,
        'is_required' => true,
    ]);
    StageLog::factory()->for($this->matter)->published()->create([
        'occurred_at' => now()->subDays(2),
        'client_action' => 'Câu cũ, không còn phải làm nữa.',
    ]);
    StageLog::factory()->for($this->matter)->published()->create([
        'occurred_at' => now(),
        'client_action' => 'Anh/chị mang bản gốc sổ đỏ tới văn phòng trước thứ Sáu.',
    ]);

    $html = $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk()->getContent();

    expect(blocksInOrder($html))->toBe(['1', '2', '3', '4', '5', '6', '7'])
        ->and($html)->toContain('Anh/chị mang bản gốc sổ đỏ tới văn phòng trước thứ Sáu.');

    // Khối 2 chỉ lấy dòng MỚI NHẤT: câu cũ vẫn đọc được ở dòng thời gian của nó, nhưng không
    // được nhắc lại như một việc đang treo.
    $todo = substr($html, (int) strpos($html, 'data-portal-block="2"'), (int) strpos($html, 'data-portal-block="3"') - (int) strpos($html, 'data-portal-block="2"'));

    expect($todo)->not->toContain('Câu cũ, không còn phải làm nữa.');
});

// =========================================================================================
// KHỐI 3 — DIỄN BIẾN: CHỈ DÒNG ĐÃ CÔNG BỐ, VÀ `from_stage === to_stage` LÀ DÒNG KHÔNG ĐỔI
// =========================================================================================

it('puts published updates on the timeline, newest first, and never an unpublished one', function () {
    StageLog::factory()->for($this->matter)->published()->create([
        'occurred_at' => now()->subDays(5),
        'public_content' => 'Văn phòng đã nhận đủ giấy tờ ban đầu.',
    ]);
    StageLog::factory()->for($this->matter)->published()->create([
        'occurred_at' => now()->subDay(),
        'public_content' => 'Toà đã nhận đơn khởi kiện.',
    ]);
    StageLog::factory()->for($this->matter)->internalOnly()->create([
        'internal_note' => 'Chưa công bố '.PROGRESS_MARKER,
    ]);

    $html = $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk()->getContent();

    expect($html)->toContain('Toà đã nhận đơn khởi kiện.')
        ->and($html)->toContain('Văn phòng đã nhận đủ giấy tờ ban đầu.')
        ->and($html)->not->toContain(PROGRESS_MARKER)
        ->and(strpos($html, 'Toà đã nhận đơn khởi kiện.'))
        ->toBeLessThan(strpos($html, 'Văn phòng đã nhận đủ giấy tờ ban đầu.'));
});

it('shows the four parts SPEC 8.3 asks of every timeline entry', function () {
    StageLog::factory()->for($this->matter)->published()->create([
        'public_content' => 'Toà đã thụ lý vụ việc của anh/chị.',
        'next_step' => 'Toà sẽ mời các bên lên hoà giải.',
        'client_action' => 'Anh/chị giữ điện thoại để chúng tôi báo lịch.',
        'expected_next_update_at' => now()->addDays(21)->toDateString(),
    ]);

    $this->actingAs($this->clientUser, 'client')
        ->get(progressUrl($this->matter))
        ->assertOk()
        ->assertSee('Toà đã thụ lý vụ việc của anh/chị.', escape: false)
        ->assertSee('Toà sẽ mời các bên lên hoà giải.', escape: false)
        ->assertSee('Anh/chị giữ điện thoại để chúng tôi báo lịch.', escape: false)
        ->assertSee(now()->addDays(21)->format('d/m/Y'), escape: false);
});

/**
 * **`from_stage === to_stage`, KHÔNG `from_stage === null`** (SPEC §4.8 và §6.3 mâu thuẫn; M3 đi
 * theo §6.3, và §6.3 viết thẳng "`from_stage` và `to_stage` đều bằng giai đoạn hiện tại").
 *
 * Ba dòng dưới đây được chọn để phân biệt được hai cách đọc — đó là toàn bộ lý do test này tồn
 * tại. Đổi điều kiện sang `from_stage === null` thì dòng thứ hai (`filed → filed`) lập tức được
 * vẽ như một lần chuyển giai đoạn, và test đỏ. Một fixture `null → null` một mình thì KHÔNG phân
 * biệt được gì cả: cả hai cách đọc đều gọi nó là dòng không đổi.
 */
it('marks a real transition and leaves an unchanged-stage update unmarked', function () {
    StageLog::factory()->for($this->matter)->published()->transition('drafting', 'filed')->create([
        'occurred_at' => now()->subDays(3),
        'public_content' => 'Đơn đã được nộp cho toà.',
    ]);
    StageLog::factory()->for($this->matter)->published()->transition('filed', 'filed')->create([
        'occurred_at' => now()->subDay(),
        'public_content' => 'Tuần này chưa có văn bản mới từ toà, đây là điều bình thường.',
    ]);

    $html = $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk()->getContent();

    $transitionLabel = __('portal_progress.timeline.moved_to', [
        'stage' => $this->matter->matterType->stage('filed')->client_label,
    ]);

    // Đúng MỘT lần: của dòng `drafting → filed`, không của dòng `filed → filed`.
    expect(substr_count($html, $transitionLabel))->toBe(1)
        ->and($html)->toContain('Tuần này chưa có văn bản mới từ toà, đây là điều bình thường.');
});

// =========================================================================================
// KHỐI 4 — HỒ SƠ GIẤY TỜ
// =========================================================================================

it('shows the full rejection reason, never a truncated one', function () {
    $reason = 'Ảnh chụp bị mờ ở góc dưới bên phải nên không đọc được số thửa. Anh/chị chụp lại '
        .'dưới ánh sáng tự nhiên, để phẳng tờ giấy, và chụp đủ cả bốn góc giúp chúng tôi.';

    MatterChecklistItem::factory()->for($this->matter)->create([
        'name' => 'Sổ đỏ',
        'status' => ChecklistItemStatus::Rejected,
        'rejection_reason' => $reason,
    ]);

    $region = progressRegion(
        $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk()->getContent()
    );

    expect($region)->toContain(e($reason))
        // Không cắt ngắn: không một dấu ba chấm nào trong phần trang này.
        ->and($region)->not->toContain('…')
        ->and($region)->not->toContain('...');
});

it('takes X of Y from the ChecklistProgress action instead of counting again', function () {
    MatterChecklistItem::factory()->for($this->matter)->count(2)->create([
        'is_required' => true,
        'status' => ChecklistItemStatus::Accepted,
    ]);
    MatterChecklistItem::factory()->for($this->matter)->create([
        'is_required' => true,
        'status' => ChecklistItemStatus::Missing,
    ]);
    // Không bắt buộc, KHÔNG có tài liệu nào: theo đính chính §4.10 nó không nằm ở vế nào cả.
    MatterChecklistItem::factory()->for($this->matter)->create([
        'is_required' => false,
        'status' => ChecklistItemStatus::NotApplicable,
    ]);

    $progress = app(ChecklistProgress::class)->handle($this->matter);

    expect($progress)->toBe(['submitted' => 2, 'total' => 3]);

    $this->actingAs($this->clientUser, 'client')
        ->get(progressUrl($this->matter))
        ->assertOk()
        ->assertSee(__('portal_progress.checklist.progress', $progress), escape: false);
});

// =========================================================================================
// KHỐI 5 — TÀI LIỆU: HAI CỜ ĐỘC LẬP, VÀ NHÓM D KHÔNG BAO GIỜ
// =========================================================================================

it('shows a viewable document without a download button until the second flag is on', function () {
    $viewOnly = Document::factory()->for($this->matter)->group(DocumentGroup::Issued)->create([
        'title' => 'Thông báo thụ lý của toà',
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => false,
    ]);
    $downloadable = Document::factory()->for($this->matter)->group(DocumentGroup::Issued)->create([
        'title' => 'Quyết định của toà',
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
    ]);

    $html = $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk()->getContent();

    expect($html)->toContain('Thông báo thụ lý của toà')
        ->and($html)->toContain('Quyết định của toà')
        // Đúng MỘT nút tải, và nó trỏ tới bản ghi được phép tải.
        ->and(substr_count($html, 'documents/'.$downloadable->getKey().'/download'))->toBeGreaterThan(0)
        ->and(substr_count($html, 'documents/'.$viewOnly->getKey().'/download'))->toBe(0)
        // Đường tải luôn là URL đã ký của M4, không bao giờ một đường dẫn đĩa.
        ->and($html)->toContain('signature=')
        ->and($html)->not->toContain('/storage/');
});

/**
 * **Nhóm D biến mất kể cả khi CẢ HAI cờ khách đều bật** (SPEC §4.11, §11 "Tài liệu nội bộ").
 *
 * Hai điều kiện được tách ra để test đo đúng thứ nó nêu tên:
 *  - `forceClientFlags()` ghi thẳng vào bảng, vòng qua hook `saving` của model — nếu không thì
 *    dòng nhóm D lưu ra với hai cờ `false` và mọi khẳng định dưới đây xanh nhờ CÁI CỜ;
 *  - global scope bị thay bằng một scope rỗng, đúng hình dạng "ai đó quên một câu `where`" —
 *    nếu không thì tầng truy vấn đã loại dòng đó trước khi trang kịp hỏi một câu nào.
 *
 * Còn lại đúng một thứ đang giữ: lần hỏi `Gate` trên từng tài liệu ở chính trang này.
 */
it('keeps a group D document off the page even with both client flags on and the scope emptied', function () {
    $internal = forceClientFlags(
        Document::factory()->for($this->matter)->group(DocumentGroup::Internal)->create([
            'title' => 'Kế hoạch tranh tụng '.PROGRESS_MARKER,
            'status' => DocumentStatus::Published,
        ])
    );
    Document::factory()->for($this->matter)->group(DocumentGroup::Issued)->create([
        'title' => 'Quyết định của toà',
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
    ]);

    expect($internal->client_can_view)->toBeTrue()
        ->and($internal->client_can_download)->toBeTrue();

    Document::addGlobalScope(ClientPortalScope::class, function (): void {});

    try {
        $html = $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk()->getContent();
    } finally {
        Document::addGlobalScope(new ClientPortalScope);
    }

    expect($html)->not->toContain(PROGRESS_MARKER)
        ->and($html)->not->toContain('Kế hoạch tranh tụng')
        // Vế dương trong CÙNG ngữ cảnh thủng: trang không từ chối tất cả.
        ->and($html)->toContain('Quyết định của toà');
});

// =========================================================================================
// KHỐI 6 — MỐC THỜI HẠN: CHỈ `is_published`
// =========================================================================================

it('lists only published deadlines', function () {
    Deadline::factory()->for($this->matter)->published()->create(['name' => 'Nộp bản tự khai']);
    Deadline::factory()->for($this->matter)->create([
        'is_published' => false,
        'name' => 'Mốc nội bộ '.PROGRESS_MARKER,
    ]);

    $this->actingAs($this->clientUser, 'client')
        ->get(progressUrl($this->matter))
        ->assertOk()
        ->assertSee('Nộp bản tự khai', escape: false)
        ->assertDontSee(PROGRESS_MARKER, escape: false);
});

// =========================================================================================
// GHI CHÚ NỘI BỘ — SPEC §11 "Ghi chú nội bộ"
// =========================================================================================

/**
 * `internal_note` được canh **ở tầng serialize** (`HidesInternalAttributesFromPortal`), và Task 2
 * đã ghim ra rằng `$log->internal_note` trong Blade vẫn trả về chuỗi thật. Nên điều test này đo
 * là điều SPEC §11 hứa: chuỗi đánh dấu không có ở BẤT KỲ ĐÂU trong HTML lẫn JSON của response.
 *
 * Chuỗi được đặt vào dòng khách ĐƯỢC đọc — một dòng đã công bố mà trang chắc chắn vẽ ra — chứ
 * không vào một dòng nháp: một dòng nháp đã bị loại ở một cổng sớm hơn, và khi đó test chết
 * trước khi chạm tới điều kiện nó nêu tên.
 */
it('never leaks internal_note into the html or the json of the page', function () {
    $log = StageLog::factory()->for($this->matter)->published()->create([
        'public_content' => 'Toà đã nhận đơn khởi kiện.',
        'internal_note' => PROGRESS_MARKER,
    ]);

    $html = $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk()->getContent();

    expect($html)->not->toContain(PROGRESS_MARKER)
        // Vế dương: nội dung khách ĐƯỢC đọc nằm trong cùng bản ghi đó và vẫn hiện ra.
        ->and($html)->toContain('Toà đã nhận đơn khởi kiện.')
        // Và ranh giới, ghim lại ở đây để nó không đổi trong im lặng: cột thật vẫn còn giá trị.
        ->and($log->fresh()->internal_note)->toBe(PROGRESS_MARKER);
});

// =========================================================================================
// CÁCH LY: URL CỦA KHÁCH KHÁC → 404 (SPEC §11, §10.10)
// =========================================================================================

/**
 * **Trang tự hỏi `Gate`, và đây là phép đo của câu đó.**
 * `Filament\Pages\Concerns\CanAuthorizeAccess::canAccess()` mặc định trả `true` cho trang tuỳ
 * chỉnh (đã đọc trong vendor ở Task 2), nên middleware 404 chỉ đổi HÌNH DẠNG của một lời từ
 * chối đã có — nó không bao giờ tự sinh ra một lời từ chối. Test khẳng định cả hai vế cùng lúc:
 * cổng tĩnh MỞ, mà request vẫn 404.
 */
it('answers the matter of another client with 404, although the static page gate is open', function () {
    $otherClient = Client::factory()->create();
    $otherMatter = Matter::factory()->for($otherClient)->create(['is_published_to_portal' => true]);

    expect(MatterProgress::canAccess())->toBeTrue();

    $this->actingAs($this->clientUser, 'client')
        ->get(progressUrl($otherMatter))
        ->assertNotFound();

    // Vế dương: cùng một đường, cùng một tài khoản, hồ sơ của chính mình thì mở.
    $this->actingAs($this->clientUser, 'client')
        ->get(progressUrl($this->matter))
        ->assertOk();
});

it('answers a matter that is not published to the portal with 404, even for its own client', function () {
    $hidden = Matter::factory()->for($this->client)->unpublished()->create();

    $this->actingAs($this->clientUser, 'client')->get(progressUrl($hidden))->assertNotFound();
});

it('answers a soft deleted matter with 404', function () {
    $this->matter->delete();

    $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertNotFound();
});

it('answers an id that does not exist with 404', function () {
    $this->actingAs($this->clientUser, 'client')->get(progressUrl(999999))->assertNotFound();
});

// =========================================================================================
// BIÊN BẢN ĐÃ XEM — SPEC §4.18, và đây là BẰNG CHỨNG, không phải thống kê
// =========================================================================================

it('writes exactly one view receipt per rendered published update', function () {
    $published = StageLog::factory()->for($this->matter)->published()->create();
    $draft = StageLog::factory()->for($this->matter)->internalOnly()->create();

    $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk();

    expect(StageLogView::withoutGlobalScope(ClientPortalScope::class)->count())->toBe(1)
        ->and(StageLogView::withoutGlobalScope(ClientPortalScope::class)->first()->stage_log_id)->toBe($published->getKey())
        // Vế âm trong cùng phép đo: một dòng CHƯA công bố không sinh biên bản, vì khách chưa
        // được cho xem nó.
        ->and(StageLogView::withoutGlobalScope(ClientPortalScope::class)->where('stage_log_id', $draft->getKey())->exists())
        ->toBeFalse();
});

/**
 * **Lần xem thứ hai không dời `viewed_at`.** Giá trị của bảng này là dấu thời gian của lần đọc
 * ĐẦU; ghi đè là xoá mất đúng con số nó tồn tại để giữ.
 */
it('never moves viewed_at on a second visit', function () {
    StageLog::factory()->for($this->matter)->published()->create();

    $this->travelTo(now()->subDays(3));
    $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk();

    $first = StageLogView::withoutGlobalScope(ClientPortalScope::class)->firstOrFail();

    $this->travelBack();
    $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk();

    $after = StageLogView::withoutGlobalScope(ClientPortalScope::class)->firstOrFail();

    expect(StageLogView::withoutGlobalScope(ClientPortalScope::class)->count())->toBe(1)
        ->and($after->viewed_at->toDateTimeString())->toBe($first->viewed_at->toDateTimeString())
        // Vế dương của phép đo thời gian: hai thời điểm THẬT SỰ khác nhau, nếu không thì khẳng
        // định trên xanh vì đồng hồ đứng yên chứ không vì mã đúng.
        ->and($after->viewed_at->isBefore(now()->subDay()))->toBeTrue();
});

it('writes a receipt for the account that is reading, not for the other account of the same client', function () {
    $sibling = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);
    $log = StageLog::factory()->for($this->matter)->published()->create();

    $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk();

    $receipts = StageLogView::withoutGlobalScope(ClientPortalScope::class)->get();

    expect($receipts)->toHaveCount(1)
        ->and($receipts->first()->client_user_id)->toBe($this->clientUser->getKey())
        ->and($receipts->first()->stage_log_id)->toBe($log->getKey())
        ->and($receipts->first()->ip)->not->toBeEmpty()
        ->and($receipts->where('client_user_id', $sibling->getKey()))->toHaveCount(0);
});

it('writes no receipt at all when the page refuses to open', function () {
    $otherClient = Client::factory()->create();
    $otherMatter = Matter::factory()->for($otherClient)->create(['is_published_to_portal' => true]);
    StageLog::factory()->for($otherMatter)->published()->create();

    $this->actingAs($this->clientUser, 'client')->get(progressUrl($otherMatter))->assertNotFound();

    expect(StageLogView::withoutGlobalScope(ClientPortalScope::class)->count())->toBe(0);
});

// =========================================================================================
// DÙNG ĐƯỢC Ở 375px — cấu trúc, không phải ảnh chụp màn hình
// =========================================================================================

it('lays the page out in one column with no table and no empty state left blank', function () {
    $html = $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk()->getContent();

    $page = progressRegion($html);

    expect($page)->not->toContain('<table')
        // Hồ sơ không có tài liệu, không có mốc hạn, không có dòng nào: cả ba trạng thái rỗng
        // phải là một câu hướng dẫn, không phải một khoảng trắng.
        ->and($page)->toContain(__('portal_progress.blocks.documents.empty'))
        ->and($page)->toContain(__('portal_progress.blocks.deadlines.empty'))
        ->and($page)->toContain(__('portal_progress.blocks.timeline.empty'))
        ->and($page)->toContain(__('portal_progress.blocks.checklist.empty'));
});

it('paints only with colour variables the panel actually registers', function () {
    MatterChecklistItem::factory()->for($this->matter)->create(['status' => ChecklistItemStatus::Rejected, 'rejection_reason' => str_repeat('Lý do đủ dài. ', 3)]);
    Document::factory()->for($this->matter)->group(DocumentGroup::Issued)->create([
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
    ]);
    Deadline::factory()->for($this->matter)->published()->create();
    StageLog::factory()->for($this->matter)->published()->create();

    $html = $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk()->getContent();

    $page = progressRegion($html);

    expect(colourVariablesIn($page))->not->toBeEmpty()
        ->and(unregisteredColourVariables($page))->toBe([]);
});

it('gives every tappable thing on the page a 44px target', function () {
    Document::factory()->for($this->matter)->group(DocumentGroup::Issued)->create([
        'title' => 'Quyết định của toà',
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
    ]);

    $html = $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk()->getContent();

    $page = progressRegion($html);

    preg_match_all('/<a\b[^>]*>/', $page, $links);

    expect($links[0])->not->toBeEmpty();

    foreach ($links[0] as $link) {
        expect($link)->toContain('min-height: 44px');
    }
});

// =========================================================================================
// NGHI THỨC BA TẦNG — tầng 2: thay global scope bằng một scope rỗng, TRANG vẫn phải từ chối
// =========================================================================================

/**
 * Chạy `$callback` trong lúc global scope của `$models` đã bị thay bằng một scope **rỗng** —
 * đúng hình dạng "ai đó quên một câu `where`".
 *
 * Không có thiết bị này thì mọi khẳng định âm ở tệp trên xanh nhờ TẦNG TRUY VẤN, và những lần
 * hỏi `Gate` trong `MatterProgress` — tức tầng thứ hai, tầng mà trang tự dựng — không hề được
 * đo. Đo được: xoá lần hỏi `Gate` tương ứng thì đúng những test dưới đây đỏ, còn những test ở
 * trên vẫn xanh.
 *
 * @param  list<class-string<Model>>  $models
 */
function withEmptyPortalScope(array $models, Closure $callback): mixed
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

it('still answers another clients matter with 404 when the matter scope forgets its rule', function () {
    $otherClient = Client::factory()->create();
    $otherMatter = Matter::factory()->for($otherClient)->create([
        'is_published_to_portal' => true,
        'title' => 'Hồ sơ khách B '.PROGRESS_MARKER,
    ]);
    $unpublished = Matter::factory()->for($this->client)->unpublished()->create();

    withEmptyPortalScope([Matter::class], function () use ($otherMatter, $unpublished) {
        // Tầng truy vấn đã thủng — nếu không thì khẳng định dưới không đo tầng nào cả.
        expect(Matter::find($otherMatter->getKey()))->not->toBeNull()
            ->and(Matter::find($unpublished->getKey()))->not->toBeNull();

        $this->actingAs($this->clientUser, 'client')->get(progressUrl($otherMatter))->assertNotFound();
        $this->actingAs($this->clientUser, 'client')->get(progressUrl($unpublished))->assertNotFound();

        // Vế dương trong CÙNG ngữ cảnh thủng: trang không từ chối tất cả.
        $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk();
    });
});

it('keeps an unpublished update off the timeline when the stage log scope forgets its rule', function () {
    StageLog::factory()->for($this->matter)->published()->create(['public_content' => 'Toà đã nhận đơn khởi kiện.']);
    StageLog::factory()->for($this->matter)->internalOnly()->create(['internal_note' => 'Nháp '.PROGRESS_MARKER]);
    $foreignLog = StageLog::factory()->published()->create(['public_content' => 'Của khách khác '.PROGRESS_MARKER]);

    $html = withEmptyPortalScope([StageLog::class], function () use ($foreignLog) {
        expect(StageLog::find($foreignLog->getKey()))->not->toBeNull();

        return $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk()->getContent();
    });

    expect($html)->not->toContain(PROGRESS_MARKER)
        ->and($html)->toContain('Toà đã nhận đơn khởi kiện.');
});

it('keeps an unpublished deadline off the page when the deadline scope forgets its rule', function () {
    Deadline::factory()->for($this->matter)->published()->create(['name' => 'Nộp bản tự khai']);
    Deadline::factory()->for($this->matter)->create(['is_published' => false, 'name' => 'Mốc nội bộ '.PROGRESS_MARKER]);

    $html = withEmptyPortalScope([Deadline::class], function () {
        return $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk()->getContent();
    });

    expect($html)->not->toContain(PROGRESS_MARKER)
        ->and($html)->toContain('Nộp bản tự khai');
});

/**
 * **Test này chỉ đỏ khi CẢ HAI lớp cùng mất, và điều đó là cố ý — đã đo.** Quan hệ
 * `$matter->checklistItems()` một mình đã đủ giữ một đầu mục của hồ sơ khác ra ngoài, nên xoá
 * riêng lần hỏi `Gate` trong `MatterProgress::checklistItems()` KHÔNG làm test này đỏ. Nhưng thay
 * quan hệ đó bằng một truy vấn trần rồi giữ `Gate` thì bộ test vẫn xanh, còn bỏ cả hai thì test
 * này đỏ. Hai lớp, mỗi lớp đỡ được lần quên của lớp kia; lý lẽ đầy đủ ở docblock của phương thức.
 */
it('keeps a checklist item of another matter off the page when its scope forgets its rule', function () {
    MatterChecklistItem::factory()->for($this->matter)->create(['name' => 'Sổ hộ khẩu']);
    $foreignItem = MatterChecklistItem::factory()->create(['name' => 'Của hồ sơ khác '.PROGRESS_MARKER]);

    $html = withEmptyPortalScope([MatterChecklistItem::class], function () use ($foreignItem) {
        expect(MatterChecklistItem::find($foreignItem->getKey()))->not->toBeNull();

        return $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk()->getContent();
    });

    expect($html)->not->toContain(PROGRESS_MARKER)
        ->and($html)->toContain('Sổ hộ khẩu');
});

// =========================================================================================
// HAI ĐƯỜNG BIÊN NỮA CỦA KHỐI 2 VÀ KHỐI 6
// =========================================================================================

/**
 * `pending_review` đang chờ ở VĂN PHÒNG, không ở khách. Liệt kê nó vào "việc anh/chị cần làm" là
 * giục khách làm một việc họ đã làm xong — và với một người đang lo vụ việc của mình, đó là một
 * câu nói sai.
 */
it('never puts an item that is waiting on the office into the list of things the client must do', function () {
    MatterChecklistItem::factory()->for($this->matter)->create([
        'name' => 'Sổ đỏ đã gửi chờ kiểm tra',
        'status' => ChecklistItemStatus::PendingReview,
    ]);
    MatterChecklistItem::factory()->for($this->matter)->create([
        'name' => 'Chứng minh nhân dân chưa gửi',
        'status' => ChecklistItemStatus::Missing,
    ]);

    $html = $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk()->getContent();

    $todo = substr($html, (int) strpos($html, 'data-portal-block="2"'), (int) strpos($html, 'data-portal-block="3"') - (int) strpos($html, 'data-portal-block="2"'));

    expect($todo)->toContain('Chứng minh nhân dân chưa gửi')
        ->and($todo)->not->toContain('Sổ đỏ đã gửi chờ kiểm tra')
        // Vế dương: đầu mục đó vẫn có mặt ở khối 4 với trạng thái của nó.
        ->and($html)->toContain('Sổ đỏ đã gửi chờ kiểm tra')
        ->and($html)->toContain(__('portal_progress.checklist.status.pending_review'));
});

/**
 * **Ô nổi bật nhất màn hình và thanh tiến độ ngay dưới nó phải nói về CÙNG MỘT tập dòng.**
 *
 * `outstandingItems()` liệt kê mọi đầu mục `missing`/`rejected` bất kể bắt buộc hay không, và
 * không vẽ dấu hiệu gì; thanh tiến độ thì chỉ nói về tập `Y` của SPEC §4.10 (bắt buộc, hoặc
 * không bắt buộc nhưng đã có tài liệu). Trên hồ sơ mẫu số 6 khách đọc mười một dòng giấy tờ cần
 * nộp bên trên một thanh nói "Đã nộp … / 4", và một trong mười một dòng ấy là giấy chứng tử mà
 * văn phòng đã đánh dấu KHÔNG bắt buộc. Cùng hình dạng "hai nguồn sự thật cho một câu" mà nhánh
 * này đã tìm thấy hai lần trước đó (thanh X/Y ở M4, huy hiệu thẻ hồ sơ ở vòng sửa danh sách).
 *
 * Cách chữa giống hệt vòng sửa danh sách: một tập dòng, hai câu chữ. Đầu mục nào nằm trong `Y`
 * thì ở nhóm "văn phòng còn chờ"; đầu mục nào nằm ngoài `Y` — tức thanh tiến độ KHÔNG đếm nó —
 * thì ở nhóm "không bắt buộc", nói thẳng ra là không bắt buộc.
 */
it('separates the papers the progress bar counts from the ones it does not', function () {
    MatterChecklistItem::factory()->for($this->matter)->create([
        'name' => 'Giấy chứng nhận quyền sử dụng đất',
        'status' => ChecklistItemStatus::Missing,
        'is_required' => true,
    ]);
    MatterChecklistItem::factory()->for($this->matter)->create([
        'name' => 'Giấy chứng tử của người để lại di sản',
        'status' => ChecklistItemStatus::Missing,
        'is_required' => false,
    ]);

    $html = $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk()->getContent();
    $todo = progressTodoBlock($html);

    // Tiền đề: thanh tiến độ nói về ĐÚNG MỘT dòng, không phải hai.
    expect($this->matter->refresh())->not->toBeNull();
    $progress = app(ChecklistProgress::class)->handle($this->matter);
    expect($progress['total'])->toBe(1);

    // Cả hai vẫn hiện ra — khách vẫn cần biết cả hai — nhưng dưới hai câu dẫn khác nhau, và
    // dòng không bắt buộc nằm SAU câu dẫn của nó.
    expect($todo)->toContain(__('portal_progress.blocks.todo.documents_lead'))
        ->toContain(__('portal_progress.blocks.todo.documents_optional_lead'))
        ->toContain('Giấy chứng nhận quyền sử dụng đất')
        ->toContain('Giấy chứng tử của người để lại di sản');

    $requiredLead = strpos($todo, __('portal_progress.blocks.todo.documents_lead'));
    $optionalLead = strpos($todo, __('portal_progress.blocks.todo.documents_optional_lead'));

    expect(strpos($todo, 'Giấy chứng nhận quyền sử dụng đất'))->toBeGreaterThan($requiredLead)
        ->toBeLessThan($optionalLead)
        ->and(strpos($todo, 'Giấy chứng tử của người để lại di sản'))->toBeGreaterThan($optionalLead);
});

/**
 * Vế còn lại của luật `Y`, và nó là vế khiến "không bắt buộc" KHÔNG đồng nghĩa với
 * `is_required = false`: một đầu mục không bắt buộc mà khách ĐÃ gửi một tờ giấy vào thì thanh
 * tiến độ đếm nó, nên nó thuộc nhóm văn phòng còn chờ. Không có test này, một câu
 * `$item['is_required']` trần cũng làm test trên xanh — và nó sẽ đẩy đúng những đầu mục khách đã
 * bắt đầu làm xuống nhóm "không bắt buộc".
 */
it('keeps an optional item the client already sent a paper for in the group the bar counts', function () {
    $item = MatterChecklistItem::factory()->for($this->matter)->create([
        'name' => 'Biên bản họp gia đình',
        'status' => ChecklistItemStatus::Rejected,
        'rejection_reason' => 'Bản chụp bị mờ mất phần chữ ký, anh/chị chụp lại giúp cho rõ nhé.',
        'is_required' => false,
    ]);

    Document::factory()->for($this->matter)->create([
        'matter_checklist_item_id' => $item->getKey(),
        'group' => DocumentGroup::ClientProvided,
        'status' => DocumentStatus::Published,
    ]);

    $progress = app(ChecklistProgress::class)->handle($this->matter);
    expect($progress['total'])->toBe(1);

    $todo = progressTodoBlock(
        $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk()->getContent()
    );

    $optionalLead = strpos($todo, __('portal_progress.blocks.todo.documents_optional_lead'));

    expect($todo)->toContain('Biên bản họp gia đình')
        // Câu dẫn "không bắt buộc" không được vẽ ra chút nào, vì không có dòng nào thuộc nhóm đó.
        ->and($optionalLead)->toBeFalse();
});

/** "Sắp tới" là chữ về việc CÒN PHẢI LÀM: một mốc đã xong thôi là thứ khách cần nhớ. */
it('leaves a completed deadline out of the upcoming list', function () {
    Deadline::factory()->for($this->matter)->published()->create(['name' => 'Nộp bản tự khai']);
    Deadline::factory()->for($this->matter)->published()->create([
        'name' => 'Việc đã xong rồi '.PROGRESS_MARKER,
        'is_completed' => true,
        'completed_at' => now(),
    ]);

    $this->actingAs($this->clientUser, 'client')
        ->get(progressUrl($this->matter))
        ->assertOk()
        ->assertSee('Nộp bản tự khai', escape: false)
        ->assertDontSee(PROGRESS_MARKER, escape: false);
});

// =========================================================================================
// REQUEST CẬP NHẬT LIVEWIRE — nơi middleware 404 KHÔNG với tới
// =========================================================================================

/**
 * **Trang hỏi lại `Gate` ở MỌI request, không chỉ ở lần tải đầu — và thứ bắt nó phải làm vậy là:
 * không một thuộc tính CÔNG KHAI nào mang theo bản ghi đã được gác.**
 *
 * Đây là chỗ phát hiện của Task 2 cắn thật. `AnswerDeniedPanelRequestsWithNotFound` là middleware
 * bền, nhưng trên một request cập nhật Livewire nó chạy với một response stub 200 TRƯỚC khi
 * component hydrate, nên nó không đổi được hình dạng của một lời từ chối sinh ra sau đó — và
 * toàn bộ cổng khách hàng là Livewire. Livewire chỉ mang theo các thuộc tính công khai; mọi thứ
 * khác được dựng lại từ đầu ở request kế tiếp. Nên nếu `MatterProgress` giữ `Matter` đã gác trong
 * một thuộc tính công khai, bản ghi ấy sẽ đi theo component qua từng lần cập nhật **mà không ai
 * hỏi lại quyền** — kể cả sau khi văn phòng vừa rút hồ sơ khỏi cổng.
 *
 * Test này CỐ Ý không dùng `$this->livewire(...)->call('$refresh')`. Helper đó giữ NGUYÊN một đối
 * tượng PHP giữa hai lần gọi, nên bộ nhớ đệm riêng của trang sống sót và phép đo mất hết ý nghĩa:
 * đã thử, và nó báo "không có 404" trong khi một request thật thì có. Một test xanh vì sai lý do
 * còn tệ hơn không có test, nên nó được thay bằng hai mệnh đề đo được thật.
 */
it('carries no authorised record on a public property, so every request must resolve again', function () {
    Filament::setCurrentPanel('portal');
    $this->actingAs($this->clientUser, 'client');

    $page = new MatterProgress;
    $page->mount($this->matter->getKey());

    expect($page->getHeading())->toBe($this->matter->title);

    foreach ((new ReflectionClass($page))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        if ($property->isStatic()) {
            continue;
        }

        expect($property->getValue($page))->not->toBeInstanceOf(Matter::class);
    }
});

/** Vế thứ hai: dựng lại chỉ từ cái id mà Livewire mang theo, cổng quyền vẫn đóng lại. */
it('refuses again when a fresh component is rebuilt from the id alone', function () {
    Filament::setCurrentPanel('portal');
    $this->actingAs($this->clientUser, 'client');

    $id = $this->matter->getKey();

    $rebuild = function () use ($id): string {
        $page = new MatterProgress;
        $page->record = $id;

        return (string) $page->getHeading();
    };

    expect($rebuild())->toBe($this->matter->title);

    // Văn phòng rút hồ sơ khỏi cổng giữa hai request.
    $this->matter->update(['is_published_to_portal' => false]);

    expect($rebuild(...))->toThrow(NotFoundHttpException::class);
});

// =========================================================================================
// C1 — BIÊN BẢN CHỈ RA ĐỜI TỪ MỘT RESPONSE ĐÃ HOÀN TẤT VÀ CÓ THỂ MANG THÂN
// =========================================================================================

/**
 * **Một request `HEAD` không ghi biên bản nào.** Filament đăng ký route cho cả `GET` lẫn `HEAD`,
 * trang vẫn dựng đủ, rồi Symfony cắt sạch thân trước khi trả lời — khách nhận về KHÔNG BYTE NÀO.
 * Một dòng `stage_log_views` sinh ra từ đó nói "chúng tôi đã cho anh/chị xem cập nhật này" về một
 * trang chưa từng có nội dung, và nó không phân biệt được với một dòng thật.
 *
 * Phép đo gồm cả tiền đề: nếu `HEAD` một ngày nào đó trả về thân thật thì khẳng định "không byte
 * nào" đỏ trước, và test này không còn xanh vì một lý do sai.
 */
it('writes no view receipt for a HEAD request, which carries no body at all', function () {
    StageLog::factory()->for($this->matter)->published()->create(['public_content' => 'Toà đã nhận đơn khởi kiện.']);

    $response = $this->actingAs($this->clientUser, 'client')->call('HEAD', progressUrl($this->matter));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('')
        ->and(StageLogView::withoutGlobalScope(ClientPortalScope::class)->count())->toBe(0);

    // Vế dương, cùng tài khoản và cùng hồ sơ: một `GET` thì biên bản được ghi. Không có vế này
    // thì test trên xanh y hệt khi việc ghi biên bản hỏng hoàn toàn.
    $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk();

    expect(StageLogView::withoutGlobalScope(ClientPortalScope::class)->count())->toBe(1);
});

/**
 * **Một lỗi xảy ra SAU vòng lặp biên bản không được để lại dòng nào.** Trước vòng sửa này việc
 * ghi nằm giữa lúc render: `firstOrCreate` commit ngay, ngoài mọi transaction, trước khi có một
 * response nào tồn tại — nên một exception ở khối 4 hay khối 7 trả về trang lỗi 500 cho khách mà
 * vẫn để lại đủ biên bản "đã xem".
 *
 * Chỗ nổ được chọn là `ChecklistProgress` (khối 4) vì nó nằm SAU khối 3 trong chính view: nếu nó
 * nổ trước khi dòng thời gian được vẽ thì test này đo một thứ khác, dễ hơn.
 */
it('writes no view receipt when the page explodes after the timeline has been drawn', function () {
    StageLog::factory()->for($this->matter)->published()->create(['public_content' => 'Toà đã nhận đơn khởi kiện.']);
    MatterChecklistItem::factory()->for($this->matter)->create([
        'name' => 'Giấy chứng nhận quyền sử dụng đất',
        'status' => ChecklistItemStatus::Missing,
    ]);

    $this->app->bind(ChecklistProgress::class, fn () => throw new RuntimeException('Khối 4 vỡ giữa lúc render'));

    $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertStatus(500);

    expect(StageLogView::withoutGlobalScope(ClientPortalScope::class)->count())->toBe(0);

    // Vế dương: cùng hồ sơ, cùng fixture, khối 4 lành lại thì trang 200 và biên bản được ghi.
    $this->app->bind(ChecklistProgress::class, fn () => new ChecklistProgress);

    $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk();

    expect(StageLogView::withoutGlobalScope(ClientPortalScope::class)->count())->toBe(1);
});

/**
 * **Một lời từ chối ở listener biên bản KHÔNG được thoát ra ngoài kernel.**
 *
 * `Illuminate\Foundation\Http\Kernel::handle()` bắt exception của request bên trong khối `try`
 * của nó, nhưng nó dispatch `RequestHandled` NGOÀI khối ấy. Nên một exception ném ra từ listener
 * biên bản không được kernel bắt, không middleware nào nhìn thấy — kể cả
 * `AnswerDeniedPanelRequestsWithNotFound`, thứ đổi 403 thành 404 cho cổng — và nó nổ tới trình
 * xử lý lỗi toàn cục. Khách nhận một trang 403 chữ tiếng Anh, còn trang đã dựng xong của họ bị
 * vứt đi.
 *
 * Cảnh này là một cuộc đua có thật, không phải một tình huống bịa: hồ sơ bị rút khỏi cổng, hoặc
 * tài khoản bị vô hiệu hoá, TRONG khoảng giữa lúc dòng thời gian được vẽ và lúc request kết
 * thúc — vài giây trên một điện thoại chậm.
 *
 * Listener của test này đăng ký TRƯỚC request nên nó chạy TRƯỚC listener của trang (sự kiện chạy
 * theo thứ tự đăng ký), tức nó dựng đúng cửa sổ ấy.
 */
it('still serves the built page when the matter is retracted between the render and the end of the request', function () {
    StageLog::factory()->for($this->matter)->published()->create(['public_content' => 'Toà đã nhận đơn khởi kiện.']);

    $matterId = $this->matter->getKey();

    Event::listen(function (RequestHandled $event) use ($matterId): void {
        Matter::withoutGlobalScope(ClientPortalScope::class)
            ->whereKey($matterId)
            ->update(['is_published_to_portal' => false]);
    });

    $this->actingAs($this->clientUser, 'client')
        ->get(progressUrl($this->matter))
        ->assertOk()
        ->assertSee('Toà đã nhận đơn khởi kiện.');

    // Không có biên bản nào: `RecordStageLogView` đã từ chối, đúng như nó phải làm. Cái được sửa
    // là chỗ lời từ chối ấy hạ cánh, không phải lời từ chối.
    expect(StageLogView::withoutGlobalScope(ClientPortalScope::class)->count())->toBe(0);
});

/**
 * Vế dương của test trên, trong cùng một fixture: không ai rút hồ sơ đi thì biên bản vẫn được
 * ghi. Không có nó, một `return` đặt ở đầu listener cũng làm test kia xanh.
 */
it('still writes the receipt when nothing is retracted mid request', function () {
    StageLog::factory()->for($this->matter)->published()->create(['public_content' => 'Toà đã nhận đơn khởi kiện.']);

    $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk();

    expect(StageLogView::withoutGlobalScope(ClientPortalScope::class)->count())->toBe(1);
});

// =========================================================================================
// I1 — `$record` KHÔNG PHẢI THỨ TRÌNH DUYỆT ĐẶT ĐƯỢC
// =========================================================================================

/**
 * **Một `record` giả mạo trên request cập nhật Livewire phải dừng ở framework.** Rà soát đo được
 * rằng một `updates:{"record": …}` trỏ sang một hồ sơ KHÁC CỦA CÙNG KHÁCH trả về 200 kèm mảnh
 * HTML của hồ sơ kia trong khi thanh địa chỉ vẫn là hồ sơ đầu — và ghi luôn biên bản của hồ sơ
 * kia. Cách ly giữa hai khách hàng không hề thủng; khuyết tật là thuộc tính này trình duyệt ghi
 * được.
 *
 * Khoá KHÔNG thay cho việc gác: {@see MatterProgress::resolveMatter()} vẫn hỏi `Gate` ở mọi
 * request, và các test 404 ở trên vẫn là tầng thật.
 *
 * Khẳng định theo CÂU CHỮ chứ không theo tên lớp: Livewire bọc lại exception của mình trước khi
 * nó ra tới đây (cùng phép đo đã ghi ở `MyRequestsTest`).
 */
it('refuses a forged record that names another matter of the same client', function () {
    $sibling = Matter::factory()->for($this->client)->create([
        'is_published_to_portal' => true,
        'title' => 'Hồ sơ thứ hai của cùng khách hàng',
    ]);
    $siblingLog = StageLog::factory()->for($sibling)->published()->create(['public_content' => 'Cập nhật của hồ sơ thứ hai.']);

    Filament::setCurrentPanel('portal');

    try {
        $this->actingAs($this->clientUser, 'client')
            ->livewire(MatterProgress::class, ['record' => $this->matter->getKey()])
            ->set('record', $sibling->getKey());

        $this->fail('Livewire đáng lẽ phải từ chối một thuộc tính đã khoá');
    } catch (Throwable $exception) {
        expect($exception->getMessage())->toContain('Cannot update locked property')
            ->and($exception->getMessage())->toContain('record');
    }

    // Và không một biên bản nào của hồ sơ kia được ghi bằng đường đó.
    expect(StageLogView::withoutGlobalScope(ClientPortalScope::class)->where('stage_log_id', $siblingLog->getKey())->count())->toBe(0);

    // Vế dương: hồ sơ thứ hai vẫn mở bình thường bằng ĐƯỜNG DẪN của chính nó.
    $this->actingAs($this->clientUser, 'client')
        ->get(progressUrl($sibling))
        ->assertOk()
        ->assertSee('Cập nhật của hồ sơ thứ hai.', escape: false);
});

// =========================================================================================
// I2 — "MỚI NHẤT" PHẢI XÁC ĐỊNH ĐƯỢC KHI HAI CẬP NHẬT CÙNG MỘT NGÀY
// =========================================================================================

/**
 * **`occurred_at` đến từ một ô chọn NGÀY, nên nó luôn là nửa đêm.** Hai cập nhật trong cùng một
 * ngày bằng nhau tuyệt đối ở cột sắp xếp — đó là trường hợp thường ngày, không phải một ca biên.
 * Không có tiêu chí phụ thì thứ tự do bộ tối ưu truy vấn quyết định, và khi nó đổi thì khối 2 sẽ
 * đưa một chỉ dẫn ĐÃ BỊ THAY THẾ ra làm việc khách đang phải làm.
 *
 * **Test này một mình KHÔNG ghim được tiêu chí phụ** và câu đó phải được nói ra: SQLite lẫn
 * MariaDB hôm nay đều tình cờ trả về `id` giảm dần, nên xoá tiêu chí phụ vẫn xanh. Thứ ghim là
 * test cấu trúc ngay dưới; test này là lưới đo HÀNH VI cho ngày thứ tự ấy đổi.
 */
it('puts the newer of two updates from the same day first, and reads the latest client_action from it', function () {
    $sameDay = today();

    $older = StageLog::factory()->for($this->matter)->published()->create([
        'occurred_at' => $sameDay,
        'public_content' => 'Buổi sáng: chúng tôi đã nộp đơn.',
        'client_action' => 'Chỉ dẫn cũ, đã bị thay thế.',
    ]);
    $newer = StageLog::factory()->for($this->matter)->published()->create([
        'occurred_at' => $sameDay,
        'public_content' => 'Buổi chiều: toà đã nhận đơn.',
        'client_action' => 'Chỉ dẫn đang có hiệu lực.',
    ]);

    // Tiền đề của phép đo: hai dòng thật sự bằng nhau ở cột sắp xếp, và dòng mới có id lớn hơn.
    expect($older->occurred_at->equalTo($newer->occurred_at))->toBeTrue()
        ->and($newer->getKey())->toBeGreaterThan($older->getKey());

    $html = $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk()->getContent();

    expect(strpos($html, 'Buổi chiều: toà đã nhận đơn.'))
        ->toBeLessThan(strpos($html, 'Buổi sáng: chúng tôi đã nộp đơn.'));

    $todo = substr($html, (int) strpos($html, 'data-portal-block="2"'), (int) strpos($html, 'data-portal-block="3"') - (int) strpos($html, 'data-portal-block="2"'));

    expect($todo)->toContain('Chỉ dẫn đang có hiệu lực.')
        ->and($todo)->not->toContain('Chỉ dẫn cũ, đã bị thay thế.');
});

/**
 * Thứ THẬT SỰ ghim tiêu chí phụ, vì nó hỏi chính câu lệnh chứ không hỏi thứ tự mà một cơ sở dữ
 * liệu tình cờ trả về hôm nay. `stage_logs` là bảng chỉ ghi thêm (`StageLogImmutable`), nên `id`
 * tăng dần đúng theo thứ tự sự việc được ghi vào — nó là tiêu chí phụ đúng nghĩa, không phải một
 * cột được mượn tạm.
 */
it('breaks the tie on the stage log relation with a descending id', function () {
    $orders = $this->matter->stageLogs()->getQuery()->toBase()->orders;

    expect($orders)->toBe([
        ['column' => 'occurred_at', 'direction' => 'desc'],
        ['column' => 'id', 'direction' => 'desc'],
    ]);
});

// =========================================================================================
// I3 — MỘT LOẠI VỤ VIỆC BỊ XOÁ MỀM KHÔNG ĐƯỢC LÀM VỠ TRANG CỦA KHÁCH
// =========================================================================================

/**
 * Một lần bấm xoá ở màn hình thiết lập không được biến trang của mọi khách hàng đang dùng loại vụ
 * việc đó thành một trang lỗi. Trường hợp "xoá mềm một GIAI ĐOẠN" đã được xử lý từ Task 4; đây là
 * trường hợp rộng hơn — xoá mềm cả LOẠI vụ việc — và nó chạm vào hai chỗ khác nhau:
 * `Matter::currentStage()` (khối 1) và `MatterProgress::stageLabel()` (khối 3).
 */
it('still serves the page when the matter type behind it has been soft deleted', function () {
    StageLog::factory()->for($this->matter)->published()->transition('collecting_documents', 'filed')->create([
        'public_content' => 'Toà đã nhận đơn khởi kiện.',
    ]);

    $this->matter->matterType->delete();

    $this->actingAs($this->clientUser, 'client')
        ->get(progressUrl($this->matter))
        ->assertOk()
        // Khối 1 nói một câu tiếng Việt thay vì để trống, và KHÔNG vỡ.
        ->assertSee(__('portal_progress.blocks.status.unknown'), escape: false)
        // Khối 3 vẫn kể được diễn biến, chỉ thiếu cái nhãn giai đoạn không còn khai báo ở đâu.
        ->assertSee('Toà đã nhận đơn khởi kiện.', escape: false);
});

// =========================================================================================
// I4 — TRANG LÀ MỘT BỀ MẶT RPC: GIÁ TRỊ TRẢ VỀ CỦA MỌI PHƯƠNG THỨC CÔNG KHAI ĐI VÀO RESPONSE
// =========================================================================================

/**
 * **Mọi phương thức `public` của một component Livewire đều gọi được từ trình duyệt, và giá trị
 * trả về của nó được serialize thẳng vào response.** Rà soát đo được: `currentStage()` trả về cả
 * dòng `matter_type_stages` — nhãn NỘI BỘ, danh sách giai đoạn kế tiếp được phép, cờ kết thúc,
 * số ngày cập nhật mặc định — còn `matter()` và `timeline()` trả về nguyên bản ghi kèm id nhân sự
 * và id người công bố. Ranh giới tuyệt đối (`internal_note`, `description_internal`) vẫn đứng;
 * cái thủng là SPEC §8, "không thuật ngữ nội bộ trước mặt khách".
 *
 * Test phát biểu LUẬT chứ không liệt kê tên hàm: mọi phương thức công khai KHAI BÁO TRÊN CHÍNH
 * LỚP NÀY mà gọi được không tham số đều bị gọi, kết quả đem `json_encode` — đúng hình dạng đường
 * đi vào response — và không một mảnh ngôn ngữ nội bộ nào được có mặt. Một hàm mới trả về model
 * sẽ làm test này đỏ mà không ai phải nhớ ra là phải thêm nó vào một danh sách.
 */
it('serialises no internal terminology from any public method the browser can call', function () {
    $stage = $this->matter->matterType->stage('collecting_documents');

    StageLog::factory()->for($this->matter)->published()->create([
        'public_content' => 'Toà đã nhận đơn khởi kiện.',
        'internal_note' => PROGRESS_MARKER,
    ]);
    MatterChecklistItem::factory()->for($this->matter)->create(['status' => ChecklistItemStatus::Missing]);
    Document::factory()->for($this->matter)->group(DocumentGroup::Issued)->create([
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
    ]);
    Deadline::factory()->for($this->matter)->published()->create();

    Filament::setCurrentPanel('portal');
    $this->actingAs($this->clientUser, 'client');

    $page = new MatterProgress;
    $page->mount($this->matter->getKey());

    $called = [];

    foreach ((new ReflectionClass($page))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->isStatic() || $method->getDeclaringClass()->getName() !== MatterProgress::class) {
            continue;
        }

        if ($method->getNumberOfRequiredParameters() > 0) {
            continue;
        }

        $called[$method->getName()] = json_encode($method->invoke($page), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // Tiền đề: thật sự có bề mặt để đo, và nó gồm đúng những hàm khối 1–7 đọc dữ liệu.
    expect($called)->toHaveKeys(['currentStage', 'timeline', 'documents', 'deadlines', 'checklistItems']);

    $json = implode("\n", $called);

    expect($json)
        // Vế dương: những gì khách ĐƯỢC đọc vẫn đi ra, nếu không thì mọi khẳng định âm vô nghĩa.
        ->toContain('Toà đã nhận đơn khởi kiện.')
        ->toContain($stage->client_label)
        // Ngôn ngữ nội bộ của bảng giai đoạn.
        ->not->toContain($stage->label)
        ->not->toContain('allowed_next_stages')
        ->not->toContain('is_terminal')
        ->not->toContain('default_update_interval_days')
        // Nhân sự của văn phòng.
        ->not->toContain('lead_lawyer_id')
        ->not->toContain('published_by')
        ->not->toContain('created_by')
        // Và ranh giới tuyệt đối, ghim lại ở đúng đường đi này.
        ->not->toContain('internal_note')
        ->not->toContain(PROGRESS_MARKER);
});

/**
 * Cùng một luật, đo trên **response cập nhật Livewire thật** — "JSON của cổng" mà SPEC §11 nói
 * tới. `assertReturned()` đọc đúng `effects.returns.0`, tức giá trị mà Livewire đặt vào response
 * gửi về trình duyệt.
 */
it('returns no internal stage row over a real livewire call', function () {
    $stage = $this->matter->matterType->stage('collecting_documents');

    Filament::setCurrentPanel('portal');

    $returned = null;

    $this->actingAs($this->clientUser, 'client')
        ->livewire(MatterProgress::class, ['record' => $this->matter->getKey()])
        ->call('currentStage')
        ->assertReturned(function ($value) use (&$returned): bool {
            $returned = $value;

            return true;
        });

    $json = json_encode($returned, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    expect($json)->toContain($stage->client_label)
        ->and($json)->not->toContain($stage->label)
        ->and($json)->not->toContain('allowed_next_stages')
        ->and($json)->not->toContain('default_update_interval_days');
});

// =========================================================================================
// MỘT LỜI TỪ CHỐI CŨNG PHẢI BẰNG TIẾNG VIỆT, VÀ PHẢI CHỈ RA MỘT ĐƯỜNG ĐI TIẾP
// =========================================================================================

/**
 * SPEC §10.10 đòi "không có quyền" và "không tồn tại" cùng một câu trả lời; SPEC §8 đòi câu trả
 * lời ấy bằng tiếng Việt và không dùng thuật ngữ. Trước vòng này không có
 * `resources/views/errors/404.blade.php`, nên một lời từ chối trên request cập nhật Livewire hiện
 * ra trang 404 mặc định của Laravel — tiếng Anh — bên trong hộp lỗi của Livewire.
 *
 * Hai khẳng định, và cái thứ hai là cái khó: hai tình huống khác nhau phải trả về **đúng từng
 * byte** cùng một trang.
 */
it('answers a denial with a vietnamese 404 that carries the office phone number', function () {
    $otherClient = Client::factory()->create();
    $otherMatter = Matter::factory()->for($otherClient)->create([
        'is_published_to_portal' => true,
        'title' => 'Hồ sơ của khách khác '.PROGRESS_MARKER,
    ]);

    $denied = $this->actingAs($this->clientUser, 'client')->get(progressUrl($otherMatter))->assertNotFound()->getContent();

    expect($denied)->toContain(__('portal_progress.not_found.heading'))
        ->and($denied)->toContain(__('portal_progress.not_found.body'))
        // Đường đi tiếp KHÔNG qua một trang: người không mở được trang cần một số điện thoại.
        ->and($denied)->toContain(config('vkcrm.brand.hotline'))
        ->and($denied)->toContain('tel:')
        // Không một chữ tiếng Anh nào của trang mặc định Laravel.
        ->and($denied)->not->toContain('Not Found')
        // Và không một chữ nào về hồ sơ vừa bị từ chối.
        ->and($denied)->not->toContain(PROGRESS_MARKER)
        ->and($denied)->not->toContain($otherMatter->code);

    // §10.10: một id không tồn tại trả về ĐÚNG TỪNG BYTE cùng một trang. Một khác biệt dù nhỏ là
    // một máy dò sự tồn tại.
    $missing = $this->actingAs($this->clientUser, 'client')->get(progressUrl(999999))->assertNotFound()->getContent();

    expect($missing)->toBe($denied);
});

// =========================================================================================
// KHỐI 7 VÀ LỐI QUAY LẠI — hai thứ khách chạm vào được
// =========================================================================================

/**
 * `requestEntryPoint()` nay luôn trả về một URL, nên nhánh `@else` cũ — câu "gọi điện" — không
 * còn được vẽ ra nữa, và số điện thoại văn phòng lặng lẽ biến mất khỏi trang. Khối 7 giờ nói cả
 * hai: một cái nút gửi yêu cầu, VÀ một số gọi được. Chúng không thay thế nhau — một người đang
 * lo lắng vào lúc chín giờ tối muốn gọi, không muốn điền biểu mẫu.
 */
it('keeps the office phone number on the page beside the button to send a request', function () {
    $region = progressRegion(
        $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk()->getContent()
    );

    expect($region)->toContain(__('portal_progress.blocks.requests.open'))
        ->and($region)->toContain(config('vkcrm.brand.hotline'))
        ->and($region)->toContain('tel:'.config('vkcrm.brand.hotline'));
});

/** @return list<string> `href` của mọi thẻ `<a>` mang đúng nhãn `$label`, đọc từ HTML thật. */
function progressHrefsFor(string $html, string $label): array
{
    preg_match_all(
        '/<a\b[^>]*\bhref="([^"]*)"[^>]*>(?:(?!<\/a>).)*?'.preg_quote($label, '/').'/su',
        $html,
        $matches,
    );

    return array_map(
        fn (string $href): string => html_entity_decode($href, ENT_QUOTES | ENT_HTML5),
        $matches[1],
    );
}

/**
 * **Phép đo ĐI THEO ĐƯỜNG LINK, không hỏi mã nguồn** — cùng thiết bị mà màn hình danh sách vừa
 * phải dựng cho mục điều hướng của nó. Khách có ĐÚNG MỘT hồ sơ là ca khó: `/portal` trần sẽ đá
 * họ ngược về đúng trang này, nên một lối quay lại chỉ có thật khi nó mang cờ `tat-ca`.
 *
 * `200` chứ không `302` là toàn bộ nội dung của khẳng định cuối.
 */
it('puts a back link on the detail page that really opens the matter list', function () {
    $this->actingAs($this->clientUser, 'client');

    // Tiền đề: khách này có đúng một hồ sơ, nên gốc cổng CHUYỂN HƯỚNG. Nếu dòng này đỏ thì khẳng
    // định cuối xanh với một `href` bất kỳ trỏ vào `/portal`.
    $this->get(url('/portal'))->assertRedirect(progressUrl($this->matter));

    $region = progressRegion($this->get(progressUrl($this->matter))->assertOk()->getContent());

    $hrefs = progressHrefsFor($region, __('portal_progress.back_to_list'));

    expect($hrefs)->not->toBeEmpty()
        ->and(array_values(array_unique($hrefs)))->toHaveCount(1);

    $this->get($hrefs[0])
        ->assertOk()
        ->assertSee(__('portal_matters.heading'), escape: false);
});

// =========================================================================================
// KHỐI 5 — CHUỖI NỘP LẠI BỊ ĐỨT VẪN CHỈ ĐƯỢC VẼ MỘT BẢN
// =========================================================================================

/**
 * **Một chuỗi nộp lại bị đứt giữa chừng không được làm bản ĐÃ BỊ TỪ CHỐI hiện lại cạnh bản đang
 * có hiệu lực.**
 *
 * Fixture là đúng trạng thái cơ sở dữ liệu mà `SubmitClientDocument` sinh ra khi một bản giữa
 * chuỗi biến mất khỏi tầm nhìn của khách: bản 1 bị từ chối, bản 2 thay nó, bản 2 bị xoá mềm, bản
 * 3 nộp tiếp — và `parent_document_id` của bản 3 trỏ vào bản 2 chứ không vào bản 1, vì
 * `latestInSubmissionChain()` tra bằng `withTrashed()` (một bản đã xoá mềm vẫn chiếm số version
 * của nó).
 *
 * Luật cũ — "bản cũ là bản nào được một bản ĐANG HIỂN THỊ trỏ vào" — không bắt được ca này: bản
 * 2 không hiển thị, nên không ai trỏ vào bản 1, và trang vẽ ra bản 3 CÙNG bản 1. Hai dòng cùng
 * tên đầu mục, cùng ngày, và cái cũ chính là cái vừa bị văn phòng từ chối.
 *
 * Hôm nay chưa có nút xoá tài liệu ở panel nội bộ, nhưng chuyển nhóm thì có — và chuyển một bản
 * giữa chuỗi sang một nhóm khách không xem được làm đứt chuỗi y hệt.
 */
it('shows only the newest visible version when a middle version of the chain is gone', function () {
    $item = MatterChecklistItem::factory()->for($this->matter)->create([
        'name' => 'Sổ đỏ',
        'status' => ChecklistItemStatus::PendingReview,
    ]);

    $chain = function (int $version, ?Document $parent) use ($item): Document {
        return Document::factory()->for($this->matter)->pendingReview()->create([
            'matter_checklist_item_id' => $item->getKey(),
            'title' => 'Sổ đỏ',
            'version' => $version,
            'parent_document_id' => $parent?->getKey(),
        ]);
    };

    $first = $chain(1, null);
    $second = $chain(2, $first);
    $second->delete();
    $third = $chain(3, $second);

    // Một chuỗi KHÁC trên cùng hồ sơ: nó phải vẫn hiện ra, nếu không thì luật mới đang gom nhầm.
    $other = Document::factory()->for($this->matter)->pendingReview()->create([
        'title' => 'Chứng minh nhân dân',
        'version' => 1,
    ]);

    // Tiền đề: bản giữa thật sự biến mất khỏi tầm nhìn, và bản mới trỏ vào chính nó.
    expect($second->fresh()?->trashed())->toBeTrue()
        ->and($third->parent_document_id)->toBe($second->getKey());

    $html = $this->actingAs($this->clientUser, 'client')->get(progressUrl($this->matter))->assertOk()->getContent();

    $start = (int) strpos($html, 'data-portal-block="5"');
    $documents = substr($html, $start, (int) strpos($html, 'data-portal-block="6"') - $start);

    expect($documents)->toContain('documents/'.$third->getKey().'/download')
        ->and($documents)->toContain('documents/'.$other->getKey().'/download')
        // Bản vừa bị từ chối KHÔNG đứng cạnh bản đang có hiệu lực.
        ->and($documents)->not->toContain('documents/'.$first->getKey().'/download')
        ->and($documents)->not->toContain('documents/'.$second->getKey().'/download')
        // Đúng hai dòng: bản mới nhất của chuỗi này, và chuỗi kia.
        ->and(substr_count($documents, '/download'))->toBe(2);
});
