<?php

namespace App\Support\Performance;

use App\Actions\Matter\ReassignMatter;
use App\Models\Matter;
use Carbon\CarbonInterface;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Spatie\Activitylog\Models\Activity;

/**
 * Luật sư phụ trách của một vụ việc TẠI một thời điểm (M13, R18) — dựng lại từ nhật ký, không phải
 * `lead_lawyer_id` hiện tại. Dùng cho P5 (vụ kết thúc trong kỳ: người phụ trách lúc vụ kết thúc) và
 * cho {@see RequestHolderAt} (luồng chưa giao ai thuộc về người phụ trách vụ lúc đó).
 *
 * # Luật
 *
 * Tìm dòng `matter_reassigned` (chủ thể là vụ) SỚM NHẤT có `created_at` SAU thời điểm hỏi: có thì
 * người phụ trách lúc đó là `properties.from_user_id` của dòng ấy (người đã bàn giao đi); không có thì
 * là `lead_lawyer_id` hiện tại. So "sau" CHẶT (`created_at > $at`): một dòng ghi đúng giây `$at` coi như
 * đã có hiệu lực tại `$at`. Cùng hình dạng với {@see DeadlineHolderAtDue} và {@see RequestHolderAt}.
 *
 * # Vì sao lịch sử này đầy đủ, kể cả trước ngày triển khai M13
 *
 * {@see ReassignMatter} là đường DUY NHẤT đổi `lead_lawyer_id` (docblock lớp đó), và nó ghi
 * `matter_reassigned` kèm `from_user_id` từ M6.5. Khác lịch sử người giữ mốc (R9) hay luồng giao đích
 * danh (R18), không có lần bàn giao vụ nào thiếu dòng.
 *
 * # Dữ liệu hỏng: không đoán
 *
 * `from_user_id` rỗng hoặc không phải một id (số nguyên, hoặc chuỗi chữ số): trả `null`. Vụ đó không quy
 * về ai, chỉ vào dòng "Chung" (R5, R8). Mọi đường ghi hôm nay đều ghi một id.
 *
 * # Một truy vấn cho cả lô (R11)
 *
 * Mọi dòng `matter_reassigned` của các vụ trong lô, qua index morph `subject` của `activity_log`; phân
 * loại theo thời điểm bằng PHP. `$at` có thể khác nhau cho từng vụ (P5: `closed_at` của chính vụ đó),
 * nên không có điều kiện thời gian nào trong SQL.
 *
 * `$matters` cần cột `id` và `lead_lawyer_id`. Lớp này có tên trong danh sách ngoại lệ của
 * `NoSecondDefinitionTest`: được viết điều kiện trên `event`, `subject_type`, `subject_id`, `created_at` của
 * `activity_log` (R18) — truy vấn lịch sử của cả ba bộ dựng nằm ở {@see self::changes()}.
 */
final class LeadAt
{
    /** Khoá sự kiện mang lịch sử người phụ trách vụ — dòng bước 5 của {@see ReassignMatter}. */
    public const EVENT = 'matter_reassigned';

    /**
     * @param  array<int, list<array{at: CarbonInterface, from: mixed}>>  $changes  matter_id => các lần
     *                                                                              bàn giao, cũ trước
     */
    private function __construct(private readonly array $changes) {}

    /**
     * @param  Collection<int, Matter>  $matters
     * @param  Closure(Matter): CarbonInterface  $at
     * @return array<int, ?int> matter_id => user_id; `null` = không quy được về ai
     */
    public static function resolve(Collection $matters, Closure $at): array
    {
        if ($matters->isEmpty()) {
            return [];
        }

        $history = self::historyOf($matters->map(fn (Matter $matter): int => (int) $matter->getKey())->all());

        return $matters
            ->mapWithKeys(fn (Matter $matter): array => [$matter->getKey() => $history->leadOf($matter, $at($matter))])
            ->all();
    }

    /**
     * Lịch sử bàn giao của nhiều vụ, nạp bằng MỘT truy vấn, để hỏi lại nhiều lần với các thời điểm
     * khác nhau trên CÙNG một vụ — {@see RequestHolderAt} cần đúng điều đó (hai luồng của một vụ, hỏi
     * tại hai lúc trả lời khác nhau), điều mà {@see self::resolve()} (một thời điểm cho mỗi vụ) không làm.
     *
     * @param  list<int>  $matterIds
     */
    public static function historyOf(array $matterIds): self
    {
        return new self(self::changes(self::EVENT, (new Matter)->getMorphClass(), $matterIds, 'from_user_id'));
    }

