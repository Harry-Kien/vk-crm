<?php

namespace App\Filament\Admin\Concerns;

use App\Exceptions\FileRejected;
use Closure;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Chạy một Action nghiệp vụ từ một màn hình Filament và bảo đảm MỌI lời từ chối của nó đến được
 * mắt người dùng bằng tiếng Việt — không bao giờ thành trang 500, không bao giờ thành một chuỗi
 * tiếng Anh của framework.
 *
 * **Bốn họ exception, và chúng đi bằng bốn đường khác nhau.** Đây là lý do trait này tồn tại thay
 * vì một `try/catch` chép đi chép lại ở từng nút:
 *
 *  1. `ValidationException` — Action đang nói về một Ô NHẬP SAI và đã gắn sẵn tên ô
 *     (`rejection_reason`, `title`, `issued_at`, `matter_checklist_item_id`). Nhưng khoá nó gắn
 *     là khoá TRẦN, còn một trường trong modal của Filament nằm ở state path
 *     `mountedActionSchema0.<tên ô>`; ném thẳng lên thì Filament coi khoá đó không thuộc form
 *     nào và KHÔNG hiện lỗi ở đâu cả (cùng cái bẫy `PartiesRelationManager::errorKey()` đã ghi
 *     lại). Nên ở đây mỗi khoá được dịch sang state path thật.
 *  2. `FileRejected` — cũng là một `DomainException`, nhưng nó là họ DUY NHẤT luôn có một ô để
 *     chỉ vào (ô chọn tệp), và người đọc nó thường phải làm một việc cụ thể với đúng cái tệp đó.
 *     Nên nó được đổi thành lỗi gắn vào ô tệp, không phải một thông báo trôi nổi. Vì nó là con
 *     của `DomainException`, thứ tự `catch` bên dưới là BẮT BUỘC.
 *  3. `DomainException` còn lại (`DocumentNotPublishable`, `ChecklistItemNotReviewable`,
 *     `DocumentGroupNotChangeable`) — Action đang nói về TRẠNG THÁI của một bản ghi, không về
 *     một ô nào. Không có ô để gắn vào, nên nó ra bằng một `Notification` `persistent()`: những
 *     câu này dài (mỗi câu nói ra việc cần làm tiếp theo, SPEC §8.4) và một thông báo tự tắt sau
 *     vài giây là một câu không ai đọc hết.
 *  4. `AuthorizationException` — `Gate` bên trong Action từ chối. Nó KHÔNG phải con của
 *     `DomainException` (nó kế thừa thẳng `\Exception`), nên trước bản sửa này nó thoát khỏi cả
 *     ba nhánh trên và đi lên thành một trang 403 mang nguyên văn tiếng Anh "This action is
 *     unauthorized." — xem phần dưới cho lý do nó không phải một khả năng lý thuyết.
 *
 * **Không họ nào được ánh xạ sang một mã HTTP riêng, và đó là một phán quyết chứ không phải một
 * thiếu sót.** Các cổng TRẠNG THÁI của những Action này chạy TRƯỚC `Gate` (xem `PublishDocument`
 * và `OpensChecklistItem`), nên một mã HTTP riêng cho `DomainException` sẽ phân biệt được "bản
 * ghi này tồn tại nhưng đang ở trạng thái khác" với "không có bản ghi nào như vậy" — đúng cái
 * máy dò sự tồn tại mà SPEC §10.10 cấm và M3 đã dẹp bằng `AnswerDeniedPanelRequestsWithNotFound`.
 *
 * **Vì sao `AuthorizationException` bắt Ở ĐÂY chứ không đổi thành `DomainException` trong từng
 * Action.** Nó bắn ra từ những lần HỎI LẠI QUYỀN mà M4 thêm vào sau cửa sổ quét virus
 * (`UploadStaffDocument` bước 5, tới 30 giây), cộng các `Gate::authorize()` của `PublishDocument`
 * và `RegroupDocument`. Nó bắn đúng lúc nó tồn tại để bắn: hồ sơ bị xoá mềm, người nộp bị gỡ
 * khỏi đội ngũ, hoặc quyền công bố bị thu hồi TRONG lúc quét — và người dùng nhận về một chuỗi
 * tiếng Anh trên một request `update` của Livewire, đúng trường hợp mà
 * `AnswerDeniedPanelRequestsWithNotFound` tự ghi là nó không phủ được, kèm việc mất luôn một tệp
 * đã quét xong.
 *
 * Ba lý do chọn chỗ này:
 *
 *  - Hợp đồng bị vi phạm là hợp đồng CỦA TRAIT NÀY ("mọi lời từ chối đến được mắt người dùng
 *    bằng tiếng Việt"), không phải của Action. Action từ chối đúng; cách một lời từ chối được
 *    VẼ RA là việc của màn hình — đó là toàn bộ lý do trait này tồn tại.
 *  - Đổi trong Action thì mỗi Action phải phân biệt "cổng vào" với "hỏi lại", tức cùng một câu
 *    hỏi phân quyền ném ra hai lớp exception khác nhau tuỳ chỗ gọi, và phải chép `try/catch`
 *    quanh từng `Gate::authorize()` ở ba Action. Bắt ở đây phủ cả ba cộng mọi `Gate` thêm vào
 *    sau này, kể cả `throw new AuthorizationException` trần ở `UploadStaffDocument` bước 5.
 *  - Thông điệp KHÔNG lấy từ exception mà lấy từ `lang/vi/actions.php`: một câu duy nhất cho mọi
 *    nguyên nhân, nên nó không phân biệt được "không có quyền" với "không tồn tại" (SPEC §10.10).
 *
 * Cái nó KHÔNG làm: nó không thay `->authorize()` của từng nút Filament (cổng hiển thị) và không
 * thay `AnswerDeniedPanelRequestsWithNotFound` (cổng của cả TRANG, nơi 404 mới là câu trả lời
 * đúng của SPEC §10.10). Nó chỉ phủ đúng khoảng mà hai thứ kia không với tới: một Action đã chạy
 * và đổi ý giữa chừng.
 *
 * Bất cứ thứ gì KHÔNG thuộc bốn họ trên vẫn thoát ra thành lỗi 500. Cố ý: một `TypeError` hay một
 * lỗi hạ tầng không phải một câu để nói với người dùng, và nuốt nó ở đây sẽ biến một sự cố thật
 * thành một thông báo màu đỏ mà không ai đi điều tra.
 */
trait ReportsActionFailures
{
    /**
     * @param  Closure(): mixed  $callback  lời gọi Action, không có gì khác
     * @param  string|null  $fileField  tên ô tệp trong schema của action này, nếu có
     */
    protected function runAction(Action $action, Closure $callback, ?string $fileField = null): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            $this->failWithFieldErrors($action, $exception->errors());
        } catch (FileRejected $exception) {
            // Hai hàm dưới đây đều KẾT THÚC bằng một exception (`Halt` hoặc `ValidationException`),
            // nên không lời gọi nào ở đây rơi xuống dòng sau.
            $fileField === null
                ? $this->failWithNotification($action, $exception->getMessage())
                : $this->failWithFieldErrors($action, [$fileField => [$exception->getMessage()]]);
        } catch (DomainException $exception) {
            $this->failWithNotification($action, $exception->getMessage());
        } catch (AuthorizationException) {
            // Thông điệp KHÔNG lấy từ exception: `Gate::authorize()` ném "This action is
            // unauthorized." — một chuỗi tiếng Anh của framework, đúng thứ hợp đồng của trait
            // này tồn tại để chặn. Câu thay thế nằm ở `lang/vi/actions.php`, một câu duy nhất
            // cho mọi nguyên nhân (SPEC §10.10 — xem bình luận tại khoá đó).
            $this->failWithNotification($action, __('actions.unauthorized'));
        }
    }

    /**
     * Đổi các khoá trần của Action thành state path thật của modal đang mở, rồi ném lại —
     * Livewire giữ nguyên dữ liệu người dùng đã nhập khi một action ném `ValidationException`,
     * nên modal ở lại và người dùng sửa ngay tại chỗ.
     *
     * **Đo được, vì bản đầu của đoạn này nói sai về việc mình làm gì.** Ném thẳng
     * `ValidationException` của Action lên với khoá TRẦN thì Livewire ghi nó vào error bag đúng
     * bằng khoá trần đó (`rejection_reason`), còn đi qua đây thì nó thành
     * `mountedActions.0.data.rejection_reason` — chạy thử cả hai và đọc error bag. Khoá thứ hai
     * mới là state path của ô trong modal, nên đây là việc thật, không phải trang trí.
     *
     * **Khoá nào KHÔNG ứng với một ô trong modal thì đi bằng `Notification`.** Không có nhánh
     * này, một câu lỗi gắn vào một ô không tồn tại sẽ vào error bag rồi biến mất khỏi màn hình.
     * Nói thẳng phạm vi của nó: hôm nay KHÔNG đường nào từ hai màn hình M4 sinh ra một khoá như
     * vậy — mọi khoá mà `UploadStaffDocument` và `ReviewChecklistItem` ném ra (`file`, `title`,
     * `issued_at`, `matter_checklist_item_id`, `rejection_reason`) đều có ô tương ứng, và khoá
     * `status` của `ReviewChecklistItem` không với tới được vì màn hình luôn truyền một enum hợp
     * lệ. Vì vậy nhánh này KHÔNG có test làm nó đỏ được, và một mutation probe xoá điều kiện
     * `in_array` đã để cả bộ test xanh. Nó ở đây cho đường vào tiếp theo, không phải vì nó đang
     * chặn gì.
     *
     * @param  array<string, array<int, string>>  $errors
     */
    private function failWithFieldErrors(Action $action, array $errors): void
    {
        $fields = $this->mountedActionFieldNames();
        $bound = [];
        $unbound = [];

        foreach ($errors as $field => $messages) {
            if (in_array($field, $fields, true)) {
                $bound[$this->actionErrorKey($field)] = $messages;

                continue;
            }

            $unbound = [...$unbound, ...$messages];
        }

        if ($unbound !== []) {
            $this->sendFailureNotification(implode("\n", $unbound));
        }

        // `halt()` ném `Filament\Actions\Exceptions\Halt`, nên nhánh này không rơi xuống dòng
        // dưới: không khoá nào bám được vào một ô thì câu đã đi bằng `Notification` ở trên rồi.
        if ($bound === []) {
            $action->halt();
        }

        throw ValidationException::withMessages($bound);
    }

    private function failWithNotification(Action $action, string $message): void
    {
        $this->sendFailureNotification($message);

        // `halt()` chứ không `cancel()`: modal ở lại với dữ liệu đã nhập, và thông báo "đã lưu
        // xong" của chính action KHÔNG được gửi. Không có nó, một lần từ chối vẫn hiện ra một
        // dòng xanh báo thành công ngay cạnh dòng đỏ báo lỗi.
        $action->halt();
    }

    private function sendFailureNotification(string $message): void
    {
        Notification::make()
            ->title(__('actions.failed_title'))
            ->body($message)
            ->danger()
            ->persistent()
            ->send();
    }

    /**
     * `{mountedActionSchemaN}.{field}` — cùng công thức `PartiesRelationManager::errorKey()` và
     * `Filament\Forms\Testing\TestsForms::assertHasFormErrors()` dùng: mượn tên schema từ chính
     * action đang mounted thay vì tự đoán chỉ số, vì action lồng được vào nhau.
     */
    private function actionErrorKey(string $field): string
    {
        $statePath = $this->mountedActionStatePath();

        return $statePath === null ? $field : "{$statePath}.{$field}";
    }

    /** @return array<int, string> tên các ô trong modal đang mở (kể cả ô đang ẩn). */
    private function mountedActionFieldNames(): array
    {
        $schemaName = $this->getMountedActionSchemaName();

        if ($schemaName === null) {
            return [];
        }

        return array_keys($this->getSchema($schemaName)?->getFlatFields(withHidden: true) ?? []);
    }

    private function mountedActionStatePath(): ?string
    {
        $schemaName = $this->getMountedActionSchemaName();
        $statePath = $schemaName !== null ? $this->getSchema($schemaName)?->getStatePath() : null;

        return filled($statePath) ? $statePath : null;
    }
}
