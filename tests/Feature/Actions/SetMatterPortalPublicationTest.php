<?php

use App\Actions\SetMatterPortalPublication;
use App\Enums\MatterRole;
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

/**
 * R5 (roles-05, M6.5 Task 10): trợ lý có `matter.update` (Role::Assistant->permissions()) nhưng
 * KHÔNG có `stageLog.publish` — bật/tắt công tắc công bố cả vụ việc cho khách là "quyết định đưa
 * gì ra cho khách", cùng loại quyết định với công bố một dòng tiến độ, nên đòi cùng quyền. Trước
 * bản sửa này, Action chỉ hỏi `matter.update`, nên trợ lý bật/tắt được công tắc — xem
 * MatterPolicy::setPortalPublication().
 */
it('refuses an assistant with matter.update but without stageLog.publish, on both directions', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matterOff = Matter::factory()->create(['is_published_to_portal' => false]);
    $matterOn = Matter::factory()->create(['is_published_to_portal' => true]);
    // Cần đứng trong đội ngũ để qua được `view()`/`update()` trước — nếu không việc bị từ chối là
    // vì KHÔNG THẤY vụ việc (chưa trong đội ngũ), không phải vì thiếu stageLog.publish, và test sẽ
    // xanh vì lý do sai.
    $matterOff->addTeamMember($assistant, MatterRole::Assistant);
    $matterOn->addTeamMember($assistant, MatterRole::Assistant);

    expect(fn () => app(SetMatterPortalPublication::class)->handle(
        matter: $matterOff,
        publish: true,
        actor: $assistant,
    ))->toThrow(AuthorizationException::class);

    expect(fn () => app(SetMatterPortalPublication::class)->handle(
        matter: $matterOn,
        publish: false,
        actor: $assistant,
    ))->toThrow(AuthorizationException::class);

    expect($matterOff->fresh()->is_published_to_portal)->toBeFalse()
        ->and($matterOn->fresh()->is_published_to_portal)->toBeTrue();
});

/**
 * I-1 (fix round 4). Bản sửa trước nhường cho giá trị gán tường minh bằng `isDirty('updated_by')`,
 * mà `isDirty` so với giá trị GỐC — nên khi cột đã sẵn mang đúng id của actor, phép gán không
 * "bẩn", cửa nhường không mở, và hook ghi đè lại bằng phiên ambient. Ba fixture của vòng trước đều
 * đặt giá trị lưu sẵn là MỘT NGƯỜI KHÁC actor, nên đúng trường hợp này nằm ngoài chúng — chính lời
 * phê bình mà bản xem xét trước đã dành cho fixture của vòng trước đó.
 *
 * Kịch bản tối thiểu để phân biệt: actor A, phiên B, và `matters.updated_by` ĐÃ là A.
 */
it('keeps updated_by on the actor even when the column already holds the actor id', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $someoneElse = User::factory()->withRole(Role::Admin)->create();

    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'is_published_to_portal' => false]);

    // Query builder, không `update()` của model: đặt giá trị GỐC mà không chạy qua HasBlameable.
    Matter::query()->whereKey($matter->id)->update(['updated_by' => $lawyer->id]);

    $this->actingAs($someoneElse, 'web');

    app(SetMatterPortalPublication::class)->handle(matter: $matter->fresh(), publish: true, actor: $lawyer);

    expect($matter->fresh()->updated_by)->toBe($lawyer->id);
});
