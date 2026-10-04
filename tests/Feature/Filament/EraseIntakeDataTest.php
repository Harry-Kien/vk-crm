<?php

use App\Actions\Client\FindClientByIdentifier;
use App\Actions\Intake\AnonymiseProspect;
use App\Actions\Intake\MergeIntake;
use App\Actions\Intake\RecordIntake;
use App\Actions\Intake\RecordPrivacyNotice;
use App\Actions\Intake\UpdateIntakeSummary;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\IntakeRequests\IntakeRequestResource;
use App\Filament\Admin\Resources\IntakeRequests\Pages\ConvertIntakeRequest;
use App\Filament\Admin\Resources\IntakeRequests\Pages\EditIntakeRequest;
use App\Filament\Admin\Resources\IntakeRequests\Pages\ListIntakeRequests;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use App\Support\Audit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Spatie\Activitylog\Models\Activity;

/*
 * M10 Task 7 (R7c) — "Xoá dữ liệu theo yêu cầu" trên trang một lần tiếp nhận, qua Livewire: chỉ admin
 * thấy và dùng được; lý do ≥ 20 ký tự (`mb_strlen`); bản đã chuyển thành vụ việc bị từ chối kèm lời
 * giải thích; trang của bản đã ẩn danh nói khi nào và vì sao. Cộng một điều của R7b mà màn hình phải
 * giữ: từ chối từ trang sửa đặt hạn lưu. Luật của Action ở `tests/Feature/Intake/AnonymiseProspectTest.php`.
 *
 * Hàm toàn cục mang tiền tố `era…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->travelTo(now()->setDate(2026, 10, 3)->setTime(15, 0));
});

function eraStaff(Role $role = Role::Assistant): User
{
    return User::factory()->withRole($role)->create();
}

function eraRecord(User $actor, array $overrides = []): IntakeRequest
{
    return app(RecordIntake::class)->handle($actor, [...[
        'contact_name' => 'Hoàng Thị Riêng Tư',
        'contact_phone' => '0966555444',
        'contact_email' => 'rieng@example.test',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
    ], ...$overrides], [['name' => 'Bên Kia Kín', 'role' => PartyRole::Defendant, 'phone' => '0977000555']])->intake;
}

function eraEdit(IntakeRequest $intake)
{
    return test()->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()]);
}

/** Đúng thông báo `ReportsActionFailures` gửi khi một Action từ chối mà không chỉ vào ô nào. */
function eraFailure(string $body): Notification
{
    return Notification::make()->title(__('actions.failed_title'))->body($body)->danger()->persistent();
}

const ERA_REASON_20 = 'Yêu cầu xoá dữ liệu.';

it('counts the twenty-character reason the test uses in characters, not bytes', function () {
    expect(mb_strlen(ERA_REASON_20))->toBe(20)
        ->and(strlen(ERA_REASON_20))->toBeGreaterThan(20)
        ->and(mb_strlen(mb_substr(ERA_REASON_20, 0, 19)))->toBe(19);
});

it('shows "erase on request" only to an admin', function (Role $role, bool $visible) {
    $assistant = eraStaff();
    $intake = eraRecord($assistant);
    $actor = $role === Role::Assistant ? $assistant : eraStaff($role);
    $intake->forceFill(['assigned_to' => $actor->id])->save();

    $this->actingAs($actor, 'web');

    $visible
        ? eraEdit($intake)->assertActionVisible('eraseData')
        : eraEdit($intake)->assertActionHidden('eraseData');
})->with([
    'admin' => [Role::Admin, true],
    'manager' => [Role::Manager, false],
    'lawyer' => [Role::Lawyer, false],
    'assistant who recorded it' => [Role::Assistant, false],
]);

