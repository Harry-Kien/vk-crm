<?php

use App\Enums\ClientRequestStatus;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\RelationManagers\ClientRequestsRelationManager;
use App\Filament\Portal\Pages\MatterProgress;
use App\Filament\Portal\Pages\MyRequests;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Gate;

/**
 * Đầu KHÁCH của cuộc trao đổi — SPEC §8.3 mục 7.
 *
 * Mọi khẳng định âm ở đây đi kèm vế dương của nó **trong cùng một test**: một test nói "thứ X
 * không xuất hiện" xanh y hệt khi trang trắng, và khi đó nó không còn đo gì nữa.
 */
const REQUESTS_MARKER = 'GHI-CHU-NOI-BO-R7Q9';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Lái thẳng component Livewire thì không có middleware nào dựng panel hiện hành, nên mọi
    // lời gọi `Filament::auth()` và `Page::getUrl()` bên trong trang sẽ hỏi một registry trống.
    // Cùng thành ngữ `ChecklistRelationManagerTest` dùng cho panel `admin`.
    Filament::setCurrentPanel('portal');

    $this->client = Client::factory()->create(['name' => 'Khách hàng A']);
    $this->clientUser = ClientUser::factory()->activated()->create([
        'client_id' => $this->client->id,
        'name' => 'Nguyễn Văn An',
    ]);
    $this->sibling = ClientUser::factory()->activated()->create([
        'client_id' => $this->client->id,
        'name' => 'Trần Thị Bình',
    ]);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create([
        'name' => 'Luật sư Vũ Khang',
        'email' => 'luatsu-rieng-tu@example.test',
        'phone' => '0900111222',
    ]);

    $this->matter = Matter::factory()->for($this->client)->create([
        'is_published_to_portal' => true,
        'lead_lawyer_id' => $this->lawyer->id,
        'title' => 'Tranh chấp quyền sử dụng đất',
    ]);
});

function requestsUrl(Matter|int $matter): string
{
    return MyRequests::getUrl(
        ['record' => $matter instanceof Matter ? $matter->getKey() : $matter],
        panel: 'portal',
    );
}

/** Chỉ phần trang do Task 6 vẽ ra, cắt bằng hai mốc trong chính view. */
function requestsRegion(string $html): string
{
    $start = strpos($html, 'data-portal-page="my-requests"');
    $end = strpos($html, 'data-portal-end="my-requests"');

    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();

    return substr($html, (int) $start, (int) $end - (int) $start);
}

/** Component Livewire của trang, lái thẳng — đường mà một request cập nhật đi. */
function requestsPage(ClientUser $viewer, Matter|int $matter)
{
    return test()->actingAs($viewer, 'client')->livewire(MyRequests::class, [
        'record' => $matter instanceof Matter ? $matter->getKey() : $matter,
    ]);
}

// =========================================================================================
// KHÁCH GỬI ĐƯỢC, VÀ THẤY LẠI ĐƯỢC
// =========================================================================================

it('sends a request and shows it in the history right away', function () {
    requestsPage($this->clientUser, $this->matter)
        ->set('subject', 'Xin hỏi về ngày hoà giải')
        ->set('content', 'Văn phòng cho tôi hỏi ngày hoà giải đã có chưa ạ.')
        ->call('submitRequest')
        ->assertHasNoErrors()
        ->assertSee('Xin hỏi về ngày hoà giải')
        ->assertSee('Văn phòng cho tôi hỏi ngày hoà giải đã có chưa ạ.');

    $stored = ClientRequest::query()->withoutGlobalScope(ClientPortalScope::class)->firstOrFail();

    expect($stored->matter_id)->toBe($this->matter->id)
        ->and($stored->client_user_id)->toBe($this->clientUser->id)
        ->and($stored->status)->toBe(ClientRequestStatus::New);
});

it('empties the form after sending so the next question starts clean', function () {
    requestsPage($this->clientUser, $this->matter)
        ->set('subject', 'Câu hỏi thứ nhất')
        ->set('content', 'Nội dung thứ nhất')
        ->call('submitRequest')
        ->assertSet('subject', '')
        ->assertSet('content', '');
});

it('shows the offices answer to the client', function () {
    $request = ClientRequest::factory()->for($this->matter)->create([
        'client_user_id' => $this->clientUser->id,
        'subject' => 'Xin hỏi về án phí',
        'content' => 'Án phí bao nhiêu ạ?',
        'status' => ClientRequestStatus::Answered,
    ]);

    ClientRequestReply::factory()->for($request, 'request')->create([
        'author_type' => $this->lawyer->getMorphClass(),
        'author_id' => $this->lawyer->id,
        'content' => 'Án phí sơ thẩm là 300.000 đồng, văn phòng đã nộp thay anh/chị.',
    ]);

    $html = $this->actingAs($this->clientUser, 'client')->get(requestsUrl($this->matter))->assertOk()->getContent();
    $page = requestsRegion($html);

    expect($page)->toContain('Án phí sơ thẩm là 300.000 đồng')
        ->and($page)->toContain(__('requests.portal.history.from_office'));
});

