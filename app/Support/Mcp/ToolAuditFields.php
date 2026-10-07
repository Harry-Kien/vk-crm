<?php

namespace App\Support\Mcp;

/**
 * Phần "tham số" và "kết quả" của dòng nhật ký `mcp_tool_called` (M11 R8, Task 8): những gì của MỘT
 * lần gọi tool được phép nằm lại trong `activity_log`, và chỉ những thứ đó. Hàm thuần, không trạng
 * thái — gọi qua `App\Support\Mcp\ToolCallContext`.
 *
 * Nhật ký không bao giờ chứa prompt, nội dung, CCCD hay số điện thoại [DC:153], [DC:224], [DC:674].
 * Cả hai nửa là ALLOWLIST theo HÌNH DẠNG giá trị, không blocklist theo tên tham số:
 *
 * **Tham số** ({@see self::arguments()}): chỉ tham số có khai trong `inputSchema` của tool; tên lạ
 * (client tự thêm) không vào nhật ký, kể cả TÊN của nó — chỉ còn số lượng. Với mỗi tham số đã khai:
 *  - `null`, số, cờ: nguyên giá trị;
 *  - chuỗi: nguyên giá trị CHỈ KHI nó là một id có tiền tố ({@see McpIds::isAny()}), một giá trị
 *    trong `enum` mà chính tool khai cho tham số đó, hoặc một ngày/giờ ISO 8601 trọn vẹn
 *    ({@see self::DATE}); mọi chuỗi khác — `query`, `summary`, `counterpart`, `cursor`,
 *    `idempotency_key`… và cả một "id" hay "enum" lẫn chữ khác — thành `['length' => số ký tự]`;
 *  - danh sách tối đa {@see self::MAX_LIST_ITEMS} phần tử: từng phần tử theo đúng luật trên (enum của
 *    `items`); danh sách dài hơn hay object: `['count' => số phần tử]`.
 *
 * **Kết quả** ({@see self::result()}), đọc từ `structuredContent`:
 *  - id các đối tượng trả về: mọi giá trị ở khoá `id` có hình dạng id có tiền tố, không trùng, theo
 *    thứ tự gặp; lưu tối đa {@see self::MAX_RETURNED_IDS};
 *  - số bản ghi: số id khác nhau (không cắt);
 *  - TÊN các trường lá đã trả (`matter.code`, `deadlines[].due_on`), không giá trị nào, sắp theo bảng
 *    chữ; khoá không có dạng tên trường (`[a-z_][a-z0-9_]*`, ≤ 64 ký tự) ghi là `*`, để một khoá dựng
 *    từ dữ liệu không mang dữ liệu vào nhật ký.
 */
final class ToolAuditFields
{
    public const MAX_LIST_ITEMS = 25;

    public const MAX_RETURNED_IDS = 100;

    public const MAX_FIELDS = 100;

    /** Ngày `YYYY-MM-DD`, có thể kèm giờ ISO 8601 (`T` hoặc dấu cách, giây, phần lẻ, múi giờ). */
    public const DATE = '/\A\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})?)?\z/';

    private const FIELD_NAME = '/\A[a-z_][a-z0-9_]{0,63}\z/i';

    /**
     * @param  array<array-key, mixed>  $arguments  `params.arguments` của request
     * @param  array<string, mixed>  $properties  `inputSchema.properties` của tool
     * @return array{arguments: array<string, mixed>, unknown: int}
     */
    public static function arguments(array $arguments, array $properties): array
    {
        $kept = [];
        $unknown = 0;

        foreach ($arguments as $name => $value) {
            if (! is_string($name) || ! isset($properties[$name]) || ! is_array($properties[$name])) {
                $unknown++;

                continue;
            }

            $kept[$name] = self::value($value, $properties[$name]);
        }

        return ['arguments' => $kept, 'unknown' => $unknown];
    }

    /**
     * @param  array<string, mixed>  $structured  `structuredContent` của kết quả tool
     * @return array{ids: list<string>, ids_truncated: bool, count: int, fields: list<string>}
     */
    public static function result(array $structured): array
    {
        $ids = [];
        $fields = [];

        self::walk($structured, '', $ids, $fields);

        $fields = array_keys($fields);
        sort($fields, SORT_STRING);
        $ids = array_map('strval', array_keys($ids));

        return [
            'ids' => array_slice($ids, 0, self::MAX_RETURNED_IDS),
            'ids_truncated' => count($ids) > self::MAX_RETURNED_IDS,
            'count' => count($ids),
            'fields' => array_slice($fields, 0, self::MAX_FIELDS),
        ];
    }

    /** @param  array<string, mixed>  $schema */
    private static function value(mixed $value, array $schema): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return self::isLoggable($value, $schema) ? $value : ['length' => mb_strlen($value)];
        }

        if (is_array($value) && array_is_list($value) && count($value) <= self::MAX_LIST_ITEMS) {
            $items = is_array($schema['items'] ?? null) ? $schema['items'] : [];

            return array_map(fn (mixed $item): mixed => is_array($item)
                ? ['count' => count($item)]
                : self::value($item, $items), $value);
        }

        return ['count' => is_array($value) ? count($value) : 0];
    }

    /** @param  array<string, mixed>  $schema */
    private static function isLoggable(string $value, array $schema): bool
    {
        $enum = is_array($schema['enum'] ?? null) ? $schema['enum'] : [];

        return McpIds::isAny($value)
            || in_array($value, $enum, true)
            || preg_match(self::DATE, $value) === 1;
    }

    /**
     * @param  array<string, true>  $ids
     * @param  array<string, true>  $fields
     */
    private static function walk(mixed $node, string $path, array &$ids, array &$fields): void
    {
        if (! is_array($node)) {
            return;
        }

        if (array_is_list($node)) {
            foreach ($node as $item) {
                self::walk($item, $path.'[]', $ids, $fields);
            }

            return;
        }

        foreach ($node as $key => $value) {
            $segment = is_string($key) && preg_match(self::FIELD_NAME, $key) === 1 ? $key : '*';
            $field = $path === '' ? $segment : $path.'.'.$segment;

            if ($key === 'id' && is_string($value) && McpIds::isAny($value)) {
                $ids[$value] = true;
            }

            if (is_array($value) && $value !== []) {
                self::walk($value, $field, $ids, $fields);

                continue;
            }

            $fields[$field] = true;
        }
    }
}
