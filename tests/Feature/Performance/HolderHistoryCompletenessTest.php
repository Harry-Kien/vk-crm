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
 * **"Ghi"** là một trong ba mẫu của kế hoạch (R9), thêm `??=` ở mẫu gán và `new Model([...])`:
 *  - `->update([... 'col' => ...])` (kể cả `update()` hàng loạt trên truy vấn);
 *  - `->col = …` (không phải `==`, `===`, `=>`);
 *  - `create([... 'col' => ...])`, `forceFill([...])`, `fill([...])`, `new Model([...])`.
 *
 * Mẫu mảng quét bằng TOKEN theo độ sâu ngoặc ({@see m13bHhArrayWriteKeys()}, sửa 2026-10-07, Task 7 làn A —
 * minor m2 của rà soát Task 3): khoá `'col' =>` ở CẤP MỘT của mảng đối số đầu, bất kể phần tử trước nó có
 * `$data['x']`, một mảng con hay một lời gọi. Mẫu regex cũ `[^\]]*` dừng ở dấu `]` đầu tiên và để lọt
 * `->update(['name' => $data['name'], 'responsible_user_id' => …])`.
 *
 * **Không phải "ghi":** khoá lỗi `ValidationException::withMessages(['col' => [...]])`, câu đọc
 * `->where('col', …)`, `->select('col')`, đọc `$x->col` không gán, so sánh, khoá của một mảng CON. Có
 * fixture cho từng dạng.
 *
 * **Ngoại lệ (tạo mới, không phải đổi người):** `AddMatterDeadline` (mốc mới) cho `responsible_user_id`;
 * `OpenClientRequest` (luồng mới, `assigned_to = null`) cho `assigned_to`.
 *
 * **Luật có tên — `assigned_to` chỉ của `client_requests`** ({@see M13B_HH_COLUMN_TABLES}, cùng lần sửa —
 * minor m3): cột `assigned_to` có ở HAI bảng, `client_requests` (người giữ luồng, R18) và `intake_requests`
 * (người được giao bản ghi tiếp nhận, M10). Dòng `client_request_assigned` chỉ là lịch sử của bảng thứ
 * nhất, nên luật của cột chỉ áp cho tệp có nhắc tới bảng đó trong mã (lớp `ClientRequest` dạng token, hoặc
 * tên bảng `client_requests` trong một chuỗi; chú thích không tính). `RecordIntake` và
 * `UpdateIntakeIdentity` ghi `assigned_to` của bản ghi tiếp nhận — không phải đổi người giữ một luồng yêu
 * cầu — nên không bị đòi dòng đó; một tệp ghi `assigned_to` và có nhắc `ClientRequest` thì vẫn bị đòi.
 * `responsible_user_id` chỉ có ở `deadlines`, không cần luật này.
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

/** Phương thức nhận mảng `cột => giá trị` làm đối số đầu (mẫu "ghi" dạng mảng, cùng `new Model([...])`). */
const M13B_HH_ARRAY_WRITERS = ['update', 'create', 'forceFill', 'fill'];

/**
 * Luật có tên (docblock tệp): cột => lớp model và bảng mà mã của tệp phải nhắc tới thì luật của cột mới áp.
 * Cột không có ở đây áp cho mọi tệp.
 */
const M13B_HH_COLUMN_TABLES = [
    'assigned_to' => ['class' => 'ClientRequest', 'table' => 'client_requests'],
];

/**
 * Khoá chuỗi ở CẤP MỘT của mảng đối số đầu của mọi lời gọi `->update([...])`, `::create([...])`,
 * `->forceFill([...])`, `->fill([...])` và `new Model([...])` trong `$code` — quét token, đếm độ sâu của cả
 * ba loại ngoặc, nên một `$data['x']`, một mảng con hay một lời gọi đứng trước khoá không làm dừng lần quét.
 *
 * @return list<string>
 */
