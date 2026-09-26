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

/**
 * Fix round 1 (việc D): `CodeSequence::next()` commit số thứ tự trong transaction riêng của
 * nó, chạy trước khi StageNotConfigured được ném. Nếu giữ nguyên thứ tự cũ (sinh mã trước,
 * kiểm tra giai đoạn sau), mỗi lần một loại vụ việc chưa có giai đoạn bị chọn sẽ tiêu mất một
 * số thứ tự dù không vụ việc nào được tạo. `Matter::creating` giờ kiểm tra giai đoạn (và ném
 * lỗi nếu cần) trước khi gọi nextCode().
 */
it('does not burn a code sequence number when a create fails with StageNotConfigured', function () {
    $type = MatterType::factory()->create(['code' => 'ZZ']);

    expect(fn () => Matter::factory()->for($type, 'matterType')->create())
        ->toThrow(StageNotConfigured::class);

    $type->stages()->create([
        'key' => 'intake', 'label' => 'Tiếp nhận', 'client_label' => 'Tiếp nhận',
        'sort_order' => 1, 'allowed_next' => [],
    ]);
    $type->unsetRelation('stages');

    $matter = Matter::factory()->for($type, 'matterType')->create();

    expect($matter->code)->toEndWith('-0001');
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

/**
 * R8 (M6.5 Task 5): `scopeOpen()` là định nghĩa DUY NHẤT của "vụ đang mở" — `closed_at` null và
 * chưa xoá mềm. Ba trường hợp cùng một khẳng định: một vụ bình thường đang mở lọt qua, một vụ đã
 * đóng (`closed_at` có giá trị) bị loại, và một vụ đã huỷ (xoá mềm, `closed_at` vẫn null) cũng bị
 * loại — vế thứ hai không tự nhiên đến từ SoftDeletingScope một mình vì scope này còn phải đứng
 * vững sau khi ai đó gỡ global scope (`withTrashed()`), nên nó tự khẳng định lại `deleted_at`.
 */
it('scopeOpen keeps only matters with no closed_at and not soft deleted', function () {
    $open = Matter::factory()->create();
    $closed = Matter::factory()->create(['closed_at' => now()->subDay()]);
    $cancelled = Matter::factory()->create();
    $cancelled->delete();

    $ids = Matter::query()->withTrashed()->open()->pluck('id');

    expect($ids)->toContain($open->id)
        ->and($ids)->not->toContain($closed->id)
        ->and($ids)->not->toContain($cancelled->id);
});
