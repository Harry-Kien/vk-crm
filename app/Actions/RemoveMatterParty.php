<?php

namespace App\Actions;

use App\Actions\Matter\CancelMatter;
use App\Exceptions\ConflictCheckBusy;
use App\Exceptions\MatterPartyAlreadyRemoved;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Gỡ MỘT bên khỏi vụ việc (SPEC §4.16, §7.2 tab "Các bên"; M6.5 Task 9, brief R14).
 *
 * # Xoá mềm kèm lý do bắt buộc — và ý nghĩa khác hẳn "huỷ hồ sơ mở nhầm"
 *
 * R14 nói thẳng: "gỡ một bên là xoá mềm kèm lý do bắt buộc. Bên đã gỡ KHÔNG còn trong dữ liệu đối
 * chiếu xung đột, vì gỡ nghĩa là 'nhập nhầm, chưa từng là bên'." Đây là phán quyết NGƯỢC với
 * {@see CancelMatter} (huỷ một VỤ VIỆC mở nhầm) — đọc docblock lớp đó cho lý
 * lẽ đầy đủ: một vụ việc huỷ vẫn từng CÓ THẬT, các bên của nó từng có quan hệ THẬT với văn phòng,
 * nên vẫn phải nằm trong dữ liệu đối chiếu (false positive rẻ hơn false negative ở một công cụ đạo
 * đức nghề nghiệp). Một BÊN gõ nhầm thì khác trục hoàn toàn: nó CHƯA TỪNG là một dữ kiện thật —
 * người/hồ sơ đó chưa từng là một bên của vụ việc này — nên không có gì để mà đối chiếu cả.
 *
 * `RunConflictCheck` đã tự thi hành đúng phân biệt này TỪ TASK 8 (fix round 1, I1/R14):
 * `existingParties()` và `matchesFor()` đều KHÔNG `withTrashed()` ở `MatterParty` (chỉ còn giữ ở
 * quan hệ `matter` nạp kèm) — nghĩa là hàm này chỉ cần gọi `delete()`, không cần thêm bất kỳ mã lọc
 * nào: bên vừa xoá mềm tự động biến mất khỏi CẢ hai truy vấn đó ở lần kiểm tra kế tiếp, dù là kiểm
 * tra trên chính vụ việc này hay ở một vụ việc KHÁC (test đi kèm khoá cả hai trục).
 *
 * # Bên của CHÍNH khách hàng vụ việc KHÔNG gỡ được ở đây (brief)
 *
 * `OpenMatter::buildOwnClientParty()` tự dựng một bên `is_our_client` từ `matters.client_id` khi mở
 * vụ — bên đó không phải một dữ liệu nhập tay có thể "nhập nhầm", nó LÀ khách hàng của hồ sơ, và
 * "gỡ" nó không có nghĩa gì khác ngoài phá vỡ một bất biến ("mọi vụ việc có đúng một bên là khách
 * hàng của chính nó") mà không sửa được gì hữu ích — muốn đổi khách hàng của một vụ thì huỷ hồ sơ
 * mở nhầm ({@see CancelMatter}) và mở lại đúng, không gỡ một bên. Điều kiện
 * chặn CHỈ áp cho bên `is_our_client` trỏ đúng `matters.client_id` — một khách hàng KHÁC của văn
 * phòng đứng vai đồng nguyên đơn (`is_our_client = true` nhưng `client_id` khác `matters.client_id`)
 * vẫn gỡ được bình thường qua đây, vì đó không phải khách hàng CỦA HỒ SƠ.
 *
 * # Một khoá DUY NHẤT cho MỌI lần sửa `matter_parties` (fix round 1, C2 — phán quyết của chủ nhiệm)
 *
 * **Bản round 0 SAI: "hàm này KHÔNG đi qua `Cache::lock('conflict-check')`, vì xoá mềm không chạy
 * lại `RunConflictCheck`".** Chủ nhiệm đã lật lại phán quyết đó. `AddMatterParty`, `UpdateMatterParty`
 * và `OpenMatter` (bước lưu bên) đã khoá `Cache::store('database')->lock('conflict-check', 30)`
 * quanh giai đoạn kiểm tra+lưu của chúng — nhưng `RemoveMatterParty` KHÔNG khoá gì cả, nên nó có
 * thể xoá mềm một bên NGAY GIỮA giai đoạn kiểm tra của một trong ba Action kia: giai đoạn kiểm tra
 * đó đã ĐỌC `matter_parties` của vụ việc (qua `existingParties()`) để tính `$ourClientRoles`/tìm
 * bản ghi trùng, và nếu `RemoveMatterParty` xen vào ngay sau lần đọc đó, kết quả kiểm tra vừa tính
 * xong đã dựa trên một tập hợp bên LỖI THỜI — đúng cửa sổ đua tranh mà khoá `conflict-check` (R13g)
 * tồn tại để chặn, chỉ là chưa từng phủ tới Action này. Hàm này giờ khoá y hệt ba Action kia — CÙNG
 * tên khoá, CÙNG TTL (30 giây), CÙNG thời gian chờ (10 giây) — và một `LockTimeoutException` thành
 * `ConflictCheckBusy` y hệt (không còn chờ vô hạn, không còn 500 trần).
 *
 * # Khoá dòng vụ việc là câu ĐẦU TIÊN của transaction (cùng kỷ luật `UpdateMatterDetails`/
 * `RemoveTeamMember`/`UpdateMatterParty`)
 *
 * Bên TRONG khoá `conflict-check`, câu ĐẦU TIÊN của transaction là khoá dòng `matters`, rồi khoá
 * dòng `matter_parties` đang gỡ — không có câu đọc trần nào đứng trước, cùng lý do REPEATABLE READ
 * đã ghi ở `UpdateMatterDetails`/`RemoveTeamMember` (không lặp lại lý lẽ ở đây).
 *
 * # Bên đã bị GỠ dưới khoá (fix round 1, C1)
 *
 * Hai tab cùng nhìn một bên: tab A gỡ nó, tab B (modal GỠ hoặc SỬA đã mở TỪ TRƯỚC, không biết) gửi
 * lại. `MatterParty` dùng `SoftDeletes`, nên khoá xong mà không tìm thấy dòng (đã bị
 * `SoftDeletingScope` loại, hoặc chưa từng có id đó) từng ném `ModelNotFoundException` trần — một
 * trang lỗi 404 không câu tiếng Việt nào, và KHÔNG phải `DomainException` nên không đi qua được
 * lưới `catch (DomainException)` mà `PartiesRelationManager` đã có sẵn. Giờ
 * `lockForUpdate()->first()` (không phải `firstOrFail()`) và ném `MatterPartyAlreadyRemoved` khi
 * `null` — đúng lớp `DomainException` màn hình đã bắt.
 *
 * # Audit ghi TRONG transaction, TRƯỚC lệnh xoá mềm (cùng thứ tự `CancelMatter`)
 *
 * SPEC §10.5/R14: không bao giờ ghi số CCCD thô — dòng `matter_party_removed` chỉ nêu vai trò, tên
 * (không phải định danh) và lý do gỡ.
 */
