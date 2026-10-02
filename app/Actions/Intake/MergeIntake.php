<?php

namespace App\Actions\Intake;

use App\Actions\Intake\Concerns\HoldsConflictCheckLock;
use App\Enums\IntakeStatus;
use App\Models\IntakeParty;
use App\Models\IntakeRequest;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Gộp một lần tiếp nhận TRÙNG (`$source`) vào một lần khác (`$target`) — M10 R4: "một người gọi ba
 * lần trong một tuần không được thành ba bản ghi không liên quan". Bản nguồn nhận `status = merged` và
 * `merged_into_id`; từ đó nó rời nguồn dò thứ hai của `RunConflictCheck` (`openForConflictCheck()`)
 * và thành chỉ đọc.
 *
 * **Gộp không được là đường rửa Đỏ thứ hai** (phán quyết controller khi chốt fix vòng 1 của Task 2).
 * Vì bản đã gộp không còn là "lần gọi trước" của ai, hai thứ của nó phải SANG bản đích, nếu không
 * chúng biến mất khỏi kiểm tra xung đột:
 *  1. **Các bên đối lập** của bản nguồn chuyển sang bản đích (đổi `intake_request_id`; một bên mà bản
 *     đích đã có — cùng vai, tên chuẩn hoá, SĐT chuẩn hoá và dấu băm — thì bỏ, không nhân đôi). Áp cho
 *     MỌI lần gộp, không chỉ bản Đỏ: những gì văn phòng đã nghe về những người đó vẫn là dữ kiện cho
 *     kiểm tra về sau (R1).
 *  2. **Dấu Đỏ đang chờ.** Bản nguồn còn Đỏ chưa xử lý hoặc đã bị từ chối vì xung đột
 *     ({@see IntakeRequest::locksRepeatCalls()}) thì bản đích nhận `conflict_red_pending_since`, giữ
 *     thời điểm SỚM HƠN của hai bản. Chỉ quản lý/admin xử lý được nó (`ResolveIntakeRedConflict`) —
 *     nên một trợ lý gộp một bản Đỏ vào bản Xanh của cùng người vẫn không ghi được câu chuyện ở bản
 *     đích.
 * Sau đó bản đích được KIỂM TRA LẠI ngay (`CheckIntakeConflict`, trong cùng khoá và transaction): danh
 * sách bên đối lập của nó vừa đổi, và một bên mang sang có thể khớp một khách hiện hữu (Đỏ thật).
 *
 * **Bản người liên hệ của nguồn không sang.** Gộp là khẳng định "cùng một người"; nếu người liên hệ
 * của bản nguồn có SĐT/CCCD khác bản đích, định danh đó rời nguồn dò thứ hai cùng bản nguồn. Câu
 * chuyện (`summary`) của bản nguồn ở lại bản nguồn (vẫn đọc được trên trang của nó).
 *
 * Quyền: sửa được CẢ HAI bản (`IntakeRequestPolicy::update`) — người không có `intake.viewAny` chỉ
 * gộp giữa các bản của mình/được giao. Từ chối: gộp vào chính nó; bản nguồn hoặc bản đích đã xong
 * việc ({@see IntakeRequest::isClosedToChanges()}: đã gộp, đã ẩn danh, đã chuyển thành vụ); bản đích
 * sẽ có hơn {@see IntakeRequest::MAX_OPPOSING_PARTIES} bên đối lập sau khi gộp (đếm sau khi bỏ bên
 * trùng — cùng trần với form, để bản đích còn sửa được). Một bản đã từ chối vẫn gộp đi hoặc gộp vào
 * được (nó vẫn là một lần liên hệ thật).
 *
 * Chạy dưới khoá `conflict-check` (nó đổi đầu vào của kiểm tra xung đột). Câu đầu tiên của transaction
 * khoá CẢ HAI dòng, theo thứ tự id tăng dần (hai lần gộp ngược chiều nhau không khoá chéo). Nhật ký:
 * `intake_merged` trên bản nguồn (mã bản đích, số bên đã chuyển) và `intake_merge_received` trên bản
 * đích (mã bản nguồn, số bên) — không tên, không SĐT. Bản ghi tự động của model tắt cho hai lần lưu.
 */
