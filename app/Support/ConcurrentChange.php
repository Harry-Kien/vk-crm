<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Hai lỗi InnoDB mà một người dùng chỉ cần THỬ LẠI để vượt qua (final review wave 2, phụ lục — mẫu
 * lỗi làn M9 tìm ra): ERROR 1020 "Record has changed since last read" (MariaDB 11.8,
 * `innodb_snapshot_isolation = ON`: một transaction ghi/khoá một dòng mà phiên khác đã sửa sau ảnh
 * chụp của nó) và ERROR 1213 deadlock. Để lọt ra ngoài, cả hai là một trang lỗi 500 tiếng Anh;
 * đổi thành một lỗi kiểm tra tiếng Việt nói đúng điều người dùng cần làm.
 *
 * Không phải cách sửa: đường ghi phải tự tránh 1020 bằng cách để câu ĐẦU TIÊN của transaction là
 * một lần đọc CÓ khoá. Đây chỉ là lưới an toàn cho những gì còn sót (deadlock vẫn có thể xảy ra ở
 * bất kỳ đâu có hai khoá).
 */
final class ConcurrentChange
{
    /** @var list<int> Mã lỗi phía máy chủ (`errorInfo[1]`) của MariaDB/MySQL. */
    public const RETRYABLE_CODES = [1020, 1213];

    public static function isRetryable(QueryException $exception): bool
    {
        return in_array((int) ($exception->errorInfo[1] ?? 0), self::RETRYABLE_CODES, true);
    }

    /**
     * Chạy `$callback`; một `QueryException` 1020/1213 thành `ValidationException` gắn vào `$field`.
     * Mọi lỗi khác đi ra nguyên vẹn.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function guard(string $field, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (QueryException $exception) {
            if (! self::isRetryable($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                $field => [__('actions.concurrent_change_retry')],
            ]);
        }
    }
}
