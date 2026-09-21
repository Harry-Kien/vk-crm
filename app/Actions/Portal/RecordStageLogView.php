<?php

namespace App\Actions\Portal;

use App\Actions\Concerns\ChecksAccountActive;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\StageLogView;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;

/**
 * Ghi biên bản "khách đã được cho xem dòng cập nhật này" — SPEC §4.18.
 *
 * **Bảng này là bằng chứng, không phải số liệu thống kê.** Nó là thứ văn phòng đưa ra để chứng
 * minh mình ĐÃ BÁO cho khách hàng, nên hai câu dưới đây là hợp đồng của Action, không phải chi
 * tiết cài đặt:
 *
 *  1. **Một dòng cho mỗi cặp `(stage_log_id, client_user_id)`, ghi ở LẦN XEM ĐẦU.**
 *  2. **`viewed_at` không bao giờ bị ghi đè.** Lần xem thứ hai, thứ mười, không đổi gì. Ghi đè
 *     là xoá mất đúng con số mà bảng này tồn tại để giữ: khách biết chuyện này từ bao giờ. Một
 *     `updateOrCreate` ở đây sẽ biến một bằng chứng đứng vững thành một dòng chỉ nói "lần cuối
 *     họ mở trang", và không ai đọc lại nó sẽ nhận ra sự khác nhau.
 *
 * **Lúc nào thì gọi — cách đọc đã chốt.** Ghi khi khách MỞ TRANG CHI TIẾT hồ sơ và dòng đã công
 * bố đó thật sự được render, **không** khi dòng chỉ xuất hiện trong một danh sách (phán quyết
 * của người điều phối M5, 19/09/2026). "Đã lướt qua trong một danh sách" không phải "đã được cho
 * xem cập nhật", và nếu có ngày bảng này phải đứng trước một người phản biện thì cách đọc rộng
 * hơn sẽ bị bẻ ngay ở câu hỏi đầu tiên. Nơi gọi là `MatterProgress` (Task 4), và trang đó có
 * nghĩa vụ nhắc lại cách đọc này trong docblock của nó.
 *
 * **Không đọc `auth()`, và mọi truy vấn bỏ `ClientPortalScope` ra tường minh** — cùng kỷ luật
 * với các Action tài liệu của M4, xem {@see ReadsWithoutPortalScope}. Danh tính người xem là
 * `$actor`, truyền vào; `$ip` cũng truyền vào được, để một lần gọi lại từ console không ghi bừa
 * một địa chỉ.
 *
 * **Không tin tham số.** `$stageLog` đi ra từ một tham số trên URL của portal, nên Action đọc
 * lại dòng thật và mọi quyết định dùng bản đọc lại đó.
 *
 * **Không ghi một dòng `Audit`.** SPEC §10.6 liệt kê những việc VĂN PHÒNG làm; việc này thì
 * khách làm, và chính bảng `stage_log_views` đã là dấu vết của nó — đầy đủ hơn một dòng nhật ký
 * (nó mang `viewed_at` và `ip`, và nó là thứ nhãn "Khách đã xem" ở SPEC §7.2 đọc ra). Ghi thêm
 * một dòng nhật ký cho mỗi lần render một trang chi tiết cũng sẽ làm nhật ký kiểm toán ngập
 * trong những dòng không ai đi tìm.
 */
class RecordStageLogView
{
    use ChecksAccountActive;
    use ReadsWithoutPortalScope;

    /** Khi không có request nào để hỏi (console, job). Cột `ip` là `NOT NULL` (SPEC §4.18). */
    private const UNKNOWN_IP = '0.0.0.0';

    public function handle(StageLog $stageLog, ClientUser $actor, ?string $ip = null): StageLogView
    {
        // Đọc lại dòng thật. `find()` trả `null` cho một id bịa — và cho một dòng của khách khác
        // thì không, cố ý: phân biệt hai tình huống đó là việc của cổng quyền ngay dưới, và cả
        // hai đi ra bằng cùng một câu (SPEC §10.10, xem {@see self::refuse()}).
        $log = $this->scopelessly(StageLog::query())->find($stageLog->getKey()) ?? $this->refuse();

        // Nạp sẵn vụ việc bằng một truy vấn đã bỏ scope TRƯỚC khi đưa đối tượng cho `Gate`. Bài
        // học đo được ở `SubmitClientDocument`: một quan hệ nạp lười chạy dưới guard NÀO ĐANG
        // MỞ, nên với một phiên portal của khách hàng khác nó trả `null` và một lần ghi hợp lệ
        // bị từ chối oan. Chiều ngược lại vẫn an toàn — `ChecksPortalVisibility` hỏi lại scope
        // thật bằng `ClientPortalScope::actingAs($actor)`.
        $log->setRelation('matter', $this->scopelessly(Matter::query())->find($log->matter_id));

        // SPEC §10.9: một tài khoản vừa bị vô hiệu hoá không ghi được gì nữa. Ở đây điều đó còn
        // có một nghĩa riêng — một biên bản mang tên một tài khoản đã bị khoá là một bằng chứng
        // tự mâu thuẫn. Xem {@see ChecksAccountActive}.
        if (! $this->accountIsActive($actor)) {
            $this->refuse();
        }

        // Cổng quyền đi KÈM ngữ cảnh. `StageLogViewPolicy::create()` có một nhánh không ngữ cảnh
        // trả `true` (nó chỉ trả lời câu hỏi giao diện), nên hỏi trống ở đây sẽ là một cái cổng
        // luôn mở — đúng hình dạng lỗ hổng `DocumentPolicy::create()` mà M4 phải vá.
        //
        // `inspect()` chứ không `authorize()`: `Gate::authorize()` ném thông điệp mặc định của
        // Laravel, "This action is unauthorized.", tiếng Anh, viết cho lập trình viên. SPEC §8
        // cấm đúng kiểu câu đó trên màn hình khách hàng.
        if (Gate::forUser($actor)->inspect('create', [StageLogView::class, $log])->denied()) {
            $this->refuse();
        }

        return $this->recordOnce($log, $actor, $ip);
    }