it('does nothing for a manager who forges the call to the hidden action', function () {
    $intake = eraRecord(eraStaff());
    $this->actingAs(eraStaff(Role::Manager), 'web');

    // Gọi thẳng các phương thức Livewire (không qua `callAction`, vốn tự đòi nút phải hiện): đúng thứ một
    // request sửa tay gửi được.
    eraEdit($intake)
        ->call('mountAction', 'eraseData')
        ->set('mountedActions.0.data.erase_reason', 'Người liên hệ yêu cầu xoá qua điện thoại')
        ->call('callMountedAction');

    expect($intake->fresh()->contact_name)->toBe('Hoàng Thị Riêng Tư')
        ->and($intake->fresh()->anonymised_at)->toBeNull()
        ->and(Activity::query()->where('event', 'prospect_data_erased')->exists())->toBeFalse();

    // Cặp dương: cùng chuỗi gọi đó, từ một admin, thì tới được Action.
    $this->actingAs(eraStaff(Role::Admin), 'web');
    eraEdit($intake)
        ->call('mountAction', 'eraseData')
        ->set('mountedActions.0.data.erase_reason', 'Người liên hệ yêu cầu xoá qua điện thoại')
        ->call('callMountedAction');

    expect($intake->fresh()->contact_name)->toBeNull();
});

it('answers 404 to anyone without access to the record before any action is reached', function () {
    $intake = eraRecord(eraStaff());

    $this->actingAs(eraStaff(), 'web')
        ->get(IntakeRequestResource::getUrl('edit', ['record' => $intake], panel: 'admin'))
        ->assertNotFound();

    $this->actingAs(eraStaff(Role::Accountant), 'web')
        ->get(IntakeRequestResource::getUrl('edit', ['record' => $intake], panel: 'admin'))
        ->assertNotFound();
});

it('offers erasure on an open, a declined, a lost and a merged record', function (IntakeStatus $status) {
    $intake = eraRecord(eraStaff());
    $intake->forceFill(['status' => $status])->save();

    $this->actingAs(eraStaff(Role::Admin), 'web');
    eraEdit($intake)->assertActionVisible('eraseData');
})->with([
    'new' => [IntakeStatus::New],
    'declined' => [IntakeStatus::Declined],
    'lost' => [IntakeStatus::Lost],
    'merged' => [IntakeStatus::Merged],
]);

it('keeps everything when the admin gives 19 characters, and erases with 20', function () {
    $assistant = eraStaff();
    $intake = eraRecord($assistant);
    app(RecordPrivacyNotice::class)->handle($assistant, $intake, true);
    app(UpdateIntakeSummary::class)->handle($assistant, $intake->fresh(), 'Câu chuyện kín của người liên hệ');

    $admin = eraStaff(Role::Admin);
    $this->actingAs($admin, 'web');

    eraEdit($intake)
        ->callAction('eraseData', data: ['erase_reason' => mb_substr(ERA_REASON_20, 0, 19)])
        ->assertHasActionErrors(['erase_reason']);

    expect($intake->fresh()->contact_name)->toBe('Hoàng Thị Riêng Tư')
        ->and($intake->fresh()->summary)->toBe('Câu chuyện kín của người liên hệ');

    eraEdit($intake)
        ->callAction('eraseData', data: ['erase_reason' => ERA_REASON_20])
        ->assertHasNoActionErrors()
        ->assertNotified(__('intake.anonymise.done', ['code' => $intake->code]))
        ->assertRedirect(IntakeRequestResource::getUrl('edit', ['record' => $intake], panel: 'admin'));

    $fresh = $intake->fresh();
    expect($fresh->contact_name)->toBeNull()
        ->and($fresh->contact_phone)->toBeNull()
        ->and($fresh->contact_email)->toBeNull()
        ->and($fresh->summary)->toBeNull()
        ->and($fresh->parties()->first()->name)->toBeNull()
        ->and($fresh->anonymised_by)->toBe($admin->id)
        ->and($fresh->anonymised_reason)->toBe(ERA_REASON_20);

    $row = Activity::query()->where('event', 'prospect_data_erased')->sole();
    expect($row->causer_id)->toBe($admin->id)
        ->and($row->properties->all())->toBe(['code' => $intake->code, 'reason' => ERA_REASON_20]);
});

