<?php

use App\Actions\Intake\AcknowledgeIntakeConflict;
use App\Actions\Intake\IntakeSummaryGate;
use App\Actions\Intake\RecordIntake;
use App\Actions\Intake\RecordPrivacyNotice;
use App\Actions\Intake\RerunIntakeConflictCheck;
use App\Actions\Intake\ResolveIntakeRedConflict;
use App\Actions\Intake\UpdateIntakeSummary;
use App\Actions\RunConflictCheck;
use App\Enums\ConflictLevel;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\IntakeSummaryBlocker;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Exceptions\ConflictBlocked;
use App\Models\Client;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/*
 * M10 Task 2, fix vòng 1 (C1): NGƯỜI GỌI LẠI. Người liên hệ khớp SĐT/CCCD với người liên hệ của một
 * lần tiếp nhận khác còn mở, CÙNG vai, là cùng một người gọi lại về cùng một việc. Ba điều phải đúng:
 *  - các bên đối lập khai ở lần gọi trước được MANG vào lần kiểm tra của lần gọi lại (Đỏ bật lại từ
 *    chính khách hàng hiện hữu, không phải từ nguồn thứ hai — nguồn đó tối đa Vàng);
 *  - lần gọi trước còn Đỏ chưa xử lý, hoặc đã bị từ chối vì xung đột, thì khớp với nó KHÔNG bị bỏ
 *    (mã TN-… hiện ra) và lần gọi lại bị KHOÁ như Đỏ cho tới khi quản lý/admin xử lý;
 *  - lần gọi trước lành thì vẫn không thành khớp (R4 gộp xử lý), như trước.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function rcStaff(Role $role = Role::Assistant): User
{
    return User::factory()->withRole($role)->create();
}

/** Một khách hàng hiện hữu, đã có vụ, mang số 0912000111. */
function rcExistingClient(string $phone = '0912000111', ?string $idNumber = '079012345678'): Client
{
    $client = Client::factory()->create(['phone' => $phone, 'id_number' => $idNumber, 'name' => 'Khách Hiện Hữu']);
    MatterParty::factory()->for(Matter::factory()->create())->ourClient($client)->create();

    return $client;
}

/** Người gọi X, nguyên đơn, 0832270898 — trừ khi ghi đè. */
function rcRecord(User $actor, array $overrides = [], array $parties = []): IntakeRequest
{
    return app(RecordIntake::class)->handle($actor, [...[
        'contact_name' => 'Người Gọi',
        'contact_phone' => '0832270898',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
    ], ...$overrides], $parties)->intake;
}

/** Bên đối lập mang SĐT của khách hiện hữu, ở vai đối lập với nguyên đơn: Đỏ. */
function rcRedParties(): array
{
    return [['name' => 'Bị Đơn Là Khách', 'role' => PartyRole::Defendant, 'phone' => '0912000111']];
}

/** @return list<string> */
function rcMatchedCodes(IntakeRequest $intake): array
{
    return collect($intake->conflict_result['matches'])->pluck('matter_code')->all();
}

