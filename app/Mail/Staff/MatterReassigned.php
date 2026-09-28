<?php

namespace App\Mail\Staff;

use App\Mail\BrandedMailable;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/**
 * Mẫu `staff.matter_reassigned` của SPEC §9 (SPEC §6.11 bước 3; M6.5 Task 4 đã hoãn thư này sang
 * đây, R10; M7 Task 1 dựng nó).
 *
 * **Một thư, cả LÔ vụ việc** — dựng để `App\Jobs\SendReassignmentDigest` dùng lại được cho cả
 * màn hình bàn giao hàng loạt (M7 Task 2, chưa tới lượt ở milestone này): constructor nhận
 * `$blocks`, một mảng ĐÃ DỰNG SẴN (không phải model thô) — mỗi phần tử một vụ việc, gồm chính
 * `Matter`, danh sách `Deadline` còn hợp lệ, và số yêu cầu khách còn mở đã chuyển. Việc DỰNG
 * `$blocks` (đọc lại CSDL, lọc mốc đã hoàn thành, lọc vụ người nhận không còn xem được) là việc
 * của job — lớp này chỉ hiển thị những gì đã được dựng, không tự quyết định gì thêm, cùng ranh
 * giới "nghiệp vụ chỉ ở app/Actions/ hay tương đương, Mailable chỉ trình bày" của
 * `App\Mail\Staff\DeadlineReminder`.
 *
 * **Tiêu đề không nêu mã hay tiêu đề vụ việc nào** (phán quyết controller Task 1) — chỉ số
 * lượng. Dòng `outbound_messages` của thư này hiện tiêu đề đó; `relatedRecord()` trỏ vào NGƯỜI
 * NHẬN (không phải một `Matter`) nên dòng đó chỉ admin thấy được (phán quyết Task 13, M6.5), và
 * vẫn không mang mã/tiêu đề của bất kỳ vụ `restricted` nào trong lô.
 *
 * `$blocks` là mảng con:
 * `array{matter: Matter, deadlines: Collection<int, Deadline>, client_requests_moved: int, reason: string}`.
 */
class MatterReassigned extends BrandedMailable
{
    /**
     * @param  array<int, array{matter: Matter, deadlines: Collection<int, Deadline>, client_requests_moved: int, reason: string}>  $blocks
     */
    public function __construct(
        public User $recipient,
        public array $blocks,
    ) {}

    protected function template(): string
    {
        return 'staff.matter_reassigned';
    }

    /**
     * Không phải một `Matter` — thư này nói về NHIỀU vụ việc, không riêng vụ nào. Trỏ vào chính
     * người nhận, để dòng `outbound_messages` vẫn tra cứu được theo người, mà không lộ mã/tiêu
     * đề của bất kỳ vụ nào trong lô ra ngoài `template`/`payload->subject` (đã không mang mã/tiêu
     * đề nào — xem docblock lớp).
     */
    protected function relatedRecord(): ?Model
    {
        return $this->recipient;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('reassign.email.subject', ['count' => count($this->blocks)]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.staff.matter-reassigned',
            text: 'emails.staff.matter-reassigned-text',
            with: [
                'recipientName' => $this->recipient->name,
                'blocks' => $this->blocks,
                'office' => config('vkcrm.brand.legal_name'),
                'hotline' => config('vkcrm.brand.hotline'),
            ],
        );
    }
}
