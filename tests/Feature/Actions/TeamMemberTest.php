<?php

use App\Actions\Matter\AddTeamMember;
use App\Actions\Matter\RemoveTeamMember;
use App\Actions\OpenMatter;
use App\Enums\MatterRole;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Enums\UserPosition;
use App\Exceptions\TeamMemberHasOpenWork;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use App\Support\OpenWork;
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

/**
 * Fix round 1, finding I3 — "bấm hai lần" qua chính Action, không phải qua dữ liệu dựng sẵn bằng
 * `Matter::addTeamMember()` (test trên). Gọi `handle()` hai lần LIÊN TIẾP với CÙNG tham số: trước
 * `lockForUpdate()`, hai lần gọi rời (không khoá) vẫn cho ra `ValidationException` sạch trong một
 * tiến trình ĐƠN LUỒNG như test này — cái `lockForUpdate()` sửa là RACE giữa HAI KẾT NỐI DB đồng
 * thời (hai request thật), thứ SQLite trong bộ nhớ, một kết nối, không tái hiện được. Test này vì
 * vậy không tự nó chứng minh khoá có tác dụng dưới tải thật — chỉ xác nhận: SAU KHI bọc
 * `DB::transaction`/`lockForUpdate()`, luồng "gọi Action hai lần" (giả lập chính xác việc bấm hai
 * lần từ góc nhìn người dùng) vẫn cho lời từ chối tiếng Việt sạch, không phải một exception hạ
 * tầng. Ghi rõ giới hạn này trong báo cáo — khoá thật cần `bin/dev test:mariadb` với hai kết nối
 * song song, ngoài phạm vi round sửa này.
 */
it('turns a duplicate add through the action itself into a clean refusal, not a database error', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();

    app(AddTeamMember::class)->handle($this->matter, $this->lead, $assistant, MatterRole::Assistant);

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
    $anotherAdmin = User::factory()->withRole(Role::Admin)->create();

    expect(fn () => app(AddTeamMember::class)->handle($restricted, $manager, $anotherAdmin, MatterRole::Observer))
        ->toThrow(AuthorizationException::class);

    // Người được THÊM ở đây phải là admin (xem finding I1 dưới đây): trên vụ restricted, chỉ
    // admin mới qua được `Gate::forUser($member)->allows('view', $restricted)` sau khi thêm.
    app(AddTeamMember::class)->handle($restricted, $this->lead, $anotherAdmin, MatterRole::Observer);
    app(AddTeamMember::class)->handle($restricted, $admin, User::factory()->withRole(Role::Admin)->create(), MatterRole::Observer);

    expect($restricted->team()->count())->toBe(3); // lead + 2
});

/**
 * Fix round 1, finding I1 (controller ruling) — `AddTeamMember` phải từ chối bất kỳ ai sẽ KHÔNG
 * qua được `Gate::forUser($member)->allows('view', $matter)` sau khi được thêm. Trên vụ
 * `restricted`, `Matter::isListableBy()` không đọc `team()` chút nào cho nhánh này (chỉ
 * `hasRole(Admin)` hoặc chính `lead_lawyer_id`) — một trợ lý ĐỦ ĐIỀU KIỆN theo vai (assistant→
 * `assistant`, qua được `eligibleForRole()`) vẫn không bao giờ thấy được vụ này, nên bị từ chối.
 *
 * Khẳng định ROLLBACK: dòng `matter_user` vừa `attach()` bên trong transaction phải biến mất
 * hoàn toàn, không để lại một "thành viên ma" không thấy được vụ việc của chính mình.
 */
it('refuses to add a member who would not see a restricted matter once added, and rolls back the attach', function () {
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);
    $newHire = User::factory()->withRole(Role::Assistant)->create();

    try {
        app(AddTeamMember::class)->handle($restricted, $this->lead, $newHire, MatterRole::Assistant);
        test()->fail('Đáng lẽ phải ném ValidationException');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('user_id');
    }

    expect($restricted->team()->whereKey($newHire->id)->exists())->toBeFalse()
        ->and($restricted->team()->count())->toBe(1); // chỉ còn lead — không có "thành viên ma"
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
 * Fix round 1, finding I2 — một mốc hạn CHƯA XONG thuộc một vụ việc ĐÃ ĐÓNG không còn gì "dở
 * dang" thật sự (R8: vụ đóng nghĩa là xong việc). Trước bản sửa này, `OpenWork` chỉ lọc
 * `matter_id`, không hỏi gì về trạng thái vụ việc đứng sau mốc hạn đó.
 */
it('does not block removal on an incomplete deadline whose own matter has been closed', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);
    Deadline::factory()->for($this->matter)->create([
        'responsible_user_id' => $assistant->id,
        'is_completed' => false,
    ]);

    $this->matter->update(['closed_at' => today()]);

    app(RemoveTeamMember::class)->handle($this->matter, $this->lead, $assistant);

    expect($this->matter->team()->whereKey($assistant->id)->exists())->toBeFalse();
});

