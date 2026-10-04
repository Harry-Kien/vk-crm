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
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

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

/**
 * Gộp M9 (xung đột 2): nửa kia của CÙNG một định nghĩa. M9 cần "vụ đã kết thúc" (widget "Hồ sơ
 * đã kết thúc còn công nợ", bộ lọc cùng tên ở trang Công nợ, dòng cảnh báo ở tab tiền) và từng tự
 * viết `whereNotNull('closed_at')` ở bốn chỗ. `scopeClosed()` là phần bù của `scopeOpen()` TRONG
 * các vụ chưa huỷ: đã kết thúc = `closed_at` có giá trị VÀ chưa xoá mềm — một vụ đã huỷ không
 * "đang mở" mà cũng không "đã kết thúc", nó đã huỷ. `isClosed()` là bản trong bộ nhớ, cùng hai
 * điều kiện, và phải trả cùng câu trả lời với scope cho cả ba vụ.
 */
it('scopeClosed keeps only closed matters that were not cancelled, and isClosed agrees with it', function () {
    $open = Matter::factory()->create();
    $closed = Matter::factory()->create(['closed_at' => now()->subDay()]);
    $closedThenCancelled = Matter::factory()->create(['closed_at' => now()->subDays(2)]);
    $closedThenCancelled->delete();

    $ids = Matter::query()->withTrashed()->closed()->pluck('id');

    expect($ids->all())->toBe([$closed->id]);

    foreach ([$open, $closed, $closedThenCancelled] as $matter) {
        $fresh = Matter::withTrashed()->find($matter->id);

        expect($fresh->isClosed())->toBe($ids->contains($matter->id))
            ->and($fresh->isOpen() && $fresh->isClosed())->toBeFalse();
    }
});

/**
 * Mã nguồn `$source` có dùng `closed_at` làm ĐIỀU KIỆN không — lọc SQL hay so trong bộ nhớ.
 *
 * Tách thành một hàm nhận mã nguồn (M13 Task 2, khuôn `forceDeleteCallLines()` của
 * `RecordsAreNeverForceDeletedTest`) để chạy được trên một FIXTURE, không chỉ trên `app/`: không có
 * cách đặt một tệp mẫu vào `app/`, nên trước bản tách này không test nào chứng minh được bộ quét bắt
 * đúng thứ nó phải bắt. Chỉ đọc MÃ: docblock/chú thích kể lại lịch sử `whereNull('closed_at')` thì
 * không tính.
 */
