<?php

namespace App\Support\Mcp;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * `cursor` phân trang của các tool danh sách MCP (kế hoạch M11, "Quy ước chung": `limit` mặc định
 * 10, tối đa 25, `cursor`). Phân trang theo khoá (keyset): cursor mang vị trí của dòng cuối trang
 * trước, trang kế là các dòng đứng SAU vị trí đó — ổn định khi có dòng mới chen vào, không đếm, không
 * `OFFSET`. Hai dạng vị trí:
 *  - chỉ id ({@see self::encode()}/{@see self::decode()}, `search_matters`: thứ tự `id` giảm dần);
 *  - giá trị cột sắp xếp cộng id ({@see self::encodePosition()}/{@see self::decodePosition()}, Task 11:
 *    mốc theo hạn, dòng tiến độ theo ngày xảy ra, tài liệu theo ngày tạo, yêu cầu theo hoạt động gần
 *    nhất). Giá trị cột là chuỗi thô đọc từ CSDL, hoặc `null` khi cột rỗng.
 *
 * Cursor là một chuỗi MÃ HOÁ bằng `APP_KEY` (`Crypt::encryptString`, AES kèm MAC), gắn bốn thứ:
 *  - người sở hữu token (`users.id`) — cursor của người A không mở được trang của người B (Task 10);
 *  - tên tool;
 *  - dấu vân tay của bộ lọc — đổi bộ lọc giữa chừng thì cursor cũ không dùng được, thay vì trả một
 *    trang của một danh sách khác;
 *  - vị trí của dòng cuối.
 *
 * Mọi cursor không dùng được (sửa, rác, của người khác, của tool khác, bộ lọc khác, sai dạng vị trí)
 * cho cùng một câu trả lời `null`; tool đổi nó thành MỘT thông điệp. Cursor không mở thêm gì: trang kế
 * vẫn đi qua `McpMatterScope` của người gọi, nên kể cả khi giải mã được thì cũng chỉ ra những dòng
 * người đó thấy. Không có hạn dùng: cursor không chứa dữ liệu nào người gọi chưa có.
 */
final class McpCursor
{
    private const VERSION = 1;

    /**
     * @param  array<string, mixed>  $filters  bộ lọc đã kiểm của lần gọi (không gồm `limit`, `cursor`)
     */
    public static function encode(User $actor, string $tool, array $filters, int $afterId): string
    {
        return self::seal($actor, $tool, $filters, ['a' => $afterId]);
    }

    /**
     * Id của dòng cuối trang trước, hoặc `null` nếu cursor không dùng được cho đúng người, tool và
     * bộ lọc này.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function decode(string $cursor, User $actor, string $tool, array $filters): ?int
    {
        return self::open($cursor, $actor, $tool, $filters)['a'] ?? null;
    }

    /**
     * Cursor mang vị trí (giá trị cột sắp xếp, id) của dòng cuối trang — cho danh sách không xếp theo
     * `id` (Task 11).
     *
     * @param  array<string, mixed>  $filters  bộ lọc đã kiểm của lần gọi (không gồm `limit`, `cursor`)
     * @param  string|null  $sort  giá trị thô của cột sắp xếp trên dòng cuối, `null` khi cột rỗng
     */
    public static function encodePosition(User $actor, string $tool, array $filters, ?string $sort, int $afterId): string
    {
        return self::seal($actor, $tool, $filters, ['s' => $sort, 'a' => $afterId]);
    }

    /**
     * Vị trí của dòng cuối trang trước, hoặc `null` nếu cursor không dùng được cho đúng người, tool và
     * bộ lọc này — kể cả một cursor chỉ có id (của {@see self::encode()}) hay mang giá trị cột không
     * phải chuỗi.
     *
     * @param  array<string, mixed>  $filters
     * @return array{sort: ?string, id: int}|null
     */
    public static function decodePosition(string $cursor, User $actor, string $tool, array $filters): ?array
    {
        $payload = self::open($cursor, $actor, $tool, $filters);

        if ($payload === null
            || ! array_key_exists('s', $payload)
            || ! ($payload['s'] === null || is_string($payload['s']))) {
            return null;
        }

        return ['sort' => $payload['s'], 'id' => $payload['a']];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $position
     */
    private static function seal(User $actor, string $tool, array $filters, array $position): string
    {
        return Crypt::encryptString((string) json_encode([
            'v' => self::VERSION,
            'u' => (int) $actor->getKey(),
            't' => $tool,
            'f' => self::fingerprint($filters),
            ...$position,
        ]));
    }

    /**
     * Payload đã giải mã nếu nó đúng phiên bản, người, tool, bộ lọc và mang một id dương; ngược lại
     * `null`.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>|null
     */
    private static function open(string $cursor, User $actor, string $tool, array $filters): ?array
    {
        try {
            $payload = json_decode(Crypt::decryptString($cursor), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($payload)
            || ($payload['v'] ?? null) !== self::VERSION
            || ($payload['u'] ?? null) !== (int) $actor->getKey()
            || ($payload['t'] ?? null) !== $tool
            || ($payload['f'] ?? null) !== self::fingerprint($filters)
            || ! is_int($payload['a'] ?? null)
            || $payload['a'] < 1) {
            return null;
        }

        return $payload;
    }

    /** @param  array<string, mixed>  $filters */
    private static function fingerprint(array $filters): string
    {
        ksort($filters);

        return hash('sha256', (string) json_encode($filters));
    }
}
