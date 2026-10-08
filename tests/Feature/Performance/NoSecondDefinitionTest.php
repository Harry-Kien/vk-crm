<?php

use Illuminate\Support\Facades\File;

/**
 * M13 — luật riêng "không định nghĩa thứ hai" (Ràng buộc toàn cục của kế hoạch M13).
 *
 * Mã của M13 không được viết điều kiện (`where*`, `having*`, `orWhere*`, so sánh trong bộ nhớ) trên
 * các cột nghiệp vụ dưới đây. Mọi điều kiện đi qua một scope có tên ở lớp đang giữ luật đó
 * (`Matter`, `Deadline`, `ClientRequest`, `MatterChecklistItem`, `ChecklistProgress`,
 * `MatterStaleness`, `ActivityOwningMatter`, `App\Support\Billing`) — nơi có test đồng nhất với
 * widget trang chủ, thư nhắc và trang doanh thu (`SingleSourceParityTest`).
 *
 * **Được:** đọc cột quy người (`lead_lawyer_id`, `responsible_user_id`, `created_by`,
 * `attributed_lawyer_id`, `causer_id`, `user_id`) trong `select`/`groupBy`/`orderBy`/`pluck` — đó là
 * quy về người (R5), không phải điều kiện; hiển thị (`TextColumn::make('due_date')`, nhãn, định dạng).
 *
 * **Tệp bị quét:** mọi tệp dưới `app/Actions/Performance/`, `app/Support/Performance/`,
 * `app/Filament/Admin/Widgets/Performance/`; `app/Actions/Schedule/CapturePerformanceSnapshots.php`
 * (chỗ tách `confidentiality`, đúng điểm rò của R4); ba trang `TeamOverview`, `TeamMember`,
 * `Performance`. Tệp chưa tồn tại thì bỏ qua — test tự canh khi tệp xuất hiện.
 *
 * **Ngoại lệ có tên, theo tệp** ({@see M13T2_NAMED_EXCEPTIONS}) — không tệp nào khác được thêm mà
 * không sửa kế hoạch: `TeamRoster.php` là định nghĩa danh sách R3 (điều kiện trên `is_active`,
 * `deleted_at` của `users`, `created_at` của dòng nhật ký vô hiệu hoá); ba bộ dựng "ai giữ việc lúc
 * nào" (R9, R18) đọc `created_at` của `activity_log` (`event`, `subject_type`, `subject_id` không
 * nằm trong danh sách cấm).
 *
 * Quét bằng TOKEN (bỏ chú thích và docblock, giữ chuỗi) — khuôn `tests/Feature/Models/MatterTest.php`.
 * Hàm quét nhận mã nguồn làm tham số và có cặp dương/âm trên fixture cho từng nhóm cột, để "xanh"
 * không thể là "xanh vì hàm quét hỏng".
 */
const M13T2_FORBIDDEN_COLUMNS = [
    // vụ việc
    'closed_at', 'last_client_update_at', 'stage_entered_at', 'is_published_to_portal', 'confidentiality',
    'lead_lawyer_id', 'role_in_matter',
    // mốc thời hạn
    'is_completed', 'completed_at', 'due_date', 'responsible_user_id',
    // yêu cầu của khách
    'answered_at', 'assigned_to',
    // giấy tờ, tiền, tiến độ
    'is_required', 'reviewed_by', 'reviewed_at', 'voided_at', 'paid_on', 'from_stage', 'to_stage',
    'occurred_at', 'created_by', 'attributed_lawyer_id',
    // chung (mọi bảng)
    'status', 'deleted_at', 'created_at', 'is_active',
    // R20: mốc tạo qua AI tính như mốc thường — đảo thì sửa Deadline, CheckDeadlines và widget cùng lúc
    'created_via', 'confirmed_at',
];

/** Cột kiểu boolean: phủ định (`!`) hay nối logic (`&&`, `||`) trên chúng cũng là một điều kiện. */
const M13T2_BOOLEAN_COLUMNS = ['is_published_to_portal', 'is_completed', 'is_required', 'is_active'];

/** Đường dẫn dưới `app/` => cột được phép làm điều kiện ở đúng tệp đó. */
const M13T2_NAMED_EXCEPTIONS = [
    'Support/Performance/TeamRoster.php' => ['is_active', 'deleted_at', 'created_at'],
    'Support/Performance/DeadlineHolderAtDue.php' => ['created_at'],
    'Support/Performance/RequestHolderAt.php' => ['created_at'],
    'Support/Performance/LeadAt.php' => ['created_at'],
];

