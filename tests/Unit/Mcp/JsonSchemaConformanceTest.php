<?php

use Tests\Support\JsonSchemaConformance;

/*
|--------------------------------------------------------------------------
| M11 Task 10 — bộ kiểm JSON Schema tối thiểu của test (tests/Support/JsonSchemaConformance)
|--------------------------------------------------------------------------
| Test tool khẳng định `structuredContent` khớp `outputSchema` bằng bộ kiểm này; một bộ kiểm luôn
| "khớp" làm mọi khẳng định đó xanh vô nghĩa. Mỗi từ khoá một cặp dương/âm.
*/

/** @return array<string, mixed> */
function jsonSchemaConformanceFixture(): array
{
    return [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['id', 'tags', 'owner'],
        'properties' => [
            'id' => ['type' => 'string'],
            'count' => ['type' => 'integer'],
            'ratio' => ['type' => 'number'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
            'owner' => ['type' => ['object', 'null'], 'additionalProperties' => false, 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]],
            'kind' => ['type' => 'string', 'enum' => ['a', 'b']],
        ],
    ];
}

it('khớp một giá trị đúng (cặp dương)', function () {
    $schema = jsonSchemaConformanceFixture();

    expect(JsonSchemaConformance::violations($schema, [
        'id' => 'x', 'count' => 1, 'ratio' => 2, 'tags' => ['a'], 'owner' => ['name' => 'n'], 'kind' => 'a',
    ]))->toBe([])
        ->and(JsonSchemaConformance::violations($schema, ['id' => 'x', 'tags' => [], 'owner' => null]))->toBe([]);
});

it('bắt từng loại lệch: sai kiểu, thiếu khoá bắt buộc, khoá ngoài khai báo, phần tử mảng sai, ngoài enum, null không được phép', function (array $value, string $expected) {
    $violations = JsonSchemaConformance::violations(jsonSchemaConformanceFixture(), $value);

    expect(implode("\n", $violations))->toContain($expected);
})->with([
    'sai kiểu' => [['id' => 5, 'tags' => [], 'owner' => null], '$.id: kiểu integer'],
    'thiếu khoá' => [['id' => 'x', 'tags' => []], "thiếu khoá bắt buộc 'owner'"],
    'khoá ngoài khai báo' => [['id' => 'x', 'tags' => [], 'owner' => null, 'email' => 'a@b'], "khoá ngoài khai báo 'email'"],
    'khoá ngoài khai báo, lồng' => [['id' => 'x', 'tags' => [], 'owner' => ['name' => 'n', 'phone' => '1']], "$.owner: khoá ngoài khai báo 'phone'"],
    'phần tử mảng' => [['id' => 'x', 'tags' => ['a', 3], 'owner' => null], '$.tags[1]: kiểu integer'],
    'ngoài enum' => [['id' => 'x', 'tags' => [], 'owner' => null, 'kind' => 'c'], '$.kind: giá trị ngoài enum'],
    'null không được phép' => [['id' => null, 'tags' => [], 'owner' => null], '$.id: kiểu null'],
]);

it('báo từ khoá schema mà bộ kiểm không hiểu, thay vì coi là khớp', function () {
    expect(JsonSchemaConformance::violations(['type' => 'string', 'pattern' => '^a'], 'b'))
        ->toBe(["$: từ khoá schema lạ 'pattern'"]);
});