it('keeps the callback of a caller whose earlier call is red and unresolved locked for an assistant, until a manager overrides it', function () {
    rcExistingClient();
    $receptionistA = rcStaff();
    $receptionistB = rcStaff();
    $manager = rcStaff(Role::Manager);

    $first = rcRecord($receptionistA, [], rcRedParties());
    expect($first->conflict_level)->toBe(ConflictLevel::Red);

    // Hôm sau người đó gọi lại; trợ lý KHÁC ghi (không thấy được bản trước), gõ số theo cách khác,
    // cùng vai, không nhắc lại bên đối lập.
    $second = rcRecord($receptionistB, ['contact_phone' => '0832 270 898']);

    $red = collect($second->conflict_result['matches'])->firstWhere('level', 'red');

    expect($second->conflict_level)->toBe(ConflictLevel::Red)
        // Đỏ đến từ khách hàng hiện hữu, qua bên đối lập đã khai ở lần gọi trước.
        ->and($red['our_party_name'])->toBe('Bị Đơn Là Khách')
        ->and($red['tier'])->toBe('phone')
        // Lần gọi trước đang Đỏ chưa xử lý: khớp với nó không bị bỏ, mã của nó hiện ra.
        ->and(rcMatchedCodes($second))->toContain($first->code)
        ->and($second->conflict_red_pending_since)->not->toBeNull();

    app(RecordPrivacyNotice::class)->handle($receptionistB, $second, true);

    expect(IntakeSummaryGate::blockers($second->fresh()))->toBe([IntakeSummaryBlocker::ConflictRed])
        ->and(fn () => app(UpdateIntakeSummary::class)->handle($receptionistB, $second, 'Toàn bộ câu chuyện về khách hiện hữu'))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(AcknowledgeIntakeConflict::class)->handle($receptionistB, $second, ConflictLevel::Red))
        ->toThrow(ConflictBlocked::class)
        ->and(fn () => app(ResolveIntakeRedConflict::class)->handle($receptionistB, $second, 'Tôi thấy không sao'))
        ->toThrow(AuthorizationException::class);

    expect($second->fresh()->summary)->toBeNull();

    app(ResolveIntakeRedConflict::class)->handle($manager, $second, 'Đã xem cả hai lần gọi, bên kia đã rút khỏi vụ cũ');

    // R13(c): ghi đè che đúng các khớp quản lý đã thấy, kể cả khớp của bên mang sang — một lần chạy
    // lại không có gì mới (lần gọi trước vẫn Đỏ chưa xử lý) không khoá lại lần gọi lại.
    app(RerunIntakeConflictCheck::class)->handle($receptionistB, $second->fresh());

    expect(IntakeSummaryGate::isOpen($second->fresh()))->toBeTrue();

    app(UpdateIntakeSummary::class)->handle($receptionistB, $second, 'Câu chuyện sau khi quản lý xử lý');

    expect($second->fresh()->summary)->toBe('Câu chuyện sau khi quản lý xử lý');
});

it('locks the callback even when the earlier red can no longer be reproduced, because its opposing party was removed', function () {
    rcExistingClient();
    $receptionistA = rcStaff();
    $receptionistB = rcStaff();

    $first = rcRecord($receptionistA, [], rcRedParties());
    $first->parties()->first()->delete();
    app(RerunIntakeConflictCheck::class)->handle($receptionistA, $first->fresh());

    expect($first->fresh()->conflict_level)->toBe(ConflictLevel::Green);

    $second = rcRecord($receptionistB);

    // Không còn bên nào để mang sang: chỉ còn khớp Vàng với lần gọi trước — nhưng lần gọi trước vẫn
    // là một Đỏ chưa ai xử lý, nên lần gọi lại bị khoá như nó.
    expect($second->conflict_level)->toBe(ConflictLevel::Yellow)
        ->and(rcMatchedCodes($second))->toBe([$first->code])
        ->and($second->conflict_red_pending_since)->not->toBeNull();

    app(RecordPrivacyNotice::class)->handle($receptionistB, $second, true);
    app(AcknowledgeIntakeConflict::class)->handle($receptionistB, $second, ConflictLevel::Yellow);

    expect(IntakeSummaryGate::blockers($second->fresh()))->toBe([IntakeSummaryBlocker::ConflictRed])
        ->and(fn () => app(UpdateIntakeSummary::class)->handle($receptionistB, $second, 'Câu chuyện'))
        ->toThrow(ValidationException::class);
});

it('locks the callback of a caller the office declined for a conflict, and not one declined for another reason', function (bool $forConflict) {
    $receptionistA = rcStaff();
    $receptionistB = rcStaff();

    $first = rcRecord($receptionistA);
    $first->forceFill([
        'status' => IntakeStatus::Declined, 'decline_reason' => 'Lý do từ chối', 'decline_reason_is_conflict' => $forConflict,
    ])->save();

    $second = rcRecord($receptionistB);
    app(RecordPrivacyNotice::class)->handle($receptionistB, $second, true);

    if ($forConflict) {
        expect($second->conflict_level)->toBe(ConflictLevel::Yellow)
            ->and(rcMatchedCodes($second))->toBe([$first->code])
            ->and(IntakeSummaryGate::blockers($second->fresh()))->toBe([IntakeSummaryBlocker::ConflictRed]);
    } else {
        expect($second->conflict_level)->toBe(ConflictLevel::Green)
            ->and(rcMatchedCodes($second))->toBe([])
            ->and(IntakeSummaryGate::isOpen($second->fresh()))->toBeTrue();
    }
})->with(['declined for a conflict' => true, 'declined for another reason' => false]);

