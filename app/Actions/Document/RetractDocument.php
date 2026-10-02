<?php

namespace App\Actions\Document;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\DocumentStatus;
use App\Exceptions\DocumentNotRetractable;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * M7 Task 7 — RÚT LẠI một tài liệu đang ra tới khách (món nợ mang từ M4, `docs/docs-6`: trước đây
 * một tài liệu công bố nhầm không có đường rút đúng nghiệp vụ, chỉ có hai "đường rút tạm" — chuyển
 * vào nhóm D, hoặc xoá).
 *
 * **Rút là gì.** `status = retracted`, tắt `client_can_view`/`client_can_download`, ghi
 * `retracted_at`/`retracted_by`/`retraction_reason`. Tài liệu biến khỏi mọi truy vấn và mọi quyền
 * phía khách (scope portal và `DocumentPolicy::view` đều đòi `published` + cờ xem), nên một URL tải
 * có chữ ký phát trước lúc rút trả 404 sau đó. Thay vào chỗ tài liệu, khách thấy MỘT dòng
 * "Văn phòng đã rút lại tài liệu này. Lý do: …" (đường đọc hẹp `Document::retractionNoticesFor()`):
 * một khoảng trống không lời giải thích làm khách nghĩ tài liệu bị mất.
 *
 * **Rút KHÔNG xoá gì.** Tệp (media) giữ nguyên, mọi dòng `document_downloads` giữ nguyên — bằng
 * chứng khách đã tải là thứ không được phép biến mất (khoá ngoại của chúng nay `restrictOnDelete`,
 * bước 1 của task này). `published_at`/`published_by` giữ nguyên: lần công bố đã xảy ra. Dòng audit
 * `document_retracted` mang số lượt tải CỦA KHÁCH trước lúc rút, để người đọc nhật ký nối được lần
 * rút với `document_downloads` mà không phải tự đếm.
 *
 * **Vị từ "đang ra tới khách" là `Document::isReleasedToPortal()`, MỘT vị từ duy nhất** dùng chung
 * cho: cổng của Action này, lời từ chối của `RegroupDocument` khi chuyển vào nhóm D,
 * `DocumentPolicy::delete()`, và điều kiện hiện nút "Rút lại" trên màn hình. Chọn nó chứ không
 * `wasPublishedToClient()` vì nó trả lời đúng câu "khách có đang được cho xem bản ghi này không" ở
 * tầng TÀI LIỆU: `published` + cờ xem + không nhóm D + chưa xoá mềm. `wasPublishedToClient()` thiếu
 * hai vế sau — một tài liệu đã xoá mềm sẽ thành "đang ra tới khách" và bị chặn khỏi nhóm D vô cớ.
 * Vị từ KHÔNG hỏi vụ việc: một tài liệu đã công bố trên một vụ chưa lên portal (hay đã hết hạn tra
 * cứu) vẫn là một tài liệu ĐÃ được quyết định cho khách xem — nó trở lại tầm mắt khách ngay khi vụ
 * việc lên lại portal — nên rút nó vẫn là một lần rút có lý do, không phải một lần đổi nhóm.
 *
 * **Thứ tự (mọi bước trong MỘT transaction):**
 *  1. Câu ĐẦU TIÊN là một lần đọc có khoá trên `matters` (thứ tự khoá toàn cục: `matters` trước):
 *     dòng vụ việc của tài liệu, tìm bằng truy vấn con trên `documents.matter_id` — không tin
 *     `$document->matter_id` caller cầm trong tay. Gỡ `ClientPortalScope`, kể cả vụ đã xoá mềm.
 *  2. Khoá dòng `documents` (gỡ scope portal, kể cả đã xoá mềm), và mọi quyết định đọc từ bản này,
 *     không từ `$document` của caller. Không có → `missing`.
 *  3. Người thực hiện ĐỌC LẠI từ CSDL: tài khoản vừa bị vô hiệu hoá/xoá trong lúc hộp thoại còn mở
 *     không rút được bằng đối tượng cũ → `AuthorizationException`.
 *  4. Tài liệu đã xoá mềm, vụ việc đã xoá mềm → từ chối bằng câu trạng thái (cùng hạng các cổng
 *     trạng thái đứng trước `Gate` của `PublishDocument`).
 *  5. `DocumentPolicy::publish` (tức `document.publish` + `update` + `view`), hỏi lại dưới khoá trên
 *     bản đọc lại. Trợ lý không có `document.publish` nên không rút được — giá đã chấp nhận: họ nhờ
 *     luật sư/trưởng phòng bấm "Rút lại".
 *  6. Đã rút → `alreadyRetracted` (quyết định đầu giữ nguyên). Không đang ra tới khách →
 *     `notReleased`.
 *  7. Lý do: bắt buộc; gỡ khoảng trắng Unicode ở hai đầu (`\s`, `\p{Z}` — NBSP, khoảng trắng biểu
 *     ý — và zero-width space, cùng cách `RegroupDocument`); `mb_strlen` ≥ {@see self::REASON_MIN}
 *     và ≤ {@see self::REASON_MAX} (cột `text`; trần chủ động như các lý do khác của dự án). Sai thì
 *     `ValidationException` gắn đúng tên ô `retraction_reason` của form.
 *  8. Đếm lượt tải của khách, ghi bản ghi, ghi audit.
 *
 * Không gửi thư, không thông báo: khách thấy dòng rút ở lần mở cổng kế tiếp; listener thư
 * `client.document_published` (M6) không liên quan tới thao tác này.
 *
 * **Chỗ gắn cho M9 (chưa merge vào làn này).** Làn M9 yêu cầu Action này và
 * `DocumentPolicy::delete()` từ chối tài liệu đang được `payments.receipt_document_id` hoặc
 * `contract_amendments.document_id` trỏ tới (biên lai, phụ lục hợp đồng là chứng từ tài chính). Hai
 * bảng đó chưa có trên base của làn M7; M9 sở hữu phần chặn đó lúc merge và đặt nó ở bước 6, ngay
 * sau `notReleased` (cổng trạng thái, dưới khoá `documents`; khoá các bảng tiền tệ — nếu cần — đi
 * SAU `matters` theo thứ tự toàn cục).
 */