    /**
     * Đường chạy đua hai tab mở cùng lúc.
     *
     * `firstOrCreate()` đọc trước rồi mới ghi, và giữa hai bước đó có một cửa sổ — hai tab của
     * cùng một khách mở cùng một hồ sơ là chuyện thường ngày, không phải một tình huống hiếm.
     * Bảng đã có `unique(stage_log_id, client_user_id)` (migration
     * `2026_09_14_000008_create_stage_log_views_table`), nên cửa sổ đó kết thúc bằng một lỗi
     * trùng khoá chứ không bằng một dòng thứ hai. **Một lần `exists()` trước khi insert không
     * chữa được việc này** — nó chỉ làm cửa sổ hẹp lại.
     *
     * Khối `catch` ở đây là **lưới thứ hai**, và nói cho đúng: lưới thứ nhất nằm trong framework.
     * `Builder::firstOrCreate()` gọi `createOrFirst()`, và hàm đó đã tự bắt
     * `UniqueConstraintViolationException` rồi đọc lại bằng `useWritePdo()`
     * (`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php:751`) — đã đọc
     * trên bản đang cài, không phải nhớ. Nên trên đường đi hôm nay, `catch` dưới đây KHÔNG chạy
     * tới; nó là phòng thủ nhiều lớp, không phải một điều kiện có test đứng sau. **Đo bằng
     * mutation:** đổi thân `catch` thành `throw $exception;` thì bộ test vẫn XANH — đúng như câu
     * trên nói, và câu trên được viết ra để không ai đọc khối này rồi tưởng nó là thứ đang giữ
     * cuộc đua. Giữ lại vì lần đọc lại của framework kết thúc bằng `?? throw $e`, và vì hợp đồng
     * "một dòng, dấu thời gian của lần đầu" của Action này không nên phụ thuộc vào một chi tiết
     * bên trong framework.
     *
     * Thứ CÓ test đứng sau là KẾT QUẢ: một dòng duy nhất, mang `viewed_at` và `ip` của lần ghi
     * ĐẦU — xem `RecordStageLogViewTest`, test "giữ biên bản đầu tiên khi hai tab cùng ghi". Đo
     * cả hai chiều: thay `firstOrCreate()` bằng `create()` làm test đó đỏ, và thay bằng
     * `updateOrCreate()` làm HAI test đỏ (test đó, và "không bao giờ dời `viewed_at`") —
     * `updateOrCreate` vẫn để lại đúng MỘT dòng, nên phép đếm không bắt được nó; chỉ dấu thời
     * gian mới bắt được.
     */
    private function recordOnce(StageLog $log, ClientUser $actor, ?string $ip): StageLogView
    {
        $identity = [
            'stage_log_id' => $log->getKey(),
            'client_user_id' => $actor->getKey(),
        ];

        try {
            return $this->receipts()->firstOrCreate($identity, [
                'viewed_at' => now(),
                'ip' => $ip ?? request()->ip() ?? self::UNKNOWN_IP,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            return $this->receipts()->where($identity)->first() ?? throw $exception;
        }
    }

    /** @return Builder<StageLogView> */
    private function receipts()
    {
        return $this->scopelessly(StageLogView::query());
    }

    /**
     * **Bốn tình huống, MỘT câu** — SPEC §10.10. Dòng tiến độ không tồn tại, chưa được công bố,
     * thuộc một hồ sơ của khách hàng khác (hoặc một hồ sơ đã bị gỡ khỏi portal), và tài khoản đã
     * bị vô hiệu hoá: cả bốn đi ra từ đúng dòng `throw` này, nên chúng không phân biệt được ở
     * tên lớp lẫn ở câu chữ. Một cặp thông điệp khác nhau chính là cái máy dò sự tồn tại mà
     * §10.10 dựng lên để chặn.
     *
     * `AuthorizationException` chứ không `ValidationException`: tầng HTTP của Laravel đổi nó
     * thành 403, và middleware `AnswerDeniedPanelRequestsWithNotFound` đổi tiếp thành 404 trên
     * request tải trang của panel. Câu chữ bằng tiếng Việt, từ `lang/vi/matters.php`: một
     * `DomainException` hay một câu tiếng Anh lọt ra màn hình khách là lỗi mà M3 và M4 đều đã
     * mắc một lần.
     */
    private function refuse(): never
    {
        throw new AuthorizationException(__('matters.stage_log_views.unavailable'));
    }
}
