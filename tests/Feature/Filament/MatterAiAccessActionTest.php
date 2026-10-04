<?php

use App\Enums\MatterAiAccess;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use PHPUnit\Framework\ExpectationFailedException;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| M11 R9 (Task 7) — cờ "truy cập qua AI" của một vụ việc, trên tab Tổng quan
|--------------------------------------------------------------------------
|
| Luật Luật sư Điều 25: thông tin vụ việc chỉ được tiết lộ khi khách đồng ý BẰNG VĂN BẢN; Luật 91
| Điều 9: im lặng không phải đồng ý. Nên vụ việc chỉ lên MCP khi một người có `matter.update` bật cờ
| VÀ tự tích "Khách đã đồng ý bằng văn bản cho việc này" — ô tích không đánh dấu sẵn. Mỗi lần đổi
| ghi `matter_ai_access_changed` kèm người và thời điểm.
|
| Chiều TẮT không đòi ô tích: rút một vụ khỏi AI chỉ thu hẹp, không phải một lần tiết lộ mới, và
| không ai phải chờ một văn bản của khách để ngừng chia sẻ.
|
| Mọi test ở đây đi qua màn hình (Livewire, trang `ViewMatter`).
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

it('allows a matter for AI access when the box saying the client consented in writing is ticked, and records who and when', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    expect($matter->refresh()->ai_access)->toBe(MatterAiAccess::Denied);

    $this->actingAs($lawyer, 'web');

    $this->travelTo(now()->setDate(2026, 10, 3)->setTime(9, 30));

    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->assertActionVisible('setAiAccess')
        ->assertActionHasLabel('setAiAccess', __('matters.ai_access.allow'))
        ->callAction('setAiAccess', data: ['client_consented' => true])
        ->assertHasNoActionErrors();

    expect($matter->refresh()->ai_access)->toBe(MatterAiAccess::Allowed);

    $audits = Activity::query()->where('event', 'matter_ai_access_changed')->get();

    expect($audits)->toHaveCount(1);

    $audit = $audits->first();

    expect($audit->causer?->is($lawyer))->toBeTrue()
        ->and($audit->subject?->is($matter))->toBeTrue()
        ->and($audit->created_at->format('Y-m-d H:i'))->toBe('2026-10-03 09:30')
        ->and($audit->properties->get('from'))->toBe('denied')
        ->and($audit->properties->get('to'))->toBe('allowed')
        ->and($audit->properties->get('client_consent_confirmed'))->toBeTrue();
});

it('refuses to allow AI access while the consent box is left unticked, and changes nothing', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->callAction('setAiAccess', data: ['client_consented' => false])
        ->assertHasActionErrors(['client_consented' => 'accepted']);

    expect($matter->refresh()->ai_access)->toBe(MatterAiAccess::Denied)
        ->and(Activity::query()->where('event', 'matter_ai_access_changed')->exists())->toBeFalse();
});

it('does not tick the consent box in advance', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->mountAction('setAiAccess')
        ->assertActionDataSet(['client_consented' => false])
        ->callMountedAction()
        ->assertHasActionErrors(['client_consented' => 'accepted']);

    expect($matter->refresh()->ai_access)->toBe(MatterAiAccess::Denied);
});

it('turns AI access off without asking for the consent box, and records it', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->aiAccessAllowed()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->assertActionHasLabel('setAiAccess', __('matters.ai_access.deny'))
        ->callAction('setAiAccess')
        ->assertHasNoActionErrors()
        ->assertActionHasLabel('setAiAccess', __('matters.ai_access.allow'));

    expect($matter->refresh()->ai_access)->toBe(MatterAiAccess::Denied);

    $audit = Activity::query()->where('event', 'matter_ai_access_changed')->sole();

    expect($audit->causer?->is($lawyer))->toBeTrue()
        ->and($audit->properties->get('from'))->toBe('allowed')
        ->and($audit->properties->get('to'))->toBe('denied')
        ->and($audit->properties->get('client_consent_confirmed'))->toBeFalse();
});

it('shows the new state on the button right after allowing', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->callAction('setAiAccess', data: ['client_consented' => true])
        ->assertActionHasLabel('setAiAccess', __('matters.ai_access.deny'));
});

/**
 * Mọi vai trò có `matter.view` trong bộ quyền mẫu đều có sẵn `matter.update` (SPEC §5), nên nhân
 * chứng được cấp quyền thẳng tay — chỉ `matter.view` — cùng cách `AddChecklistItemTest` làm.
 */
it('hides the button from someone who can open the matter but lacks matter.update, and blocks a forced call', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $viewerOnly = User::factory()->create();
    $viewerOnly->givePermissionTo(Permission::MatterView->value);
    $matter->addTeamMember($viewerOnly, MatterRole::Observer);

    $this->actingAs($viewerOnly, 'web');

    $page = $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->assertActionHidden('setAiAccess');

    expect(fn () => $page->callAction('setAiAccess', data: ['client_consented' => true]))
        ->toThrow(ExpectationFailedException::class);

    expect($matter->refresh()->ai_access)->toBe(MatterAiAccess::Denied)
        ->and(Activity::query()->where('event', 'matter_ai_access_changed')->exists())->toBeFalse();
});

/** Cặp dương của test trên: CHỈ thêm `matter.update` là nút hiện và bấm được. */
it('shows the button to the same kind of team member once they hold matter.update', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $member = User::factory()->create();
    $member->givePermissionTo([Permission::MatterView->value, Permission::MatterUpdate->value]);
    $matter->addTeamMember($member, MatterRole::Observer);

    $this->actingAs($member, 'web');

    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->assertActionVisible('setAiAccess')
        ->callAction('setAiAccess', data: ['client_consented' => true]);

    expect($matter->refresh()->ai_access)->toBe(MatterAiAccess::Allowed);
});

/**
 * Chiều được CHỤP lúc mở hộp (cùng luật với công tắc công bố portal, final review C-M1): nếu người
 * khác đã đổi trong lúc hộp còn mở, không lật ngược lại.
 */
it('refuses to flip the AI switch the other way when someone else changed it while the dialog was open', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->mountAction('setAiAccess');

    Matter::query()->whereKey($matter->id)->update(['ai_access' => MatterAiAccess::Allowed->value]);

    $component->setActionData(['client_consented' => true])->callMountedAction();

    Notification::assertNotified(
        Notification::make()
            ->title(__('actions.failed_title'))
            ->body(__('matters.ai_access.changed'))
            ->danger()
            ->persistent()
    );

    expect($matter->refresh()->ai_access)->toBe(MatterAiAccess::Allowed)
        ->and(Activity::query()->where('event', 'matter_ai_access_changed')->exists())->toBeFalse();
});

it('shows the AI access state of the matter on the overview tab', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $denied = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $allowed = Matter::factory()->aiAccessAllowed()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(ViewMatter::class, ['record' => $denied->getKey()])
        ->assertSee(__('matters.overview_fields.ai_access'))
        ->assertSee(MatterAiAccess::Denied->label())
        ->assertDontSee(MatterAiAccess::Allowed->label());

    $this->livewire(ViewMatter::class, ['record' => $allowed->getKey()])
        ->assertSee(__('matters.overview_fields.ai_access'))
        ->assertSee(MatterAiAccess::Allowed->label());
});
