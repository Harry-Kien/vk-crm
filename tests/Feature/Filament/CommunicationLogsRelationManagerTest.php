<?php

use App\Enums\CommunicationType;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\CommunicationLogsRelationManager;
use App\Models\Client;
use App\Models\CommunicationLog;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

/**
 * Tab "Liên lạc" (SPEC §7.2, M7 Task 8) — màn hình đầu tiên ghi được một dòng `communication_logs`.
 *
 * SPEC đặt cho nó một ràng buộc THỜI GIAN: "ghi nhanh một cuộc gọi trong dưới 15 giây, vì nếu
 * mất lâu hơn thì không ai ghi". Tệp này đo hình dạng của ràng buộc đó (một lần chạm chọn kênh +
 * một ô nội dung là đủ, mọi thứ khác đã điền sẵn), và đo rằng nhật ký là BẰNG CHỨNG: không sửa,
 * xoá phải có lý do và để lại dấu vết, không có công tắc "khách thấy" giả.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Vũ Khang']);
    $this->client = Client::factory()->create(['name' => 'Nguyễn Thị Hoà']);
    $this->matter = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lawyer->id]);
});

function communicationsTab(Matter $matter)
{
    return test()->livewire(CommunicationLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ]);
}

// =========================================================================================
// GHI NHANH — một lần chạm + một ô
// =========================================================================================

it('logs a call from the channel and the summary alone, defaulting the time, the author and the counterpart', function () {
    Carbon::setTestNow('2026-10-02 09:41:00');
    $this->actingAs($this->lawyer, 'web');

    communicationsTab($this->matter)->callTableAction('create', data: [
        'type' => CommunicationType::CallIn->value,
        'summary' => 'Khách hỏi lịch phiên hoà giải.',
    ])->assertHasNoTableActionErrors();

    $log = CommunicationLog::query()->sole();

    expect($log->matter_id)->toBe($this->matter->id)
        ->and($log->type)->toBe(CommunicationType::CallIn)
        ->and($log->summary)->toBe('Khách hỏi lịch phiên hoà giải.')
        ->and($log->occurred_at->format('Y-m-d H:i'))->toBe('2026-10-02 09:41')
        ->and($log->counterpart)->toBe('Nguyễn Thị Hoà')
        ->and($log->created_by)->toBe($this->lawyer->id)
        ->and($log->duration_minutes)->toBeNull()
        ->and($log->is_visible_to_client)->toBeFalse();

    // Chỉ Action ghi dòng này — một `Model::create()` trong màn hình sẽ để khẳng định này đỏ.
    expect(Activity::query()->where('event', 'communication_logged')->count())->toBe(1);
});

it('keeps the time, the counterpart and the duration the lawyer typed', function () {
    $this->actingAs($this->lawyer, 'web');

    communicationsTab($this->matter)->callTableAction('create', data: [
        'type' => CommunicationType::CourtVisit->value,
        'summary' => 'Nộp đơn khởi kiện tại toà.',
        'occurred_at' => '2026-09-30 14:05:00',
        'counterpart' => 'Thư ký Toà án nhân dân quận Ba Đình',
        'duration_minutes' => 45,
    ])->assertHasNoTableActionErrors();

    $log = CommunicationLog::query()->sole();

    expect($log->occurred_at->format('Y-m-d H:i'))->toBe('2026-09-30 14:05')
        ->and($log->counterpart)->toBe('Thư ký Toà án nhân dân quận Ba Đình')
        ->and($log->duration_minutes)->toBe(45);
});

it('opens the form with the time and the client name filled in, and the channel left for one tap', function () {
    Carbon::setTestNow('2026-10-02 09:41:00');
    $this->actingAs($this->lawyer, 'web');

    $component = communicationsTab($this->matter)
        ->mountTableAction('create')
        ->assertTableActionDataSet([
            'counterpart' => 'Nguyễn Thị Hoà',
            'type' => null,
            'summary' => null,
        ]);

    $state = $component->instance()->getMountedTableActionForm()?->getRawState() ?? [];

    expect((string) ($state['occurred_at'] ?? ''))->toStartWith('2026-10-02 09:41');
});

it('requires the channel and the summary, and saves nothing without them', function () {
    $this->actingAs($this->lawyer, 'web');

    communicationsTab($this->matter)->callTableAction('create', data: [
        'type' => null,
        'summary' => '',
    ])->assertHasTableActionErrors(['type' => 'required', 'summary' => 'required']);

    expect(CommunicationLog::query()->count())->toBe(0);
});

it('refuses a summary made only of spaces with an error on the field', function () {
    $this->actingAs($this->lawyer, 'web');

    communicationsTab($this->matter)->callTableAction('create', data: [
        'type' => CommunicationType::CallOut->value,
        'summary' => '    ',
    ])->assertHasTableActionErrors(['summary']);

    expect(CommunicationLog::query()->count())->toBe(0);
});

/**
 * `is_visible_to_client` KHÔNG lên form (phán quyết Task 8): không màn hình portal nào đọc bảng
 * này, nên một công tắc ở đây chỉ làm luật sư tin rằng khách đã thấy. Một payload dàn dựng gửi
 * thẳng `true` vẫn ra một dòng nội bộ — Action ép `false`.
 */
