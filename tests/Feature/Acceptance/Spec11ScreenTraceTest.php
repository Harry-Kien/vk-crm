<?php

use App\Enums\ChecklistItemStatus;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\Pages\ListMatters;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\ChecklistRelationManager;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Spatie\Activitylog\Models\Activity;

/*
 * Nghiệm thu bản 1.0 (v1 Task 2, M8 Task 8) — bảng truy vết SPEC §11 → test. Ba gạch đầu dòng của
 * §11 được khẳng định đủ ở tầng policy hoặc Action (`MatterPolicyTest`, `ReviewChecklistItemTest`) nhưng
 * ở màn hình chỉ từng mảnh (một vai, một tab, lý do 19 ký tự); tệp này khẳng định trọn từng gạch ở MÀN
 * HÌNH người dùng thật đi qua: trang xem vụ việc của `/admin` (HTTP) và nút "Từ chối" trên tab Hồ sơ giấy
 * tờ (Livewire). Mỗi khẳng định âm đi kèm vế dương của nó.
 *
 * Hàm toàn cục mang tiền tố `s11…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

function s11ViewUrl(Matter $matter): string
{
    return MatterResource::getUrl('view', ['record' => $matter], panel: 'admin');
}

/**
 * §11 "Quyền nội bộ": vụ `restricted` chỉ luật sư phụ trách và admin xem được. Trưởng phòng (người
 * xem được MỌI vụ thường), một trợ lý đứng tên trong `matter_user` của chính vụ đó (đứng tên trong đội
 * KHÔNG mở được vụ `restricted`), kế toán và một luật sư khác đều nhận cùng một 404, và trang không lộ
 * tiêu đề. Luật nằm ở hai lớp độc lập — `Matter::scopeListableBy()` và `Matter::isListableBy()` — và
 * mutation probe của nghiệm thu cho thấy mỗi lớp tự đủ chặn: bỏ một lớp, test vẫn xanh; bỏ cả hai, đỏ.
 */
it('§11 opens the page of a restricted matter for its lead lawyer and the admin only', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->restricted()->create([
        'lead_lawyer_id' => $lead->id,
        'title' => 'S11-TIEU-DE-MAT tranh chấp thừa kế',
    ]);
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter->team()->attach($assistant->id, ['role_in_matter' => 'assistant']);

    foreach ([$lead, User::factory()->withRole(Role::Admin)->create()] as $allowed) {
        $this->actingAs($allowed, 'web')->get(s11ViewUrl($matter))->assertOk()->assertSee('S11-TIEU-DE-MAT');
    }

    foreach ([
        User::factory()->withRole(Role::Manager)->create(),
        $assistant,
        User::factory()->withRole(Role::Accountant)->create(),
        User::factory()->withRole(Role::Lawyer)->create(),
    ] as $refused) {
        $this->actingAs($refused, 'web')->get(s11ViewUrl($matter))->assertNotFound()->assertDontSee('S11-TIEU-DE-MAT');
    }
});

/**
 * §11 "Quyền nội bộ": kế toán không xem được nội dung hồ sơ. Kế toán thấy vụ trong danh sách (để
 * làm việc tiền) nhưng không có cột tiêu đề, và trang xem vụ việc — nơi có tiến độ, giấy tờ, các
 * bên — trả 404. Vế dương: luật sư phụ trách mở được chính trang đó.
 */
it('§11 keeps the content of a matter from the accountant, who still finds it in the list', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id, 'title' => 'S11-NOI-DUNG-HO-SO']);
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->actingAs($accountant, 'web');

    $this->livewire(ListMatters::class)
        ->assertCanSeeTableRecords([$matter])
        ->assertTableColumnHidden('title')
        ->assertDontSee('S11-NOI-DUNG-HO-SO');

    $this->get(s11ViewUrl($matter))->assertNotFound()->assertDontSee('S11-NOI-DUNG-HO-SO');

    $this->actingAs($lead, 'web')->get(s11ViewUrl($matter))->assertOk()->assertSee('S11-NOI-DUNG-HO-SO');
});

/**
 * §11 "Nghiệp vụ": từ chối một đầu mục không kèm lý do → lỗi xác thực, đo trên chính nút "Từ chối"
 * của tab Hồ sơ giấy tờ. Ô để trống và ô chỉ có khoảng trắng: lỗi gắn vào ô lý do, đầu mục vẫn chờ
 * duyệt, không dòng nhật ký nào. Vế dương: một lý do thật thì từ chối được.
 */
it('§11 refuses to reject a checklist item without a reason on the documents tab, and changes nothing', function (?string $reason) {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);
    $item = MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::PendingReview)->create();

    $this->actingAs($lead, 'web');
    $tab = fn () => $this->livewire(ChecklistRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class]);

    $tab()->callAction(TestAction::make('reject')->table($item), data: ['rejection_reason' => $reason])
        ->assertHasActionErrors(['rejection_reason']);

    expect($item->refresh()->status)->toBe(ChecklistItemStatus::PendingReview)
        ->and($item->rejection_reason)->toBeNull()
        ->and(Activity::query()->where('event', 'checklist_item_reviewed')->count())->toBe(0);

    $tab()->callAction(TestAction::make('reject')->table($item), data: ['rejection_reason' => __('checklist.rejection_templates.blurred')])
        ->assertHasNoActionErrors();

    expect($item->refresh()->status)->toBe(ChecklistItemStatus::Rejected);
})->with([
    'ô trống' => [null],
    'chuỗi rỗng' => [''],
    'chỉ khoảng trắng' => ['     '],
]);