/**
 * Bề mặt không scope được nêu đích danh ở `PortalIsolationSweepTest`: `$reply->author` là một
 * `MorphTo` trả về NGUYÊN hàng `users`. Trang chiếu thành tên bằng `pluck('name', 'id')`, nên ba
 * cột riêng tư không rời khỏi cơ sở dữ liệu.
 *
 * Vế dương nằm trong cùng test: tên luật sư PHẢI hiện ra. Không có nó, một trang không vẽ gì về
 * người trả lời cũng xanh.
 */
it('prints the staff name and never their email, phone or bar number', function () {
    $this->lawyer->update(['bar_number' => 'BAR-RIENG-TU-001']);

    $request = ClientRequest::factory()->for($this->matter)->create(['client_user_id' => $this->clientUser->id]);
    ClientRequestReply::factory()->for($request, 'request')->create([
        'author_type' => $this->lawyer->getMorphClass(),
        'author_id' => $this->lawyer->id,
        'content' => 'Văn phòng đã trả lời.',
    ]);

    $html = $this->actingAs($this->clientUser, 'client')->get(requestsUrl($this->matter))->assertOk()->getContent();

    expect($html)->toContain('Luật sư Vũ Khang')
        ->and($html)->not->toContain('luatsu-rieng-tu@example.test')
        ->and($html)->not->toContain('0900111222')
        ->and($html)->not->toContain('BAR-RIENG-TU-001');
});

/**
 * SPEC §8: không thuật ngữ. Bốn trạng thái hiện ra bằng CÂU, không bằng tên enum — và không bằng
 * nhãn ngắn của panel nội bộ, thứ nói một điều khác cho một người đọc khác.
 *
 * `answered` ở đây đi KÈM một câu trả lời viết ra (REQ-5): không có nó, câu là câu "trả lời qua
 * điện thoại" ({@see self::statusLineFor()} — test riêng ngay ở section REQ-5) chứ không phải
 * câu chung `requests.portal.status.answered` mà vòng lặp này đang đo.
 */
it('says the status in whole sentences and never in the enum name', function () {
    foreach (ClientRequestStatus::cases() as $status) {
        $matter = Matter::factory()->for($this->client)->create(['is_published_to_portal' => true]);
        $request = ClientRequest::factory()->for($matter)->create([
            'client_user_id' => $this->clientUser->id,
            'status' => $status,
        ]);

        if ($status === ClientRequestStatus::Answered) {
            ClientRequestReply::factory()->for($request, 'request')->create([
                'author_type' => $this->lawyer->getMorphClass(),
                'author_id' => $this->lawyer->id,
            ]);
        }

        $page = requestsRegion(
            $this->actingAs($this->clientUser, 'client')->get(requestsUrl($matter))->assertOk()->getContent()
        );

        expect($page)->toContain(__('requests.portal.status.'.$status->value))
            ->and($page)->not->toContain('>'.$status->value.'<')
            ->and($page)->not->toContain($status->name);
    }
});

it('guides the client instead of leaving a blank space when nothing has been sent', function () {
    $page = requestsRegion(
        $this->actingAs($this->clientUser, 'client')->get(requestsUrl($this->matter))->assertOk()->getContent()
    );

    expect($page)->toContain(__('requests.portal.history.empty'))
        ->and($page)->toContain(__('requests.portal.new.submit'));
});

// =========================================================================================
// TRẢ LỜI THEO LUỒNG (phán quyết 19/09/2026)
// =========================================================================================

it('adds the clients second question to the same thread instead of opening a new one', function () {
    $request = ClientRequest::factory()->for($this->matter)->create([
        'client_user_id' => $this->clientUser->id,
        'status' => ClientRequestStatus::Answered,
    ]);

    requestsPage($this->clientUser, $this->matter)
        ->set('replies.'.$request->id, 'Tôi còn một ý chưa rõ.')
        ->call('submitReply', $request->id)
        ->assertHasNoErrors()
        ->assertSee('Tôi còn một ý chưa rõ.');

    expect(ClientRequest::query()->withoutGlobalScope(ClientPortalScope::class)->count())->toBe(1)
        ->and(ClientRequestReply::query()->withoutGlobalScope(ClientPortalScope::class)->count())->toBe(1);
});

/**
 * Cuộc trao đổi đã đóng vẫn ĐỌC được — SPEC §8.3 mục 7 đòi "xem lại lịch sử trao đổi" — nhưng ô
 * viết tiếp BIẾN MẤT thay vì hiện ra rồi từ chối khi bấm, và chỗ nó đứng nói ra việc tiếp theo.
 */
