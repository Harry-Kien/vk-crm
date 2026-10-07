<?php

use App\Actions\Schedule\CheckDeadlines;
use App\Enums\CreatedVia;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\CommunicationLogsRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\DeadlinesRelationManager;
use App\Mail\Staff\DeadlineReminder;
use App\Models\CommunicationLog;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| M11 Task 12 — mốc và nhật ký liên lạc tạo qua AI, trên trang vụ việc
|--------------------------------------------------------------------------
|
| Mốc `created_via = mcp` mang nhãn "Tạo qua AI, chưa xác nhận" cho tới khi một người có quyền sửa
| mốc bấm "Xác nhận" (ghi `confirmed_at`/`confirmed_by`). Mốc đó vẫn được nhắc hạn như mốc thường
| (`CheckDeadlines`): một mốc tố tụng thật không được im lặng chỉ vì AI tạo. Nhật ký liên lạc
| `created_via = mcp` mang nhãn "Tạo qua AI" trên tab Liên lạc.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Vũ Khang']);
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
});

function aiTab(string $relationManager, Matter $matter): Testable
{
    return test()->livewire($relationManager, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ]);
}

function aiDeadline(Matter $matter, array $attributes = []): Deadline
{
    return Deadline::factory()->for($matter)->create([
        'responsible_user_id' => $matter->lead_lawyer_id,
        'name' => 'Hạn nộp bản tự khai',
        'due_date' => today()->addDays(10),
        'created_via' => CreatedVia::Mcp,
        ...$attributes,
    ]);
}

it('labels an AI-created deadline as not yet confirmed, and labels nothing on a deadline entered on the web', function () {
    $ai = aiDeadline($this->matter);
    $web = aiDeadline($this->matter, ['name' => 'Hạn nhập tay', 'created_via' => CreatedVia::Web]);

    $this->actingAs($this->lawyer, 'web');

    aiTab(DeadlinesRelationManager::class, $this->matter)
        ->assertTableColumnFormattedStateSet('created_via', __('ai_drafts.deadline.unconfirmed'), $ai)
        ->assertTableColumnFormattedStateNotSet('created_via', __('ai_drafts.deadline.unconfirmed'), $web)
        ->assertTableColumnFormattedStateNotSet('created_via', __('ai_drafts.deadline.confirmed'), $web)
        ->assertSee(__('ai_drafts.deadline.unconfirmed'));
});

it('confirms an AI-created deadline: records who and when, changes the label, and hides the button', function () {
    // Người phụ trách mốc KHÁC người bấm: "người xác nhận" là người bấm, không phải người giữ mốc.
    $associate = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->addTeamMember($associate, MatterRole::Associate);
    $deadline = aiDeadline($this->matter, ['responsible_user_id' => $associate->id]);
    $this->travelTo(now()->setDate(2026, 10, 7)->setTime(8, 15));

    $this->actingAs($this->lawyer, 'web');

    aiTab(DeadlinesRelationManager::class, $this->matter)
        ->assertTableActionVisible('confirmAi', $deadline)
        ->callTableAction('confirmAi', $deadline)
        ->assertHasNoTableActionErrors();

    $deadline->refresh();

    expect($deadline->confirmed_by)->toBe($this->lawyer->id)
        ->and($deadline->confirmed_at->format('Y-m-d H:i'))->toBe('2026-10-07 08:15')
        ->and($deadline->created_via)->toBe(CreatedVia::Mcp);

    $audit = Activity::query()->where('event', 'deadline_ai_confirmed')->sole();

    expect($audit->causer?->is($this->lawyer))->toBeTrue()
        ->and($audit->subject?->is($deadline))->toBeTrue()
        ->and($audit->properties->get('matter_id'))->toBe($this->matter->id);

    aiTab(DeadlinesRelationManager::class, $this->matter)
        ->assertTableActionHidden('confirmAi', $deadline)
        ->assertTableColumnFormattedStateSet('created_via', __('ai_drafts.deadline.confirmed'), $deadline);
});

it('offers no confirm button on a deadline entered on the web', function () {
    $web = aiDeadline($this->matter, ['created_via' => CreatedVia::Web]);

    $this->actingAs($this->lawyer, 'web');

    aiTab(DeadlinesRelationManager::class, $this->matter)->assertTableActionHidden('confirmAi', $web);
});

it('hides the confirm button from a person who cannot edit the deadline', function () {
    $deadline = aiDeadline($this->matter);
    $viewerOnly = User::factory()->create();
    $viewerOnly->givePermissionTo(Permission::MatterView->value);
    $this->matter->addTeamMember($viewerOnly, MatterRole::Observer);

    $this->actingAs($viewerOnly, 'web');

    aiTab(DeadlinesRelationManager::class, $this->matter)->assertTableActionHidden('confirmAi', $deadline);

    expect($deadline->refresh()->confirmed_at)->toBeNull();
});

it('reminds an AI-created deadline exactly like one entered on the web, confirmed or not', function () {
    Mail::fake();
    $ai = aiDeadline($this->matter, ['due_date' => today()->addDays(7)]);
    $web = aiDeadline($this->matter, ['due_date' => today()->addDays(7), 'created_via' => CreatedVia::Web]);

    $result = (new CheckDeadlines)->handle();

    expect($result['reminded'])->toBe(2)
        ->and($ai->refresh()->reminders_sent)->toContain('d7')
        ->and($web->refresh()->reminders_sent)->toContain('d7');
    Mail::assertSent(DeadlineReminder::class, 2);
});

it('labels an AI-created communication log on the communications tab, and not one logged on the web', function () {
    $ai = CommunicationLog::factory()->for($this->matter)->create(['summary' => 'Ghi qua AI']);
    $ai->forceFill(['created_via' => CreatedVia::Mcp])->save();
    $web = CommunicationLog::factory()->for($this->matter)->create(['summary' => 'Ghi trên web']);

    $this->actingAs($this->lawyer, 'web');

    aiTab(CommunicationLogsRelationManager::class, $this->matter)
        ->assertTableColumnFormattedStateSet('created_via', CreatedVia::Mcp->label(), $ai)
        ->assertTableColumnFormattedStateNotSet('created_via', CreatedVia::Mcp->label(), $web)
        ->assertSee(CreatedVia::Mcp->label());
});
