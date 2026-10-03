<?php

use App\Actions\Intake\AnonymiseProspect;
use App\Actions\Intake\ChangeIntakeStatus;
use App\Actions\Intake\DeclineIntake;
use App\Actions\Intake\RecordIntake;
use App\Actions\Intake\RecordPrivacyNotice;
use App\Actions\Intake\UpdateIntakeSummary;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\IntakeRequests\Pages\ConvertIntakeRequest;
use App\Filament\Admin\Resources\IntakeRequests\Pages\EditIntakeRequest;
use App\Models\IntakeRequest;
use App\Models\MatterType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;

/*
 * M10 Task 5 (R5) — `first_response_at` là lần ĐẦU bản ghi rời `new` bằng một việc văn phòng làm với
 * người liên hệ: đổi trạng thái, từ chối, chuyển thành vụ việc — qua màn hình. Gộp KHÔNG phải một lần
 * phản hồi (bản gộp đi là bản trùng; đồng hồ của người gọi chạy tiếp ở bản đích).
 *
 * Hàm toàn cục mang tiền tố `ifr…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00', 'Asia/Ho_Chi_Minh'));
});

function ifrStaff(Role $role = Role::Assistant): User
{
    return User::factory()->withRole($role)->create();
}

function ifrRecord(User $actor, array $overrides = []): IntakeRequest
{
    return app(RecordIntake::class)->handle($actor, [...[
        'contact_name' => 'Người Gọi Mẫu',
        'contact_phone' => '0832270898',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
    ], ...$overrides])->intake;
}

function ifrEdit(IntakeRequest $intake)
{
    return test()->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()]);
}

it('records the first response when a person first moves the record out of new on the screen', function (IntakeStatus $to) {
    $assistant = ifrStaff();
    $intake = ifrRecord($assistant);

    expect($intake->first_response_at)->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:20', 'Asia/Ho_Chi_Minh'));
    $this->actingAs($assistant, 'web');
    ifrEdit($intake)->callAction('changeStatus', data: ['status' => $to->value])->assertHasNoErrors();

    expect($intake->fresh()->status)->toBe($to)
        ->and($intake->fresh()->first_response_at?->format('Y-m-d H:i'))->toBe('2026-10-07 10:20');
})->with([
    'đã liên hệ lại' => IntakeStatus::Contacted,
    'đang tư vấn' => IntakeStatus::Consulting,
    'đã báo phí' => IntakeStatus::Quoted,
    'khách không theo tiếp' => IntakeStatus::Lost,
]);

it('keeps the first response time when the status moves on later', function () {
    $assistant = ifrStaff();
    $intake = ifrRecord($assistant);
    $this->actingAs($assistant, 'web');

    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:20', 'Asia/Ho_Chi_Minh'));
    ifrEdit($intake)->callAction('changeStatus', data: ['status' => IntakeStatus::Contacted->value])->assertHasNoErrors();

    $this->travelTo(CarbonImmutable::parse('2026-10-08 15:00', 'Asia/Ho_Chi_Minh'));
    ifrEdit($intake->fresh())->callAction('changeStatus', data: ['status' => IntakeStatus::Quoted->value])->assertHasNoErrors();

    expect($intake->fresh()->status)->toBe(IntakeStatus::Quoted)
        ->and($intake->fresh()->first_response_at?->format('Y-m-d H:i'))->toBe('2026-10-07 10:20');
});

it('counts a decline from new as the first response, and keeps an earlier one', function () {
    $lawyer = ifrStaff(Role::Lawyer);
    $fresh = ifrRecord($lawyer);
    $answered = ifrRecord($lawyer, ['contact_phone' => '0901222333']);
    $this->actingAs($lawyer, 'web');

    $this->travelTo(CarbonImmutable::parse('2026-10-07 09:40', 'Asia/Ho_Chi_Minh'));
    ifrEdit($answered)->callAction('changeStatus', data: ['status' => IntakeStatus::Contacted->value])->assertHasNoErrors();

    $this->travelTo(CarbonImmutable::parse('2026-10-07 11:05', 'Asia/Ho_Chi_Minh'));
    ifrEdit($fresh)->callAction('decline', data: ['decline_reason' => 'Ngoài lĩnh vực'])->assertHasNoErrors();
    ifrEdit($answered->fresh())->callAction('decline', data: ['decline_reason' => 'Ngoài lĩnh vực'])->assertHasNoErrors();

    expect($fresh->fresh()->status)->toBe(IntakeStatus::Declined)
        ->and($fresh->fresh()->first_response_at?->format('Y-m-d H:i'))->toBe('2026-10-07 11:05')
        ->and($answered->fresh()->status)->toBe(IntakeStatus::Declined)
        ->and($answered->fresh()->first_response_at?->format('Y-m-d H:i'))->toBe('2026-10-07 09:40');
});

/** Một bản Xanh đủ định danh, đã ghi thông báo và câu chuyện — chuyển đổi được ngay. */
function ifrConvertible(User $lawyer, array $overrides = []): IntakeRequest
{
    $type = MatterType::factory()->withStages()->create(['is_active' => true, 'name' => 'Dân sự']);
    $intake = ifrRecord($lawyer, [...['contact_phone' => '0832 270 898', 'matter_type_id' => $type->id], ...$overrides]);
    app(RecordPrivacyNotice::class)->handle($lawyer, $intake, true);
    app(UpdateIntakeSummary::class)->handle($lawyer, $intake->fresh(), 'Tranh chấp hợp đồng.');

    return $intake->fresh();
}

