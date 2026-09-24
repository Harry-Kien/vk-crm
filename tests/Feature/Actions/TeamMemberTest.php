<?php

use App\Actions\Matter\AddTeamMember;
use App\Actions\Matter\RemoveTeamMember;
use App\Actions\OpenMatter;
use App\Enums\MatterRole;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Exceptions\TeamMemberHasOpenWork;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * `App\Actions\Matter\AddTeamMember` và `App\Actions\Matter\RemoveTeamMember` (M6.5 Task 3, R6) —
 * trước task này, chỗ DUY NHẤT ghi vào `matter_user` ngoài `Matter::created()` (chỉ thêm lead) là
 * `Matter::addTeamMember()`, và hàm đó chỉ được `MatterSeeder` và test gọi. Đây là test Ở TẦNG
 * ACTION (gọi thẳng `app(AddTeamMember::class)->handle(...)`, cùng quy ước mọi tệp khác trong
 * `tests/Feature/Actions/`) — test Ở TẦNG MÀN HÌNH (đi qua Livewire) nằm ở
 * `tests/Feature/Filament/TeamRelationManagerTest.php`, đúng yêu cầu của brief Task 3.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Vũ Khang']);
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
});

// =========================================================================================
// AddTeamMember — THÊM
// =========================================================================================

it('adds an eligible member and records the role on the audit trail', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Mai']);

    app(AddTeamMember::class)->handle($this->matter, $this->lead, $assistant, MatterRole::Assistant);

    expect($this->matter->team()->whereKey($assistant->id)->exists())->toBeTrue();

    $log = Activity::query()->where('event', 'team_member_added')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->subject_id)->toBe($this->matter->id)
        ->and($log->causer_id)->toBe($this->lead->id)
        ->and($log->properties['user_id'])->toBe($assistant->id)
        ->and($log->properties['role'])->toBe('assistant');
});

/**
 * `$lawyer` ở đây CỐ Ý là một luật sư — người mà `eligibleForRole()` sẽ chấp nhận cho vai
 * `associate`. Nếu test dùng một người không đủ điều kiện cho bất kỳ vai nào, việc xoá NHÁNH
 * `lead` riêng ở `handle()` vẫn bị `eligibleForRole()` (vốn cũng trả `false` cho `Lead`, phòng
 * thủ hai lớp) chặn lại, và test không phân biệt được hai lớp phòng thủ đó — mutation probe đã
 * đo đúng điều này (xoá nhánh `if ($role === MatterRole::Lead)` không làm test đỏ nếu chỉ kiểm
 * `ValidationException::class` chung chung). Kiểm khoá lỗi CỤ THỂ (`role_in_matter`, không phải
 * `user_id`) để probe phân biệt được hai lớp.
 */
it('refuses to add anyone with the lead role — that role only changes through reassignment', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    try {
        app(AddTeamMember::class)->handle($this->matter, $this->lead, $lawyer, MatterRole::Lead);
        test()->fail('Đáng lẽ phải ném ValidationException');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('role_in_matter');
    }

    expect($this->matter->team()->whereKey($lawyer->id)->exists())->toBeFalse();
});

/**
 * Ba nhánh của cùng một hàm, `AddTeamMember::eligibleForRole()`: trợ lý cho `assistant`; luật sư
 * hoặc manager cho `associate`; nhân sự nội bộ TRỪ kế toán cho `observer`. Test dựng đủ bốn chức
 * danh (Lawyer, Manager, Assistant, Accountant) và thử SAI vai cho từng người để đo cả ba nhánh
 * `match` cùng lúc, thay vì lặp lại một khuôn bốn lần.
 */
it('refuses a role the chosen person cannot hold', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    expect(fn () => app(AddTeamMember::class)->handle($this->matter, $this->lead, $assistant, MatterRole::Associate))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(AddTeamMember::class)->handle($this->matter, $this->lead, $accountant, MatterRole::Observer))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(AddTeamMember::class)->handle($this->matter, $this->lead, $accountant, MatterRole::Assistant))
        ->toThrow(ValidationException::class);

    expect($this->matter->team()->count())->toBe(1); // chỉ còn lead
});

it('accepts every role the brief names for the person holding it', function () {
    $associateLawyer = User::factory()->withRole(Role::Lawyer)->create();
    $associateManager = User::factory()->withRole(Role::Manager)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $observerAdmin = User::factory()->withRole(Role::Admin)->create();

    app(AddTeamMember::class)->handle($this->matter, $this->lead, $associateLawyer, MatterRole::Associate);
    app(AddTeamMember::class)->handle($this->matter, $this->lead, $associateManager, MatterRole::Associate);
    app(AddTeamMember::class)->handle($this->matter, $this->lead, $assistant, MatterRole::Assistant);
    app(AddTeamMember::class)->handle($this->matter, $this->lead, $observerAdmin, MatterRole::Observer);

    expect($this->matter->team()->count())->toBe(5); // lead + 4
});

