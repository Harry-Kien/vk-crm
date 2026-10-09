<?php

use App\Enums\MatterAiAccess;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\CreateMatter;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| M11 R9 (Task 7) — vụ mới nhận cờ AI mặc định từ `.env` (`MCP_MATTER_DEFAULT`)
|--------------------------------------------------------------------------
|
| Mặc định của dự án là `denied`: dự án không tự quyết thay luật sư rằng khách đã đồng ý. Chủ văn
| phòng đổi được (câu hỏi mở 2 của kế hoạch). Giá trị đọc qua `config('vkcrm.mcp.matter_default')`
| ở MỘT chỗ — hook `creating` của `Matter` — nên vụ mở từ màn hình, từ Action hay từ factory đều đi
| qua cùng một luật. Hai giá trị đều được thử qua màn hình "Mở vụ việc".
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

/** Mở một vụ việc qua form "Mở vụ việc" (Livewire), cho một khách mà `$lawyer` thấy được. */
function openMatterThroughCreateScreen(User $lawyer, string $title): Matter
{
    $client = Client::factory()->create();
    $prior = Matter::factory()->create(['client_id' => $client->id, 'lead_lawyer_id' => $lawyer->id]);
    MatterParty::factory()->for($prior)->ourClient($client, PartyRole::Plaintiff)->create();

    $type = MatterType::factory()->withStages()->create();

    test()->actingAs($lawyer, 'web');

    test()->livewire(CreateMatter::class)
        ->fillForm([
            'client_id' => $client->id,
            'client_role' => PartyRole::Plaintiff->value,
            'matter_type_id' => $type->id,
            'title' => $title,
            'lead_lawyer_id' => $lawyer->id,
            'summary_for_client' => 'Tóm tắt gửi khách hàng.',
            'other_parties' => [],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    return Matter::query()->where('title', $title)->sole();
}

it('gives a matter opened on the create screen no AI access when MCP_MATTER_DEFAULT is denied', function () {
    config(['vkcrm.mcp.matter_default' => 'denied']);
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $matter = openMatterThroughCreateScreen($lawyer, 'Vụ mở khi mặc định là không cho phép');

    expect($matter->ai_access)->toBe(MatterAiAccess::Denied);
});

it('gives a matter opened on the create screen AI access when MCP_MATTER_DEFAULT is allowed', function () {
    config(['vkcrm.mcp.matter_default' => 'allowed']);
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $matter = openMatterThroughCreateScreen($lawyer, 'Vụ mở khi mặc định là cho phép');

    expect($matter->ai_access)->toBe(MatterAiAccess::Allowed);
});

it('falls back to no AI access when MCP_MATTER_DEFAULT holds anything else', function (mixed $value) {
    config(['vkcrm.mcp.matter_default' => $value]);

    expect(MatterAiAccess::defaultForNewMatter())->toBe(MatterAiAccess::Denied)
        ->and(Matter::factory()->create()->refresh()->ai_access)->toBe(MatterAiAccess::Denied);
})->with([
    'trống' => [''],
    'null' => [null],
    'chữ hoa' => ['ALLOWED'],
    'yes' => ['yes'],
    'true' => [true],
]);

it('reads the default from MCP_MATTER_DEFAULT and ships it as denied', function () {
    expect(config('vkcrm.mcp.matter_default'))->toBe('denied');

    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)->toMatch('/^MCP_MATTER_DEFAULT=denied$/m');
});

/**
 * Chỉ `SetMatterAiAccess` (kèm ô tích đồng ý và audit) được đổi cờ. Cột KHÔNG nằm trong
 * `$fillable`, nên một mảng thuộc tính đi qua `fill()` — form "Mở vụ việc" (`OpenMatter`), form
 * "Sửa vụ việc" (`UpdateMatterDetails`) hay một trường ai đó thêm vào sau này — không mở được vụ
 * cho AI mà không qua ô tích.
 */
it('never lets mass assignment set the AI flag', function () {
    config(['vkcrm.mcp.matter_default' => 'denied']);

    $template = Matter::factory()->make();
    $attributes = [...$template->getAttributes(), 'ai_access' => MatterAiAccess::Allowed->value];

    $matter = Matter::query()->create($attributes);

    expect($matter->refresh()->ai_access)->toBe(MatterAiAccess::Denied);

    $matter->update(['ai_access' => MatterAiAccess::Allowed->value]);

    expect($matter->refresh()->ai_access)->toBe(MatterAiAccess::Denied);
});

/** Dòng có sẵn trước migration (và mọi INSERT không nêu cột): `denied`, không phụ thuộc `.env`. */
it('stores denied for a matter row that does not name the column', function () {
    config(['vkcrm.mcp.matter_default' => 'allowed']);

    $template = Matter::factory()->create();
    $row = DB::table('matters')->where('id', $template->id)->first();

    $values = collect((array) $row)
        ->except(['id', 'ai_access'])
        ->merge(['code' => $row->code.'-RAW'])
        ->all();

    $id = DB::table('matters')->insertGetId($values);

    expect(DB::table('matters')->where('id', $id)->value('ai_access'))->toBe('denied');
});
