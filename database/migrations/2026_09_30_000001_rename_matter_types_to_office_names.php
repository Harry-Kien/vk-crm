<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M9 Task 1 — migration DỮ LIỆU một lần: bốn loại vụ việc cũ mang tên của văn phòng.
 *
 * Văn phòng hành nghề mười hai lĩnh vực; `MatterTypeSeeder` hôm nay đặt tên bốn loại đầu theo cách
 * gọi cũ. Seeder chỉ THÊM, không bao giờ ghi đè (final review X10), nên một máy chủ đã có dữ liệu
 * không nhận tên mới từ seeder; migration này đổi tên đúng một lần. Bản cài mới (bảng còn rỗng lúc
 * migration chạy) không có gì để đổi — seeder tạo thẳng tên mới.
 *
 * **Chỉ đổi khi tên hiện tại ĐÚNG BẰNG tên seed cũ**, so từng ký tự. Tên khác nghĩa là quản trị viên
 * đã tự đặt, và migration không đụng vào. `code` không đổi một chữ (`matters.code` nhúng mã loại).
 *
 * **Bẫy collation (MariaDB).** `utf8mb4_unicode_ci` so `=` không phân biệt hoa/thường lẫn dấu:
 * `where name = 'Lao động'` khớp cả "LAO ĐỘNG", "Lao Động" và "Lao dong". Một quản trị viên đổi tên
 * chỉ khác hoa/thường hay dấu sẽ bị "sửa" lại đúng điều họ cố ý gõ. Vì vậy câu truy vấn chỉ lọc
 * thô theo `code` (không theo tên), còn khớp tên (và khớp `code`) là `===` trong PHP, rồi cập nhật
 * theo `id`. Không dùng `BINARY`: `===` không phụ thuộc collation của cột hay của kết nối.
 *
 * Chép cứng cặp tên cũ/mới ngay đây: migration là ảnh chụp đóng băng của một thời điểm, không được
 * đọc từ `MatterTypeSeeder` (lớp đó còn đổi tiếp).
 *
 * Dòng đã xoá mềm cũng được đổi, theo đúng luật "tên đúng bằng tên seed cũ": vô hại, và nếu quản trị
 * viên khôi phục dòng đó thì nó mang tên của văn phòng như các loại khác. Dòng vẫn ở trạng thái đã xoá.
 *
 * Dùng `DB::table`, không dùng model: `MatterType` có hook `saving` (kiểm trùng mã) và
 * `HasBlameable` (đọc phiên đăng nhập), còn migration chạy không có ai đăng nhập. `matter_types` không
 * dùng `LogsActivity`, nên không mất dòng nhật ký nào. `updated_at` và `updated_by` KHÔNG đổi: đây
 * là dọn dữ liệu tham chiếu, không phải một lần sửa của người nào.
 *
 * `down()` đảo lại theo cùng luật: chỉ dòng có tên đúng bằng tên MỚI mới trở về tên cũ, để lần
 * `migrate:reset` rồi `migrate` + `db:seed` của CI (job `schema`) không làm hỏng dữ liệu.
 */
return new class extends Migration
{
    /** @var array<string, array{old: string, new: string}> mã loại => cặp tên cũ/mới */
    private const RENAMES = [
        'DD' => ['old' => 'Tranh chấp đất đai', 'new' => 'Đất đai và bất động sản'],
        'DN' => ['old' => 'Doanh nghiệp', 'new' => 'Đầu tư và doanh nghiệp'],
        'DS' => ['old' => 'Tranh chấp dân sự', 'new' => 'Giải quyết tranh chấp'],
        'LD' => ['old' => 'Lao động', 'new' => 'Lao động và nhân sự'],
    ];

    public function up(): void
    {
        $this->rename('old', 'new');
    }

    public function down(): void
    {
        $this->rename('new', 'old');
    }

    /** Đổi mọi dòng (kể cả đã xoá mềm) có đúng `code` và đúng tên `$from` sang tên `$to`. */
    private function rename(string $from, string $to): void
    {
        $rows = DB::table('matter_types')
            ->whereIn('code', array_keys(self::RENAMES))
            ->get(['id', 'code', 'name']);

        foreach ($rows as $row) {
            $pair = self::RENAMES[$row->code] ?? null;

            // `===` trên cả `code` lẫn `name`: xem "Bẫy collation" ở docblock.
            if ($pair === null || $row->name !== $pair[$from]) {
                continue;
            }

            DB::table('matter_types')->where('id', $row->id)->update(['name' => $pair[$to]]);
        }
    }
};