/** @return list<string> đường dẫn dưới `app/` của mọi tệp bị quét đang tồn tại */
function m13t2ScannedFiles(): array
{
    $files = [];

    foreach (['Actions/Performance', 'Support/Performance', 'Filament/Admin/Widgets/Performance'] as $directory) {
        if (! is_dir(app_path($directory))) {
            continue;
        }

        foreach (File::allFiles(app_path($directory)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $directory.'/'.str_replace('\\', '/', $file->getRelativePathname());
            }
        }
    }

    foreach ([
        'Actions/Schedule/CapturePerformanceSnapshots.php',
        'Filament/Admin/Pages/TeamOverview.php',
        'Filament/Admin/Pages/TeamMember.php',
        'Filament/Admin/Pages/Performance.php',
    ] as $file) {
        if (is_file(app_path($file))) {
            $files[] = $file;
        }
    }

    sort($files);

    return $files;
}

/** Mã nguồn bỏ chú thích và docblock (chuỗi giữ nguyên: tên cột nằm trong chuỗi). */
function m13t2CodeOf(string $source): string
{
    return collect(token_get_all($source))
        ->reject(fn (mixed $token): bool => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
        ->map(fn (mixed $token): string => is_array($token) ? $token[1] : $token)
        ->implode('');
}

/**
 * Các cột cấm mà `$source` dùng làm điều kiện, trừ `$allowed`.
 *
 * @param  list<string>  $allowed
 * @return list<string>
 */
function m13t2ConditionColumns(string $source, array $allowed = []): array
{
    $code = m13t2CodeOf($source);

    // Toán tử so sánh; `<=>` (sắp xếp) và `=>` (khoá mảng, mũi tên) KHÔNG phải điều kiện.
    $operator = '(?:===|!==|==|!=|<=(?!>)|>=|(?<![=<\-])<(?![=>])|(?<![=<\-])>(?!=))';
    $property = '(?:\?->|->)\s*';
    $carbonComparison = '(?:lt|lte|gt|gte|eq|ne|equalTo|notEqualTo|lessThan|lessThanOrEqualTo|greaterThan|greaterThanOrEqualTo|isBefore|isAfter|isPast|isFuture|isToday|isSameDay|between|betweenIncluded|betweenExcluded)';

    $found = [];

    foreach (M13T2_FORBIDDEN_COLUMNS as $column) {
        if (in_array($column, $allowed, true)) {
            continue;
        }

        $name = preg_quote($column, '/');
        $quoted = '[\'"](?:\w+\.)?'.$name.'[\'"]';

        $patterns = [
            // where('col' …), whereIn/whereNotIn/whereBetween/whereNull/whereDate/whereColumn('col' …),
            // orWhere*, having*, firstWhere; kể cả cột bọc trong một lời gọi (`qualifyColumn('col')`).
            '/\b(?:where|orWhere|having|orHaving|firstWhere)\w*\(\s*(?:[\w$:>\-]+\(\s*)?'.$quoted.'/i',
            // where(['col' => …])
            '/\b(?:where|orWhere|firstWhere)\w*\(\s*\[[^\]]*'.$quoted.'\s*=>/i',
            // whereColumn('a', '<', 'col'), whereRelation('matter', 'col', …): cột ở tham số sau.
            '/\b(?:where|orWhere)(?:Column|Relation|MorphRelation)\([^;)]*'.$quoted.'/i',
            // điều kiện viết tay: whereRaw('COALESCE(col, …) < ?'), havingRaw(…)
            '/\b(?:where|orWhere|having|orHaving)Raw\(\s*[\'"][^\'"]*\b'.$name.'\b/i',
            // điều kiện trong một biểu thức chọn: selectRaw('SUM(CASE WHEN col = ? …)')
            '/\bWHEN\b[^\'"]*\b'.$name.'\b/i',
            // so sánh trong bộ nhớ: $x->col === …, … < $x->y->col
            '/'.$property.$name.'\b\s*'.$operator.'/',
            '/'.$operator.'\s*\$\w+(?:\s*'.$property.'\w+)*\s*'.$property.$name.'\b/',
            // so sánh Carbon: $x->col->lte(…), $x->col?->isPast()
            '/'.$property.$name.'\s*'.$property.$carbonComparison.'\s*\(/i',
        ];

        if (in_array($column, M13T2_BOOLEAN_COLUMNS, true)) {
            // ! $x->col, $a && $x->col, $x->col || $b
            $patterns[] = '/(?:!|&&|\|\||\band\b|\bor\b)\s*\$\w+(?:\s*'.$property.'\w+)*\s*'.$property.$name.'\b/i';
            $patterns[] = '/'.$property.$name.'\b\s*(?:&&|\|\||\band\b|\bor\b)/i';
        }

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $code) === 1) {
                $found[] = $column;

                break;
            }
        }
    }

    return $found;
}