it('keeps a closed thread readable but takes the reply box away and says why', function () {
    $closed = ClientRequest::factory()->for($this->matter)->create([
        'client_user_id' => $this->clientUser->id,
        'subject' => 'Việc đã xong',
        'content' => 'Nội dung câu hỏi cũ',
        'status' => ClientRequestStatus::Closed,
    ]);

    $page = requestsRegion(
        $this->actingAs($this->clientUser, 'client')->get(requestsUrl($this->matter))->assertOk()->getContent()
    );

    expect($page)->toContain('Nội dung câu hỏi cũ')
        ->and($page)->toContain('data-portal-closed="'.$closed->id.'"')
        ->and($page)->not->toContain('wire:submit="submitReply('.$closed->id.')"');

    // Và cổng thật vẫn đứng sau cái ô đã biến mất: gọi thẳng vẫn bị từ chối, bằng một câu
    // tiếng Việt nói ra việc tiếp theo — không phải một lỗi 500.
    requestsPage($this->clientUser, $this->matter)
        ->set('replies.'.$closed->id, 'Tôi viết thêm')
        ->call('submitReply', $closed->id)
        ->assertOk();

    expect(ClientRequestReply::query()->withoutGlobalScope(ClientPortalScope::class)->count())->toBe(0);
});

/**
 * Phán quyết 19/09/2026 — cách đọc **theo `Client`**. Vế dương (anh em đọc và viết được) và vế
 * âm (khách hàng khác thì không) trong cùng một test, vì một mình vế dương không phân biệt được
 * "đọc theo `Client`" với "không có phạm vi nào cả".
 */
it('lets a second account of the same client read and answer the first accounts thread, and no one else', function () {
    $request = ClientRequest::factory()->for($this->matter)->create([
        'client_user_id' => $this->clientUser->id,
        'content' => 'Câu hỏi của người thứ nhất',
    ]);

    $page = requestsRegion(
        $this->actingAs($this->sibling, 'client')->get(requestsUrl($this->matter))->assertOk()->getContent()
    );

    expect($page)->toContain('Câu hỏi của người thứ nhất');

    requestsPage($this->sibling, $this->matter)
        ->set('replies.'.$request->id, 'Tôi là người nhà, xin hỏi thêm.')
        ->call('submitReply', $request->id)
        ->assertHasNoErrors();

    expect(ClientRequestReply::query()->withoutGlobalScope(ClientPortalScope::class)->count())->toBe(1);

    // Vế âm: một khách hàng khác hoàn toàn không mở được chính hồ sơ đó.
    $outsider = ClientUser::factory()->activated()->create();
    $this->actingAs($outsider, 'client')->get(requestsUrl($this->matter))->assertNotFound();
});

// =========================================================================================
// SỬA THAM SỐ — SPEC §11, §10.10, tiêu chí §14 mục 5
// =========================================================================================

it('answers another clients matter with 404, not 403', function () {
    $foreign = Matter::factory()->create(['is_published_to_portal' => true]);

    $this->actingAs($this->clientUser, 'client')->get(requestsUrl($foreign))->assertNotFound();
    $this->actingAs($this->clientUser, 'client')->get(requestsUrl(999999))->assertNotFound();
});

/**
 * **Sửa tham số ở đường GHI**, không chỉ ở đường đọc: `submitReply()` chạy trên một request cập
 * nhật Livewire, nơi `AnswerDeniedPanelRequestsWithNotFound` **không** phủ tới (Task 2 đã chứng
 * minh trong vendor). Nên trang phải tự `abort(404)`, và test này đo đúng đường đó.
 */
it('answers a tampered reply id with 404 on a livewire update, and writes nothing', function () {
    $foreignRequest = ClientRequest::factory()
        ->for(Matter::factory()->create(['is_published_to_portal' => true]))
        ->create();

    requestsPage($this->clientUser, $this->matter)
        ->set('replies.'.$foreignRequest->id, 'Cho tôi xem')
        ->call('submitReply', $foreignRequest->id)
        ->assertNotFound();

    requestsPage($this->clientUser, $this->matter)
        ->call('submitReply', 999999)
        ->assertNotFound();

    expect(ClientRequestReply::query()->withoutGlobalScope(ClientPortalScope::class)->count())->toBe(0);
});

/**
 * **Trình duyệt không đặt lại được `$record`.** Rà soát Task 4 đo trên HTTP thật rằng một thuộc
 * tính công khai KHÔNG khoá nhận được giá trị mới qua `updates:{"record": …}` của Livewire. Ở đây
 * nó là `#[Locked]`, nên một lần sửa như vậy dừng ngay ở framework.
 *
 * Khoá KHÔNG thay cho việc gác, và test này không được đọc thành "đã khoá nên khỏi gác": các
 * test 404 bên trên vẫn là tầng thật. Vế dương nằm ngay dưới — hai ô nhập thì trình duyệt ĐƯỢC
 * đặt, vì đó là việc của chúng.
 */
it('refuses a forged record on a livewire update while still letting the form fields be typed in', function () {
    $sibling = Matter::factory()->for($this->client)->create(['is_published_to_portal' => true]);

    // Khẳng định theo CÂU CHỮ, không theo tên lớp: Livewire bọc lại exception của mình trước khi
    // nó ra tới đây, nên một khẳng định `toThrow(CannotUpdateLockedPropertyException::class)` đỏ
    // ngay cả khi khoá đang hoạt động đúng — đo được.
    try {
        requestsPage($this->clientUser, $this->matter)->set('record', $sibling->getKey());
        $this->fail('Livewire đáng lẽ phải từ chối một thuộc tính đã khoá');
    } catch (Throwable $exception) {
        expect($exception->getMessage())->toContain('Cannot update locked property')
            ->and($exception->getMessage())->toContain('record');
    }

    requestsPage($this->clientUser, $this->matter)
        ->set('subject', 'Gõ được')
        ->assertSet('subject', 'Gõ được');
});

