<?php

use App\Actions\Intake\ConvertIntakeToMatter;
use App\Actions\Intake\DeclineIntake;
use App\Actions\Intake\MergeIntake;
use App\Actions\Intake\RecordIntake;
use App\Actions\Intake\RecordPrivacyNotice;
use App\Actions\Intake\RerunIntakeConflictCheck;
use App\Actions\Intake\UpdateIntakeSummary;
use App\Enums\ConflictLevel;
use App\Enums\ConflictMatchTier;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\IntakeRequests\Pages\ConvertIntakeRequest;
use App\Filament\Admin\Resources\IntakeRequests\Pages\EditIntakeRequest;
use App\Filament\Admin\Resources\IntakeRequests\Pages\ListIntakeRequests;
use App\Models\Client;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;

/*
 * Rà soát cuối M10, vòng sửa 1 — các màn hình đọc trạng thái "Đỏ chờ xử lý" và phần còn lại của R1/R8
 * trên trang tiếp nhận, qua Livewire:
 *  - FI2: MỘT định nghĩa "bị khoá như một cuộc gọi lại" cho cổng ô câu chuyện và cho chuyển đổi — bản
 *    ghi kiểm tra Xanh TRƯỚC khi lần gọi kia của cùng người ra Đỏ bị khoá ngay, không đợi "Kiểm tra lại";
 *    và trưởng phòng mở lần gọi đang khoá thấy mã các lần gọi nó giữ;
 *  - FI5: cột "Kết quả kiểm tra", khối kiểm tra và bộ lọc "Đỏ chờ trưởng phòng xử lý" đọc cùng định
 *    nghĩa đó, không đọc mức của lần chạy gần nhất;
 *  - FI6 (R1 nguyên văn): với khớp mức đỏ, người không xử lý được Đỏ chỉ thấy mã hồ sơ và vai;
 *  - FI3 (R8): lý do từ chối — mọi lý do — chỉ cho người qua `viewConflictReason` (và người đã từ chối),
 *    nên "không có dòng lý do" không còn nghĩa là "từ chối vì xung đột";
 *  - FI4: thông báo "đã chuyển" không nêu mã một vụ `restricted` mà người bấm không còn xem được.
 *
 * Hàm toàn cục mang tiền tố `ics…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

function icsStaff(Role $role = Role::Assistant): User
{
    return User::factory()->withRole($role)->create();
}

/** Khách hiện hữu mang số 0912000111, là khách của văn phòng trong một vụ lĩnh vực "Lĩnh Vực Kín". */
function icsExistingClient(): Matter
{
    $client = Client::factory()->create(['phone' => '0912000111', 'id_number' => null, 'name' => 'Khách Hiện Hữu Thật']);
    $matter = Matter::factory()->create([
        'client_id' => $client->id,
        'matter_type_id' => MatterType::factory()->withStages()->create(['name' => 'Lĩnh Vực Kín'])->id,
    ]);
    MatterParty::factory()->for($matter)->ourClient($client)->create();

    return $matter;
}

/**
 * Một lần gọi của "Bà Gọi Lại" (0832 270 898, nguyên đơn) đã ghi nhận thông báo.
 *
 * @param  array<int, array<string, mixed>>  $parties
 */
function icsCall(User $actor, array $parties, array $overrides = []): IntakeRequest
{
    $intake = app(RecordIntake::class)->handle($actor, [...[
        'contact_name' => 'Bà Gọi Lại',
        'contact_phone' => '0832 270 898',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
    ], ...$overrides], $parties)->intake;

    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);

    return $intake->fresh();
}

/**
 * Kịch bản của FI2 (probe 3 của rà soát cuối): lần gọi B ghi trước, Xanh, câu chuyện mở và đã ghi; về
 * sau CÙNG người (cùng SĐT, cùng vai) gọi lại, trợ lý khác ghi A nêu tên một khách hiện hữu — A ra Đỏ.
 *
 * @return array{a: IntakeRequest, b: IntakeRequest, assistant: User}
 */