it('has no client-visibility switch, and a forged one is ignored', function () {
    $this->actingAs($this->lawyer, 'web');

    $component = communicationsTab($this->matter)->mountTableAction('create');

    $fields = array_keys($component->instance()->getMountedTableActionForm()?->getFlatFields(withHidden: true) ?? []);

    expect($fields)->not->toContain('is_visible_to_client')
        ->and($fields)->toContain('type')
        ->and($fields)->toContain('summary');

    communicationsTab($this->matter)->callTableAction('create', data: [
        'type' => CommunicationType::Email->value,
        'summary' => 'Gửi bản thảo hợp đồng.',
        'is_visible_to_client' => true,
    ])->assertHasNoTableActionErrors();

    expect(CommunicationLog::query()->sole()->is_visible_to_client)->toBeFalse();
});

it('lets an assistant on the matter team log a call', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($assistant, 'web');

    communicationsTab($this->matter)
        ->assertTableActionVisible('create')
        ->callTableAction('create', data: [
            'type' => CommunicationType::CallOut->value,
            'summary' => 'Nhắc khách mang CCCD bản gốc.',
        ])->assertHasNoTableActionErrors();

    expect(CommunicationLog::query()->sole()->created_by)->toBe($assistant->id);
});

/**
 * Kế toán có `matter.viewAny` nhưng không có `matter.view` hay `matter.update` (SPEC §5): tab
 * không tồn tại cho họ, và một lần ép gọi Livewire bị chặn trước khi chạm tới Action.
 */
it('does not exist for an accountant, and a forced call writes nothing', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $this->actingAs($accountant, 'web');

    expect(CommunicationLogsRelationManager::canViewForRecord($this->matter, ViewMatter::class))->toBeFalse();

    try {
        communicationsTab($this->matter)->callTableAction('create', data: [
            'type' => CommunicationType::CallOut->value,
            'summary' => 'Không được ghi.',
        ]);
    } catch (Throwable) {
        // Bị chặn ở cổng của tab — cũng là một lần từ chối.
    }

    expect(CommunicationLog::query()->count())->toBe(0);
});

it('does not exist for a lawyer outside the matter team, and a forced call writes nothing', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($outsider, 'web');

    expect(CommunicationLogsRelationManager::canViewForRecord($this->matter, ViewMatter::class))->toBeFalse();

    try {
        communicationsTab($this->matter)->callTableAction('create', data: [
            'type' => CommunicationType::CallOut->value,
            'summary' => 'Không được ghi.',
        ]);
    } catch (Throwable) {
    }

    expect(CommunicationLog::query()->count())->toBe(0);
});

it('lists the logs of this matter only, newest first, with who wrote them', function () {
    $older = CommunicationLog::factory()->for($this->matter)->create([
        'summary' => 'Cuộc gọi tuần trước',
        'occurred_at' => now()->subDays(7),
        'created_by' => $this->lawyer->id,
    ]);
    $newer = CommunicationLog::factory()->for($this->matter)->create([
        'summary' => 'Cuộc gọi hôm qua',
        'occurred_at' => now()->subDay(),
        'created_by' => $this->lawyer->id,
    ]);
    CommunicationLog::factory()->for(Matter::factory())->create(['summary' => 'Cuộc gọi của hồ sơ khác']);

    $this->actingAs($this->lawyer, 'web');

    communicationsTab($this->matter)
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
        ->assertSee('Cuộc gọi hôm qua')
        ->assertSee('Luật sư Vũ Khang')
        ->assertDontSee('Cuộc gọi của hồ sơ khác');
});

/**
 * Nhật ký là bằng chứng về AI đã nói với khách: người ghi đã nghỉ việc (tài khoản xoá mềm) vẫn
 * phải hiện tên, không thành một ô trống.
 */
