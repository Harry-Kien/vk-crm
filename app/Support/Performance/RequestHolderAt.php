<?php

namespace App\Support\Performance;

use App\Actions\Matter\ReassignMatter;
use App\Actions\Portal\TriageClientRequest;
use App\Models\ClientRequest;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Người giữ mỗi luồng yêu cầu của khách TẠI một thời điểm (M13, R18) — người mà P3, P9, P10 tính luồng
 * cho: lúc văn phòng trả lời lần đầu (`answered_at`), hoặc lúc hết kỳ nếu chưa trả lời. Không phải
 * `COALESCE(assigned_to, lead_lawyer_id)` HIỆN TẠI: gần như mọi luồng có `assigned_to = NULL`, nên cách
 * đọc "hiện tại" chuyển MỌI luồng của một vụ, kể cả luồng đã trả lời, sang người nhận mỗi lần bàn giao,
 * và viết lại các tháng đã qua của người trước. Đừng "đơn giản hoá" lớp này về biểu thức đó.
 *
 * # Luật
 *
 * 1. Người được giao tại `$at`: `properties.from` của dòng `client_request_assigned` SỚM NHẤT có
 *    `created_at` SAU `$at`; không có dòng nào thì `assigned_to` hiện tại. So "sau" CHẶT: dòng ghi đúng
 *    giây `$at` coi như đã có hiệu lực — cùng hình dạng với {@see LeadAt} và {@see DeadlineHolderAtDue}.
 * 2. Người được giao rỗng (`from = null`: lúc đó chưa giao ai) thì người giữ là luật sư phụ trách vụ
 *    tại CÙNG thời điểm, qua {@see LeadAt}.
 *
 * Ba đường ghi khoá `client_request_assigned`: {@see TriageClientRequest::assign()} (giao, đổi, gỡ),
 * lần mở lại có gỡ người của {@see TriageClientRequest::setStatus()} (`to = null`), và
 * {@see ReassignMatter} bước 4 (một dòng cho MỖI luồng giao đích danh cho luật sư cũ). Cùng một khoá,
 * nên "ai từng giữ luồng này" đọc ở MỘT chỗ. `HolderHistoryCompletenessTest` canh đường ghi mới.
 *
 * **Giới hạn đã biết:** luồng được giao ĐÍCH DANH cho luật sư cũ rồi bị `ReassignMatter` chuyển TRƯỚC
 * ngày triển khai M13 không có dòng riêng; với thời điểm trước lần chuyển đó, người được giao rơi về
 * `assigned_to` hiện tại. Luồng chưa giao ai (gần như mọi luồng) không bị giới hạn này: lịch sử người
 * phụ trách vụ (`matter_reassigned`) có từ M6.5.
 *
 * # Người đã xoá mềm VẪN là người giữ (lịch sử, không phải người nhận thông báo)
 *
 * Khác {@see ClientRequest::holderId()} ("bây giờ", N9), nơi người được giao đã xoá mềm nhường cho luật
 * sư phụ trách như đường thông báo `ReplyToClientRequest::notifyHolderOfFollowUp()`. Người đã xoá mềm
 * không có dòng trên trang (R3), nên việc của họ chỉ hiện ở dòng "Chung". Hai cách đọc chỉ khác nhau khi
 * một người bị xoá mềm lúc còn giữ một luồng chưa đóng, điều `GuardsStaffOffboarding` chặn.
 *
 * # Dữ liệu hỏng: không đoán
 *
 * Dòng tìm được thiếu khoá `from`, hoặc `from` không rỗng mà không phải một id ({@see LeadAt::userIdIn()}):
 * luồng không quy về ai (`null`), không rơi về luật sư phụ trách — chỉ vào dòng "Chung" (R5, R8).
 *
 * # Hai truy vấn cho cả lô (R11)
 *
 * Dòng `client_request_assigned` của các luồng; dòng `matter_reassigned` của các vụ có luồng chưa giao ai
 * tại thời điểm hỏi (bỏ qua khi không có luồng nào như vậy). Không phụ thuộc số luồng. Quan hệ `matter`
 * nên nạp sẵn (`with('matter')`); thiếu thì lớp này nạp MỘT lần cho cả lô (`loadMissing`). Luồng của vụ
 * đã huỷ (quan hệ `matter` rỗng) mà chưa giao ai thì không quy về ai.
 *
 * Cả hai truy vấn đọc qua {@see LeadAt::changes()} (đọc thô, không dựng model `Activity` — số đo Task 8).
 *
 * Lớp này có tên trong danh sách ngoại lệ của `NoSecondDefinitionTest`: được viết điều kiện trên `event`,
 * `subject_type`, `subject_id`, `created_at` của `activity_log` (R18). Truy vấn nay nằm ở {@see LeadAt::changes()}.
 */
