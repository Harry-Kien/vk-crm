<?php

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| M11 R4 (Task 9) — test cấu trúc: presenter không bao giờ đổ cả một model ra
|--------------------------------------------------------------------------
|
| Ở MCP không có `HidesInternalAttributesFromPortal` (chỉ chạy khi `ClientPortalScope::isActive()`),
| nên `$model->toArray()` / `attributesToArray()` / `jsonSerialize()` trả MỌI cột — `internal_note`,
| `description_internal`, `id_number` đã giải mã. Presenter đọc từng trường theo TÊN, liệt kê tường
| minh. Test này quét mã nguồn (đã gỡ chú thích) của `app/Support/Mcp/Presenters` tìm mọi lời gọi
| đổ hàng loạt thuộc tính hay quan hệ, và mọi nhắc tới dữ liệu tiếp nhận của M10 (C3: không
| presenter nào cho tiếp nhận).
|
| Giới hạn, nói thẳng: quét văn bản không thấy lời gọi gián tiếp (`$m->{$name}()`,
| `call_user_func`). Lượt quét dữ liệu thật ở Task 14 là lớp thứ hai.
*/

/** @return list<string> các vi phạm tìm thấy trong một đoạn mã PHP */
function presenterStructureViolations(string $code): array
{
    $stripped = '';

    foreach (token_get_all($code) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $stripped .= is_array($token) ? $token[1] : $token;
    }

    $patterns = [
        'bulk model dump' => '/(?:->|::)\s*(toArray|attributesToArray|relationsToArray|jsonSerialize|toJson|getAttributes|getOriginal|getRawOriginal|getDirty|only|except|makeVisible|makeHidden|setVisible|setHidden)\s*\(/i',
        'serialisation' => '/\b(json_encode|serialize|var_export|get_object_vars)\s*\(/i',
        'array cast' => '/\(\s*array\s*\)/i',
        'intake data (C3)' => '/Intake/i',
    ];

    $violations = [];

    foreach ($patterns as $label => $pattern) {
        if (preg_match_all($pattern, $stripped, $matches)) {
            foreach ($matches[0] as $match) {
                $violations[] = "{$label}: {$match}";
            }
        }
    }

    return $violations;
}

it('catches every forbidden shape on a planted sample (the scanner itself works)', function () {
    $planted = <<<'PHP'
        <?php
        // toArray() in a comment is fine
        /** jsonSerialize() in a docblock is fine */
        return [$m->toArray(), $m->attributesToArray(), $m->jsonSerialize(), $m->toJson(), $m->getAttributes(),
            $m->only(['a']), json_encode($m), (array) $m, $m::toArray(), App\Models\IntakeRequest::class];
        PHP;

    expect(presenterStructureViolations($planted))->toHaveCount(10);
});

it('finds no bulk model dump, serialisation or intake reference in any presenter', function () {
    $files = iterator_to_array(Finder::create()->files()->name('*.php')->in(app_path('Support/Mcp/Presenters')));

    // Mười presenter (mỗi loại đối tượng một) cộng thư mục Concerns — một thư mục rỗng không được xanh.
    expect(count($files))->toBeGreaterThanOrEqual(10);

    foreach ($files as $file) {
        expect(presenterStructureViolations($file->getContents()))->toBe([], $file->getRelativePathname());
    }
});
