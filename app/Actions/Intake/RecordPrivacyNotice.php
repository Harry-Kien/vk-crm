<?php

namespace App\Actions\Intake;

use App\Models\IntakeRequest;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Ghi nhận người liên hệ đã nghe câu thông báo xử lý dữ liệu cá nhân và đồng ý (M10 R7a; Nghị định
 * 356/2025/NĐ-CP: đồng ý phải lưu lại và kiểm chứng được, cấm đánh dấu sẵn). Lưu PHIÊN BẢN của câu
 * thông báo (`lang/vi/intake.php`, `privacy_notice.version`), thời điểm, và người ghi nhận.
 *
 * **`$heardAndAgreed` phải là `true` do người nhập chủ động chọn**: `false` bị từ chối (không có "ghi
 * nhận việc chưa đồng ý"), để không ai gọi Action này theo đường mặc định. Ô câu chuyện chỉ mở khi đã
 * có ghi nhận này ({@see IntakeSummaryGate}, blocker `PrivacyNotice`) — thêm vào cổng của R1.
 *
 * Ghi nhận lại cùng phiên bản thì không làm gì (không nhân đôi dòng nhật ký, giữ nguyên thời điểm
 * đầu); phiên bản đã đổi thì ghi nhận lại. Phần DANH TÍNH được lưu dù chưa có ghi nhận này để chạy
 * kiểm tra xung đột — căn cứ pháp lý của việc đó là mục CẦN LUẬT SƯ XÁC NHẬN (R7a), không phải một
 * quyết định của Action. Dòng `intake_privacy_notice_recorded` chỉ mang phiên bản.
 *
 * Quyền: người nhìn thấy được bản ghi (`IntakeRequestPolicy::update`); bản đã ẩn danh hoặc đã gộp
 * bị từ chối.
 */
class RecordPrivacyNotice
{
    public function handle(User $actor, IntakeRequest $intake, bool $heardAndAgreed): IntakeRequest
    {
        Gate::forUser($actor)->authorize('update', $intake);

        if (! $heardAndAgreed) {
            throw ValidationException::withMessages(['privacy_notice' => [__('intake.errors.privacy_notice_not_agreed')]]);
        }

        $version = (string) __('intake.privacy_notice.version');

        return DB::transaction(function () use ($actor, $intake, $version): IntakeRequest {
            $locked = IntakeRequest::query()->whereKey($intake->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isClosedToWrites()) {
                throw ValidationException::withMessages(['intake' => [__('intake.errors.record_closed')]]);
            }

            if ($locked->privacy_notice_acknowledged_at !== null && $locked->privacy_notice_version === $version) {
                return $locked;
            }

            $locked->fill([
                'privacy_notice_version' => $version,
                'privacy_notice_acknowledged_at' => now(),
                'privacy_notice_recorded_by' => $actor->getKey(),
            ])->blameOn($actor)->save();

            Audit::record('intake_privacy_notice_recorded', $locked, ['version' => $version], $actor);

            return $locked;
        });
    }
}
