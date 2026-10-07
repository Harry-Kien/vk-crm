<?php

use Illuminate\Support\Facades\File;

/**
 * M13 Task 3 — test cấu trúc R9/R18: lịch sử "ai từng giữ việc này" chỉ đầy đủ khi MỌI đường ghi
 * người giữ cũng ghi dòng nhật ký của nó. `DeadlineHolderAtDue` đọc `from` của dòng
 * `deadline_responsible_changed` sớm nhất sau hạn; `RequestHolderAt` đọc `from` của dòng
 * `client_request_assigned` sớm nhất sau thời điểm hỏi. Một đường ghi im lặng làm hai bộ dựng đó
 * rơi về người giữ HIỆN TẠI — đúng lỗi R9 sửa (người nhận bàn giao gánh mốc người trước đã lỡ).
 *
 * **Luật:** mọi tệp dưới `app/Actions` GHI `responsible_user_id` (của `deadlines`) cũng chứa literal
 * `'deadline_responsible_changed'`; mọi tệp GHI `assigned_to` (của `client_requests`) cũng chứa
 * `'client_request_assigned'`. Quét trên mã đã bỏ chú thích và docblock: một khoá chỉ nhắc trong
 * chú thích không phải một dòng nhật ký.
 *
 * **"Ghi"** là một trong ba mẫu token của kế hoạch (R9), thêm `??=` ở mẫu gán:
 *  - `->update([... 'col' => ...])` (kể cả `update()` hàng loạt trên truy vấn);
 *  - `->col = …` (không phải `==`, `===`, `=>`);
 *  - `create([... 'col' => ...])`, `forceFill([...])`, `fill([...])`.
 *
 * **Không phải "ghi":** khoá lỗi `ValidationException::withMessages(['col' => [...]])`, câu đọc
 * `->where('col', …)`, `->select('col')`, đọc `$x->col` không gán, so sánh. Có fixture cho từng dạng.
 *
 * **Ngoại lệ (tạo mới, không phải đổi người):** `AddMatterDeadline` (mốc mới) cho `responsible_user_id`;
 * `OpenClientRequest` (luồng mới, `assigned_to = null`) cho `assigned_to`.
 *
 * Hàm tiện ích mang tiền tố `m13bHh` (làn m13b, tệp này).
 */
const M13B_HH_HISTORY_EVENTS = [
    'responsible_user_id' => 'deadline_responsible_changed',
    'assigned_to' => 'client_request_assigned',
];

/** Cột => tệp (dưới `app/Actions/`) được ghi cột đó mà không cần dòng lịch sử. */
const M13B_HH_EXCEPTIONS = [
    'responsible_user_id' => ['Deadline/AddMatterDeadline.php'],
    'assigned_to' => ['Portal/OpenClientRequest.php'],
];

