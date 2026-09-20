<?php

namespace App\Events;

use App\Models\MatterChecklistItem;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatch khi `ReviewChecklistItem` từ chối một giấy tờ khách đã nộp (SPEC §6.7). Listener gửi
 * mẫu email `client.document_rejected` ở SPEC §9 thuộc M6 — không viết ở đây.
 *
 * **Chỉ nhánh từ chối.** Bảng mẫu email ở SPEC §9 không có mẫu nào cho một lần duyệt đạt, và đó
 * là một quyết định chứ không phải một chỗ thiếu: một hồ sơ có mười đầu mục sẽ sinh mười email
 * "giấy tờ của anh/chị đã đạt", và một hộp thư như vậy là hộp thư người ta thôi mở — kể cả khi
 * email thứ mười một là email báo còn thiếu giấy tờ. Tiến độ `X/Y` trên portal (SPEC §8.2) đã
 * nói được việc đó mà không cần gửi gì.
 *
 * Sự kiện mang theo đầu mục chứ không mang theo lý do: lý do nằm ở `rejection_reason` của chính
 * đầu mục, và chép nó vào đây tạo ra một bản thứ hai có thể lệch với bản khách đọc trên portal —
 * đúng câu mà email và portal phải nói giống hệt nhau.
 *
 * `ShouldDispatchAfterCommit`, cùng lý lẽ với `DocumentPublished` và `ClientDocumentSubmitted`:
 * một email đã gửi thì không rút lại được, nên nó chỉ được phép rời đi sau khi transaction ngoài
 * cùng đã commit thật.
 */
class ChecklistItemRejected implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly MatterChecklistItem $checklistItem) {}
}
