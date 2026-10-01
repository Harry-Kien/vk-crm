<?php

namespace App\Actions\Intake\Concerns;

use App\Actions\Intake\CheckIntakeConflict;
use App\Exceptions\ConflictCheckBusy;
use App\Models\IntakeRequest;
use App\Models\User;
use App\Support\ConflictCheckResult;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Khoá ứng dụng `conflict-check` — CÙNG khoá, cùng tham số với `OpenMatter`, `AddMatterParty`,
 * `UpdateMatterParty` và `RemoveMatterParty` (M6.5 R13g): hai người nhận hai cuộc gọi đối nhau cùng
 * lúc phải được tuần tự hoá, để lần thứ hai luôn thấy lần thứ nhất đã commit.
 *
 * **Không tái nhập.** Khoá cache `database` không tái nhập: một Action đang giữ khoá mà gọi một Action
 * khác cũng lấy khoá này sẽ tự chờ 10 giây rồi ra `ConflictCheckBusy`. Vì vậy chỉ các Action
 * "cửa ngoài" (`RecordIntake`, `RerunIntakeConflictCheck`, `AcknowledgeIntakeConflict`,
 * `ResolveIntakeRedConflict`) dùng trait này; `CheckIntakeConflict` KHÔNG lấy khoá và đòi người gọi
 * đang giữ nó.
 *
 * Quá hạn chờ thì `ConflictCheckBusy` (một `DomainException` tiếng Việt), không bao giờ một lỗi 500.
 */
trait HoldsConflictCheckLock
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function underConflictCheckLock(Closure $callback): mixed
    {
        try {
            return Cache::store('database')->lock('conflict-check', 30)->block(10, $callback);
        } catch (LockTimeoutException) {
            throw ConflictCheckBusy::make();
        }
    }

    /**
     * Chạy kiểm tra và ghi kết quả trong MỘT transaction RIÊNG, commit ngay — NGƯỜI GỌI PHẢI ĐANG GIỮ
     * khoá `conflict-check`. Riêng và commit trước để bằng chứng "đã kiểm tra" (dòng `conflict_check_run`
     * và `conflict_result`) không mất khi bước sau (xác nhận, ghi đè) bị từ chối. Câu đầu tiên của
     * transaction là một lần đọc có khoá dòng bản ghi (luật dự án); bản đã ẩn danh hoặc đã gộp bị từ chối.
     */
    protected function checkAndRecord(User $actor, IntakeRequest $intake): ConflictCheckResult
    {
        return DB::transaction(function () use ($actor, $intake): ConflictCheckResult {
            $locked = IntakeRequest::query()->whereKey($intake->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isClosedToWrites()) {
                throw ValidationException::withMessages(['intake' => [__('intake.errors.record_closed')]]);
            }

            return app(CheckIntakeConflict::class)->handle($actor, $locked);
        });
    }
}
