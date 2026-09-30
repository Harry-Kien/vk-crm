<?php

namespace App\Jobs;

use App\Actions\Document\ChecklistProgress;
use App\Actions\Notification\ResolveClientRecipients;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Schedule\RemindMissingDocuments;
use App\Enums\Role;
use App\Mail\Client\MissingDocuments;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Gửi thư `client.missing_documents` (SPEC §6.9) — dispatch bởi
 * {@see RemindMissingDocuments::processOne()} bằng `->afterCommit()`, cùng khuôn
 * {@see SendStaleMatterMail} (R2: transaction của Action chỉ khoá dòng, đọc lại điều kiện và
 * dispatch — không gọi ra mạng bên trong nó).
 *
 * # Tính lại TOÀN BỘ lúc gửi, không tin ảnh chụp lúc dispatch
 *
 * Payload chỉ mang `matterId`. Giữa lúc xếp hàng và lúc job chạy, khách có thể vừa nộp nốt giấy
 * tờ, văn phòng vừa đóng hồ sơ hay tắt cổng, một tài khoản vừa bị khoá. `handle()` vì vậy:
 *
 *  1. đọc lại hồ sơ VÀ hỏi lại đúng tập §6.9 ({@see ChecklistProgress::mattersAwaitingClient()} —
 *     chưa xoá mềm, còn mở, còn công bố portal, còn đầu mục bắt buộc thiếu);
 *  2. tính lại DANH SÁCH đầu mục còn thiếu ({@see ChecklistProgress::outstandingRequiredItems()}) —
 *     thư liệt kê đúng những gì còn thiếu LÚC GỬI, không phải lúc lên lịch; rỗng thì không gửi;
 *  3. tính lại NGƯỜI NHẬN qua {@see ResolveClientRecipients} (R12).
 *
 * # Không gửi trùng khi thử lại
 *
 * `handle()` gửi TỪNG người trong vòng `foreach`; nếu một người làm transport ném lỗi, ngoại lệ
 * thoát khỏi `handle()` và Laravel THẢ LẠI toàn bộ job — lần thử sau chạy lại TỪ ĐẦU, kể cả người đã
 * nhận thành công ở lượt trước. {@see RemindMissingDocuments::alreadyDelivered()} hỏi thẳng
 * `outbound_messages` trước mỗi thư, nên người đã nhận không nhận bản thứ hai chỉ vì người khác
 * trong cùng lượt từng hỏng. Cùng lớp chống trùng này cũng phủ trường hợp Action chạy hai lần trước
 * khi ai rút hàng đợi (hai job nối nhau, sổ thư được transport ghi đồng bộ).
 *
 * Một người nhận hỏng không được chặn những người sau (final review X5, B-I1): giữ ngoại lệ ĐẦU
 * TIÊN, thử hết, rồi ném lại để hàng đợi thấy job hỏng (thử lại, rồi {@see self::failed()}).
 */
class SendMissingDocumentsMail implements ShouldQueue
{
    use Queueable;

    /** Một lần hỏng thoáng qua (SMTP chết tạm) không cần báo động ngay; xem `backoff()`. */
    public int $tries = 5;

    public function __construct(
        public readonly int $matterId,
    ) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        $matter = ChecklistProgress::mattersAwaitingClient(Matter::query()->whereKey($this->matterId))->first();

        if ($matter === null) {
            return;
        }

        $items = ChecklistProgress::outstandingRequiredItems($matter);

        if ($items->isEmpty()) {
            return;
        }

        $failure = null;

        foreach (app(ResolveClientRecipients::class)->recipientsFor($matter->client_id) as $recipient) {
            if (RemindMissingDocuments::alreadyDelivered($matter, $recipient)) {
                continue;
            }

            try {
                Mail::to($recipient->email)->send(new MissingDocuments($matter, $recipient, $items));
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Chạy đúng MỘT lần, sau khi CẢ `$tries` lần đều thất bại — cùng hình dạng
     * {@see SendStaleMatterMail::failed()}. Khách không nhận được thư nhắc, nên người cần biết là
     * luật sư phụ trách (để gọi điện): thông báo trong hệ thống tới
     * {@see ResolveStaffRecipients::handle()} với `[luật sư phụ trách]` — R3, chuỗi dự phòng
     * "không bao giờ im lặng", và vụ `restricted` không lộ mã cho người không xem được. Hồ sơ đã bị
     * xoá hẳn (không còn để hỏi Gate) thì rơi xuống mọi admin đang hoạt động.
     *
     * "Không im lặng" còn dựa vào lượt {@see RemindMissingDocuments} kế tiếp: thư `failed` không
     * tính là "đã nhắc", nên lần chạy sau vẫn xếp job lại.
     */
    public function failed(?Throwable $exception): void
    {
        /** @var Matter|null $matter */
        $matter = Matter::query()->withTrashed()->find($this->matterId);

        Audit::record('missing_documents_reminder_failed', $matter, [
            'matter_id' => $this->matterId,
        ]);

        $recipients = $matter !== null
            ? app(ResolveStaffRecipients::class)->handle($matter, [$matter->leadLawyer])
            : User::query()->where('is_active', true)->role(Role::Admin->value)->get();

        foreach ($recipients as $recipient) {
            Notification::make()
                ->title(__('matters.missing_documents_failed_notification.title'))
                ->body(__('matters.missing_documents_failed_notification.body', [
                    'code' => e($matter->code ?? ''),
                ]))
                ->color('danger')
                ->sendToDatabase($recipient);
        }
    }
}
