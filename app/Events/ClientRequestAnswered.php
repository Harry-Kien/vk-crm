<?php

namespace App\Events;

use App\Models\ClientRequestReply;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Một câu trả lời của NHÂN SỰ vừa đưa một luồng trao đổi vào `answered` LẦN ĐẦU, từ một trạng
 * thái khác — SPEC §9 mẫu `client.request_answered` (đính chính 2026-09-27, `requests/REQ-4`).
 * Bắn từ `App\Actions\Portal\ReplyToClientRequest::advanceStatus()`.
 *
 * **CHỈ khi trạng thái THẬT SỰ chuyển vào `answered`** — một câu trả lời thứ hai vào một luồng
 * đã `answered` (docblock `ReplyToClientRequest`: `answered_at` không dịch đi) không bắn sự kiện
 * này lần nữa, và `App\Actions\Portal\TriageClientRequest::setStatus()` (đặt tay
 * `answered` không kèm câu trả lời nào — "trả lời qua điện thoại") cũng không bắn nó: SPEC nói rõ
 * mẫu này kích hoạt khi "một câu trả lời của văn phòng đổi luồng sang answered", và không có câu
 * trả lời nào được viết ra ở nhánh `setStatus()` để mà thư mời khách "vào cổng xem" trỏ tới.
 *
 * Mang `ClientRequestReply` (không phải `ClientRequest`): chống trùng của mẫu thư phải khoá theo
 * TỪNG câu trả lời — xem docblock `App\Mail\Client\RequestAnswered`.
 *
 * `ShouldDispatchAfterCommit`: `ReplyToClientRequest::handle()` bắn sự kiện này BÊN TRONG
 * `DB::transaction()` của nó — cùng lý lẽ `ClientDocumentSubmitted`/`DocumentPublished`.
 */
class ClientRequestAnswered implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly ClientRequestReply $reply) {}
}