function icsGreenThenSameCallerRed(): array
{
    $assistant = icsStaff();
    icsExistingClient();

    $b = icsCall($assistant, [['name' => 'Ông Chồng Cũ', 'role' => PartyRole::Defendant, 'phone' => '0977 000 333']]);
    app(UpdateIntakeSummary::class)->handle($assistant, $b, 'CÂU-CHUYỆN-LẦN-ĐẦU');

    $a = icsCall(icsStaff(), [['name' => 'Bị Đơn Là Khách', 'role' => PartyRole::Defendant, 'phone' => '0912000111']]);

    expect($b->fresh()->conflict_level)->toBe(ConflictLevel::Green)
        ->and($b->fresh()->conflict_red_pending_since)->toBeNull()
        ->and($a->conflict_level)->toBe(ConflictLevel::Red)
        ->and(collect($a->conflict_result['matches'])->pluck('matter_code'))->not->toContain($b->code);

    return ['a' => $a, 'b' => $b->fresh(), 'assistant' => $assistant];
}

function icsEdit(IntakeRequest $intake)
{
    return test()->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()]);
}

// ------------------------------------------------------------------------------------------- FI2

it('locks the story of a call checked green once a later call of the same caller turns red, without waiting for a re-run', function () {
    ['b' => $b, 'assistant' => $assistant] = icsGreenThenSameCallerRed();

    expect(ConvertIntakeToMatter::refusal($b))->toBe(__('intake.errors.convert_caller_locked'));

    $this->actingAs($assistant, 'web');
    icsEdit($b)
        ->assertSee(__('enums.intake_summary_blocker.conflict_red'))
        ->assertFormFieldIsDisabled('summary')
        ->set('data.summary', 'CÂU-CHUYỆN-LẦN-ĐẦU và thêm chi tiết nghe sau khi lần kia ra Đỏ')
        ->callAction(TestAction::make('saveSummary')->schemaComponent('storyActions'))
        ->assertHasErrors(['data.summary']);

    expect($b->fresh()->summary)->toBe('CÂU-CHUYỆN-LẦN-ĐẦU');
});

it('lets a manager resolve the held call from its own page, which opens its story while the red call stays locked', function () {
    ['a' => $a, 'b' => $b, 'assistant' => $assistant] = icsGreenThenSameCallerRed();
    $resolveRed = TestAction::make('resolveRed')->schemaComponent('checkActions');

    $this->actingAs($assistant, 'web');
    icsEdit($b)->assertActionDoesNotExist($resolveRed);

    $this->actingAs(icsStaff(Role::Manager), 'web');
    icsEdit($b)
        ->assertActionVisible($resolveRed)
        ->callAction($resolveRed, data: ['override_reason' => 'Đã gọi xác minh: bà kiện chồng cũ, không liên quan khách hiện hữu.'])
        ->assertHasNoErrors();

    $this->actingAs($assistant, 'web');
    icsEdit($b)->assertSee(__('intake.gate.open'))->assertFormFieldIsEnabled('summary');

    expect($a->fresh()->hasUnresolvedRed())->toBeTrue();

    // Bản đã được xử lý thôi là "lần gọi bị giữ" trên trang lần gọi Đỏ.
    $this->actingAs(icsStaff(Role::Manager), 'web');
    icsEdit($a)->assertDontSee(__('intake.check.held_repeat_calls'))->assertDontSee($b->code);
});

it('shows a manager, on the red call, the codes of the other calls of the same caller it holds — and not the assistant', function () {
    ['a' => $a, 'b' => $b] = icsGreenThenSameCallerRed();
    $unrelated = icsCall(icsStaff(), [['name' => 'Ai Đó', 'role' => PartyRole::Defendant, 'phone' => '0977 000 444']], [
        'contact_name' => 'Người Khác Hẳn', 'contact_phone' => '0901 222 333',
    ]);
    // Cùng số máy nhưng KHÁC vai (người nhà chung máy): tự nó Đỏ chờ, nhưng không phải lần gọi mà A giữ.
    $otherRole = icsCall(icsStaff(), [['name' => 'Nguyên Đơn Là Khách', 'role' => PartyRole::Plaintiff, 'phone' => '0912000111']], [
        'contact_name' => 'Ông Chung Máy', 'contact_role' => PartyRole::Defendant,
    ]);

    expect($otherRole->awaitsConflictResolution())->toBeTrue();

    $this->actingAs(icsStaff(Role::Manager), 'web');
    icsEdit($a)
        ->assertSee(__('intake.check.held_repeat_calls'))
        ->assertSee($b->code)
        ->assertDontSee($unrelated->code)
        ->assertDontSee($otherRole->code);

    // Trang của chính lần gọi bị giữ không nói nó "giữ" lần gọi Đỏ.
    icsEdit($b)->assertDontSee(__('intake.check.held_repeat_calls'));

    // Người ghi lần gọi Đỏ (trợ lý) không có dòng đó: chỉ người xử lý được Đỏ cần biết — kể cả khi trợ lý
    // đó xem được lần gọi bị giữ (được giao nó).
    $recorderOfA = User::query()->findOrFail($a->created_by);
    $this->actingAs($recorderOfA, 'web');
    icsEdit($a)
        ->assertDontSee(__('intake.check.held_repeat_calls'))
        ->assertDontSee($b->code);

    $b->forceFill(['assigned_to' => $recorderOfA->id])->saveQuietly();

    expect($b->fresh()->isVisibleTo($recorderOfA))->toBeTrue();

    icsEdit($a)
        ->assertDontSee(__('intake.check.held_repeat_calls'))
        ->assertDontSee($b->code);

    // Không có lần gọi nào bị giữ: không có dòng đó, kể cả với trưởng phòng.
    $this->actingAs(icsStaff(Role::Manager), 'web');
    icsEdit($unrelated)->assertDontSee(__('intake.check.held_repeat_calls'));
});

