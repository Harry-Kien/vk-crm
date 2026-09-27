<?php

namespace Database\Seeders;

use App\Models\MatterType;
use App\Support\StagePresets;
use Illuminate\Database\Seeder;

/**
 * Sáu loại vụ việc tham chiếu và giai đoạn của chúng (lấy từ `StagePresets`).
 *
 * **Chỉ THÊM, không bao giờ sửa (final review X10, C-I5).** Seeder này chạy lại trên máy chủ thật
 * mỗi lần `db:seed --force` (docs/CAI-DAT.md). Bản trước dùng `updateOrCreate`, nên mỗi lần chạy
 * lại xoá sạch cấu hình quản trị viên đã chỉnh: tên loại, "Đang dùng", thứ tự, nhãn và mô tả giai
 * đoạn — và TẠO LẠI những giai đoạn đã xoá mềm (dòng đã xoá không khớp `updateOrCreate`). Giờ một
 * loại (kèm giai đoạn) chỉ được tạo khi MÃ của nó chưa từng tồn tại, kể cả đã xoá mềm; loại đã có
 * thì bỏ qua hoàn toàn.
 */
class MatterTypeSeeder extends Seeder
{
    /** @return list<array{code: string, name: string}> */
    public static function types(): array
    {
        return [
            ['code' => 'DD', 'name' => 'Tranh chấp đất đai'],
            ['code' => 'DS', 'name' => 'Tranh chấp dân sự'],
            ['code' => 'HS', 'name' => 'Hình sự'],
            ['code' => 'DN', 'name' => 'Doanh nghiệp'],
            ['code' => 'LD', 'name' => 'Lao động'],
            ['code' => 'HN', 'name' => 'Hôn nhân và gia đình'],
        ];
    }

    public function run(): void
    {
        foreach (self::types() as $index => $data) {
            if (MatterType::withTrashed()->where('code', $data['code'])->exists()) {
                continue;
            }

            $type = MatterType::query()->create([
                'code' => $data['code'],
                'name' => $data['name'],
                'is_active' => true,
                'sort_order' => $index + 1,
            ]);

            foreach (StagePresets::for($type->code) as $order => $stage) {
                $type->stages()->create([...$stage, 'sort_order' => $order + 1]);
            }
        }
    }
}
