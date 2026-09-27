<?php

namespace App\Actions\Document;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\DocumentGroup;
use App\Exceptions\DocumentLifecycleNotAllowed;
use App\Models\Document;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

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
 * **Rời khỏi nhóm B, SANG A HOẶC C, cũng đòi `document.publish` CỘNG một cổng vòng đời — phán
 * quyết R9 (Task 16), sửa `docs/docs-2`, MỞ RỘNG ở vòng sửa 1.** Trước Task 16, B → C (hay B → A)
 * chỉ đòi `document.update`, mà trợ lý cũng có; `PublishDocument` chỉ áp luật `signed_filed` cho
 * `group === Issued`, nên một tài liệu B vừa đổi nhãn thành C được công bố THẲNG từ
 * `internal_draft` — đúng thứ SPEC §4.11 cấm ("không được nhảy thẳng từ `internal_draft`... ngăn
 * khách nhìn thấy một bản đơn mà toà chưa hề nhận được"). Cổng chặn CHÍNH XÁC ở đây.
 *
 * **Vòng sửa 1 sửa lại phạm vi: cổng KHÔNG áp dụng khi nhóm ĐÍCH là D.** Bản đầu (Task 16) chặn
 * "bất kể nhóm đích là gì, kể cả D", với lý do D có thể thành trạm trung chuyển. Phán quyết vòng
 * sửa 1 lật lại phần đó: chuyển VÀO nhóm D luôn được phép với `document.update` — nó chỉ SIẾT lại
 * (khách mất quyền xem NGAY LẬP TỨC, xem hook `saving` của `Document`), và đây là đường DUY NHẤT
 * để rút một tài liệu nhóm B đã lỡ công bố ra khỏi tầm mắt khách, cho tới khi `M7` có
 * `RetractDocument` thật. Một trợ lý phát hiện một văn bản B bị công bố nhầm phải rút được nó
 * NGAY, không phải đi tìm ai đó có `document.publish` trước.
 *
 * **Hai đường rời B sang A/C (R9 mở rộng phần b):** (1) tài liệu đã `wasPublishedToClient()` hoặc
 * `signed_filed` — không có gì để "giặt" nữa, nó đã thật sự ký/nộp/ra tới khách; hoặc (2) người
 * chuyển nhập một LÝ DO SỬA NHẦM NHÓM ít nhất 10 ký tự, được ghi vào nhật ký
 * `document_regrouped.misfiling_reason`. Đường (2) tồn tại vì không phải mọi lần nộp sai nhóm đều
 * là một văn bản B thật: một tài liệu bị gắn NHẦM nhóm B ngay từ lúc tải lên (chưa từng định trình
 * duyệt) không nên bị buộc đi hết vòng đời giả để sửa một lỗi gõ. Đường (2) không đi vòng qua
 * vòng đời — nó THÚ NHẬN công khai, có dấu vết, rằng đây là một lần sửa nhầm, khác hẳn việc lặng
 * lẽ đổi nhóm rồi công bố như thể mọi thứ đúng quy trình. Xem
 * `App\Exceptions\DocumentLifecycleNotAllowed::notReadyToLeaveGroupB()`/`misfilingReasonTooShort()`.
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

    public function handle(Document $document, User $actor, DocumentGroup $group, ?string $reason = null): Document
    {
        return DB::transaction(function () use ($document, $actor, $group, $reason): Document {
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

            // R9 mở rộng (vòng sửa 1): rời khỏi nhóm B SANG A HOẶC C — không áp dụng khi nhóm
            // ĐÍCH là D, xem docblock lớp. `$misfilingReason` chỉ khác `null` khi đường "lý do sửa
            // nhầm nhóm" là đường được dùng (tài liệu chưa signed_filed/published), để dòng nhật
            // ký bên dưới ghi lại đúng NGUYÊN NHÂN của lần chuyển, không chỉ nhóm cũ/nhóm mới.
            $misfilingReason = null;

            if (self::leavesGroupB($fresh, $group)) {
                Gate::forUser($actor)->authorize('publish', $fresh);

                if (! $fresh->hasClearedIssuedLifecycle()) {
                    // `trim()` trần chỉ gỡ khoảng trắng ASCII (` \t\n\r\0\x0B`) — một lý do gõ
                    // toàn NBSP (U+00A0, bàn phím điện thoại hay chèn khi gõ có dấu, hoặc dán từ
                    // Word) hay khoảng trắng biểu ý (U+3000, IME Đông Á) đi lọt qua với độ dài > 0
                    // và không mang chữ nào — vòng sửa 2. `\p{Z}` (nhóm Unicode "Separator") phủ
                    // cả hai cộng mọi khoảng trắng Unicode khác; `\x{200B}` (zero-width space)
                    // không thuộc `\p{Z}` nên phải liệt kê riêng.
                    // `?? ''`: `preg_replace()` với cờ `/u` trả `null` nếu `$reason` không phải
                    // UTF-8 hợp lệ — một chuỗi như vậy không mang lý do gì đọc được, nên coi như
                    // rỗng (từ chối bằng câu "chưa sẵn sàng" chung) thay vì để `null` rơi xuống
                    // `mb_strlen()` phía dưới.
                    $trimmedReason = $reason === null
                        ? ''
                        : preg_replace('/^[\s\p{Z}\x{200B}]+|[\s\p{Z}\x{200B}]+$/u', '', $reason) ?? '';

                    if ($trimmedReason === '') {
                        throw DocumentLifecycleNotAllowed::notReadyToLeaveGroupB($fresh);
                    }

                    if (mb_strlen($trimmedReason) < 10) {
                        throw DocumentLifecycleNotAllowed::misfilingReasonTooShort($fresh);
                    }

                    $misfilingReason = $trimmedReason;
                }
            }

            Document::duringAuditedRegroup(fn () => $fresh->update(['group' => $group]));

            // SPEC §10.6 không liệt kê "đổi nhóm tài liệu", vì bảng đó được viết trước khi ai
            // nhận ra đổi nhóm LÀ cách mở khoá nhóm D. Dòng này ghi cả nhóm cũ lẫn nhóm mới:
            // nhóm mới đọc được từ bản ghi, còn nhóm CŨ thì sau thao tác không còn ở đâu nữa.
            // `misfiling_reason` luôn có mặt (kể cả `null`) để một truy vấn lọc theo khoá đó
            // không phải phân biệt "khoá vắng mặt" với "khoá null".
            Audit::record('document_regrouped', $fresh, [
                'matter_id' => $fresh->matter_id,
                'from_group' => $from->value,
                'to_group' => $group->value,
                'misfiling_reason' => $misfilingReason,
            ], $actor);

            return $fresh;
        });
    }

    /**
     * Lần chuyển này có phải là RỜI NHÓM B sang A hoặc C không (R9 mở rộng, cộng final review X7
     * C-I2). "Rời nhóm B" gồm cả một tài liệu đang ở D mà ngay trước khi vào D nó ở B — không có
     * vế đó, D là trạm giặt: B (nháp chưa ký) → D → C đi lọt mà không cần vòng đời hay lý do.
     * Nhóm đích B hoặc D thì không phải rời B.
     */
    public static function leavesGroupB(Document $document, DocumentGroup $target): bool
    {
        if (in_array($target, [DocumentGroup::Internal, DocumentGroup::Issued], true)) {
            return false;
        }

        $origin = $document->group === DocumentGroup::Internal
            ? self::groupBeforeInternal($document)
            : $document->group;

        return $origin === DocumentGroup::Issued;
    }

    /**
     * Màn hình "Chuyển nhóm" hỏi đúng câu Action sẽ hỏi dưới khoá: rời nhóm B khi văn bản chưa đi
     * hết vòng đời thì phải có lý do sửa nhầm nhóm. Một chỗ, để ô lý do không lệch với cổng thật.
     */
    public static function needsMisfilingReason(Document $document, DocumentGroup $target): bool
    {
        return self::leavesGroupB($document, $target) && ! $document->hasClearedIssuedLifecycle();
    }

    /**
     * Nhóm của tài liệu NGAY TRƯỚC lần vào nhóm D gần nhất — đọc từ dòng `document_regrouped`
     * mới nhất có `to_group = D` (chỉ Action này đưa được một tài liệu đã có vào D kèm nhật ký).
     * `null` khi tài liệu được tạo thẳng trong D (không có lần vào D nào để hỏi).
     */
    private static function groupBeforeInternal(Document $document): ?DocumentGroup
    {
        $properties = Activity::query()
            ->where('subject_type', $document->getMorphClass())
            ->where('subject_id', $document->getKey())
            ->where('event', 'document_regrouped')
            ->where('properties->to_group', DocumentGroup::Internal->value)
            ->latest('id')
            ->value('properties');

        $from = $properties === null ? null : collect($properties)->get('from_group');

        return is_string($from) ? DocumentGroup::tryFrom($from) : null;
    }
}
