<?php

namespace App\Mail\Staff;

use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Jobs\SendStaffWorkTransferNotices;
use App\Mail\BrandedMailable;
use App\Mail\OutboundHeaders;
use App\Models\User;
use App\Notifications\Staff\StaffWorkTransferAlert;
use App\Support\OfficeProfile;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Mẫu `staff.work_transferred` (làn fa2, Task 1, vòng sửa 1 — I2): MỘT thư tổng hợp cho người nhận một lần "Chuyển
 * việc nội bộ và ghế" của màn hình Bàn giao hàng loạt — thay cho thư "được giao" của từng mốc hạn (và, khi gộp với
 * làn fa1, của từng việc nội bộ). Chỉ {@see SendStaffWorkTransferNotices} gửi, cùng lúc chuông
 * {@see StaffWorkTransferAlert::RECEIVED}, cùng mã hồ sơ đã lọc (vụ hạn chế người nhận không mở được chỉ đếm).
 *
 * Tiêu đề không mang mã hồ sơ, tên người hay tên khách (hiện trên màn hình khoá); thân thư mang mã hồ sơ đã lọc và
 * các con số, và (gộp integ-fa — C1 của rà soát cuối làn fa2) một dòng "Mốc hạn" cho TỪNG mốc đã sang tay người nhận
 * (`$deadlineLines`, job dựng: tên mốc, hồ sơ, hạn, số ngày còn lại, mức độ, dấu GẤP) — thư này thay cho thư
 * `staff.deadline_assigned` của từng mốc. Không bản ghi liên quan (lô trải trên nhiều hồ sơ) — dòng sổ thư chỉ quản
 * trị viên thấy. `LEDGER_TIER` mang khoá của lần chuyển. Không gửi lại được từ nhật ký thư
 * (`ResendTargets::NOT_RESENDABLE`).
 */
class StaffWorkTransferred extends BrandedMailable
{
    public const TEMPLATE = 'staff.work_transferred';

    /**
     * @param  list<string>  $codes
     * @param  array{tasks: int, followed: int, deadlines: int, requests: int, seats: int}  $counts
     * @param  list<string>  $deadlineLines  gộp integ-fa: chữ thô, một dòng mỗi mốc
     */
    public function __construct(
        public User $recipient,
        public string $actorName,
        public string $fromName,
        public array $codes,
        public int $hidden,
        public array $counts,
        public string $ledgerKey,
        public array $deadlineLines = [],
    ) {}

    protected function template(): string
    {
        return self::TEMPLATE;
    }

    /** @return array<string, string> */
    protected function additionalLedgerHeaders(): array
    {
        return [OutboundHeaders::LEDGER_TIER => $this->ledgerKey];
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('internal_flow.staff_work.digest.email.subject'));
    }

    public function content(): Content
    {
        $details = [['label' => __('internal_flow.staff_work.digest.email.fields.from'), 'value' => $this->fromName]];
        $matters = StaffWorkTransferAlert::mattersPhrase($this->codes, $this->hidden);

        if ($matters !== null) {
            $details[] = ['label' => __('internal_flow.staff_work.digest.email.fields.matters'), 'value' => $matters];
        }

        foreach (['tasks', 'followed', 'deadlines', 'requests', 'seats'] as $part) {
            if (($this->counts[$part] ?? 0) > 0) {
                $details[] = ['label' => __("internal_flow.staff_work.digest.email.fields.{$part}"), 'value' => (string) $this->counts[$part]];
            }
        }

        foreach ($this->deadlineLines as $line) {
            $details[] = ['label' => __('internal_flow.staff_work.digest.email.fields.deadline_item'), 'value' => $line];
        }

        return new Content(
            view: 'emails.staff.task-notice',
            text: 'emails.staff.task-notice-text',
            with: [
                'recipientName' => $this->recipient->name,
                'headline' => __('internal_flow.staff_work.digest.email.headline', ['who' => $this->actorName, 'from' => $this->fromName]),
                'details' => $details,
                'url' => TaskResource::getUrl('index', ['tab' => 'awaiting'], panel: 'admin'),
                'action' => __('internal_flow.staff_work.digest.email.action'),
                'office' => OfficeProfile::current()->legalName(),
            ],
        );
    }
}