function m13bHhArrayWriteKeys(string $code): array
{
    $tokens = array_values(array_filter(token_get_all($code), fn (mixed $token): bool => ! (is_array($token) && $token[0] === T_WHITESPACE)));
    $text = fn (mixed $token): string => is_array($token) ? $token[1] : (string) $token;
    $kind = fn (mixed $token): ?int => is_array($token) ? $token[0] : null;
    $keys = [];

    foreach ($tokens as $i => $token) {
        if ($kind($token) === T_STRING
            && in_array($token[1], M13B_HH_ARRAY_WRITERS, true)
            && in_array($kind($tokens[$i - 1] ?? null), [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
            $open = $i + 1;
        } elseif ($kind($token) === T_NEW) {
            $open = $i + 2; // `new`, tên lớp, rồi `(`
        } else {
            continue;
        }

        if ($text($tokens[$open] ?? '') !== '(' || $text($tokens[$open + 1] ?? '') !== '[') {
            continue;
        }

        $depth = 0;

        for ($k = $open + 1, $count = count($tokens); $k < $count; $k++) {
            $current = $tokens[$k];

            if (in_array($text($current), ['[', '(', '{'], true) || in_array($kind($current), [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;

                continue;
            }

            if (in_array($text($current), [']', ')', '}'], true)) {
                if (--$depth === 0) {
                    break;
                }

                continue;
            }

            if ($depth === 1 && $kind($current) === T_CONSTANT_ENCAPSED_STRING && $kind($tokens[$k + 1] ?? null) === T_DOUBLE_ARROW) {
                $keys[] = substr($current[1], 1, -1);
            }
        }
    }

    return array_values(array_unique($keys));
}

/** `$source` có GHI cột `$column` theo một trong các mẫu ở docblock tệp không. */
function m13bHhWrites(string $source, string $column): bool
{
    $code = m13bHhCodeOf($source);

    return preg_match('/->'.preg_quote($column, '/').'\s*(?:\?\?)?=(?![=>])/', $code) === 1
        || in_array($column, m13bHhArrayWriteKeys($code), true);
}

/** Luật của `$column` có áp cho `$source` không — luật có tên {@see M13B_HH_COLUMN_TABLES}. */
function m13bHhInScope(string $source, string $column): bool
{
    $owner = M13B_HH_COLUMN_TABLES[$column] ?? null;

    if ($owner === null) {
        return true;
    }

    $code = m13bHhCodeOf($source);

    return preg_match('/\b'.preg_quote($owner['class'], '/').'\b/', $code) === 1
        || preg_match('/[\'"]'.preg_quote($owner['table'], '/').'[\'"]/', $code) === 1;
}

/**
 * Các cột `$source` ghi mà không mang literal sự kiện lịch sử của cột đó, trừ cột được miễn và cột mà luật
 * có tên không áp cho tệp này.
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
            && m13bHhInScope($source, $column)
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
        // Hai đường ghi `assigned_to` của bản ghi tiếp nhận (M10): lần quét THẤY chúng (mảng có `$data['…']`
        // trước khoá, và `new IntakeRequest([...])`) — luật có tên mới là thứ để chúng ngoài cuộc, không phải
        // một lần quét mù.
        'Intake/RecordIntake.php',
        'Intake/UpdateIntakeIdentity.php',
    );
});

it('applies the assigned_to rule to every client request writer and to no intake writer', function () {
    $sources = m13bHhActionSources();
    $inScope = fn (string $file): bool => m13bHhInScope($sources[$file], 'assigned_to');

    expect($inScope('Portal/TriageClientRequest.php'))->toBeTrue()
        ->and($inScope('Portal/OpenClientRequest.php'))->toBeTrue()
        ->and($inScope('Matter/ReassignMatter.php'))->toBeTrue()
        ->and($inScope('Intake/RecordIntake.php'))->toBeFalse()
        ->and($inScope('Intake/UpdateIntakeIdentity.php'))->toBeFalse()
        ->and(M13B_HH_COLUMN_TABLES)->toBe(['assigned_to' => ['class' => 'ClientRequest', 'table' => 'client_requests']]);
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
    'assignment to a request' => ['$thread = ClientRequest::query()->firstOrFail(); $thread->assigned_to = null;', 'assigned_to'],
    'create a request' => ["ClientRequest::query()->create(['subject' => 'x', 'assigned_to' => null]);", 'assigned_to'],
]);

it('accepts the same writes once the file writes the history event', function () {
    expect(m13bHhSilentWrites(m13bHhFixture(<<<'PHP'
        Deadline::query()->whereKey($ids)->update(['responsible_user_id' => $actor->id]);
        Audit::record('deadline_responsible_changed', $deadline, ['from' => 1, 'to' => 2], causer: $actor);
        $thread = ClientRequest::query()->firstOrFail();
        $thread->assigned_to = null;
        Audit::record('client_request_assigned', $thread, ['from' => 1, 'to' => null], causer: $actor);
PHP)))->toBe([]);
});

it('does not take an event named only in a comment or a docblock for a history row', function () {
    expect(m13bHhSilentWrites(m13bHhFixture(<<<'PHP'
        // Không ghi 'deadline_responsible_changed' ở đây.
        /** Xem 'client_request_assigned'. */
        $deadline->responsible_user_id = $actor->id;
        $thread = ClientRequest::query()->firstOrFail();
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

// =========================================================================================
// Quét theo độ sâu ngoặc (minor m2 của rà soát Task 3) và luật có tên của `assigned_to` (minor m3).
// =========================================================================================

it('catches a holder key that follows an index read, a nested array or a call in the same array', function (string $body, string $column) {
    expect(m13bHhSilentWrites(m13bHhFixture($body)))->toBe([$column]);
})->with([
    'index read before the key' => ["\$deadline->update(['name' => \$data['name'], 'responsible_user_id' => \$data['holder']]);", 'responsible_user_id'],
    'nested array before the key' => ["\$deadline->forceFill(['meta' => ['a' => [1]], 'responsible_user_id' => 2])->save();", 'responsible_user_id'],
    'call before the key' => ["Deadline::query()->create(['name' => trim(\$data['name']), 'responsible_user_id' => \$ids[0]]);", 'responsible_user_id'],
    'static create' => ["Deadline::create(['name' => \$data['name'], 'responsible_user_id' => 2]);", 'responsible_user_id'],
    'new model' => ["\$deadline = new Deadline(['name' => \$data['name'], 'responsible_user_id' => 2]);", 'responsible_user_id'],
    'request update after an index read' => ["ClientRequest::query()->whereKey(\$ids)->update(['subject' => \$data['s'], 'assigned_to' => \$actor->id]);", 'assigned_to'],
    'request fill with a double-quoted key' => ['$thread = ClientRequest::query()->firstOrFail(); $thread->fill(["subject" => $data["s"], "assigned_to" => 2]);', 'assigned_to'],
]);

it('does not take a key of a nested array, or a key after the first argument, for a write', function (string $body) {
    expect(m13bHhSilentWrites(m13bHhFixture($body)))->toBe([]);
})->with([
    'key of a nested array' => ["\$deadline->update(['meta' => ['responsible_user_id' => 2]]);"],
    'second argument of an upsert-like call' => ["\$deadline->update(['name' => 'x'], ['responsible_user_id' => 2]);"],
    'string holding a bracket' => ["\$deadline->update(['name' => ']', 'note' => '['], ['responsible_user_id' => 2]);"],
]);

it('requires client_request_assigned only from a file that names client requests', function () {
    $intake = m13bHhFixture("\$intake->fill(['contact_name' => \$data['contact_name'], 'assigned_to' => \$data['assigned_to']])->save();");
    $byClass = "<?php\nuse App\Models\ClientRequest;\n".substr($intake, 6);
    $byTable = m13bHhFixture("DB::table('client_requests')->whereKey(\$ids)->update(['assigned_to' => \$actor->id]);");
    $inComment = m13bHhFixture("// ClientRequest, 'client_requests'\n\$intake->fill(['assigned_to' => 2]);");
    $otherClass = m13bHhFixture("ClientRequestReply::query()->first(); \$intake->fill(['assigned_to' => 2]);");

    expect(m13bHhWrites($intake, 'assigned_to'))->toBeTrue()
        ->and(m13bHhSilentWrites($intake))->toBe([])
        ->and(m13bHhSilentWrites($byClass))->toBe(['assigned_to'])
        ->and(m13bHhSilentWrites($byTable))->toBe(['assigned_to'])
        ->and(m13bHhSilentWrites($inComment))->toBe([])
        ->and(m13bHhSilentWrites($otherClass))->toBe([]);
});

it('keeps requiring deadline_responsible_changed from any file, client requests named or not', function () {
    expect(m13bHhSilentWrites(m13bHhFixture("\$deadline->fill(['name' => \$data['name'], 'responsible_user_id' => 2]);")))->toBe(['responsible_user_id']);
});
