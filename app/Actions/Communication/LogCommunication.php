<?php

namespace App\Actions\Communication;

use App\Actions\Concerns\ChecksAccountActive;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\CommunicationType;
use App\Models\CommunicationLog;
use App\Models\Matter;
use App\Models\User;
use App\Policies\CommunicationLogPolicy;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Ghi một dòng nhật ký liên lạc vào một vụ việc (SPEC §4.17, §7.2 tab "Liên lạc"; M7 Task 8).
 *
 * Bảng `communication_logs` có từ M1 và policy từ M2, nhưng trước Action này không có đường ghi
 * nào ngoài dữ liệu mẫu. **Tên lớp và chữ ký giữ cho M11**: công cụ MCP ghi nhật ký liên lạc gọi
 * thẳng Action này (và M11 thêm cột `created_via`).
 *
 * # "Dưới 15 giây" là chữ ký này
 *
 * SPEC §7.2: "ghi nhanh một cuộc gọi trong dưới 15 giây, vì nếu mất lâu hơn thì không ai ghi".
 * Nên chỉ HAI thứ bắt buộc — kênh và nội dung — còn lại tự điền: thời điểm là bây giờ, người ghi
 * là `$actor` (không có ô), người liên lạc là tên khách hàng của vụ (cột NOT NULL, nhưng không ai
 * phải gõ nó cho trường hợp thường gặp nhất).
 *
 * # `is_visible_to_client` luôn `false`, và không phải một tham số
 *
 * Không màn hình portal nào đọc bảng này (SPEC §8.3, phán quyết 3 của M5). Một tham số bật được
 * cột đó sẽ là một lời hứa "khách đã thấy" mà không gì giữ.
 *
 * # Thứ tự và cổng
 *
 * Câu ĐẦU TIÊN của transaction khoá dòng `matters` (thứ tự khoá chung của dự án). Cổng hỏi trên vụ
 * việc ĐỌC LẠI dưới khoá, không trên đối tượng caller đưa vào:
 * {@see CommunicationLogPolicy::create()} với `[CommunicationLog::class, $matter]`. Mọi lý do từ
 * chối — vụ không còn (kể cả xoá mềm), tài khoản đã vô hiệu, không có quyền — ra cùng MỘT câu
 * (SPEC §10.10). Kiểm tra đầu vào chạy SAU cổng, để một người không có quyền không dò được luật
 * của các ô.
 *
 * Audit `communication_logged` ghi kênh và thời điểm, KHÔNG ghi nội dung hay tên người liên lạc:
 * dòng nhật ký liên lạc còn nguyên trong bảng (kể cả khi đã xoá mềm), nên nhật ký hệ thống không
 * cần — và không nên — là bản sao thứ hai của lời trao đổi với khách.
 */
class LogCommunication
{
    use ChecksAccountActive;
    use ReadsWithoutPortalScope;

    /** `communication_logs.counterpart` là `string(200)`. */
    public const COUNTERPART_MAX_LENGTH = 200;

    /**
     * `summary` là `TEXT` — 65.535 BYTE. Một ký tự utf8mb4 tối đa 4 byte, nên 16.383 ký tự là trần
     * BẢO ĐẢM vừa với mọi nội dung (tiếng Việt có dấu thường 2–3 byte một ký tự). Trên MariaDB
     * strict, vượt cột là một lỗi 500, không phải một lần cắt cụt.
     */
    public const SUMMARY_MAX_LENGTH = 16383;

    /** `duration_minutes` là `unsignedSmallInteger`. */
    public const DURATION_MAX_MINUTES = 65535;

    /**
     * Cho phép thời điểm lệch tới chừng này giây về phía tương lai — đồng hồ máy người dùng và
     * máy chủ không bao giờ khớp từng giây, và ô chọn giờ gửi lên phút đang hiện trên màn hình.
     */
    public const CLOCK_DRIFT_SECONDS = 60;

