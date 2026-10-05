<?php

use App\Enums\ChecklistItemStatus;
use App\Enums\ClientRequestStatus;
use App\Enums\DeadlineSeverity;
use App\Enums\MatterRole;
use App\Enums\PartyRole;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\MatterParty;
use App\Models\User;
use App\Support\Mcp\AdminUrls;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\Presenters\MatterPresenter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;
use Tests\Support\McpOAuth;
use Tests\Support\McpReadWorld;
use Tests\Support\McpToolCall;

/*
|--------------------------------------------------------------------------
| M11 Task 10 — tool `get_matter`
|--------------------------------------------------------------------------
| Tổng quan một vụ trong tập `McpMatterScope`: phần của chính vụ (`MatterPresenter::detail`, số điện
| thoại khách đã che, các bên theo R10, không `description_internal`), cộng năm mốc chưa hoàn thành
| gần nhất, "Đã nộp X/Y" (`ChecklistProgress`) và số yêu cầu từ khách đang mở. Vụ đội khác, vụ hạn
| chế, vụ `denied` và id không tồn tại: cùng một phản hồi (R3, Review Focus 2).
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->world = McpReadWorld::build();
    $this->token = McpOAuth::accessToken($this, $this->world->lead);
});

function getMatter(string $token, Matter|string $matter): array
{
    $id = $matter instanceof Matter ? McpIds::encode(McpIds::MATTER, $matter->id) : $matter;

    return McpToolCall::structured(test(), $token, 'get_matter', ['id' => $id]);
}

it('trả tổng quan của vụ: các trường của MatterPresenter::detail, url tuyệt đối về trang vụ', function () {
    $matter = $this->world->matter;
    $matter->forceFill(['court_name' => 'TAND quận Ba Đình', 'case_number' => '12/2026/TLST-KDTM'])->save();

    $out = getMatter($this->token, $matter);

    expect(array_keys($out))->toBe([...MatterPresenter::DETAIL_FIELDS, 'next_deadlines', 'checklist_progress', 'open_client_request_count'])
        ->and($out['id'])->toBe(McpIds::encode(McpIds::MATTER, $matter->id))
        ->and($out['code'])->toBe($matter->code)
        ->and($out['title'])->toBe('Tranh chấp hợp đồng Quokkavan')
        ->and($out['court_name'])->toBe('TAND quận Ba Đình')
        ->and($out['case_number'])->toBe('12/2026/TLST-KDTM')
        ->and($out['lead_lawyer'])->toBe('Luật sư Phụ Trách')
        ->and($out['stage'])->toBe($matter->stage)
        ->and($out['stage_label'])->toBe($matter->currentStage()->label)
        ->and($out['url'])->toBe(AdminUrls::matter($matter))
        ->and($out['url'])->toStartWith('http');

    expect(collect($out['team'])->pluck('role_in_matter')->sort()->values()->all())
        ->toBe([MatterRole::Assistant->value, MatterRole::Lead->value]);
});

it('R4: số điện thoại khách đã che, không email, không CCCD, không ghi chú khách; description_internal chỉ còn là cờ', function () {
    $client = Client::factory()->create([
        'name' => 'Bà Nguyễn Thị Khách',
        'phone' => '(+84) 912 345 678',
        'email' => 'khach-secret@example.test',
        'id_number' => '079188123456',
        'note' => 'SECRET-CLIENT-NOTE',
    ]);
    $matter = Matter::factory()->aiAccessAllowed()->create([
        'lead_lawyer_id' => $this->world->lead->id,
        'client_id' => $client->id,
        'description_internal' => 'SECRET-DESCRIPTION-INTERNAL',
        'summary_for_client' => 'SECRET-SUMMARY-DRAFT',
    ]);

    $response = McpToolCall::call($this, $this->token, 'get_matter', ['id' => McpIds::encode(McpIds::MATTER, $matter->id)]);
    $out = getMatter($this->token, $matter);

    expect($out['client'])->toBe([
        'name' => 'Bà Nguyễn Thị Khách',
        'type' => $client->type->value,
        'type_label' => $client->type->label(),
        'phone_masked' => '***678',
    ])
        ->and($out['has_internal_note'])->toBeTrue();

    foreach (['912 345 678', '912345678', 'khach-secret@example.test', '079188123456', 'SECRET-CLIENT-NOTE', 'SECRET-DESCRIPTION-INTERNAL', 'SECRET-SUMMARY-DRAFT'] as $secret) {
        expect($response->getContent())->not->toContain($secret);
    }

    // Cặp dương của cờ: không có ghi chú nội bộ thì cờ là false.
    $matter->forceFill(['description_internal' => null])->save();
    expect(getMatter($this->token, $matter)['has_internal_note'])->toBeFalse();
});

it('R10: bên không phải khách của văn phòng ra tên giả "Bị đơn 1, 2", khách của văn phòng ra tên thật; không số điện thoại, địa chỉ, ghi chú của bên nào', function () {
    $matter = $this->world->matter;
    MatterParty::factory()->create(['matter_id' => $matter->id, 'role' => PartyRole::Defendant, 'name' => 'Trần Bị Đơn Một', 'phone_normalized' => '84911000111', 'address' => 'SECRET-PARTY-ADDRESS', 'note' => 'SECRET-PARTY-NOTE']);
    MatterParty::factory()->create(['matter_id' => $matter->id, 'role' => PartyRole::Defendant, 'name' => 'Lê Bị Đơn Hai']);
    MatterParty::factory()->create(['matter_id' => $matter->id, 'role' => PartyRole::Plaintiff, 'name' => 'Nguyên Đơn Của Văn Phòng', 'is_our_client' => true]);

    $response = McpToolCall::call($this, $this->token, 'get_matter', ['id' => McpIds::encode(McpIds::MATTER, $matter->id)]);
    $parties = getMatter($this->token, $matter)['parties'];

    expect(array_column($parties, 'label'))->toBe([
        __('mcp.party_pseudonym', ['role' => PartyRole::Defendant->label(), 'number' => 1]),
        __('mcp.party_pseudonym', ['role' => PartyRole::Defendant->label(), 'number' => 2]),
        'Nguyên Đơn Của Văn Phòng',
    ])
        ->and(array_column($parties, 'is_pseudonym'))->toBe([true, true, false]);

    foreach (['Trần Bị Đơn Một', 'Lê Bị Đơn Hai', '84911000111', 'SECRET-PARTY-ADDRESS', 'SECRET-PARTY-NOTE'] as $secret) {
        expect($response->getContent())->not->toContain($secret);
    }
});

it('năm mốc chưa hoàn thành gần nhất, quá hạn lên đầu; mốc đã xong và mốc đã xoá không vào', function () {
    $matter = $this->world->matter;
    $lead = $this->world->lead;
    $make = fn (int $days, array $extra = []) => Deadline::factory()->create([
        'matter_id' => $matter->id, 'responsible_user_id' => $lead->id, 'due_date' => today()->addDays($days)->toDateString(), ...$extra,
    ]);

    $overdue = $make(-3, ['name' => 'Quá hạn', 'severity' => DeadlineSeverity::Critical]);
    $make(-1, ['name' => 'Đã xong', 'is_completed' => true, 'completed_at' => now()]);
    $deleted = $make(1, ['name' => 'Đã xoá']);
    $deleted->delete();
    $soon = collect([2, 4, 6, 8, 10])->map(fn (int $days) => $make($days, ['name' => "Mốc +{$days}"]));

    $next = getMatter($this->token, $matter)['next_deadlines'];

    expect(array_column($next, 'name'))->toBe(['Quá hạn', 'Mốc +2', 'Mốc +4', 'Mốc +6', 'Mốc +8'])
        ->and($next[0]['id'])->toBe(McpIds::encode(McpIds::DEADLINE, $overdue->id))
        ->and($next[0]['severity'])->toBe(DeadlineSeverity::Critical->value)
        ->and($next[0]['matter']['id'])->toBe(McpIds::encode(McpIds::MATTER, $matter->id))
        ->and($next[0]['responsible']['name'])->toBe('Luật sư Phụ Trách')
        ->and($next[0]['url'])->toBe(AdminUrls::deadline($overdue));
});

it('người phụ trách mốc đã nghỉ việc (xoá mềm) vẫn hiện tên — cùng mốc, cùng câu trả lời với list_deadlines (Task 11)', function () {
    $departed = User::factory()->create(['name' => 'Luật sư Đã Nghỉ']);
    Deadline::factory()->create(['matter_id' => $this->world->matter->id, 'responsible_user_id' => $departed->id, 'due_date' => today()->addDay()->toDateString()]);
    $departed->delete();

    expect(getMatter($this->token, $this->world->matter)['next_deadlines'][0]['responsible']['name'])->toBe('Luật sư Đã Nghỉ');
});

it('"Đã nộp X/Y" theo ChecklistProgress và số yêu cầu đang mở (chưa đóng, chưa rút)', function () {
    $matter = $this->world->matter;
    MatterChecklistItem::factory()->create(['matter_id' => $matter->id, 'is_required' => true, 'status' => ChecklistItemStatus::Accepted]);
    MatterChecklistItem::factory()->create(['matter_id' => $matter->id, 'is_required' => true, 'status' => ChecklistItemStatus::Missing]);
    MatterChecklistItem::factory()->create(['matter_id' => $matter->id, 'is_required' => false, 'status' => ChecklistItemStatus::NotApplicable]);

    ClientRequest::factory()->create(['matter_id' => $matter->id, 'status' => ClientRequestStatus::New]);
    ClientRequest::factory()->create(['matter_id' => $matter->id, 'status' => ClientRequestStatus::Answered]);
    ClientRequest::factory()->create(['matter_id' => $matter->id, 'status' => ClientRequestStatus::Closed]);
    ClientRequest::factory()->create(['matter_id' => $matter->id, 'status' => ClientRequestStatus::InProgress])->delete();

    $out = getMatter($this->token, $matter);

    expect($out['checklist_progress'])->toBe([
        'submitted' => 1,
        'total' => 2,
        'label' => __('mcp.get_matter.checklist_progress', ['submitted' => 1, 'total' => 2]),
    ])
        ->and($out['open_client_request_count'])->toBe(2);
});

it('R3: vụ đội khác, vụ hạn chế của chính mình, vụ denied và id không tồn tại cho CÙNG một phản hồi', function () {
    $call = fn (string $id) => McpToolCall::call($this, $this->token, 'get_matter', ['id' => $id])->json('result');

    $baseline = $call(McpIds::encode(McpIds::MATTER, 999999));

    expect($baseline['content'])->toBe([['type' => 'text', 'text' => __('mcp.tool_errors.not_found')]])
        ->and($baseline['isError'])->toBeTrue()
        ->and($baseline)->not->toHaveKey('structuredContent');

    foreach ($this->world->hiddenFromLead() as $case => $matter) {
        expect($call(McpIds::encode(McpIds::MATTER, $matter->id)))->toBe($baseline, $case);
    }

    // Id sai định dạng hay sai loại cũng không có thông điệp riêng (McpIds đọc ngược chặt).
    foreach (['matter_0', 'matter_01', 'Matter_'.$this->world->matter->id, 'request_'.$this->world->matter->id, (string) $this->world->matter->id, 'matter_'.$this->world->matter->id.' '] as $bad) {
        expect($call($bad))->toBe($baseline, $bad);
    }

    // Cặp dương: chính các vụ ấy mở được cho người được thấy chúng qua MCP.
    getMatter(McpOAuth::accessToken($this, $this->world->outsider), $this->world->otherTeam);
});

it('vụ đã xoá mềm: cùng phản hồi "Không tìm thấy"; cặp dương: khôi phục thì mở được', function () {
    $matter = $this->world->matter;
    $id = McpIds::encode(McpIds::MATTER, $matter->id);
    $matter->delete();

    expect(McpToolCall::error($this, $this->token, 'get_matter', ['id' => $id]))->toBe(__('mcp.tool_errors.not_found'));

    $matter->restore();

    expect(getMatter($this->token, $matter)['id'])->toBe($id);
});

it('thành viên đội (trợ lý) mở được; kế toán thì "Không tìm thấy"', function () {
    expect(getMatter(McpOAuth::accessToken($this, $this->world->assistant), $this->world->matter)['code'])->toBe($this->world->matter->code);

    expect(McpToolCall::error($this, McpOAuth::accessToken($this, $this->world->accountant), 'get_matter', [
        'id' => McpIds::encode(McpIds::MATTER, $this->world->matter->id),
    ]))->toBe(__('mcp.tool_errors.not_found'));
});

it('một phiên cổng khách đang mở trong cùng tiến trình không cắt khách hàng, các bên hay mốc của vụ', function () {
    $matter = $this->world->matter;
    MatterParty::factory()->create(['matter_id' => $matter->id, 'role' => PartyRole::Defendant]);
    Deadline::factory()->create(['matter_id' => $matter->id, 'responsible_user_id' => $this->world->lead->id, 'is_published' => false]);

    ClientRequest::factory()->create(['matter_id' => $matter->id, 'status' => ClientRequestStatus::New]);

    $this->actingAs(ClientUser::factory()->activated()->create(), 'client');

    $out = getMatter($this->token, $matter);

    expect($out['client'])->not->toBeNull()
        ->and($out['parties'])->toHaveCount(1)
        ->and($out['next_deadlines'])->toHaveCount(1)
        ->and($out['open_client_request_count'])->toBe(1);
});

it('kế thừa policy web: một điều kiện mới của Gate view trên vụ (mà bản SQL của McpMatterScope chưa có) cũng đóng tool, cùng phản hồi "Không tìm thấy"', function () {
    $matter = $this->world->matter;
    $id = McpIds::encode(McpIds::MATTER, $matter->id);

    // Cặp dương trước: không có điều kiện thêm thì mở được.
    expect(getMatter($this->token, $matter)['id'])->toBe($id);

    Gate::before(fn ($user, string $ability, array $arguments = []) => $ability === 'view'
        && ($arguments[0] ?? null) instanceof Matter
        && $arguments[0]->is($matter) ? false : null);

    expect(McpToolCall::error($this, $this->token, 'get_matter', ['id' => $id]))->toBe(__('mcp.tool_errors.not_found'));
});

it('id là tham số bắt buộc', function () {
    expect(McpToolCall::error($this, $this->token, 'get_matter'))->not->toBe(__('mcp.tool_errors.not_found'));
});