final class RequestHolderAt
{
    /** Khoá sự kiện DUY NHẤT mang lịch sử người được giao một luồng. */
    public const EVENT = 'client_request_assigned';

    /**
     * @param  Collection<int, ClientRequest>  $requests  (quan hệ `matter` nên nạp sẵn)
     * @param  Closure(ClientRequest): CarbonInterface  $at
     * @return array<int, ?int> request_id => user_id; người được giao tại `$at`, rỗng thì {@see LeadAt}
     *                          tại `$at`; `null` = không quy được về ai
     */
    public static function resolve(Collection $requests, Closure $at): array
    {
        if ($requests->isEmpty()) {
            return [];
        }

        (new EloquentCollection($requests->all()))->loadMissing('matter');

        $changes = LeadAt::changes(
            self::EVENT,
            (new ClientRequest)->getMorphClass(),
            $requests->map(fn (ClientRequest $request): int => (int) $request->getKey())->all(),
            'from',
        );

        $moments = [];
        $assignees = [];

        foreach ($requests as $request) {
            $id = (int) $request->getKey();
            $moments[$id] = $at($request);
            $assignees[$id] = self::assigneeAt($request, $changes[$id] ?? [], $moments[$id]);
        }

        $unassigned = $requests->filter(fn (ClientRequest $request): bool => $assignees[(int) $request->getKey()] === null);

        $leads = LeadAt::historyOf($unassigned
            ->map(fn (ClientRequest $request): int => (int) $request->matter_id)
            ->unique()->values()->all());

        return $requests
            ->mapWithKeys(function (ClientRequest $request) use ($assignees, $moments, $leads): array {
                $id = (int) $request->getKey();
                $assignee = $assignees[$id];

                return [$request->getKey() => match (true) {
                    $assignee === false => null,
                    $assignee !== null => $assignee,
                    $request->matter === null => null,
                    default => $leads->leadOf($request->matter, $moments[$id]),
                }];
            })
            ->all();
    }

    /**
     * Người được giao `$request` tại `$at`: một id; `null` = lúc đó chưa giao ai (rơi về {@see LeadAt});
     * `false` = dòng lịch sử hỏng, không quy về ai.
     *
     * @param  list<array{at: int, hasFrom: bool, from: mixed}>  $changes  cũ trước, `at` là dấu thời gian Unix
     */
    private static function assigneeAt(ClientRequest $request, array $changes, CarbonInterface $at): int|false|null
    {
        $moment = $at->getTimestamp();

        foreach ($changes as $change) {
            if ($change['at'] > $moment) {
                return $change['hasFrom'] ? self::assigneeIn($change['from']) : false;
            }
        }

        return self::assigneeIn($request->assigned_to);
    }

    /** `null` giữ nguyên nghĩa "chưa giao ai"; một giá trị khác phải là một id, không thì `false`. */
    private static function assigneeIn(mixed $value): int|false|null
    {
        if ($value === null) {
            return null;
        }

        return LeadAt::userIdIn($value) ?? false;
    }
}