it('refuses to add someone who is no longer active', function () {
    $retired = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);

    expect(fn () => app(AddTeamMember::class)->handle($this->matter, $this->lead, $retired, MatterRole::Associate))
        ->toThrow(ValidationException::class);

    expect($this->matter->team()->whereKey($retired->id)->exists())->toBeFalse();
});

/**
 * `ChecksAccountActive`: xoá mềm một tài khoản KHÔNG hạ cờ `is_active` — hai cột nói hai chuyện
 * khác nhau. Test riêng cho `trashed()`, khác test trên (chỉ đo `is_active`), để mutation probe
 * phân biệt được hai điều kiện của cùng một `if`.
 */
it('refuses to add someone who has been soft-deleted, even while still marked active', function () {
    $deleted = User::factory()->withRole(Role::Lawyer)->create();
    $deleted->delete();

    expect(fn () => app(AddTeamMember::class)->handle($this->matter, $this->lead, $deleted, MatterRole::Associate))
        ->toThrow(ValidationException::class);

    expect($this->matter->team()->whereKey($deleted->id)->exists())->toBeFalse();
});

it('refuses to add someone already on the team', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    expect(fn () => app(AddTeamMember::class)->handle($this->matter, $this->lead, $assistant, MatterRole::Assistant))
        ->toThrow(ValidationException::class);

    expect($this->matter->team()->whereKey($assistant->id)->count())->toBe(1);
});

it('refuses an actor who cannot manage the team — assistants included', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);
    $outsiderLawyer = User::factory()->withRole(Role::Lawyer)->create();
    $newHire = User::factory()->withRole(Role::Assistant)->create();

    expect(fn () => app(AddTeamMember::class)->handle($this->matter, $assistant, $newHire, MatterRole::Assistant))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(AddTeamMember::class)->handle($this->matter, $outsiderLawyer, $newHire, MatterRole::Assistant))
        ->toThrow(AuthorizationException::class);
});

it('lets the lead, a manager, and an admin manage the team of an ordinary matter', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();

    app(AddTeamMember::class)->handle($this->matter, $this->lead, User::factory()->withRole(Role::Assistant)->create(), MatterRole::Assistant);
    app(AddTeamMember::class)->handle($this->matter, $manager, User::factory()->withRole(Role::Assistant)->create(), MatterRole::Assistant);
    app(AddTeamMember::class)->handle($this->matter, $admin, User::factory()->withRole(Role::Assistant)->create(), MatterRole::Assistant);

    expect($this->matter->team()->count())->toBe(4); // lead + 3
});

/** Review Focus 1 (Task 3 brief): vụ `restricted` chỉ lead hoặc admin quản lý được đội ngũ. */
it('restricts team management on a restricted matter to the lead and the admin', function () {
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);
    $manager = User::factory()->withRole(Role::Manager)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $newHire = User::factory()->withRole(Role::Assistant)->create();

    expect(fn () => app(AddTeamMember::class)->handle($restricted, $manager, $newHire, MatterRole::Assistant))
        ->toThrow(AuthorizationException::class);

    app(AddTeamMember::class)->handle($restricted, $this->lead, $newHire, MatterRole::Assistant);
    app(AddTeamMember::class)->handle($restricted, $admin, User::factory()->withRole(Role::Assistant)->create(), MatterRole::Assistant);

    expect($restricted->team()->count())->toBe(3); // lead + 2
});

// =========================================================================================
// RemoveTeamMember — GỠ
// =========================================================================================

it('removes a member with no open work and records the role that was removed', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    app(RemoveTeamMember::class)->handle($this->matter, $this->lead, $assistant);

    expect($this->matter->team()->whereKey($assistant->id)->exists())->toBeFalse();

    $log = Activity::query()->where('event', 'team_member_removed')->latest('id')->first();

    expect($log->properties['user_id'])->toBe($assistant->id)
        ->and($log->properties['role'])->toBe('assistant');
});

it('refuses to remove someone who is not on the team', function () {
    $stranger = User::factory()->withRole(Role::Assistant)->create();

    expect(fn () => app(RemoveTeamMember::class)->handle($this->matter, $this->lead, $stranger))
        ->toThrow(ValidationException::class);
});

it('refuses to remove a member still holding an unfinished deadline, and lists it', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);
    Deadline::factory()->for($this->matter)->create([
        'name' => 'Nộp đơn kháng cáo',
        'responsible_user_id' => $assistant->id,
        'is_completed' => false,
    ]);

    try {
        app(RemoveTeamMember::class)->handle($this->matter, $this->lead, $assistant);
        test()->fail('Đáng lẽ phải ném TeamMemberHasOpenWork');
    } catch (TeamMemberHasOpenWork $exception) {
        expect($exception->getMessage())->toContain('Nộp đơn kháng cáo');
    }

    expect($this->matter->team()->whereKey($assistant->id)->exists())->toBeTrue();
});

