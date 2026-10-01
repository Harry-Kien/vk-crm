<?php

namespace App\Mail\Client;

use App\Mail\BrandedMailable;
use App\Models\ClientRequestReply;
use App\Models\ClientUser;
use App\Support\PortalUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Mẫu `client.request_answered` của SPEC §9 (đính chính 2026-09-27, M6.5 Task 21, `requests/REQ-4`)
 * — văn phòng vừa trả lời một luồng trao đổi và câu trả lời đó đưa luồng vào trạng thái `answered`
 * LẦN ĐẦU (từ một trạng thái khác). Dispatch bởi `App\Events\ClientRequestAnswered`
 * (`App\Actions\Portal\ReplyToClientRequest::advanceStatus()`, nhánh nhân sự) và gửi bởi
 * `App\Actions\Notification\NotifyClientOfRequestAnswered` qua
 * `App\Listeners\SendClientRequestAnsweredNotification`.
 *
 * **`relatedRecord()` là chính `ClientRequestReply`** (bí danh morph `client_request_reply`,
 * `OutboundMessage::relatedMatterId()` đã biết tra vụ việc từ đó từ Task 3/M5) — không phải
 * `ClientRequest`: chống trùng của mẫu này phải khoá theo TỪNG câu trả lời (SPEC "câu trả lời của
 * văn phòng đổi luồng sang answered", không phải "luồng này"), vì cùng một luồng có thể được trả
 * lời-rồi-hỏi-lại-rồi-trả-lời nhiều lần trong đời nó, và mỗi lần CHUYỂN vào `answered` là một sự
 * kiện đáng báo riêng.
 *
 * **R6/R7:** thân thư KHÔNG trích nội dung câu trả lời (SPEC §9: "chi tiết mời bấm vào portal") —
 * câu trả lời có thể dài, và trích một phần là tạo ra một bản sao thứ hai, không đồng bộ, của một
 * hàng `client_request_replies`. Thân thư chỉ mời khách vào cổng để đọc nguyên văn. Tiêu đề chỉ
 * mang mã hồ sơ, không mang tiêu đề yêu cầu (`ClientRequest::subject` do CHÍNH khách gõ) — cùng
 * luật R6 áp cho `client.document_published`/`client.document_rejected`.
 */
class RequestAnswered extends BrandedMailable
{
    public function __construct(
        public ClientRequestReply $reply,
        public ClientUser $recipient,
    ) {}

    protected function template(): string
    {
        return 'client.request_answered';
    }

    protected function relatedRecord(): ?Model
    {
        return $this->reply;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('portal.email.request_answered.subject', [
                'code' => $this->reply->request?->matter?->code ?? '',
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.client.request-answered',
            text: 'emails.client.request-answered-text',
            with: [
                'name' => $this->recipient->name,
                'matterCode' => $this->reply->request?->matter?->code,
                'portalUrl' => PortalUrl::base(),
                'office' => config('vkcrm.brand.legal_name'),
                'hotline' => config('vkcrm.brand.hotline'),
            ],
        );
    }
}
