<?php

use App\Actions\Intake\ChangeIntakeStatus;
use App\Actions\Intake\ConvertIntakeToMatter;
use App\Actions\Intake\DeclineIntake;
use App\Actions\Intake\MergeIntake;
use App\Actions\Intake\RecordIntake;
use App\Enums\ClientType;
use App\Enums\Confidentiality;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Models\IntakeRequest;
use App\Models\MatterType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * M10 Task 7 (R7b) — hạn lưu của người KHÔNG thành khách. Bản ghi vào `declined`, `lost` hoặc `merged`
 * nhận `retention_until` = thời điểm đó + `PROSPECT_RETENTION_MONTHS` tháng; mọi đường vào ba trạng
 * thái đó đi qua MỘT chỗ đặt hạn (`IntakeRequest` lúc lưu), nên không Action nào quên được. Bản còn mở
 * hoặc đã chuyển thành vụ việc không có hạn. Hành vi màn hình (từ chối từ trang sửa) ở
 * `tests/Feature/Filament/EraseIntakeDataTest.php`.
 *
 * Hàm toàn cục mang tiền tố `ret…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(now()->setDate(2026, 10, 3)->setTime(15, 0));
});

function retStaff(Role $role = Role::Assistant): User
{
    return User::factory()->withRole($role)->create();
}

function retRecord(User $actor, string $phone, array $overrides = []): IntakeRequest
{
    return app(RecordIntake::class)->handle($actor, [...[
        'contact_name' => 'Người Gọi '.$phone,
        'contact_phone' => $phone,
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
    ], ...$overrides])->intake;
}

it('stamps retention_until when a record is declined, lost or merged: PROSPECT_RETENTION_MONTHS after that moment', function () {
    $assistant = retStaff();
    $manager = retStaff(Role::Manager);

    $declined = app(DeclineIntake::class)->handle($assistant, retRecord($assistant, '0901000001'), 'Ngoài lĩnh vực của văn phòng');
    $declinedForConflict = app(DeclineIntake::class)->handle($manager, retRecord($assistant, '0901000002'), 'Xung đột với khách hiện hữu', forConflict: true);
    $lost = app(ChangeIntakeStatus::class)->handle($assistant, retRecord($assistant, '0901000003'), IntakeStatus::Lost);

    $source = retRecord($assistant, '0901000004');
    $target = retRecord($assistant, '0901000005');
    app(MergeIntake::class)->handle($assistant, $source, $target);

    foreach ([$declined, $declinedForConflict, $lost, $source] as $record) {
        expect($record->fresh()->retention_until?->toDateString())->toBe('2028-10-03', $record->code);
    }

    // Bản đích của một lần gộp vẫn mở: không có hạn.
    expect($target->fresh()->status)->toBe(IntakeStatus::New)
        ->and($target->fresh()->retention_until)->toBeNull();
});

it('gives no retention date to a record that stays open, nor to one converted into a matter', function () {
    $assistant = retStaff();
    $lawyer = retStaff(Role::Lawyer);

    $contacted = app(ChangeIntakeStatus::class)->handle($assistant, retRecord($assistant, '0901000011'), IntakeStatus::Contacted);
    $quoted = app(ChangeIntakeStatus::class)->handle($assistant, retRecord($assistant, '0901000012'), IntakeStatus::Quoted);

    $converted = retRecord($lawyer, '0901000013');
    $type = MatterType::factory()->withStages()->create(['is_active' => true]);
    app(ConvertIntakeToMatter::class)->handle($lawyer, $converted, [
        'title' => 'Tranh chấp hợp đồng mua bán',
        'matter_type_id' => $type->id,
        'lead_lawyer_id' => $lawyer->id,
        'client_role' => PartyRole::Plaintiff->value,
        'opened_at' => today()->toDateString(),
        'confidentiality' => Confidentiality::Normal->value,
        'client_type' => ClientType::Individual->value,
    ]);

    expect($contacted->fresh()->retention_until)->toBeNull()
        ->and($quoted->fresh()->retention_until)->toBeNull()
        ->and($converted->fresh()->status)->toBe(IntakeStatus::Won)
        ->and($converted->fresh()->retention_until)->toBeNull();
});

it('keeps the first retention date when a closed record is saved again without a status change', function () {
    $assistant = retStaff();

    $declined = app(DeclineIntake::class)->handle($assistant, retRecord($assistant, '0901000021'), 'Khách không phù hợp');

    // Ba tháng sau, một lần gọi trùng được gộp VÀO bản đã từ chối: bản đích được lưu lại (bên đối lập,
    // kiểm tra lại) nhưng trạng thái không đổi, nên hạn lưu giữ nguyên ngày cũ.
    $this->travel(3)->months();
    app(MergeIntake::class)->handle($assistant, retRecord($assistant, '0901000022'), $declined->fresh());

    expect($declined->fresh()->status)->toBe(IntakeStatus::Declined)
        ->and($declined->fresh()->retention_until->toDateString())->toBe('2028-10-03');
});

it('restarts the clock when a declined record later enters another closed state by being merged away', function () {
    $assistant = retStaff();

    $first = app(DeclineIntake::class)->handle($assistant, retRecord($assistant, '0901000031'), 'Khách không phù hợp');
    $target = retRecord($assistant, '0901000031', ['contact_name' => 'Cùng người, gọi lại']);

    $this->travel(2)->months();
    app(MergeIntake::class)->handle($assistant, $first->fresh(), $target);

    expect($first->fresh()->status)->toBe(IntakeStatus::Merged)
        ->and($first->fresh()->retention_until->toDateString())->toBe('2028-12-03');
});

it('reads the number of months from PROSPECT_RETENTION_MONTHS', function () {
    config()->set('vkcrm.prospect_retention_months', 6);
    $assistant = retStaff();

    $declined = app(DeclineIntake::class)->handle($assistant, retRecord($assistant, '0901000041'), 'Ngoài lĩnh vực');

    expect(IntakeRequest::retentionMonths())->toBe(6)
        ->and($declined->fresh()->retention_until->toDateString())->toBe('2027-04-03');

    config()->set('vkcrm.prospect_retention_months', '36');
    expect(IntakeRequest::retentionMonths())->toBe(36);
});

/*
 * Một giá trị vô nghĩa trong `.env` không được biến thành "ẩn danh ngay ngày mai": (int) 'abc' là 0.
 * Mặc định an toàn là 24 tháng (kế hoạch R7b), không phải 1.
 */
it('falls back to 24 months when PROSPECT_RETENTION_MONTHS is missing, zero, negative or not a whole number', function (mixed $value) {
    config()->set('vkcrm.prospect_retention_months', $value);

    expect(IntakeRequest::retentionMonths())->toBe(24);
})->with([
    'missing' => [null],
    'empty' => [''],
    'zero' => ['0'],
    'negative' => ['-3'],
    'text' => ['abc'],
    'fraction' => ['2.5'],
]);

it('ships 24 months as the default when .env says nothing', function () {
    expect(IntakeRequest::retentionMonths())->toBe(24);
});

/*
 * Dữ liệu dựng sẵn (seeder, nhập liệu, test) đặt cả trạng thái lẫn hạn lưu trong CÙNG một lần lưu: hạn
 * đã cho thắng. Không cho thì hạn được tính như mọi đường khác.
 */
it('keeps a retention date set explicitly in the same save, and stamps one when none is given', function () {
    $explicit = IntakeRequest::factory()->status(IntakeStatus::Declined)->create(['retention_until' => '2027-01-01']);
    $implicit = IntakeRequest::factory()->status(IntakeStatus::Declined)->create();

    expect($explicit->fresh()->retention_until->toDateString())->toBe('2027-01-01')
        ->and($implicit->fresh()->retention_until->toDateString())->toBe('2028-10-03');
});