/** Cùng lý lẽ trên, áp cho yêu cầu khách chưa đóng của một vụ việc đã đóng. */
it('does not block removal on an unanswered client request whose own matter has been closed', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);
    ClientRequest::factory()->for($this->matter)->create([
        'assigned_to' => $assistant->id,
        'status' => 'in_progress',
    ]);

    $this->matter->update(['closed_at' => today()]);

    app(RemoveTeamMember::class)->handle($this->matter, $this->lead, $assistant);

    expect($this->matter->team()->whereKey($assistant->id)->exists())->toBeFalse();
});

/**
 * Fix round 1, finding I2 — cùng luật trên, cho vụ việc ĐÃ XOÁ MỀM. Không thể tự dựng qua
 * `RemoveTeamMember($this->matter, ...)` (xoá mềm CHÍNH vụ việc đang thao tác sẽ bị
 * `MatterPolicy::manageTeam()`'s `! $matter->trashed()` chặn từ TRƯỚC, ném `AuthorizationException`
 * chứ không phải câu hỏi I2 đang kiểm) — gọi thẳng `OpenWork::forUser()` ở chế độ TOÀN BỘ vụ việc
 * (`$matter = null`, đúng hình dạng Task 4 sẽ dùng) trên một vụ KHÁC đã bị xoá mềm.
 */
it('does not count a deadline whose matter has been soft-deleted, in the whole-account OpenWork query', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $otherMatter = Matter::factory()->create();
    Deadline::factory()->for($otherMatter)->create([
        'responsible_user_id' => $assistant->id,
        'is_completed' => false,
    ]);
    $otherMatter->delete();

    $result = OpenWork::forUser($assistant);

    expect($result->deadlines)->toBeEmpty();
});

/** Cùng lý lẽ trên, áp cho yêu cầu khách của một vụ việc đã xoá mềm. */
it('does not count a client request whose matter has been soft-deleted, in the whole-account OpenWork query', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $otherMatter = Matter::factory()->create();
    ClientRequest::factory()->for($otherMatter)->create([
        'assigned_to' => $assistant->id,
        'status' => 'in_progress',
    ]);
    $otherMatter->delete();

    $result = OpenWork::forUser($assistant);

    expect($result->clientRequests)->toBeEmpty();
});

/**
 * Fix round 1, finding S3 — vai `lead` KHÔNG BAO GIỜ gỡ được qua `RemoveTeamMember`, dù vụ việc
 * đang mở hay đã đóng. Bản gốc của Task 3 chỉ chặn qua `OpenWork::leadMatters` (chỉ thấy vụ ĐANG
 * MỞ) — SAI, vì nó để lọt trường hợp gỡ lead của một vụ ĐÃ ĐÓNG (xem test kế: bản gốc GHIM hành
 * vi đó là "allows removing the lead once the matter is closed"). R6 nói "vai `lead` CHỈ ĐỔI qua
 * `ReassignMatter`" — không có ngoại lệ theo trạng thái vụ việc.
 */
it('refuses to remove the lead of a still-open matter', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();

    try {
        app(RemoveTeamMember::class)->handle($this->matter, $manager, $this->lead);
        test()->fail('Đáng lẽ phải ném ValidationException');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('user_id');
    }

    expect($this->matter->team()->whereKey($this->lead->id)->exists())->toBeTrue();
});

/**
 * Fix round 1, finding S3 — thay thế TRỰC TIẾP test cũ "allows removing the lead once the matter
 * is closed", vốn GHIM một hành vi sai (gỡ trắng lead của một vụ đã đóng, không qua
 * `ReassignMatter`, xem docblock lớp Action). Vụ ĐÃ ĐÓNG vẫn từ chối y hệt vụ đang mở — nhánh
 * `role_in_matter === Lead` đứng TRƯỚC `OpenWork` nên không đọc `closed_at` chút nào.
 */
it('refuses to remove the lead even once the matter is closed', function () {
    $this->matter->update(['closed_at' => today()]);
    $manager = User::factory()->withRole(Role::Manager)->create();

    expect(fn () => app(RemoveTeamMember::class)->handle($this->matter, $manager, $this->lead))
        ->toThrow(ValidationException::class);

    expect($this->matter->team()->whereKey($this->lead->id)->exists())->toBeTrue();
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

/**
 * Fix round 1, finding S2 (R5): "quản lý đội ngũ đòi `matter.update` VÀ không phải trợ lý." Bản
 * gốc chỉ hỏi `lead_lawyer_id === $user->getKey()` — một luật sư phụ trách bị ĐỔI CHỨC DANH sang
 * trợ lý (`EditUser` → `assignRoleFromPosition()`, cột `matters.lead_lawyer_id` không tự đổi
 * theo) vẫn còn "là lead" trên vụ việc CŨ và lọt qua nhánh đó — trong khi vai `Assistant` CÓ
 * `matter.update` (`Role::Assistant->permissions()`), nên thiếu điều kiện `! hasRole(Assistant)`
 * để chặn đúng người này.
 */
it('grants manageTeam to a lead who is a lawyer, and denies it to the same person once demoted to assistant', function () {
    expect($this->lead->can('manageTeam', $this->matter))->toBeTrue();

    $this->lead->update(['position' => UserPosition::Assistant]);
    $this->lead->assignRoleFromPosition();

    expect($this->lead->fresh()->can('manageTeam', $this->matter))->toBeFalse();
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
