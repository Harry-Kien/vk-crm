<?php

namespace App\Events;

use App\Models\Document;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Dispatch ở SPEC §6.6 bước 9, khi một khách hàng vừa nộp MỘT LẦN vào một đầu mục danh mục hồ sơ.
 * Listener (thông báo trong hệ thống cho luật sư phụ trách và trợ lý) thuộc M6 — không viết ở đây.
 *
 * Người nhận thông báo là ĐỘI NGŨ, không phải khách: khách vừa bấm nút và đã thấy trạng thái
 * "Đang chờ văn phòng kiểm tra" (SPEC §8.4), còn thứ chưa ai trong văn phòng biết là có tệp mới
 * đang chờ. Đội ngũ đọc ra từ `$documents->first()->matter` (luật sư phụ trách cộng
 * `matter_user`), nên sự kiện không chép sẵn một danh sách người nhận có thể đã cũ khi listener
 * chạy.
 *
 * **MỘT sự kiện cho MỘT LẦN NỘP, không phải một sự kiện mỗi `Document` (vòng sửa 1, finding
 * I4).** Bản đầu (Task 17) dispatch sự kiện này BÊN TRONG vòng lặp tạo `Document` của R10, nên
 * một lần nộp CCCD hai mặt tạo ra HAI sự kiện — hai thông báo trong hộp thư của luật sư phụ
 * trách cho đúng MỘT hành động khách vừa làm. Đó là hình dạng ngược với chính cái R10 sửa ở tầng
 * dữ liệu: `SubmitClientDocument` đã gộp nhiều tệp của một lần nộp vào CHUNG một `version` để
 * không tài liệu nào "che" tài liệu kia; sự kiện báo tin cho đội ngũ phải gộp theo đúng ranh giới
 * đó, không phải theo ranh giới `Document`. Sự kiện mang `$documents` — TOÀN BỘ tài liệu sinh ra
 * từ lô đó — để listener đọc được cả hai mặt CCCD từ một lần thông báo, thay vì phải tự đoán
 * "còn tài liệu nào khác cùng version với tài liệu này không".
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

    /**
     * @param  Collection<int, Document>  $documents  Mọi `Document` sinh ra từ CÙNG một lần nộp
     *                                                (R10: một lô có thể gồm nhiều tệp, tất cả
     *                                                cùng version) — không bao giờ rỗng.
     */
    public function __construct(public readonly Collection $documents) {}

    /**
     * Tài liệu đầu của lô — dùng khi listener chỉ cần MỘT đại diện của cả lô (ví dụ để đọc
     * `matter`, `matter_checklist_item_id`: cả lô cùng một đầu mục nên giá trị này giống nhau
     * trên mọi phần tử).
     */
    public function firstDocument(): Document
    {
        return $this->documents->first();
    }
}