it('requires a reason at all', function () {
    $intake = eraRecord(eraStaff());
    $this->actingAs(eraStaff(Role::Admin), 'web');

    eraEdit($intake)
        ->callAction('eraseData', data: ['erase_reason' => ''])
        ->assertHasActionErrors(['erase_reason' => 'required']);

    expect($intake->fresh()->anonymised_at)->toBeNull();
});

it('hides erasure on a converted record and tells the admin that the data now follows the client file', function () {
    $intake = eraRecord(eraStaff());
    $intake->forceFill(['status' => IntakeStatus::Won, 'matter_id' => Matter::factory()->create()->id])->save();

    $this->actingAs(eraStaff(Role::Admin), 'web');
    eraEdit($intake)
        ->assertActionHidden('eraseData')
        ->assertSee(__('intake.anonymise.errors.converted'));

    // Người không phải admin không cần câu đó.
    $this->actingAs(eraStaff(Role::Manager), 'web');
    eraEdit($intake)->assertDontSee(__('intake.anonymise.errors.converted'));
});

/*
 * Fix vòng 1 (rà soát Task 7, I1): người gọi hai lần, lần đầu được gộp vào lần sau, lần sau thành vụ
 * việc qua trang chuyển đổi — người đó đã là khách, nên bản đã gộp cũng không xoá ở đây; admin đọc vì
 * sao, kèm mã bản đã thành vụ.
 */
it('hides erasure on a record merged into one that became a client, and tells the admin which record that is', function () {
    $lawyer = eraStaff(Role::Lawyer);
    $first = eraRecord($lawyer);
    $target = eraRecord($lawyer, [
        'contact_name' => 'Hoàng Thị Riêng Tư, gọi lại',
        'matter_type_id' => MatterType::factory()->withStages()->create(['is_active' => true])->id,
    ]);
    app(MergeIntake::class)->handle($lawyer, $first, $target);
    $message = __('intake.anonymise.errors.converted_through_merge', ['code' => $target->code]);

    $admin = eraStaff(Role::Admin);
    $this->actingAs($admin, 'web');

    // Cặp dương: khi bản đích còn mở, admin xoá được bản đã gộp.
    eraEdit($first)->assertActionVisible('eraseData')->assertDontSee($message);

    $this->actingAs($lawyer, 'web');
    test()->livewire(ConvertIntakeRequest::class, ['record' => $target->getRouteKey()])
        ->call('convert')
        ->assertHasNoFormErrors();

    expect($target->fresh()->status)->toBe(IntakeStatus::Won);

    $this->actingAs($admin, 'web');
    eraEdit($first)
        ->assertActionHidden('eraseData')
        ->assertSee($message);

    expect($first->fresh()->contact_name)->toBe('Hoàng Thị Riêng Tư');
});

/*
 * Fix vòng 1 (rà soát Task 7, C1): trang chuyển đổi luôn chạy một lượt đầu chưa xác nhận; lượt đó bị
 * từ chối để lại một dòng kiểm tra mang tên các bên. Modal hứa "tên họ trong kết quả kiểm tra xung đột
 * sẽ bị xoá" — xoá theo yêu cầu phải tới được cả dòng đó.
 */
