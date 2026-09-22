<?php

namespace App\Actions\Portal;

use App\Actions\Concerns\ChecksAccountActive;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\ClientRequestStatus;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Khách hàng mở một cuộc trao đổi mới về một vụ việc — SPEC §4.14, §8.3 mục 7.
 *
 * **Một yêu cầu là một LUỒNG, và Action này sinh ra đầu luồng.** Phán quyết ngày 19/09/2026: khách
 * viết tiếp vào một yêu cầu đã gửi thì đó là một dòng `client_request_replies`, không phải một
 * `ClientRequest` thứ hai ({@see ReplyToClientRequest}). Nên chỗ duy nhất một hàng
 * `client_requests` ra đời là đây, và nó ra đời với `status = new`: chưa ai trong văn phòng nhìn
 * thấy nó.
 *
 * # `$actor` chỉ có thể là một `ClientUser`, và đó là một câu về nghiệp vụ
 *
 * SPEC §5 phần Portal cho khách "tạo và xem `ClientRequest` của chính mình"; SPEC §7.2 cho văn
 * phòng một **hộp thư** — nhận, trả lời, đổi trạng thái, giao việc — chứ không cho họ mở một yêu
 * cầu thay khách. Một hàng `client_requests` do nhân sự tạo ra là một câu hỏi văn phòng tự đặt
 * cho mình rồi tự trả lời, và nó không phân biệt được với câu hỏi thật của khách trong cùng một
 * hộp thư. `client_user_id` là `NOT NULL` ở SPEC §4.14, nên mô hình dữ liệu nói cùng một điều.
 *
 * Vì vậy kiểu tham số ở đây hẹp (`ClientUser`), khác {@see ReplyToClientRequest} — nơi cả hai
 * phía đều viết, và nơi lý do chọn MỘT Action cho hai phía được ghi ra đầy đủ.
 * `ClientRequestPolicy::create()` vẫn có nhánh nhân sự và nó vẫn đúng: nó trả lời câu hỏi giao
 * diện "ai được ghi vào cuộc trao đổi của vụ việc này", và {@see ReplyToClientRequest} hỏi đúng
 * nhánh ấy.
 *
 * # Cổng quyền LUÔN đi kèm `Matter` — nghĩa vụ mang sang từ rà soát M4
 *
 * `ClientRequestPolicy::create()` có một nhánh KHÔNG ngữ cảnh, và nhánh ấy trả `true` cho **mọi**
 * `ClientUser` một cách vô điều kiện. Đó là cố ý: nó chỉ trả lời câu hỏi giao diện "màn hình có
 * vẽ ô gửi yêu cầu không". Rà soát M4 Task 2 ghi lại rằng nghĩa vụ "luôn hỏi kèm vụ việc" của
 * `ClientRequest` **chưa được viết ở đâu cả** — nó chỉ được viết cho `Document`. Đây là chỗ nghĩa
 * vụ đó được thực hiện và được ghi ra: hỏi `Gate::forUser($actor)->inspect('create',
 * [ClientRequest::class, $target])`, không bao giờ hỏi trống — và `$target` là **bản đã đọc
 * lại**, không phải đối tượng caller đưa vào. Một lời gọi trống ở đây là một cái cổng luôn mở,
 * đúng hình dạng lỗ hổng `DocumentPolicy::create()` mà M4 phải vá.
 *
 * # Không đọc `auth()`, không tin tham số
 *
 * Cùng kỷ luật với `SubmitClientDocument` và `RecordStageLogView`: danh tính người gửi là
 * `$actor`, truyền vào tường minh; `$matter` đi ra từ một tham số trên URL nên Action đọc lại
 * hàng thật bằng một truy vấn đã gỡ `ClientPortalScope` và mọi quyết định dùng bản đọc lại đó.
 * Một Action để phạm vi dữ liệu phụ thuộc vào guard nào đang mở là một Action đúng cho tới lần
 * đầu ai đó gọi nó từ một job, một lệnh console, hay một phiên thuộc về người khác.
 *
 * SPEC §10.9 được hỏi ở đây vì lý do đã ghi ở {@see ChecksAccountActive}: một tài khoản vừa bị
 * vô hiệu hoá không ghi thêm được gì vào hồ sơ.
 *
 * # Nhật ký
 *
 * SPEC §10.6 liệt kê những việc VĂN PHÒNG làm, và "khách gửi một yêu cầu" không nằm trong danh
 * sách đó — nhưng dòng nhật ký ở đây vẫn phải có, vì cùng lý do hẹp mà `SubmitClientDocument` ghi
 * ra: trait `LogsActivity` KHÔNG được bật trên `ClientRequest`, nên không có dấu vết nào khác nêu
 * đích danh tài khoản khách hàng đã gửi. `$causer` truyền tường minh chứ không để
 * `Audit::record()` suy từ phiên: helper đó ưu tiên guard `web`, nên trên một máy đang mở cả
 * /admin lẫn /portal (chuyện thường ngày lúc demo, và trên máy dùng chung) một yêu cầu của khách
 * sẽ bị ghi tên một nhân sự. Đúng cái bẫy mà vòng rà soát Task 1 đo được ở luồng đăng nhập.
 */
class OpenClientRequest
{
    use ChecksAccountActive;
    use ReadsWithoutPortalScope;

    /** SPEC §4.14: `subject` là `string(200)`. */
    public const SUBJECT_MAX = 200;

