<?php

use App\Actions\Matter\UpdateMatterDetails;
use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * `intake/intake-06`, `spec-gap/spec-gap-06` (M6.5 Task 5): sửa được title, summary_for_client,
 * court_name, case_number sau khi mở vụ việc — trước Task 5 không có Action hay trang nào làm
 * được việc này. Audit ghi TÊN các trường đã đổi (R14), không ghi giá trị thô.
 */
it('updates the editable fields and records which fields changed on the audit trail', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'title' => 'Tiêu đề cũ',
        'court_name' => null,
        'case_number' => null,
    ]);

    $updated = app(UpdateMatterDetails::class)->handle($matter, $lawyer, [
        'title' => 'Tiêu đề mới sau khi sửa',
        'summary_for_client' => 'Tóm tắt mới cho khách',
        'court_name' => 'Toà án nhân dân quận 1',
        'case_number' => '12/2026/DS-ST',
    ]);

    expect($updated->title)->toBe('Tiêu đề mới sau khi sửa')
        ->and($updated->court_name)->toBe('Toà án nhân dân quận 1')
        ->and($updated->case_number)->toBe('12/2026/DS-ST')
        ->and($matter->fresh()->title)->toBe('Tiêu đề mới sau khi sửa');

    $activity = Activity::query()->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer?->is($lawyer))->toBeTrue()
        ->and($activity->properties->get('changed_fields'))->toEqualCanonicalizing([
            'title', 'summary_for_client', 'court_name', 'case_number',
        ]);
});

/** Cặp âm: không đổi gì thì không có dòng audit "đã sửa" nào bị ghi thêm. */
it('does not write an audit entry when nothing actually changed', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'title' => 'Tiêu đề giữ nguyên',
    ]);

    Activity::query()->delete();

    app(UpdateMatterDetails::class)->handle($matter, $lawyer, [
        'title' => 'Tiêu đề giữ nguyên',
    ]);

    expect(Activity::query()->where('event', 'matter_details_updated')->count())->toBe(0);
});

/**
 * R5: đổi confidentiality đòi matter.update VÀ không phải trợ lý. Trợ lý bị từ chối, kể cả khi
 * họ đứng trong đội ngũ và có matter.update.
 */
it('refuses an assistant who tries to change confidentiality', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'confidentiality' => Confidentiality::Normal,
    ]);
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    expect(fn () => app(UpdateMatterDetails::class)->handle($matter, $assistant, [
        'title' => $matter->title,
        'confidentiality' => Confidentiality::Restricted->value,
    ]))->toThrow(AuthorizationException::class);

    expect($matter->fresh()->confidentiality)->toBe(Confidentiality::Normal);
});

/** Cặp dương: cùng vụ việc, cùng thay đổi, nhưng do luật sư phụ trách (không phải trợ lý) thực hiện — phải qua. */
it('lets the lead lawyer change confidentiality', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'confidentiality' => Confidentiality::Normal,
    ]);

    app(UpdateMatterDetails::class)->handle($matter, $lawyer, [
        'title' => $matter->title,
        'confidentiality' => Confidentiality::Restricted->value,
    ]);

    expect($matter->fresh()->confidentiality)->toBe(Confidentiality::Restricted);
});

/**
 * Trợ lý VẪN sửa được title/summary_for_client/court_name/case_number — R5 nói "hạn chế" nghĩa
 * là mọi việc TRỪ các quyết định đưa gì ra cho khách và cấu trúc vụ việc; nó không cấm mọi việc
 * sửa. Cặp dương này giữ cho test trên không bị đọc nhầm là "trợ lý không sửa được gì".
 */
it('lets an assistant edit ordinary fields without touching confidentiality', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    app(UpdateMatterDetails::class)->handle($matter, $assistant, [
        'title' => 'Sửa bởi trợ lý',
    ]);

    expect($matter->fresh()->title)->toBe('Sửa bởi trợ lý');
});

/** client_id, matter_type_id và lead_lawyer_id không sửa được qua Action này, dù $data mang chúng. */
it('never changes client_id, matter_type_id, or lead_lawyer_id even if the caller supplies them', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $otherLawyer = User::factory()->withRole(Role::Lawyer)->create();
    $originalClient = Client::factory()->create();
    $otherClient = Client::factory()->create();
    $originalType = MatterType::factory()->withStages()->create();
    $otherType = MatterType::factory()->withStages()->create();

    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'client_id' => $originalClient->id,
        'matter_type_id' => $originalType->id,
    ]);

    app(UpdateMatterDetails::class)->handle($matter, $lawyer, [
        'title' => $matter->title,
        'client_id' => $otherClient->id,
        'matter_type_id' => $otherType->id,
        'lead_lawyer_id' => $otherLawyer->id,
    ]);

    $fresh = $matter->fresh();

    expect($fresh->client_id)->toBe($originalClient->id)
        ->and($fresh->matter_type_id)->toBe($originalType->id)
        ->and($fresh->lead_lawyer_id)->toBe($lawyer->id);
});

/** Cổng thật: một người ngoài đội ngũ, không thấy được vụ việc, bị từ chối hoàn toàn. */
it('refuses an outsider who cannot view the matter at all', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create();

    expect(fn () => app(UpdateMatterDetails::class)->handle($matter, $outsider, [
        'title' => 'Không được phép',
    ]))->toThrow(AuthorizationException::class);
});
