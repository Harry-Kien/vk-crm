<?php

use App\Actions\Storage\RecordDataTransferDossier;
use App\Enums\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Storage\TransferDossier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| M14 Task 5 — `RecordDataTransferDossier`: hợp đồng của Action (R13)
|--------------------------------------------------------------------------
|
| Hành vi MÀN HÌNH đo ở `tests/Feature/Filament/DocumentStorePageTest.php`. Ở đây chỉ những điều
| Action tự giữ cho mọi đường gọi, kể cả đường không qua form: quyền với actor tường minh, dạng ngày,
| độ dài, căn cứ đi cùng ngày ý kiến, và ô trống nghĩa là xoá.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withRole(Role::Admin)->create();
});

it('người không có settings.manage bị từ chối, không ghi gì', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    expect(fn () => app(RecordDataTransferDossier::class)->handle($lawyer, ['transfer_dossier_on' => '2026-10-02']))
        ->toThrow(AuthorizationException::class);

    expect(Setting::query()->count())->toBe(0);
});

it('ngày không đúng dạng Y-m-d bị từ chối, không ghi gì', function (string $field, string $value) {
    expect(fn () => app(RecordDataTransferDossier::class)->handle($this->admin, [$field => $value]))
        ->toThrow(ValidationException::class);

    expect(Setting::query()->count())->toBe(0);
})->with([
    'dd/mm/yyyy' => ['transfer_dossier_on', '02/10/2026'],
    'ngày không có thật' => ['dpa_accepted_on', '2026-02-30'],
    'chữ' => ['transfer_before_dossier_on', 'hom qua'],
]);

it('mã hồ sơ quá 100 ký tự (mb_strlen) và căn cứ quá 200 ký tự bị từ chối', function (string $field, int $length) {
    $input = [$field => str_repeat('ệ', $length)];

    if ($field === 'transfer_before_dossier_basis') {
        $input['transfer_before_dossier_on'] = '2026-09-29';
    }

    expect(fn () => app(RecordDataTransferDossier::class)->handle($this->admin, $input))
        ->toThrow(ValidationException::class);
})->with([
    'mã 101' => ['transfer_dossier_reference', 101],
    'căn cứ 201' => ['transfer_before_dossier_basis', 201],
]);

it('đúng 100 và 200 ký tự có dấu thì nhận', function () {
    app(RecordDataTransferDossier::class)->handle($this->admin, [
        'transfer_dossier_reference' => str_repeat('ệ', 100),
        'transfer_before_dossier_on' => '2026-09-29',
        'transfer_before_dossier_basis' => str_repeat('ệ', 200),
    ]);

    expect(mb_strlen(Setting::query()->where('key', TransferDossier::KEYS['transfer_before_dossier_basis'])->value('value')))->toBe(200);
});

it('ngày ý kiến luật sư mà thiếu căn cứ bị từ chối', function () {
    expect(fn () => app(RecordDataTransferDossier::class)->handle($this->admin, ['transfer_before_dossier_on' => '2026-09-29']))
        ->toThrow(ValidationException::class);
});

it('ô trống xoá giá trị đã lưu và audit nêu đúng trường đó; khoá vắng mặt giữ nguyên', function () {
    $action = app(RecordDataTransferDossier::class);

    $action->handle($this->admin, ['transfer_dossier_on' => '2026-10-02', 'dpa_accepted_on' => '2026-09-30']);
    $changed = $action->handle($this->admin, ['transfer_dossier_on' => '  ']);

    expect($changed)->toBe(['transfer_dossier_on'])
        ->and(TransferDossier::current()->dossierOn())->toBeNull()
        ->and(TransferDossier::current()->dpaAcceptedOn()?->toDateString())->toBe('2026-09-30')
        ->and(Activity::query()->where('event', 'data_transfer_dossier_recorded')->count())->toBe(2);
});
