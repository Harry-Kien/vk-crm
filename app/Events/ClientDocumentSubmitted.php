<?php

namespace App\Events;

use App\Models\Document;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatch ở SPEC §6.6 bước 9, khi một khách hàng vừa nộp một tệp vào một đầu mục danh mục hồ sơ.
 * Listener (thông báo trong hệ thống cho luật sư phụ trách và trợ lý) thuộc M6 — không viết ở đây.
 *
 * Người nhận thông báo là ĐỘI NGŨ, không phải khách: khách vừa bấm nút và đã thấy trạng thái
 * "Đang chờ văn phòng kiểm tra" (SPEC §8.4), còn thứ chưa ai trong văn phòng biết là có một tệp
 * mới đang chờ. Đội ngũ đọc ra từ `$document->matter` (luật sư phụ trách cộng `matter_user`), nên
 * sự kiện không chép sẵn một danh sách người nhận có thể đã cũ khi listener chạy.
 *
 * `ShouldDispatchAfterCommit`, cùng lý lẽ với `DocumentPublished`: `SubmitClientDocument` dispatch
 * sự kiện này BÊN TRONG transaction của nó, và một màn hình portal ở M5 có thể bọc thêm một
 * transaction nữa ở ngoài. Nếu transaction ngoài cùng rollback mà sự kiện đã chạy đồng bộ, đội
 * ngũ nhận thông báo về một tệp không tồn tại, rồi đi tìm nó trong danh mục hồ sơ.
 */
class ClientDocumentSubmitted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Document $document) {}
}