/**
 * **Khách không sửa được yêu cầu đã gửi, và không giao việc được.** SPEC §4.14 không có bước nào
 * cho hai việc đó, và `ClientRequestPolicy::update()` — cổng của cả "đổi trạng thái" lẫn "gán
 * người xử lý" — từ chối mọi `ClientUser`, kể cả trên yêu cầu của CHÍNH họ.
 *
 * Vế dương đứng cạnh để test này đo quyền chứ không đo một cổng hỏng: một luật sư trong đội ngũ
 * thì qua được.
 */
it('never lets a client edit or assign a request, not even their own', function () {
    $own = ClientRequest::factory()->for($this->matter)->create(['client_user_id' => $this->clientUser->id]);
    $foreign = ClientRequest::factory()
        ->for(Matter::factory()->create(['is_published_to_portal' => true]))
        ->create();

    expect(Gate::forUser($this->clientUser)->allows('update', $own))->toBeFalse()
        ->and(Gate::forUser($this->clientUser)->allows('update', $foreign))->toBeFalse()
        ->and(Gate::forUser($this->lawyer)->allows('update', $own))->toBeTrue();

    // Và không có bề mặt nào trên cổng diễn đạt được hai việc đó: trang không có phương thức
    // nào để gọi. Đo bằng chính lớp, để một phương thức thêm vào sau này làm test đỏ.
    expect(method_exists(MyRequests::class, 'assign'))->toBeFalse()
        ->and(method_exists(MyRequests::class, 'changeStatus'))->toBeFalse()
        ->and(method_exists(MyRequests::class, 'updateRequest'))->toBeFalse();
});

// =========================================================================================
// NGHI THỨC BA TẦNG — tầng 2: thay global scope bằng một scope RỖNG, trang vẫn phải từ chối
// =========================================================================================

/**
 * @param  list<class-string<Model>>  $models
 */
function withEmptyScopeForRequests(array $models, Closure $callback): mixed
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

it('keeps another clients thread off the page when the request scope forgets its rule', function () {
    ClientRequest::factory()->for($this->matter)->create([
        'client_user_id' => $this->clientUser->id,
        'content' => 'Câu hỏi của chính tôi',
    ]);

    $foreign = ClientRequest::factory()
        ->for(Matter::factory()->create(['is_published_to_portal' => true]))
        ->create(['content' => 'Của khách khác '.REQUESTS_MARKER]);

    $html = withEmptyScopeForRequests([ClientRequest::class], function () use ($foreign) {
        // Tầng truy vấn đã thủng — nếu không thì khẳng định dưới không đo tầng nào cả.
        expect(ClientRequest::find($foreign->getKey()))->not->toBeNull();

        return $this->actingAs($this->clientUser, 'client')->get(requestsUrl($this->matter))->assertOk()->getContent();
    });

    expect($html)->not->toContain(REQUESTS_MARKER)
        // Vế dương TRONG CÙNG ngữ cảnh thủng: trang không từ chối tất cả.
        ->and($html)->toContain('Câu hỏi của chính tôi');
});

/**
 * Một yêu cầu đã rút (xoá mềm) không quay lại: `withTrashed()` gỡ `SoftDeletingScope` chứ không
 * gỡ `ClientPortalScope`, nên điều kiện đó được phát biểu ở CẢ hai chỗ —
 * `ClientRequest::applyClientPortalConstraints()` và `ClientRequestPolicy::view()` (bằng thuộc
 * tính). Ở đây cả hai scope cùng bị làm rỗng, nên chỉ tầng thuộc tính còn đứng.
 */
it('keeps a retracted thread off the page even with both scopes emptied', function () {
    $live = ClientRequest::factory()->for($this->matter)->create([
        'client_user_id' => $this->clientUser->id,
        'content' => 'Yêu cầu còn hiệu lực',
    ]);
    $retracted = ClientRequest::factory()->for($this->matter)->create([
        'client_user_id' => $this->clientUser->id,
        'content' => 'Đã rút '.REQUESTS_MARKER,
    ]);
    $retracted->delete();

    $html = withEmptyScopeForRequests([ClientRequest::class], function () use ($retracted) {
        // Thay ĐÚNG `SoftDeletingScope` bằng một scope rỗng. Một scope mới mang tên khác
        // (`addGlobalScope('soft_deleting_off', …)`) KHÔNG gỡ được nó — bản đầu của test này làm
        // đúng như vậy và vì thế nó không bao giờ chạm tới điều kiện mà tên nó nêu ra: hàng đã
        // rút chưa từng lọt vào truy vấn, nên không có gì để tầng thứ hai từ chối. Đo được bằng
        // mutation: xoá lần lọc `Gate` trong `MyRequests::threads()` mà cả tệp vẫn xanh.
        ClientRequest::addGlobalScope(SoftDeletingScope::class, function (): void {});

        // Khẳng định TẦNG TRUY VẤN đã thủng THẬT, bằng một truy vấn không nhắc tới `withTrashed()`
        // — nếu câu này đỏ thì phần còn lại của test không đo tầng nào cả.
        expect(ClientRequest::query()->find($retracted->getKey()))->not->toBeNull();

        return $this->actingAs($this->clientUser, 'client')->get(requestsUrl($this->matter))->assertOk()->getContent();
    });

    expect($html)->not->toContain(REQUESTS_MARKER)
        ->and($html)->toContain('Yêu cầu còn hiệu lực')
        ->and($live->trashed())->toBeFalse();
});