it('still treats the callback after a resolved call as the same person, but carries the earlier opposing parties so it needs its own decision', function () {
    rcExistingClient();
    $receptionistA = rcStaff();
    $receptionistB = rcStaff();
    $manager = rcStaff(Role::Manager);

    $first = rcRecord($receptionistA, [], rcRedParties());
    app(ResolveIntakeRedConflict::class)->handle($manager, $first, 'Đã xem xét, bên kia đã rút khỏi vụ cũ');

    $second = rcRecord($receptionistB);
    app(RecordPrivacyNotice::class)->handle($receptionistB, $second, true);

    // Ghi đè của bản trước là quyết định cho bản trước: lần gọi lại Đỏ lại (cùng khách hiện hữu,
    // qua bên đối lập mang sang) và đòi quản lý/admin xử lý lần nữa. Khớp với chính lần gọi trước
    // (đã xử lý) thì không hiện: cùng một người.
    expect($second->conflict_level)->toBe(ConflictLevel::Red)
        ->and(rcMatchedCodes($second))->not->toContain($first->code)
        ->and(IntakeSummaryGate::blockers($second->fresh()))->toBe([IntakeSummaryBlocker::ConflictRed]);
});

it('does not match the carried parties against the earlier call they came from, and does not count them as missing identity', function () {
    $receptionistA = rcStaff();
    $receptionistB = rcStaff();

    $first = rcRecord($receptionistA, [], [
        ['name' => 'Bên Có Số', 'role' => PartyRole::Defendant, 'phone' => '0955000111'],
        ['name' => 'Bên Chỉ Có Tên', 'role' => PartyRole::Related],
    ]);

    expect($first->conflict_result['incomplete_parties'])->toBe(['Bên Chỉ Có Tên']);

    $second = rcRecord($receptionistB);
    app(RecordPrivacyNotice::class)->handle($receptionistB, $second, true);

    expect($second->conflict_level)->toBe(ConflictLevel::Green)
        ->and($second->conflict_result['matches'])->toBe([])
        ->and($second->conflict_result['incomplete_parties'])->toBe([])
        ->and(IntakeSummaryGate::isOpen($second->fresh()))->toBeTrue();
});

it('still matches the carried parties against the other people the office has heard from', function () {
    $receptionistA = rcStaff();
    $receptionistB = rcStaff();

    $first = rcRecord($receptionistA, [], [['name' => 'Bên Kia', 'role' => PartyRole::Defendant, 'phone' => '0955000111']]);
    // Bên kia cũng từng tự gọi tới văn phòng (một bản ghi khác, người khác).
    $other = rcRecord($receptionistA, ['contact_name' => 'Bên Kia Tự Gọi', 'contact_phone' => '0955000111', 'contact_role' => PartyRole::Related]);

    $second = rcRecord($receptionistB);

    expect($second->conflict_level)->toBe(ConflictLevel::Yellow)
        ->and(rcMatchedCodes($second))->toBe([$other->code])
        ->and($second->conflict_result['matches'][0]['our_party_name'])->toBe('Bên Kia');
});

it('does not count an opposing party repeated on the callback as a match against the earlier call', function () {
    $receptionistA = rcStaff();
    $receptionistB = rcStaff();

    rcRecord($receptionistA, [], [['name' => 'Bên Kia', 'role' => PartyRole::Defendant, 'phone' => '0955000111']]);
    $second = rcRecord($receptionistB, [], [['name' => 'Bên Kia', 'role' => PartyRole::Defendant, 'phone' => '0955000111']]);

    expect($second->conflict_level)->toBe(ConflictLevel::Green)
        ->and($second->conflict_result['matches'])->toBe([]);
});

