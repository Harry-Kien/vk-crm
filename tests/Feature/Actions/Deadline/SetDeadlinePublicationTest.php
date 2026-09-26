<?php

use App\Actions\Deadline\SetDeadlinePublication;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Exceptions\MatterNotPublishedToPortal;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

/**
 * Công tắc "công bố cho khách" của một mốc thời hạn — cột `deadlines.is_published` mà cổng khách
 * đọc từ M5 (SPEC §8.3 khối 6) và cho tới task này chưa có một màn hình nào bật được.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->create([
        'lead_lawyer_id' => $this->lawyer->id,
        'is_published_to_portal' => true,
    ]);
    $this->deadline = Deadline::factory()->for($this->matter)->create([
        'name' => 'Phiên hoà giải',
        'responsible_user_id' => $this->lawyer->id,
    ]);
});

it('publishes a deadline to the client and records who did it', function () {
    $result = app(SetDeadlinePublication::class)->handle(
        deadline: $this->deadline,
        publish: true,
        actor: $this->lawyer,
    );

    $activity = Activity::query()->where('event', 'deadline_publication_set')->latest('id')->first();

    expect($result->is_published)->toBeTrue()
        ->and($this->deadline->fresh()->is_published)->toBeTrue()
        ->and($this->deadline->fresh()->updated_by)->toBe($this->lawyer->id)
        ->and($activity)->not->toBeNull()
        ->and($activity->causer?->is($this->lawyer))->toBeTrue()
        ->and($activity->properties->get('publish'))->toBeTrue();
});

/**
 * Vế thật của công tắc: sau khi bật, tài khoản portal của chính khách hàng ấy ĐỌC được mốc này —
 * và trước khi bật thì không. Đo bằng chính policy cổng khách (`DeadlinePolicy::view`), không
 * bằng một lời khẳng định về cột.
 */
it('is what decides whether the clients own portal account can read the deadline', function () {
    $clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->matter->client_id]);

    expect(Gate::forUser($clientUser)->allows('view', $this->deadline))->toBeFalse();

    app(SetDeadlinePublication::class)->handle($this->deadline, true, $this->lawyer);

    expect(Gate::forUser($clientUser)->allows('view', $this->deadline->fresh()))->toBeTrue();
});

it('takes a deadline back off the portal', function () {
    $this->deadline->update(['is_published' => true]);

    app(SetDeadlinePublication::class)->handle($this->deadline, false, $this->lawyer);

    expect($this->deadline->fresh()->is_published)->toBeFalse();
});

/**
 * Cùng luật `TransitionMatterStage` giữ cho `stage_logs`: một mốc `is_published = true` trên một
 * vụ việc chưa bật portal nằm chờ im lặng rồi lộ ra nguyên loạt vào khoảnh khắc ai đó bật công
 * tắc của vụ việc.
 */
it('refuses to publish a deadline on a matter that is not on the portal', function () {
    $this->matter->update(['is_published_to_portal' => false]);

    expect(fn () => app(SetDeadlinePublication::class)->handle($this->deadline, true, $this->lawyer))
        ->toThrow(MatterNotPublishedToPortal::class);

    expect($this->deadline->fresh()->is_published)->toBeFalse();
});

/**
 * Chiều GỠ thì không: một mốc đã trót công bố trên một vụ việc sau đó bị rút khỏi cổng vẫn phải
 * gỡ xuống được, nếu không cái cổng ở trên khoá luôn đường sửa sai.
 */
it('still lets a deadline be unpublished when the matter has left the portal', function () {
    $this->deadline->update(['is_published' => true]);
    $this->matter->update(['is_published_to_portal' => false]);

    app(SetDeadlinePublication::class)->handle($this->deadline, false, $this->lawyer);

    expect($this->deadline->fresh()->is_published)->toBeFalse();
});

it('is a no-op when the deadline is already in the state asked for', function () {
    app(SetDeadlinePublication::class)->handle($this->deadline, false, $this->lawyer);

    expect(Activity::query()->where('event', 'deadline_publication_set')->count())->toBe(0);
});

it('refuses an actor who cannot write to the matter', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    expect(fn () => app(SetDeadlinePublication::class)->handle($this->deadline, true, $accountant))
        ->toThrow(AuthorizationException::class);

    expect($this->deadline->fresh()->is_published)->toBeFalse();
});

/**
 * R5 (roles-05, M6.5 Task 10): công bố/gỡ một mốc hạn cho khách là cùng LOẠI quyết định với công
 * bố một dòng tiến độ (SetMatterPortalPublication) — trợ lý có `matter.update` nhưng không có
 * `stageLog.publish` nên không tự ý quyết định. Xem DeadlinePolicy::publish().
 */
it('refuses an assistant with matter.update but without stageLog.publish, on both directions', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    expect(fn () => app(SetDeadlinePublication::class)->handle($this->deadline, true, $assistant))
        ->toThrow(AuthorizationException::class);

    $this->deadline->update(['is_published' => true]);

    expect(fn () => app(SetDeadlinePublication::class)->handle($this->deadline->fresh(), false, $assistant))
        ->toThrow(AuthorizationException::class);

    expect($this->deadline->fresh()->is_published)->toBeTrue();
});

it('refuses an actor whose account has been deactivated', function () {
    $this->lawyer->update(['is_active' => false]);

    expect(fn () => app(SetDeadlinePublication::class)->handle($this->deadline, true, $this->lawyer))
        ->toThrow(AuthorizationException::class);
});