// =========================================================================================
// GHI CHÚ NỘI BỘ — SPEC §11
// =========================================================================================

/**
 * Trang này không đọc `stage_logs`, nhưng nó nằm trên cùng một hồ sơ, nên một chuỗi đánh dấu
 * trong `internal_note` phải không xuất hiện ở bất kỳ đâu trong HTML. Vế dương: nội dung khách
 * đọc được của chính dòng ấy hiện ra ở trang tiến độ, nên fixture không phải một dòng vô hình.
 */
it('never prints an internal note anywhere in the requests page', function () {
    StageLog::factory()->for($this->matter)->published()->create([
        'public_content' => 'Toà đã nhận đơn khởi kiện.',
        'internal_note' => 'Nội bộ '.REQUESTS_MARKER,
    ]);

    ClientRequest::factory()->for($this->matter)->create(['client_user_id' => $this->clientUser->id]);

    $html = $this->actingAs($this->clientUser, 'client')->get(requestsUrl($this->matter))->assertOk()->getContent();

    expect($html)->not->toContain(REQUESTS_MARKER);

    $progress = $this->actingAs($this->clientUser, 'client')
        ->get(MatterProgress::getUrl(['record' => $this->matter->getKey()], panel: 'portal'))
        ->assertOk()->getContent();

    expect($progress)->toContain('Toà đã nhận đơn khởi kiện.')
        ->and($progress)->not->toContain(REQUESTS_MARKER);
});

// =========================================================================================
// LỜI TỪ CHỐI ĐẾN ĐƯỢC MẮT KHÁCH — bài học M3 Task 9
// =========================================================================================

it('shows a vietnamese message under the field instead of a 500 when the form is empty', function () {
    requestsPage($this->clientUser, $this->matter)
        ->set('subject', '')
        ->set('content', '')
        ->call('submitRequest')
        ->assertHasErrors(['subject', 'content']);

    expect(ClientRequest::query()->withoutGlobalScope(ClientPortalScope::class)->count())->toBe(0);
});

it('binds an empty reply error to that threads own box, not to the new request form', function () {
    $request = ClientRequest::factory()->for($this->matter)->create(['client_user_id' => $this->clientUser->id]);

    requestsPage($this->clientUser, $this->matter)
        ->set('replies.'.$request->id, '   ')
        ->call('submitReply', $request->id)
        ->assertHasErrors('replies.'.$request->id)
        ->assertHasNoErrors('content');
});

// =========================================================================================
// LỐI VÀO TỪ TRANG CHI TIẾT — seam của Task 4
// =========================================================================================

it('is reachable from block 7 of the matter detail page', function () {
    $html = $this->actingAs($this->clientUser, 'client')
        ->get(MatterProgress::getUrl(['record' => $this->matter->getKey()], panel: 'portal'))
        ->assertOk()->getContent();

    expect($html)->toContain(requestsUrl($this->matter))
        ->and($html)->toContain(__('portal_progress.blocks.requests.open'));
});

// =========================================================================================
// DÙNG ĐƯỢC Ở 375px — cấu trúc, không phải ảnh chụp màn hình
// =========================================================================================

it('lays the page out in one column with no table and 44px tap targets', function () {
    ClientRequest::factory()->for($this->matter)->create(['client_user_id' => $this->clientUser->id]);

    $page = requestsRegion(
        $this->actingAs($this->clientUser, 'client')->get(requestsUrl($this->matter))->assertOk()->getContent()
    );

    expect($page)->not->toContain('<table')
        ->and($page)->toContain('flex-direction:column');

    // Ranh giới từ sau tên thẻ là bắt buộc: không có nó, `<a` khớp cả `<article`, và test đo một
    // khối bố cục thay vì một thứ bấm được.
    preg_match_all('/<(?:button|a|textarea|input)[\s>][^>]*style="([^"]*)"/', $page, $controls);

    expect($controls[1])->not->toBeEmpty();

    foreach ($controls[1] as $style) {
        expect($style)->toContain('min-height:44px');
    }
});

// =========================================================================================
// "ĐÃ TRẢ LỜI" MÀ KHÔNG CÓ CÂU TRẢ LỜI VIẾT RA — REQ-5
// =========================================================================================

