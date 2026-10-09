<?php

use App\Actions\Matter\SetMatterAiAccess;
use App\Enums\MatterAiAccess;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\MatterAiAccessChanged;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Test Ở TẦNG ACTION cho `App\Actions\Matter\SetMatterAiAccess` (M11 R9, Task 7). Hành vi màn hình
 * (nút, ô tích, nhãn, audit) đã đo qua Livewire ở `tests/Feature/Filament/MatterAiAccessActionTest.php`;
 * tệp này đo những lớp phòng thủ NẰM DƯỚI màn hình — thứ form của Filament che mất nên không test
 * Livewire nào chạm tới được: ô tích bị bỏ qua (payload giả), người không có `matter.update` gọi
 * thẳng, và người ghi `updated_by`. Mọi màn hình sau này đổi cờ cũng gọi đúng Action này. MCP thì
 * KHÔNG gọi nó: không tool nào đổi được cờ quyết định chính tool đó thấy gì (R5, R9).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
});

it('refuses to allow AI access without the client consent confirmation, even when no form stands in front of it', function () {
    expect(fn () => app(SetMatterAiAccess::class)->handle(
        matter: $this->matter,
        access: MatterAiAccess::Allowed,
        actor: $this->lawyer,
        clientConsented: false,
    ))->toThrow(ValidationException::class);

    expect($this->matter->refresh()->ai_access)->toBe(MatterAiAccess::Denied)
        ->and(Activity::query()->where('event', 'matter_ai_access_changed')->exists())->toBeFalse();
});

it('names the consent box in the refusal, in Vietnamese', function () {
    try {
        app(SetMatterAiAccess::class)->handle($this->matter, MatterAiAccess::Allowed, $this->lawyer, clientConsented: false);
        $this->fail('Action phải từ chối khi chưa xác nhận đồng ý.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['client_consented' => [__('matters.ai_access.consent_required')]]);
    }
});

it('allows AI access once the consent is confirmed', function () {
    $result = app(SetMatterAiAccess::class)->handle($this->matter, MatterAiAccess::Allowed, $this->lawyer, clientConsented: true);

    expect($result->ai_access)->toBe(MatterAiAccess::Allowed)
        ->and($this->matter->refresh()->ai_access)->toBe(MatterAiAccess::Allowed);
});

it('turns AI access off without any consent confirmation', function () {
    Matter::query()->whereKey($this->matter->id)->update(['ai_access' => MatterAiAccess::Allowed->value]);

    app(SetMatterAiAccess::class)->handle($this->matter, MatterAiAccess::Denied, $this->lawyer);

    expect($this->matter->refresh()->ai_access)->toBe(MatterAiAccess::Denied);
});

it('refuses someone who can see the matter but lacks matter.update', function () {
    $viewerOnly = User::factory()->create();
    $viewerOnly->givePermissionTo(Permission::MatterView->value);
    $this->matter->addTeamMember($viewerOnly, MatterRole::Observer);

    expect(fn () => app(SetMatterAiAccess::class)->handle($this->matter, MatterAiAccess::Allowed, $viewerOnly, clientConsented: true))
        ->toThrow(AuthorizationException::class);

    expect($this->matter->refresh()->ai_access)->toBe(MatterAiAccess::Denied)
        ->and(Activity::query()->where('event', 'matter_ai_access_changed')->exists())->toBeFalse();
});

it('refuses a lawyer who is not on the matter', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();

    expect(fn () => app(SetMatterAiAccess::class)->handle($this->matter, MatterAiAccess::Allowed, $outsider, clientConsented: true))
        ->toThrow(AuthorizationException::class);

    expect($this->matter->refresh()->ai_access)->toBe(MatterAiAccess::Denied);
});

it('refuses to write when the matter already holds the requested state, and records nothing', function () {
    Matter::query()->whereKey($this->matter->id)->update(['ai_access' => MatterAiAccess::Allowed->value]);

    expect(fn () => app(SetMatterAiAccess::class)->handle($this->matter, MatterAiAccess::Allowed, $this->lawyer, clientConsented: true))
        ->toThrow(MatterAiAccessChanged::class);

    expect(Activity::query()->where('event', 'matter_ai_access_changed')->exists())->toBeFalse();
});

/**
 * Phiên và actor CỐ Ý là hai người khác nhau (cùng lý do `SetMatterPortalPublicationTest`): một
 * cài đặt đọc phiên và một cài đặt đọc tham số cho cùng đáp án nếu hai người trùng nhau. Ở MCP sau
 * này thì không có phiên `web` nào cả.
 */
it('writes updated_by and the audit causer from the actor passed in, not from the session', function () {
    $someoneElse = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($someoneElse, 'web');

    app(SetMatterAiAccess::class)->handle($this->matter, MatterAiAccess::Allowed, $this->lawyer, clientConsented: true);

    $audit = Activity::query()->where('event', 'matter_ai_access_changed')->sole();

    expect($this->matter->refresh()->updated_by)->toBe($this->lawyer->id)
        ->and($audit->causer?->is($this->lawyer))->toBeTrue();
});

/**
 * Ngoài dòng có cấu trúc `matter_ai_access_changed`, cột nằm trong `logOnly` của `Matter`: lịch sử
 * chung của vụ (`created`/`updated`) nói cả giá trị lúc mở vụ lẫn mọi lần đổi — kể cả một lần đổi
 * KHÔNG đi qua Action này, thứ không có dòng có cấu trúc nào.
 */
it('keeps the AI flag in the generic history of the matter, from the day it opens', function () {
    $created = Activity::query()
        ->where('subject_type', 'matter')
        ->where('subject_id', $this->matter->id)
        ->where('event', 'created')
        ->sole();

    expect($created->properties->get('attributes')['ai_access'] ?? null)->toBe('denied');

    app(SetMatterAiAccess::class)->handle($this->matter, MatterAiAccess::Allowed, $this->lawyer, clientConsented: true);

    $updated = Activity::query()
        ->where('subject_type', 'matter')
        ->where('subject_id', $this->matter->id)
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect($updated?->properties->get('attributes')['ai_access'] ?? null)->toBe('allowed')
        ->and($updated?->properties->get('old')['ai_access'] ?? null)->toBe('denied');
});

it('records the audit without any session at all', function () {
    app(SetMatterAiAccess::class)->handle($this->matter, MatterAiAccess::Allowed, $this->lawyer, clientConsented: true);

    expect(Activity::query()->where('event', 'matter_ai_access_changed')->sole()->causer?->is($this->lawyer))->toBeTrue();
});
