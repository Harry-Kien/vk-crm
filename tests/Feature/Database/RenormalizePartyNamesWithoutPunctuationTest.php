<?php

use App\Actions\RunConflictCheck;
use App\Enums\ConflictLevel;
use App\Enums\PartyRole;
use App\Models\Client;
use App\Models\IntakeParty;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Support\Normalizer;
use Illuminate\Support\Facades\DB;

/**
 * Rà soát toàn hệ thống 2026-10-22 (làn intake): migration
 * `2026_10_22_000001_renormalize_party_names_without_punctuation.php` tính lại các cột tên chuẩn hoá đã
 * lưu theo `Normalizer::name()` mới (bỏ dấu câu). Migration đã chạy trên CSDL rỗng trước mọi test, nên
 * test ghi thẳng giá trị CŨ (còn dấu câu) vào cột rồi gọi lại `up()`/`down()` — cùng thành ngữ
 * `RehashMatterPartyIdNumberHashesTest`.
 */
function renormalizePartyNamesMigration(): object
{
    return require database_path('migrations/2026_10_22_000001_renormalize_party_names_without_punctuation.php');
}

it('recomputes stored party and intake names so a company saved with a trailing period matches again', function () {
    $company = Client::factory()->create(['name' => 'Công ty TNHH ABC.']);
    $matter = Matter::factory()->create();
    $party = MatterParty::factory()->for($matter)->ourClient($company, PartyRole::Plaintiff)->create();
    DB::table('matter_parties')->where('id', $party->id)->update(['name_normalized' => 'cong ty tnhh abc.']);

    $intake = IntakeRequest::factory()->create(['contact_name' => 'Nguyễn-Văn A', 'organization_name' => '“ABC” Co., Ltd']);
    DB::table('intake_requests')->where('id', $intake->id)->update([
        'contact_name_normalized' => 'nguyen-van a',
        'organization_name_normalized' => '"abc" co., ltd',
    ]);
    $intakeParty = IntakeParty::factory()->for($intake, 'intakeRequest')->create(['name' => 'Bà (Trần) Thị B']);
    DB::table('intake_parties')->where('id', $intakeParty->id)->update(['name_normalized' => 'ba (tran) thi b']);

    $updatedAt = DB::table('matter_parties')->where('id', $party->id)->value('updated_at');

    // Trước migration: tên gõ không dấu chấm không khớp gì.
    $check = fn () => app(RunConflictCheck::class)->handle(collect([
        (new MatterParty(['role' => PartyRole::Plaintiff, 'name' => 'Khách mới', 'is_our_client' => true])),
        (new MatterParty(['role' => PartyRole::Defendant, 'name' => 'Công ty TNHH ABC'])),
    ]));
    expect($check()->matches->where('matterCode', $matter->code))->toBeEmpty();

    renormalizePartyNamesMigration()->up();

    expect(DB::table('matter_parties')->where('id', $party->id)->value('name_normalized'))->toBe('cong ty tnhh abc')
        ->and(DB::table('intake_requests')->where('id', $intake->id)->value('contact_name_normalized'))->toBe(Normalizer::name('Nguyễn-Văn A'))
        ->and(DB::table('intake_requests')->where('id', $intake->id)->value('organization_name_normalized'))->toBe('abc co ltd')
        ->and(DB::table('intake_parties')->where('id', $intakeParty->id)->value('name_normalized'))->toBe('ba tran thi b')
        ->and(DB::table('matter_parties')->where('id', $party->id)->value('updated_at'))->toBe($updatedAt);

    $after = $check();
    expect($after->level)->toBe(ConflictLevel::Yellow)
        ->and($after->matches->where('matterCode', $matter->code))->toHaveCount(1);

    renormalizePartyNamesMigration()->down();

    expect(DB::table('matter_parties')->where('id', $party->id)->value('name_normalized'))->toBe('cong ty tnhh abc.')
        ->and(DB::table('intake_parties')->where('id', $intakeParty->id)->value('name_normalized'))->toBe('ba (tran) thi b');
});

it('leaves anonymised names null', function () {
    $intake = IntakeRequest::factory()->create();
    DB::table('intake_requests')->where('id', $intake->id)->update([
        'contact_name' => null,
        'contact_name_normalized' => null,
        'organization_name' => null,
        'organization_name_normalized' => null,
    ]);

    renormalizePartyNamesMigration()->up();

    expect(DB::table('intake_requests')->where('id', $intake->id)->value('contact_name_normalized'))->toBeNull()
        ->and(DB::table('intake_requests')->where('id', $intake->id)->value('organization_name_normalized'))->toBeNull();
});
