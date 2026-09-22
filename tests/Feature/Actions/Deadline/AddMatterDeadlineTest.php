<?php

use App\Actions\Deadline\AddMatterDeadline;
use App\Enums\Confidentiality;
use App\Enums\DeadlineSeverity;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Exceptions\MatterNotPublishedToPortal;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * "Thêm nhanh" một mốc thời hạn (SPEC §4.13, §7.2) — nửa NGHIỆP VỤ của màn hình M6 Task 5.
 *
 * Bảng `deadlines` có từ M1 và cho tới task này đường duy nhất ghi vào nó là `MatterSeeder`, tức
 * dữ liệu mẫu — không một màn hình nào. Mọi thứ đọc nó (cổng khách từ M5, `CheckDeadlines` của
 * Task 6) vì thế đọc một cái bảng CÓ dữ liệu trên máy lập trình viên và RỖNG ở văn phòng.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Vũ Khang']);
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
});

/**
 * **Người THÊM mốc cố ý không phải luật sư phụ trách, và đó là điều kiện để test này nói được
 * gì.** Bản đầu để trợ lý và luật sư phụ trách là một người: đo bằng mutation
 * (`$responsible ??= $actor` thay cho `$responsible ??= $fresh->leadLawyer`) thì test vẫn XANH —
 * hai cài đặt khác hẳn nhau cho ra cùng đáp án, đúng hình dạng "fixture rỗng" mà M5 đã tìm thấy
 * sáu cái. Giờ trợ lý là người gõ, luật sư phụ trách là người nhận, và chỉ một trong hai cài đặt
 * cho ra đáp án đúng.
 */
it('adds a deadline and defaults the responsible person to the matter lead lawyer', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Mai']);
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    $deadline = app(AddMatterDeadline::class)->handle(
        matter: $this->matter,
        actor: $assistant,
        name: 'Nộp đơn kháng cáo',
        dueDate: today()->addDays(9)->toDateString(),
    );

    expect($deadline->name)->toBe('Nộp đơn kháng cáo')
        ->and($deadline->matter_id)->toBe($this->matter->id)
        ->and($deadline->responsible_user_id)->toBe($this->lawyer->id)
        ->and($deadline->responsible_user_id)->not->toBe($assistant->id)
        ->and($deadline->severity)->toBe(DeadlineSeverity::Normal)
        ->and($deadline->is_published)->toBeFalse()
        ->and($deadline->is_completed)->toBeFalse()
        ->and($deadline->due_date->toDateString())->toBe(today()->addDays(9)->toDateString());
});

it('accepts an explicit severity and responsible person from the matter team', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    $deadline = app(AddMatterDeadline::class)->handle(
        matter: $this->matter,
        actor: $this->lawyer,
        name: 'Hạn kháng nghị giám đốc thẩm',
        dueDate: today()->addDays(3)->toDateString(),
        severity: DeadlineSeverity::Critical,
        responsible: $assistant,
    );

    expect($deadline->severity)->toBe(DeadlineSeverity::Critical)
        ->and($deadline->responsible_user_id)->toBe($assistant->id);
});

/**
 * Cùng khẳng định `SetMatterPortalPublicationTest` đặt ra cho `updated_by`: phiên và actor CỐ Ý
 * là hai người khác nhau, nếu không một cài đặt đọc phiên và một cài đặt đọc tham số cho ra cùng
 * đáp án và test không phân biệt được gì.
 */
it('writes created_by from the actor passed in, not from the user in the session', function () {
    $someoneElse = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($someoneElse, 'web');

    $deadline = app(AddMatterDeadline::class)->handle(
        matter: $this->matter,
        actor: $this->lawyer,
        name: 'Nộp bản tự khai',
        dueDate: today()->addDays(5)->toDateString(),
    );

    expect($deadline->fresh()->created_by)->toBe($this->lawyer->id);
});

it('records an audit row naming the actor and the severity', function () {
    app(AddMatterDeadline::class)->handle(
        matter: $this->matter,
        actor: $this->lawyer,
        name: 'Hạn nộp án phí',
        dueDate: today()->addDays(2)->toDateString(),
        severity: DeadlineSeverity::Critical,
    );

    $activity = Activity::query()->where('event', 'deadline_added')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer?->is($this->lawyer))->toBeTrue()
        ->and($activity->properties->get('severity'))->toBe(DeadlineSeverity::Critical->value)
        ->and($activity->properties->get('matter_id'))->toBe($this->matter->id);
});

