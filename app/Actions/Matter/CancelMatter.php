<?php

namespace App\Actions\Matter;

use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\User;
use App\Support\Audit;
use App\Support\Billing\BillingSummary;
use App\Support\Billing\Money;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Huỷ hồ sơ mở nhầm" (M6.5 Task 5) — admin, xoá mềm kèm lý do BẮT BUỘC. Đây là đường sửa cho
 * vụ gắn nhầm khách hàng hoặc nhầm loại vụ việc: hai cột đó (`client_id`, `matter_type_id`)
 * KHÔNG sửa được qua {@see UpdateMatterDetails} (xem docblock lớp đó), nên hồ sơ mở sai chỉ còn
 * một đường là huỷ và mở lại đúng.
 *
 * # Các bên của vụ đã huỷ VẪN nằm trong dữ liệu đối chiếu xung đột — chủ ý, phán quyết riêng
 *
 * Fix round 1: bản đầu của docblock này so sánh việc này với R14 ("gỡ một bên nghĩa là nhập
 * nhầm, chưa từng là bên, nên KHÔNG còn trong dữ liệu đối chiếu xung đột") — phép so sánh đó SAI
 * và đã bị sửa. "Huỷ hồ sơ mở nhầm" không phải "nhập nhầm, chưa từng có vụ việc": vụ việc đó CÓ
 * THẬT, mỗi bên trong nó đã CÓ THẬT quan hệ với văn phòng ở một thời điểm nào đó, chỉ là hồ sơ bị
 * gắn sai khách hàng hoặc sai loại. Một xung đột lợi ích không biến mất chỉ vì văn phòng sau đó
 * huỷ tờ giấy ghi lại nó — luật sư đối lập hôm nay vẫn từng là luật sư đối lập trong vụ đã huỷ
 * đó. Vì kiểm tra xung đột là một CÔNG CỤ ĐẠO ĐỨC NGHỀ NGHIỆP, một kết quả DƯƠNG TÍNH GIẢ (báo
 * động nhầm, người xem lại thấy vụ đã huỷ nên bỏ qua) an toàn hơn NHIỀU so với một kết quả ÂM
 * TÍNH GIẢ (im lặng bỏ sót một xung đột thật) — nên các bên của vụ đã huỷ PHẢI còn nằm trong dữ
 * liệu đối chiếu, và Action này KHÔNG được thêm bất kỳ mã nào gỡ chúng ra.
 *
 * Hành vi này đã đúng SẴN, không cần Action ở đây làm thêm gì: `RunConflictCheck` tự
 * `withTrashed()` trên cả `MatterParty::query()` lẫn quan hệ `matter` của nó (đọc lại xác nhận
 * trong `app/Actions/RunConflictCheck.php`), nên gọi `$locked->delete()` (xoá mềm) không rút một
 * bên nào ra khỏi tầm nhìn của lần kiểm tra xung đột KẾ TIẾP. Ghi lại ở đây để người sau không tự
 * "sửa" nó bằng một `whereNull('deleted_at')` tưởng là đúng.
 *
 * # Khoá TRƯỚC, không đọc gì trước khi khoá
 *
 * Cùng kỷ luật với {@see UpdateMatterDetails}: câu lệnh ĐẦU TIÊN trong transaction là
 * `lockForUpdate()`, và `Gate::authorize()` chạy SAU đó trên bản ghi vừa khoá — không phải trên
 * `$matter` do caller truyền vào — để một lần đọc quyền không tự fix cứng ảnh chụp
 * (snapshot) REPEATABLE READ của MariaDB trước khi khoá kịp giữ dòng.
 *
 * # Audit GHI TRONG transaction, trước khi xoá mềm
 *
 * Dòng `matter_cancelled` ghi lý do huỷ TRƯỚC lệnh `delete()` — cùng một transaction, nên cả hai
 * cùng commit hoặc cùng rollback. Ghi trước hay sau lệnh xoá không đổi tính đúng đắn (cả hai đều
 * ở trong transaction), nhưng ghi trước để lý do luôn đọc được cùng bối cảnh của bản ghi CÒN
 * `exists = true`, không phải một bản ghi model đã có `deleted_at` gán sẵn trong bộ nhớ.
 *
 * # Cách ly cổng khách (SPEC §11) — không tầng nào được yếu đi vì tầng khác đã che
 *
 * Một vụ đã xoá mềm biến mất khỏi cổng khách hoàn toàn nhờ BA tầng ĐỘC LẬP, không tầng nào ở
 * đây cần sửa vì cả ba đã đứng vững từ M2–M5: tầng truy vấn
 * (`Matter::applyClientPortalConstraints()` gọi `whereNull('deleted_at')` tường minh, không chỉ
 * tin `SoftDeletingScope`), tầng policy (`MatterPolicy::releasedToPortal()` đọc lại
 * `$matter->trashed()`), và tầng serialize (`HidesInternalAttributesFromPortal` — không liên
 * quan trực tiếp tới xoá mềm nhưng cùng nguyên tắc "không tin một tầng một mình"). Hành động
 * DUY NHẤT của Action này là gọi `delete()` — không viết lại luật cách ly ở đây, chỉ dựa vào nó.
 */
class CancelMatter
{
    public function handle(Matter $matter, User $actor, string $reason): Matter
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($matter, $actor, $reason): Matter {
            /** @var Matter $locked */
            $locked = Matter::withTrashed()->whereKey($matter->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('cancelMatter', $locked);

            if ($locked->trashed()) {
                throw ValidationException::withMessages([
                    'reason' => [__('actions.cancel_matter.already_cancelled')],
                ]);
            }

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' => [__('actions.cancel_matter.reason_required')],
                ]);
            }

            $this->refuseIfEverClosed($locked);

            $this->refuseIfPaymentsRecorded($locked);

            $this->refuseIfBalanceOutstanding($locked);

            Audit::record('matter_cancelled', $locked, [
                'reason' => $reason,
            ], $actor);

            $locked->delete();

            return $locked;
        });
    }

    /**
     * Làn fm A4 (kiểm tra nghiệp vụ 2026-10-09): chỉ huỷ được vụ CHƯA TỪNG kết thúc. Vụ đang đóng, hay
     * vụ đã có dòng lưu trữ (từng đóng rồi được mở lại), không phải "mở nhầm" — và huỷ nó (xoá mềm)
     * làm hồ sơ thoát khỏi chính sách lưu trữ: `FlagRetentionExpiry` không bao giờ cảnh báo vụ đã xoá
     * mềm, `RecordMatterDestruction` từ chối nó. Đây là bất biến SPEC §6.12 vẫn nói ("vụ huỷ vì mở
     * nhầm không bao giờ có dòng lưu trữ") mà trước bản sửa mã không giữ. Đọc thường dòng lưu trữ sau
     * khi đã khoá `matters` là đủ: mọi đường ghi `matter_archives` khoá `matters` trước.
     */
    private function refuseIfEverClosed(Matter $locked): void
    {
        $hasArchive = MatterArchive::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->withTrashed()
            ->where('matter_id', $locked->getKey())
            ->exists();

        if ($locked->isClosed() || $hasArchive) {
            throw ValidationException::withMessages([
                'reason' => [__('lifecycle.cancel.closed_refused')],
            ]);
        }
    }

    /**
     * Làn fa3 (2026-10-16, mục 1 — phán quyết controller): hồ sơ đã có khoản thu CHƯA HUỶ trên bất kỳ
     * hợp đồng nào (kể cả hợp đồng đã huỷ hay đã hoàn tất) thì không huỷ được. Tiền đã về là sự thật kế
     * toán: trước luật này, theo đúng lời từ chối "còn dư nợ" cũ (thu nốt / miễn / huỷ hợp đồng), hồ sơ huỷ
     * được trong khi vẫn mang khoản thu, và khoản đó rời khỏi mọi đường sửa (`VoidPayment` từ chối vụ đã
     * xoá mềm qua cổng tiền). Hỏi TRƯỚC dư nợ: huỷ khoản thu làm đợt về lại `pending`, nên câu dư nợ (huỷ
     * hợp đồng) là bước sau — câu từ chối nói luôn bước đó (fix round 1, m7).
     *
     * Hai câu, theo việc hệ thống THẬT SỰ cho làm ({@see self::paymentsRefusal()}):
     *  - khoản thu nằm trên hợp đồng đang hiệu lực hay đã huỷ: kế toán huỷ từng khoản thu (ghi lý do) và ghi
     *    lại vào đúng hồ sơ, huỷ hợp đồng nếu còn hiệu lực, rồi mới huỷ hồ sơ — không có chức năng "chuyển
     *    khoản thu sang hồ sơ khác";
     *  - fix round 1 (I1): khoản thu nằm trên hợp đồng ĐÃ HOÀN TẤT: `VoidPayment` từ chối huỷ khoản thu ở đó
     *    (`ContractStatus::allowsPaymentVoid()`), và không Action nào đưa một hợp đồng đã hoàn tất về lại
     *    hiệu lực (`CancelContract`, `RevertContractToDraft` chỉ nhận `active`) — nên hồ sơ đó KHÔNG có
     *    đường huỷ như hồ sơ mở nhầm; câu nói thế, và chỉ đường còn lại: đóng hồ sơ (giai đoạn kết thúc), mở
     *    hồ sơ mới cho đúng khách hàng / loại vụ việc. Câu này đứng trước câu kia: dù vụ còn khoản thu khác
     *    huỷ được, khoản trên hợp đồng đã hoàn tất vẫn chặn.
     *
     * Con số là {@see BillingSummary::collectedOnCompletedForMatter()} / {@see BillingSummary::collectedForMatter()};
     * đọc thường sau khi khoá `matters` là đủ, cùng lý lẽ với {@see self::refuseIfBalanceOutstanding()} (mọi
     * Action tiền khoá `matters` trước).
     */
    private function refuseIfPaymentsRecorded(Matter $locked): void
    {
        $refusal = self::paymentsRefusal($locked);

        if ($refusal !== null) {
            throw ValidationException::withMessages([
                'reason' => [$refusal],
            ]);
        }
    }

    /**
     * Câu từ chối vì khoản thu (docblock {@see self::refuseIfPaymentsRecorded()}), hoặc `null` khi vụ không còn
     * khoản thu chưa huỷ nào — MỘT chuỗi cho lời từ chối của Action và cho dòng cảnh báo của hộp thoại huỷ
     * (`MatterOpenWork::cancellingLines()`), để hai nơi không lệch nhau.
     */
    public static function paymentsRefusal(Matter $matter): ?string
    {
        $onCompleted = BillingSummary::collectedOnCompletedForMatter((int) $matter->getKey());

        if ($onCompleted['count'] > 0) {
            return __('actions.cancel_matter.payments_on_completed', [
                'code' => $matter->code,
                'contract' => implode(', ', $onCompleted['contracts']),
                'count' => $onCompleted['count'],
                'amount' => Money::format($onCompleted['amount']),
            ]);
        }

        $collected = BillingSummary::collectedForMatter((int) $matter->getKey());

        if ($collected['count'] > 0) {
            return __('actions.cancel_matter.payments_recorded', [
                'code' => $matter->code,
                'count' => $collected['count'],
                'amount' => Money::format($collected['amount']),
            ]);
        }

        return null;
    }

    /**
     * Hồ sơ còn dư nợ trên hợp đồng `active` thì không huỷ được (gộp M9, xung đột 1).
     *
     * Hook `Matter::deleting` của M9 đã chặn lệnh `delete()` bên dưới — nhưng bằng
     * `MatterHasOutstandingBalance`, một `DomainException`, mà hộp thoại "Huỷ hồ sơ" của
     * `EditMatter` không bắt: một lỗi 500 đúng lúc admin cần biết phải làm gì. Nên Action tự hỏi
     * TRƯỚC, bằng đúng MỘT định nghĩa dư nợ mà hook dùng ({@see BillingSummary::outstandingForMatter()}
     * — không phép tính thứ hai), và trả lời trên ô `reason` mà hộp thoại đã hiện lỗi. Hook vẫn ở
     * nguyên làm chốt chặn cho mọi đường xoá mềm khác.
     *
     * **Đọc thường sau khi khoá là đủ.** Mọi Action tiền khoá hàng `matters` TRƯỚC (luật thứ tự khoá
     * toàn dự án, `LocksBillingRows`), nên khi Action này đang giữ khoá đó thì không Action tiền nào
     * của vụ này đang chạy dở hay chen vào được; và ảnh chụp REPEATABLE READ của transaction này chỉ
     * được dựng ở lần đọc thường đầu tiên — sau câu `lockForUpdate()` ở trên. Không gọi một Action
     * tiền nào ở đây (xem `BillingLockOrderTest`).
     */
    private function refuseIfBalanceOutstanding(Matter $locked): void
    {
        $balance = BillingSummary::outstandingForMatter((int) $locked->getKey());

        if ($balance['amount'] > 0) {
            throw ValidationException::withMessages([
                'reason' => [__('actions.cancel_matter.outstanding_balance', [
                    'code' => $locked->code,
                    'amount' => Money::format($balance['amount']),
                    'count' => $balance['count'],
                ])],
            ]);
        }
    }
}