/** Câu status của MỘT luồng cụ thể, cắt ra khỏi trang bằng chính thuộc tính đo được của nó. */
function statusLineFor(string $html, int|string $requestId): string
{
    preg_match('/data-portal-status="'.preg_quote((string) $requestId, '/').'"[^>]*>(.*?)<\/p>/s', $html, $matches);

    return trim($matches[1] ?? '');
}

/**
 * `TriageClientRequest::setStatus()` cho phép đặt thẳng `answered` mà không cần viết câu trả lời
 * nào — ca có chủ đích ("luật sư trả lời qua điện thoại rồi đánh dấu thẳng Đã trả lời"). Câu mặc
 * định (`requests.portal.status.answered`) mời khách "xem bên dưới", nhưng bên dưới khi đó
 * TRỐNG. Vế dương đứng cạnh, TRONG CÙNG trang: một luồng `answered` CÓ câu trả lời viết ra vẫn
 * dùng câu gốc — nếu không, một mutation biến MỌI luồng `answered` thành câu điện thoại vẫn xanh.
 */
it('tells the client the office answered by phone when nothing was written, and the normal line otherwise', function () {
    $silent = ClientRequest::factory()->for($this->matter)->create([
        'client_user_id' => $this->clientUser->id,
        'status' => ClientRequestStatus::Answered,
    ]);
    $written = ClientRequest::factory()->for($this->matter)->create([
        'client_user_id' => $this->clientUser->id,
        'status' => ClientRequestStatus::Answered,
    ]);
    ClientRequestReply::factory()->for($written, 'request')->create([
        'author_type' => $this->lawyer->getMorphClass(),
        'author_id' => $this->lawyer->id,
        'content' => 'Đã nộp đơn xong.',
    ]);

    $page = requestsRegion(
        $this->actingAs($this->clientUser, 'client')->get(requestsUrl($this->matter))->assertOk()->getContent()
    );

    // **Chữ tiếng Việt VIẾT THẲNG, không qua `__()`.** Fix round 1, C1: bản trước so
    // `__('requests.portal.status.answered_by_phone')` với chính nó — khoá đó không tồn tại (nó
    // nằm ở `portal.answered_by_phone`, NGOÀI mảng `status`), nên Laravel trả về NGUYÊN VĂN khoá
    // khi không tìm thấy bản dịch, và một khẳng định so khoá-với-chính-nó XANH bất kể trang có in
    // ra khoá thô hay câu thật. Viết thẳng câu tiếng Việt ở đây là cách DUY NHẤT một lần đổi tên
    // khoá làm test này đỏ thật.
    expect(statusLineFor($page, $silent->id))->toBe('Văn phòng đã trả lời anh/chị qua điện thoại hoặc trực tiếp.')
        ->and(statusLineFor($page, $silent->id))->not->toContain('requests.portal')
        ->and(statusLineFor($page, $written->id))->toBe('Văn phòng đã trả lời, anh/chị xem bên dưới');
});

/**
 * **Bảo vệ (fix round 1, C1): mọi khoá `requests.*` mà Task 18 thêm phải giải quyết được.**
 * `__($key)` trả về nguyên văn `$key` khi không tìm thấy bản dịch — im lặng, không lỗi, không
 * cảnh báo — nên một khoá gõ sai hoặc đặt sai chỗ (đúng lỗi vừa xảy ra ở trên) không tự lộ ra
 * bằng bất cứ cách nào khác ngoài việc có người đọc thấy khoá thô trên màn hình. Test này quét
 * đúng những khoá Task 18 đã thêm/dùng và hỏi thẳng `__()`.
 */
it('resolves every requests.* key this task added or touched', function () {
    $keys = [
        'requests.portal.shared_accounts_notice',
        'requests.portal.status.answered_by_phone',
        'requests.portal.history.heading',
        'requests.portal.history.unknown_client_author',
        'requests.tab.assignee_deactivated',
        'requests.tab.actions.reply_success_hidden',
        'requests.tab.actions.change_status_unassigned',
    ];

    foreach ($keys as $key) {
        // Không truyền tham số (`:name`, …) — vẫn đủ để đo đúng thứ cần đo (khoá có tồn tại hay
        // không), một khoá thiếu tham số chỉ để lại nguyên văn `:name` chứ không đổi kết quả này.
        expect(__($key))->not->toBe($key, "khoá không giải quyết được: {$key}");
    }
});

// =========================================================================================
// HOẠT ĐỘNG GẦN NHẤT TRÊN CỔNG — fix round 1, ruling + I1
// =========================================================================================

/**
 * Fix round 1, I1 — đường ghi thứ nhất: `OpenClientRequest::handle()`, qua đúng màn hình khách
 * dùng (`submitRequest`). Một luồng VỪA MỞ phải nổi lên trên MỌI luồng cũ, kể cả những luồng đã
 * có `last_activity_at` GẦN với hiện tại (`$newer`) — nếu cột này không được ghi lúc tạo, giá trị
 * `null` của nó sẽ xếp CUỐI trong `ORDER BY ... DESC` (SQLite: `NULL` luôn nhỏ hơn mọi giá trị),
 * và luồng mới nhất sẽ rơi xuống ĐÁY danh sách thay vì lên đầu.
 */
