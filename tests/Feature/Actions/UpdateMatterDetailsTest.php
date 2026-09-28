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
use Illuminate\Validation\ValidationException;
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
 * Fix round 1, finding I2: đổi confidentiality CHỈ dành cho lead của CHÍNH vụ việc này hoặc
 * admin — không còn "matter.update VÀ không phải trợ lý" của bản đầu. Trợ lý (không lead, không
 * admin) bị từ chối, kể cả khi đứng trong đội ngũ và có matter.update. Từ chối bằng
 * `ValidationException` gắn vào ô `confidentiality` (không phải `AuthorizationException` chung
 * chung) — Action tự dịch nó ngay tại nơi phát hiện, để `EditMatter` không phải đoán ô nào gây
 * lỗi (xem docblock lớp và fix round 1, finding minor "EditMatter.php:119").
 */
it('refuses an assistant who tries to change confidentiality', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'confidentiality' => Confidentiality::Normal,
    ]);
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    try {
        app(UpdateMatterDetails::class)->handle($matter, $assistant, [
            'title' => $matter->title,
            'confidentiality' => Confidentiality::Restricted->value,
        ]);

        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('confidentiality');
    }

    expect($matter->fresh()->confidentiality)->toBe(Confidentiality::Normal);
});

/** Cặp dương: cùng vụ việc, cùng thay đổi, nhưng do luật sư phụ trách — phải qua, không có thành viên nào khác để chặn. */
it('lets the lead lawyer switch confidentiality to restricted when no other team member is on it', function () {
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
 * Fix round 1, finding I2: chuyển sang `restricted` bị từ chối khi còn một thành viên KHÁC lead
 * và admin trong đội ngũ — người đó sẽ hết thấy được vụ việc ngay sau khi đổi
 * (`Matter::isListableBy()`, nhánh restricted). Thông điệp nêu tên người đó bằng tiếng Việt.
 */
it('refuses the lead switching to restricted while an assistant remains on the team', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Ngọc Anh']);
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'confidentiality' => Confidentiality::Normal,
    ]);
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    try {
        app(UpdateMatterDetails::class)->handle($matter, $lawyer, [
            'title' => $matter->title,
            'confidentiality' => Confidentiality::Restricted->value,
        ]);

        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['confidentiality'][0] ?? '')->toContain('Trợ lý Ngọc Anh');
    }

    expect($matter->fresh()->confidentiality)->toBe(Confidentiality::Normal);
});

/**
 * Cặp dương của test trên: gỡ trợ lý ra khỏi đội ngũ trước (đúng đường Task 3 chỉ — tab Đội ngũ),
 * rồi lead chuyển sang restricted vẫn được, dù đội ngũ TỪNG có thành viên khác.
 */
it('lets the lead switch to restricted once the other member has been removed from the team first', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'confidentiality' => Confidentiality::Normal,
    ]);
    $matter->addTeamMember($assistant, MatterRole::Assistant);
    $matter->team()->detach($assistant->id);

    app(UpdateMatterDetails::class)->handle($matter, $lawyer, [
        'title' => $matter->title,
        'confidentiality' => Confidentiality::Restricted->value,
    ]);

    expect($matter->fresh()->confidentiality)->toBe(Confidentiality::Restricted);
});

/**
 * Fix round 1, finding I2: một admin đứng trong đội ngũ (ví dụ vai `observer`) KHÔNG chặn việc
 * chuyển sang restricted — chính admin đó vẫn thấy được vụ hạn chế qua nhánh `isListableBy()`
 * riêng cho admin, không cần đứng tên lead.
 */
it('does not count an admin team member as blocking the switch to restricted', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $adminObserver = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'confidentiality' => Confidentiality::Normal,
    ]);
    $matter->addTeamMember($adminObserver, MatterRole::Observer);

    app(UpdateMatterDetails::class)->handle($matter, $lawyer, [
        'title' => $matter->title,
        'confidentiality' => Confidentiality::Restricted->value,
    ]);

    expect($matter->fresh()->confidentiality)->toBe(Confidentiality::Restricted);
});

/**
 * Fix round 1, finding I2: chuyển NGƯỢC LẠI (`restricted` → `normal`) không bị chặn bởi đội ngũ —
 * luật chỉ áp cho chiều chuyển VÀO `restricted`, và một admin không phải lead vẫn đổi được.
 */
it('lets an admin switch confidentiality back to normal, even with other team members present', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->restricted()->create(['lead_lawyer_id' => $lawyer->id]);
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    app(UpdateMatterDetails::class)->handle($matter, $admin, [
        'title' => $matter->title,
        'confidentiality' => Confidentiality::Normal->value,
    ]);

    expect($matter->fresh()->confidentiality)->toBe(Confidentiality::Normal);
});

/**
 * Trợ lý VẪN sửa được title/court_name/case_number — R5 nói "hạn chế" nghĩa là mọi việc TRỪ các
 * quyết định đưa gì ra cho khách và cấu trúc vụ việc; nó không cấm mọi việc sửa. Cặp dương này
 * giữ cho các test từ chối ở trên không bị đọc nhầm là "trợ lý không sửa được gì".
 */
it('lets an assistant edit ordinary fields without touching confidentiality or summary_for_client', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    app(UpdateMatterDetails::class)->handle($matter, $assistant, [
        'title' => 'Sửa bởi trợ lý',
    ]);

    expect($matter->fresh()->title)->toBe('Sửa bởi trợ lý');
});

// =========================================================================================
// Fix round 1, finding I1: summary_for_client đòi stageLog.publish, không chỉ matter.update.
// =========================================================================================

it('refuses an assistant who tries to change summary_for_client', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'summary_for_client' => 'Tóm tắt cũ',
    ]);
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    try {
        app(UpdateMatterDetails::class)->handle($matter, $assistant, [
            'title' => $matter->title,
            'summary_for_client' => 'Trợ lý cố sửa tóm tắt',
        ]);

        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('summary_for_client');
    }

    expect($matter->fresh()->summary_for_client)->toBe('Tóm tắt cũ');
});

/** Cặp dương: một luật sư (có stageLog.publish, không cần là lead) đổi được summary_for_client. */
it('lets a lawyer with stageLog.publish change summary_for_client', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'summary_for_client' => 'Tóm tắt cũ',
    ]);

    app(UpdateMatterDetails::class)->handle($matter, $lawyer, [
        'title' => $matter->title,
        'summary_for_client' => 'Tóm tắt mới cho khách',
    ]);

    expect($matter->fresh()->summary_for_client)->toBe('Tóm tắt mới cho khách');
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