class MergeIntake
{
    use HoldsConflictCheckLock;

    public function handle(User $actor, IntakeRequest $source, IntakeRequest $target): IntakeRequest
    {
        Gate::forUser($actor)->authorize('update', $source);
        Gate::forUser($actor)->authorize('update', $target);

        if ($source->is($target)) {
            throw ValidationException::withMessages(['merge_target' => [__('intake.errors.merge_into_itself')]]);
        }

        return $this->underConflictCheckLock(fn (): IntakeRequest => DB::transaction(function () use ($actor, $source, $target): IntakeRequest {
            $rows = IntakeRequest::query()
                ->whereKey([$source->getKey(), $target->getKey()])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lockedSource = $rows->get($source->getKey());
            $lockedTarget = $rows->get($target->getKey());

            if ($lockedSource === null || $lockedSource->isClosedToChanges()) {
                throw ValidationException::withMessages(['merge_target' => [__('intake.errors.record_final')]]);
            }

            if ($lockedTarget === null || $lockedTarget->isClosedToChanges()) {
                throw ValidationException::withMessages(['merge_target' => [__('intake.errors.merge_target_closed')]]);
            }

            $moved = $this->moveParties($lockedSource, $lockedTarget);

            // Trần số bên đối lập của một bản ghi: vượt thì từ chối, và transaction hoàn lại các dòng
            // vừa chuyển — không gì đổi, kể cả kiểm tra của bản đích (chưa chạy tới).
            if ($lockedTarget->parties()->count() > IntakeRequest::MAX_OPPOSING_PARTIES) {
                throw ValidationException::withMessages(['merge_target' => [__('intake.errors.merge_too_many_parties', [
                    'max' => IntakeRequest::MAX_OPPOSING_PARTIES,
                ])]]);
            }

            if ($lockedSource->locksRepeatCalls()) {
                $since = $lockedSource->conflict_red_pending_since ?? now();
                $current = $lockedTarget->conflict_red_pending_since;
                $lockedTarget->conflict_red_pending_since = ($current !== null && $current->lessThan($since)) ? $current : $since;
            }

            $lockedSource->fill(['status' => IntakeStatus::Merged, 'merged_into_id' => $lockedTarget->getKey()]);
            $lockedSource->blameOn($actor);
            $lockedSource->disableLogging()->save();
            $lockedSource->enableLogging();

            $lockedTarget->blameOn($actor);
            $lockedTarget->disableLogging()->save();
            $lockedTarget->enableLogging();

            app(CheckIntakeConflict::class)->handle($actor, $lockedTarget);

            Audit::record('intake_merged', $lockedSource, ['merged_into' => $lockedTarget->code, 'moved_parties' => $moved], $actor);
            Audit::record('intake_merge_received', $lockedTarget, ['merged_from' => $lockedSource->code, 'moved_parties' => $moved], $actor);

            return $lockedTarget;
        }));
    }

    /** Chuyển các bên đối lập sang bản đích, bỏ những bên bản đích đã có y hệt. Trả số dòng đã chuyển. */
    private function moveParties(IntakeRequest $source, IntakeRequest $target): int
    {
        $signature = fn (IntakeParty $party): string => implode('|', [
            $party->role?->value, $party->name_normalized, $party->phone_normalized, $party->id_number_hash,
        ]);

        $known = $target->parties()->get()->map($signature)->all();
        $moved = 0;

        foreach ($source->parties()->get() as $party) {
            if (in_array($signature($party), $known, true)) {
                $party->delete();

                continue;
            }

            $party->intake_request_id = $target->getKey();
            $party->save();
            $known[] = $signature($party);
            $moved++;
        }

        return $moved;
    }
}