class RemoveMatterParty
{
    public function handle(MatterParty $party, User $actor, string $reason): MatterParty
    {
        $reason = trim($reason);
        $matterId = $party->matter_id;
        $partyId = $party->getKey();

        try {
            return Cache::store('database')->lock('conflict-check', 30)->block(10, function () use (
                $matterId, $partyId, $actor, $reason,
            ): MatterParty {
                return DB::transaction(function () use ($matterId, $partyId, $actor, $reason): MatterParty {
                    // Câu ĐẦU TIÊN: khoá dòng vụ việc, rồi khoá dòng bên đang gỡ.
                    $matter = Matter::query()->whereKey($matterId)->lockForUpdate()->firstOrFail();
                    $locked = MatterParty::query()->whereKey($partyId)->lockForUpdate()->first();

                    if ($locked === null) {
                        throw MatterPartyAlreadyRemoved::make();
                    }

                    $locked->setRelation('matter', $matter);

                    Gate::forUser($actor)->authorize('delete', $locked);

                    // Bên của CHÍNH khách hàng vụ việc — xem docblock lớp.
                    if (
                        $locked->is_our_client
                        && $locked->client_id !== null
                        && (int) $locked->client_id === (int) $matter->client_id
                    ) {
                        throw ValidationException::withMessages([
                            'reason' => [__('matters.remove_party_form.own_client_denied')],
                        ]);
                    }

                    if ($reason === '') {
                        throw ValidationException::withMessages([
                            'reason' => [__('matters.remove_party_form.reason_required')],
                        ]);
                    }

                    Audit::record('matter_party_removed', $matter, [
                        'party_id' => $locked->id,
                        'role' => $locked->role->value,
                        'name' => $locked->name,
                        'reason' => $reason,
                    ], $actor);

                    $locked->delete();

                    return $locked;
                });
            });
        } catch (LockTimeoutException) {
            throw ConflictCheckBusy::make();
        }
    }
}
