<?php

use App\Actions\Intake\AcknowledgeIntakeConflict;
use App\Actions\Intake\ConvertIntakeToMatter;
use App\Actions\Intake\MergeIntake;
use App\Actions\Intake\RecordIntake;
use App\Actions\Intake\RecordPrivacyNotice;
use App\Actions\Intake\UpdateIntakeSummary;
use App\Enums\ClientType;
use App\Enums\Confidentiality;
use App\Enums\ConflictLevel;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Exceptions\ConflictAcknowledgementRequired;
use App\Filament\Admin\Pages\ActivityLogPage;
use App\Filament\Admin\Resources\IntakeRequests\IntakeRequestResource;
use App\Filament\Admin\Resources\IntakeRequests\Pages\CreateIntakeRequest;
use App\Filament\Admin\Resources\IntakeRequests\Pages\EditIntakeRequest;
use App\Filament\Admin\Resources\IntakeRequests\Pages\ListIntakeRequests;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/*
 * Rà soát cuối M10, vòng sửa 1 — FC1 và FI1: "thấy được" đi theo CHUỖI GỘP.
 *
 * Gộp (R4) để tên, SĐT, email và câu chuyện ở lại bản nguồn, và Task 7 đã coi bản nguồn đó là "một phần
 * hồ sơ của khách" khi bản đích thành vụ việc. Trước bản sửa, luật `restricted` của Task 1
 * (`IntakeRequest::scopeVisibleTo()`/`isVisibleTo()`) chỉ đọc `matter_id` của CHÍNH bản ghi: bản đích
 * thành vụ `restricted` của luật sư khác thì biến mất, còn bản nguồn — cùng người, cùng câu chuyện — vẫn
 * hiện với trưởng phòng, với người đã ghi nó, ở danh sách, ô tìm tên, trang sửa và trang Nhật ký hệ thống.
 *
 * Mọi khẳng định đi qua màn hình (HTTP/Livewire). Dữ liệu dựng bằng đúng các Action mà màn hình gọi.
 *
 * Hàm toàn cục mang tiền tố `imv…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

function imvStaff(Role $role): User
{
    return User::factory()->withRole($role)->create();
}

/**
 * Một lần gọi đã qua cổng ô câu chuyện: thông báo đã ghi nhận, và — khi kết quả đòi (Vàng, hay bên đối
 * lập chỉ có tên) — người ghi đã xác nhận đã xem các khớp.
 *
 * @param  array<int, array<string, mixed>>|null  $parties
 */
function imvCall(User $actor, array $overrides = [], ?array $parties = null): IntakeRequest
{
    $intake = app(RecordIntake::class)->handle($actor, [...[
        'contact_name' => 'Bà Kín Đáo',
        'contact_phone' => '0832 270 898',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
    ], ...$overrides], $parties ?? [['name' => 'Ông Bên Kia', 'role' => PartyRole::Defendant, 'phone' => '0977 000 333']])->intake;

    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);

    $intake = $intake->fresh();

    if ($intake->conflict_level !== ConflictLevel::Green || ($intake->conflict_result['incomplete_parties'] ?? []) !== []) {
        app(AcknowledgeIntakeConflict::class)->handle($actor, $intake, $intake->conflict_level);
    }

    return $intake->fresh();
}

/** Chuyển `$intake` thành vụ `$confidentiality` do `$lead` phụ trách (lượt xác nhận Vàng nếu có). */
function imvConvert(User $converter, IntakeRequest $intake, User $lead, Confidentiality $confidentiality = Confidentiality::Restricted): Matter
{
    $type = MatterType::factory()->withStages()->create(['is_active' => true]);
    $attributes = [
        'title' => 'Vụ của bà Kín Đáo',
        'matter_type_id' => $type->id,
        'lead_lawyer_id' => $lead->id,
        'client_role' => PartyRole::Plaintiff->value,
        'opened_at' => today()->toDateString(),
        'confidentiality' => $confidentiality->value,
        'client_type' => ClientType::Individual->value,
    ];

    try {
        $conversion = app(ConvertIntakeToMatter::class)->handle($converter, $intake->fresh(), $attributes);
    } catch (ConflictAcknowledgementRequired $required) {
        $conversion = app(ConvertIntakeToMatter::class)->handle($converter, $intake->fresh(), $attributes, null, $required->result->level);
    }

    return $conversion->opening->matter;
}

/**
 * Trợ lý ghi lần gọi đầu A (giao cho `$lead`), nghe câu chuyện; người đó gọi lại, trợ lý ghi B (giao cho
 * luật sư chuyển đổi) và gộp A vào B (R4); luật sư chuyển B thành vụ `restricted` do `$lead` phụ trách.
 *
 * @param  array<int, array<string, mixed>>|null  $sourceParties  Bên đối lập của lần gọi đầu (mặc định: có SĐT).
 * @return array{source: IntakeRequest, target: IntakeRequest, matter: Matter, assistant: User, converter: User}
 */
