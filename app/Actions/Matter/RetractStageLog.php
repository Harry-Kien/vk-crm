<?php

namespace App\Actions\Matter;

use App\Actions\Concerns\ChecksAccountActive;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Exceptions\StageLogNotRetractable;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Rút một dòng tiến độ đã công bố khỏi cổng khách (làn fm, mục A2 của kiểm tra nghiệp vụ
 * 2026-10-09). Ca điển hình: luật sư mở hai tab và đăng cập nhật của khách A lên vụ của khách B, bật
 * "Công bố". Trước Action này cách duy nhất là tắt công bố CẢ vụ, và bật lại thì dòng sai hiện ra lại.
 *
 * **Không xoá, không sửa nội dung.** `stage_logs` chỉ ghi thêm (SPEC §4.8, `StageLogImmutable`): Action
 * chỉ đặt `is_published = false` và ba cột dấu vết `retracted_at`/`retracted_by`/`retraction_reason`
 * (đều thuộc `StageLog::MUTABLE`). `published_at`, `notified_at` và biên bản khách đã xem
 * (`stage_log_views`) giữ nguyên — chúng là sự thật đã xảy ra. Vì mọi chỗ đưa dòng tiến độ tới khách
 * đều đọc `is_published` — cổng (`StageLog::applyClientPortalConstraints`), thư
 * (`NotifyClientOfStageUpdate` đọc lại lúc gửi), mục lục gói bàn giao (`RenderHandoverIndex`) — tắt
 * cờ đó là đủ để dòng rời cả ba, không cần luật thứ hai. Không có đường công bố lại một dòng đã rút:
 * muốn khách đọc nội dung đúng thì đăng một cập nhật mới.
 *
 * Lý do rút là chữ NỘI BỘ: dòng biến hẳn khỏi cổng, không để lại dòng "đã rút" nào cho khách (khác tài
 * liệu), vì ca rút điển hình là thông tin của khách khác — một dấu vết trên cổng chỉ gợi thêm câu hỏi.
 *
 * Thứ tự (quy ước khoá toàn dự án): câu đầu tiên trong transaction khoá dòng `matters`, sau đó mới khoá
 * dòng `stage_logs`; cổng là `StageLogPolicy::publish` (người được công bố mới được rút), hỏi trên bản
 * đã khoá; tài khoản bị vô hiệu thì từ chối như không có quyền. Thư đã gửi thì không thu hồi được —
 * hộp thoại nói rõ điều đó.
 */
class RetractStageLog
{
    use ChecksAccountActive;
    use ReadsWithoutPortalScope;

    /** Lý do tối thiểu, cùng ngưỡng `RetractDocument::REASON_MIN`. */
    public const REASON_MIN = 20;

    /** Trần chủ động cho cột `text`, cùng con số `RetractDocument::REASON_MAX`. */
    public const REASON_MAX = 5000;

    /**
     * @throws AuthorizationException
     * @throws StageLogNotRetractable
     * @throws ValidationException
     */
    public function handle(StageLog $stageLog, User $actor, string $reason): StageLog
    {
        $matterId = $stageLog->matter_id;

        return DB::transaction(function () use ($stageLog, $actor, $reason, $matterId): StageLog {
            // Câu ĐẦU TIÊN: khoá dòng `matters` (vụ đã huỷ thì không còn gì để rút trên cổng).
            $matter = $this->scopelessly(Matter::query())->lockForUpdate()->find($matterId);

            $fresh = $matter === null ? null : $this->scopelessly(StageLog::query())
                ->where('matter_id', $matter->getKey())
                ->lockForUpdate()
                ->find($stageLog->getKey());

            if ($fresh === null) {
                throw StageLogNotRetractable::missing();
            }

            $fresh->setRelation('matter', $matter);

            if (! $this->accountIsActive($actor)) {
                throw new AuthorizationException;
            }

            Gate::forUser($actor)->authorize('publish', $fresh);

            if ($fresh->isRetracted()) {
                throw StageLogNotRetractable::alreadyRetracted();
            }

            if (! $fresh->is_published) {
                throw StageLogNotRetractable::notPublished();
            }

            $reason = $this->validatedReason($reason);

            $fresh->forceFill([
                'is_published' => false,
                'retracted_at' => now(),
                'retracted_by' => $actor->getKey(),
                'retraction_reason' => $reason,
            ]);
            $fresh->blameOn($actor)->save();

            // Không chép nội dung công bố hay lý do vào nhật ký (lý do đã nằm trên dòng, có thể dài
            // và mang thông tin của khách khác); chỉ id, để trang nhật ký lọc theo quyền xem vụ.
            Audit::record('stage_log_retracted', $fresh, [
                'matter_id' => $matter->getKey(),
                'client_id' => $matter->client_id,
                'stage_log_id' => $fresh->getKey(),
                'was_notified' => $fresh->notified_at !== null,
            ], $actor);

            return $fresh;
        });
    }

    /** Gỡ khoảng trắng Unicode hai đầu rồi đếm ký tự — cùng cách `RetractDocument`. */
    private function validatedReason(string $reason): string
    {
        $trimmed = preg_replace('/^[\s\p{Z}\x{200B}]+|[\s\p{Z}\x{200B}]+$/u', '', $reason) ?? '';
        $length = mb_strlen($trimmed);

        if ($length < self::REASON_MIN) {
            throw ValidationException::withMessages([
                'retraction_reason' => [__('lifecycle.stage_log.reason_min', ['min' => self::REASON_MIN])],
            ]);
        }

        if ($length > self::REASON_MAX) {
            throw ValidationException::withMessages([
                'retraction_reason' => [__('lifecycle.stage_log.reason_max', ['max' => self::REASON_MAX])],
            ]);
        }

        return $trimmed;
    }
}
