<?php

namespace App\Exceptions;

use App\Actions\Portal\ReplyToClientRequest;
use App\Enums\ClientRequestStatus;
use DomainException;

/**
 * {@see ReplyToClientRequest} từ chối vì **TRẠNG THÁI** của cuộc trao đổi: yêu cầu đã đóng
 * (`ClientRequestStatus::Closed`) thì không ai viết thêm được vào đó nữa — không khách, không
 * nhân sự.
 *
 * **Vì sao điều kiện này ở Action chứ không ở policy.** Dự án tách hai câu hỏi ở mọi chỗ khác và
 * tách ở đây cũng vì đúng lý do ấy: policy trả lời "người này có được phép không", Action trả lời
 * "bản ghi này có đang ở trạng thái cho phép việc đó không". Lý lẽ đầy đủ nằm ở docblock
 * `ReviewChecklistItem::guardDecisionAgainstState()` và ở `ChecklistRelationManager` (nơi
 * `->authorize()` và `->visible()` là hai cổng cố ý rời nhau). Hệ quả cụ thể ở đây: một yêu cầu
 * đã đóng vẫn ĐỌC được — khách vẫn xem lại được cả cuộc trao đổi, đó là điều SPEC §8.3 mục 7
 * đòi — nó chỉ không nhận thêm chữ nào.
 *
 * **Vì sao đóng lại thì không viết tiếp được, thay vì mở lại cuộc trao đổi.** Bốn trạng thái của
 * SPEC §4.14 có một đường đi một chiều tới `closed`, và người bấm nút đóng là nhân sự văn phòng.
 * Nếu một dòng trả lời mới lặng lẽ mở nó ra lại thì cái nút kia không còn nghĩa gì; nếu nó KHÔNG
 * mở ra lại thì cuộc trao đổi tích thêm những câu không ai đọc, vì hộp thư của SPEC §7.2 sắp theo
 * lần trao đổi gần nhất chứ không có chỗ nào kêu lên "có dòng mới trong một yêu cầu đã đóng". Cả
 * hai đều tệ hơn việc nói thẳng với khách rằng việc này đã xong và mời họ gửi một yêu cầu mới —
 * một yêu cầu mới là một dòng mới trong hộp thư, nơi nó được nhìn thấy.
 *
 * **Hai người đọc, hai câu — và đó KHÔNG phải một ngoại lệ của SPEC §10.10.** §10.10 nói về
 * những lời từ chối có thể tiết lộ sự tồn tại của một bản ghi, và cổng này chạy SAU cổng quyền
 * nên nó đã ra khỏi phạm vi đó (xem đoạn cuối). Thứ còn lại là một câu hướng dẫn, và hướng dẫn
 * thì phải chỉ vào một việc người đọc làm được: {@see self::closed()} mời khách "gửi một yêu cầu
 * mới ở ô trên cùng" — cái ô đó chỉ có trên cổng khách hàng — còn {@see self::closedForStaff()}
 * chỉ vào nút "Đổi trạng thái" của hộp thư nội bộ, đường mở lại một việc đã đóng. In câu của
 * khách ra panel nội bộ (việc bản đầu của `ReplyToClientRequest` làm) là chỉ một luật sư tới một
 * cái ô không tồn tại, và là lần duy nhất `lang/vi/requests.php` phá lời tự giới thiệu của chính
 * nó rằng hai nửa của tệp không dùng chung chuỗi nào.
 *
 * `DomainException`, không phải `AuthorizationException`: câu này KHÔNG phải một lời từ chối vì
 * thiếu quyền, và nó cố ý nói ra sự thật ("cuộc trao đổi đã kết thúc") thay vì trốn sau câu chung
 * của SPEC §10.10. Điều đó an toàn vì người đọc nó đã được xác nhận là người ĐỌC ĐƯỢC yêu cầu
 * này — cổng quyền chạy trước cổng trạng thái, nên câu này không tiết lộ sự tồn tại của thứ gì
 * mà người đọc chưa nhìn thấy.
 */
class ClientRequestNotOpen extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /** Câu viết cho KHÁCH, vẽ trên cổng khách hàng. */
    public static function closed(): self
    {
        return new self(__('requests.portal.closed_notice'));
    }

    /**
     * Câu viết cho VĂN PHÒNG, vẽ trong panel nội bộ qua `ReportsActionFailures`.
     *
     * Cùng một cổng trạng thái, hai câu — xem phần "Hai người đọc" ở docblock lớp.
     */
    public static function closedForStaff(): self
    {
        return new self(__('requests.tab.closed_notice'));
    }

    /** Nhắc lại để nơi gọi không phải nhớ enum nào là cổng. */
    public static function accepts(ClientRequestStatus $status): bool
    {
        return $status !== ClientRequestStatus::Closed;
    }
}