function imvMergedSourceOfRestrictedConversion(User $lead, ?array $sourceParties = null): array
{
    $assistant = imvStaff(Role::Assistant);
    $converter = imvStaff(Role::Lawyer);

    $source = imvCall($assistant, ['assigned_to' => $lead->id], $sourceParties);
    app(UpdateIntakeSummary::class)->handle($assistant, $source, 'SECRET-STORY-OF-THE-FIRST-CALL');

    $target = imvCall($assistant, ['assigned_to' => $converter->id]);

    app(MergeIntake::class)->handle($assistant, $source->fresh(), $target->fresh());

    $matter = imvConvert($converter, $target, $lead);

    return [
        'source' => $source->fresh(),
        'target' => $target->fresh(),
        'matter' => $matter,
        'assistant' => $assistant,
        'converter' => $converter,
    ];
}

it('answers 404 on the merged source of a call converted into a restricted matter to the manager and to the assistant who recorded it, while the admin and the lead still open it', function () {
    $lead = imvStaff(Role::Lawyer);
    ['source' => $source, 'target' => $target, 'matter' => $matter, 'assistant' => $assistant] = imvMergedSourceOfRestrictedConversion($lead);

    expect($source->status)->toBe(IntakeStatus::Merged)
        ->and($source->merged_into_id)->toBe($target->id)
        ->and($target->matter_id)->toBe($matter->id)
        ->and($matter->confidentiality)->toBe(Confidentiality::Restricted);

    $url = IntakeRequestResource::getUrl('edit', ['record' => $source], panel: 'admin');

    foreach ([imvStaff(Role::Manager), $assistant] as $blind) {
        $this->actingAs($blind, 'web')->get($url)->assertNotFound();
    }

    // Người xem được vụ: admin, và luật sư phụ trách vụ (được giao lần gọi đầu) — trang mở, câu chuyện còn đó.
    foreach ([imvStaff(Role::Admin), $lead] as $seer) {
        $this->actingAs($seer, 'web')->get($url)->assertOk()->assertSee('SECRET-STORY-OF-THE-FIRST-CALL');
    }
});

it('drops the merged source out of the list and out of a search by name for whoever cannot view the restricted matter', function () {
    $lead = imvStaff(Role::Lawyer);
    ['source' => $source, 'target' => $target, 'assistant' => $assistant] = imvMergedSourceOfRestrictedConversion($lead);
    $manager = imvStaff(Role::Manager);
    $unrelated = imvCall($manager, ['contact_name' => 'Người Không Liên Quan', 'contact_phone' => '0901 000 999']);

    foreach ([$manager, $assistant] as $blind) {
        $this->actingAs($blind, 'web');

        $this->livewire(ListIntakeRequests::class)
            ->assertCanNotSeeTableRecords([$source, $target]);

        $this->livewire(ListIntakeRequests::class)
            ->searchTable('Kín Đáo')
            ->assertCanNotSeeTableRecords([$source, $target])
            ->assertDontSee('Bà Kín Đáo');
    }

    $this->actingAs($manager, 'web');
    $this->livewire(ListIntakeRequests::class)->assertCanSeeTableRecords([$unrelated]);

    foreach ([imvStaff(Role::Admin), $lead] as $seer) {
        $this->actingAs($seer, 'web');
        $this->livewire(ListIntakeRequests::class)
            ->searchTable('Kín Đáo')
            ->assertCanSeeTableRecords([$source]);
    }
});

/*
 * Gợi ý trùng (R4) không bao giờ đưa ra một bản ĐÃ GỘP (`FindIntakeDuplicates`, `merged_into_id` null),
 * nên bản nguồn không tới được đó cả trước bản sửa — test này ghim điều đó cho bản nguồn của một vụ
 * `restricted`: cùng số gọi lần thứ ba, người ghi không thấy mã hay tên của lần gọi nào trong chuỗi.
 */