it('lets an override of the callback stand across a re-run while the earlier call is still unresolved, and locks it again once its identity changes', function () {
    $receptionistA = rcStaff();
    $receptionistB = rcStaff();
    $manager = rcStaff(Role::Manager);

    $first = rcRecord($receptionistA);
    $first->forceFill(['status' => IntakeStatus::Declined, 'decline_reason' => 'Xung đột', 'decline_reason_is_conflict' => true])->save();

    $second = rcRecord($receptionistB);
    app(RecordPrivacyNotice::class)->handle($receptionistB, $second, true);
    app(ResolveIntakeRedConflict::class)->handle($manager, $second, 'Đã xem lần gọi trước, việc lần này khác hẳn');

    app(RerunIntakeConflictCheck::class)->handle($receptionistB, $second->fresh());

    expect(IntakeSummaryGate::isOpen($second->fresh()))->toBeTrue();

    // Sửa danh tính: ghi đè cũ nói về một danh tính khác, và lần gọi trước vẫn bị từ chối vì xung đột.
    $second->fresh()->forceFill(['contact_name' => 'Tên Đã Sửa'])->save();
    app(RerunIntakeConflictCheck::class)->handle($receptionistB, $second->fresh());

    expect(IntakeSummaryGate::blockers($second->fresh()))->toContain(IntakeSummaryBlocker::ConflictRed);
});

it('does not carry or lock across a different role: two people sharing one phone are not the same caller', function () {
    rcExistingClient();
    $receptionistA = rcStaff();
    $receptionistB = rcStaff();

    // Vợ (nguyên đơn) gọi từ máy bàn nhà, Đỏ chưa xử lý.
    $wife = rcRecord($receptionistA, ['contact_name' => 'Vợ', 'contact_phone' => '0955123456'], rcRedParties());

    // Chồng gọi từ CHÍNH máy đó, ở vai bị đơn: một người khác — Vàng "đã liên hệ", không mang bên đối
    // lập của vợ sang, không bị khoá như Đỏ.
    $husband = rcRecord($receptionistB, ['contact_name' => 'Chồng', 'contact_phone' => '0955123456', 'contact_role' => PartyRole::Defendant]);

    expect($husband->conflict_level)->toBe(ConflictLevel::Yellow)
        ->and(rcMatchedCodes($husband))->toBe([$wife->code])
        ->and($husband->conflict_red_pending_since)->toBeNull();
});

it('never treats a name-only match as the same caller, and treats the same id number as one', function () {
    rcExistingClient();
    $receptionistA = rcStaff();
    $receptionistB = rcStaff();

    $first = rcRecord($receptionistA, ['contact_name' => 'Nguyễn Văn Tú', 'contact_id_number' => '001099887766'], rcRedParties());

    // Cùng tên, khác số, không CCCD: không phải cùng một người gọi lại.
    $sameName = rcRecord($receptionistB, ['contact_name' => 'nguyen van tu', 'contact_phone' => '0977000222']);

    expect($sameName->conflict_level)->toBe(ConflictLevel::Yellow)
        ->and($sameName->conflict_red_pending_since)->toBeNull();

    // Cùng CCCD, số máy khác: cùng một người gọi lại.
    $sameId = rcRecord($receptionistB, ['contact_name' => 'Nguyễn Văn Tú', 'contact_phone' => '0977000333', 'contact_id_number' => '001099887766']);

    expect($sameId->conflict_level)->toBe(ConflictLevel::Red)
        ->and(rcMatchedCodes($sameId))->toContain($first->code)
        ->and($sameId->conflict_red_pending_since)->not->toBeNull();
});

it('ignores an earlier call that was merged, converted or anonymised', function (array $closed) {
    rcExistingClient();
    $receptionistA = rcStaff();
    $receptionistB = rcStaff();

    $first = rcRecord($receptionistA, [], rcRedParties());
    $target = rcRecord($receptionistA, ['contact_name' => 'Bản đích', 'contact_phone' => '0977111222']);
    $first->forceFill(array_map(fn ($value) => $value === 'target' ? $target->id : ($value === 'matter' ? Matter::factory()->create()->id : $value), $closed))->save();

    $second = rcRecord($receptionistB);

    expect($second->conflict_level)->toBe(ConflictLevel::Green)
        ->and($second->conflict_red_pending_since)->toBeNull();
})->with([
    'merged' => [['status' => IntakeStatus::Merged, 'merged_into_id' => 'target']],
    'converted' => [['status' => IntakeStatus::Won, 'matter_id' => 'matter']],
    'anonymised' => [['anonymised_at' => '2026-09-30 10:00:00']],
]);