// ------------------------------------------------------------------------------------------- FI5

it('shows the held call and a sticky red as red pending in the list, the check box and the "red pending" filter — never as green', function () {
    ['a' => $a, 'b' => $b] = icsGreenThenSameCallerRed();
    $assistant = icsStaff();

    // Đỏ DÍNH: bản ghi ra Đỏ, gỡ bên đối lập rồi chạy lại ra Xanh — Đỏ vẫn chờ.
    $sticky = icsCall($assistant, [['name' => 'Bị Đơn Là Khách', 'role' => PartyRole::Defendant, 'phone' => '0912000111']], [
        'contact_name' => 'Ông Dính', 'contact_phone' => '0901 555 666',
    ]);
    $sticky->parties()->delete();
    app(RerunIntakeConflictCheck::class)->handle($assistant, $sticky->fresh());

    $green = icsCall($assistant, [['name' => 'Ai Đó', 'role' => PartyRole::Defendant, 'phone' => '0977 000 444']], [
        'contact_name' => 'Người Xanh', 'contact_phone' => '0901 222 333',
    ]);
    $declined = icsCall($assistant, [['name' => 'Bị Đơn Là Khách', 'role' => PartyRole::Defendant, 'phone' => '0912000111']], [
        'contact_name' => 'Người Đã Từ Chối', 'contact_phone' => '0901 777 888',
    ]);
    app(DeclineIntake::class)->handle(icsStaff(Role::Manager), $declined, 'Bên kia là khách hiện hữu', true);

    expect($sticky->fresh()->conflict_level)->toBe(ConflictLevel::Green)
        ->and($sticky->fresh()->hasUnresolvedRed())->toBeTrue();

    $this->actingAs(icsStaff(Role::Manager), 'web');
    $this->livewire(ListIntakeRequests::class)
        ->assertTableColumnFormattedStateSet('conflict_level', __('intake.check.badge_red_pending'), $b)
        ->assertTableColumnFormattedStateSet('conflict_level', __('intake.check.badge_red_pending'), $sticky->fresh())
        ->assertTableColumnFormattedStateSet('conflict_level', __('intake.check.badge_red_pending'), $a)
        ->assertTableColumnFormattedStateSet('conflict_level', ConflictLevel::Green->label(), $green)
        ->filterTable('red_pending')
        ->assertCanSeeTableRecords([$a, $b, $sticky])
        ->assertCanNotSeeTableRecords([$green, $declined]);

    icsEdit($b)
        ->assertSee(__('intake.check.heading_red_pending'))
        ->assertSee(__('intake.check.red_pending_note'))
        ->assertDontSee(__('intake.check.heading_clear'));

    icsEdit($sticky)
        ->assertSee(__('intake.check.heading_red_pending'))
        ->assertDontSee(__('intake.check.heading_clear'));

    icsEdit($green)
        ->assertSee(__('intake.check.heading_clear'))
        ->assertDontSee(__('intake.check.heading_red_pending'));
});

