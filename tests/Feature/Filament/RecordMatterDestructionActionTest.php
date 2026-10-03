<?php

use App\Actions\Schedule\FlagRetentionExpiry;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\User;
use App\Notifications\Staff\RetentionExpiryAlert;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Features\SupportTesting\Testable;
use Spatie\Activitylog\Models\Activity;

/**
 * M7 Task 6 (R5) — nút "Ghi quyết định tiêu huỷ" và khối "Lưu trữ hồ sơ" trên trang vụ việc. Mọi
 * khẳng định đi qua Livewire (`ViewMatter`), không gọi thẳng Action.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản trị Lê Thị Hạnh']);
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->create([
        'lead_lawyer_id' => $this->lawyer->id,
        'closed_at' => now()->subYears(11)->toDateString(),
    ]);
    $this->archive = MatterArchive::factory()->create([
        'matter_id' => $this->matter->id,
        'archived_by' => $this->lawyer->id,
        'client_access_until' => now()->subYears(11)->addDays(90)->toDateString(),
        'retention_until' => now()->subDay()->toDateString(),
    ]);
});

const RMDA_REASON = 'Hết hạn lưu trữ theo quy chế; Ban giám đốc phê duyệt tiêu huỷ ngày 30/09.';

function rmdaPage(object $test, ?User $user = null, ?Matter $matter = null): Testable
{
    $test->actingAs($user ?? $test->admin, 'web');

    return $test->livewire(ViewMatter::class, ['record' => ($matter ?? $test->matter)->getKey()]);
}

it('admin thấy nút khi hồ sơ đã quá hạn lưu trữ, ghi được quyết định, và khối "Lưu trữ hồ sơ" hiện quyết định đó', function () {
    rmdaPage($this)
        ->assertSee('Lưu trữ hồ sơ')
        ->assertSee('Chưa có quyết định tiêu huỷ')
        ->assertSee('Hồ sơ đã quá hạn lưu trữ')
        // Ba dòng của một quyết định đã ghi chưa hiện khi chưa có quyết định — không một nhãn
        // treo trên một giá trị rỗng.
        ->assertDontSee('Người ghi quyết định')
        ->assertDontSee('Số biên bản tiêu huỷ')
        ->assertDontSee('Lý do tiêu huỷ')
        ->assertDontSee('Quản trị Lê Thị Hạnh')
        ->assertActionVisible('recordDestruction')
        ->callAction('recordDestruction', [
            'destruction_record_no' => 'BB-TH-2026-007',
            'destruction_reason' => RMDA_REASON,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Đã ghi quyết định tiêu huỷ hồ sơ.')
        ->assertSee('Người ghi quyết định')
        ->assertSee('BB-TH-2026-007')
        ->assertSee(RMDA_REASON)
        ->assertSee('Quản trị Lê Thị Hạnh')
        ->assertDontSee('Chưa có quyết định tiêu huỷ')
        ->assertDontSee('Hồ sơ đã quá hạn lưu trữ')
        ->assertActionHidden('recordDestruction');

    $archive = $this->archive->fresh();
    expect($archive->destroyed_at)->not->toBeNull()
        ->and($archive->destroyed_by)->toBe($this->admin->id)
        ->and($archive->destruction_record_no)->toBe('BB-TH-2026-007')
        ->and($archive->destruction_reason)->toBe(RMDA_REASON);

    // Không xoá gì.
    expect(Matter::query()->whereKey($this->matter->id)->exists())->toBeTrue()
        ->and(MatterArchive::query()->whereKey($this->archive->id)->exists())->toBeTrue();
});

it('form: lý do dưới 20 ký tự, số biên bản rỗng hoặc dài quá cột (50) bị chặn ngay trên form', function () {
    rmdaPage($this)
        ->callAction('recordDestruction', [
            'destruction_record_no' => '',
            'destruction_reason' => 'Quá ngắn',
        ])
        ->assertHasActionErrors(['destruction_record_no' => 'required', 'destruction_reason' => 'min']);

    rmdaPage($this)
        ->callAction('recordDestruction', [
            'destruction_record_no' => str_repeat('B', 51),
            'destruction_reason' => RMDA_REASON,
        ])
        ->assertHasActionErrors(['destruction_record_no' => 'max']);

    rmdaPage($this)
        ->callAction('recordDestruction', [
            'destruction_record_no' => 'BB-1',
            'destruction_reason' => '',
        ])
        ->assertHasActionErrors(['destruction_reason' => 'required']);

    rmdaPage($this)
        ->callAction('recordDestruction', [
            'destruction_record_no' => 'BB-1',
            'destruction_reason' => str_repeat('a', 5001),
        ])
        ->assertHasActionErrors(['destruction_reason' => 'max']);

    expect($this->archive->fresh()->destroyed_at)->toBeNull();
});

it('lý do toàn khoảng trắng qua được form nhưng bị Action chặn, lỗi gắn đúng ô', function () {
    rmdaPage($this)
        ->callAction('recordDestruction', [
            'destruction_record_no' => 'BB-1',
            'destruction_reason' => str_repeat(' ', 25),
        ])
        ->assertHasActionErrors(['destruction_reason']);

    expect($this->archive->fresh()->destroyed_at)->toBeNull();
});

/**
 * Người ghi quyết định là người chịu trách nhiệm về bản ghi đó. Tài khoản của họ bị xoá mềm sau
 * này (nghỉ việc) không được xoá tên họ khỏi khối "Lưu trữ hồ sơ" — một quyết định tiêu huỷ không
 * có người ghi là đúng thứ một lần thanh tra sẽ hỏi.
 */
