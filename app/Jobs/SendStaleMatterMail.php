<?php

namespace App\Jobs;

use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Schedule\CheckStaleMatters;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Mail\Staff\StaleMatterReminder;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Support\Audit;
use App\Support\MatterStaleness;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Gửi thư `staff.stale_matter` (SPEC §6.4) — dispatch bởi
 * {@see CheckStaleMatters::processOne()} bằng `->afterCommit()`, ĐÚNG cùng khuôn `App\Jobs\
 * SendDeadlineReminderMail` (M6.5 Task 11/12/14): transaction của `CheckStaleMatters` chỉ khoá
 * dòng, đọc lại điều kiện, và dispatch — không gọi ra mạng bên trong nó (R2).
 *
 * # Đọc lại TOÀN BỘ điều kiện lúc gửi, không tin ảnh chụp lúc dispatch
 *
 * Payload chỉ mang `matterId` (SPEC §10.5 style — không mang danh sách người nhận). `handle()` đọc
 * lại vụ việc, đọc lại `MatterStaleness::scopeStale()`/`olderThan()` (một cập nhật khách hàng, một
 * lần đóng/tắt cổng vụ việc có thể xảy ra GIỮA lúc dispatch và lúc job thật sự chạy), rồi gọi lại
 * CHÍNH {@see CheckStaleMatters::recipientsFor()} — hàm mà `CheckStaleMatters::processOne()` đã
 * dùng để quyết định có dispatch hay không — để tính lại TOÀN BỘ đối tượng nhận thư, không tin bất
 * kỳ ảnh chụp nào (R3, "re-derive audience at send time").
 *
 * # Không gửi trùng khi thử lại
 *
 * `handle()` gửi TỪNG người trong vòng `foreach`; nếu một người làm transport ném lỗi, ngoại lệ
 * thoát khỏi `handle()` và Laravel THẢ LẠI toàn bộ job — lần thử sau chạy lại TỪ ĐẦU, tính lại
 * TOÀN BỘ người nhận và LẶP LẠI vòng `foreach`, kể cả người đã nhận thành công ở lượt trước.
 * {@see self::alreadyDelivered()} hỏi thẳng `outbound_messages` (cùng hình dạng
 * `SendDeadlineReminderMail::alreadyDelivered()`, không cần lọc theo "bậc" như thư nhắc mốc — mẫu
 * này chỉ có MỘT hình dạng, không có khái niệm bậc) trước khi gửi lại, nên người đã nhận không
 * nhận thêm bản thứ hai chỉ vì người khác trong cùng lượt từng hỏng.
 *
 * Một người nhận hỏng không được chặn những người sau (final review X5, B-I1 — cùng lý lẽ
 * `SendDeadlineReminderMail`): giữ ngoại lệ ĐẦU TIÊN, thử hết, rồi ném lại để hàng đợi thấy job
 * hỏng (thử lại, rồi `failed()`).
 */
class SendStaleMatterMail implements ShouldQueue
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
        /** @var Matter|null $matter */
        $matter = Matter::query()->find($this->matterId);

        if ($matter === null) {
            return;
        }

        // Vụ việc đã đóng/xoá mềm/tắt cổng, hay vừa được cập nhật cho khách, giữa lúc job xếp hàng
        // và lúc nó chạy — cùng định nghĩa DUY NHẤT `MatterStaleness::scopeStale()` mà
        // `CheckStaleMatters` đã dùng để dựng danh sách ứng viên, không viết lại nó lần nữa ở đây.
        if (! MatterStaleness::scopeStale(Matter::query()->whereKey($matter->getKey()))->exists()) {
            return;
        }

        if (! MatterStaleness::olderThan($matter, MatterStaleness::EMAIL_AFTER_DAYS)) {
            return;
        }

        // Tính lại TOÀN BỘ đối tượng nhận thư TẠI THỜI ĐIỂM GỬI — xem docblock lớp.
        $recipients = app(CheckStaleMatters::class)->recipientsFor($matter, 'mail');

        $failure = null;

        foreach ($recipients as $recipient) {
            if ($this->alreadyDelivered($matter, $recipient)) {
                continue;
            }

            try {
                Mail::to($recipient->email)->send(new StaleMatterReminder($matter, $recipient));
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Cùng hình dạng {@see SendDeadlineReminderMail::alreadyDelivered()}, không có điều kiện
     * "bậc" (mẫu này chỉ có MỘT hình dạng) nhưng CÓ điều kiện `template`: chỉ một thư
     * `staff.stale_matter` đã gửi mới tính là "đã nhắc" — thư mẫu khác về cùng vụ việc tới cùng
     * người không được nuốt lời nhắc này. `CheckStaleMatters` đã tự chặn việc dispatch trong 7 ngày
     * kể từ lần gửi thành công gần nhất (R5); kiểm tra ở đây là lớp phòng thủ THỨ HAI, theo TỪNG
     * người nhận, cho lúc thử lại và cho hai lần chạy Action xếp job trước khi ai rút hàng đợi
     * (các job chạy nối nhau, sổ thư được transport ghi đồng bộ).
     */
    private function alreadyDelivered(Matter $matter, User $recipient): bool
    {
        return OutboundMessage::query()
            ->withoutGlobalScopes()
            ->where('related_type', $matter->getMorphClass())
            ->where('related_id', $matter->getKey())
            ->where('template', 'staff.stale_matter')
            ->where('recipient', $recipient->email)
            ->where('status', OutboundStatus::Sent)
            ->where('sent_at', '>=', now()->subDays(7))
            ->exists();
    }

    /**
     * Chạy đúng MỘT lần, sau khi CẢ `$tries` lần đều thất bại — cùng hình dạng
     * `SendDeadlineReminderMail::failed()`. Không có `reminders_sent` để rút lại ở đây (mẫu này
     * không có cột chống trùng riêng, R3), nên "không im lặng" dựa vào hai thứ:
     *
     *  - thông báo trong hệ thống dưới đây, tới đúng những người lẽ ra nhận thư;
     *  - lượt `CheckStaleMatters` KẾ TIẾP: nếu KHÔNG người nào nhận được thư (không có dòng
     *    `outbound_messages` `status = sent` nào cho vụ việc này trong 7 ngày qua) nó dispatch lại.
     *
     * Giới hạn đã biết: nếu một số người đã nhận thư còn số khác thì không, thì dòng `sent` của
     * người đã nhận khiến `CheckStaleMatters` coi vụ việc là "đã nhắc" tới hết 7 ngày — người chưa
     * nhận chỉ còn thông báo trong hệ thống ở đây, cho tới lượt nhắc kế tiếp.
     */
    public function failed(?Throwable $exception): void
    {
        /** @var Matter|null $matter */
        $matter = Matter::query()->withTrashed()->find($this->matterId);

        Audit::record('stale_matter_reminder_failed', $matter, [
            'matter_id' => $this->matterId,
        ]);

        $resolver = app(ResolveStaffRecipients::class);

        $recipients = $matter !== null
            ? $resolver->handle(
                $matter,
                collect([$matter->leadLawyer])->merge($resolver->supervisorsFor($matter))->all(),
            )
            : User::query()->where('is_active', true)->role(Role::Admin->value)->get();

        foreach ($recipients as $recipient) {
            Notification::make()
                ->title(__('matters.stale_reminder_failed_notification.title'))
                ->body(__('matters.stale_reminder_failed_notification.body', [
                    'code' => e($matter->code ?? ''),
                ]))
                ->color('danger')
                ->sendToDatabase($recipient);
        }
    }
}