/*
 * Bộ lọc chọn ứng viên bằng SQL rồi hỏi đúng định nghĩa trong bộ nhớ (`scopeAwaitingConflictResolution()`).
 * Mỗi đường một bản ghi, để mỗi vế của phần SQL có một bản chỉ đi qua được vế đó: Đỏ cũ không mang dấu
 * "đang chờ" (lưới an toàn của `hasUnresolvedRed()`), cùng người qua CCCD (SĐT khác), lần gọi kia bị từ
 * chối vì xung đột (mức của nó Xanh), lần gọi kia Đỏ dính (mức của nó Xanh). Bản đã gộp — đã xong việc —
 * không bao giờ "chờ", dù nó mang Đỏ dính.
 */
it('lists in the "red pending" filter every way a record can wait for a manager, and never a closed one', function () {
    icsExistingClient();
    $assistant = icsStaff();
    $manager = icsStaff(Role::Manager);
    $redParty = fn (): array => [['name' => 'Bị Đơn Là Khách', 'role' => PartyRole::Defendant, 'phone' => '0912000111']];
    // Tên bên kia khác nhau ở mỗi lần gọi: trùng tên là một khớp Vàng ở nguồn dò thứ hai.
    $harmless = fn (string $phone): array => [['name' => 'Bên Kia Số '.$phone, 'role' => PartyRole::Defendant, 'phone' => $phone]];

    // Đỏ cũ, không dấu "đang chờ" (ghi trước khi có cột, hay sửa tay) — và một lần gọi trước của cùng người
    // mà nó giữ (lần gọi kia chỉ có MỨC Đỏ, không dấu chờ, không từ chối).
    $heldByLegacy = icsCall($assistant, $harmless('0977 100 009'), ['contact_name' => 'Đỏ Cũ', 'contact_phone' => '0901 100 001']);
    $legacyRed = icsCall($assistant, $redParty(), ['contact_name' => 'Đỏ Cũ', 'contact_phone' => '0901 100 001']);
    $legacyRed->forceFill(['conflict_red_pending_since' => null])->saveQuietly();

    // Cùng người qua CCCD, hai SĐT khác nhau: lần đầu Xanh, lần sau Đỏ.
    $byIdFirst = icsCall($assistant, $harmless('0977 100 002'), ['contact_name' => 'Cùng Căn Cước', 'contact_phone' => '0901 100 002', 'contact_id_number' => '079200000001']);
    $byIdSecond = icsCall($assistant, $redParty(), ['contact_name' => 'Cùng Căn Cước', 'contact_phone' => '0901 100 003', 'contact_id_number' => '079200000001']);

    // Lần gọi kia bị từ chối vì xung đột, mức của nó Xanh.
    $heldByDecline = icsCall($assistant, $harmless('0977 100 004'), ['contact_name' => 'Bị Giữ Vì Từ Chối', 'contact_phone' => '0901 100 004']);
    $declinedSibling = icsCall($assistant, $harmless('0977 100 005'), ['contact_name' => 'Bị Giữ Vì Từ Chối', 'contact_phone' => '0901 100 004']);
    app(DeclineIntake::class)->handle($manager, $declinedSibling, 'Bên kia là khách hiện hữu', true);

    // Lần gọi kia Đỏ dính, mức của nó Xanh.
    $heldBySticky = icsCall($assistant, $harmless('0977 100 006'), ['contact_name' => 'Bị Giữ Vì Đỏ Dính', 'contact_phone' => '0901 100 006']);
    $stickySibling = icsCall($assistant, $redParty(), ['contact_name' => 'Bị Giữ Vì Đỏ Dính', 'contact_phone' => '0901 100 006']);
    $stickySibling->parties()->delete();
    app(RerunIntakeConflictCheck::class)->handle($assistant, $stickySibling->fresh());

    // Bản Đỏ đã gộp đi (trưởng phòng gộp): đã xong việc.
    $mergedRed = icsCall($assistant, $redParty(), ['contact_name' => 'Đã Gộp Đi', 'contact_phone' => '0901 100 007']);
    $mergeTarget = icsCall($assistant, $harmless('0977 100 008'), ['contact_name' => 'Đã Gộp Đi', 'contact_phone' => '0901 100 007']);
    app(MergeIntake::class)->handle($manager, $mergedRed->fresh(), $mergeTarget->fresh());

    expect($declinedSibling->fresh()->conflict_level)->toBe(ConflictLevel::Green)
        ->and($stickySibling->fresh()->conflict_level)->toBe(ConflictLevel::Green)
        ->and($stickySibling->fresh()->conflict_red_pending_since)->not->toBeNull()
        ->and($mergedRed->fresh()->status)->toBe(IntakeStatus::Merged)
        ->and($mergedRed->fresh()->conflict_red_pending_since)->not->toBeNull();

    $this->actingAs($manager, 'web');
    $list = $this->livewire(ListIntakeRequests::class)
        ->set('tableRecordsPerPage', 50)
        ->assertTableColumnExists('conflict_level', fn (TextColumn $column): bool => $column->getColor($column->getState()) === 'danger', $byIdFirst)
        ->assertTableColumnFormattedStateSet('conflict_level', ConflictLevel::Red->label(), $mergedRed->fresh())
        ->filterTable('red_pending');

    // So đúng TẬP bản ghi của bảng đã lọc, không qua `assertCanSeeTableRecords()`: nó tìm khoá
    // `….table.records.{id}` không có dấu ngoặc đóng, nên bản số 1 "thấy được" nhờ bản số 10.
    $shown = collect($list->instance()->getTableRecords()->items())->pluck('id')->sort()->values()->all();
    $expected = collect([
        $heldByLegacy, $legacyRed, $byIdFirst, $byIdSecond, $heldByDecline, $heldBySticky, $stickySibling,
        // Bản đích của lần gộp nhận dấu Đỏ chờ của bản nguồn (`MergeIntake`).
        $mergeTarget,
    ])->pluck('id')->sort()->values()->all();

    expect($shown)->toBe($expected)
        ->and($shown)->not->toContain($mergedRed->id)
        ->and($shown)->not->toContain($declinedSibling->id);
});

