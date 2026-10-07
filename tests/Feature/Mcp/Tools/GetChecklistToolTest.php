<?php

use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Support\Mcp\AdminUrls;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\Presenters\ChecklistItemPresenter;
use App\Support\Mcp\Presenters\MatterPresenter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;
use Tests\Support\McpOAuth;
use Tests\Support\McpReadWorld;
use Tests\Support\McpToolCall;

/*
|--------------------------------------------------------------------------
| M11 Task 11 — tool `get_checklist` (bảng tool 8 [DC:54])
|--------------------------------------------------------------------------
| Danh mục hồ sơ của MỘT vụ trong tập `McpMatterScope`, theo thứ tự của tab "Danh mục hồ sơ": tên mục,
| bắt buộc hay không, trạng thái, lý do từ chối, và SỐ tài liệu nhóm A/B/C đã gắn — không tên tệp,
| không tiêu đề tài liệu, không đếm nhóm D (R4). Kèm "Đã nộp X/Y" của chính `ChecklistProgress`.
| `MatterChecklistItemPolicy::view` từng mục. Một danh mục là một phần của một vụ, như các bên của
| `get_matter`: không phân trang.
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    McpOAuth::openServer();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->world = McpReadWorld::build();
    $this->token = McpOAuth::accessToken($this, $this->world->lead);
});

/** @return array{matter: array<string, mixed>, items: list<array<string, mixed>>, checklist_progress: array<string, mixed>} */
function checklistOf(string $token, Matter $matter): array
{
    return McpToolCall::structured(test(), $token, 'get_checklist', ['matter_id' => McpIds::encode(McpIds::MATTER, $matter->id)]);
}

function clItemId(MatterChecklistItem $item): string
{
    return McpIds::encode(McpIds::CHECKLIST_ITEM, $item->id);
}

function clAttach(MatterChecklistItem $item, DocumentGroup $group, string $title = 'Tệp đính kèm'): Document
{
    return Document::factory()->group($group)->create([
        'matter_id' => $item->matter_id,
        'matter_checklist_item_id' => $item->id,
        'title' => $title,
    ]);
}

it('trả danh mục theo thứ tự của tab (sort_order rồi id), đủ trường của ChecklistItemPresenter, lý do từ chối, url về tab Danh mục hồ sơ, và "Đã nộp X/Y"', function () {
    $matter = $this->world->matter;
    $second = MatterChecklistItem::factory()->create(['matter_id' => $matter->id, 'name' => 'Giấy chứng nhận quyền sử dụng đất', 'sort_order' => 2, 'status' => ChecklistItemStatus::Accepted]);
    $first = MatterChecklistItem::factory()->create([
        'matter_id' => $matter->id, 'name' => 'CCCD của nguyên đơn', 'sort_order' => 1,
        'status' => ChecklistItemStatus::Rejected, 'rejection_reason' => 'Ảnh mờ, vui lòng chụp lại.',
    ]);
    $third = MatterChecklistItem::factory()->create(['matter_id' => $matter->id, 'name' => 'Giấy uỷ quyền', 'sort_order' => 2, 'is_required' => false, 'status' => ChecklistItemStatus::Missing]);
    // Mục của một vụ KHÁC cũng trong tập MCP của người gọi: không thuộc danh mục của vụ này.
    MatterChecklistItem::factory()->create([
        'matter_id' => Matter::factory()->aiAccessAllowed()->create(['lead_lawyer_id' => $this->world->lead->id])->id,
        'sort_order' => 0,
    ]);

    $out = checklistOf($this->token, $matter);

    expect(array_keys($out))->toBe(['matter', 'items', 'checklist_progress'])
        ->and($out['matter'])->toBe(MatterPresenter::reference($matter))
        ->and(array_column($out['items'], 'id'))->toBe([clItemId($first), clItemId($second), clItemId($third)])
        ->and($out['checklist_progress'])->toBe([
            'submitted' => 1,
            'total' => 2,
            'label' => __('mcp.get_matter.checklist_progress', ['submitted' => 1, 'total' => 2]),
        ]);

    $row = $out['items'][0];

    expect(array_keys($row))->toBe(ChecklistItemPresenter::FIELDS)
        ->and($row['name'])->toBe('CCCD của nguyên đơn')
        ->and($row['is_required'])->toBeTrue()
        ->and($row['status'])->toBe(ChecklistItemStatus::Rejected->value)
        ->and($row['status_label'])->toBe(ChecklistItemStatus::Rejected->label())
        ->and($row['rejection_reason'])->toBe('Ảnh mờ, vui lòng chụp lại.')
        ->and($row['document_count'])->toBe(0)
        ->and($row['url'])->toBe(AdminUrls::checklistItem($first))
        ->and($out['items'][2]['is_required'])->toBeFalse();

    // "Đã nộp X/Y" là đúng con số của get_matter (một định nghĩa, ChecklistProgress).
    expect($out['checklist_progress'])->toBe(McpToolCall::structured($this, $this->token, 'get_matter', ['id' => McpIds::encode(McpIds::MATTER, $matter->id)])['checklist_progress']);
});

