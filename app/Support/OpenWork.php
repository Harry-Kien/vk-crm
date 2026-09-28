<?php

namespace App\Support;

use App\Actions\Matter\RemoveTeamMember;
use App\Enums\ClientRequestStatus;
use App\Models\ClientRequest;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;

/**
 * "Việc còn mở mà người này còn đứng tên" — MỘT định nghĩa, dùng chung cho Task 3
 * ({@see RemoveTeamMember}, giới hạn vào một vụ việc) và việc nghỉ việc của
 * Task 4 (hỏi trên TOÀN BỘ nhân sự, không giới hạn vụ việc — R7). Giữ chung ở đây thay vì viết
 * lại ba câu truy vấn ở hai chỗ để hai luồng đó không lệch nhau lần một trong ba điều kiện đổi.
 *
 * Ba loại việc mở, đúng SPEC §6.11 bước 1 liệt kê: còn là luật sư phụ trách của một vụ việc ĐANG
 * MỞ, còn đứng tên một mốc thời hạn CHƯA HOÀN THÀNH, còn được giao một yêu cầu khách CHƯA ĐÓNG.
 *
 * **"Vụ đang mở" nghĩa là `closed_at` null và chưa xoá mềm (R8).** M6.5 Task 5 thêm
 * `Matter::scopeOpen()` — đúng MỘT định nghĩa dùng chung toàn hệ thống, ghi bởi
 * `TransitionMatterStage` khi vụ việc vào/rời một giai đoạn `is_terminal` — và cả ba câu truy vấn
 * dưới đây gọi nó thay vì tự viết lại điều kiện, để hàm này và `scopeOpen()` không định nghĩa
 * "mở" theo hai cách.
 *
 * **Fix round 1, finding I2 — `deadlines`/`clientRequests` giờ cũng đòi vụ việc CÒN TỒN TẠI VÀ
 * ĐANG MỞ, không chỉ `leadMatters`.** Bản gốc chỉ lọc `matter_id` (khi `$matter` được truyền) mà
 * không hỏi gì về TRẠNG THÁI của vụ việc đứng sau mốc hạn/yêu cầu đó — một mốc hạn CHƯA XONG
 * thuộc một vụ việc ĐÃ ĐÓNG (không còn gì "dở dang" thật sự) hoặc ĐÃ XOÁ MỀM (không ai còn thao
 * tác được trên nó qua giao diện) vẫn bị đếm là việc mở, chặn nhầm một lần gỡ/nghỉ việc hợp lệ.
 * `whereHas('matter', fn ($q) => $q->open())` giải quyết CẢ HAI cùng lúc: `whereHas` tự áp
 * `SoftDeletingScope` mặc định của `Matter` (chỉ khớp một `matters.id` CHƯA xoá mềm — và
 * `scopeOpen()` tự khẳng định lại đúng điều kiện đó một lần nữa, xem docblock của nó), còn điều
 * kiện `closed_at` bên trong `scopeOpen()` đóng nốt vế "đang mở".
 *
 * **Không tự lọc `is_active`/`trashed()` của `$user`.** Người gọi (`RemoveTeamMember`, việc nghỉ
 * việc) tự quyết định có hỏi câu này cho một tài khoản đã bị vô hiệu hoá hay đã xoá mềm hay
 * không — hàm này chỉ trả lời đúng một câu "việc gì đang đứng tên người đó ngay bây giờ", không
 * phải "người đó có NÊN còn đứng tên hay không". Trộn hai câu hỏi vào một hàm sẽ khiến Task 4 (hỏi
 * đúng lúc tài khoản SẮP bị vô hiệu hoá, tức trước khi `is_active` đổi) không hỏi được câu nó cần.
 */
final class OpenWork
{
    /**
     * @param  Matter|null  $matter  Giới hạn cả ba loại việc vào ĐÚNG vụ việc này (Task 3, gỡ một
     *                               thành viên khỏi một vụ). Để `null` để hỏi trên TOÀN BỘ vụ việc
     *                               (Task 4, nghỉ việc — R7).
     */
    public static function forUser(User $user, ?Matter $matter = null): OpenWorkResult
    {
        return new OpenWorkResult(
            leadMatters: Matter::query()
                ->where('lead_lawyer_id', $user->getKey())
                ->open()
                ->when($matter !== null, fn ($query) => $query->whereKey($matter->getKey()))
                ->get(),
            deadlines: Deadline::query()
                ->where('responsible_user_id', $user->getKey())
                ->where('is_completed', false)
                ->whereHas('matter', fn ($query) => $query->open())
                ->when($matter !== null, fn ($query) => $query->where('matter_id', $matter->getKey()))
                ->with('matter')
                ->get(),
            clientRequests: ClientRequest::query()
                ->where('assigned_to', $user->getKey())
                ->where('status', '!=', ClientRequestStatus::Closed->value)
                ->whereHas('matter', fn ($query) => $query->open())
                ->when($matter !== null, fn ($query) => $query->where('matter_id', $matter->getKey()))
                ->with('matter')
                ->get(),
        );
    }
}