it('drops a held call from the "red pending" filter and shows its own last result once a manager resolves it', function () {
    ['b' => $b] = icsGreenThenSameCallerRed();
    $manager = icsStaff(Role::Manager);

    $this->actingAs($manager, 'web');
    icsEdit($b)->callAction(TestAction::make('resolveRed')->schemaComponent('checkActions'), data: ['override_reason' => 'Đã xác minh, không xung đột.']);

    // Lần kiểm tra lại của bước ghi đè mang bên đối lập của lần gọi Đỏ sang (cùng người): mức của CHÍNH
    // nó giờ là Đỏ, đã ghi đè — nhãn đó, không phải "chờ trưởng phòng".
    expect($b->fresh()->conflict_level)->toBe(ConflictLevel::Red)
        ->and($b->fresh()->hasConflictOverride())->toBeTrue();

    $this->livewire(ListIntakeRequests::class)
        ->assertTableColumnFormattedStateSet('conflict_level', ConflictLevel::Red->label(), $b->fresh())
        ->filterTable('red_pending')
        ->assertCanNotSeeTableRecords([$b]);
});

// ------------------------------------------------------------------------------------------- FI6 (R1)

it('shows whoever cannot resolve a red only the file code and the role of a red match, and the manager the whole row', function () {
    $matter = icsExistingClient();
    $assistant = icsStaff();
    $intake = icsCall($assistant, [['name' => 'Bị Đơn Là Khách', 'role' => PartyRole::Defendant, 'phone' => '0912000111']]);

    expect($intake->conflict_level)->toBe(ConflictLevel::Red);

    // Vai của bên trùng ("Nguyên đơn") cũng là một lựa chọn của ô vai trên form, lĩnh vực "Lĩnh Vực Kín"
    // một lựa chọn của ô lĩnh vực dự kiến, tiêu chí "Số điện thoại" nhãn một ô: ba thứ đó đo bằng số lần
    // xuất hiện — bảng của trưởng phòng có thêm đúng một lần mỗi thứ bị giấu, còn vai thì hai bảng bằng nhau.
    $this->actingAs($assistant, 'web');
    $assistantPage = icsEdit($intake)
        ->assertSee($matter->code)
        ->assertSee(__('intake.check.hidden_for_red'))
        ->assertSee(__('intake.check.red_hidden_note'))
        ->assertDontSee('Khách Hiện Hữu Thật')
        ->html();

    $this->actingAs(icsStaff(Role::Manager), 'web');
    $managerPage = icsEdit($intake)
        ->assertSee($matter->code)
        ->assertSee('Khách Hiện Hữu Thật')
        ->assertDontSee(__('intake.check.hidden_for_red'))
        ->assertDontSee(__('intake.check.red_hidden_note'))
        ->html();

    $count = fn (string $page, string $text): int => substr_count($page, e($text));

    expect($count($assistantPage, __('intake.check.hidden_for_red')))->toBe(3)
        ->and($count($managerPage, 'Lĩnh Vực Kín') - $count($assistantPage, 'Lĩnh Vực Kín'))->toBe(1)
        ->and($count($managerPage, ConflictMatchTier::Phone->label()) - $count($assistantPage, ConflictMatchTier::Phone->label()))->toBe(1)
        ->and($count($assistantPage, PartyRole::Plaintiff->label()))->toBe($count($managerPage, PartyRole::Plaintiff->label()));
});

