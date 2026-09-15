<?php

use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Exceptions\MatterNotDestroyable;
use App\Exceptions\StageNotConfigured;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\StageLog;
use App\Models\User;
use Illuminate\Database\QueryException;

it('generates a code per year and type and starts at the first stage', function () {
    $dd = MatterType::factory()->withStages()->create(['code' => 'DD']);
    $ds = MatterType::factory()->withStages()->create(['code' => 'DS']);
    $year = now()->format('Y');

    $a = Matter::factory()->for($dd, 'matterType')->create();
    $b = Matter::factory()->for($dd, 'matterType')->create();
    $c = Matter::factory()->for($ds, 'matterType')->create();

    expect($a->code)->toBe("VK-{$year}-DD-0001")
        ->and($b->code)->toBe("VK-{$year}-DD-0002")
        ->and($c->code)->toBe("VK-{$year}-DS-0001")
        ->and($a->stage)->toBe('intake')
        ->and($a->currentStage()->label)->toBe('Tiếp nhận')
        ->and($a->stage_entered_at)->not->toBeNull()
        ->and($a->opened_at->isToday())->toBeTrue()
        ->and($a->confidentiality)->toBe(Confidentiality::Normal)
        ->and(Matter::factory()->unpublished()->create()->is_published_to_portal)->toBeFalse();
});

/**
 * M1 mang sang: khi loại vụ việc chưa cấu hình giai đoạn nào, `firstStage()` trả về null và
 * `matters.stage` (NOT NULL) sẽ bị vi phạm với lỗi DB thô. Giờ `Matter::creating` ném
 * `StageNotConfigured` có thông điệp tiếng Việt nêu rõ tên loại vụ việc.
 */
it('refuses to open a matter whose type has no stages configured', function () {
    $type = MatterType::factory()->create(['name' => 'Thử nghiệm không giai đoạn']);

    expect(fn () => Matter::factory()->for($type, 'matterType')->create())
        ->toThrow(StageNotConfigured::class, 'Thử nghiệm không giai đoạn');
});

it('uses the configured matter code prefix', function () {
    config(['vkcrm.matter_code_prefix' => 'LVK']);
    $matter = Matter::factory()->create();

    expect($matter->code)->toStartWith('LVK-');
});

it('keeps a team with roles', function () {
    $matter = Matter::factory()->create();
    $associate = User::factory()->create();

    $matter->addTeamMember($associate, MatterRole::Associate);

    $matter->refresh();

    expect($matter->team)->toHaveCount(2)
        ->and($matter->team->firstWhere('id', $matter->lead_lawyer_id)->pivot->role_in_matter)->toBe(MatterRole::Lead)
        ->and($matter->team->firstWhere('id', $associate->id)->pivot->role_in_matter)->toBe(MatterRole::Associate)
        ->and($associate->teamMatters->first()->is($matter))->toBeTrue()
        ->and($matter->leadLawyer->leadMatters->first()->is($matter))->toBeTrue();
});

it('rejects the same user twice in one team', function () {
    $matter = Matter::factory()->create();

    expect(fn () => $matter->addTeamMember($matter->leadLawyer, MatterRole::Observer))
        ->toThrow(QueryException::class);
});

it('belongs to a client and a type', function () {
    $matter = Matter::factory()->create();

    expect($matter->client->matters->first()->is($matter))->toBeTrue()
        ->and($matter->matterType->matters->first()->is($matter))->toBeTrue();
});

it('can be soft deleted but never force deleted', function () {
    $matter = Matter::factory()->create();
    StageLog::factory()->for($matter)->create();

    $matter->delete();
    expect(Matter::withTrashed()->find($matter->id)->trashed())->toBeTrue();

    expect(fn () => $matter->forceDelete())->toThrow(MatterNotDestroyable::class)
        ->and(StageLog::count())->toBe(1);
});
