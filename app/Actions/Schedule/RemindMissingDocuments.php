<?php

namespace App\Actions\Schedule;

use App\Actions\Document\ChecklistProgress;
use App\Actions\Notification\RecordOutboundMessage;
use App\Actions\Notification\ResolveClientRecipients;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\OutboundStatus;
use App\Jobs\SendMissingDocumentsMail;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\Staff\MissingDocumentsStuckAlert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SPEC §6.9 — nhắc khách nộp giấy tờ còn thiếu, thứ Hai/Tư/Sáu 08:00. Mỗi hồ sơ đang mở, đã công
 * bố portal, còn đầu mục BẮT BUỘC ở `missing`/`rejected`: một thư `client.missing_documents` cho
 * mỗi tài khoản khách đủ điều kiện (R12), và nếu tình trạng thiếu kéo dài quá
 * {@see ChecklistProgress::STUCK_AFTER_DAYS} ngày thì luật sư phụ trách được báo TRONG HỆ THỐNG để
 * gọi điện cho khách.
 *
 * # MỘT nguồn sự thật về "còn thiếu" — {@see ChecklistProgress}
 *
 * Tập hồ sơ ({@see ChecklistProgress::mattersAwaitingClient()}), danh sách đầu mục
 * ({@see ChecklistProgress::outstandingRequiredItems()}) và đồng hồ "thiếu từ"
 * ({@see ChecklistProgress::earliestMissingSince()}) đều là của lớp đó — cùng những thứ mà
 * `MattersMissingDocumentsWidget` hiện cho luật sư. Action này KHÔNG có một điều kiện
 * `status`/`is_required` hay `COALESCE` nào về đầu mục của riêng nó (chỉ có `status = sent` trên SỔ
 * THƯ, xem bên dưới): widget "hồ sơ tắc quá 14 ngày" và thông báo 14 ngày dưới đây trả lời cùng
 * một câu, trên cùng một hồ sơ, bằng cùng một định nghĩa. `pending_review` KHÔNG phải "thiếu"
 * (khách đã nộp, quả bóng ở sân văn phòng) — ghim bằng test.
 *
 * Thư khách đọc một tập HẸP hơn của cùng lớp: {@see ChecklistProgress::itemsToRemindClientOf()} bỏ đầu
 * mục bản hợp đồng đã ký khi hợp đồng còn nháp hoặc chưa có (lượt quét §10 trước bản 1.0); tập đó rỗng
 * thì không xếp job thư, còn thông báo 14 ngày cho luật sư vẫn đọc mọi đầu mục còn thiếu.
 *
 * # Người nhận khách: R12 + chống trùng 3 ngày theo TỪNG người (R3 của kế hoạch M6)
 *
 * {@see ResolveClientRecipients} là chỗ DUY NHẤT nói tài khoản nào nhận thư về một hồ sơ: R12, rồi
 * `onPortal()` (vụ còn trên cổng của chính người nhận — việc sau gộp M7, làn fu2, cùng câu hỏi
 * `SendMissingDocumentsMail::context()` hỏi lúc gửi, nên Action không xếp một job mà job sẽ bỏ).
 * Chống trùng: {@see self::alreadyDelivered()} hỏi `outbound_messages` (`template =
 * client.missing_documents`, `related` = hồ sơ, `recipient`, `status = sent`, `sent_at` trong cửa sổ
 * {@see self::mailWindowStart()}) — KHÔNG thêm cột "đã nhắc lúc nào" (R3). `status = sent`: một thư
 * `failed` vẫn là một dòng (R1) nhưng không phải "đã nhắc", nếu không một lần gửi hỏng biến thành
 * một lần im lặng không bao giờ gửi lại ({@see RecordOutboundMessage}, "Hệ quả cho M6 Task 8").
 * Hỏi theo TỪNG người nhận (không theo hồ sơ): khi một trong hai tài khoản đã nhận và tài khoản kia
 * hỏng, lượt sau vẫn xếp job cho người còn lại.
 *
 * **Cửa sổ 3 ngày tính theo NGÀY LỊCH** ({@see self::mailWindowStart()}: 00:00 của hôm nay - 2), cùng
 * bài học R5 của Task 7: `sent_at` đóng dấu khi worker thật sự gửi, luôn muộn hơn lượt 08:00 đã xếp
 * job vài chục giây, nên `now()->subDays(3)` biến chu kỳ 3 ngày thành 4. Thư ngày D chặn D..D+2,
 * không chặn D+3. Hệ quả cần nói ra: lịch là thứ Hai/Tư/Sáu (cách nhau 2, 2, 3 ngày) mà luật là "không
 * quá một thư mỗi 3 ngày", nên hồ sơ thiếu liên tục nhận thư thứ Hai và thứ Sáu; thứ Tư chỉ gửi cho
 * hồ sơ mới bắt đầu thiếu (hoặc lượt trước đã hỏng).
 *
 * # 14 ngày — thông báo trong hệ thống, một lần mỗi ĐỢT thiếu
 *
 * Đợt thiếu bắt đầu ở mốc "thiếu từ" SỚM NHẤT hiện tại của hồ sơ
 * ({@see ChecklistProgress::earliestMissingSince()}). Nó tự dời về sau khi đầu mục cũ nhất được
 * xử lý xong, hoặc khi một đầu mục bị từ chối lại (`reviewed_at` mới) — nên "đợt mới" không cần một
 * cột riêng (R3). Chống lặp qua bảng `notifications` ({@see self::alreadyNotified()}): (người nhận,
 * `viewData.matter_id`, `created_at` ≥ đầu đợt) — cùng hình dạng `CheckStaleMatters`. Thông báo
 * KHÔNG kèm thư nào cho khách và KHÔNG phụ thuộc khách có tài khoản gửi thư được hay không: luật sư
 * cần gọi điện chính vì thư có thể không tới.
 *
 * # Khoá dòng + đọc lại TRONG transaction; thư và thông báo ra NGOÀI transaction (R2)
 *
 * Danh sách ứng viên dựng TRƯỚC vòng lặp; `processOne()` khoá dòng `matters` (`lockForUpdate()`)
 * rồi đọc lại đúng cùng điều kiện — một lần đóng vụ, tắt cổng, hay khách vừa nộp nốt giấy tờ đã
 * commit ở nơi khác vẫn được thấy. Transaction chỉ khoá, đọc và `dispatch(...)->afterCommit()`;
 * không có `Mail::`/`->notify(` nào bên trong nó (`ArchitectureTest`). `->notify(` thật sự chạy sau
 * khi commit, cùng hình dạng `CheckStaleMatters::processOne()`.
 */