it('finds no condition on a business column in any M13 file outside its named exceptions', function () {
    $offenders = collect(m13t2ScannedFiles())
        ->flatMap(fn (string $file): array => collect(m13t2ConditionColumns(
            (string) file_get_contents(app_path($file)),
            M13T2_NAMED_EXCEPTIONS[$file] ?? [],
        ))->map(fn (string $column): string => "{$file}: {$column}")->all())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

/** Hôm nay đã có tệp để quét — "xanh" không phải "xanh vì rỗng". */
it('scans the roster and the three pages that exist today', function () {
    expect(m13t2ScannedFiles())->toContain('Support/Performance/TeamRoster.php')
        ->and(m13t2ScannedFiles())->toContain('Filament/Admin/Pages/TeamOverview.php')
        ->and(m13t2ScannedFiles())->toContain('Filament/Admin/Pages/TeamMember.php')
        ->and(m13t2ScannedFiles())->toContain('Filament/Admin/Pages/Performance.php');
});

/** Danh sách ngoại lệ được ghim: thêm một tệp là sửa kế hoạch, không phải sửa một mảng. */
it('names exactly four files as exceptions, each with only the columns the plan grants it', function () {
    expect(M13T2_NAMED_EXCEPTIONS)->toBe([
        'Support/Performance/TeamRoster.php' => ['is_active', 'deleted_at', 'created_at'],
        'Support/Performance/DeadlineHolderAtDue.php' => ['created_at'],
        'Support/Performance/RequestHolderAt.php' => ['created_at'],
        'Support/Performance/LeadAt.php' => ['created_at'],
    ]);
});

it('forbids every column group the plan lists, R20 included', function () {
    foreach ([
        'closed_at', 'last_client_update_at', 'stage_entered_at', 'is_published_to_portal', 'confidentiality', 'lead_lawyer_id', 'role_in_matter',
        'is_completed', 'completed_at', 'due_date', 'responsible_user_id',
        'answered_at', 'assigned_to',
        'is_required', 'reviewed_by', 'reviewed_at', 'voided_at', 'paid_on', 'from_stage', 'to_stage', 'occurred_at', 'created_by', 'attributed_lawyer_id',
        'status', 'deleted_at', 'created_at', 'is_active',
        'created_via', 'confirmed_at',
    ] as $column) {
        expect(M13T2_FORBIDDEN_COLUMNS)->toContain($column);
    }
});

/** @return list<string> các cột tìm thấy trong một đoạn mã mẫu */
function m13t2Fixture(string $body, array $allowed = []): array
{
    return m13t2ConditionColumns("<?php\n".$body."\n", $allowed);
}

it('catches a condition on a matter column', function () {
    expect(m13t2Fixture('$q->whereBetween(\'closed_at\', $bounds);'))->toBe(['closed_at'])
        ->and(m13t2Fixture('$q->where(\'matters.lead_lawyer_id\', $id);'))->toBe(['lead_lawyer_id'])
        ->and(m13t2Fixture('$q->where($this->qualifyColumn(\'confidentiality\'), \'restricted\');'))->toBe(['confidentiality'])
        ->and(m13t2Fixture('$q->whereRaw(\'COALESCE(last_client_update_at, stage_entered_at) < ?\', [$t]);'))->toBe(['last_client_update_at', 'stage_entered_at'])
        ->and(m13t2Fixture('if ($m->is_published_to_portal === true) {}'))->toBe(['is_published_to_portal'])
        ->and(m13t2Fixture('if (! $m->is_published_to_portal) {}'))->toBe(['is_published_to_portal'])
        ->and(m13t2Fixture('$team->whereIn(\'matter_user.role_in_matter\', $roles);'))->toBe(['role_in_matter'])
        ->and(m13t2Fixture('$q->whereRelation(\'matter\', \'closed_at\', \'<\', $x);'))->toBe(['closed_at'])
        ->and(m13t2Fixture('$q->orWhereNull(\'closed_at\');'))->toBe(['closed_at']);
});

it('catches a condition on a deadline column', function () {
    expect(m13t2Fixture('$q->where(\'is_completed\', false);'))->toBe(['is_completed'])
        ->and(m13t2Fixture('if ($d->due_date < $cutoff) {}'))->toBe(['due_date'])
        ->and(m13t2Fixture('if ($cutoff >= $d->matter->due_date) {}'))->toBe(['due_date'])
        ->and(m13t2Fixture('$q->whereDate(\'due_date\', \'<\', today());'))->toBe(['due_date'])
        ->and(m13t2Fixture('if ($d->completed_at->lte($cutoff)) {}'))->toBe(['completed_at'])
        ->and(m13t2Fixture('if ($d->due_date?->isPast()) {}'))->toBe(['due_date'])
        ->and(m13t2Fixture('$q->whereColumn(\'completed_at\', \'>\', \'due_date\');'))->toBe(['completed_at', 'due_date'])
        ->and(m13t2Fixture('$rows->filter(fn ($d) => $a && $d->is_completed);'))->toBe(['is_completed'])
        ->and(m13t2Fixture('$q->where([\'responsible_user_id\' => $id]);'))->toBe(['responsible_user_id']);
});

it('catches a condition on a client request column', function () {
    expect(m13t2Fixture('$q->whereNotNull(\'client_requests.answered_at\');'))->toBe(['answered_at'])
        ->and(m13t2Fixture('if (null === $r->assigned_to) {}'))->toBe(['assigned_to'])
        ->and(m13t2Fixture('$c = $rows->firstWhere(\'assigned_to\', $id);'))->toBe(['assigned_to']);
});

it('catches a condition on a document, money or progress column', function () {
    expect(m13t2Fixture('$q->whereNull(\'voided_at\');'))->toBe(['voided_at'])
        ->and(m13t2Fixture('$q->whereBetween(\'paid_on\', $b);'))->toBe(['paid_on'])
        ->and(m13t2Fixture('$q->where(\'stage_logs.occurred_at\', \'>=\', $x);'))->toBe(['occurred_at'])
        ->and(m13t2Fixture('$q->orWhereIn(\'created_by\', $ids);'))->toBe(['created_by'])
        ->and(m13t2Fixture('$q->having(\'attributed_lawyer_id\', \'>\', 0);'))->toBe(['attributed_lawyer_id'])
        ->and(m13t2Fixture('$q->whereColumn(\'from_stage\', \'!=\', \'to_stage\');'))->toBe(['from_stage', 'to_stage'])
        ->and(m13t2Fixture('$q->where(\'is_required\', true);'))->toBe(['is_required'])
        ->and(m13t2Fixture('$q->whereNotNull(\'reviewed_by\')->where(\'reviewed_at\', \'<\', $x);'))->toBe(['reviewed_by', 'reviewed_at']);
});

it('catches a condition on a common column, and the two R20 columns', function () {
    expect(m13t2Fixture('$q->where(\'status\', \'pending_review\');'))->toBe(['status'])
        ->and(m13t2Fixture('$q->selectRaw(\'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as n\', [$s]);'))->toBe(['status'])
        ->and(m13t2Fixture('$q->whereNull(\'deleted_at\');'))->toBe(['deleted_at'])
        ->and(m13t2Fixture('$q->where(\'activity_log.created_at\', \'>\', $x);'))->toBe(['created_at'])
        ->and(m13t2Fixture('$q->where(\'is_active\', true);'))->toBe(['is_active'])
        ->and(m13t2Fixture('$q->whereNotNull(\'confirmed_at\');'))->toBe(['confirmed_at'])
        ->and(m13t2Fixture('$q->where(\'created_via\', \'mcp\');'))->toBe(['created_via']);
});

it('lets attribution, ordering, display and comments through', function () {
    $clean = <<<'PHP'
        // ->where('closed_at', '<', $x) chỉ là chú thích
        /** $q->whereNull('deleted_at') trong docblock */
        $q->select('responsible_user_id')->groupBy('lead_lawyer_id')->orderBy('due_date');
        $q->selectRaw('lead_lawyer_id as holder_id, COUNT(*) as n');
        $q->pluck('due_date', 'id');
        TextColumn::make('due_date')->label(__('x'));
        TextEntry::make('closed_at');
        $row = ['due' => $d->due_date, 'done' => $d->is_completed];
        $label = $d->due_date->format('d/m/Y');
        usort($rows, fn ($a, $b) => $a->due_date <=> $b->due_date);
        $q->with('matter:id,closed_at,lead_lawyer_id');
        $q->where('name', 'like', $term);
        $status = $r->status->label();
        PHP;

    expect(m13t2Fixture($clean))->toBe([]);
});

it('lets a named exception through only for the columns it is given', function () {
    $roster = '$users->where(\'is_active\', true)->whereNull(\'users.deleted_at\')->where(\'created_at\', \'>=\', $from)->where(\'status\', \'x\');';

    expect(m13t2Fixture($roster))->toBe(['status', 'deleted_at', 'created_at', 'is_active'])
        ->and(m13t2Fixture($roster, M13T2_NAMED_EXCEPTIONS['Support/Performance/TeamRoster.php']))->toBe(['status']);
});