it('does not block removal on a completed deadline', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);
    Deadline::factory()->for($this->matter)->create([
        'responsible_user_id' => $assistant->id,
        'is_completed' => true,
        'completed_at' => now(),
    ]);

    app(RemoveTeamMember::class)->handle($this->matter, $this->lead, $assistant);

    expect($this->matter->team()->whereKey($assistant->id)->exists())->toBeFalse();
});

it('refuses to remove a member still holding an unanswered client request, and lists it', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);
    ClientRequest::factory()->for($this->matter)->create([
        'subject' => 'Hỏi về lệ phí toà án',
        'assigned_to' => $assistant->id,
        'status' => 'in_progress',
    ]);

    try {
        app(RemoveTeamMember::class)->handle($this->matter, $this->lead, $assistant);
        test()->fail('Đáng lẽ phải ném TeamMemberHasOpenWork');
    } catch (TeamMemberHasOpenWork $exception) {
        expect($exception->getMessage())->toContain('Hỏi về lệ phí toà án');
    }

    expect($this->matter->team()->whereKey($assistant->id)->exists())->toBeTrue();
});

it('does not block removal on a closed client request', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);
    ClientRequest::factory()->for($this->matter)->create([
        'assigned_to' => $assistant->id,
        'status' => 'closed',
    ]);

    app(RemoveTeamMember::class)->handle($this->matter, $this->lead, $assistant);

    expect($this->matter->team()->whereKey($assistant->id)->exists())->toBeFalse();
});

/**
 * `OpenWork::forUser($member, $matter)` giới hạn cả ba loại việc vào ĐÚNG vụ việc đang gỡ —
 * mốc hạn/yêu cầu khách/vai lead của MỘT vụ việc KHÁC không được tính. Không có test này, ba
 * điều kiện `when($matter !== null, ...)` của `OpenWork::forUser()` có thể bị xoá mà không test
 * nào đỏ (mọi test khác của tệp này chỉ dựng dữ liệu trong ĐÚNG MỘT vụ việc).
 */
it('ignores open work that belongs to a different matter', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    $otherMatter = Matter::factory()->create(['lead_lawyer_id' => $assistant->id]);
    Deadline::factory()->for($otherMatter)->create([
        'responsible_user_id' => $assistant->id,
        'is_completed' => false,
    ]);
    ClientRequest::factory()->for($otherMatter)->create([
        'assigned_to' => $assistant->id,
        'status' => 'in_progress',
    ]);

    app(RemoveTeamMember::class)->handle($this->matter, $this->lead, $assistant);

    expect($this->matter->team()->whereKey($assistant->id)->exists())->toBeFalse();
});

/**
 * R6: vai `lead` chỉ đổi qua bàn giao vụ việc. `RemoveTeamMember` không có một nhánh
 * `role_in_matter === Lead` riêng — nó chặn qua chính `OpenWork::leadMatters` (đọc docblock lớp
 * Action), nên test này đo ĐÚNG con đường đó, không phải một cổng khác.
 */
it('refuses to remove the lead of a still-open matter', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();

    expect(fn () => app(RemoveTeamMember::class)->handle($this->matter, $manager, $this->lead))
        ->toThrow(TeamMemberHasOpenWork::class);

    expect($this->matter->team()->whereKey($this->lead->id)->exists())->toBeTrue();
});

/** Vụ đã ĐÓNG thì không còn gì để "bàn giao" — xem docblock lớp Action cho lý lẽ đầy đủ. */
it('allows removing the lead once the matter is closed', function () {
    $this->matter->update(['closed_at' => today()]);
    $manager = User::factory()->withRole(Role::Manager)->create();

    app(RemoveTeamMember::class)->handle($this->matter, $manager, $this->lead);

    expect($this->matter->team()->whereKey($this->lead->id)->exists())->toBeFalse();
});

it('refuses an actor who cannot manage the team', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);
    $outsiderLawyer = User::factory()->withRole(Role::Lawyer)->create();

    expect(fn () => app(RemoveTeamMember::class)->handle($this->matter, $outsiderLawyer, $assistant))
        ->toThrow(AuthorizationException::class);

    expect($this->matter->team()->whereKey($assistant->id)->exists())->toBeTrue();
});

// =========================================================================================
// MatterPolicy::manageTeam — trực tiếp, độc lập với Action
// =========================================================================================

it('grants manageTeam to the lead, any manager who can see the matter, and the admin', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    expect($this->lead->can('manageTeam', $this->matter))->toBeTrue()
        ->and($manager->can('manageTeam', $this->matter))->toBeTrue()
        ->and($admin->can('manageTeam', $this->matter))->toBeTrue()
        ->and($assistant->can('manageTeam', $this->matter))->toBeFalse();
});