it('a freshly submitted request sorts above older but silent threads on the portal page', function () {
    $older = ClientRequest::factory()->for($this->matter)->create([
        'client_user_id' => $this->clientUser->id,
        'created_at' => now()->subDays(3),
        'last_activity_at' => now()->subDays(3),
    ]);
    $newer = ClientRequest::factory()->for($this->matter)->create([
        'client_user_id' => $this->clientUser->id,
        'created_at' => now()->subHour(),
        'last_activity_at' => now()->subHour(),
    ]);

    requestsPage($this->clientUser, $this->matter)
        ->set('subject', 'Câu hỏi mới nhất')
        ->set('content', 'Nội dung mới nhất, vừa gửi.')
        ->call('submitRequest')
        ->assertHasNoErrors();

    $page = requestsRegion(
        $this->actingAs($this->clientUser, 'client')->get(requestsUrl($this->matter))->assertOk()->getContent()
    );

    $freshId = ClientRequest::query()->withoutGlobalScope(ClientPortalScope::class)
        ->where('subject', 'Câu hỏi mới nhất')->value('id');

    $posFresh = strpos($page, 'data-portal-thread="'.$freshId.'"');
    $posNewer = strpos($page, 'data-portal-thread="'.$newer->id.'"');
    $posOlder = strpos($page, 'data-portal-thread="'.$older->id.'"');

    expect($posFresh)->not->toBeFalse()->and($posNewer)->not->toBeFalse()->and($posOlder)->not->toBeFalse()
        ->and($posFresh)->toBeLessThan($posNewer)
        ->and($posNewer)->toBeLessThan($posOlder);
});

/**
 * Fix round 1, ruling — trang cổng phải sắp CÙNG cột với hộp thư nội bộ
 * ({@see ClientRequestsRelationManager}).
 * Cùng kịch bản với test admin "sorts the inbox by latest activity...", đo trên chính trang
 * khách: một luồng CŨ hơn, sau khi khách viết tiếp vào nó, phải nổi lên trên một luồng MỚI hơn
 * nhưng im lặng. Sắp theo `created_at` (bản trước `MyRequests::threads()`) sẽ không đảo được thứ
 * tự này, vì `created_at` của luồng cũ không đổi.
 */
it('sorts the portal list by latest activity, not by when the client first wrote in', function () {
    $older = ClientRequest::factory()->for($this->matter)->create([
        'client_user_id' => $this->clientUser->id,
        'created_at' => now()->subDays(3),
        'last_activity_at' => now()->subDays(3),
    ]);
    $newer = ClientRequest::factory()->for($this->matter)->create([
        'client_user_id' => $this->clientUser->id,
        'created_at' => now()->subHour(),
        'last_activity_at' => now()->subHour(),
    ]);

    $page = requestsRegion(
        $this->actingAs($this->clientUser, 'client')->get(requestsUrl($this->matter))->assertOk()->getContent()
    );

    expect(strpos($page, 'data-portal-thread="'.$newer->id.'"'))
        ->toBeLessThan(strpos($page, 'data-portal-thread="'.$older->id.'"'));

    requestsPage($this->clientUser, $this->matter)
        ->set('replies.'.$older->id, 'Tôi hỏi thêm.')
        ->call('submitReply', $older->id)
        ->assertHasNoErrors();

    $page = requestsRegion(
        $this->actingAs($this->clientUser, 'client')->get(requestsUrl($this->matter))->assertOk()->getContent()
    );

    expect(strpos($page, 'data-portal-thread="'.$older->id.'"'))
        ->toBeLessThan(strpos($page, 'data-portal-thread="'.$newer->id.'"'));
});

// =========================================================================================
// TÀI KHOẢN NGƯỜI NHÀ — REQ-8
// =========================================================================================

it('tells the client that other accounts of the same customer can read this too', function () {
    $page = requestsRegion(
        $this->actingAs($this->clientUser, 'client')->get(requestsUrl($this->matter))->assertOk()->getContent()
    );

    expect($page)->toContain(__('requests.portal.shared_accounts_notice'));
});

/** Dòng tác giả của MỘT mục cụ thể, cắt bằng `data-portal-entry-author` — xem docblock blade. */
function entryAuthorLine(string $html, int $index): string
{
    preg_match('/data-portal-entry-author="'.$index.'"[^>]*>(.*?)<\/p>/s', $html, $matches);

    // Blade biên dịch `@if`/`@elseif`/`@else` thành các chú thích HTML `<!--[if BLOCK]>-->` bao
    // quanh nhánh được vẽ, cộng khoảng trắng thụt dòng của chính template — cả hai không phải một
    // phần của CÂU CHỮ đang được đo, nên bỏ đi trước khi so sánh.
    $line = preg_replace('/<!--.*?-->/s', '', $matches[1] ?? '');
    $line = preg_replace('/\s+/', ' ', (string) $line);

    return trim($line);
}