class RemindMissingDocuments
{
    /** "Không quá một thư mỗi 3 ngày cho cùng một matter" (kế hoạch M6, Task 8, R3). */
    public const MAIL_REPEAT_DAYS = 3;

    public function __invoke(): array
    {
        return $this->handle();
    }

    /**
     * @return array{notified: int, mailed: int}
     */
    public function handle(): array
    {
        $notified = 0;
        $mailed = 0;

        $candidates = ChecklistProgress::mattersAwaitingClient(Matter::query())
            ->orderBy('matters.id')
            ->pluck('matters.id');

        foreach ($candidates as $id) {
            try {
                $this->processOne($id, $notified, $mailed);
            } catch (Throwable $e) {
                // Một hồ sơ lỗi không được dừng cả vòng lặp — cùng lý lẽ `CheckStaleMatters::handle()`.
                report($e);
            }
        }

        return ['notified' => $notified, 'mailed' => $mailed];
    }

    /**
     * Cận dưới (gồm cả nó) của cửa sổ "đã gửi thư nhắc trong {@see self::MAIL_REPEAT_DAYS} ngày
     * qua", theo NGÀY LỊCH trong múi giờ ứng dụng: 00:00 của (hôm nay - 2). Dùng chung cho
     * {@see self::alreadyDelivered()} — cả ở Action lẫn ở {@see SendMissingDocumentsMail} — để hai
     * lớp chống trùng không lệch nhau. Xem docblock lớp, mục "Cửa sổ 3 ngày".
     */
    public static function mailWindowStart(): Carbon
    {
        return today()->subDays(self::MAIL_REPEAT_DAYS - 1);
    }

    /**
     * Tài khoản này đã có thư `client.missing_documents` GỬI THÀNH CÔNG về hồ sơ này trong cửa sổ
     * {@see self::mailWindowStart()} chưa. Tra `outbound_messages`, không thêm cột (R3); một thư
     * `failed` không tính — xem docblock lớp.
     */
    public static function alreadyDelivered(Matter $matter, ClientUser $recipient): bool
    {
        return OutboundMessage::query()
            ->withoutGlobalScopes()
            ->where('related_type', $matter->getMorphClass())
            ->where('related_id', $matter->getKey())
            ->where('template', 'client.missing_documents')
            ->where('recipient', $recipient->email)
            ->where('status', OutboundStatus::Sent)
            ->where('sent_at', '>=', self::mailWindowStart())
            ->exists();
    }

