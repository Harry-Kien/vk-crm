<?php

use Illuminate\Filesystem\Filesystem;

/**
 * M10 Task 7 (R7, "không bao giờ xoá dòng") — dữ liệu của người chưa thành khách được ẨN DANH (cập
 * nhật các cột về null), không bao giờ xoá cứng: dòng, mã, nguồn, trạng thái và các mốc thời gian ở lại
 * cho thống kê và cho dấu vết kiểm tra xung đột. Cùng quy tắc test cấu trúc của M7 Task 6 (kế hoạch
 * M7, dòng 209), nhưng là test RIÊNG của tiếp nhận, không gộp với tác vụ hồ sơ của M7.
 *
 * Quét LỜI GỌI, không quét chuỗi: chuỗi token `->`/`?->`/`::` + `forceDelete` (hoặc `forceDeleteQuietly`,
 * `forceDestroy`) + `(` trong mọi tệp PHP có tham chiếu tới model tiếp nhận (`IntakeRequest`,
 * `IntakeParty`) dưới `app/`, `database/` và `routes/`. Một tên trong chú thích, một chuỗi
 * `'forceDelete'` (tên ability của policy, `authorizeIndividualRecords('forceDelete')`), hay
 * `ForceDeleteBulkAction` bị policy chặn đều không phải lời gọi. Không suy được kiểu của vế trái từ token,
 * nên phạm vi là cả TỆP có nhắc tới model tiếp nhận — chặt hơn "đúng model", và chính vì thế an toàn.
 */
const INTAKE_FORCE_DELETE_METHODS = ['forceDelete', 'forceDeleteQuietly', 'forceDestroy'];

const INTAKE_MODEL_NAMES = ['IntakeRequest', 'IntakeParty'];

/** @return list<string> */
function intakeForceDeleteRoots(): array
{
    return [app_path(), database_path(), base_path('routes')];
}

/**
 * @param  list<string>  $roots
 * @return list<string> "đường dẫn tương đối:dòng" của từng lời gọi tìm thấy trong tệp có nhắc model tiếp nhận.
 */
function intakeForceDeleteCalls(array $roots): array
{
    $found = [];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        foreach ((new Filesystem)->allFiles($root) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $tokens = array_values(array_filter(
                token_get_all((string) file_get_contents($file->getPathname())),
                fn (array|string $token): bool => ! (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)),
            ));

            $mentionsIntake = collect($tokens)->contains(fn (array|string $token): bool => is_array($token)
                && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_CONSTANT_ENCAPSED_STRING], true)
                && collect(INTAKE_MODEL_NAMES)->contains(fn (string $model): bool => str_contains($token[1], $model)));

            if (! $mentionsIntake) {
                continue;
            }

            foreach ($tokens as $i => $token) {
                $operator = $token;
                $method = $tokens[$i + 1] ?? null;
                $paren = $tokens[$i + 2] ?? null;

                if (is_array($operator) && in_array($operator[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)
                    && is_array($method) && $method[0] === T_STRING && in_array($method[1], INTAKE_FORCE_DELETE_METHODS, true)
                    && $paren === '(') {
                    $found[] = $file->getRelativePathname().':'.$method[2];
                }
            }
        }
    }

    return $found;
}

it('finds no forceDelete call in any file that touches the intake models', function () {
    expect(intakeForceDeleteCalls(intakeForceDeleteRoots()))->toBe([]);
});

/*
 * Cặp dương: phép quét phải BẮT được lời gọi thật ở mọi dạng, và KHÔNG bắt chú thích, chuỗi, tên
 * ability, `ForceDeleteBulkAction`, hay một lời gọi trong tệp không nhắc tới model tiếp nhận — nếu không
 * thì "xanh" ở trên không chứng minh được gì.
 */
it('does catch a real call in any form, and ignores comments, strings, policy abilities and unrelated files', function () {
    $dir = sys_get_temp_dir().'/vkcrm-force-delete-fixture-'.bin2hex(random_bytes(4));
    mkdir($dir.'/Nested', 0777, true);

    $intakeUse = "use App\\Models\\IntakeRequest;\n";
    file_put_contents($dir.'/Arrow.php', "<?php\n{$intakeUse}class A { function f(IntakeRequest \$r) { \$r->forceDelete(); } }\n");
    file_put_contents($dir.'/Nullsafe.php', "<?php\nuse App\\Models\\{Client, IntakeParty};\nclass B { function f(?IntakeParty \$p) { \$p?->forceDeleteQuietly(); } }\n");
    file_put_contents($dir.'/Nested/Query.php', "<?php\nclass C { function f() { \\App\\Models\\IntakeRequest::query()->whereKey(1)\n    ->forceDelete (); } }\n");
    file_put_contents($dir.'/Static.php', "<?php\n{$intakeUse}class D { function f() { IntakeRequest::forceDestroy([1]); } }\n");
    file_put_contents($dir.'/Comment.php', "<?php\n{$intakeUse}class E {\n    // \$intake->forceDelete() là sai\n    /** IntakeRequest::forceDestroy() */\n    function f() {}\n}\n");
    file_put_contents($dir.'/Strings.php', "<?php\n{$intakeUse}class F { function f(\$a) { \$a->authorizeIndividualRecords('forceDelete'); return ['->forceDelete(']; } }\n");
    file_put_contents($dir.'/BulkAction.php', "<?php\n{$intakeUse}use Filament\\Actions\\ForceDeleteBulkAction;\nclass G { function f() { return ForceDeleteBulkAction::make(); } function forceDelete() {} }\n");
    file_put_contents($dir.'/Unrelated.php', "<?php\nuse App\\Models\\Client;\nclass H { function f(Client \$c) { \$c->forceDelete(); } }\n");

    try {
        $found = intakeForceDeleteCalls([$dir]);
    } finally {
        (new Filesystem)->deleteDirectory($dir);
    }

    expect($found)->toEqualCanonicalizing([
        'Arrow.php:3',
        'Nullsafe.php:3',
        'Nested'.DIRECTORY_SEPARATOR.'Query.php:3',
        'Static.php:3',
    ]);
});

it('watches the application code, the database code and the routes', function () {
    expect(intakeForceDeleteRoots())->toBe([app_path(), database_path(), base_path('routes')]);
});
