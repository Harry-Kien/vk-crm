<?php

use App\Enums\ClientRequestStatus;
use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\ClientRequestsRelationManager;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

/**
 * Tab "Yêu cầu từ khách" (SPEC §7.2) — hộp thư của vụ việc, đầu VĂN PHÒNG của cuộc trao đổi.
 *
 * Tệp này đo hai thứ: **ai nhìn thấy gì** (phạm vi qua `ScopesToVisibleMatters`, và ba nút —
 * trả lời, giao việc, đổi trạng thái — chỉ hiện cho người ghi được vào vụ việc), và **một lời từ
 * chối của Action đến được mắt người dùng bằng tiếng Việt** thay vì thành trang 500.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->client = Client::factory()->create(['name' => 'Khách hàng A']);
    $this->clientUser = ClientUser::factory()->activated()->create([
        'client_id' => $this->client->id,
        'name' => 'Nguyễn Văn An',
    ]);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Vũ Khang']);
    $this->matter = Matter::factory()->for($this->client)->create([
        'lead_lawyer_id' => $this->lawyer->id,
        'is_published_to_portal' => true,
    ]);

    $this->request = ClientRequest::factory()->for($this->matter)->create([
        'client_user_id' => $this->clientUser->id,
        'subject' => 'Xin hỏi về ngày hoà giải',
        'content' => 'Văn phòng cho tôi hỏi ngày hoà giải đã có chưa ạ.',
        'status' => ClientRequestStatus::New,
    ]);
});

function requestsInbox(Matter $matter)
{
    return test()->livewire(ClientRequestsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ]);
}

function reloadRequest(ClientRequest $request): ClientRequest
{
    return ClientRequest::query()->withoutGlobalScope(ClientPortalScope::class)->findOrFail($request->getKey());
}

// =========================================================================================
// VĂN PHÒNG NHÌN THẤY YÊU CẦU — nửa "nhận được phản hồi" của SPEC §14 mục 4
// =========================================================================================

it('shows the clients request in the matters inbox', function () {
    $this->actingAs($this->lawyer, 'web');

    requestsInbox($this->matter)
        ->assertSee('Xin hỏi về ngày hoà giải')
        ->assertSee('Nguyễn Văn An')
        ->assertSee(ClientRequestStatus::New->label())
        ->assertSee(__('requests.tab.unassigned'));
});

/**
 * `ScopesToVisibleMatters` như mọi relation manager khác: một yêu cầu trên một vụ việc khác không
 * lọt vào bảng của vụ việc này. Vế dương trong cùng test — yêu cầu của chính vụ việc PHẢI hiện —
 * vì một bảng rỗng cũng "không chứa" mọi thứ.
 */
it('never shows a request that belongs to another matter', function () {
    $foreign = ClientRequest::factory()
        ->for(Matter::factory()->create(['is_published_to_portal' => true]))
        ->create(['subject' => 'Yêu cầu của hồ sơ khác']);

    $this->actingAs($this->lawyer, 'web');

    requestsInbox($this->matter)
        ->assertSee('Xin hỏi về ngày hoà giải')
        ->assertDontSee('Yêu cầu của hồ sơ khác')
        ->assertCanNotSeeTableRecords([$foreign]);
});

/**
 * SPEC §5: kế toán không có `matter.update`, nên họ không nhận, không giao, không đổi trạng thái
 * và không trả lời. Vế dương đứng cạnh: luật sư phụ trách thấy cả ba nút.
 */
it('hides every action from an accountant and shows all three to a team lawyer', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->actingAs($accountant, 'web');

    requestsInbox($this->matter)
        ->assertTableActionHidden('reply', $this->request)
        ->assertTableActionHidden('assign', $this->request)
        ->assertTableActionHidden('changeStatus', $this->request);

    $this->actingAs($this->lawyer, 'web');

    requestsInbox($this->matter)
        ->assertTableActionVisible('reply', $this->request)
        ->assertTableActionVisible('assign', $this->request)
        ->assertTableActionVisible('changeStatus', $this->request);
});

/**
 * Vụ việc `restricted` (SPEC §4.6): một luật sư ngoài đội ngũ không đọc được hộp thư của nó.
 * Cùng `listableBy()` mà mọi danh sách khác dùng, không một câu `where` nào viết tay.
 */
it('keeps a restricted matters inbox away from a lawyer who is not on the team', function () {
    $this->matter->update(['confidentiality' => Confidentiality::Restricted]);
    $outsider = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($outsider, 'web');

    requestsInbox($this->matter)->assertCanNotSeeTableRecords([$this->request]);

    // Vế dương: luật sư phụ trách vẫn đọc được chính hộp thư đó.
    $this->actingAs($this->lawyer, 'web');

    requestsInbox($this->matter)->assertCanSeeTableRecords([$this->request]);
});

// =========================================================================================
// TRẢ LỜI — và khách đọc được câu trả lời đó
// =========================================================================================

it('sends an answer through the action and moves the thread to answered', function () {
    $this->actingAs($this->lawyer, 'web');

    requestsInbox($this->matter)
        ->callTableAction('reply', $this->request, ['content' => 'Ngày hoà giải là 12/10, văn phòng đã nhận giấy mời.'])
        ->assertHasNoTableActionErrors();

    $reply = ClientRequestReply::query()->withoutGlobalScope(ClientPortalScope::class)->firstOrFail();

    expect($reply->content)->toBe('Ngày hoà giải là 12/10, văn phòng đã nhận giấy mời.')
        ->and($reply->author_id)->toBe($this->lawyer->id)
        ->and(reloadRequest($this->request)->status)->toBe(ClientRequestStatus::Answered)
        ->and(reloadRequest($this->request)->answered_at)->not->toBeNull();
});

