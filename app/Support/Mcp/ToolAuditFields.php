<?php

namespace App\Support\Mcp;

/**
 * Phần "tham số" và "kết quả" của dòng nhật ký `mcp_tool_called` (M11 R8, Task 8): những gì của MỘT
 * lần gọi tool được phép nằm lại trong `activity_log`, và chỉ những thứ đó. Hàm thuần, không trạng
 * thái — gọi qua `App\Support\Mcp\ToolCallContext`.
 *
 * Nhật ký không bao giờ chứa prompt, nội dung, CCCD hay số điện thoại [DC:153], [DC:224], [DC:674].
 * Cả hai nửa là ALLOWLIST, không blocklist theo tên tham số:
 *
 * **Tham số** ({@see self::arguments()}): chỉ tham số có khai trong `inputSchema` của tool; tên lạ
 * (client tự thêm) không vào nhật ký, kể cả TÊN của nó — chỉ còn số lượng. Với mỗi tham số đã khai,
 * quyết theo KIỂU mà tool khai cho tham số đó (`type`, `enum`, `format`), không theo hình dạng của giá
 * trị (rà soát Task 8, I1 — model hay gửi số điện thoại hay CCCD dạng số JSON vào `query`):
 *  - `null`: nguyên giá trị;
 *  - cờ chỉ ở tham số `boolean`; số nguyên ở tham số `integer`/`number`; số lẻ chỉ ở tham số `number`;
 *  - chuỗi chỉ ở tham số `string`, và CHỈ KHI nó là một id có tiền tố ({@see McpIds::isAny()}), một
 *    giá trị trong `enum` mà chính tool khai cho tham số đó, hoặc một ngày/giờ ISO 8601 trọn vẹn
 *    ({@see self::DATE}) ở tham số khai `format` là `date`/`date-time` ({@see self::DATE_FORMATS});
 *  - mọi giá trị vô hướng khác — chuỗi của `query`, `summary`, `counterpart`, `cursor`,
 *    `idempotency_key`…, một "id" hay "enum" lẫn chữ khác, một ngày ở tham số không khai `format`, một
 *    số hay cờ ở tham số không khai đúng kiểu đó — thành `['length' => số ký tự]` (số và cờ đo theo
 *    dạng chữ: `912345678` là 9, `true` là 4);
 *  - danh sách tối đa {@see self::MAX_LIST_ITEMS} phần tử: từng phần tử theo đúng luật trên với schema
 *    của `items`; danh sách dài hơn hay object: `['count' => số phần tử]`.
 * Vì vậy tham số ngày của tool phải khai `->format('date')` (hay `date-time`) thì giá trị mới được ghi.
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

    /** `format` của JSON Schema đánh dấu một tham số ngày (`$schema->string()->format('date')`). */
    public const DATE_FORMATS = ['date', 'date-time'];

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
        if ($value === null) {
            return null;
        }

        if (is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return self::isLoggable($value, $schema) ? $value : ['length' => mb_strlen(self::text($value))];
        }

        if (is_array($value) && array_is_list($value) && count($value) <= self::MAX_LIST_ITEMS) {
            $items = is_array($schema['items'] ?? null) ? $schema['items'] : [];

            return array_map(fn (mixed $item): mixed => is_array($item)
                ? ['count' => count($item)]
                : self::value($item, $items), $value);
        }

        return ['count' => is_array($value) ? count($value) : 0];
    }

    /**
     * Giá trị vô hướng được ghi nguyên khi KIỂU nó khớp kiểu mà tham số khai (`type`, có thể là một
     * mảng như `["string", "null"]`), không theo hình dạng của chính giá trị (rà soát Task 8, I1):
     *  - cờ chỉ ở tham số `boolean`;
     *  - số nguyên ở tham số `integer` hay `number`; số lẻ chỉ ở tham số `number`;
     *  - chuỗi chỉ ở tham số `string`, và chỉ khi là id có tiền tố, giá trị trong `enum` của tham số,
     *    hoặc ngày/giờ ISO ({@see self::DATE}) ở tham số khai `format` là `date` hay `date-time`.
     *
     * @param  array<string, mixed>  $schema
     */
    private static function isLoggable(bool|int|float|string $value, array $schema): bool
    {
        $types = array_filter((array) ($schema['type'] ?? []), 'is_string');

        if (is_bool($value)) {
            return in_array('boolean', $types, true);
        }

        if (is_int($value)) {
            return in_array('integer', $types, true) || in_array('number', $types, true);
        }

        if (is_float($value)) {
            return in_array('number', $types, true);
        }

        if (! in_array('string', $types, true)) {
            return false;
        }

        $enum = is_array($schema['enum'] ?? null) ? $schema['enum'] : [];

        return McpIds::isAny($value)
            || in_array($value, $enum, true)
            || (in_array($schema['format'] ?? null, self::DATE_FORMATS, true) && preg_match(self::DATE, $value) === 1);
    }

    /** Dạng chữ của một giá trị vô hướng, chỉ để đo độ dài (cờ: `true`/`false`, số: như PHP in ra). */
    private static function text(bool|int|float|string $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
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
