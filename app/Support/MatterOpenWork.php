<?php

namespace App\Support;

use App\Enums\ChecklistItemStatus;
use App\Enums\ClientRequestStatus;
use App\Enums\Permission;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Matter;
use App\Models\User;
use App\Support\Billing\BillingSummary;
use App\Support\Billing\Money;
use App\Support\Scopes\ClientPortalScope;

/**
 * Những việc CÒN DỞ trên một vụ việc, đọc ra để hiện trên hai hộp thoại "khó quay lại" của trang vụ
 * (làn fm, sửa sau kiểm tra nghiệp vụ 2026-10-09): chuyển sang một giai đoạn kết thúc
 * (`TransitionStageAction`) và "Huỷ hồ sơ mở nhầm" (`EditMatter`). Chỉ ĐỌC, không quyết định gì:
 * Action nghiệp vụ không hỏi lớp này, người dùng đọc rồi tự tích xác nhận.
 *
 * Mỗi dòng là một câu tiếng Việt đã dịch (`lang/vi/lifecycle.php`), mỗi loại việc chỉ có mặt khi
 * số đếm khác không. Dư nợ chỉ hiện cho người có `billing.view` — một luật sư không có quyền xem
 * tiền không được biết con số qua đường vòng này (SPEC §5). Người gọi đã mở được trang vụ (đã qua
 * `MatterPolicy::view`), nên tên mốc thời hạn của chính vụ đó không lộ gì thêm.
 */
final class MatterOpenWork
{
    /** Liệt kê tên tối đa từng ấy mốc; số còn lại gộp vào một câu "và n mốc khác". */
    public const LISTED_DEADLINES = 5;

    /**
     * Các dòng việc còn dở khi ĐÓNG vụ: mốc chưa xong (kèm tên và ngày), yêu cầu của khách chưa
     * đóng, đầu mục danh mục hồ sơ chờ duyệt, dư nợ.
     *
     * @return list<string>
     */
    public static function closingLines(Matter $matter, ?User $viewer): array
    {
        $lines = self::deadlineLines($matter);

        $openRequests = $matter->clientRequests()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->where('status', '!=', ClientRequestStatus::Closed->value)
            ->count();

        if ($openRequests > 0) {
            $lines[] = __('lifecycle.open_work.client_requests', ['count' => $openRequests]);
        }

        $pendingReview = $matter->checklistItems()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->where('status', ChecklistItemStatus::PendingReview->value)
            ->count();

        if ($pendingReview > 0) {
            $lines[] = __('lifecycle.open_work.checklist_pending', ['count' => $pendingReview]);
        }

        $balance = self::balanceLine($matter, $viewer);

        if ($balance !== null) {
            $lines[] = $balance;
        }

        return $lines;
    }

    /**
     * Các dòng cho hộp thoại "Huỷ hồ sơ mở nhầm": mốc chưa xong, số tài liệu, và việc vụ đang được
     * công bố trên cổng khách.
     *
     * @return list<string>
     */
    public static function cancellingLines(Matter $matter): array
    {
        $lines = self::deadlineLines($matter);

        $documents = Document::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->where('matter_id', $matter->getKey())
            ->count();

        if ($documents > 0) {
            $lines[] = __('lifecycle.open_work.documents', ['count' => $documents]);
        }

        if ($matter->is_published_to_portal) {
            $lines[] = __('lifecycle.open_work.on_portal');
        }

        return $lines;
    }

    /** @return list<string> */
    private static function deadlineLines(Matter $matter): array
    {
        $open = Deadline::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->where('matter_id', $matter->getKey())
            ->where('is_completed', false)
            ->orderBy('due_date')
            ->get(['id', 'name', 'due_date']);

        $lines = $open->take(self::LISTED_DEADLINES)
            ->map(fn (Deadline $deadline): string => __('lifecycle.open_work.deadline', [
                'name' => $deadline->name,
                'date' => $deadline->due_date->format('d/m/Y'),
            ]))
            ->values()
            ->all();

        if ($open->count() > self::LISTED_DEADLINES) {
            $lines[] = __('lifecycle.open_work.more_deadlines', ['count' => $open->count() - self::LISTED_DEADLINES]);
        }

        return $lines;
    }

    private static function balanceLine(Matter $matter, ?User $viewer): ?string
    {
        if ($viewer === null || ! $viewer->can(Permission::BillingView->value)) {
            return null;
        }

        $balance = BillingSummary::outstandingForMatter((int) $matter->getKey());

        if ($balance['amount'] <= 0) {
            return null;
        }

        return __('lifecycle.open_work.balance', ['amount' => Money::format($balance['amount'])]);
    }
}