it('still shows the recorder the whole row of a yellow match, which they must judge before acknowledging', function () {
    // Vàng theo SĐT: bên trùng KHÔNG phải khách của văn phòng, và tên trong hồ sơ cũ khác tên người gọi
    // khai — nên tên đó chỉ có thể đến từ bảng kết quả.
    $party = MatterParty::factory()->for(Matter::factory()->create())->make(['name' => 'Tên Trong Hồ Sơ Cũ', 'role' => PartyRole::Related]);
    $party->identify(null, '0977 300 300')->save();
    $assistant = icsStaff();
    $intake = icsCall($assistant, [['name' => 'Tên Người Gọi Khai', 'role' => PartyRole::Defendant, 'phone' => '0977 300 300']]);

    expect($intake->conflict_level)->toBe(ConflictLevel::Yellow);

    $this->actingAs($assistant, 'web');
    icsEdit($intake)
        ->assertSee('Tên Trong Hồ Sơ Cũ')
        ->assertDontSee(__('intake.check.hidden_for_red'))
        ->assertDontSee(__('intake.check.red_hidden_note'));
});

// ------------------------------------------------------------------------------------------- FI3 (R8)

it('shows the assistant who recorded them the same decision block for a call declined for a conflict and one declined for an ordinary reason', function () {
    $assistant = icsStaff();
    $manager = icsStaff(Role::Manager);
    $ordinary = icsCall($assistant, [['name' => 'Ai Đó', 'role' => PartyRole::Defendant, 'phone' => '0977 000 444']], [
        'contact_name' => 'Lê Thị Thường', 'contact_phone' => '0901 000 001',
    ]);
    $conflict = icsCall($assistant, [['name' => 'Ai Khác', 'role' => PartyRole::Defendant, 'phone' => '0977 000 555']], [
        'contact_name' => 'Trần Văn Xung', 'contact_phone' => '0901 000 002',
    ]);
    app(DeclineIntake::class)->handle($manager, $ordinary, 'LÝ-DO-THƯỜNG: văn phòng kín lịch tới cuối tháng', false);
    app(DeclineIntake::class)->handle($manager, $conflict, 'LÝ-DO-XUNG-ĐỘT: bên kia là khách của văn phòng', true);

    $decisionBlock = function (IntakeRequest $intake): string {
        $html = icsEdit($intake)->html();
        $start = strpos($html, e(__('intake.sections.decision')));
        $end = strpos($html, e(__('intake.decision.outward_answer')), (int) $start);

        expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();

        // Chữ người xem đọc được, không phải mã HTML (khoá `wire:key` khác nhau giữa hai lần mở trang).
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags(substr($html, $start, $end - $start))));
    };

    $this->actingAs($assistant, 'web');
    icsEdit($ordinary)->assertDontSee('LÝ-DO-THƯỜNG')->assertSee(__('intake.decision.outward_answer'));
    icsEdit($conflict)->assertDontSee('LÝ-DO-XUNG-ĐỘT')->assertSee(__('intake.decision.outward_answer'));

    expect($decisionBlock($conflict))->toBe($decisionBlock($ordinary));

    // Một trưởng phòng KHÁC người đã từ chối: đọc được lý do vì `viewConflictReason`, không vì đã viết nó.
    $this->actingAs(icsStaff(Role::Manager), 'web');
    icsEdit($ordinary)->assertSee('LÝ-DO-THƯỜNG');
    icsEdit($conflict)->assertSee('LÝ-DO-XUNG-ĐỘT')->assertSee(__('intake.decision.declined_for_conflict'));
});

