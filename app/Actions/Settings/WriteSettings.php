<?php

namespace App\Actions\Settings;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Bước ghi CHUNG của bảng `settings` (M7 Task 10). Mỗi tính năng có Action riêng của nó dựng trên
 * bước này — Action đó hỏi quyền, kiểm tra và chuẩn hoá đầu vào, rồi ghi audit với tên sự kiện của
 * chính nó: `UpdateOfficeProfile` (khoá `office.*`) hôm nay; M11 (`mcp.enabled`,
 * `mcp.write_enabled`, kế hoạch M11 R2) sau này. Bước này KHÔNG hỏi quyền và KHÔNG ghi audit, vì
 * chỉ Action gọi nó mới biết lần ghi này có nghĩa gì và ai được làm.
 *
 * # Giá trị
 *
 * Chuỗi hoặc `null`; chuỗi rỗng hay chỉ khoảng trắng cũng lưu thành `null` = "chưa đặt" (người đọc
 * rơi về mặc định của mình). `'0'` là một giá trị, không phải "trống" — công tắc tắt của M11 lưu
 * `'0'`, không lưu `false`: một giá trị không phải chuỗi bị từ chối (`(string) false` là `''`, tức
 * "chưa đặt", ngược hẳn ý người gọi). Bước này không cắt khoảng trắng hay chuẩn hoá gì thêm.
 *
 * # Trả về
 *
 * Danh sách khoá có giá trị THẬT SỰ đổi, theo thứ tự của `$values` — để Action gọi nó ghi audit chỉ
 * khi có gì đổi, và nêu đúng tên. Ghi `null` vào một khoá chưa từng có không phải một lần đổi.
 *
 * # Khoá dòng
 *
 * Trong một transaction (lồng vào transaction của người gọi nếu có), câu ĐẦU TIÊN là một lần đọc
 * khoá `lockForUpdate()` MỌI khoá của lần ghi, nên so sánh cũ/mới luôn làm trên dòng đã khoá: hai
 * admin bấm lưu cùng lúc thì lần sau chờ lần trước commit, và danh sách "đã đổi" của mỗi lần đúng
 * với giá trị nó thật sự ghi đè.
 *
 * Khoá CHƯA có dòng nào (lần lưu đầu tiên của một trường) thì `insertOrIgnore` một dòng `null` —
 * vô hại, vì dòng `null` và dòng vắng mặt cùng nghĩa "chưa đặt" — rồi khoá-đọc lại. Chỉ chèn khi
 * thiếu, không chèn mỗi lần: `INSERT IGNORE` trúng một khoá đã có vẫn đặt khoá CHIA SẺ lên dòng đó,
 * và hai lần lưu đồng thời cùng giữ khoá chia sẻ rồi cùng xin khoá ghi là một deadlock chắc chắn.
 * Lần lưu đầu tiên đồng thời của CÙNG một khoá mới vẫn có thể deadlock (khoá khoảng của InnoDB);
 * transaction ngoài cùng vì vậy thử lại tới {@see self::ATTEMPTS} lần — chạy lại toàn bộ closure
 * là an toàn, vì nó đọc lại mọi thứ dưới khoá. Chưa có test hai tiến trình cho đường này: hiếm
 * (chỉ admin, chỉ lần lưu đầu), và đường tuần tự chạy thật trên MariaDB khi `test:mariadb`.
 *
 * Từng dòng đổi được lưu bằng `save()` (dấu thời gian và `updated_by` tự đi theo), không
 * `update()` hàng loạt.
 */
class WriteSettings
{
    /** Độ dài cột `settings.key`. */
    public const KEY_MAX_LENGTH = 100;

    /** Số lần chạy transaction khi gặp deadlock (chỉ có tác dụng khi đây là transaction ngoài cùng). */
    public const ATTEMPTS = 3;

    /**
     * @param  array<string, ?string>  $values  khoá đầy đủ (`office.hotline`, `mcp.enabled`) => giá trị
     * @return list<string> khoá đã đổi
     */
    public function handle(array $values, ?User $actor): array
    {
        foreach ($values as $key => $value) {
            $key = (string) $key;

            // Khoá do MÃ đặt, không do người gõ: khoá hỏng là lỗi lập trình, báo trước khi chạm DB.
            // Không trông vào MariaDB strict được: `insertOrIgnore` bên dưới là `INSERT IGNORE`, và
            // IGNORE hạ lỗi "quá dài" thành cảnh báo rồi CẮT khoá còn 100 ký tự (đo trên MariaDB của
            // máy dev: cảnh báo 1265, dòng mang khoá đã cắt) — một dòng sai khoá nằm lại trong bảng.
            if ($key === '' || mb_strlen($key) > self::KEY_MAX_LENGTH) {
                throw new InvalidArgumentException("Khoá cấu hình [{$key}] rỗng hoặc dài hơn ".self::KEY_MAX_LENGTH.' ký tự.');
            }

            if ($value !== null && ! is_string($value)) {
                throw new InvalidArgumentException("Giá trị của khoá cấu hình [{$key}] phải là chuỗi hoặc null.");
            }
        }

        if ($values === []) {
            return [];
        }

        return DB::transaction(function () use ($values, $actor): array {
            $keys = array_map('strval', array_keys($values));

            $rows = $this->lockedRows($keys);
            $missing = array_values(array_diff($keys, $rows->keys()->all()));

            if ($missing !== []) {
                $now = now();

                Setting::query()->insertOrIgnore(array_map(fn (string $key): array => [
                    'key' => $key,
                    'value' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $missing));

                $rows = $this->lockedRows($keys);
            }

            $changed = [];

            foreach ($values as $key => $value) {
                $key = (string) $key;
                $new = filled($value) ? $value : null;

                /** @var Setting $row */
                $row = $rows->get($key);

                if ($row->value === $new) {
                    continue;
                }

                $row->value = $new;
                $row->updated_by = $actor?->getKey();
                $row->save();

                $changed[] = $key;
            }

            return $changed;
        }, self::ATTEMPTS);
    }

    /**
     * @param  list<string>  $keys
     * @return Collection<string, Setting>
     */
    private function lockedRows(array $keys): Collection
    {
        return Setting::query()->whereIn('key', $keys)->lockForUpdate()->get()->keyBy('key');
    }
}
