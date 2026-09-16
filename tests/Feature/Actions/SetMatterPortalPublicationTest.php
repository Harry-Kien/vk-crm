<?php

use App\Actions\SetMatterPortalPublication;
use App\Enums\Role;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('turns portal publication on and records who did it', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'is_published_to_portal' => false]);

    $result = app(SetMatterPortalPublication::class)->handle(
        matter: $matter,
        publish: true,
        actor: $lawyer,
    );

    expect($result->is_published_to_portal)->toBeTrue()
        ->and($matter->fresh()->is_published_to_portal)->toBeTrue();

    $activity = Activity::query()->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->event)->toBe('matter_portal_publication_set')
        ->and($activity->causer?->is($lawyer))->toBeTrue()
        ->and($activity->properties->get('publish'))->toBeTrue();
});

/**
 * `Matter` dùng `HasBlameable`, và `HasBlameable::updating` ghi `updated_by` từ `auth('web')`
 * ambient. Action này đã nhận `$actor` tường minh để kiểm tra quyền, nên cột "ai sửa lần cuối"
 * phải là chính người đó — cùng lỗi mà bản xem xét trước xếp hạng Critical cho
 * `stage_logs.created_by`. Phiên và actor CỐ Ý là hai người khác nhau, nếu không thì một cài đặt
 * đọc phiên và một cài đặt đọc tham số cho ra cùng đáp án và test không phân biệt được gì.
 */
it('writes updated_by from the actor passed in, not from the user in the session', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $someoneElse = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($someoneElse, 'web');

    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'is_published_to_portal' => false]);

    app(SetMatterPortalPublication::class)->handle(matter: $matter, publish: true, actor: $lawyer);

    expect($matter->fresh()->updated_by)->toBe($lawyer->id);
});

it('turns portal publication off', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'is_published_to_portal' => true]);

    app(SetMatterPortalPublication::class)->handle(
        matter: $matter,
        publish: false,
        actor: $lawyer,
    );

    expect($matter->fresh()->is_published_to_portal)->toBeFalse();
});

/**
 * Số dòng `stage_logs.is_published = true` ghi vào audit properties là chỗ M6 sẽ đọc để cảnh báo
 * trước khi cho bật lại công bố (xem docblock Action — carry-forward M6). Test này khẳng định con
 * số đó đúng ngay từ M3, dù chưa có cảnh báo thật.
 */
it('records how many stage logs are already published, for the M6 re-exposure warning seam', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'is_published_to_portal' => false]);

    StageLog::factory()->published()->count(3)->create(['matter_id' => $matter->id]);
    StageLog::factory()->count(2)->create(['matter_id' => $matter->id, 'is_published' => false]);

    app(SetMatterPortalPublication::class)->handle(
        matter: $matter,
        publish: true,
        actor: $lawyer,
    );

    $activity = Activity::query()->latest('id')->first();

    expect($activity->properties->get('published_stage_log_count'))->toBe(3);
});

it('refuses someone without matter.update, regardless of caller-side checks', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $matter = Matter::factory()->create(['is_published_to_portal' => false]);

    expect(fn () => app(SetMatterPortalPublication::class)->handle(
        matter: $matter,
        publish: true,
        actor: $accountant,
    ))->toThrow(AuthorizationException::class);

    expect($matter->fresh()->is_published_to_portal)->toBeFalse();
});