    /**
     * Một hồ sơ, một transaction — tách khỏi {@see self::handle()} để `foreach` bắt lỗi gọn.
     *
     * `$notice` (`array{matter: Matter, recipients: Collection<int, User>, episodeStart: Carbon}|null`)
     * mang dữ liệu cho thông báo 14 ngày ra NGOÀI closure của transaction — xem docblock lớp.
     */
    private function processOne(int $id, int &$notified, int &$mailed): void
    {
        $notice = null;

        DB::transaction(function () use ($id, &$mailed, &$notice): void {
            /** @var Matter|null $matter */
            $matter = Matter::query()->whereKey($id)->lockForUpdate()->first();

            if ($matter === null) {
                return;
            }

            // Đọc lại ĐÚNG cùng điều kiện đã dựng danh sách ứng viên, TRONG transaction, sau khi đã
            // khoá dòng: một lần đóng vụ/tắt cổng/khách nộp nốt vừa commit ở nơi khác trong lúc chờ
            // tới lượt phải loại hồ sơ này ra, không dùng ảnh chụp lúc `pluck()` chạy.
            if (! ChecklistProgress::mattersAwaitingClient(Matter::query()->whereKey($matter->getKey()))->exists()) {
                return;
            }

            $items = ChecklistProgress::outstandingRequiredItems($matter);
            $episodeStart = ChecklistProgress::earliestMissingSince($items);

            if ($episodeStart !== null && $episodeStart->lt(now()->subDays(ChecklistProgress::STUCK_AFTER_DAYS))) {
                $recipients = app(ResolveStaffRecipients::class)->handle($matter, [$matter->leadLawyer]);

                if ($recipients->isNotEmpty()) {
                    $notice = ['matter' => $matter, 'recipients' => $recipients, 'episodeStart' => $episodeStart];
                }
            }

            $resolver = app(ResolveClientRecipients::class);
            $accounts = $resolver->onPortal($matter, $resolver->recipientsFor($matter->client_id));

            // Thư chỉ đi khi còn đầu mục ĐƯỢC ĐÒI khách (`itemsToRemindClientOf()` — không đòi bản hợp
            // đồng đã ký khi hợp đồng còn nháp); thông báo 14 ngày ở trên vẫn đọc mọi đầu mục còn thiếu.
            if (ChecklistProgress::itemsToRemindClientOf($matter)->isNotEmpty()
                && $accounts->contains(fn (ClientUser $account): bool => ! self::alreadyDelivered($matter, $account))) {
                // `->afterCommit()`: Laravel hoãn việc đẩy job tới khi transaction NÀY thật sự commit.
                // Job tự đọc lại hồ sơ, danh sách còn thiếu VÀ người nhận lúc nó thật sự chạy — không
                // tin ảnh chụp ở đây (xem docblock job).
                SendMissingDocumentsMail::dispatch($matter->getKey())->afterCommit();
                $mailed++;
            }
        });

        if ($notice !== null) {
            /** @var Collection<int, User> $recipients */
            $recipients = $notice['recipients'];

            foreach ($recipients as $recipient) {
                // Mỗi người một `try` — một lần ghi hỏng cho người này không làm những người nhận
                // còn lại mất thông báo (cùng `CheckStaleMatters`).
                try {
                    if ($this->alreadyNotified($recipient, $notice['matter'], $notice['episodeStart'])) {
                        continue;
                    }

                    $recipient->notify(new MissingDocumentsStuckAlert($notice['matter']));
                    $notified++;
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }
    }

    /**
     * Người này đã có thông báo 14 ngày cho ĐÚNG hồ sơ này trong ĐỢT THIẾU hiện tại chưa: khoá là
     * (người nhận, `viewData.matter_id`, `created_at` ≥ đầu đợt). Một đợt MỚI (đầu mục cũ nhất đã
     * xong, hay một đầu mục bị từ chối lại — đồng hồ dời về sau đầu đợt cũ) không bị chặn bởi thông
     * báo của đợt CŨ.
     */
    private function alreadyNotified(User $recipient, Matter $matter, Carbon $episodeStart): bool
    {
        return $recipient->notifications()
            ->where('type', MissingDocumentsStuckAlert::class)
            ->where('data->viewData->matter_id', $matter->getKey())
            ->where('created_at', '>=', $episodeStart)
            ->exists();
    }
}