/**
 * Kế toán có `matter.viewAny` nhưng không có `matter.update` (SPEC §5): họ không đặt được một mốc
 * tố tụng lên hồ sơ của người khác.
 */
it('refuses an actor who cannot write to the matter', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    expect(fn () => app(AddMatterDeadline::class)->handle(
        matter: $this->matter,
        actor: $accountant,
        name: 'Hạn nộp án phí',
        dueDate: today()->addDays(2)->toDateString(),
    ))->toThrow(AuthorizationException::class);

    expect($this->matter->deadlines()->count())->toBe(0);
});

it('refuses an actor whose account has been deactivated', function () {
    $this->lawyer->update(['is_active' => false]);

    expect(fn () => app(AddMatterDeadline::class)->handle(
        matter: $this->matter,
        actor: $this->lawyer,
        name: 'Hạn nộp án phí',
        dueDate: today()->addDays(2)->toDateString(),
    ))->toThrow(AuthorizationException::class);
});

/**
 * Cùng cổng mà `TriageClientRequest::canHoldTheThread()` dựng cho ô "giao việc": giao một mốc tố
 * tụng cho người không mở được hồ sơ là đẩy nó vào một hàng đợi không ai nhìn thấy — và với một
 * vụ `restricted` (SPEC §4.6) còn là một cách rò rỉ tên hồ sơ qua một ô chọn.
 */
it('refuses a responsible person who cannot open the matter', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->update(['confidentiality' => Confidentiality::Restricted]);

    expect(fn () => app(AddMatterDeadline::class)->handle(
        matter: $this->matter,
        actor: $this->lawyer,
        name: 'Hạn kháng cáo',
        dueDate: today()->addDays(2)->toDateString(),
        responsible: $outsider,
    ))->toThrow(ValidationException::class);

    expect($this->matter->deadlines()->count())->toBe(0);
});

it('refuses a responsible person whose account has been deactivated', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create(['is_active' => false]);
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    expect(fn () => app(AddMatterDeadline::class)->handle(
        matter: $this->matter,
        actor: $this->lawyer,
        name: 'Hạn kháng cáo',
        dueDate: today()->addDays(2)->toDateString(),
        responsible: $assistant,
    ))->toThrow(ValidationException::class);
});

it('refuses a blank name', function () {
    expect(fn () => app(AddMatterDeadline::class)->handle(
        matter: $this->matter,
        actor: $this->lawyer,
        name: '   ',
        dueDate: today()->addDays(2)->toDateString(),
    ))->toThrow(ValidationException::class);
});

/**
 * Cùng luật `TransitionMatterStage` giữ cho `stage_logs`: một dòng `is_published = true` trên một
 * vụ việc chưa bật portal nằm chờ im lặng rồi lộ ra NGUYÊN backlog vào khoảnh khắc ai đó bật công
 * tắc. Chặn từ gốc, không chỉ chặn tác dụng phụ.
 */
it('refuses to publish a deadline on a matter that is not on the portal', function () {
    $this->matter->update(['is_published_to_portal' => false]);

    expect(fn () => app(AddMatterDeadline::class)->handle(
        matter: $this->matter,
        actor: $this->lawyer,
        name: 'Phiên hoà giải',
        dueDate: today()->addDays(4)->toDateString(),
        isPublished: true,
    ))->toThrow(MatterNotPublishedToPortal::class);

    expect($this->matter->deadlines()->count())->toBe(0);
});

it('publishes a deadline when the matter is already on the portal', function () {
    $this->matter->update(['is_published_to_portal' => true]);

    $deadline = app(AddMatterDeadline::class)->handle(
        matter: $this->matter,
        actor: $this->lawyer,
        name: 'Phiên hoà giải',
        dueDate: today()->addDays(4)->toDateString(),
        isPublished: true,
    );

    expect($deadline->is_published)->toBeTrue();
});

it('refuses to add a deadline to a soft deleted matter', function () {
    $this->matter->delete();

    expect(fn () => app(AddMatterDeadline::class)->handle(
        matter: $this->matter,
        actor: $this->lawyer,
        name: 'Hạn nộp án phí',
        dueDate: today()->addDays(2)->toDateString(),
    ))->toThrow(AuthorizationException::class);
});