it('still shows whoever declined a call for an ordinary reason the reason they wrote', function () {
    $assistant = icsStaff();
    $intake = icsCall($assistant, [['name' => 'Ai Đó', 'role' => PartyRole::Defendant, 'phone' => '0977 000 444']]);
    app(DeclineIntake::class)->handle($assistant, $intake, 'LÝ-DO-CỦA-CHÍNH-MÌNH', false);

    $this->actingAs($assistant, 'web');
    icsEdit($intake)->assertSee('LÝ-DO-CỦA-CHÍNH-MÌNH');

    // Một trợ lý khác được giao bản ghi không phải người từ chối: không thấy lý do.
    $other = icsStaff();
    $intake->fresh()->forceFill(['assigned_to' => $other->id])->saveQuietly();

    $this->actingAs($other, 'web');
    icsEdit($intake)->assertDontSee('LÝ-DO-CỦA-CHÍNH-MÌNH')->assertSee(__('intake.decision.outward_answer'));
});

// ------------------------------------------------------------------------------------------- FI4

/** Tiêu đề và thân của mọi thông báo Filament đang chờ hiện. */
function icsNotificationTexts(): string
{
    $notifications = new Notifications;
    $notifications->mount();

    return $notifications->notifications
        ->map(fn (Notification $notification): string => $notification->getTitle().' '.$notification->getBody())
        ->implode("\n");
}

it('does not name the new matter in the conversion notice when it is restricted to another lead the converter cannot view', function () {
    $lawyer = icsStaff(Role::Lawyer);
    $otherLead = icsStaff(Role::Lawyer);
    $type = MatterType::factory()->withStages()->create(['is_active' => true]);
    $intake = icsCall($lawyer, [['name' => 'Công Ty Bên Kia', 'role' => PartyRole::Defendant, 'phone' => '0977 000 222', 'id_number' => '079088000111']], [
        'matter_type_id' => $type->id,
    ]);

    $this->actingAs($lawyer, 'web');
    test()->livewire(ConvertIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->fillForm(['confidentiality' => 'restricted', 'lead_lawyer_id' => $otherLead->id])
        ->call('convert')
        ->assertHasNoFormErrors()
        ->assertNotified(__('intake.convert.done_hidden', ['intake' => $intake->code]));

    $matter = Matter::query()->findOrFail($intake->fresh()->matter_id);

    expect(icsNotificationTexts())->not->toContain($matter->code);
});

it('names the new matter in the conversion notice when the converter can view it', function () {
    $lawyer = icsStaff(Role::Lawyer);
    $type = MatterType::factory()->withStages()->create(['is_active' => true]);
    $intake = icsCall($lawyer, [['name' => 'Công Ty Bên Kia', 'role' => PartyRole::Defendant, 'phone' => '0977 000 222', 'id_number' => '079088000111']], [
        'matter_type_id' => $type->id,
    ]);

    $this->actingAs($lawyer, 'web');
    test()->livewire(ConvertIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->fillForm(['confidentiality' => 'restricted', 'lead_lawyer_id' => $lawyer->id])
        ->call('convert')
        ->assertHasNoFormErrors();

    $matter = Matter::query()->findOrFail($intake->fresh()->matter_id);

    expect(icsNotificationTexts())->toContain(__('intake.convert.done', ['intake' => $intake->code, 'matter' => $matter->code]));
});

/*
 * Bản ghi tiếp nhận đã từ chối không bao giờ vào bộ lọc "Đỏ chờ trưởng phòng xử lý" dù Đỏ dính của nó
 * chưa xoá (từ chối vì xung đột không xoá Đỏ dính) — từ chối LÀ một cách xử lý Đỏ (R1).
 */
it('treats a declined record as handled: no red pending badge for it', function () {
    $assistant = icsStaff();
    icsExistingClient();
    $intake = icsCall($assistant, [['name' => 'Bị Đơn Là Khách', 'role' => PartyRole::Defendant, 'phone' => '0912000111']]);
    app(DeclineIntake::class)->handle(icsStaff(Role::Manager), $intake, 'Bên kia là khách hiện hữu', true);

    expect($intake->fresh()->status)->toBe(IntakeStatus::Declined)
        ->and($intake->fresh()->hasUnresolvedRed())->toBeTrue()
        ->and($intake->fresh()->awaitsConflictResolution())->toBeFalse();

    $this->actingAs(icsStaff(Role::Manager), 'web');
    $this->livewire(ListIntakeRequests::class)
        ->assertTableColumnFormattedStateSet('conflict_level', ConflictLevel::Red->label(), $intake->fresh());
});