it('still names the author of an entry after their account has been soft-deleted', function () {
    $former = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Đã Nghỉ']);
    CommunicationLog::factory()->for($this->matter)->create(['created_by' => $former->id]);
    $former->delete();

    $this->actingAs($this->lawyer, 'web');

    communicationsTab($this->matter)->assertSee('Luật sư Đã Nghỉ');
});

/** Nhật ký là bằng chứng: không có nút "Sửa" nào trên tab. */
it('offers no edit button on a logged entry', function () {
    $log = CommunicationLog::factory()->for($this->matter)->create();

    $this->actingAs($this->lawyer, 'web');

    communicationsTab($this->matter)
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionVisible('delete', $log);
});

/**
 * Nút "Xoá" hỏi `CommunicationLogPolicy::delete` — không rộng hơn cửa ghi. Một thành viên đội
 * ngũ chỉ có `matter.view` (gán thẳng; không vai nào của SPEC §5 có đúng tổ hợp này) đọc được
 * tab nhưng không thấy nút ghi hay nút xoá.
 */
it('shows the tab but neither the add nor the delete button to a team member who cannot write to the matter', function () {
    $log = CommunicationLog::factory()->for($this->matter)->create(['summary' => 'Dòng đọc được']);
    $readOnly = User::factory()->create();
    $readOnly->givePermissionTo('matter.view');
    $this->matter->addTeamMember($readOnly, MatterRole::Observer);

    $this->actingAs($readOnly, 'web');

    communicationsTab($this->matter)
        ->assertSee('Dòng đọc được')
        ->assertTableActionHidden('create')
        ->assertTableActionHidden('delete', $log);
});

// =========================================================================================
// XOÁ — xoá mềm, lý do bắt buộc, có dấu vết
// =========================================================================================

it('deletes softly with a reason, keeps the row in the database and leaves an audit entry', function () {
    $log = CommunicationLog::factory()->for($this->matter)->create(['summary' => 'Ghi nhầm hồ sơ']);

    $this->actingAs($this->lawyer, 'web');

    communicationsTab($this->matter)->callTableAction('delete', $log, data: [
        'reason' => 'Ghi nhầm vào hồ sơ này.',
    ])->assertHasNoTableActionErrors();

    expect(CommunicationLog::query()->find($log->id))->toBeNull()
        ->and(CommunicationLog::withTrashed()->find($log->id)?->trashed())->toBeTrue();

    $audit = Activity::query()->where('event', 'communication_log_deleted')->sole();

    expect($audit->subject_type)->toBe('communication_log')
        ->and($audit->subject_id)->toBe($log->id)
        ->and($audit->causer_id)->toBe($this->lawyer->id)
        ->and($audit->properties->get('reason'))->toBe('Ghi nhầm vào hồ sơ này.')
        ->and($audit->properties->get('matter_id'))->toBe($this->matter->id);

    communicationsTab($this->matter)->assertCanNotSeeTableRecords([$log]);
});

it('requires a reason to delete, and deletes nothing without one', function () {
    $log = CommunicationLog::factory()->for($this->matter)->create();

    $this->actingAs($this->lawyer, 'web');

    communicationsTab($this->matter)->callTableAction('delete', $log, data: [
        'reason' => '',
    ])->assertHasTableActionErrors(['reason' => 'required']);

    communicationsTab($this->matter)->callTableAction('delete', $log, data: [
        'reason' => '   ',
    ])->assertHasTableActionErrors(['reason']);

    expect(CommunicationLog::query()->find($log->id))->not->toBeNull()
        ->and(Activity::query()->where('event', 'communication_log_deleted')->count())->toBe(0);
});

// =========================================================================================
// VỤ ĐÃ XOÁ MỀM
// =========================================================================================

/**
 * Admin vẫn MỞ được một vụ đã xoá mềm (để khôi phục), nên tab còn đó — nhưng không ghi được gì
 * vào nó: `MatterPolicy::update` chặn mọi vụ đã xoá mềm, và Action hỏi lại dưới khoá.
 */
it('refuses to log a call on a soft-deleted matter, even for an admin', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->matter->delete();

    $this->actingAs($admin, 'web');

    $matter = Matter::withTrashed()->find($this->matter->id);

    communicationsTab($matter)->assertTableActionHidden('create');

    try {
        communicationsTab($matter)->callTableAction('create', data: [
            'type' => CommunicationType::CallOut->value,
            'summary' => 'Không được ghi.',
        ]);
    } catch (Throwable) {
    }

    expect(CommunicationLog::withTrashed()->count())->toBe(0);
});