it('erases the names that a refused first pass on the conversion page left in its conflict check', function () {
    $lawyer = eraStaff(Role::Lawyer);
    $intake = app(RecordIntake::class)->handle($lawyer, [
        'contact_name' => 'Zqxera Contact',
        'contact_phone' => '0966555444',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
        'matter_type_id' => MatterType::factory()->withStages()->create(['is_active' => true])->id,
    ], [['name' => 'Zqxera Party', 'role' => PartyRole::Defendant]])->intake;

    $this->actingAs($lawyer, 'web');
    test()->livewire(ConvertIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->call('convert')
        ->assertHasFormErrors(['acknowledge_conflict']);

    expect($intake->fresh()->matter_id)->toBeNull();

    $trail = fn (): string => (string) json_encode(Activity::query()->pluck('properties'), JSON_UNESCAPED_UNICODE);
    expect($trail())->toContain('Zqxera Party');

    $this->actingAs(eraStaff(Role::Admin), 'web');
    eraEdit($intake)
        ->callAction('eraseData', data: ['erase_reason' => ERA_REASON_20])
        ->assertHasNoActionErrors();

    expect($intake->fresh()->contact_name)->toBeNull()
        ->and($trail())->not->toContain('Zqxera');
});

/*
 * Việc sau gộp M9 + M10 (làn fu3, Task 1 mục E): mỗi lần ghi nhận (và mỗi lần chuyển đổi) chạy
 * `FindClientByIdentifier`, thứ ghi dòng `client_lookup` mang `identifier_hash` — HMAC của chữ số đã gõ,
 * cùng hàm băm với dấu băm CCCD của bản ghi. Modal hứa xoá SĐT và số căn cước, nên dấu băm của chúng
 * trong sổ tra khách đi cùng: thành null, DÒNG ở lại (ai tra, lúc nào, trúng hay trượt). Kể cả lần tra
 * của nhân sự khác gõ cùng số theo cách khác (`+84 …`, có dấu chấm), và dòng `client_lookup_throttled`.
 * Cặp dương: lần tra một số của người khác giữ nguyên dấu băm.
 *
 * Mutation probe: bỏ lời gọi làm sạch sổ tra khách khỏi `AnonymiseProspect::anonymise()` — ĐỎ; bỏ từng
 * cách viết trong `lookupHashesOf()` (chuỗi đã lưu, `84…`, `0084…`, `0…`, số trần, `840…`) — mỗi lần
 * ĐỎ; bỏ dấu băm CCCD — ĐỎ; bỏ `client_lookup_throttled` khỏi các sự kiện được làm sạch — ĐỎ.
 */
it('erases the identifier hashes the client lookups of this person left, but keeps the lookup rows', function () {
    $assistant = eraStaff();
    // Người ghi nhận gõ SĐT theo một cách lạ — chữ số của nó không trùng cách viết chuẩn nào — nên chỉ chính
    // chuỗi đã lưu mới cho ra dấu băm của lần tra lúc ghi nhận.
    $intake = eraRecord($assistant, ['contact_phone' => '0084 (0) 966 555 444', 'contact_id_number' => '079 188 123 456']);

    // Cùng số, mọi cách viết thường gặp mà `Normalizer::phone()` đưa về `84966555444`, và cùng CCCD có dấu chấm.
    $lawyer = eraStaff(Role::Lawyer);
    foreach (['0966 555 444', '+84 966 555 444', '0084 966 555 444', '966 555 444', '+84 (0) 966 555 444', '079.188.123.456'] as $typed) {
        app(FindClientByIdentifier::class)->handle($lawyer, $typed);
    }
    Audit::record('client_lookup_throttled', null, ['identifier_hash' => Audit::identifierHash('84966555444')], $lawyer);
    app(FindClientByIdentifier::class)->handle($lawyer, '0911 222 333');

    $lookups = fn () => Activity::query()->whereIn('event', ['client_lookup', 'client_lookup_throttled'])->orderBy('id')->get();
    $before = $lookups();
    $someoneElse = Audit::identifierHash('0911222333');

    // Tiền đề: hai dòng của lần ghi nhận (SĐT và CCCD như đã gõ), bảy dòng tra cùng người (một bị chặn), một
    // dòng người khác — mười dấu băm, bảy giá trị khác nhau cho cùng một người.
    expect($before)->toHaveCount(10)
        ->and($before->map(fn (Activity $row) => $row->properties['identifier_hash'])->filter()->count())->toBe(10)
        ->and($before->map(fn (Activity $row) => $row->properties['identifier_hash'])->unique()->count())->toBe(8);

    $this->actingAs(eraStaff(Role::Admin), 'web');
    eraEdit($intake)
        ->callAction('eraseData', data: ['erase_reason' => ERA_REASON_20])
        ->assertHasNoActionErrors();

    $after = $lookups();

    expect($after->pluck('id')->all())->toBe($before->pluck('id')->all())
        ->and($after->map(fn (Activity $row): ?string => $row->properties['identifier_hash'])->all())
        ->toBe([null, null, null, null, null, null, null, null, null, $someoneElse])
        // Phần còn lại của dòng giữ nguyên: trúng hay trượt, người tra.
        ->and($after->map(fn (Activity $row): array => [$row->causer_id, $row->properties['hit'] ?? 'throttled'])->all())
        ->toBe($before->map(fn (Activity $row): array => [$row->causer_id, $row->properties['hit'] ?? 'throttled'])->all());
});

/*
 * Cặp âm của các cách viết: chúng chỉ là cách viết của một số VIỆT NAM (`84…`). Một số nước ngoài không
 * có dạng `0…`/số trần nào — cắt hai chữ số đầu của nó ra là một số khác, của người khác, và lần tra số
 * đó giữ nguyên dấu băm.
 *
 * Mutation probe: bỏ điều kiện `str_starts_with($normalized, '84')` trong `lookupHashesOf()` — ĐỎ.
 */
it('keeps the hash of someone else\'s number that only looks like a shortened form of a foreign number', function () {
    $intake = eraRecord(eraStaff(), ['contact_phone' => '+1 415 555 0100']);
    $lawyer = eraStaff(Role::Lawyer);
    app(FindClientByIdentifier::class)->handle($lawyer, '0155 550 100');

    $hashes = fn (): array => Activity::query()->where('event', 'client_lookup')->orderBy('id')
        ->get()->map(fn (Activity $row): ?string => $row->properties['identifier_hash'])->all();

    expect($intake->contact_phone_normalized)->toBe('14155550100')
        ->and($hashes())->toBe([Audit::identifierHash('14155550100'), Audit::identifierHash('0155550100')]);

    $this->actingAs(eraStaff(Role::Admin), 'web');
    eraEdit($intake)
        ->callAction('eraseData', data: ['erase_reason' => ERA_REASON_20])
        ->assertHasNoActionErrors();

    expect($hashes())->toBe([null, Audit::identifierHash('0155550100')]);
});

/*
 * Trang đọc lại bản ghi ở mỗi request: một bản được chuyển thành vụ ở tab khác SAU khi trang mở thì nút
 * biến mất ngay ở lần bấm, và trang nói lý do.
 */
it('erases nothing when the record was converted after the page was opened, and says why', function () {
    $intake = eraRecord(eraStaff());
    $this->actingAs(eraStaff(Role::Admin), 'web');

    $page = eraEdit($intake)->assertActionVisible('eraseData');

    $intake->forceFill(['status' => IntakeStatus::Won, 'matter_id' => Matter::factory()->create()->id])->save();

    $page->callAction('eraseData', data: ['erase_reason' => ERA_REASON_20])
        ->assertActionHidden('eraseData')
        ->assertSee(__('intake.anonymise.errors.converted'));

    expect($intake->fresh()->contact_name)->toBe('Hoàng Thị Riêng Tư')
        ->and($intake->fresh()->anonymised_at)->toBeNull()
        ->and(Activity::query()->where('event', 'prospect_data_erased')->exists())->toBeFalse();
});

/*
 * Khe hẹp còn lại: bản ghi được chuyển thành vụ SAU lúc trang kiểm tra nút nhưng TRƯỚC lúc Action khoá
 * dòng. Dựng lại khe đó bằng một Action bọc ngoài đổi bản ghi ngay trước khi gọi Action thật: Action
 * (đọc trên dòng vừa khoá) từ chối, và màn hình hiện đúng câu tiếng Việt, không trang lỗi.
 */
it('shows the refusal of the Action as a Vietnamese notification when the record changes in the last moment', function () {
    $intake = eraRecord(eraStaff());
    $this->actingAs(eraStaff(Role::Admin), 'web');

    app()->bind(AnonymiseProspect::class, fn () => new class extends AnonymiseProspect
    {
        public function erase(User $actor, IntakeRequest $intake, string $reason): IntakeRequest
        {
            IntakeRequest::query()->whereKey($intake->getKey())->update(['status' => IntakeStatus::Won->value]);

            return parent::erase($actor, $intake, $reason);
        }
    });

    eraEdit($intake)
        ->callAction('eraseData', data: ['erase_reason' => ERA_REASON_20])
        ->assertNotified(eraFailure(__('intake.anonymise.errors.converted')));

    expect($intake->fresh()->contact_name)->toBe('Hoàng Thị Riêng Tư')
        ->and($intake->fresh()->anonymised_at)->toBeNull()
        ->and(Activity::query()->where('event', 'prospect_data_erased')->exists())->toBeFalse();
});

it('shows on an erased record when its data was erased and why — the reason only to an admin — and offers no second erasure', function () {
    $assistant = eraStaff();
    $intake = eraRecord($assistant);
    $admin = eraStaff(Role::Admin);
    app(AnonymiseProspect::class)->erase($admin, $intake, 'Yêu cầu qua điện thoại ngày 03/10/2026');

    $this->actingAs($admin, 'web');
    eraEdit($intake)
        ->assertActionHidden('eraseData')
        ->assertSee(__('intake.anonymise.decision_request', ['date' => '03/10/2026']))
        ->assertSee('Yêu cầu qua điện thoại ngày 03/10/2026')
        ->assertDontSee(__('intake.anonymise.errors.already'))
        ->assertFormSet(['contact_name' => null, 'contact_phone' => null])
        ->assertDontSee('Hoàng Thị Riêng Tư')
        ->assertDontSee('Bên Kia Kín');

    $this->actingAs($assistant, 'web');
    eraEdit($intake)
        ->assertSee(__('intake.anonymise.decision_request', ['date' => '03/10/2026']))
        ->assertDontSee('Yêu cầu qua điện thoại ngày 03/10/2026');
});

it('shows on a record anonymised at the end of its retention that it was the retention, not a request', function () {
    $assistant = eraStaff();
    $intake = eraRecord($assistant);
    $intake->forceFill(['status' => IntakeStatus::Lost])->save();

    $this->travelTo(now()->setDate(2028, 10, 4)->setTime(3, 30));
    app(AnonymiseProspect::class)->expire($intake->fresh());

    $this->actingAs($assistant, 'web');
    eraEdit($intake)
        ->assertSee(__('intake.anonymise.decision_retention', ['date' => '04/10/2028']))
        ->assertDontSee(__('intake.anonymise.decision_request', ['date' => '04/10/2028']));
});

it('lists an erased record with a placeholder instead of a blank name', function () {
    $assistant = eraStaff();
    $intake = eraRecord($assistant);
    app(AnonymiseProspect::class)->erase(eraStaff(Role::Admin), $intake, ERA_REASON_20);

    $this->actingAs($assistant, 'web');
    test()->livewire(ListIntakeRequests::class)
        ->assertCanSeeTableRecords([$intake])
        ->assertSee(__('intake.anonymise.placeholder'))
        ->assertDontSee('Hoàng Thị Riêng Tư');
});

it('stamps the retention date when a record is declined from its page', function () {
    $assistant = eraStaff();
    $intake = eraRecord($assistant);

    $this->actingAs($assistant, 'web');
    eraEdit($intake)
        ->callAction('decline', data: ['decline_reason' => 'Ngoài lĩnh vực của văn phòng'])
        ->assertHasNoActionErrors();

    expect($intake->fresh()->status)->toBe(IntakeStatus::Declined)
        ->and($intake->fresh()->retention_until->toDateString())->toBe('2028-10-03');
});
