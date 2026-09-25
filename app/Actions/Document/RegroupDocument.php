<?php

namespace App\Actions\Document;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Exceptions\DocumentLifecycleNotAllowed;
use App\Models\Document;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Đổi nhóm của một tài liệu (SPEC §4.11) — cửa DUY NHẤT để một tài liệu rời nhóm D.
 *
 * **Vì sao Action này tồn tại.** Nhóm D là ranh giới SPEC gọi là tuyệt đối: `PublishDocument`
 * chặn nó trước cả kiểm tra quyền, global scope loại nó, `DocumentPolicy` loại nó. Nhưng cả ba
 * lớp ấy đọc cột `group`, nên ai sửa được cột đó thì đi vòng được qua tất cả — và cho tới trước
 * Action này, sửa nó KHÔNG để lại dòng nhật ký nào, vì `Document` chưa dùng `LogsActivity`. Thứ
 * duy nhất còn lại sau một lần đổi D → C rồi công bố là một dòng `document_published` nói về một
 * tài liệu nhóm C: đúng sự thật ở thời điểm ghi, và vô dụng với người đi tìm chuyện đã xảy ra.
 * Tệ hơn, chính thông điệp lỗi của sản phẩm từng CHỈ người dùng làm đúng nước đi đó.
 *
 * **Rời khỏi nhóm D đòi `document.publish`; đi vào nhóm D thì không.** Hai chiều không đối xứng
 * vì chúng không cùng hậu quả: rời nhóm D là mở ra khả năng tài liệu tới tay khách, tức đúng cái
 * quyết định mà Task 2 đặt sau `document.publish`; còn đưa một tài liệu VÀO nhóm D chỉ siết lại
 * — nó lập tức biến mất khỏi mọi truy vấn portal và mất quyền tải. Bắt quyền công bố cho một
 * thao tác siết lại sẽ làm một trợ lý nhận ra tài liệu bị xếp nhầm nhóm phải đi tìm luật sư, và
 * trong lúc chờ thì tài liệu vẫn nằm ở chỗ rộng hơn.
 *
 * Hai chiều đều đi qua `DocumentPolicy::update` (kèm `view()`, nên không ai đổi nhóm một tài
 * liệu họ không đọc được, và không ai đụng tới tài liệu của một vụ việc đã xoá mềm).
 *
 * **Rời khỏi nhóm B cũng đòi `document.publish`, CỘNG một cổng vòng đời — phán quyết R9 (Task
 * 16), sửa `docs/docs-2`.** Trước bản sửa này, B → C (hay B → A) chỉ đòi `document.update`, mà
 * trợ lý cũng có; `PublishDocument` chỉ áp luật `signed_filed` cho `group === Issued`, nên một
 * tài liệu B vừa đổi nhãn thành C được công bố THẲNG từ `internal_draft` — đúng thứ SPEC §4.11
 * cấm ("không được nhảy thẳng từ `internal_draft`... ngăn khách nhìn thấy một bản đơn mà toà chưa
 * hề nhận được"). Cổng mới chặn CHÍNH XÁC ở đây, bất kể nhóm ĐÍCH là gì — kể cả D: một tài liệu B
 * chưa ký không được dùng D làm trạm trung chuyển để rồi rời D (chỉ đòi `document.publish`, không
 * đòi trạng thái) sang C mà không đi qua vòng đời nào. Xem
 * `App\Exceptions\DocumentLifecycleNotAllowed::notReadyToLeaveGroupB()`.
 *
 * **Hàng rào ở tầng model là phần không thể bỏ.** Action này là đường đúng; `Document::booted()`
 * là thứ làm cho nó thành đường DUY NHẤT. Xem docblock `Document::duringAuditedRegroup()` để
 * biết vì sao một bất biến dữ liệu được canh ở model trong khi nghiệp vụ vẫn ở Action.
 *
 * Cổng nhóm B ở trên KHÔNG có hàng rào tương ứng ở tầng model — khác nhóm D. SPEC không gọi vòng
 * đời nhóm B là một ranh giới "tuyệt đối" theo đúng nghĩa đó (nó là một CHUỖI trạng thái, không
 * phải một tập bị cấm tuyệt đối), nên một cổng ở tầng Action là đủ, cùng mức với cổng
 * `signed_filed` mà `PublishDocument` đã áp từ trước cho chính nhóm này.
 */
class RegroupDocument
{
    use ReadsWithoutPortalScope;

    public function handle(Document $document, User $actor, DocumentGroup $group): Document
    {
        return DB::transaction(function () use ($document, $actor, $group): Document {
            // Đọc lại dưới khoá, cùng lý do với `PublishDocument`: nhóm hiện tại quyết định cần
            // quyền gì, nên đọc nó từ đối tượng caller cầm trong tay là để caller tự khai. Và vì
            // lần đọc lại ấy tồn tại để KHÔNG tin caller, nó cũng không được để guard đang mở
            // quyết định nó thấy gì — `scopelessly()`, xem docblock `ReadsWithoutPortalScope`.
            $fresh = $this->scopelessly(Document::query())->lockForUpdate()->findOrFail($document->getKey());

            $from = $fresh->group;

            // Đổi sang đúng nhóm đang có không phải một thao tác: không kiểm tra thêm, không ghi
            // một dòng nhật ký không mang tin gì. Một danh sách nhật ký đầy những dòng "D → D"
            // là một danh sách người ta thôi đọc.
            if ($from === $group) {
                return $fresh;
            }

            Gate::forUser($actor)->authorize('update', $fresh);

            if ($from === DocumentGroup::Internal) {
                Gate::forUser($actor)->authorize('publish', $fresh);
            }

            // R9: rời khỏi nhóm B đòi `document.publish` CỘNG đã đi hết vòng đời
            // (`internal_draft` → `pending_approval` → `signed_filed`) — xem docblock lớp và
            // `DocumentLifecycleNotAllowed::notReadyToLeaveGroupB()`. Bất kể nhóm ĐÍCH là gì:
            // không chỉ chặn "giặt" B → C, mà chặn cả B → D, nếu không D trở thành một trạm
            // trung chuyển để bỏ qua cổng này (rời D chỉ đòi `document.publish`, không đòi trạng
            // thái).
            if ($from === DocumentGroup::Issued) {
                Gate::forUser($actor)->authorize('publish', $fresh);

                if (! in_array($fresh->status, [DocumentStatus::SignedFiled, DocumentStatus::Published], true)) {
                    throw DocumentLifecycleNotAllowed::notReadyToLeaveGroupB($fresh);
                }
            }

            Document::duringAuditedRegroup(fn () => $fresh->update(['group' => $group]));

            // SPEC §10.6 không liệt kê "đổi nhóm tài liệu", vì bảng đó được viết trước khi ai
            // nhận ra đổi nhóm LÀ cách mở khoá nhóm D. Dòng này ghi cả nhóm cũ lẫn nhóm mới:
            // nhóm mới đọc được từ bản ghi, còn nhóm CŨ thì sau thao tác không còn ở đâu nữa.
            Audit::record('document_regrouped', $fresh, [
                'matter_id' => $fresh->matter_id,
                'from_group' => $from->value,
                'to_group' => $group->value,
            ], $actor);

            return $fresh;
        });
    }
}