function matterClosedAtConditionIn(string $source): bool
{
    // Toán tử so sánh; `<=>` (sắp xếp) và `=>` (khoá mảng, mũi tên) không phải điều kiện.
    $operator = '(?:===|!==|==|!=|<=(?!>)|>=|(?<![=<\-])<(?![=>])|(?<![=<\-])>(?!=))';
    $property = '(?:\?->|->)\s*';

    $pattern = '/'
        // where/orWhere/having… với closed_at ở tham số đầu: whereNull, whereNotNull, whereBetween,
        // whereDate, whereColumn, whereIn… — kể cả cột bọc trong `qualifyColumn('closed_at')`.
        .'\b(?:where|orWhere|having|orHaving)\w*\(\s*(?:[\w$:>\-]+\(\s*)?[\'"](?:\w+\.)?closed_at[\'"]'
        // whereColumn/whereRelation với closed_at ở tham số sau.
        .'|\b(?:where|orWhere)(?:Column|Relation)\([^;)]*[\'"](?:\w+\.)?closed_at[\'"]'
        // so sánh trong bộ nhớ, hai chiều.
        .'|'.$property.'closed_at\b\s*'.$operator
        .'|'.$operator.'\s*\$\w+(?:\s*'.$property.'\w+)*\s*'.$property.'closed_at\b'
        // so sánh Carbon: ->closed_at->lte(…), ->closed_at?->isBefore(…)
        .'|'.$property.'closed_at\s*'.$property.'(?:lt|lte|gt|gte|eq|ne|equalTo|lessThan|lessThanOrEqualTo|greaterThan|greaterThanOrEqualTo|isBefore|isAfter|isPast|isFuture|isSameDay|between|betweenIncluded|betweenExcluded)\s*\('
        .'/i';

    $code = collect(token_get_all($source))
        ->reject(fn (mixed $token): bool => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
        ->map(fn (mixed $token): string => is_array($token) ? $token[1] : $token)
        ->implode('');

    return preg_match($pattern, $code) === 1;
}

/**
 * Một định nghĩa, và nó phải CÒN là một (gộp M9, xung đột 2): ngoài `Matter.php` (nơi định nghĩa
 * `scopeOpen`/`scopeClosed`/`isOpen`/`isClosed`, và từ M13 `scopeClosedWithin`/`closedOnOrBefore`) và
 * `TransitionMatterStage` (nơi DUY NHẤT ghi cột này), không tệp nào trong app/ được dùng `closed_at`
 * làm điều kiện — lọc SQL hay so trong bộ nhớ. Hiển thị cột (`TextEntry::make('closed_at')`, cast,
 * nhãn) thì được. Bốn chỗ M9 viết trước khi M6.5 có scope là đúng thứ test này bắt.
 */
it('uses closed_at as a condition nowhere in app/ except the matter model and the stage transition', function () {
    $allowed = ['Models/Matter.php', 'Actions/TransitionMatterStage.php'];

    $offenders = collect(File::allFiles(app_path()))
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->reject(fn (string $path): bool => in_array($path, $allowed, true))
        ->filter(fn (string $path): bool => matterClosedAtConditionIn((string) file_get_contents(app_path($path))))
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

/**
 * M13 Task 2: bộ quét cũ chỉ bắt `where(Null|NotNull)?(` và bốn toán tử bằng/khác — một
 * `scopeClosedWithin()` viết ngoài `Matter.php` bằng `whereBetween('closed_at'` hay so `<` trong bộ
 * nhớ sẽ lọt qua. Cặp dương cho từng dạng, cặp âm cho hiển thị và chú thích.
 */
it('catches whereBetween, whereDate, whereColumn and ordering comparisons on closed_at, and lets display through', function () {
    $condition = fn (string $body): bool => matterClosedAtConditionIn("<?php\n".$body."\n");

    expect($condition('$q->whereBetween(\'closed_at\', $bounds);'))->toBeTrue()
        ->and($condition('$q->whereBetween(\'matters.closed_at\', $bounds);'))->toBeTrue()
        ->and($condition('$q->whereDate(\'closed_at\', \'<=\', $day);'))->toBeTrue()
        ->and($condition('$q->whereColumn(\'closed_at\', \'<\', \'due_date\');'))->toBeTrue()
        ->and($condition('$q->whereColumn(\'due_date\', \'>\', \'matters.closed_at\');'))->toBeTrue()
        ->and($condition('$q->orWhereNull(\'closed_at\');'))->toBeTrue()
        ->and($condition('$q->where($this->qualifyColumn(\'closed_at\'), \'<\', $x);'))->toBeTrue()
        ->and($condition('if ($matter->closed_at < $day) {}'))->toBeTrue()
        ->and($condition('if ($matter->closed_at <= $day) {}'))->toBeTrue()
        ->and($condition('if ($matter->closed_at > $day) {}'))->toBeTrue()
        ->and($condition('if ($matter->closed_at >= $day) {}'))->toBeTrue()
        ->and($condition('if ($day > $deadline->matter->closed_at) {}'))->toBeTrue()
        ->and($condition('if ($matter->closed_at === null) {}'))->toBeTrue()
        ->and($condition('if ($matter->closed_at?->lte($day)) {}'))->toBeTrue()
        ->and($condition('TextEntry::make(\'closed_at\')->label(__(\'x\'));'))->toBeFalse()
        ->and($condition('$label = $matter->closed_at?->format(\'d/m/Y\');'))->toBeFalse()
        ->and($condition('$row = [\'closed\' => $matter->closed_at];'))->toBeFalse()
        ->and($condition('// $q->whereBetween(\'closed_at\', $b) chỉ là chú thích'))->toBeFalse()
        ->and($condition('$q->with(\'matter:id,closed_at\');'))->toBeFalse();
});
