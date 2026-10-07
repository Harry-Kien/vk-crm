<?php

namespace App\Actions\Intake;

use App\Models\IntakeRequest;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Ghi Ô CÂU CHUYỆN (`summary`) của một lần tiếp nhận (M10 R1, R7a) — đường DUY NHẤT ghi cột này.
 * Cổng thật nằm ở đây, không ở màn hình: một request Livewire bị chỉnh sửa gửi thẳng `summary` cũng
 * đi qua hàm này và bị chặn như nhau (Task 3 có test đó ở lớp màn hình).
 *
 * Ô chỉ mở khi {@see IntakeSummaryGate} không còn điều gì chặn — đã ghi nhận thông báo (R7a), đã kiểm
 * tra xung đột cho danh tính hiện tại, và theo mức: Đỏ phải được quản lý/admin ghi đè, Vàng hoặc "thiếu
 * định danh" phải được xác nhận. Đỏ chưa xử lý thì KHÔNG lưu câu chuyện nào, kể cả khi người nhập cố
 * gửi. Thông báo lỗi nói điều đang chặn (nhãn `IntakeSummaryBlocker`), không nói vì sao Đỏ.
 *
 * Cổng được đọc từ dòng bản ghi vừa KHOÁ (`lockForUpdate`, câu đầu tiên của transaction), nên một lần
 * ghi đè/xác nhận/kiểm tra lại chen vào giữa được tính đúng — không đọc một bản đã cũ trong bộ nhớ.
 *
 * Quyền: người nhìn thấy được bản ghi (`IntakeRequestPolicy::update` — người ghi hoặc được giao,
 * hoặc `intake.viewAny`, và không phải vụ `restricted` họ không xem được). Bản đã xong việc (đã chuyển
 * thành vụ việc, đã ẩn danh hoặc đã gộp — `IntakeRequest::isClosedToChanges()`, đọc trên dòng vừa khoá:
 * một lần lưu đã qua bước hiện nút trước khi tab khác chuyển đổi xong cũng bị chặn)
 * bị từ chối. Rỗng hoặc chỉ khoảng trắng nghĩa là xoá câu chuyện (đặt null) — vẫn qua cổng. Dòng
 * `intake_summary_updated` chỉ mang số ký tự: nội dung câu chuyện không bao giờ vào nhật ký.
 */
class UpdateIntakeSummary
{
    /** Cột `text` của MariaDB chứa tối đa 65.535 BYTE; chừa chỗ cho ký tự nhiều byte. */
    private const MAX_BYTES = 60000;

    public function handle(User $actor, IntakeRequest $intake, ?string $summary): IntakeRequest
    {
        Gate::forUser($actor)->authorize('update', $intake);

        $summary = $summary === null ? null : trim($summary);
        $summary = $summary === '' ? null : $summary;

        if ($summary !== null && strlen($summary) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['summary' => [__('intake.errors.summary_too_long')]]);
        }

        return DB::transaction(function () use ($actor, $intake, $summary): IntakeRequest {
            $locked = IntakeRequest::query()->whereKey($intake->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isClosedToChanges()) {
                throw ValidationException::withMessages(['summary' => [__('intake.errors.record_closed')]]);
            }

            $blockers = IntakeSummaryGate::blockers($locked);

            if ($blockers !== []) {
                throw ValidationException::withMessages(['summary' => [__('intake.errors.summary_locked', [
                    'blockers' => collect($blockers)->map(fn ($blocker): string => $blocker->label())->implode('; '),
                ])]]);
            }

            $locked->fill(['summary' => $summary])->blameOn($actor)->save();

            Audit::record('intake_summary_updated', $locked, [
                'length' => $summary === null ? 0 : mb_strlen($summary),
            ], $actor);

            return $locked;
        });
    }
}
