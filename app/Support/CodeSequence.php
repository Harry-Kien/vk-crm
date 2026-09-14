<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Sinh số thứ tự tuần tự theo khoá. Hai request cùng lúc luôn nhận hai số khác nhau
 * vì dòng đếm bị khoá trong transaction; nếu có transaction bao ngoài thì tham gia
 * transaction đó và cùng rollback.
 */
final class CodeSequence
{
    public static function next(string $key): int
    {
        return DB::transaction(function () use ($key): int {
            DB::table('code_sequences')->insertOrIgnore([
                'key' => $key,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $current = (int) DB::table('code_sequences')
                ->where('key', $key)
                ->lockForUpdate()
                ->value('last_number');

            $next = $current + 1;

            DB::table('code_sequences')
                ->where('key', $key)
                ->update(['last_number' => $next, 'updated_at' => now()]);

            return $next;
        });
    }

    public static function format(string $prefix, int $number): string
    {
        return $prefix.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }
}
