<?php

use App\Actions\Intake\ConvertIntakeToMatter;
use App\Actions\Intake\RecordIntake;
use App\Actions\Intake\ReopenIntake;
use App\Actions\Intake\UpdateIntakeIdentity;
use App\Enums\ClientType;
use App\Enums\Confidentiality;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;

/*
 * Làn fa — luật của Action (không phải hành vi màn hình): những cổng mà form đã chặn trước nên một
 * test màn hình không chạm tới được — mã số thuế không kèm tên tổ chức (form `requiredWith`), và luật
 * sư phụ trách không đủ điều kiện (ô chọn chỉ liệt kê người đủ điều kiện). Action là cổng thật cho mọi
 * lối vào khác (lệnh, job, request sửa tay). Hàm toàn cục mang tiền tố `ioa…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function ioaStaff(Role $role = Role::Lawyer): User
{
    return User::factory()->withRole($role)->create();
}

function ioaRecord(User $actor, array $overrides = []): IntakeRequest
{
    return app(RecordIntake::class)->handle($actor, [...[
        'contact_name' => 'Nguyễn Văn An',
        'contact_phone' => '0832270898',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
    ], ...$overrides], [['name' => 'Công Ty Bên Kia', 'role' => PartyRole::Defendant, 'phone' => '0977000222']])->intake;
}

function ioaRefusal(Closure $call, string $key): ?string
{
    try {
        $call();
    } catch (ValidationException $e) {
        return $e->errors()[$key][0] ?? (string) json_encode($e->errors());
    }

    return null;
}

it('refuses a tax code without the name of the organisation in the Action itself', function () {
    $refusal = ioaRefusal(fn () => ioaRecord(ioaStaff(Role::Assistant), ['organization_id_number' => '0101234567']), 'organization_name');

    expect($refusal)->not->toBeNull()
        ->and(IntakeRequest::query()->count())->toBe(0);
});

it('refuses a lead lawyer who is deactivated or cannot run a matter, whatever the screen sent', function (Closure $lead) {
    $lawyer = ioaStaff();
    $intake = ioaRecord($lawyer);
    $type = MatterType::factory()->withStages()->create(['is_active' => true]);

    $refusal = ioaRefusal(fn () => app(ConvertIntakeToMatter::class)->handle($lawyer, $intake, [
        'title' => 'Tranh chấp hợp đồng',
        'matter_type_id' => $type->id,
        'lead_lawyer_id' => $lead()->id,
        'client_role' => PartyRole::Plaintiff->value,
        'opened_at' => today()->toDateString(),
        'confidentiality' => Confidentiality::Normal->value,
        'client_type' => ClientType::Individual->value,
    ]), 'lead_lawyer_id');

    expect($refusal)->toBe(__('intake_org.errors.convert_lead_lawyer_ineligible'))
        ->and(Matter::query()->count())->toBe(0);
})->with([
    'deactivated lawyer' => [fn (): User => tap(ioaStaff(), fn (User $user) => $user->forceFill(['is_active' => false])->save())],
    'assistant' => [fn (): User => ioaStaff(Role::Assistant)],
]);

it('refuses to reopen a record that is still open', function () {
    $intake = ioaRecord(ioaStaff(Role::Assistant));

    $refusal = ioaRefusal(fn () => app(ReopenIntake::class)->handle(ioaStaff(Role::Manager), $intake, 'Mở lại để thử nghiệm'), 'reopen_reason');

    expect($refusal)->not->toBeNull()
        ->and($intake->fresh()->status)->toBe(IntakeStatus::New);
});

it('ignores a received time sent by someone who is not a manager, whatever the screen sent', function () {
    $assistant = ioaStaff(Role::Assistant);
    $intake = ioaRecord($assistant);
    $before = $intake->received_at->toIso8601String();

    $refusal = ioaRefusal(fn () => app(UpdateIntakeIdentity::class)->handle($assistant, $intake, [
        'contact_name' => 'Nguyễn Văn An', 'contact_phone' => '0832270898', 'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone, 'received_at' => now()->subDay()->toDateTimeString(),
    ], [['id' => $intake->parties()->sole()->id, 'name' => 'Công Ty Bên Kia', 'role' => PartyRole::Defendant]]), 'received_at');

    expect($refusal)->toBeNull()
        ->and($intake->fresh()->received_at->toIso8601String())->toBe($before);
});

it('refuses a received time in the future, even from a manager', function () {
    $intake = ioaRecord(ioaStaff(Role::Assistant));

    $refusal = ioaRefusal(fn () => app(UpdateIntakeIdentity::class)->handle(ioaStaff(Role::Manager), $intake, [
        'contact_name' => 'Nguyễn Văn An', 'contact_phone' => '0832270898', 'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone, 'received_at' => now()->addHour()->toDateTimeString(),
    ], [['id' => $intake->parties()->sole()->id, 'name' => 'Công Ty Bên Kia', 'role' => PartyRole::Defendant]]), 'received_at');

    expect($refusal)->toBe(__('intake.errors.received_at_in_future'));
});