it('counts converting a record still in new into a matter as the first response', function () {
    $lawyer = ifrStaff(Role::Lawyer);
    $intake = ifrConvertible($lawyer);

    $this->travelTo(CarbonImmutable::parse('2026-10-07 14:10', 'Asia/Ho_Chi_Minh'));
    $this->actingAs($lawyer, 'web');
    $this->livewire(ConvertIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->call('convert')
        ->assertHasNoFormErrors();

    expect($intake->fresh()->status)->toBe(IntakeStatus::Won)
        ->and($intake->fresh()->first_response_at?->format('Y-m-d H:i'))->toBe('2026-10-07 14:10');
});

it('keeps the earlier first response when a record already answered is converted', function () {
    $lawyer = ifrStaff(Role::Lawyer);
    $intake = ifrConvertible($lawyer);
    $this->actingAs($lawyer, 'web');

    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:20', 'Asia/Ho_Chi_Minh'));
    ifrEdit($intake)->callAction('changeStatus', data: ['status' => IntakeStatus::Consulting->value])->assertHasNoErrors();

    $this->travelTo(CarbonImmutable::parse('2026-10-08 14:10', 'Asia/Ho_Chi_Minh'));
    $this->livewire(ConvertIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->call('convert')
        ->assertHasNoFormErrors();

    expect($intake->fresh()->status)->toBe(IntakeStatus::Won)
        ->and($intake->fresh()->first_response_at?->format('Y-m-d H:i'))->toBe('2026-10-07 10:20');
});

it('does not count merging a duplicate as a response, on either record', function () {
    $assistant = ifrStaff();
    $source = ifrRecord($assistant);
    $target = ifrRecord($assistant);
    $this->actingAs($assistant, 'web');

    ifrEdit($source)->callAction('merge', data: ['merge_target' => $target->id])->assertHasNoErrors();

    expect($source->fresh()->status)->toBe(IntakeStatus::Merged)
        ->and($source->fresh()->first_response_at)->toBeNull()
        ->and($target->fresh()->status)->toBe(IntakeStatus::New)
        ->and($target->fresh()->first_response_at)->toBeNull();
});

it('refuses a received time in the future, which would make the response clock run backwards', function () {
    try {
        ifrRecord(ifrStaff(), ['received_at' => '2026-10-07 09:01:00']);
        $this->fail('Lúc nhận ở tương lai phải bị từ chối.');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['received_at' => [__('intake.errors.received_at_in_future')]]);
    }

    expect(IntakeRequest::query()->count())->toBe(0);

    expect(ifrRecord(ifrStaff(), ['received_at' => '2026-10-07 09:00:00'])->received_at->format('Y-m-d H:i'))
        ->toBe('2026-10-07 09:00');
});

/** "Còn chờ phản hồi lần đầu" có hai bản — SQL (`scopeAwaitingFirstResponse`) và trong bộ nhớ — và chúng phải trả lời giống nhau. */
it('answers "still awaiting a first response" the same way in SQL and in memory', function (Closure $state, bool $awaiting) {
    $intake = ifrRecord(ifrStaff());
    $state($intake);

    $inSql = IntakeRequest::query()->withTrashed()->awaitingFirstResponse()->whereKey($intake->id)->exists()
        && IntakeRequest::query()->whereKey($intake->id)->exists();

    expect($inSql)->toBe($awaiting)
        ->and(IntakeRequest::query()->withTrashed()->findOrFail($intake->id)->isAwaitingFirstResponse())->toBe($awaiting);
})->with([
    'mới' => [fn (IntakeRequest $i) => null, true],
    'đã liên hệ lại' => [fn (IntakeRequest $i) => $i->forceFill(['status' => IntakeStatus::Contacted])->save(), false],
    'đã gộp' => [fn (IntakeRequest $i) => $i->forceFill(['status' => IntakeStatus::Merged])->save(), false],
    'đã ẩn danh mà vẫn ở new' => [fn (IntakeRequest $i) => $i->forceFill(['anonymised_at' => now()])->save(), false],
    'xoá theo yêu cầu khi còn new (Action thật của Task 7)' => [fn (IntakeRequest $i) => app(AnonymiseProspect::class)
        ->erase(ifrStaff(Role::Admin), $i, 'Người liên hệ yêu cầu xoá dữ liệu qua điện thoại'), false],
    'đã xoá mềm' => [fn (IntakeRequest $i) => $i->delete(), false],
]);

/*
 * Gộp làn m10-t7 (Task 7) vào m10-intake: rời `new` sang một trạng thái cuối "không thành khách" ghi HAI
 * mốc trong cùng một lần lưu — mốc phản hồi của Task 5 (Action) và hạn lưu của Task 7 (móc `saving`
 * `IntakeRequest::stampRetention()`); từ một bước sau `new` thì chỉ hạn lưu là mới.
 */
it('records the first response and the retention date in the same save when a record leaves new for lost or declined', function (Closure $close) {
    $lawyer = ifrStaff(Role::Lawyer);
    $fresh = ifrRecord($lawyer);
    $answered = ifrRecord($lawyer, ['contact_phone' => '0901222333']);
    $this->actingAs($lawyer, 'web');

    $this->travelTo(CarbonImmutable::parse('2026-10-07 09:40', 'Asia/Ho_Chi_Minh'));
    ifrEdit($answered)->callAction('changeStatus', data: ['status' => IntakeStatus::Contacted->value])->assertHasNoErrors();
    expect($answered->fresh()->retention_until)->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-10-07 11:05', 'Asia/Ho_Chi_Minh'));
    $close($fresh);
    $close($answered->fresh());

    expect($fresh->fresh()->first_response_at?->format('Y-m-d H:i'))->toBe('2026-10-07 11:05')
        ->and($fresh->fresh()->retention_until?->toDateString())->toBe('2028-10-07')
        ->and($answered->fresh()->first_response_at?->format('Y-m-d H:i'))->toBe('2026-10-07 09:40')
        ->and($answered->fresh()->retention_until?->toDateString())->toBe('2028-10-07');
})->with([
    'khách không theo tiếp' => fn (IntakeRequest $i) => ifrEdit($i)
        ->callAction('changeStatus', data: ['status' => IntakeStatus::Lost->value])->assertHasNoErrors(),
    'từ chối' => fn (IntakeRequest $i) => ifrEdit($i)
        ->callAction('decline', data: ['decline_reason' => 'Ngoài lĩnh vực'])->assertHasNoErrors(),
]);

it('never records a first response on a record erased on request while still in new', function () {
    $manager = ifrStaff(Role::Manager);
    $intake = ifrRecord($manager);
    app(AnonymiseProspect::class)->erase(ifrStaff(Role::Admin), $intake, 'Người liên hệ yêu cầu xoá dữ liệu qua điện thoại');

    $this->travelTo(CarbonImmutable::parse('2026-10-07 11:05', 'Asia/Ho_Chi_Minh'));

    expect(fn () => app(ChangeIntakeStatus::class)->handle($manager, $intake->fresh(), IntakeStatus::Contacted))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(DeclineIntake::class)->handle($manager, $intake->fresh(), 'Ngoài lĩnh vực'))
        ->toThrow(ValidationException::class);

    $after = $intake->fresh();
    expect($after->status)->toBe(IntakeStatus::New)
        ->and($after->first_response_at)->toBeNull()
        ->and($after->retention_until)->toBeNull()
        ->and($after->contact_name)->toBeNull()
        ->and($after->contact_phone_normalized)->toBeNull();
});
