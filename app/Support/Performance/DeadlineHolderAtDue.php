<?php

namespace App\Support\Performance;

use App\Actions\Deadline\ChangeDeadlineResponsible;
use App\Actions\Deadline\SetDeadlineCompletion;
use App\Actions\Deadline\UpdateDeadline;
use App\Actions\Matter\ReassignMatter;
use App\Models\Deadline;
use Illuminate\Support\Collection;

/**
 * Người giữ mỗi mốc VÀO NGÀY ĐẾN HẠN (M13, R9) — người mà cột P1 ("Mốc đúng hạn") tính mốc cho, để
 * người nhận bàn giao không gánh mốc người trước đã lỡ. Không phải `responsible_user_id` hiện tại.
 *
 * # Luật
 *
 * Với mỗi mốc, tìm dòng `deadline_responsible_changed` SỚM NHẤT có `created_at` SAU hết ngày đến hạn
 * ({@see Deadline::dueEnd()}, 23:59:59 của `due_date` theo `APP_TIMEZONE` — cùng biên với "xong đúng
 * hạn" của {@see Deadline::outcomeAt()}). Có thì người giữ vào ngày đến hạn là `properties.from` của
 * dòng ấy; không có thì là `responsible_user_id` hiện tại (cột `NOT NULL`, nên nhánh này luôn có
 * người). So "sau" CHẶT: lần đổi lúc 23:59:59 ngày đến hạn là "trước", người nhận giữ mốc vào ngày đó;
 * lần đổi lúc 00:00:01 hôm sau là "sau". Cùng hình dạng với {@see LeadAt} và {@see RequestHolderAt}.
 *
 * # MỘT khoá sự kiện, mọi đường ghi
 *
 * Từ M13 mọi đường đổi `responsible_user_id` ghi đúng khoá này: {@see ChangeDeadlineResponsible}, lần
 * mở lại có chuyển người của {@see SetDeadlineCompletion}, {@see UpdateDeadline} khi người phụ trách thật
 * sự đổi, và {@see ReassignMatter} bước 3 (một dòng cho MỖI mốc). `HolderHistoryCompletenessTest` canh
 * rằng một đường ghi mới không quên dòng đó.
 *
 * **Giới hạn đã biết:** lần bàn giao vụ TRƯỚC ngày triển khai M13 không có dòng cho từng mốc (bước 3 khi
 * đó chỉ để lại SỐ LƯỢNG trên dòng `matter_reassigned`). Những mốc đó rơi về người giữ hiện tại.
 *
 * # Dữ liệu hỏng: không đoán
 *
 * `properties.from` của dòng tìm được rỗng hoặc không phải một id ({@see LeadAt::userIdIn()}): trả `null`.
 * Mốc đó không quy về ai, chỉ vào dòng "Chung" (R5, R8). Phân loại đúng hạn/trễ/lỡ của mốc không đổi —
 * đó là việc của {@see Deadline::outcomeAt()} (ca 9 của bảng ca biên P1).
 *
 * # Một truy vấn cho cả lô (R11)
 *
 * Mọi dòng của các mốc trong lô, qua {@see LeadAt::changes()} (index morph `subject` của `activity_log`,
 * đọc thô); so với hết ngày đến hạn của từng mốc bằng PHP. Người gọi nạp tập mốc của kỳ KHÔNG lọc người
 * (R11): lọc theo `responsible_user_id` hiện tại sẽ làm rơi đúng mốc đã bị chuyển đi sau khi lỡ.
 *
 * Lớp này có tên trong danh sách ngoại lệ của `NoSecondDefinitionTest`: được viết điều kiện trên `event`,
 * `subject_type`, `subject_id`, `created_at` của `activity_log` (R9). Truy vấn nay nằm ở {@see LeadAt::changes()}.
 */
final class DeadlineHolderAtDue
{
    /** Khoá sự kiện DUY NHẤT mang lịch sử người giữ mốc. */
    public const EVENT = 'deadline_responsible_changed';

    /**
     * @param  Collection<int, Deadline>  $deadlines
     * @return array<int, ?int> deadline_id => user_id; `null` = không quy được về ai (R9)
     */
    public static function resolve(Collection $deadlines): array
    {
        if ($deadlines->isEmpty()) {
            return [];
        }

        $changes = LeadAt::changes(
            self::EVENT,
            (new Deadline)->getMorphClass(),
            $deadlines->map(fn (Deadline $deadline): int => (int) $deadline->getKey())->all(),
            'from',
        );

        return $deadlines
            ->mapWithKeys(fn (Deadline $deadline): array => [
                $deadline->getKey() => self::holderOf($deadline, $changes[(int) $deadline->getKey()] ?? []),
            ])
            ->all();
    }

    /** @param  list<array{at: int, hasFrom: bool, from: mixed}>  $changes  cũ trước, `at` là dấu thời gian Unix */
    private static function holderOf(Deadline $deadline, array $changes): ?int
    {
        $dueEnd = $deadline->dueEnd()->getTimestamp();

        foreach ($changes as $change) {
            if ($change['at'] > $dueEnd) {
                return LeadAt::userIdIn($change['from']);
            }
        }

        return LeadAt::userIdIn($deadline->responsible_user_id);
    }
}
