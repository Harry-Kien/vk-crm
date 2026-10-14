<?php

use App\Actions\Matter\UpdateMatterDetails;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\EditMatter;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
 * Làn fm, mục A3 (kiểm tra nghiệp vụ 2026-10-09): "Ghi chú nội bộ" nhập lúc mở vụ phải đọc lại được
 * trên tab Tổng quan và sửa được trên trang Sửa; "Ngày mở hồ sơ" gõ nhầm cũng phải sửa được — nhưng
 * không sau hôm nay và không sau ngày kết thúc.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

function fmEditFormData(Matter $matter, array $overrides = []): array
{
    return [
        'title' => $matter->title,
        'summary_for_client' => $matter->summary_for_client,
        'court_name' => $matter->court_name,
        'case_number' => $matter->case_number,
        'confidentiality' => $matter->confidentiality->value,
        'description_internal' => $matter->description_internal,
        'opened_at' => $matter->opened_at->toDateString(),
        ...$overrides,
    ];
}

it('shows the internal note on the overview tab, marked internal only', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'description_internal' => 'Chiến lược: đề nghị hoà giải trước, giữ chứng cứ chuyển khoản làm át chủ bài.',
    ]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->assertSee('Chiến lược: đề nghị hoà giải trước, giữ chứng cứ chuyển khoản làm át chủ bài.')
        ->assertSee(__('lifecycle.details.internal_only'));
});

it('edits the internal note and the opening date, and logs which fields changed', function () {
    Carbon::setTestNow('2026-10-09 10:00:00');
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'opened_at' => '2026-10-05']);

    $this->actingAs($lawyer, 'web');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->fillForm(fmEditFormData($matter, [
            'description_internal' => 'Đã sửa: khách đồng ý hoà giải.',
            'opened_at' => '2026-09-28',
        ]))
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $matter->fresh();

    expect($fresh->description_internal)->toBe('Đã sửa: khách đồng ý hoà giải.')
        ->and($fresh->opened_at->toDateString())->toBe('2026-09-28');

    $activity = Activity::query()->where('event', 'matter_details_updated')->latest('id')->first();

    expect($activity->properties->get('changed_fields'))->toEqualCanonicalizing(['description_internal', 'opened_at'])
        // Nội dung ghi chú nội bộ không vào nhật ký.
        ->and(json_encode($activity->properties->all(), JSON_UNESCAPED_UNICODE))->not->toContain('hoà giải');
});

it('refuses an opening date in the future', function () {
    Carbon::setTestNow('2026-10-09 10:00:00');
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'opened_at' => '2026-10-05']);

    $this->actingAs($lawyer, 'web');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->fillForm(fmEditFormData($matter, ['opened_at' => '2026-10-10']))
        ->call('save')
        ->assertHasFormErrors(['opened_at']);

    expect($matter->fresh()->opened_at->toDateString())->toBe('2026-10-05');
});

it('refuses an opening date after the closing date, and accepts the closing day itself', function () {
    Carbon::setTestNow('2026-10-09 10:00:00');
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->atStage('closed')->create(['opened_at' => '2026-08-01', 'closed_at' => '2026-09-15']);

    $this->actingAs($admin, 'web');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->fillForm(fmEditFormData($matter, ['opened_at' => '2026-09-16']))
        ->call('save')
        ->assertHasFormErrors(['opened_at']);

    expect($matter->fresh()->opened_at->toDateString())->toBe('2026-08-01');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->fillForm(fmEditFormData($matter, ['opened_at' => '2026-09-15']))
        ->call('save')
        ->assertHasNoFormErrors();

    expect($matter->fresh()->opened_at->toDateString())->toBe('2026-09-15');
});

/**
 * `maxDate()` trên ô ngày chỉ là tiện lợi; cổng thật ở Action (API công khai: MCP, lệnh, job không
 * đi qua DatePicker). Ngày tương lai và chuỗi hỏng (kể cả một ngày không có thật) bị từ chối trên
 * đúng trường `opened_at`.
 */
it('refuses a future or malformed opening date in the action itself', function (string $value, string $key) {
    Carbon::setTestNow('2026-10-09 10:00:00');
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'opened_at' => '2026-10-05']);

    try {
        app(UpdateMatterDetails::class)->handle($matter, $lawyer, ['opened_at' => $value]);
        $this->fail('Không ném ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['opened_at' => [__("lifecycle.details.{$key}")]]);
    }

    expect($matter->fresh()->opened_at->toDateString())->toBe('2026-10-05');
})->with([
    'ngày mai' => ['2026-10-10', 'opened_at_future'],
    'ngày không có thật' => ['2026-02-31', 'opened_at_invalid'],
    'chuỗi hỏng' => ['hôm qua', 'opened_at_invalid'],
]);