it('admin đã ghi quyết định rồi bị xoá mềm: tên họ vẫn hiện trên khối "Lưu trữ hồ sơ"', function () {
    $this->archive->update([
        'destroyed_at' => now()->subDay(),
        'destroyed_by' => $this->admin->id,
        'destruction_record_no' => 'BB-CU',
        'destruction_reason' => RMDA_REASON,
    ]);
    $this->admin->delete();
    $manager = User::factory()->withRole(Role::Manager)->create();

    rmdaPage($this, $manager)
        ->assertSee('Người ghi quyết định')
        ->assertSee('Quản trị Lê Thị Hạnh');
});

it('chưa quá hạn lưu trữ (kể cả đúng ngày cuối): không có nút', function () {
    $this->archive->update(['retention_until' => today()->toDateString()]);

    rmdaPage($this)
        ->assertSee('Lưu trữ hồ sơ')
        ->assertDontSee('Hồ sơ đã quá hạn lưu trữ')
        ->assertActionHidden('recordDestruction');
});

it('đã ghi quyết định: không còn nút', function () {
    $this->archive->update([
        'destroyed_at' => now()->subDay(),
        'destroyed_by' => $this->admin->id,
        'destruction_record_no' => 'BB-CU',
        'destruction_reason' => RMDA_REASON,
    ]);

    rmdaPage($this)
        ->assertSee('BB-CU')
        ->assertDontSee('Chưa có quyết định tiêu huỷ')
        ->assertDontSee('Hồ sơ đã quá hạn lưu trữ')
        ->assertActionHidden('recordDestruction');
});

it('vụ đã mở lại (closed_at rỗng): không có nút', function () {
    $this->matter->update(['closed_at' => null]);

    rmdaPage($this)->assertActionHidden('recordDestruction');
});

it('vụ chưa có bản ghi lưu trữ: không có khối, không có nút', function () {
    $open = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);

    rmdaPage($this, matter: $open)
        ->assertDontSee('Lưu trữ hồ sơ')
        ->assertActionHidden('recordDestruction');

    // Vụ ĐÃ đóng nhưng bản ghi lưu trữ đã xoá mềm: quan hệ `archive` rỗng, nên điều kiện "có bản
    // ghi lưu trữ" là vế duy nhất chặn — `closed_at` không chặn thay được.
    $this->archive->delete();

    rmdaPage($this)
        ->assertDontSee('Lưu trữ hồ sơ')
        ->assertActionHidden('recordDestruction');
});

it('trưởng phòng và luật sư phụ trách xem được khối nhưng không thấy nút; ép gọi cũng không ghi được gì', function (Role $role) {
    $actor = $role === Role::Lawyer ? $this->lawyer : User::factory()->withRole($role)->create();

    $page = rmdaPage($this, $actor)
        ->assertSee('Lưu trữ hồ sơ')
        ->assertActionHidden('recordDestruction');

    // Ép gọi bằng request Livewire thô — `callAction()` của bộ test từ chối ngay một action ẩn
    // trước khi gửi gì, nên nó không đo được đường này.
    $page->call('mountAction', 'recordDestruction')
        ->set('mountedActions.0.data.destruction_record_no', 'BB-EP')
        ->set('mountedActions.0.data.destruction_reason', RMDA_REASON)
        ->call('callMountedAction');

    expect($this->archive->fresh()->destroyed_at)->toBeNull()
        ->and(Activity::query()->where('event', 'matter_destruction_recorded')->exists())->toBeFalse();
})->with([
    'trưởng phòng' => [Role::Manager],
    'luật sư phụ trách' => [Role::Lawyer],
]);

