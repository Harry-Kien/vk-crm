<?php

namespace Database\Seeders;

use App\Models\MatterType;
use App\Support\StagePresets;
use Illuminate\Database\Seeder;

/**
 * Mười hai loại vụ việc tham chiếu — mười hai lĩnh vực hành nghề của văn phòng (M9 Task 1) — và
 * giai đoạn của chúng (lấy từ `StagePresets`). Sáu loại đầu dùng bộ dân sự (`DD`, `DS`, `HN`, `LD`),
 * hình sự (`HS`) hoặc doanh nghiệp (`DN`); sáu loại thêm ở M9 dùng bộ năm giai đoạn TẠM (`StagePresets::provisional()`), ghi rõ trong
 * `description`, cho tới khi chủ văn phòng mô tả quy trình thật.
 *
 * Bốn loại cũ (`DD`, `DN`, `DS`, `LD`) mang tên mới ở đây, nhưng seeder KHÔNG BAO GIỜ đổi tên một
 * loại đã có: máy chủ thật đổi tên bằng migration dữ liệu một lần
 * (`2026_09_30_000001_rename_matter_types_to_office_names`), bản cài mới nhận tên mới thẳng từ đây.
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
    /**
     * `sort_order` = chỉ số + 1, chỉ được gán lúc TẠO. Loại mới chỉ được NỐI VÀO CUỐI mảng: xếp lại
     * sẽ làm loại mới trên máy chủ thật trùng `sort_order` với loại cũ đã có.
     *
     * @return list<array{code: string, name: string, description?: string}>
     */
    public static function types(): array
    {
        $provisional = 'Bộ giai đoạn TẠM, năm bước chung — chờ chủ văn phòng mô tả quy trình thật của lĩnh vực này.';

        return [
            ['code' => 'DD', 'name' => 'Đất đai và bất động sản'],
            ['code' => 'DS', 'name' => 'Giải quyết tranh chấp'],
            ['code' => 'HS', 'name' => 'Hình sự'],
            ['code' => 'DN', 'name' => 'Đầu tư và doanh nghiệp'],
            ['code' => 'LD', 'name' => 'Lao động và nhân sự'],
            ['code' => 'HN', 'name' => 'Hôn nhân và gia đình'],
            ['code' => 'HC', 'name' => 'Hành chính và giấy phép', 'description' => $provisional],
            ['code' => 'TM', 'name' => 'Hợp đồng và thương mại', 'description' => $provisional],
            ['code' => 'NH', 'name' => 'Ngân hàng và tín dụng', 'description' => $provisional],
            ['code' => 'SH', 'name' => 'Sở hữu trí tuệ và công nghệ', 'description' => $provisional],
            ['code' => 'TC', 'name' => 'Thuế và tài chính', 'description' => $provisional],
            ['code' => 'XD', 'name' => 'Xây dựng và hạ tầng', 'description' => $provisional],
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
                'description' => $data['description'] ?? null,
                'is_active' => true,
                'sort_order' => $index + 1,
            ]);

            foreach (StagePresets::for($type->code) as $order => $stage) {
                $type->stages()->create([...$stage, 'sort_order' => $order + 1]);
            }
        }
    }
}
