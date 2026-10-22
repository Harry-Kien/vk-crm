<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rà soát toàn hệ thống 2026-10-22 (làn intake): `Normalizer::name()` nay bỏ dấu câu (mọi ký tự không
 * phải chữ hay số thành một khoảng trắng), để "Công ty TNHH ABC." khớp "Công ty TNHH ABC" ở tầng tên của
 * kiểm tra xung đột lợi ích (SPEC §6.10). Tính lại mọi cột tên chuẩn hoá ĐÃ LƯU từ cột tên gốc cạnh nó —
 * thiếu bước này, tên nhập trước bản cập nhật vẫn mang dấu câu và không bao giờ khớp tên gõ sau.
 *
 * Phép chuẩn hoá được CHÉP vào đây chứ không gọi `App\Support\Normalizer`: migration phải cho cùng
 * kết quả dù lớp đó đổi về sau. `down()` dùng phép cũ (giữ dấu câu). Dòng đã ẩn danh có tên gốc `null`
 * nên cột chuẩn hoá vẫn `null`. Không đổi `updated_at`: đây là sửa dữ liệu dẫn xuất, không phải một lần
 * người dùng sửa bản ghi.
 */
return new class extends Migration
{
    /** @var array<string, array<string, string>> bảng => [cột chuẩn hoá => cột tên gốc] */
    private const COLUMNS = [
        'matter_parties' => ['name_normalized' => 'name'],
        'intake_parties' => ['name_normalized' => 'name'],
        'intake_requests' => [
            'contact_name_normalized' => 'contact_name',
            'organization_name_normalized' => 'organization_name',
        ],
    ];

    public function up(): void
    {
        $this->rewrite(fn (string $ascii): string => (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $ascii));
    }

    public function down(): void
    {
        $this->rewrite(fn (string $ascii): string => (string) preg_replace('/\s+/u', ' ', $ascii));
    }

    private function rewrite(Closure $collapse): void
    {
        $normalize = function (?string $value) use ($collapse): ?string {
            if ($value === null || trim($value) === '') {
                return null;
            }

            return trim($collapse(Str::ascii(mb_strtolower(trim($value), 'UTF-8')))) ?: null;
        };

        foreach (self::COLUMNS as $table => $columns) {
            DB::table($table)
                ->select(['id', ...array_values($columns), ...array_keys($columns)])
                ->chunkById(500, function ($rows) use ($table, $columns, $normalize): void {
                    foreach ($rows as $row) {
                        $changes = [];

                        foreach ($columns as $normalized => $source) {
                            $value = $normalize($row->{$source});

                            if ($value !== $row->{$normalized}) {
                                $changes[$normalized] = $value;
                            }
                        }

                        if ($changes !== []) {
                            DB::table($table)->where('id', $row->id)->update($changes);
                        }
                    }
                });
        }
    }
};
