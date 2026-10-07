<?php

use App\Actions\Intake\AcknowledgeIntakeConflict;
use App\Actions\Intake\AnonymiseProspect;
use App\Actions\Intake\ChangeIntakeStatus;
use App\Actions\Intake\ConvertIntakeToMatter;
use App\Actions\Intake\DeclineIntake;
use App\Actions\Intake\FindIntakeDuplicates;
use App\Actions\Intake\MergeIntake;
use App\Actions\Intake\RecordIntake;
use App\Actions\Intake\RecordPrivacyNotice;
use App\Actions\Intake\ResolveIntakeRedConflict;
use App\Actions\Intake\UpdateIntakeSummary;
use App\Actions\OpenMatter;
use App\Actions\RunConflictCheck;
use App\Actions\Schedule\AnonymiseExpiredProspects;
use App\Actions\Schedule\RemindUnansweredIntakes;
use App\Enums\ClientType;
use App\Enums\Confidentiality;
use App\Enums\ConflictLevel;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\OutboundStatus;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Exceptions\ConflictAcknowledgementRequired;
use App\Exceptions\ConflictBlocked;
use App\Mail\Staff\IntakeUnanswered;
use App\Models\Client;
use App\Models\IntakeParty;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterType;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\Staff\IntakeUnansweredAlert;
use App\Support\Audit;
use App\Support\Normalizer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
 * M10 Task 7 (R7b, R7c) — MỘT Action ẩn danh (`AnonymiseProspect`) cho hai đường: tác vụ hằng ngày
 * `AnonymiseExpiredProspects` (chỉ bản quá `retention_until`, chưa chuyển đổi, chưa ẩn danh) và "Xoá
 * dữ liệu theo yêu cầu" của admin (lý do ≥ 20 ký tự `mb_strlen`, audit `prospect_data_erased`).
 *
 * Ẩn danh là CẬP NHẬT, không xoá dòng: các cột cá nhân về null, các bên đối lập mất tên và định danh
 * (kể cả dấu băm — R7b, mặc định chờ xác nhận), và dữ liệu người đó để lại ở NƠI KHÁC được làm sạch:
 * nhật ký của chính bản ghi (kết quả kiểm tra, lý do ghi đè, chữ ký các cặp đã xác nhận), tên của nó
 * trong bằng chứng kiểm tra của bản ghi và vụ việc khác (theo mã `TN-…`), và bên đối lập của nó đã
 * được mang sang lần gọi lại của cùng người. Test chuỗi đánh dấu quét MỌI bảng.
 *
 * Hành vi màn hình ở `tests/Feature/Filament/EraseIntakeDataTest.php`. Hàm toàn cục mang tiền tố `anp…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(now()->setDate(2026, 10, 3)->setTime(15, 0));
});

function anpStaff(Role $role = Role::Assistant): User
{
    return User::factory()->withRole($role)->create();
}

function anpRecord(User $actor, array $overrides = [], array $parties = []): IntakeRequest
{
    return app(RecordIntake::class)->handle($actor, [...[
        'contact_name' => 'Người Gọi Mẫu',
        'contact_phone' => '0832270898',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
    ], ...$overrides], $parties)->intake;
}

/** Khách hiện hữu (SĐT 0912000111), nguyên đơn trong một vụ — để bên đối lập mang số này ra Đỏ. */
function anpExistingClient(): Client
{
    $client = Client::factory()->create(['phone' => '0912000111', 'id_number' => null, 'name' => 'Khách Hiện Hữu']);
    MatterParty::factory()->for(Matter::factory()->create())->ourClient($client)->create();

    return $client;
}

function anpExpire(): array
{
    return app(AnonymiseExpiredProspects::class)->handle();
}

/** Mọi cột cá nhân của bản ghi, và mọi cột của các bên đối lập trừ vai, đều phải về null. */
function anpExpectPersonalDataGone(IntakeRequest $intake): void
{
    $intake = IntakeRequest::query()->withTrashed()->findOrFail($intake->getKey());

    foreach (['contact_name', 'contact_name_normalized', 'contact_phone', 'contact_phone_normalized', 'contact_email',
        'contact_id_number_hash', 'referred_by', 'summary', 'decline_reason', 'conflict_override_reason', 'conflict_result'] as $column) {
        expect($intake->getAttribute($column))->toBeNull("{$intake->code}.{$column}");
    }

    foreach (IntakeParty::query()->where('intake_request_id', $intake->getKey())->get() as $party) {
        expect([$party->name, $party->name_normalized, $party->phone_normalized, $party->id_number_hash])
            ->toBe([null, null, null, null]);
    }

    expect($intake->anonymised_at)->not->toBeNull();
}

/**
 * Mọi chỗ chuỗi `$needle` (không phân biệt hoa thường) còn nằm trong CSDL: "bảng#id" của từng dòng.
 *
 * @return list<string>
 */
function anpFindEverywhere(string $needle): array
{
    $found = [];

    // Chỉ CSDL của kết nối này: trên MariaDB `getTables()` không tham số liệt kê bảng của mọi CSDL trên máy.
    foreach (Schema::getTables(Schema::getCurrentSchemaListing()) as $table) {
        $name = $table['name'];

        if ($name === 'migrations') {
            continue;
        }

        foreach (DB::table($name)->get() as $row) {
            $text = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            if (stripos((string) $text, $needle) !== false) {
                $found[] = $name.'#'.($row->id ?? '?');
            }
        }
    }

    return $found;
}

// =================================================================================================
// Hạn lưu: chỉ bản ghi quá hạn, chưa chuyển đổi, chưa ẩn danh
// =================================================================================================

it('anonymises a declined, a lost and a merged record once retention_until has passed, and keeps each row for the numbers', function () {
    $assistant = anpStaff();

    $declined = anpRecord($assistant, ['contact_phone' => '0901000001', 'contact_email' => 'a@example.test', 'referred_by' => 'Anh Giới Thiệu',
        'contact_id_number' => '079123000001', 'quoted_amount' => '15000000'], [['name' => 'Bên Kia Một', 'role' => PartyRole::Defendant, 'phone' => '0977000001', 'id_number' => '079000111222']]);
    app(RecordPrivacyNotice::class)->handle($assistant, $declined, true);
    app(UpdateIntakeSummary::class)->handle($assistant, $declined, 'Câu chuyện của người gọi thứ nhất');
    app(DeclineIntake::class)->handle($assistant, $declined->fresh(), 'Ngoài lĩnh vực của văn phòng');

    $lost = app(ChangeIntakeStatus::class)->handle($assistant, anpRecord($assistant, ['contact_phone' => '0901000002']), IntakeStatus::Lost);

    $merged = anpRecord($assistant, ['contact_phone' => '0901000003']);
    $target = anpRecord($assistant, ['contact_phone' => '0901000004', 'contact_name' => 'Bản Đích Còn Mở']);
    app(MergeIntake::class)->handle($assistant, $merged, $target);

    $this->travelTo(now()->setDate(2028, 10, 4)->setTime(3, 30));

    expect(anpExpire())->toBe(['anonymised' => 3, 'skipped' => 0]);

    foreach ([$declined, $lost, $merged] as $record) {
        anpExpectPersonalDataGone($record);
        $fresh = $record->fresh();

        // Dòng, mã, nguồn, trạng thái và các mốc thời gian ở lại cho thống kê (R7b).
        expect($fresh->code)->toBe($record->code)
            ->and($fresh->source)->toBe(IntakeSource::Phone)
            ->and($fresh->received_at->toDateString())->toBe('2026-10-03')
            ->and($fresh->retention_until->toDateString())->toBe('2028-10-03')
            ->and($fresh->anonymised_at->toDateString())->toBe('2028-10-04')
            ->and($fresh->anonymised_by)->toBeNull()
            ->and($fresh->anonymised_reason)->toContain('03/10/2028');
    }

    expect($declined->fresh()->status)->toBe(IntakeStatus::Declined)
        ->and($declined->fresh()->quoted_amount)->toBe(15_000_000)
        ->and($declined->fresh()->contact_role)->toBe(PartyRole::Plaintiff)
        ->and($declined->fresh()->created_by)->toBe($assistant->id)
        ->and($declined->fresh()->privacy_notice_acknowledged_at)->not->toBeNull()
        ->and($declined->fresh()->conflict_checked_at)->not->toBeNull()
        ->and($declined->fresh()->parties()->count())->toBe(1)
        ->and($declined->fresh()->parties()->first()->role)->toBe(PartyRole::Defendant)
        ->and($lost->fresh()->status)->toBe(IntakeStatus::Lost)
        ->and($merged->fresh()->status)->toBe(IntakeStatus::Merged)
        ->and($merged->fresh()->merged_into_id)->toBe($target->id);

    // Bản đích còn mở: không đụng tới, kể cả bên đối lập nó nhận từ bản đã gộp.
    expect($target->fresh()->contact_name)->toBe('Bản Đích Còn Mở')
        ->and($target->fresh()->anonymised_at)->toBeNull();

    // Báo cáo vẫn đếm được: dòng còn, đúng trạng thái và nguồn.
    expect(IntakeRequest::query()->where('status', IntakeStatus::Declined->value)->where('source', IntakeSource::Phone->value)->count())->toBe(1)
        ->and(IntakeRequest::query()->whereIn('status', ['declined', 'lost', 'merged'])->whereNotNull('anonymised_at')->count())->toBe(3);

    $rows = Activity::query()->where('event', 'prospect_data_anonymised')->get();
    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('subject_id')->sort()->values()->all())->toBe(collect([$declined->id, $lost->id, $merged->id])->sort()->values()->all())
        ->and($rows->first()->properties->keys()->sort()->values()->all())->toBe(['code', 'retention_until'])
        ->and($rows->first()->causer_id)->toBeNull();
});

it('leaves alone a record whose retention date is today, an open one, a converted one and one already anonymised', function () {
    $assistant = anpStaff();

    $dueToday = app(DeclineIntake::class)->handle($assistant, anpRecord($assistant, ['contact_phone' => '0901000011']), 'Ngoài lĩnh vực');

    // Ba bản bị ép mang một hạn đã qua: chỉ trạng thái cuối "không thành khách", chưa chuyển đổi, chưa
    // ẩn danh mới bị đụng tới.
    $open = anpRecord($assistant, ['contact_phone' => '0901000012', 'contact_name' => 'Còn Mở']);
    $open->forceFill(['retention_until' => '2027-01-01'])->save();

    $won = anpRecord($assistant, ['contact_phone' => '0901000013', 'contact_name' => 'Đã Thành Khách']);
    $won->forceFill(['status' => IntakeStatus::Won, 'matter_id' => Matter::factory()->create()->id, 'retention_until' => '2027-01-01'])->save();

    $declinedButLinked = anpRecord($assistant, ['contact_phone' => '0901000014', 'contact_name' => 'Từ Chối Mà Có Vụ']);
    $declinedButLinked->forceFill(['status' => IntakeStatus::Declined, 'matter_id' => Matter::factory()->create()->id, 'retention_until' => '2027-01-01'])->save();

    $wonWithoutMatter = anpRecord($assistant, ['contact_phone' => '0901000015', 'contact_name' => 'Thắng Không Vụ']);
    $wonWithoutMatter->forceFill(['status' => IntakeStatus::Won, 'retention_until' => '2027-01-01'])->save();

    $already = anpRecord($assistant, ['contact_phone' => '0901000016', 'contact_name' => 'Đã Ẩn Danh Trước']);
    $already->forceFill(['status' => IntakeStatus::Lost, 'retention_until' => '2027-01-01', 'anonymised_at' => '2027-01-02 03:30:00'])->save();

    $this->travelTo(now()->setDate(2028, 10, 3)->setTime(23, 59));

    expect(anpExpire())->toBe(['anonymised' => 0, 'skipped' => 0])
        ->and($dueToday->fresh()->contact_name)->not->toBeNull()
        ->and($open->fresh()->contact_name)->toBe('Còn Mở')
        ->and($won->fresh()->contact_name)->toBe('Đã Thành Khách')
        ->and($declinedButLinked->fresh()->contact_name)->toBe('Từ Chối Mà Có Vụ')
        ->and($wonWithoutMatter->fresh()->contact_name)->toBe('Thắng Không Vụ')
        ->and($already->fresh()->contact_name)->toBe('Đã Ẩn Danh Trước')
        ->and($already->fresh()->anonymised_at->toDateTimeString())->toBe('2027-01-02 03:30:00');

    // Một ngày sau hạn thì đến lượt bản đến hạn — và chỉ nó.
    $this->travelTo(now()->setDate(2028, 10, 4)->setTime(0, 1));

    expect(anpExpire())->toBe(['anonymised' => 1, 'skipped' => 0])
        ->and($dueToday->fresh()->contact_name)->toBeNull()
        ->and($open->fresh()->contact_name)->toBe('Còn Mở')
        ->and($won->fresh()->contact_name)->toBe('Đã Thành Khách');
});

it('changes nothing when it runs a second time: same values, same anonymised_at, no second audit row', function () {
    $assistant = anpStaff();
    $declined = app(DeclineIntake::class)->handle($assistant, anpRecord($assistant), 'Ngoài lĩnh vực');

    $this->travelTo(now()->setDate(2028, 10, 4)->setTime(3, 30));
    expect(anpExpire())->toBe(['anonymised' => 1, 'skipped' => 0]);
    $first = IntakeRequest::query()->findOrFail($declined->id)->getAttributes();

    $this->travelTo(now()->setDate(2028, 10, 5)->setTime(3, 30));
    expect(anpExpire())->toBe(['anonymised' => 0, 'skipped' => 0])
        ->and(IntakeRequest::query()->findOrFail($declined->id)->getAttributes())->toBe($first)
        ->and(Activity::query()->where('event', 'prospect_data_anonymised')->count())->toBe(1);

    // Gọi thẳng đường hết hạn trên bản đã ẩn danh cũng không làm gì.
    expect(app(AnonymiseProspect::class)->expire($declined->fresh()))->toBeFalse();
});

it('also anonymises an expired record that was soft-deleted', function () {
    $assistant = anpStaff();
    $declined = app(DeclineIntake::class)->handle($assistant, anpRecord($assistant), 'Ngoài lĩnh vực');
    $declined->delete();

    $this->travelTo(now()->setDate(2028, 10, 4)->setTime(3, 30));

    expect(anpExpire())->toBe(['anonymised' => 1, 'skipped' => 0]);
    anpExpectPersonalDataGone($declined);
});

it('makes the old phone and ID invisible: a later check finds nothing and the duplicate hint stays silent', function () {
    $assistant = anpStaff();
    $declined = anpRecord($assistant, ['contact_name' => 'Người Đã Gọi', 'contact_phone' => '0955123456', 'contact_id_number' => '079123000077']);
    app(DeclineIntake::class)->handle($assistant, $declined, 'Ngoài lĩnh vực');

    $probe = fn () => app(RunConflictCheck::class)->handle(collect([
        (new MatterParty(['role' => PartyRole::Defendant, 'name' => 'Tên khác hẳn', 'is_our_client' => false]))->identify('079123000077', '0955123456'),
    ]));
    $hint = fn () => app(FindIntakeDuplicates::class)->handle($assistant, '0955123456', '079123000077', 'Người Đã Gọi');

    // Cặp dương: trước khi hết hạn, cả kiểm tra lẫn gợi ý trùng đều thấy bản ghi cũ.
    expect($probe()->matches->pluck('matterCode')->all())->toBe([$declined->code])
        ->and($hint()->sameIdentity->pluck('id')->all())->toBe([$declined->id]);

    $this->travelTo(now()->setDate(2028, 10, 4)->setTime(3, 30));
    anpExpire();

    expect($probe()->matches->all())->toBe([])
        ->and($probe()->level)->toBe(ConflictLevel::Green)
        ->and($hint()->sameIdentity->all())->toBe([])
        ->and($hint()->sameName->all())->toBe([]);
});

// =================================================================================================
// Xoá theo yêu cầu (R7c)
// =================================================================================================

it('refuses an erasure reason of 19 characters and accepts 20, counted in characters, not bytes', function () {
    $admin = anpStaff(Role::Admin);
    $intake = anpRecord(anpStaff());

    // 19 ký tự có dấu = 57 byte: đếm bằng `strlen` thì đã "đủ".
    foreach ([str_repeat('ố', 19), '   '.str_repeat('ố', 19).'   '] as $tooShort) {
        expect(fn () => app(AnonymiseProspect::class)->erase($admin, $intake, $tooShort))
            ->toThrow(ValidationException::class);
    }

    expect($intake->fresh()->anonymised_at)->toBeNull()
        ->and($intake->fresh()->contact_name)->toBe('Người Gọi Mẫu');

    app(AnonymiseProspect::class)->erase($admin, $intake, str_repeat('ố', 20));

    anpExpectPersonalDataGone($intake);
    expect($intake->fresh()->anonymised_by)->toBe($admin->id)
        ->and($intake->fresh()->anonymised_reason)->toBe(str_repeat('ố', 20))
        ->and($intake->fresh()->status)->toBe(IntakeStatus::New);
});

it('refuses an erasure reason longer than 2000 characters', function () {
    $admin = anpStaff(Role::Admin);
    $intake = anpRecord(anpStaff());

    expect(fn () => app(AnonymiseProspect::class)->erase($admin, $intake, str_repeat('a', 2001)))
        ->toThrow(ValidationException::class);

    app(AnonymiseProspect::class)->erase($admin, $intake, str_repeat('a', 2000));
    expect($intake->fresh()->anonymised_at)->not->toBeNull();
});

it('lets only an admin erase: a manager, a lawyer and the assistant who recorded it are refused', function (Role $role) {
    $assistant = anpStaff();
    $intake = anpRecord($assistant);
    $actor = $role === Role::Assistant ? $assistant : anpStaff($role);
    $intake->forceFill(['assigned_to' => $actor->id])->save();

    expect(fn () => app(AnonymiseProspect::class)->erase($actor, $intake, 'Người liên hệ yêu cầu xoá qua điện thoại'))
        ->toThrow(AuthorizationException::class)
        ->and($intake->fresh()->contact_name)->toBe('Người Gọi Mẫu')
        ->and(Activity::query()->where('event', 'prospect_data_erased')->exists())->toBeFalse();
})->with(['manager' => [Role::Manager], 'lawyer' => [Role::Lawyer], 'assistant' => [Role::Assistant]]);

it('refuses to erase a converted record, saying the data now follows the client file', function (Closure $state) {
    $admin = anpStaff(Role::Admin);
    $intake = anpRecord(anpStaff());
    $intake->forceFill($state())->save();

    try {
        app(AnonymiseProspect::class)->erase($admin, $intake->fresh(), 'Người liên hệ yêu cầu xoá qua điện thoại');
        $this->fail('phải từ chối');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['intake' => [__('intake.anonymise.errors.converted')]]);
    }

    expect($intake->fresh()->contact_name)->toBe('Người Gọi Mẫu')
        ->and($intake->fresh()->anonymised_at)->toBeNull();
})->with([
    'won with a matter' => [fn () => ['status' => IntakeStatus::Won, 'matter_id' => Matter::factory()->create()->id]],
    'won' => [fn () => ['status' => IntakeStatus::Won]],
    'linked to a matter' => [fn () => ['matter_id' => Matter::factory()->create()->id]],
]);

it('refuses to erase a record whose data is already gone', function () {
    $admin = anpStaff(Role::Admin);
    $intake = anpRecord(anpStaff());
    app(AnonymiseProspect::class)->erase($admin, $intake, 'Người liên hệ yêu cầu xoá qua điện thoại');

    try {
        app(AnonymiseProspect::class)->erase($admin, $intake->fresh(), 'Yêu cầu lần hai, cùng người liên hệ');
        $this->fail('phải từ chối');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['intake' => [__('intake.anonymise.errors.already')]]);
    }

    expect(Activity::query()->where('event', 'prospect_data_erased')->count())->toBe(1);
});

it('records who erased, why and which record — never the values that were erased', function () {
    $assistant = anpStaff();
    $admin = anpStaff(Role::Admin);
    $intake = anpRecord($assistant, ['contact_name' => 'Hoàng Thị Riêng Tư', 'contact_phone' => '0966555444', 'contact_email' => 'rieng@example.test',
        'referred_by' => 'Người Giới Thiệu Kín', 'contact_id_number' => '079123000099'], [['name' => 'Bên Kia Kín', 'role' => PartyRole::Defendant, 'phone' => '0977000555']]);
    app(RecordPrivacyNotice::class)->handle($assistant, $intake, true);
    app(UpdateIntakeSummary::class)->handle($assistant, $intake, 'Câu chuyện kín của người liên hệ');

    app(AnonymiseProspect::class)->erase($admin, $intake->fresh(), '  Yêu cầu qua điện thoại ngày 03/10/2026, đã xác minh số gọi đến  ');

    $row = Activity::query()->where('event', 'prospect_data_erased')->sole();

    expect($row->causer_id)->toBe($admin->id)
        ->and($row->subject_type)->toBe('intake_request')
        ->and($row->subject_id)->toBe($intake->id)
        ->and($row->properties->all())->toBe([
            'code' => $intake->code,
            'reason' => 'Yêu cầu qua điện thoại ngày 03/10/2026, đã xác minh số gọi đến',
        ]);

    $text = json_encode($row->toArray(), JSON_UNESCAPED_UNICODE);
    foreach (['Riêng Tư', '0966555444', '966555444', 'rieng@', 'Giới Thiệu Kín', 'Bên Kia Kín', 'Câu chuyện kín', Normalizer::idNumberHash('079123000099')] as $erased) {
        expect($text)->not->toContain($erased);
    }
});

// =================================================================================================
// Dữ liệu người liên hệ để lại NGOÀI các cột của bản ghi
// =================================================================================================

it('cleans its own trail: names in its conflict checks, the override reasons and the confirmed-pair signatures', function () {
    anpExistingClient();
    $assistant = anpStaff();
    $manager = anpStaff(Role::Manager);

    // Đỏ (bên đối lập mang SĐT khách hiện hữu) → quản lý ghi đè có lý do; thêm một khớp Vàng theo tên
    // rồi xác nhận, để có đủ ba loại dòng: kiểm tra, ghi đè, xác nhận.
    MatterParty::factory()->for(Matter::factory()->create())->create(['name' => 'Phạm Văn Vàng', 'role' => PartyRole::Related]);
    $intake = anpRecord($assistant, ['contact_name' => 'Người Gọi Đỏ'], [
        ['name' => 'Bị Đơn Là Khách', 'role' => PartyRole::Defendant, 'phone' => '0912000111'],
        ['name' => 'Phạm Văn Vàng', 'role' => PartyRole::Defendant],
    ]);
    app(ResolveIntakeRedConflict::class)->handle($manager, $intake, 'Đã xem xét: ông Bị Đơn Là Khách đã rút khỏi vụ cũ');
    app(RecordPrivacyNotice::class)->handle($assistant, $intake->fresh(), true);

    $own = fn () => Activity::query()->where('subject_type', 'intake_request')->where('subject_id', $intake->id);

    expect($own()->where('event', 'conflict_check_run')->count())->toBeGreaterThan(0)
        ->and($own()->where('event', 'intake_conflict_overridden')->sole()->properties->get('confirmed_pairs'))->not->toBe([]);

    app(AnonymiseProspect::class)->erase(anpStaff(Role::Admin), $intake->fresh(), 'Người liên hệ yêu cầu xoá qua điện thoại');

    $placeholder = __('intake.anonymise.placeholder');

    foreach ($own()->where('event', 'conflict_check_run')->get() as $run) {
        foreach ([...$run->properties->get('matches', []), ...$run->properties->get('confirmed_matches', [])] as $match) {
            expect($match['our_party_name'])->toBe($placeholder)
                ->and($match['party_name'])->toBe($placeholder);
        }

        expect(collect($run->properties->get('incomplete_parties', []))->unique()->all())->toBeIn([[], [$placeholder]]);
    }

    $override = $own()->where('event', 'intake_conflict_overridden')->sole();
    expect($override->properties->get('override_reason'))->toBe($placeholder)
        ->and($override->properties->has('confirmed_pairs'))->toBeFalse()
        ->and($override->properties->get('level'))->toBe(ConflictLevel::Red->value);

    $text = json_encode($own()->get()->pluck('properties'), JSON_UNESCAPED_UNICODE);
    expect($text)->not->toContain('Bị Đơn Là Khách')
        ->and($text)->not->toContain('Phạm Văn Vàng')
        ->and($text)->not->toContain('Người Gọi Đỏ')
        ->and($text)->not->toContain('rút khỏi vụ cũ');
});

it('drops the confirmed-pair signatures of a yellow acknowledgement on the record', function () {
    $assistant = anpStaff();
    MatterParty::factory()->for(Matter::factory()->create())->create(['name' => 'Phạm Văn Vàng', 'role' => PartyRole::Related]);
    $intake = anpRecord($assistant, [], [['name' => 'Phạm Văn Vàng', 'role' => PartyRole::Defendant]]);
    app(AcknowledgeIntakeConflict::class)->handle($assistant, $intake, ConflictLevel::Yellow);

    $ack = fn () => Activity::query()->where('event', 'intake_conflict_acknowledged')->where('subject_id', $intake->id)->sole();
    expect($ack()->properties->get('confirmed_pairs'))->not->toBe([]);

    app(AnonymiseProspect::class)->erase(anpStaff(Role::Admin), $intake->fresh(), 'Người liên hệ yêu cầu xoá qua điện thoại');

    expect($ack()->properties->has('confirmed_pairs'))->toBeFalse()
        ->and($ack()->properties->get('level'))->toBe(ConflictLevel::Yellow->value);
});

it('removes its name from the conflict evidence of OTHER records that found it, by its TN code, and keeps the rest of that evidence', function () {
    $assistant = anpStaff();
    $lawyer = anpStaff(Role::Lawyer);

    $a = anpRecord($assistant, ['contact_name' => 'Nguyễn Văn Đã Gọi', 'contact_phone' => '0955123456'], [['name' => 'Bên Của A', 'role' => PartyRole::Defendant, 'phone' => '0977000321']]);

    // B (người khác) khai A là bên đối lập qua SĐT, khai bên của A qua SĐT, và khai thêm một người trùng
    // tên một bên của một vụ đã có (khớp KHÔNG trỏ tới A — phải giữ nguyên).
    MatterParty::factory()->for(Matter::factory()->create())->create(['name' => 'Phạm Văn Vàng', 'role' => PartyRole::Related]);
    $b = anpRecord($assistant, ['contact_name' => 'Lê Văn Bình', 'contact_phone' => '0903333444', 'contact_role' => PartyRole::Defendant], [
        ['name' => 'Tên khác hẳn', 'role' => PartyRole::Plaintiff, 'phone' => '+84 955 123 456'],
        ['name' => 'Một tên khác nữa', 'role' => PartyRole::Plaintiff, 'phone' => '0977000321'],
        ['name' => 'Phạm Văn Vàng', 'role' => PartyRole::Plaintiff],
    ]);
    $bBefore = $b->fresh()->conflict_result;
    expect(collect($bBefore['matches'])->pluck('party_name')->all())->toContain('Phạm Văn Vàng');

    // Một vụ mở với bên đối lập mang SĐT của A: lần đầu đòi xác nhận (dòng kiểm tra không chủ thể),
    // lần sau lưu (dòng kiểm tra gắn vụ).
    $type = MatterType::factory()->withStages()->create();
    $attributes = ['client_id' => Client::factory()->create()->id, 'client_role' => PartyRole::Plaintiff, 'matter_type_id' => $type->id,
        'title' => 'Vụ có bên từng liên hệ', 'lead_lawyer_id' => $lawyer->id];
    $parties = [['role' => PartyRole::Defendant, 'name' => 'Bên tên khác', 'phone' => '0955123456']];

    try {
        app(OpenMatter::class)->handle($lawyer, $attributes, $parties);
    } catch (ConflictAcknowledgementRequired) {
    }

    $matter = app(OpenMatter::class)->handle($lawyer, $attributes, $parties, acknowledged: ConflictLevel::Yellow)->matter;

    $runsFindingA = fn () => Activity::query()->where('event', 'conflict_check_run')
        ->where(fn ($q) => $q->whereNull('subject_id')->orWhere('subject_type', 'matter')->orWhere('subject_id', '!=', $a->id))
        ->get()
        ->filter(fn (Activity $run) => collect($run->properties->get('matches', []))->contains('matter_code', $a->code));

    expect($runsFindingA())->toHaveCount(3)
        ->and(collect($b->fresh()->conflict_result['matches'])->where('matter_code', $a->code)->pluck('party_name')->sort()->values()->all())
        ->toBe(['Bên Của A', 'Nguyễn Văn Đã Gọi']);

    // Đồng hồ chạy tiếp, để một lần ghi đè `updated_at` của B (không được có) lộ ra.
    $this->travel(5)->minutes();
    app(AnonymiseProspect::class)->erase(anpStaff(Role::Admin), $a, 'Người liên hệ yêu cầu xoá qua điện thoại');

    $placeholder = __('intake.anonymise.placeholder');

    foreach ([$b->fresh()->conflict_result, ...$runsFindingA()->map(fn (Activity $run) => $run->properties->all())->all()] as $evidence) {
        foreach ($evidence['matches'] as $match) {
            if ($match['matter_code'] === $a->code) {
                // Tên của A biến mất; mã, vai, mức, bậc khớp và ngày liên hệ ở lại làm bằng chứng.
                expect($match['party_name'])->toBe($placeholder)
                    ->and($match['level'])->toBe(ConflictLevel::Yellow->value)
                    ->and($match['tier'])->toBe('phone')
                    ->and($match['contacted_on'])->toBe('2026-10-03');
            } else {
                expect($match['party_name'])->not->toBe($placeholder);
            }

            // Phía "của mình" là của B / của vụ — không đụng tới.
            expect($match['our_party_name'])->not->toBe($placeholder);
        }
    }

    expect($b->fresh()->contact_name)->toBe('Lê Văn Bình')
        ->and(collect($b->fresh()->conflict_result['matches'])->pluck('party_name')->all())->toContain('Phạm Văn Vàng')
        ->and($b->fresh()->conflict_result['incomplete_parties'])->toBe($bBefore['incomplete_parties'])
        ->and($b->fresh()->conflict_result['fingerprint'])->toBe($bBefore['fingerprint'])->and($bBefore['fingerprint'])->not->toBeNull()
        ->and($b->fresh()->updated_at->equalTo($b->updated_at))->toBeTrue()
        ->and($matter->parties()->where('name', 'Bên tên khác')->exists())->toBeTrue();
});

it('removes the opposing party it carried into a repeat call by the same person, and keeps the parties of that call', function () {
    anpExistingClient();
    $receptionist = anpStaff();
    $manager = anpStaff(Role::Manager);

    // A (Đỏ vì bên đối lập là khách hiện hữu) được quản lý ghi đè; B là cùng người gọi lại (cùng SĐT,
    // cùng vai) và tự khai một bên riêng: lần kiểm tra của B mang bên đối lập của A sang.
    $a = anpRecord($receptionist, ['contact_name' => 'Người Gọi Hai Lần'], [['name' => 'Zqxcarried Bên Của A', 'role' => PartyRole::Defendant, 'phone' => '0912000111']]);
    app(ResolveIntakeRedConflict::class)->handle($manager, $a, 'Đã xem xét, bên kia đã rút khỏi vụ cũ');

    MatterParty::factory()->for(Matter::factory()->create())->create(['name' => 'Bên Riêng Của B', 'role' => PartyRole::Related]);
    $b = anpRecord($receptionist, ['contact_name' => 'Anh Gọi Lại'], [['name' => 'Bên Riêng Của B', 'role' => PartyRole::Defendant]]);

    $ours = fn (IntakeRequest $intake) => collect($intake->fresh()->conflict_result['matches'])->pluck('our_party_name')->sort()->values()->all();
    expect($ours($b))->toBe(['Bên Riêng Của B', 'Zqxcarried Bên Của A']);

    app(AnonymiseProspect::class)->erase(anpStaff(Role::Admin), $a->fresh(), 'Người liên hệ yêu cầu xoá qua điện thoại');

    expect($ours($b))->toBe([__('intake.anonymise.placeholder'), 'Bên Riêng Của B'])
        ->and($b->fresh()->contact_name)->toBe('Anh Gọi Lại')
        ->and($b->fresh()->parties()->pluck('name')->all())->toBe(['Bên Riêng Của B'])
        ->and(anpFindEverywhere('zqxcarried'))->toBe([]);
});

/** Ô của một lần chuyển đổi (`ConvertIntakeToMatter`), người bấm phụ trách vụ. */
function anpConversionAttributes(User $actor): array
{
    return [
        'title' => 'Vụ từ một lần tiếp nhận',
        'matter_type_id' => MatterType::factory()->withStages()->create(['is_active' => true])->id,
        'lead_lawyer_id' => $actor->id,
        'client_role' => PartyRole::Plaintiff->value,
        'opened_at' => today()->toDateString(),
        'confidentiality' => Confidentiality::Normal->value,
        'client_type' => ClientType::Individual->value,
    ];
}

/** Một lần chuyển đổi bị `OpenMatter` từ chối (Đỏ chưa ghi đè, hay chưa xác nhận): trả lớp ngoại lệ. */
function anpRefusedConversion(User $actor, IntakeRequest $intake): string
{
    try {
        app(ConvertIntakeToMatter::class)->handle($actor, $intake, anpConversionAttributes($actor));
    } catch (ConflictBlocked|ConflictAcknowledgementRequired $refused) {
        return $refused::class;
    }

    throw new RuntimeException('Lần chuyển đổi phải bị từ chối.');
}

/**
 * Bản ghi đầu của một chuỗi gộp cùng người (SĐT mặc định), có câu chuyện, gộp `$hops` lần — mỗi lần vào
 * một bản ghi mới của cùng người. Trả mọi bản theo thứ tự; bản cuối còn mở.
 *
 * @return list<IntakeRequest>
 */
function anpMergeChain(User $actor, int $hops): array
{
    $first = anpRecord($actor, ['contact_name' => 'Người Gọi Nhiều Lần']);
    app(RecordPrivacyNotice::class)->handle($actor, $first, true);
    app(UpdateIntakeSummary::class)->handle($actor, $first->fresh(), 'Câu chuyện kể ở lần gọi đầu');
    $chain = [$first];

    for ($hop = 0; $hop < $hops; $hop++) {
        $next = anpRecord($actor, ['contact_name' => 'Người Gọi Nhiều Lần']);
        app(MergeIntake::class)->handle($actor, $chain[$hop]->fresh(), $next);
        $chain[] = $next;
    }

    return $chain;
}

/** Chuyển thành vụ việc qua đúng Action của Task 4; xác nhận mức được hỏi nếu có. */
function anpConvert(User $actor, IntakeRequest $intake): void
{
    try {
        app(ConvertIntakeToMatter::class)->handle($actor, $intake->fresh(), anpConversionAttributes($actor));
    } catch (ConflictAcknowledgementRequired $ask) {
        app(ConvertIntakeToMatter::class)->handle($actor, $intake->fresh(), anpConversionAttributes($actor), acknowledged: $ask->result->level);
    }

    expect($intake->fresh()->status)->toBe(IntakeStatus::Won);
}

/*
 * Test chuỗi đánh dấu của kế hoạch: mỗi thứ người liên hệ A kể cho văn phòng mang một chuỗi riêng —
 * tên, email, người giới thiệu, câu chuyện, lý do từ chối, lý do ghi đè, tên bên đối lập — cộng SĐT và
 * dấu băm CCCD. A còn bị một bản ghi khác (B) và một vụ việc tìm thấy qua SĐT. Sau khi A hết hạn,
 * quét MỌI bảng của CSDL: không còn chuỗi nào; SĐT của A chỉ còn ở hai dòng thuộc về người KHÁC (bên
 * đối lập B tự khai, bên của vụ) — đó là dữ liệu của họ, không phải của A (PROGRESS, Ghi chú M10).
 *
 * Fix vòng 1 (rà soát Task 7, C1): trước khi từ chối, mỗi bản ghi có một lần chuyển đổi bị từ chối —
 * A ở Đỏ (quản lý không ghi lý do ghi đè), A2 ở Vàng (bên đối lập chỉ có tên, trùng tên bên của A, chưa
 * xác nhận). Dòng `conflict_check_run` của lần đó mang tên các bên mà không mang mã `TN-…` của chính
 * bản ghi (bản đang chuyển đổi bị loại khỏi nguồn dò thứ hai).
 *
 * Gộp làn m10-t7 vào m10-intake (việc (a) của PROGRESS Task 7 mục 7): A được ghi một sáng Thứ Năm và
 * còn ở `new` quá ngưỡng phản hồi, nên lượt nhắc của Task 5 đã chạy cho A TRƯỚC khi ẩn danh — thông báo
 * trong hệ thống, thư `staff.intake_unanswered` (dòng `outbound_messages` thật). Lần quét cả hai phía
 * (trước và sau hết hạn) phủ cả hai bảng đó.
 */
it('leaves no trace of anything the contact told the office in any table once the retention has passed', function () {
    $this->travelTo(now()->setDate(2026, 10, 1)->setTime(9, 0));
    anpExistingClient();
    $assistant = anpStaff();
    $manager = anpStaff(Role::Manager);
    $lawyer = anpStaff(Role::Lawyer);

    $a = anpRecord($assistant, [
        'contact_name' => 'Zqxanp Contact',
        'contact_phone' => '0919 876 543',
        'contact_email' => 'zqxanp-mail@example.test',
        'contact_id_number' => '079123456789',
        'referred_by' => 'Zqxanp Referrer',
    ], [['name' => 'Zqxanp Party', 'role' => PartyRole::Defendant, 'phone' => '0912000111']]);
    expect($a->conflict_level)->toBe(ConflictLevel::Red);

    $this->travelTo(now()->setDate(2026, 10, 1)->setTime(14, 0));
    expect(app(RemindUnansweredIntakes::class)->handle())->toBe(['reminded' => 1]);
    $reminderRows = fn (): array => [
        DB::table('notifications')->where('type', IntakeUnansweredAlert::class)->where('notifiable_id', $manager->id)->count(),
        OutboundMessage::query()->withoutGlobalScopes()->where('template', IntakeUnanswered::TEMPLATE)
            ->where('related_id', $a->id)->where('status', OutboundStatus::Sent)->count(),
    ];
    expect($reminderRows())->toBe([1, 1]);
    $this->travelTo(now()->setDate(2026, 10, 3)->setTime(15, 0));

    app(ResolveIntakeRedConflict::class)->handle($manager, $a, 'ZQXANP-OVERRIDE đã xem xét kỹ với luật sư phụ trách');
    app(RecordPrivacyNotice::class)->handle($assistant, $a->fresh(), true);
    app(UpdateIntakeSummary::class)->handle($assistant, $a->fresh(), 'ZQXANP-STORY: người liên hệ kể chuyện gia đình');
    expect(anpRefusedConversion($manager, $a->fresh()))->toBe(ConflictBlocked::class);
    app(DeclineIntake::class)->handle($assistant, $a->fresh(), 'ZQXANP-DECLINE ngoài khả năng của văn phòng');

    $a2 = anpRecord($lawyer, ['contact_name' => 'Zqxanp Second Contact', 'contact_phone' => '0907111222'],
        [['name' => 'Zqxanp Party', 'role' => PartyRole::Defendant]]);
    expect(anpRefusedConversion($lawyer, $a2->fresh()))->toBe(ConflictAcknowledgementRequired::class);
    app(DeclineIntake::class)->handle($lawyer, $a2->fresh(), 'Không theo tiếp');

    $b = anpRecord($assistant, ['contact_name' => 'Lê Văn Bình', 'contact_phone' => '0903333444', 'contact_role' => PartyRole::Defendant],
        [['name' => 'Tên khác hẳn', 'role' => PartyRole::Plaintiff, 'phone' => '0919876543']]);

    $type = MatterType::factory()->withStages()->create();
    $attributes = ['client_id' => Client::factory()->create()->id, 'client_role' => PartyRole::Plaintiff, 'matter_type_id' => $type->id,
        'title' => 'Vụ có bên từng liên hệ', 'lead_lawyer_id' => $lawyer->id];
    $parties = [['role' => PartyRole::Defendant, 'name' => 'Bên tên khác', 'phone' => '0919876543']];

    try {
        app(OpenMatter::class)->handle($lawyer, $attributes, $parties);
    } catch (ConflictAcknowledgementRequired) {
    }

    $matter = app(OpenMatter::class)->handle($lawyer, $attributes, $parties, acknowledged: ConflictLevel::Yellow)->matter;
    $hash = Normalizer::idNumberHash('079123456789');
    // HMAC của SĐT như sổ tra khách ghi: chữ số của đúng chuỗi đã gõ (`FindClientByIdentifier`).
    $phoneLookupHash = Audit::identifierHash('0919876543');
    $lookupRows = fn (): array => Activity::query()->where('event', 'client_lookup')->orderBy('id')->pluck('id')->all();

    // Cặp dương: trước khi hết hạn, các chuỗi có mặt ở nhiều bảng — kể cả hai dấu băm trong sổ tra khách.
    expect(anpFindEverywhere('zqxanp'))->not->toBe([])
        ->and(collect(anpFindEverywhere('zqxanp'))->map(fn ($hit) => strstr($hit, '#', true))->unique()->sort()->values()->all())
        ->toBe(['activity_log', 'intake_parties', 'intake_requests'])
        ->and(anpFindEverywhere($hash))->not->toBe([])
        ->and(anpFindEverywhere($phoneLookupHash))->not->toBe([]);

    $lookupsBefore = $lookupRows();

    $this->travelTo(now()->setDate(2028, 10, 4)->setTime(3, 30));
    expect(anpExpire())->toBe(['anonymised' => 2, 'skipped' => 0]);

    // Việc sau gộp M9 + M10 (làn fu3, Task 1 mục E): sổ tra khách `client_lookup` — nơi Task 7 từng CỐ Ý
    // giữ dấu băm — nay mất dấu băm SĐT và CCCD của A (HMAC của CCCD bằng đúng dấu băm cũ của bản ghi từ
    // M8 Task 4), còn DÒNG thì ở lại: ai tra, lúc nào, trúng hay trượt.
    expect(anpFindEverywhere('zqxanp'))->toBe([])
        ->and(anpFindEverywhere($hash))->toBe([])
        ->and(anpFindEverywhere($phoneLookupHash))->toBe([])
        ->and($lookupRows())->toBe($lookupsBefore)
        ->and(anpFindEverywhere('919876543'))->toEqualCanonicalizing([
            'intake_parties#'.$b->parties()->sole()->id,
            'matter_parties#'.$matter->parties()->where('name', 'Bên tên khác')->sole()->id,
        ])
        // Thông báo và nhật ký thư của lượt nhắc còn đó (không mang gì của A, nên không có gì để làm sạch).
        ->and($reminderRows())->toBe([1, 1]);
});

it('keeps the names a repeat call holds itself, even when they equal an opposing party of the anonymised call', function (bool $asContact) {
    anpExistingClient();
    $receptionist = anpStaff();
    $manager = anpStaff(Role::Manager);
    MatterParty::factory()->for(Matter::factory()->create())->create(['name' => 'Tên Trùng Nhau', 'role' => PartyRole::Related]);

    $a = anpRecord($receptionist, ['contact_name' => 'Người Gọi Hai Lần'], [['name' => 'Tên Trùng Nhau', 'role' => PartyRole::Defendant, 'phone' => '0912000111']]);
    app(ResolveIntakeRedConflict::class)->handle($manager, $a, 'Đã xem xét, bên kia đã rút khỏi vụ cũ');

    // B cùng người gọi lại, và CHÍNH B mang tên đó — là bên đối lập B tự khai, hoặc là tên B tự xưng.
    $b = $asContact
        ? anpRecord($receptionist, ['contact_name' => 'Tên Trùng Nhau'])
        : anpRecord($receptionist, ['contact_name' => 'Anh Gọi Lại'], [['name' => 'Tên Trùng Nhau', 'role' => PartyRole::Defendant]]);

    $ourNames = fn () => collect($b->fresh()->conflict_result['matches'])->pluck('our_party_name')->filter(fn ($n) => $n === 'Tên Trùng Nhau')->count();
    $before = $ourNames();
    expect($before)->toBeGreaterThanOrEqual(2);

    app(AnonymiseProspect::class)->erase(anpStaff(Role::Admin), $a->fresh(), 'Người liên hệ yêu cầu xoá qua điện thoại');

    expect($ourNames())->toBe($before);
})->with(['an opposing party of the repeat call' => [false], 'the name of the repeat caller' => [true]]);

it('does not touch any other record when the anonymised call had neither a phone nor an ID', function () {
    anpExistingClient();
    $receptionist = anpStaff();
    $manager = anpStaff(Role::Manager);

    // E rồi C: cùng người (cùng số), C mang bên đối lập "Tên Chung" của E sang.
    $e = anpRecord($receptionist, ['contact_name' => 'Người E', 'contact_phone' => '0901999888'], [['name' => 'Tên Chung', 'role' => PartyRole::Defendant, 'phone' => '0912000111']]);
    app(ResolveIntakeRedConflict::class)->handle($manager, $e, 'Đã xem xét, bên kia đã rút khỏi vụ cũ');
    $c = anpRecord($receptionist, ['contact_name' => 'Người C', 'contact_phone' => '0901999888']);
    $evidence = $c->fresh()->conflict_result;
    expect(collect($evidence['matches'])->pluck('our_party_name')->all())->toContain('Tên Chung');

    // A không có số, không có căn cước, và cũng khai một bên tên "Tên Chung".
    $a = anpRecord($receptionist, ['contact_name' => 'Người Không Số', 'contact_phone' => null], [['name' => 'Tên Chung', 'role' => PartyRole::Defendant]]);

    app(AnonymiseProspect::class)->erase(anpStaff(Role::Admin), $a, 'Người liên hệ yêu cầu xoá qua điện thoại');

    expect($c->fresh()->conflict_result)->toBe($evidence);
});

it('tells an admin a converted record is out of reach before it looks at the reason', function () {
    $admin = anpStaff(Role::Admin);
    $intake = anpRecord(anpStaff());
    $intake->forceFill(['status' => IntakeStatus::Won, 'matter_id' => Matter::factory()->create()->id])->save();

    try {
        app(AnonymiseProspect::class)->erase($admin, $intake->fresh(), 'quá ngắn');
        $this->fail('phải từ chối');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['intake' => [__('intake.anonymise.errors.converted')]]);
    }
});

// =================================================================================================
// Bản đã gộp vào một bản về sau thành khách (fix vòng 1, rà soát Task 7, I1)
// =================================================================================================

/*
 * Người gọi hai lần: lần A được gộp vào lần T, rồi T thành vụ việc — người đó đã là khách. Gộp để câu
 * chuyện và danh tính ở lại A (`MergeIntake`), nên A là một phần hồ sơ của một khách: R7c ("đã là
 * khách, dữ liệu theo hồ sơ khách") áp dọc `merged_into_id`, tới bản cuối của chuỗi gộp.
 */
it('never anonymises a record merged, directly or through another merge, into one that became a client', function (int $hops) {
    $lawyer = anpStaff(Role::Lawyer);
    $chain = anpMergeChain($lawyer, $hops);

    // Cặp dương: một bản gộp vào một bản vẫn còn mở thì vẫn hết hạn như cũ.
    $other = anpRecord($lawyer, ['contact_name' => 'Người Khác', 'contact_phone' => '0901000201']);
    app(MergeIntake::class)->handle($lawyer, $other, anpRecord($lawyer, ['contact_name' => 'Bản Đích Còn Mở', 'contact_phone' => '0901000202']));

    anpConvert($lawyer, end($chain));

    $this->travelTo(now()->setDate(2028, 10, 4)->setTime(3, 30));

    expect(anpExpire())->toBe(['anonymised' => 1, 'skipped' => 0])
        ->and($other->fresh()->contact_name)->toBeNull()
        ->and($chain[0]->fresh()->contact_name)->toBe('Người Gọi Nhiều Lần')
        ->and($chain[0]->fresh()->summary)->toBe('Câu chuyện kể ở lần gọi đầu');

    foreach (array_slice($chain, 0, -1) as $merged) {
        expect($merged->fresh()->anonymised_at)->toBeNull()
            ->and(app(AnonymiseProspect::class)->expire($merged->fresh()))->toBeFalse();
    }
})->with(['merged straight into it' => [1], 'merged into a record later merged into it' => [2]]);

/*
 * Lưới thứ hai: dù bản đó vì lý do nào còn mang một hạn đã qua (một đường chuyển đổi khác, dữ liệu cũ),
 * cửa hết hạn đọc lại chuỗi gộp trên dòng vừa khoá và không đụng tới nó.
 */
it('leaves a record merged into a client\'s record alone even when it still carries a past retention date', function () {
    $lawyer = anpStaff(Role::Lawyer);
    [$first, $target] = anpMergeChain($lawyer, 1);
    anpConvert($lawyer, $target);
    $first->forceFill(['retention_until' => '2027-01-01'])->save();

    $this->travelTo(now()->setDate(2028, 10, 4)->setTime(3, 30));

    expect(anpExpire())->toBe(['anonymised' => 0, 'skipped' => 0])
        ->and($first->fresh()->contact_name)->toBe('Người Gọi Nhiều Lần')
        ->and($first->fresh()->anonymised_at)->toBeNull()
        ->and(Activity::query()->where('event', 'prospect_data_anonymised')->exists())->toBeFalse();
});

it('refuses to erase a record merged, directly or through another merge, into one that became a client, naming that record', function (int $hops) {
    $lawyer = anpStaff(Role::Lawyer);
    $admin = anpStaff(Role::Admin);
    $chain = anpMergeChain($lawyer, $hops);
    $client = end($chain);

    // Trước khi bản cuối thành vụ: xoá được (bản này chỉ để thử, không xoá thật).
    expect(AnonymiseProspect::refusal($chain[0]->fresh()))->toBeNull();

    anpConvert($lawyer, $client);

    foreach (array_slice($chain, 0, -1) as $merged) {
        try {
            app(AnonymiseProspect::class)->erase($admin, $merged->fresh(), 'Người liên hệ yêu cầu xoá qua điện thoại');
            $this->fail('phải từ chối');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toBe(['intake' => [__('intake.anonymise.errors.converted_through_merge', ['code' => $client->code])]]);
        }

        expect($merged->fresh()->contact_name)->toBe('Người Gọi Nhiều Lần');
    }

    expect(Activity::query()->where('event', 'prospect_data_erased')->exists())->toBeFalse();
})->with(['merged straight into it' => [1], 'merged into a record later merged into it' => [2]]);

it('treats the end of a merge chain as converted when it is won, linked to a matter, or converted and later soft-deleted', function (Closure $convert) {
    $lawyer = anpStaff(Role::Lawyer);
    [$first, $target] = anpMergeChain($lawyer, 1);
    $convert($lawyer, $target);

    expect(AnonymiseProspect::refusal($first->fresh()))
        ->toBe(__('intake.anonymise.errors.converted_through_merge', ['code' => $target->code]));
})->with([
    'won' => [fn (User $lawyer, IntakeRequest $target) => $target->forceFill(['status' => IntakeStatus::Won])->save()],
    'linked to a matter' => [fn (User $lawyer, IntakeRequest $target) => $target->forceFill(['matter_id' => Matter::factory()->create()->id])->save()],
    'converted, then soft-deleted' => [function (User $lawyer, IntakeRequest $target): void {
        anpConvert($lawyer, $target);
        $target->fresh()->delete();
    }],
]);

/*
 * `MergeIntake` không tạo được vòng (chỉ gộp VÀO một bản chưa gộp đi); dựng thẳng một vòng để chắc hai
 * lần đi theo `merged_into_id` vẫn dừng.
 */
it('stops on a merge chain that loops instead of following it forever', function () {
    $lawyer = anpStaff(Role::Lawyer);
    $a = anpRecord($lawyer, ['contact_phone' => '0901000301']);
    $b = anpRecord($lawyer, ['contact_phone' => '0901000302']);
    $a->forceFill(['status' => IntakeStatus::Merged, 'merged_into_id' => $b->id])->save();
    $b->forceFill(['status' => IntakeStatus::Merged, 'merged_into_id' => $a->id])->save();

    expect($a->fresh()->mergeChainEnd()->id)->toBe($b->id)
        ->and($a->fresh()->mergedFromTreeIds())->toBe([$b->id])
        ->and(AnonymiseProspect::refusal($a->fresh()))->toBeNull();
});

it('still erases a record merged into one that is open, declined or lost', function (Closure $close) {
    $lawyer = anpStaff(Role::Lawyer);
    [$first, $target] = anpMergeChain($lawyer, 1);
    $close($lawyer, $target);

    app(AnonymiseProspect::class)->erase(anpStaff(Role::Admin), $first->fresh(), 'Người liên hệ yêu cầu xoá qua điện thoại');

    expect($first->fresh()->contact_name)->toBeNull()
        ->and($target->fresh()->contact_name)->toBe('Người Gọi Nhiều Lần');
})->with([
    'open' => [fn () => null],
    'declined' => [fn (User $lawyer, IntakeRequest $target) => app(DeclineIntake::class)->handle($lawyer, $target->fresh(), 'Ngoài lĩnh vực')],
    'lost' => [fn (User $lawyer, IntakeRequest $target) => app(ChangeIntakeStatus::class)->handle($lawyer, $target->fresh(), IntakeStatus::Lost)],
]);

// =================================================================================================
// Tác vụ hằng ngày: một bản hỏng hay bận không chặn cả lượt
// =================================================================================================

/*
 * Bản ghi mà Action không lấy được khoá `conflict-check` trong 10 giây (một người đang ghi tiếp nhận)
 * bị bỏ qua, lượt chạy đi tiếp, và lượt sau làm lại. Test này chạy với ĐỒNG HỒ THẬT (khoá cache đọc giờ
 * hiện tại; đồng hồ đóng băng thì `block(10)` không bao giờ hết giờ) và chờ đủ 10 giây.
 */
it('skips a record it cannot lock and anonymises it on the next run', function () {
    Exceptions::fake();
    $this->travelBack();
    $assistant = anpStaff();
    $declined = app(DeclineIntake::class)->handle($assistant, anpRecord($assistant), 'Ngoài lĩnh vực');
    $declined->forceFill(['retention_until' => now()->subDay()->toDateString()])->save();

    $lock = Cache::store('database')->lock('conflict-check', 120);
    expect($lock->get())->toBeTrue();

    try {
        expect(anpExpire())->toBe(['anonymised' => 0, 'skipped' => 1])
            ->and($declined->fresh()->contact_name)->toBe('Người Gọi Mẫu');
    } finally {
        $lock->release();
    }

    expect(anpExpire())->toBe(['anonymised' => 1, 'skipped' => 0])
        ->and($declined->fresh()->contact_name)->toBeNull();

    // Bận không phải lỗi: không báo cáo gì.
    Exceptions::assertNothingReported();
});

it('reports a record that fails, and still anonymises the others', function () {
    Exceptions::fake();
    $assistant = anpStaff();
    $broken = app(DeclineIntake::class)->handle($assistant, anpRecord($assistant, ['contact_name' => 'Bản Hỏng', 'contact_phone' => '0901000101']), 'Ngoài lĩnh vực');
    $fine = app(DeclineIntake::class)->handle($assistant, anpRecord($assistant, ['contact_name' => 'Bản Lành', 'contact_phone' => '0901000102']), 'Ngoài lĩnh vực');

    app()->bind(AnonymiseProspect::class, fn () => new class($broken->id) extends AnonymiseProspect
    {
        public function __construct(private int $brokenId) {}

        public function expire(IntakeRequest $intake): bool
        {
            if ($intake->id === $this->brokenId) {
                throw new RuntimeException('hỏng giữa chừng');
            }

            return parent::expire($intake);
        }
    });

    $this->travelTo(now()->setDate(2028, 10, 4)->setTime(3, 30));

    expect(anpExpire())->toBe(['anonymised' => 1, 'skipped' => 1])
        ->and($broken->fresh()->contact_name)->toBe('Bản Hỏng')
        ->and($fine->fresh()->contact_name)->toBeNull();

    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'hỏng giữa chừng');
});

/*
 * Khe giữa lúc tác vụ liệt kê các bản quá hạn và lúc Action khoá từng dòng: một admin vừa xoá theo yêu
 * cầu đúng bản đó. Action đọc lại điều kiện trên dòng đã khoá và không làm gì — và lượt chạy không đếm
 * nó là "đã ẩn danh".
 */
it('does not count a record that was erased by an admin between the listing and the lock', function () {
    $assistant = anpStaff();
    $declined = app(DeclineIntake::class)->handle($assistant, anpRecord($assistant), 'Ngoài lĩnh vực');
    $admin = anpStaff(Role::Admin);

    app()->bind(AnonymiseProspect::class, fn () => new class($admin) extends AnonymiseProspect
    {
        public function __construct(private User $admin) {}

        public function expire(IntakeRequest $intake): bool
        {
            (new AnonymiseProspect)->erase($this->admin, $intake, 'Người liên hệ yêu cầu xoá qua điện thoại');

            return parent::expire($intake);
        }
    });

    $this->travelTo(now()->setDate(2028, 10, 4)->setTime(3, 30));

    expect(anpExpire())->toBe(['anonymised' => 0, 'skipped' => 0])
        ->and($declined->fresh()->anonymised_by)->toBe($admin->id)
        ->and(Activity::query()->where('event', 'prospect_data_anonymised')->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'prospect_data_erased')->count())->toBe(1);
});