    /**
     * @param  string|null  $counterpart  trống thì lấy tên khách hàng của vụ việc
     * @param  string|CarbonInterface|null  $occurredAt  trống thì là bây giờ
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(
        Matter $matter,
        User $actor,
        CommunicationType $type,
        string $summary,
        ?string $counterpart = null,
        string|CarbonInterface|null $occurredAt = null,
        ?int $durationMinutes = null,
    ): CommunicationLog {
        return DB::transaction(function () use ($matter, $actor, $type, $summary, $counterpart, $occurredAt, $durationMinutes): CommunicationLog {
            // Câu ĐẦU TIÊN: khoá vụ việc, đọc lại từ CSDL. `Matter::query()` loại vụ đã xoá mềm,
            // nên vụ như vậy ra `null` ở đây và đi ra bằng câu từ chối chung.
            $fresh = $this->scopelessly(Matter::query())->lockForUpdate()->find($matter->getKey());

            if ($fresh === null || ! $this->accountIsActive($actor)) {
                $this->refuse();
            }

            if (Gate::forUser($actor)->inspect('create', [CommunicationLog::class, $fresh])->denied()) {
                $this->refuse();
            }

            $log = new CommunicationLog([
                'matter_id' => $fresh->getKey(),
                'type' => $type,
                'occurred_at' => $this->readOccurredAt($occurredAt),
                'duration_minutes' => $this->checkDuration($durationMinutes),
                'counterpart' => $this->cleanCounterpart($counterpart, $fresh),
                'summary' => $this->cleanSummary($summary),
                'is_visible_to_client' => false,
            ]);

            $log->blameOn($actor)->save();

            Audit::record('communication_logged', $log, [
                'matter_id' => $fresh->getKey(),
                'client_id' => $fresh->client_id,
                'type' => $type->value,
                'occurred_at' => $log->occurred_at->toDateTimeString(),
            ], causer: $actor);

            return $log;
        });
    }

    private function cleanSummary(string $summary): string
    {
        $summary = trim($summary);

        if ($summary === '') {
            throw ValidationException::withMessages([
                'summary' => [__('communications.validation.summary_required')],
            ]);
        }

        if (mb_strlen($summary) > self::SUMMARY_MAX_LENGTH) {
            throw ValidationException::withMessages([
                'summary' => [__('communications.validation.summary_too_long', ['max' => self::SUMMARY_MAX_LENGTH])],
            ]);
        }

        return $summary;
    }

    /**
     * Trống (hoặc toàn khoảng trắng) thì là tên khách hàng của vụ — cột NOT NULL, và "khách gọi
     * đến"/"gọi cho khách" là trường hợp thường gặp nhất. Tên khách là `clients.name` `string(200)`,
     * cùng độ dài với cột này, nên giá trị mặc định luôn vừa.
     */
    private function cleanCounterpart(?string $counterpart, Matter $matter): string
    {
        $counterpart = trim((string) $counterpart);

        if ($counterpart === '') {
            return (string) $matter->client()->withoutGlobalScopes()->value('name');
        }

        if (mb_strlen($counterpart) > self::COUNTERPART_MAX_LENGTH) {
            throw ValidationException::withMessages([
                'counterpart' => [__('communications.validation.counterpart_too_long', ['max' => self::COUNTERPART_MAX_LENGTH])],
            ]);
        }

        return $counterpart;
    }

    /**
     * Nhật ký ghi điều ĐÃ xảy ra: một thời điểm ở tương lai (quá {@see self::CLOCK_DRIFT_SECONDS})
     * là một dòng bằng chứng tự mâu thuẫn, nên bị từ chối. Quá khứ thì nhận — ghi lại một cuộc gọi
     * hôm qua là việc thường ngày.
     */
    private function readOccurredAt(string|CarbonInterface|null $occurredAt): CarbonImmutable
    {
        $now = CarbonImmutable::now();

        if ($occurredAt === null || (is_string($occurredAt) && trim($occurredAt) === '')) {
            return $now;
        }

        try {
            $moment = $occurredAt instanceof CarbonInterface
                ? CarbonImmutable::instance($occurredAt)
                : CarbonImmutable::parse($occurredAt);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'occurred_at' => [__('communications.validation.occurred_at_invalid')],
            ]);
        }

        if ($moment->greaterThan($now->addSeconds(self::CLOCK_DRIFT_SECONDS))) {
            throw ValidationException::withMessages([
                'occurred_at' => [__('communications.validation.occurred_at_future')],
            ]);
        }

        return $moment;
    }

    private function checkDuration(?int $minutes): ?int
    {
        if ($minutes !== null && ($minutes < 0 || $minutes > self::DURATION_MAX_MINUTES)) {
            throw ValidationException::withMessages([
                'duration_minutes' => [__('communications.validation.duration_out_of_range', ['max' => self::DURATION_MAX_MINUTES])],
            ]);
        }

        return $minutes;
    }

    /** Mọi lý do, MỘT câu (SPEC §10.10). */
    private function refuse(): never
    {
        throw new AuthorizationException(__('communications.unavailable'));
    }
}
