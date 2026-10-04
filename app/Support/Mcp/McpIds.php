<?php

namespace App\Support\Mcp;

use InvalidArgumentException;

/**
 * Id có tiền tố của bộ tool MCP (kế hoạch M11, "Quy ước chung" [DC:30-39]): `matter_123`,
 * `deadline_9`, `request_7`, `doc_88`… Một tiền tố cho mỗi loại đối tượng, để AI không đưa nhầm id
 * của loại này vào tool của loại kia mà vẫn "trúng" một bản ghi.
 *
 * Đọc ngược CHẶT: đúng `<tiền tố>_<số nguyên dương không có số 0 đứng đầu>`, tối đa 18 chữ số (vừa
 * `int` 64 bit). Mọi thứ khác — tiền tố khác, chữ hoa, khoảng trắng, xuống dòng, số 0, chữ số
 * Unicode — cho `null`, và tool trả "Không tìm thấy" y như một id không tồn tại (R3): không có thông
 * điệp "id sai định dạng" để dò. Một id có đúng một cách viết, nên id trong nhật ký (Task 8) so được
 * bằng chuỗi.
 *
 * Tiền tố lạ ở phía MÃ (`encode('client', …)`) là lỗi lập trình và ném lỗi, không trả `null`.
 */
final class McpIds
{
    public const MATTER = 'matter';

    public const DEADLINE = 'deadline';

    public const REQUEST = 'request';

    public const DOCUMENT = 'doc';

    public const UPDATE = 'update';

    public const CHECKLIST_ITEM = 'item';

    public const REPLY = 'reply';

    public const USER = 'user';

    private const TYPES = [
        self::MATTER, self::DEADLINE, self::REQUEST, self::DOCUMENT,
        self::UPDATE, self::CHECKLIST_ITEM, self::REPLY, self::USER,
    ];

    public static function encode(string $type, int $id): string
    {
        self::assertKnown($type);

        if ($id < 1) {
            throw new InvalidArgumentException("McpIds: id phải dương, nhận {$id}.");
        }

        return "{$type}_{$id}";
    }

    /** Id số của `$value` nếu nó là đúng một id loại `$type`, ngược lại `null`. */
    public static function decode(?string $value, string $type): ?int
    {
        self::assertKnown($type);

        return self::parse($value, $type)['id'] ?? null;
    }

    /**
     * `$value` thuộc MỘT trong các loại `$types` thì trả loại và id số, ngược lại `null` — cho tool
     * nhận nhiều loại id (`fetch`: vụ việc hoặc yêu cầu).
     *
     * @return array{type: string, id: int}|null
     */
    public static function parse(?string $value, string ...$types): ?array
    {
        foreach ($types as $type) {
            self::assertKnown($type);
        }

        if ($value === null || preg_match('/\A([a-z]+)_([1-9][0-9]{0,17})\z/', $value, $match) !== 1) {
            return null;
        }

        if (! in_array($match[1], $types, true)) {
            return null;
        }

        return ['type' => $match[1], 'id' => (int) $match[2]];
    }

    private static function assertKnown(string $type): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("McpIds: loại id lạ '{$type}'.");
        }
    }
}
