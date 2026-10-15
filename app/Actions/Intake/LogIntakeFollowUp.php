<?php

namespace App\Actions\Intake;

use App\Enums\FollowUpChannel;
use App\Models\IntakeFollowUp;
use App\Models\IntakeRequest;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Ghi MỘT lần chăm sóc lead (làn kd, 2026-10-14): người theo lead vừa liên hệ lại khách tiềm năng — gọi,
 * Zalo, email hay gặp — và ghi thời gian, kênh, nội dung ngắn; tuỳ chọn hẹn lần liên hệ tiếp theo.
 *
 * **Ai, khi nào:** người sửa được bản ghi (`IntakeRequestPolicy::update` — thấy được nó), trên bản ghi còn
 * mở (cùng định nghĩa với "Chuyển cho luật sư", {@see HandIntakeToLawyer::isClosed()}). Không đòi cổng ô
 * câu chuyện (`IntakeSummaryGate`): lần chăm sóc là việc ĐÃ LÀM ("đã gửi báo phí qua Zalo"), không phải
 * câu chuyện của người gọi — form nói rõ điều đó.
 *
 * **Kiểm tra đầu vào:** nội dung bắt buộc sau khi bỏ khoảng trắng hai đầu, tối đa
 * {@see IntakeFollowUp::NOTE_MAX_LENGTH} ký tự (độ dài cột — MariaDB strict); thời gian liên hệ không ở
 * tương lai; hẹn liên hệ lại (nếu có) phải ở sau lúc này.
 *
 * **Hẹn liên hệ lại:** mỗi lần ghi đặt `intake_requests.next_follow_up_at` bằng đúng hẹn của lần này —
 * để trống là XOÁ hẹn cũ (vừa liên hệ xong thì hẹn cũ đã xong việc). Widget "Lead đến hẹn liên hệ lại"
 * đọc cột đó. Lần chăm sóc KHÔNG đổi trạng thái và không chạm mốc phản hồi đầu (R5): rời "Mới" vẫn chỉ đi
 * qua "Đổi trạng thái" (`ChangeIntakeStatus`).
 *
 * Câu đầu tiên của transaction là lần đọc có khoá dòng bản ghi; điều kiện "còn mở" đọc lại trên dòng đó.
 * Nhật ký: dòng `intake_follow_up_logged` (chủ thể là bản ghi) mang id dòng, kênh và cờ có hẹn hay không —
 * KHÔNG nội dung (R7: ẩn danh phải phủ được nhật ký; nội dung nằm ở `intake_follow_ups.note`, nơi
 * `AnonymiseProspect` xoá nó).
 */
class LogIntakeFollowUp
{
    public function handle(
        User $actor,
        IntakeRequest $intake,
        FollowUpChannel $channel,
        CarbonInterface|string $occurredAt,
        string $note,
        CarbonInterface|string|null $nextFollowUpAt,
    ): IntakeFollowUp {
        Gate::forUser($actor)->authorize('update', $intake);

        $note = trim($note);

        if ($note === '') {
            throw ValidationException::withMessages(['note' => [__('leads.follow_ups.errors.note_required')]]);
        }

        if (mb_strlen($note) > IntakeFollowUp::NOTE_MAX_LENGTH) {
            throw ValidationException::withMessages(['note' => [__('leads.follow_ups.errors.note_too_long', ['max' => IntakeFollowUp::NOTE_MAX_LENGTH])]]);
        }

        $occurred = self::moment($occurredAt, 'occurred_at', 'leads.follow_ups.errors.occurred_invalid');

        if ($occurred->greaterThan(now())) {
            throw ValidationException::withMessages(['occurred_at' => [__('leads.follow_ups.errors.occurred_in_future')]]);
        }

        $next = blank($nextFollowUpAt) ? null : self::moment($nextFollowUpAt, 'next_follow_up_at', 'leads.follow_ups.errors.next_invalid');

        if ($next !== null && ! $next->greaterThan(now())) {
            throw ValidationException::withMessages(['next_follow_up_at' => [__('leads.follow_ups.errors.next_in_past')]]);
        }

        return DB::transaction(function () use ($actor, $intake, $channel, $occurred, $note, $next): IntakeFollowUp {
            $locked = IntakeRequest::query()->whereKey($intake->getKey())->lockForUpdate()->firstOrFail();

            if (HandIntakeToLawyer::isClosed($locked)) {
                throw ValidationException::withMessages(['note' => [__('leads.follow_ups.errors.closed')]]);
            }

            $followUp = (new IntakeFollowUp([
                'intake_request_id' => $locked->getKey(),
                'channel' => $channel,
                'occurred_at' => $occurred,
                'note' => $note,
            ]))->blameOn($actor);
            $followUp->save();

            $locked->forceFill(['next_follow_up_at' => $next])->blameOn($actor);
            $locked->disableLogging()->save();
            $locked->enableLogging();

            Audit::record('intake_follow_up_logged', $locked, [
                'follow_up_id' => $followUp->getKey(),
                'channel' => $channel->value,
                'next_follow_up_set' => $next !== null,
            ], $actor);

            return $followUp;
        });
    }

    private static function moment(CarbonInterface|string $value, string $field, string $error): CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value);
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (InvalidFormatException) {
            throw ValidationException::withMessages([$field => [__($error)]]);
        }
    }
}