it('takes the lock of a repeat call from another record only, not from the record itself', function () {
    $receptionist = rcStaff();

    $intake = rcRecord($receptionist);
    $intake->forceFill(['status' => IntakeStatus::Declined, 'decline_reason' => 'Xung đột', 'decline_reason_is_conflict' => true])->save();

    // Điều một lần từ chối làm với ô câu chuyện của CHÍNH bản ghi là việc của Task 3 (R8); khoá
    // "người gọi lại" chỉ đến từ một bản ghi KHÁC của cùng người.
    app(RerunIntakeConflictCheck::class)->handle($receptionist, $intake->fresh());

    expect($intake->fresh()->conflict_red_pending_since)->toBeNull();
});

it('never makes a contact with no phone and no id number a repeat caller of anyone', function () {
    $receptionistA = rcStaff();
    $receptionistB = rcStaff();

    $first = rcRecord($receptionistA);
    $first->forceFill(['status' => IntakeStatus::Declined, 'decline_reason' => 'Xung đột', 'decline_reason_is_conflict' => true])->save();

    // Cùng vai, tên khác, không SĐT, không CCCD: không có gì để nói đây là cùng một người.
    $nameless = rcRecord($receptionistB, ['contact_name' => 'Một Người Khác', 'contact_phone' => null]);

    expect($nameless->conflict_red_pending_since)->toBeNull()
        ->and(rcMatchedCodes($nameless))->toBe([]);
});

it('uses the role implied from the opposing party, like the check does, to find the earlier call that locks the callback', function () {
    $receptionistA = rcStaff();
    $receptionistB = rcStaff();

    $first = rcRecord($receptionistA);
    $first->forceFill(['status' => IntakeStatus::Declined, 'decline_reason' => 'Xung đột', 'decline_reason_is_conflict' => true])->save();

    // Lần gọi lại chưa khai vai, nhưng khai một bị đơn: vai dùng cho lần kiểm tra là nguyên đơn — đúng
    // vai lần gọi trước đã khai, nên đây là cùng một người gọi lại.
    $second = rcRecord($receptionistB, ['contact_role' => null], [['name' => 'Bị Đơn Mới', 'role' => PartyRole::Defendant, 'phone' => '0966000111']]);

    expect(rcMatchedCodes($second))->toBe([$first->code])
        ->and($second->conflict_red_pending_since)->not->toBeNull();
});

it('does not carry the parties of an earlier call that the caller of the check excluded, and survives a check with no contact', function () {
    rcExistingClient();
    $receptionistA = rcStaff();

    $first = rcRecord($receptionistA, [], rcRedParties());
    $second = rcRecord($receptionistA);

    expect($second->conflict_level)->toBe(ConflictLevel::Red);

    $contact = (new MatterParty(['role' => PartyRole::Plaintiff, 'name' => 'Người Gọi', 'is_our_client' => true]))->identify(null, '0832270898');

    // `excludeIntakeId` loại một lần tiếp nhận khỏi MỌI vai trò trong lần kiểm tra, kể cả làm "lần gọi
    // trước" để mang bên đối lập sang.
    $excluded = app(RunConflictCheck::class)->handle(collect([$contact]), null, $receptionistA, null, null, $second, $first->id);

    expect($excluded->level)->toBe(ConflictLevel::Green);

    // Chủ thể là một lần tiếp nhận nhưng không có bên nào là người liên hệ: không có người gọi lại nào.
    $opposingOnly = (new MatterParty(['role' => PartyRole::Defendant, 'name' => 'Ai Đó', 'is_our_client' => false]))->identify(null, '0977123123');
    $noContact = app(RunConflictCheck::class)->handle(collect([$opposingOnly]), null, $receptionistA, null, null, $second);

    expect($noContact->level)->toBe(ConflictLevel::Green);
});