it('R4: số tài liệu đã gắn chỉ là số đếm nhóm A/B/C — không tên tệp, không tiêu đề tài liệu, không đếm nhóm D, kể cả với người có document.viewInternal', function () {
    $item = MatterChecklistItem::factory()->create(['matter_id' => $this->world->matter->id]);
    clAttach($item, DocumentGroup::ClientProvided, 'SECRET-CLIENT-FILE-Tapirus');
    clAttach($item, DocumentGroup::Authority, 'Bản đã ký');
    clAttach($item, DocumentGroup::Internal, 'SECRET-INTERNAL-Okapino');
    clAttach($item, DocumentGroup::ClientProvided, 'Đã xoá')->delete();

    // Luật sư phụ trách và admin đều có document.viewInternal (thấy nhóm D trên web).
    foreach ([$this->world->lead, $this->world->admin] as $user) {
        expect($user->can('document.viewInternal'))->toBeTrue();

        $token = McpOAuth::accessToken($this, $user);
        $response = McpToolCall::call($this, $token, 'get_checklist', ['matter_id' => McpIds::encode(McpIds::MATTER, $this->world->matter->id)]);

        expect(checklistOf($token, $this->world->matter)['items'][0]['document_count'])->toBe(2)
            ->and($response->getContent())->not->toContain('SECRET-CLIENT-FILE-Tapirus')
            ->and($response->getContent())->not->toContain('SECRET-INTERNAL-Okapino');
    }
});

it('R3: vụ đội khác, vụ hạn chế của chính mình, vụ denied và id không tồn tại cho CÙNG một phản hồi', function () {
    $call = fn (string $id) => McpToolCall::call($this, $this->token, 'get_checklist', ['matter_id' => $id])->json('result');

    $baseline = $call(McpIds::encode(McpIds::MATTER, 999999));

    expect($baseline['content'])->toBe([['type' => 'text', 'text' => __('mcp.tool_errors.not_found')]])
        ->and($baseline['isError'])->toBeTrue()
        ->and($baseline)->not->toHaveKey('structuredContent');

    foreach ($this->world->hiddenFromLead() as $case => $matter) {
        MatterChecklistItem::factory()->create(['matter_id' => $matter->id]);

        expect($call(McpIds::encode(McpIds::MATTER, $matter->id)))->toBe($baseline, $case);
    }

    foreach (['matter_0', 'item_1', (string) $this->world->matter->id] as $bad) {
        expect($call($bad))->toBe($baseline, $bad);
    }

    expect(checklistOf(McpOAuth::accessToken($this, $this->world->outsider), $this->world->otherTeam)['items'])->toHaveCount(1);
});

it('thành viên đội (trợ lý) đọc được; kế toán bị EnsureMcpAccess từ chối (401, không phản hồi tool); vụ chưa có danh mục thì danh sách rỗng, Đã nộp 0/0', function () {
    expect(checklistOf(McpOAuth::accessToken($this, $this->world->assistant), $this->world->matter))->toMatchArray([
        'items' => [],
        'checklist_progress' => ['submitted' => 0, 'total' => 0, 'label' => __('mcp.get_matter.checklist_progress', ['submitted' => 0, 'total' => 0])],
    ]);

    McpToolCall::refused($this, McpOAuth::accessToken($this, $this->world->accountant), 'get_checklist', [
        'matter_id' => McpIds::encode(McpIds::MATTER, $this->world->matter->id),
    ]);
});

it('mục đã xoá mềm không ra', function () {
    $kept = MatterChecklistItem::factory()->create(['matter_id' => $this->world->matter->id]);
    MatterChecklistItem::factory()->create(['matter_id' => $this->world->matter->id])->delete();

    expect(array_column(checklistOf($this->token, $this->world->matter)['items'], 'id'))->toBe([clItemId($kept)]);
});

it('kế thừa policy web: Gate view từ chối một mục thì mục đó vắng mặt; Gate view từ chối vụ thì "Không tìm thấy"; cặp dương trước', function () {
    $matter = $this->world->matter;
    $hidden = MatterChecklistItem::factory()->create(['matter_id' => $matter->id, 'sort_order' => 1]);
    $shown = MatterChecklistItem::factory()->create(['matter_id' => $matter->id, 'sort_order' => 2]);

    expect(array_column(checklistOf($this->token, $matter)['items'], 'id'))->toBe([clItemId($hidden), clItemId($shown)]);

    Gate::before(fn ($user, string $ability, array $arguments = []) => $ability === 'view'
        && ($arguments[0] ?? null) instanceof MatterChecklistItem
        && $arguments[0]->is($hidden) ? false : null);

    expect(array_column(checklistOf($this->token, $matter)['items'], 'id'))->toBe([clItemId($shown)]);

    Gate::before(fn ($user, string $ability, array $arguments = []) => $ability === 'view'
        && ($arguments[0] ?? null) instanceof Matter
        && $arguments[0]->is($matter) ? false : null);

    expect(McpToolCall::error($this, $this->token, 'get_checklist', ['matter_id' => McpIds::encode(McpIds::MATTER, $matter->id)]))
        ->toBe(__('mcp.tool_errors.not_found'));
});

it('một phiên cổng khách đang mở trong cùng tiến trình không cắt mục hay tài liệu chưa công bố khi đếm', function () {
    $item = MatterChecklistItem::factory()->create(['matter_id' => $this->world->matter->id]);
    clAttach($item, DocumentGroup::Issued);

    $this->actingAs(ClientUser::factory()->activated()->create(), 'client');

    $out = checklistOf($this->token, $this->world->matter);

    expect(array_column($out['items'], 'id'))->toBe([clItemId($item)])
        ->and($out['items'][0]['document_count'])->toBe(1);
});

it('matter_id là tham số bắt buộc', function () {
    expect(McpToolCall::error($this, $this->token, 'get_checklist'))->not->toBe(__('mcp.tool_errors.not_found'));
});