    /**
     * Trần của `content`. Cột là `text` nên cơ sở dữ liệu chịu được nhiều hơn rất nhiều; con số
     * này là một cái phanh cho ô nhập, không phải một giới hạn của mô hình dữ liệu. Nó tồn tại
     * để một lần dán nhầm cả một tệp vào ô nhập trở thành một câu tiếng Việt chỉ ra việc phải
     * làm, thay vì một hàng khổng lồ mà hộp thư của SPEC §7.2 không hiển thị nổi.
     */
    public const CONTENT_MAX = 5000;

    public function handle(Matter $matter, ClientUser $actor, string $subject, string $content): ClientRequest
    {
        // Đọc lại hàng thật trước khi làm bất cứ việc gì. `find()` trả `null` cho một id bịa — và
        // cho một vụ việc của khách hàng khác thì KHÔNG, cố ý: phân biệt hai tình huống đó là
        // việc của cổng quyền ngay dưới, và cả hai đi ra bằng cùng một câu ({@see self::refuse()}).
        $target = $this->scopelessly(Matter::query())->find($matter->getKey()) ?? $this->refuse();

        if (! $this->accountIsActive($actor)) {
            $this->refuse();
        }

        // KÈM vụ việc — xem docblock lớp. `inspect()` chứ không `authorize()`: `Gate::authorize()`
        // ném "This action is unauthorized.", một câu tiếng Anh viết cho lập trình viên, và SPEC
        // §8 cấm đúng kiểu câu đó trên màn hình khách hàng.
        if (Gate::forUser($actor)->inspect('create', [ClientRequest::class, $target])->denied()) {
            $this->refuse();
        }

        [$subject, $content] = $this->validated($subject, $content);

        $request = ClientRequest::query()->create([
            'matter_id' => $target->getKey(),
            'client_user_id' => $actor->getKey(),
            'subject' => $subject,
            'content' => $content,
            // Không có tham số nào của khách chạm tới ba cột dưới đây. Trạng thái luôn bắt đầu ở
            // `new` ("chưa ai trong văn phòng nhìn thấy"), người xử lý do văn phòng giao, và
            // `answered_at` chỉ được ghi khi văn phòng thật sự trả lời.
            'status' => ClientRequestStatus::New,
            'assigned_to' => null,
            'answered_at' => null,
        ]);

        // `client_id` chép thẳng vào đây chứ không để người đọc suy ra qua `matter`:
        // `matters.client_id` là một cột sửa được, nên một hồ sơ chuyển sang khách hàng khác sẽ
        // viết lại lịch sử của mọi yêu cầu đã gửi. Cùng lý lẽ với `SubmitClientDocument`.
        Audit::record('client_request_opened', $request, [
            'matter_id' => $target->getKey(),
            'client_id' => $target->client_id,
        ], causer: $actor);

        return $request;
    }

    /**
     * **Xác thực nằm ở Action, không chỉ ở màn hình.**
     *
     * Hai màn hình gọi Action này sẽ mọc ra theo thời gian, và một luật xác thực chỉ sống ở ô
     * nhập là một luật mà màn hình thứ hai không có. Quan trọng hơn: `subject` là `string(200)`
     * ở tầng cơ sở dữ liệu, nên một chuỗi dài hơn không đi ra bằng một câu tiếng Việt mà bằng
     * một lỗi của trình điều khiển cơ sở dữ liệu — trên MariaDB ở chế độ `strict` là một
     * `QueryException`, tức lỗi 500 trên màn hình một khách hàng.
     *
     * Khoá của `ValidationException` là tên TRẦN (`subject`, `content`), trùng tên thuộc tính
     * Livewire của trang cổng và tên ô trong modal của hộp thư nội bộ — `ReportsActionFailures`
     * dịch chúng sang state path thật khi câu lỗi phải hiện trong một modal.
     *
     * `trim()` xảy ra TRƯỚC khi đo: một ô toàn dấu cách là một ô trống, và `required` của Laravel
     * đã tự hiểu như vậy — dòng `trim()` ở đây để thứ được LƯU cũng đúng như thứ đã được đo.
     *
     * @return array{0: string, 1: string}
     */
    private function validated(string $subject, string $content): array
    {
        $values = ['subject' => trim($subject), 'content' => trim($content)];

        Validator::make(
            $values,
            [
                'subject' => ['required', 'string', 'max:'.self::SUBJECT_MAX],
                'content' => ['required', 'string', 'max:'.self::CONTENT_MAX],
            ],
            [
                'subject.required' => __('requests.validation.subject_required'),
                'subject.max' => __('requests.validation.subject_max', ['max' => self::SUBJECT_MAX]),
                'content.required' => __('requests.validation.content_required'),
                'content.max' => __('requests.validation.content_max', ['max' => self::CONTENT_MAX]),
            ],
        )->validate();

        return [$values['subject'], $values['content']];
    }

    /**
     * **Bốn tình huống, MỘT câu** — SPEC §10.10. Vụ việc không tồn tại, chưa được công bố lên
     * cổng, thuộc một khách hàng khác, và tài khoản đã bị vô hiệu hoá: cả bốn đi ra từ đúng dòng
     * `throw` này, nên chúng không phân biệt được ở tên lớp lẫn ở câu chữ. Một cặp thông điệp
     * khác nhau chính là cái máy dò sự tồn tại mà §10.10 dựng lên để chặn.
     *
     * `AuthorizationException` chứ không `DomainException`: nơi gọi đổi nó thành **404** (trang
     * cổng gọi `abort(404)` thẳng, vì `AnswerDeniedPanelRequestsWithNotFound` không phủ request
     * cập nhật Livewire — xem `MatterProgress`). Câu chữ tiếng Việt, từ `lang/vi/requests.php`.
     */
    private function refuse(): never
    {
        throw new AuthorizationException(__('requests.unavailable'));
    }
}