    /** Luật sư phụ trách `$matter` tại `$at` — xem docblock lớp. */
    public function leadOf(Matter $matter, CarbonInterface $at): ?int
    {
        $moment = $at->getTimestamp();

        foreach ($this->changes[(int) $matter->getKey()] ?? [] as $change) {
            if ($change['at'] > $moment) {
                return self::userIdIn($change['from']);
            }
        }

        return self::userIdIn($matter->lead_lawyer_id);
    }

    /**
     * Mọi dòng lịch sử `$event` của các chủ thể `$subjectType` có id trong `$subjectIds`, cũ trước — MỘT
     * truy vấn qua index morph `subject` của `activity_log`. Cả ba bộ dựng lịch sử đọc qua đây
     * ({@see DeadlineHolderAtDue}, {@see RequestHolderAt} và lớp này), mỗi bộ với khoá sự kiện của mình.
     *
     * Đọc THÔ (`toBase()`), không dựng model `Activity`: một quý của trưởng phòng là hàng nghìn tới hàng chục
     * nghìn dòng lịch sử, và dựng model (cast JSON `properties`, cast ngày `created_at`) cho từng dòng là phần
     * lớn thời gian của trang "Hiệu suất theo kỳ" (số đo Task 8, R11). `at` là dấu thời gian Unix của
     * `created_at` ({@see self::timestampOf()}); cột lưu tới giây, nên "dòng SAU thời điểm `$at`" viết
     * `at > $at->getTimestamp()` — cùng kết quả với `created_at->gt($at)`, kể cả khi `$at` có phần lẻ giây.
     * `hasFrom` = `properties` có khoá `$fromKey`, kể cả khi giá trị của nó rỗng.
     *
     * @param  list<int>  $subjectIds
     * @return array<int, list<array{at: int, hasFrom: bool, from: mixed}>> subject_id => các dòng, cũ trước
     */
    public static function changes(string $event, string $subjectType, array $subjectIds, string $fromKey): array
    {
        if ($subjectIds === []) {
            return [];
        }

        $changes = [];

        $rows = Activity::query()
            ->toBase()
            ->select(['id', 'subject_id', 'properties', 'created_at'])
            ->where('event', $event)
            ->where('subject_type', $subjectType)
            ->whereIntegerInRaw('subject_id', array_values(array_unique($subjectIds)))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $properties = is_string($row->properties) ? json_decode($row->properties, true) : null;
            $properties = is_array($properties) ? $properties : [];

            $changes[(int) $row->subject_id][] = [
                'at' => self::timestampOf($row->created_at),
                'hasFrom' => array_key_exists($fromKey, $properties),
                'from' => $properties[$fromKey] ?? null,
            ];
        }

        return $changes;
    }

    /**
     * Dấu thời gian Unix của một `created_at` đọc thô — cùng giá trị mà cast ngày của Eloquent cho: chuỗi
     * `Y-m-d H:i:s` đọc theo múi giờ mặc định của PHP, tức `APP_TIMEZONE` (Laravel đặt khi khởi động).
     * `strtotime()` thay cho `Date::parse()` vì đây là vòng lặp trên hàng chục nghìn dòng; chuỗi lạ thì rơi
     * về `Date::parse()`.
     */
    private static function timestampOf(mixed $value): int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        $timestamp = is_string($value) ? strtotime($value) : false;

        return $timestamp !== false ? $timestamp : Date::parse((string) $value)->getTimestamp();
    }

    /**
     * Một id người đọc từ `properties` của một dòng lịch sử: số nguyên dương, hoặc chuỗi chữ số của nó.
     * Còn lại (rỗng, chữ, 0, số âm) là `null` — không đoán. MỘT định nghĩa cho cả ba bộ dựng lịch sử
     * ({@see DeadlineHolderAtDue}, {@see RequestHolderAt} gọi lại hàm này).
     */
    public static function userIdIn(mixed $value): ?int
    {
        $id = match (true) {
            is_int($value) => $value,
            is_string($value) && ctype_digit($value) => (int) $value,
            default => 0,
        };

        return $id > 0 ? $id : null;
    }
}