it('never offers the merged source, nor the converted record, as a duplicate hint to someone who cannot view the restricted matter', function () {
    $lead = imvStaff(Role::Lawyer);
    ['source' => $source, 'target' => $target, 'assistant' => $assistant] = imvMergedSourceOfRestrictedConversion($lead);

    $this->actingAs($assistant, 'web');
    $this->livewire(CreateIntakeRequest::class)
        ->fillForm([
            'contact_name' => 'Bà Kín Đáo',
            'contact_phone' => '0832270898',
            'contact_role' => PartyRole::Plaintiff->value,
            'source' => IntakeSource::Phone->value,
            'privacy_notice' => true,
            'parties' => [['role' => PartyRole::Defendant->value, 'name' => 'Ai Khác', 'phone' => '0977000444', 'id_number' => null]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $third = IntakeRequest::query()->whereNotIn('id', [$source->id, $target->id])->sole();

    $this->livewire(EditIntakeRequest::class, ['record' => $third->getRouteKey()])
        ->assertDontSee($source->code)
        ->assertDontSee($target->code)
        ->assertDontSee(__('intake.duplicates.same_identity'))
        ->assertDontSee(__('intake.duplicates.hidden_same_identity'));
});

it('keeps the merged source of a call converted into a NORMAL matter visible under the R9 rules', function () {
    $assistant = imvStaff(Role::Assistant);
    $converter = imvStaff(Role::Lawyer);
    $source = imvCall($assistant);
    $target = imvCall($assistant, ['assigned_to' => $converter->id]);
    app(MergeIntake::class)->handle($assistant, $source->fresh(), $target->fresh());
    imvConvert($converter, $target, imvStaff(Role::Lawyer), Confidentiality::Normal);

    $url = IntakeRequestResource::getUrl('edit', ['record' => $source], panel: 'admin');

    foreach ([imvStaff(Role::Manager), $assistant] as $seer) {
        $this->actingAs($seer, 'web')->get($url)->assertOk();
    }
});

it('follows a merge chain of two links: the first call merged into a second one merged into the converted record is hidden too, in SQL and in memory alike', function () {
    $lead = imvStaff(Role::Lawyer);
    $assistant = imvStaff(Role::Assistant);
    $converter = imvStaff(Role::Lawyer);
    $manager = imvStaff(Role::Manager);
    $admin = imvStaff(Role::Admin);

    $first = imvCall($assistant, ['assigned_to' => $lead->id]);
    $second = imvCall($assistant, ['assigned_to' => $lead->id]);
    app(MergeIntake::class)->handle($assistant, $first->fresh(), $second->fresh());
    $third = imvCall($assistant, ['assigned_to' => $converter->id]);
    app(MergeIntake::class)->handle($assistant, $second->fresh(), $third->fresh());
    imvConvert($converter, $third, $lead);

    $first = $first->fresh();
    expect($first->merged_into_id)->toBe($second->id);

    $this->actingAs($manager, 'web')
        ->get(IntakeRequestResource::getUrl('edit', ['record' => $first], panel: 'admin'))
        ->assertNotFound();

    foreach ([$manager, $assistant, $converter, $lead, $admin] as $user) {
        $sql = IntakeRequest::query()->visibleTo($user)->whereKey([$first->id, $second->id])->pluck('id')->sort()->values()->all();
        $memory = collect([$first, $second->fresh()])->filter(fn (IntakeRequest $r): bool => $r->isVisibleTo($user))->pluck('id')->sort()->values()->all();

        expect($memory)->toBe($sql, "{$user->position->value}");
    }

    expect($first->isVisibleTo($manager))->toBeFalse()
        ->and($first->isVisibleTo($assistant))->toBeFalse()
        ->and($first->isVisibleTo($lead))->toBeTrue()
        ->and($first->isVisibleTo($admin))->toBeTrue();
});

/*
 * FI1 — trang Nhật ký hệ thống. Dòng có chủ thể là một bản ghi tiếp nhận (`conflict_check_run` mang tên
 * người liên hệ và các bên, `intake_recorded`, `intake_merged`…) chỉ hiện cho người xem được CHÍNH bản
 * ghi đó — cùng định nghĩa "thấy được" của bản ghi, nên bản đã chuyển thành vụ `restricted` và bản đã gộp
 * vào nó biến mất với trưởng phòng không phụ trách vụ.
 */
it('hides from a manager outside the restricted matter the system log rows of the converted record and of its merged source, and shows them to the admin and to a manager who leads the matter', function () {
    $managerLead = imvStaff(Role::Manager);
    // Bên đối lập của lần gọi đầu chỉ có tên: dòng kiểm tra của nó mang tên đó ("thiếu định danh").
    ['source' => $source, 'target' => $target] = imvMergedSourceOfRestrictedConversion($managerLead, [
        ['name' => 'Ông Bên Kia', 'role' => PartyRole::Defendant],
    ]);
    $manager = imvStaff(Role::Manager);

    $rows = Activity::query()
        ->where('subject_type', 'intake_request')
        ->whereIn('subject_id', [$source->id, $target->id])
        ->get();
    $checkRows = $rows->where('event', 'conflict_check_run');

    expect($checkRows->where('subject_id', $source->id))->not->toBeEmpty()
        ->and($checkRows->where('subject_id', $target->id))->not->toBeEmpty()
        ->and(json_encode($checkRows->where('subject_id', $source->id)->first()->properties, JSON_UNESCAPED_UNICODE))->toContain('Ông Bên Kia');

    expect(Activity::query()->count())->toBeLessThanOrEqual(100);

    // Một trang 100 dòng: mọi dòng của bài này nằm trên trang đầu, nên "không thấy" không phải vì phân trang.
    $this->actingAs($manager, 'web');
    $component = $this->livewire(ActivityLogPage::class)
        ->set('tableRecordsPerPage', 100)
        ->assertCanNotSeeTableRecords($rows->all());

    try {
        $component->mountTableAction('viewProperties', $checkRows->where('subject_id', $source->id)->first());
    } catch (Throwable) {
        // Filament không tìm thấy dòng trong truy vấn bảng — cũng là một lần từ chối.
    }

    expect($component->instance()->getMountedActions())->toBe([]);

    foreach ([imvStaff(Role::Admin), $managerLead] as $seer) {
        $this->actingAs($seer, 'web');
        $opened = $this->livewire(ActivityLogPage::class)
            ->set('tableRecordsPerPage', 100)
            ->assertCanSeeTableRecords($rows->all())
            ->mountTableAction('viewProperties', $checkRows->where('subject_id', $source->id)->first());

        expect((string) $opened->instance()->getMountedActions()[0]->getModalContent())->toContain('Ông Bên Kia');
    }
});

it('keeps listing for a manager the system log rows of an intake that is still open, or that became a normal matter', function () {
    $manager = imvStaff(Role::Manager);
    $lawyer = imvStaff(Role::Lawyer);

    $open = imvCall($lawyer, ['contact_phone' => '0901 000 111']);
    $converted = imvCall($lawyer, ['contact_phone' => '0901 000 222']);
    imvConvert($lawyer, $converted, $lawyer, Confidentiality::Normal);

    $rows = Activity::query()
        ->where('subject_type', 'intake_request')
        ->whereIn('subject_id', [$open->id, $converted->id])
        ->get();

    expect($rows->pluck('subject_id')->unique()->sort()->values()->all())->toBe([$open->id, $converted->id]);

    $this->actingAs($manager, 'web');
    $this->livewire(ActivityLogPage::class)->set('tableRecordsPerPage', 100)->assertCanSeeTableRecords($rows->all());
});

/*
 * Migration của cột (`2026_10_04_000001_…`) điền ngược cho các chuỗi đã chuyển đổi TRƯỚC khi có cột:
 * đi cây ngược của `merged_into_id` từ mỗi bản có `matter_id`. Đo bước điền ngược của chính migration
 * trên dữ liệu thật (vòng `migrate:reset` → `migrate` chạy trên MariaDB thật, báo cáo vòng sửa).
 */
it('fills the merge-chain matter of chains converted before the column existed when the migration runs', function () {
    $lead = imvStaff(Role::Lawyer);
    $assistant = imvStaff(Role::Assistant);
    $converter = imvStaff(Role::Lawyer);

    $first = imvCall($assistant, ['assigned_to' => $lead->id]);
    $second = imvCall($assistant, ['assigned_to' => $lead->id]);
    app(MergeIntake::class)->handle($assistant, $first->fresh(), $second->fresh());
    $third = imvCall($assistant, ['assigned_to' => $converter->id]);
    app(MergeIntake::class)->handle($assistant, $second->fresh(), $third->fresh());
    $matter = imvConvert($converter, $third, $lead);
    $unrelated = imvCall($assistant, ['contact_phone' => '0901 000 777']);

    // Như trước khi có cột: chưa bản nào mang dấu.
    expect(IntakeRequest::query()->whereKey([$first->id, $second->id])->whereNotNull('merge_chain_matter_id')->count())->toBe(2);
    DB::table('intake_requests')->update(['merge_chain_matter_id' => null]);

    $migration = require database_path('migrations/2026_10_04_000001_add_merge_chain_matter_id_to_intake_requests_table.php');
    $migration->backfill();

    $stamps = IntakeRequest::query()->whereKey([$first->id, $second->id, $third->id, $unrelated->id])
        ->pluck('merge_chain_matter_id', 'id')
        ->map(fn (mixed $id): ?int => $id === null ? null : (int) $id)
        ->all();

    expect($stamps)->toBe([
        $first->id => $matter->id,
        $second->id => $matter->id,
        $third->id => null,
        $unrelated->id => null,
    ]);

    $this->actingAs(imvStaff(Role::Manager), 'web')
        ->get(IntakeRequestResource::getUrl('edit', ['record' => $first], panel: 'admin'))
        ->assertNotFound();
});