/**
 * Đối chứng của test trên: CÙNG chuỗi request thô (`mountAction` → `set` vào
 * `mountedActions.0.data.*` → `callMountedAction`), do admin gửi, ghi được quyết định. Không có
 * đối chứng này, một đường ép gọi hỏng (state path sai, tên action sai) cũng để test trên xanh mà
 * không đo gì, vì nó không ghi được gì cho BẤT KỲ AI.
 */
it('đối chứng: cùng chuỗi request thô do admin gửi thì ghi được', function () {
    rmdaPage($this)
        ->call('mountAction', 'recordDestruction')
        ->set('mountedActions.0.data.destruction_record_no', 'BB-EP')
        ->set('mountedActions.0.data.destruction_reason', RMDA_REASON)
        ->call('callMountedAction');

    expect($this->archive->fresh()->destruction_record_no)->toBe('BB-EP')
        ->and(Activity::query()->where('event', 'matter_destruction_recorded')->count())->toBe(1);
});

it('admin bị gỡ vai trong lúc trang còn mở: Action tự hỏi lại quyền, từ chối bằng câu tiếng Việt, không ghi gì', function () {
    $page = rmdaPage($this)->assertActionVisible('recordDestruction');

    // Một tiến trình khác gỡ vai admin; đối tượng người dùng của phiên này còn giữ vai cũ trong
    // bộ nhớ, nên nút vẫn hiện — chỉ Action, đọc lại người thực hiện từ CSDL, chặn được.
    User::query()->find($this->admin->id)->syncRoles([Role::Lawyer->value]);

    $page->callAction('recordDestruction', [
        'destruction_record_no' => 'BB-1',
        'destruction_reason' => RMDA_REASON,
    ])->assertNotified(__('actions.failed_title'));

    expect($this->archive->fresh()->destroyed_at)->toBeNull();
});

it('người khác đã ghi trong lúc trang còn mở: lần bấm thứ hai bị từ chối, quyết định đầu giữ nguyên', function () {
    $page = rmdaPage($this)->assertActionVisible('recordDestruction');
    $other = User::factory()->withRole(Role::Admin)->create();

    $this->archive->update([
        'destroyed_at' => now(),
        'destroyed_by' => $other->id,
        'destruction_record_no' => 'BB-DAU',
        'destruction_reason' => RMDA_REASON,
    ]);

    // Request kế tiếp nạp lại bản ghi lưu trữ, nên nút đã ẩn và Filament không chạy action nữa;
    // lớp chặn thứ hai (Action từ chối `destroyed_at` đã có) đo ở RecordMatterDestructionTest.
    $page->callAction('recordDestruction', [
        'destruction_record_no' => 'BB-SAU',
        'destruction_reason' => RMDA_REASON,
    ]);

    expect($this->archive->fresh()->destruction_record_no)->toBe('BB-DAU')
        ->and($this->archive->fresh()->destroyed_by)->toBe($other->id);
});

/**
 * Hai nửa của R5 nối với nhau: quyết định ghi qua màn hình làm tác vụ hằng ngày `retention.flag`
 * bỏ qua hồ sơ đó, trong khi một hồ sơ quá hạn khác chưa ghi vẫn được cảnh báo — tác vụ chạy thật,
 * không phải tập rỗng.
 */
it('sau khi ghi qua màn hình, tác vụ hằng ngày không còn cảnh báo hồ sơ đó', function () {
    $other = Matter::factory()->create([
        'lead_lawyer_id' => $this->lawyer->id,
        'closed_at' => now()->subYears(11)->toDateString(),
    ]);
    MatterArchive::factory()->create([
        'matter_id' => $other->id,
        'archived_by' => $this->lawyer->id,
        'retention_until' => now()->subDay()->toDateString(),
    ]);

    rmdaPage($this)
        ->callAction('recordDestruction', [
            'destruction_record_no' => 'BB-TH-2026-008',
            'destruction_reason' => RMDA_REASON,
        ])
        ->assertHasNoActionErrors();

    $result = (new FlagRetentionExpiry)->handle();

    $alerted = DatabaseNotification::query()
        ->where('type', RetentionExpiryAlert::class)
        ->get()
        ->map(fn (DatabaseNotification $row): int => (int) $row->data['viewData']['matter_id'])
        ->all();

    expect($result)->toMatchArray(['flagged' => 1, 'notified' => 1, 'failed' => 0])
        ->and($alerted)->toBe([$other->id]);
});