/**
 * **Nhãn "Anh/chị viết" chỉ dành cho CHÍNH người đang xem — câu của người NHÀ KHÁC mang tên
 * người đó.** Trước bản sửa này mọi dòng do một `ClientUser` viết đều mang nhãn "Anh/chị viết",
 * kể cả dòng của người kia — người đang xem sẽ tưởng câu của người nhà là câu của chính mình.
 * Test đổi VAI người xem giữa hai nửa, trên CÙNG một cuộc trao đổi, để đo đúng "theo người đang
 * xem" chứ không phải "theo người viết câu đầu tiên".
 *
 * **Fix round 1, I2 — nội dung KHÔNG được nhắc tên ai.** Bản trước dùng nội dung câu trả lời
 * chính là "Câu trả lời thêm của Trần Thị Bình", nên `toContain('Trần Thị Bình')` xanh vì CHÍNH
 * VĂN BẢN đó chứa tên — không đo được nhãn tác giả có đúng hay không. Ở đây nội dung trung lập,
 * và khẳng định cắt đúng dòng tác giả của TỪNG mục bằng {@see entryAuthorLine()} — mục 0 là câu
 * hỏi gốc (của Nguyễn Văn An), mục 1 là câu trả lời thêm (của Trần Thị Bình) — thay vì hỏi cả
 * trang có chứa một chuỗi hay không.
 */
it('labels each entry by who the viewer is, not by who wrote it', function () {
    $request = ClientRequest::factory()->for($this->matter)->create([
        'client_user_id' => $this->clientUser->id,
        'content' => 'Câu hỏi ban đầu, không nhắc tên ai.',
    ]);
    ClientRequestReply::factory()->for($request, 'request')->create([
        'author_type' => $this->sibling->getMorphClass(),
        'author_id' => $this->sibling->id,
        'content' => 'Con xin bổ sung thêm một ý, không nhắc tên ai.',
    ]);

    $ownerPage = requestsRegion(
        $this->actingAs($this->clientUser, 'client')->get(requestsUrl($this->matter))->assertOk()->getContent()
    );

    // Nguyễn Văn An xem: mục 0 (câu hỏi của chính mình) mang "Anh/chị viết"; mục 1 (của người
    // nhà) mang tên người đó, KHÔNG mang nhãn "Anh/chị viết".
    expect(entryAuthorLine($ownerPage, 0))->toBe(__('requests.portal.history.from_client').' — Nguyễn Văn An')
        ->and(entryAuthorLine($ownerPage, 1))->toBe('Trần Thị Bình');

    $siblingPage = requestsRegion(
        $this->actingAs($this->sibling, 'client')->get(requestsUrl($this->matter))->assertOk()->getContent()
    );

    // Đổi vai: Trần Thị Bình xem CÙNG luồng đó — mục 0 (của người nhà) giờ mang tên An, còn mục 1
    // (câu của CHÍNH Bình) mới mang "Anh/chị viết". Nhãn theo NGƯỜI ĐANG XEM, không theo thứ tự
    // viết.
    expect(entryAuthorLine($siblingPage, 0))->toBe('Nguyễn Văn An')
        ->and(entryAuthorLine($siblingPage, 1))->toBe(__('requests.portal.history.from_client').' — Trần Thị Bình');
});

/**
 * **Fix round 1, minor.** Một `author_id` không giải quyết được qua {@see MyRequests::
 * authorNames()} (id trỏ ra ngoài phạm vi `client_id` của người đang xem — dữ liệu hỏng, vì
 * scope portal đáng lẽ không cho phép, nhưng hàm không tin điều đó và tự giới hạn lại) phải rơi
 * về một nhãn TRUNG LẬP — không phải "Anh/chị viết", câu chỉ dành cho chính người xem.
 */
it('shows a neutral label instead of anh/chi viet when a co-accounts name cannot be resolved', function () {
    $outsider = ClientUser::factory()->activated()->create(['name' => 'Người Ngoài Phạm Vi']);

    $request = ClientRequest::factory()->for($this->matter)->create([
        'client_user_id' => $this->clientUser->id,
        'content' => 'Câu hỏi ban đầu.',
    ]);
    // Dữ liệu hỏng cố ý: `author_id` trỏ sang một `ClientUser` KHÁC `client_id`, thứ
    // `authorNames()` không đọc tên (giới hạn `where('client_id', $this->viewer()->client_id)`).
    ClientRequestReply::factory()->for($request, 'request')->create([
        'author_type' => $outsider->getMorphClass(),
        'author_id' => $outsider->id,
        'content' => 'Một dòng dữ liệu hỏng.',
    ]);

    $page = requestsRegion(
        $this->actingAs($this->clientUser, 'client')->get(requestsUrl($this->matter))->assertOk()->getContent()
    );

    expect(entryAuthorLine($page, 1))->toBe(__('requests.portal.history.unknown_client_author'))
        ->and(entryAuthorLine($page, 1))->not->toContain(__('requests.portal.history.from_client'));
});