/** Cùng thành ngữ `MatterPolicy::update()`/`transitionStage()`: vụ đã xoá mềm là chỉ-đọc, kể cả cho lead. */
it('denies manageTeam on a soft-deleted matter, even for the lead', function () {
    $this->matter->delete();

    expect($this->lead->can('manageTeam', $this->matter))->toBeFalse();
});

it('denies manageTeam on a restricted matter to a manager who is not its lead', function () {
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);
    $manager = User::factory()->withRole(Role::Manager)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();

    expect($manager->can('manageTeam', $restricted))->toBeFalse()
        ->and($this->lead->can('manageTeam', $restricted))->toBeTrue()
        ->and($admin->can('manageTeam', $restricted))->toBeTrue();
});

// =========================================================================================
// OpenMatter — người mở vụ được giữ lại (R6)
// =========================================================================================

/**
 * Finding `intake-01`/`roles-03`/`spec-gap-01`/`e2e-F4` (critical): trước Task 3, một luật sư mở
 * vụ việc cho một đồng nghiệp phụ trách bị `scopeListableBy()` bỏ rơi ngay sau khi lưu (không
 * `matter.viewAny`, không trong đội ngũ). `OpenMatter::handle()` bước 5 giờ tự thêm actor vào
 * đội ngũ với vai `associate` trong CÙNG transaction — xem docblock ở đó.
 */
it('keeps the opener on the team as an associate when they hand the matter to someone else', function () {
    $opener = User::factory()->withRole(Role::Lawyer)->create();
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $type = MatterType::factory()->withStages()->create();

    $result = app(OpenMatter::class)->handle(
        actor: $opener,
        attributes: [
            'client_id' => $client->id,
            'client_role' => PartyRole::Plaintiff,
            'matter_type_id' => $type->id,
            'title' => 'Tranh chấp hợp đồng thuê nhà',
            'summary_for_client' => 'Tóm tắt gửi khách hàng',
            'lead_lawyer_id' => $newLead->id,
            'is_published_to_portal' => false,
        ],
        parties: [],
    );

    $matter = $result->matter;

    expect($matter->lead_lawyer_id)->toBe($newLead->id)
        ->and($matter->team()->whereKey($newLead->id)->first()->pivot->role_in_matter)->toBe(MatterRole::Lead)
        ->and($matter->team()->whereKey($opener->id)->exists())->toBeTrue()
        ->and($matter->team()->whereKey($opener->id)->first()->pivot->role_in_matter)->toBe(MatterRole::Associate);

    $log = Activity::query()->where('event', 'team_member_added')
        ->where('properties->user_id', $opener->id)
        ->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->properties['auto_added_by_open_matter'] ?? null)->toBeTrue();
});

it('does not duplicate the lead into the team when the opener assigns themselves as lead', function () {
    $opener = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $type = MatterType::factory()->withStages()->create();

    $result = app(OpenMatter::class)->handle(
        actor: $opener,
        attributes: [
            'client_id' => $client->id,
            'client_role' => PartyRole::Plaintiff,
            'matter_type_id' => $type->id,
            'title' => 'Tranh chấp hợp đồng thuê nhà',
            'summary_for_client' => 'Tóm tắt gửi khách hàng',
            'lead_lawyer_id' => $opener->id,
            'is_published_to_portal' => false,
        ],
        parties: [],
    );

    expect($result->matter->team()->count())->toBe(1); // chỉ một dòng — lead, không thêm associate trùng người
});

/**
 * Một manager có `matter.viewAny` đã thấy MỌI vụ việc thường qua `scopeListableBy()` mà không
 * cần có tên trong `matter_user` — thêm họ vào đội ngũ sẽ là một dòng `team_member_added` không
 * cần thiết. Điều kiện `! $actor->can('matter.viewAny')` ở `OpenMatter` tồn tại chính vì ca này.
 */
it('does not add the opener to the team when they already have matter.viewAny', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $type = MatterType::factory()->withStages()->create();

    $result = app(OpenMatter::class)->handle(
        actor: $manager,
        attributes: [
            'client_id' => $client->id,
            'client_role' => PartyRole::Plaintiff,
            'matter_type_id' => $type->id,
            'title' => 'Tranh chấp hợp đồng thuê nhà',
            'summary_for_client' => 'Tóm tắt gửi khách hàng',
            'lead_lawyer_id' => $newLead->id,
            'is_published_to_portal' => false,
        ],
        parties: [],
    );

    expect($result->matter->team()->whereKey($manager->id)->exists())->toBeFalse()
        ->and($result->matter->team()->count())->toBe(1); // chỉ lead
});
