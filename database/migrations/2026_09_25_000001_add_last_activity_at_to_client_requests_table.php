<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `client_requests.last_activity_at` — mốc "hoạt động gần nhất" mà hộp thư của SPEC §7.2
 * (`ClientRequestsRelationManager`) sắp theo, thay cho `created_at` (Task 18, REQ-2).
 *
 * **Vì sao một CỘT, không phải một truy vấn con trên `client_request_replies`.** "Hoạt động"
 * của một luồng có BỐN nguồn — khách gửi yêu cầu, khách hỏi tiếp, nhân sự trả lời, nhân sự đổi
 * trạng thái — và chỉ hai trong bốn (viết tiếp) đi qua bảng `client_request_replies`. Một luật sư
 * trả lời qua điện thoại rồi bấm thẳng "Đã trả lời" (`TriageClientRequest::setStatus`, ca hợp lệ
 * ghi ở docblock lớp đó) không sinh dòng `client_request_replies` nào — REQ-5 cùng milestone đo
 * đúng ca này. Một `MAX(client_request_replies.created_at)` bỏ sót lần đổi trạng thái đó, và một
 * luồng vừa được xử lý xong bằng cách đó sẽ nằm im ở đáy hộp thư. `updated_at` của chính
 * `client_requests` thì lại RỘNG hơn "hoạt động" cần đo: nó cũng nhảy khi `TriageClientRequest::
 * assign()` đổi người xử lý — một việc quản trị, không phải một lượt trao đổi — nên dùng nó sẽ
 * đẩy một luồng im lặng lên đầu chỉ vì vừa được giao cho ai đó.
 *
 * Vì vậy cột này được các Action tự tay cập nhật ở ĐÚNG bốn đường — `OpenClientRequest::handle()`
 * (yêu cầu mới), `ReplyToClientRequest::advanceStatus()` (cả hai chiều: khách hỏi tiếp, nhân sự
 * trả lời) và `TriageClientRequest::setStatus()` (đổi trạng thái tay) — và KHÔNG được đụng ở
 * `TriageClientRequest::assign()`.
 *
 * `nullable()`: không có `NOT NULL` vì backfill bên dưới chạy SAU khi cột đã tồn tại, và một hàng
 * lạ chưa kịp backfill không được phép làm cả migration đổ vỡ. Ba đường tạo mới (Action ở trên)
 * luôn ghi giá trị, nên trên dữ liệu sống cột này không bao giờ `null` sau lần chạy này.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_requests', function (Blueprint $table) {
            $table->timestamp('last_activity_at')->nullable()->after('answered_at');
        });

        // Backfill cho các luồng đã có: hoạt động gần nhất là MAX(lần trả lời) nếu có luồng trả
        // lời, hoặc `updated_at` của chính hàng nếu không — `updated_at` đã bao gồm mọi lần
        // `save()` quá khứ (gán người xử lý, đổi trạng thái), nên nó là một cận trên an toàn hơn
        // `created_at` cho dữ liệu backfill (không có nhật ký đủ chi tiết để tính lại chính xác
        // việc dữ liệu cũ).
        DB::table('client_requests')->select('id', 'updated_at')->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $lastReplyAt = DB::table('client_request_replies')
                        ->where('request_id', $row->id)
                        ->max('created_at');

                    $lastActivityAt = ($lastReplyAt !== null && $lastReplyAt > $row->updated_at)
                        ? $lastReplyAt
                        : $row->updated_at;

                    DB::table('client_requests')->where('id', $row->id)
                        ->update(['last_activity_at' => $lastActivityAt]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('client_requests', function (Blueprint $table) {
            $table->dropColumn('last_activity_at');
        });
    }
};
