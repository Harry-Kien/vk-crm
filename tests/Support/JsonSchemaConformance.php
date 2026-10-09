<?php

namespace Tests\Support;

/**
 * Bộ kiểm JSON Schema tối thiểu cho test M11: đủ cho đúng những từ khoá mà `outputSchema` của bộ
 * tool dùng — `type` (một kiểu hay danh sách kiểu, gồm `null`), `properties`, `required`,
 * `additionalProperties: false`, `items`, `enum`. Từ khoá nào khác có trong schema thì báo vi phạm,
 * để một schema dùng thứ bộ kiểm không hiểu không bao giờ "khớp" một cách im lặng.
 *
 * Không có gói JSON Schema nào trong vendor (và kế hoạch M11 chỉ cho phép hai gói mới), nên bộ kiểm
 * này sống trong `tests/`. `JsonSchemaConformanceTest` có cặp dương/âm cho từng từ khoá.
 */
final class JsonSchemaConformance
{
    private const KNOWN = ['type', 'properties', 'required', 'additionalProperties', 'items', 'enum', 'description', 'title', 'maxLength', 'minLength', 'minimum', 'maximum', 'default'];

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string> mỗi vi phạm một dòng "đường dẫn: lý do"; rỗng là khớp
     */
    public static function violations(array $schema, mixed $value, string $path = '$'): array
    {
        $violations = [];

        foreach (array_keys($schema) as $keyword) {
            if (! in_array($keyword, self::KNOWN, true)) {
                $violations[] = "{$path}: từ khoá schema lạ '{$keyword}'";
            }
        }

        $type = self::typeOf($value);

        if (isset($schema['type'])) {
            $types = (array) $schema['type'];

            // `json_decode(…, true)` trả `[]` cho cả `{}` lẫn `[]`: rỗng khớp object khi schema đòi object.
            if ($value === [] && in_array('object', $types, true) && ! in_array('array', $types, true)) {
                $type = 'object';
            }

            if (! in_array($type, $types, true) && ! ($type === 'integer' && in_array('number', $types, true))) {
                return [...$violations, "{$path}: kiểu {$type} không thuộc ".implode('|', $types)];
            }
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $violations[] = "{$path}: giá trị ngoài enum";
        }

        if ($type === 'object') {
            /** @var array<string, mixed> $value */
            $properties = $schema['properties'] ?? [];

            foreach ($schema['required'] ?? [] as $required) {
                if (! array_key_exists($required, $value)) {
                    $violations[] = "{$path}: thiếu khoá bắt buộc '{$required}'";
                }
            }

            foreach ($value as $key => $child) {
                if (isset($properties[$key])) {
                    $violations = [...$violations, ...self::violations($properties[$key], $child, "{$path}.{$key}")];
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $violations[] = "{$path}: khoá ngoài khai báo '{$key}'";
                }
            }
        }

        if ($type === 'array' && isset($schema['items'])) {
            foreach ($value as $index => $item) {
                $violations = [...$violations, ...self::violations($schema['items'], $item, "{$path}[{$index}]")];
            }
        }

        return $violations;
    }

    /** Kiểu JSON của một giá trị PHP đã `json_decode(…, true)`: mảng có khoá chuỗi (hay rỗng có `properties`) là object. */
    private static function typeOf(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) && $value !== [] && ! array_is_list($value) => 'object',
            is_array($value) => 'array',
            default => 'unknown',
        };
    }
}
