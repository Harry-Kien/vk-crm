<?php

namespace App\Support\Performance;

use App\Filament\Admin\Pages\Performance;
use App\Filament\Admin\Pages\TeamMember;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Lối thoát cuối của R11 (M13): giữ tạm số của "Hiệu suất theo kỳ" ({@see Performance}, cả báo cáo) và đầu
 * trang của một người ({@see TeamMember}, dòng `TeamWorkloadRow`) THEO NGƯỜI XEM, tối đa 5 phút.
 *
 * Vì sao có: ngân sách R11 vẫn vỡ sau khi đã thêm index — quý của trưởng phòng/admin khoảng 1,0 giây trên
 * 500 ms, trang một người 233–246 ms trên 200 ms (Ghi chú M13). Phần còn lại là phép phân loại R11 cố ý giữ
 * bằng PHP. Kế hoạch viết sẵn lối thoát: `Cache::remember`, khoá gồm id người xem và bộ lọc, TTL ≤ 5 phút,
 * test hai người xem không bao giờ dùng chung một mục (`PerformanceCacheTest`). Lần mở ĐẦU vẫn tính đủ;
 * nhanh là các request sau trong 5 phút (sắp xếp, công tắc, mở lại).
 *
 * # Khoá
 *
 * `performance:<trang>:<id người xem>:<băm của bộ lọc>`. Id người xem đứng riêng trong khoá, không chỉ
 * trong phần băm: hai trưởng phòng cùng vai trò, cùng tập người, cùng kỳ vẫn khác mục — người này có thể
 * phụ trách một vụ `restricted` mà người kia không thấy (R4). Bộ lọc là mọi thứ của request quyết định
 * HÌNH DẠNG số ngoài người xem: kỳ (mã và hai cận), công tắc người nghỉ việc, id các người trên trang
 * (đổi vai trò làm đổi tập người, nên đổi mục ngay), và cột doanh thu có hiện không (mất quyền đọc tiền thì
 * không đọc lại tiền đã giữ). Trang tự đưa bộ lọc vào; lớp này không đoán.
 *
 * # Điều KHÔNG giữ tạm
 *
 * Danh sách vụ, mốc, yêu cầu, giấy tờ (mã, tên, khách) luôn đọc trực tiếp, cũng như mọi lần hỏi quyền
 * (`boot()` của trang). Chỉ giữ con số. "Theo dõi đội ngũ" không giữ tạm: trang đó trong ngân sách và là
 * hàng đợi hành động (R11 (a)).
 *
 * # Cái giá, có chủ đích
 *
 * Trong 5 phút, một việc vừa làm (bàn giao, hoàn thành mốc, đổi đội ngũ của vụ) có thể chưa hiện trên
 * hai trang này; trang nói điều đó trong "Cách tính các con số" (`performance.cache_note`). Mỗi lần tính
 * là một lần ghi vào kho `cache` (`database`). Giá trị đi qua `cache.serializable_classes` — chỉ các lớp
 * giá trị của số liệu hiệu suất được giải tuần tự hoá.
 */
final class PerformanceCache
{
    /** Trần TTL của R11: 5 phút, dù cấu hình nói gì. */
    public const MAX_SECONDS = 300;

    /** Số giây giữ một mục: `vkcrm.performance.cache_seconds`, cắt ở {@see self::MAX_SECONDS}; `0` là tắt. */
    public static function seconds(): int
    {
        return min((int) config('vkcrm.performance.cache_seconds'), self::MAX_SECONDS);
    }

    /**
     * Câu "số liệu có thể chậm tới N phút" cho khối "Cách tính các con số" của hai trang dùng lớp này — một
     * phần tử khi bộ nhớ tạm đang bật, không phần tử nào khi tắt (`0`).
     *
     * @return list<array{label: string, sentence: string}>
     */
    public static function explanations(): array
    {
        $seconds = self::seconds();

        return $seconds > 0
            ? [['label' => __('performance.cache_note_label'), 'sentence' => __('performance.cache_note', ['minutes' => (int) ceil($seconds / 60)])]]
            : [];
    }

    /**
     * Giá trị của `$compute` cho (trang, người xem, bộ lọc), giữ tạm {@see self::seconds()} giây.
     *
     * @template T
     *
     * @param  array<int|string, mixed>  $filters  mọi đầu vào ngoài người xem quyết định con số (xem docblock lớp)
     * @param  Closure(): T  $compute
     * @return T
     */
    public static function remember(string $page, User $viewer, array $filters, Closure $compute): mixed
    {
        $seconds = self::seconds();

        // Tắt là không đọc cả mục còn lại từ lúc bật: `Cache::remember()` với TTL 0 vẫn trả mục cũ nếu có.
        if ($seconds <= 0) {
            return $compute();
        }

        $key = sprintf('performance:%s:%d:%s', $page, $viewer->getKey(), hash('sha256', serialize($filters)));

        return Cache::remember($key, $seconds, $compute);
    }
}
