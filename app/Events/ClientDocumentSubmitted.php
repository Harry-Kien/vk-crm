<?php

namespace App\Events;

use App\Models\Document;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LogicException;

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
     * **`Illuminate\Database\Eloquent\Collection`, không phải `Illuminate\Support\Collection`
     * (vòng sửa 2).** `SerializesModels::getSerializedPropertyValue()` chỉ nhận diện một
     * `QueueableCollection` — giao diện mà Eloquent Collection cài, Support Collection thì
     * không — để đổi nó thành một `ModelIdentifier` (tên lớp + mảng id, không mang theo thuộc
     * tính). Một `Support\Collection<Document>` không được nhận diện, nên rơi xuống nhánh cuối
     * (`return $value`) và bị PHP serialize() y nguyên — chép cả state của từng `Document` vào
     * payload thay vì một tên lớp và vài id. Sự kiện này hôm nay dispatch ĐỒNG BỘ (chưa listener
     * nào ở M6 implement `ShouldQueue`), nên khác biệt chưa lộ ra ở bất kỳ hành vi nào hôm nay;
     * nó chỉ lộ ra ĐÚNG lúc một listener tương lai (R2) queue nó — khi đó một payload phình to và
     * mang state CŨ (từ lúc dispatch, không phải lúc job chạy) là một lớp lỗi mà đổi kiểu ngay từ
     * bây giờ ngăn được hoàn toàn.
     *
     * @param  Collection<int, Document>  $documents  Mọi `Document` sinh ra từ CÙNG một lần nộp
     *                                                (R10: một lô có thể gồm nhiều tệp, tất cả
     *                                                cùng version) — không bao giờ rỗng.
     */
    public function __construct(public readonly Collection $documents) {}

    /**
     * Tài liệu đầu của lô — dùng khi listener chỉ cần MỘT đại diện của cả lô (ví dụ để đọc
     * `matter`, `matter_checklist_item_id`: cả lô cùng một đầu mục nên giá trị này giống nhau
     * trên mọi phần tử).
     *
     * Ném `LogicException` trên một lô rỗng thay vì trả `null` — "không bao giờ rỗng" ở docblock
     * `$documents` là một BẤT BIẾN của Action tạo ra sự kiện này
     * (`SubmitClientDocument::handle()` từ chối một lô rỗng trước khi tới đây), không phải một
     * khả năng bình thường mà listener phải tự kiểm tra mỗi lần gọi. Một chữ ký `?Document` sẽ
     * buộc MỌI listener rải `?->`/`if (... === null)` cho một trường hợp không bao giờ xảy ra
     * thật; một ngoại lệ ở đây nói đúng hơn: nếu nó ném, có gì đó ở Action đã sai, và lỗi ấy nên
     * ồn ào ngay tại chỗ, không lặng lẽ biến thành một `TypeError` xa nguồn gốc bên trong
     * listener.
     */
    public function firstDocument(): Document
    {
        if ($this->documents->isEmpty()) {
            throw new LogicException(
                'ClientDocumentSubmitted::$documents rỗng — vi phạm bất biến "không bao giờ '
                .'rỗng" mà SubmitClientDocument::handle() phải giữ trước khi dispatch sự kiện này.'
            );
        }

        return $this->documents->first();
    }
}
