<?php

use App\Filament\Portal\Pages\MyRequests;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `client_requests.last_activity_at` — mốc "hoạt động gần nhất" mà CẢ HAI đầu của cuộc trao đổi
 * sắp theo (Task 18, REQ-2): hộp thư của văn phòng ({@see \App\Filament\Admin\Resources\Matters\
 * RelationManagers\ClientRequestsRelationManager}) VÀ danh sách luồng ở cổng khách
 * ({@see MyRequests::threads()}) — thay cho `created_at` (fix round 1,
 * ruling: cả hai màn hình phải sắp theo cùng một cột, không chỉ một bên).
 *
 * **Vì sao một CỘT, không phải một truy vấn con trên `client_request_replies`.** "Hoạt động" của
 * một luồng có BỐN nguồn — khách gửi yêu cầu, khách hỏi tiếp, nhân sự trả lời, nhân sự đổi trạng
 * thái — và chỉ hai trong bốn (viết tiếp) đi qua bảng `client_request_replies`. Một luật sư trả
 * lời qua điện thoại rồi bấm thẳng "Đã trả lời" (`TriageClientRequest::setStatus`, ca hợp lệ ghi ở
 * docblock lớp đó) không sinh dòng `client_request_replies` nào — REQ-5 cùng milestone đo đúng ca
 * này. Một `MAX(client_request_replies.created_at)` bỏ sót lần đổi trạng thái đó, và một luồng vừa
 * được xử lý xong bằng cách đó sẽ nằm im ở đáy hộp thư. `updated_at` của chính `client_requests`
 * thì lại RỘNG hơn "hoạt động" cần đo: nó nhảy ở MỌI lần `save()`, kể cả một lần `assign()` chỉ đổi
 * tay mà không đổi trạng thái — một việc quản trị, không phải một lượt trao đổi.
 *
 * **Ranh giới chính xác của "hoạt động" (fix round 1, ruling).** Cột này là lần trao đổi (client
 * hoặc staff) gần nhất, HOẶC một lần đổi trạng thái — không hơn. Bốn đường ghi:
 *
 *  - `OpenClientRequest::handle()` — yêu cầu mới, luôn ghi.
 *  - `ReplyToClientRequest::advanceStatus()` — cả hai chiều (khách hỏi tiếp, nhân sự trả lời),
 *    luôn ghi, kể cả khi cột `status` không đổi (khách hỏi tiếp vào một luồng `new`/`in_progress`
 *    vẫn là một lượt trao đổi).
 *  - `TriageClientRequest::setStatus()` — đổi trạng thái tay, luôn ghi (kể cả đặt lại đúng giá trị
 *    hiện tại: bấm Lưu là một lần nhân sự vừa động vào luồng).
 *  - `TriageClientRequest::assign()` — CHỈ ghi khi lần giao việc đó CŨNG đổi trạng thái (nhánh
 *    `new → in_progress`, "giao việc cũng là nhận" — xem docblock lớp của Action đó). Đổi tay một
 *    luồng ĐANG `in_progress` (không đổi trạng thái) thì KHÔNG ghi: đó là một việc quản trị thuần
 *    tuý, không phải một lượt trao đổi.
 *
 * `nullable()`: không có `NOT NULL` vì backfill bên dưới chạy SAU khi cột đã tồn tại, và một hàng
 * lạ chưa kịp backfill không được phép làm cả migration đổ vỡ. Bốn đường ghi ở trên luôn ghi giá
 * trị trên một luồng mới hoặc một luồng vừa có hoạt động, nên trên dữ liệu SỐNG cột này không bao
 * giờ `null` sau lần chạy này — `nullable()` chỉ tồn tại cho khoảnh khắc GIỮA lúc thêm cột và lúc
 * backfill chạy xong bên dưới.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_requests', function (Blueprint $table) {
            $table->timestamp('last_activity_at')->nullable()->after('answered_at');
        });

        // Backfill cho các luồng đã có: hoạt động gần nhất là GREATEST(created_at, MAX(lần trả
        // lời)) — KHÔNG dùng `updated_at` (fix round 1, ruling): cột đó rộng hơn "hoạt động" thật
        // (nhảy cả khi chỉ đổi người xử lý mà không đổi trạng thái, xem docblock lớp), nên dùng nó
        // để backfill sẽ đẩy một luồng im lặng lên trên chỉ vì lịch sử của nó từng được giao lại
        // tay nhiều lần. `created_at` LUÔN có giá trị (`NOT NULL`), nên kết quả không bao giờ
        // `null` — kể cả một luồng chưa từng có lượt trả lời nào.
        DB::table('client_requests')->select('id', 'created_at')->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $lastReplyAt = DB::table('client_request_replies')
                        ->where('request_id', $row->id)
                        ->max('created_at');

                    $lastActivityAt = ($lastReplyAt !== null && $lastReplyAt > $row->created_at)
                        ? $lastReplyAt
                        : $row->created_at;

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