class RetractDocument
{
    use ReadsWithoutPortalScope;

    /** Ngưỡng của đặc tả M4 (PROGRESS, "Việc hoãn lại"), cùng ngưỡng `ReviewChecklistItem`. */
    public const REASON_MIN = 20;

    /** Trần chủ động cho cột `text` — cùng con số `RecordMatterDestruction::REASON_MAX`. */
    public const REASON_MAX = 5000;

    public function handle(Document $document, User $actor, string $reason): Document
    {
        return DB::transaction(function () use ($document, $actor, $reason): Document {
            // 1. Câu ĐẦU TIÊN: khoá dòng `matters`.
            $matter = $this->scopelessly(Matter::query())
                ->withTrashed()
                ->whereIn(
                    (new Matter)->getQualifiedKeyName(),
                    $this->scopelessly(Document::query())
                        ->withTrashed()
                        ->whereKey($document->getKey())
                        ->select('matter_id'),
                )
                ->lockForUpdate()
                ->first();

            // 2. Khoá dòng `documents`, SAU `matters`.
            $fresh = $this->scopelessly(Document::query())
                ->withTrashed()
                ->lockForUpdate()
                ->find($document->getKey());

            if ($fresh === null || $matter === null || (int) $fresh->matter_id !== (int) $matter->getKey()) {
                throw DocumentNotRetractable::missing();
            }

            $fresh->setRelation('matter', $matter);

            // 3.
            $freshActor = User::query()->find($actor->getKey());

            if ($freshActor === null || ! $freshActor->is_active) {
                throw new AuthorizationException;
            }

            // 4.
            if ($fresh->trashed()) {
                throw DocumentNotRetractable::trashed($fresh);
            }

            if ($matter->trashed()) {
                throw DocumentNotRetractable::matterUnavailable($fresh);
            }

            // 5.
            Gate::forUser($freshActor)->authorize('publish', $fresh);

            // 6.
            if ($fresh->status === DocumentStatus::Retracted) {
                throw DocumentNotRetractable::alreadyRetracted($fresh);
            }

            if (! $fresh->isReleasedToPortal()) {
                throw DocumentNotRetractable::notReleased($fresh);
            }

            // 7.
            $reason = $this->validatedReason($reason);

            // 8.
            $clientDownloads = $this->scopelessly(DocumentDownload::query())
                ->where('document_id', $fresh->getKey())
                ->where('downloader_type', (new ClientUser)->getMorphClass())
                ->count();

            $fresh->update([
                'status' => DocumentStatus::Retracted,
                'client_can_view' => false,
                'client_can_download' => false,
                'retracted_at' => now(),
                'retracted_by' => $freshActor->getKey(),
                'retraction_reason' => $reason,
            ]);

            // Ghi BÊN TRONG transaction, cùng lý do `PublishDocument`. `client_id` chép vào vì
            // `matters.client_id` sửa được — "đã rút khỏi tay AI" phải đọc được về sau. Không chép
            // lý do (đã nằm trên bản ghi tài liệu, có thể dài) và không chép tiêu đề.
            Audit::record('document_retracted', $fresh, [
                'matter_id' => $matter->getKey(),
                'client_id' => $matter->client_id,
                'group' => $fresh->group->value,
                'version' => $fresh->version,
                'client_downloads' => $clientDownloads,
            ], $freshActor);

            return $fresh;
        });
    }

    private function validatedReason(string $reason): string
    {
        // `?? ''`: `preg_replace()` với `/u` trả `null` cho chuỗi không phải UTF-8 hợp lệ — chuỗi đó
        // không mang lý do đọc được, nên coi như rỗng (bị ngưỡng tối thiểu từ chối).
        $trimmed = preg_replace('/^[\s\p{Z}\x{200B}]+|[\s\p{Z}\x{200B}]+$/u', '', $reason) ?? '';
        $length = mb_strlen($trimmed);

        if ($length < self::REASON_MIN) {
            throw ValidationException::withMessages([
                'retraction_reason' => [__('retraction.validation.reason_min', ['min' => self::REASON_MIN])],
            ]);
        }

        if ($length > self::REASON_MAX) {
            throw ValidationException::withMessages([
                'retraction_reason' => [__('retraction.validation.reason_max', ['max' => self::REASON_MAX])],
            ]);
        }

        return $trimmed;
    }
}