/** Mã nguồn bỏ chú thích và docblock (chuỗi giữ nguyên). */
function m13bHhCodeOf(string $source): string
{
    return collect(token_get_all($source))
        ->reject(fn (mixed $token): bool => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
        ->map(fn (mixed $token): string => is_array($token) ? $token[1] : $token)
        ->implode('');
}

/** `$source` có GHI cột `$column` theo một trong các mẫu token ở docblock tệp không. */
function m13bHhWrites(string $source, string $column): bool
{
    $code = m13bHhCodeOf($source);
    $quoted = '[\'"]'.preg_quote($column, '/').'[\'"]';

    foreach ([
        '/->update\(\s*\[[^\]]*'.$quoted.'\s*=>/',
        '/->'.preg_quote($column, '/').'\s*(?:\?\?)?=(?![=>])/',
        '/(?:create|forceFill|fill)\(\s*\[[^\]]*'.$quoted.'\s*=>/',
    ] as $pattern) {
        if (preg_match($pattern, $code) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Các cột `$source` ghi mà không mang literal sự kiện lịch sử của cột đó, trừ cột được miễn.
 *
 * @param  list<string>  $exempt
 * @return list<string>
 */
function m13bHhSilentWrites(string $source, array $exempt = []): array
{
    $code = m13bHhCodeOf($source);

    return collect(M13B_HH_HISTORY_EVENTS)
        ->reject(fn (string $event, string $column): bool => in_array($column, $exempt, true))
        ->filter(fn (string $event, string $column): bool => m13bHhWrites($source, $column)
            && ! str_contains($code, "'{$event}'") && ! str_contains($code, "\"{$event}\""))
        ->keys()
        ->values()
        ->all();
}

/** @return array<string, string> đường dẫn dưới `app/Actions/` => mã nguồn */
function m13bHhActionSources(): array
{
    return collect(File::allFiles(app_path('Actions')))
        ->filter(fn ($file): bool => $file->getExtension() === 'php')
        ->mapWithKeys(fn ($file): array => [
            str_replace('\\', '/', $file->getRelativePathname()) => (string) file_get_contents($file->getPathname()),
        ])
        ->sortKeys()
        ->all();
}

it('finds no action that writes a holder column without writing its history event', function () {
    $offenders = collect(m13bHhActionSources())
        ->flatMap(function (string $source, string $file): array {
            $exempt = collect(M13B_HH_EXCEPTIONS)
                ->filter(fn (array $files): bool => in_array($file, $files, true))
                ->keys()
                ->all();

            return array_map(fn (string $column): string => "{$file}: {$column}", m13bHhSilentWrites($source, $exempt));
        })
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

/** Hôm nay đã có đường ghi để quét — "xanh" không phải "xanh vì hàm quét không thấy gì". */
it('recognises every holder write path that exists today', function () {
    $writers = fn (string $column): array => collect(m13bHhActionSources())
        ->filter(fn (string $source): bool => m13bHhWrites($source, $column))
        ->keys()
        ->values()
        ->all();

    expect($writers('responsible_user_id'))->toContain(
        'Deadline/ChangeDeadlineResponsible.php',
        'Deadline/SetDeadlineCompletion.php',
        'Deadline/UpdateDeadline.php',
        'Matter/ReassignMatter.php',
    )->and($writers('assigned_to'))->toContain(
        'Portal/TriageClientRequest.php',
        'Portal/OpenClientRequest.php',
        'Matter/ReassignMatter.php',
    );
});

it('names exactly two creation paths as exceptions', function () {
    expect(M13B_HH_EXCEPTIONS)->toBe([
        'responsible_user_id' => ['Deadline/AddMatterDeadline.php'],
        'assigned_to' => ['Portal/OpenClientRequest.php'],
    ]);
});

// =========================================================================================
// Hàm quét trên fixture — mỗi mẫu "ghi" có cặp dương, mỗi dạng "không phải ghi" có fixture âm.
// =========================================================================================

/** Một tệp PHP nhỏ bọc `$body` trong một phương thức. */
function m13bHhFixture(string $body): string
{
    return "<?php\nclass Fixture\n{\n    public function handle(\$deadline, \$thread, \$ids, \$actor): void\n    {\n{$body}\n    }\n}\n";
}

it('catches each write pattern on both holder columns when the history event is missing', function (string $body, string $column) {
    expect(m13bHhSilentWrites(m13bHhFixture($body)))->toBe([$column]);
})->with([
    'bulk update of deadlines' => ["Deadline::query()->whereKey(\$ids)->update(['responsible_user_id' => \$actor->id]);", 'responsible_user_id'],
    'model update of a deadline' => ["\$deadline->blameOn(\$actor)->update(['name' => 'x', 'responsible_user_id' => 2]);", 'responsible_user_id'],
    'assignment to a deadline' => ['$deadline->responsible_user_id = $actor->id;', 'responsible_user_id'],
    'null-coalescing assignment' => ['$deadline->responsible_user_id ??= $actor->id;', 'responsible_user_id'],
    'create a deadline' => ["Deadline::query()->create(['matter_id' => 1, 'responsible_user_id' => 2]);", 'responsible_user_id'],
    'forceFill a deadline' => ["\$deadline->forceFill(['responsible_user_id' => 2])->save();", 'responsible_user_id'],
    'fill a deadline' => ["\$deadline->fill(['responsible_user_id' => 2]);", 'responsible_user_id'],
    'bulk update of requests' => ["ClientRequest::query()->whereKey(\$ids)->update(['assigned_to' => \$actor->id]);", 'assigned_to'],
    'assignment to a request' => ['$thread->assigned_to = null;', 'assigned_to'],
    'create a request' => ["ClientRequest::query()->create(['subject' => 'x', 'assigned_to' => null]);", 'assigned_to'],
]);

it('accepts the same writes once the file writes the history event', function () {
    expect(m13bHhSilentWrites(m13bHhFixture(<<<'PHP'
        Deadline::query()->whereKey($ids)->update(['responsible_user_id' => $actor->id]);
        Audit::record('deadline_responsible_changed', $deadline, ['from' => 1, 'to' => 2], causer: $actor);
        $thread->assigned_to = null;
        Audit::record('client_request_assigned', $thread, ['from' => 1, 'to' => null], causer: $actor);
PHP)))->toBe([]);
});

it('does not take an event named only in a comment or a docblock for a history row', function () {
    expect(m13bHhSilentWrites(m13bHhFixture(<<<'PHP'
        // Không ghi 'deadline_responsible_changed' ở đây.
        /** Xem 'client_request_assigned'. */
        $deadline->responsible_user_id = $actor->id;
        $thread->assigned_to = $actor->id;
PHP)))->toBe(['responsible_user_id', 'assigned_to']);
});

it('does not take a validation key, a where, a select, a read or a comparison for a write', function (string $body) {
    expect(m13bHhSilentWrites(m13bHhFixture($body)))->toBe([]);
})->with([
    'validation key' => ["throw ValidationException::withMessages(['responsible_user_id' => [__('deadlines.validation.already_completed')]]);"],
    'validation key of a request' => ["throw ValidationException::withMessages(['assigned_to' => [__('requests.validation.assignee_cannot_open')]]);"],
    'where' => ["Deadline::query()->where('responsible_user_id', \$actor->id)->where('assigned_to', \$actor->id)->get();"],
    'select subquery' => ["User::query()->whereIn('id', \$deadline->deadlines()->select('responsible_user_id'))->get();"],
    'read without assignment' => ['$previous = $deadline->responsible_user_id; $holder = $thread->assigned_to;'],
    'strict comparison' => ['if ($deadline->responsible_user_id === $actor->id || $thread->assigned_to !== null) { return; }'],
    'loose comparison' => ['if ($deadline->responsible_user_id == $actor->id) { return; }'],
    'audit property of a creation' => ["Audit::record('deadline_added', \$deadline, ['responsible_user_id' => \$actor->id]);"],
]);

it('lets a named creation path write without a history row, and only for its own column', function () {
    $create = m13bHhFixture("ClientRequest::query()->create(['subject' => 'x', 'assigned_to' => null]);");

    expect(m13bHhSilentWrites($create, ['assigned_to']))->toBe([])
        ->and(m13bHhSilentWrites($create, ['responsible_user_id']))->toBe(['assigned_to']);
});
