<?php

namespace App\Events;

use App\Models\Document;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatch ở SPEC §6.5 bước 5, khi `PublishDocument` vừa đưa LẦN ĐẦU một tài liệu nhóm B hoặc C
 * ra tới khách. Listener (gửi email và thông báo trong hệ thống) thuộc M6 — không viết ở đây.
 *
 * **Chỉ lần công bố đầu tiên.** Gọi lại `PublishDocument` trên một tài liệu đã `published` là một
 * lần đổi hai cờ cho khách (ví dụ rút quyền tải), không phải một tài liệu mới; một sự kiện thứ hai
 * ở đó sẽ thành một email "văn phòng vừa gửi anh/chị một văn bản" cho thứ khách đã đọc từ tuần
 * trước. Chống gửi trùng bằng `notified_at` là việc của listener M6, nhưng listener đó chưa tồn
 * tại, nên điều kiện phải đứng được ở đây trước đã.
 *
 * `ShouldDispatchAfterCommit`, cùng lý lẽ với `StageLogPublished` và vì đúng một lý do cụ thể:
 * `PublishDocument` dispatch sự kiện này BÊN TRONG transaction của nó, và caller (một trang
 * Filament ở Task 6) có thể bọc thêm một transaction nữa ở ngoài. Nếu transaction ngoài cùng
 * rollback mà sự kiện đã chạy đồng bộ, khách nhận được thông báo về một tài liệu mà `status` của
 * nó đã quay về `internal_draft` — tức một tài liệu khách không mở được và văn phòng không biết
 * là đã hứa. Interface này hoãn dispatch tới sau khi transaction NGOÀI CÙNG commit thật.
 */
class DocumentPublished implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Document $document) {}
}