/**
 * Modal trả lời phải chứa **nguyên văn** thứ khách đã hỏi: người viết câu trả lời không được
 * phải nhớ lại câu hỏi từ một màn hình khác.
 */
it('puts the whole conversation inside the reply modal', function () {
    ClientRequestReply::factory()->for($this->request, 'request')->create([
        'author_type' => $this->lawyer->getMorphClass(),
        'author_id' => $this->lawyer->id,
        'content' => 'Văn phòng đã hỏi toà.',
    ]);

    $html = (string) ClientRequestsRelationManager::renderThread($this->request->fresh()->load('replies'));

    expect($html)->toContain('Văn phòng cho tôi hỏi ngày hoà giải đã có chưa ạ.')
        ->and($html)->toContain('Văn phòng đã hỏi toà.')
        ->and($html)->toContain('Nguyễn Văn An')
        ->and($html)->toContain('Luật sư Vũ Khang')
        // Kiểu dáng nội tuyến, không phải lớp Tailwind — không có bước dựng CSS trong dự án.
        ->and($html)->toContain('style=');
});

/**
 * Bài học M3 Task 9: một `DomainException` mà màn hình không bắt riêng là một lỗi 500. Cuộc trao
 * đổi đã đóng thì nút trả lời BIẾN MẤT, và gọi thẳng vẫn không ghi được gì.
 */
it('takes the reply button away from a closed thread and writes nothing if called anyway', function () {
    $this->request->update(['status' => ClientRequestStatus::Closed]);

    $this->actingAs($this->lawyer, 'web');

    requestsInbox($this->matter)
        ->assertTableActionHidden('reply', $this->request)
        // Hai nút kia VẪN hiện: "Đổi trạng thái" là đường mở lại một việc đã đóng nhầm.
        ->assertTableActionVisible('changeStatus', $this->request);

    expect(ClientRequestReply::query()->withoutGlobalScope(ClientPortalScope::class)->count())->toBe(0);
});

// =========================================================================================
// NHẬN, GIAO VIỆC, ĐỔI TRẠNG THÁI
// =========================================================================================

it('assigns the request and takes it out of new in the same move', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($this->lawyer, 'web');

    requestsInbox($this->matter)
        ->callTableAction('assign', $this->request, ['assigned_to' => $assistant->id])
        ->assertHasNoTableActionErrors();

    expect(reloadRequest($this->request)->assigned_to)->toBe($assistant->id)
        ->and(reloadRequest($this->request)->status)->toBe(ClientRequestStatus::InProgress);
});

/**
 * Ô chọn chỉ liệt kê đội ngũ của vụ việc, nhưng danh sách là một tiện ích chứ không phải cổng:
 * Action hỏi lại `MatterPolicy::update` TRÊN NGƯỜI ĐƯỢC CHỌN, và câu từ chối gắn vào chính ô đó.
 */
it('refuses to hand the request to someone who cannot open the matter, with the error on that field', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->update(['confidentiality' => Confidentiality::Restricted]);

    $this->actingAs($this->lawyer, 'web');

    requestsInbox($this->matter)
        ->callTableAction('assign', $this->request, ['assigned_to' => $outsider->id])
        ->assertHasTableActionErrors(['assigned_to']);

    expect(reloadRequest($this->request)->assigned_to)->toBeNull();
});

it('changes the status through the action and stamps answered_at when it lands on answered', function () {
    $this->actingAs($this->lawyer, 'web');

    requestsInbox($this->matter)
        ->callTableAction('changeStatus', $this->request, ['status' => ClientRequestStatus::Answered->value])
        ->assertHasNoTableActionErrors();

    expect(reloadRequest($this->request)->status)->toBe(ClientRequestStatus::Answered)
        ->and(reloadRequest($this->request)->answered_at)->not->toBeNull();

    // Và rời khỏi `answered` KHÔNG xoá mốc: một sự kiện đã xảy ra không viết lại được cho khớp
    // một cái nhãn.
    $stamped = reloadRequest($this->request)->answered_at;

    requestsInbox($this->matter)
        ->callTableAction('changeStatus', $this->request, ['status' => ClientRequestStatus::Closed->value]);

    expect(reloadRequest($this->request)->status)->toBe(ClientRequestStatus::Closed)
        ->and(reloadRequest($this->request)->answered_at->toDateTimeString())->toBe($stamped->toDateTimeString());
});

// =========================================================================================
// TRẠNG THÁI BẰNG TIẾNG NGƯỜI Ở CẢ HAI PHÍA
// =========================================================================================

/**
 * Panel nội bộ dùng nhãn NGẮN (`enums.client_request_status.*`), cổng khách dùng một CÂU
 * (`requests.portal.status.*`). Không bên nào in ra tên enum. Hai bộ chữ khác nhau là cố ý — nó
 * được kể ở đầu `lang/vi/requests.php` — nên test này ghim cả "có nhãn đúng" lẫn "không phải
 * nhãn của bên kia".
 */
it('labels the status in the offices own words and never in the enum name', function () {
    $this->actingAs($this->lawyer, 'web');

    foreach (ClientRequestStatus::cases() as $status) {
        $this->request->update(['status' => $status]);

        requestsInbox($this->matter)
            ->assertSee($status->label())
            ->assertDontSee($status->name)
            ->assertDontSee(__('requests.portal.status.'.$status->value));
    }
});
