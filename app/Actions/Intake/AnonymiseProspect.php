<?php

namespace App\Actions\Intake;

use App\Actions\Intake\Concerns\HoldsConflictCheckLock;
use App\Enums\IntakeStatus;
use App\Models\IntakeParty;
use App\Models\IntakeRequest;
use App\Models\User;
use App\Support\Audit;
use App\Support\Normalizer;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Ẩn danh dữ liệu của một người liên hệ KHÔNG thành khách (M10 R7b, R7c; Task 7). MỘT Action, hai cửa:
 *  - {@see self::expire()} — hết hạn lưu: tác vụ hằng ngày `AnonymiseExpiredProspects` gọi cho từng
 *    bản ghi quá `retention_until` (`IntakeRequest::scopeRetentionExpired()`: `declined`/`lost`/
 *    `merged`, chưa chuyển đổi, chưa ẩn danh). Audit `prospect_data_anonymised` (mã, ngày hạn).
 *  - {@see self::erase()} — "Xoá dữ liệu theo yêu cầu" của chủ thể: chỉ admin (`IntakeRequestPolicy::
 *    erase`), lý do ≥ 20 ký tự đếm bằng `mb_strlen` sau khi bỏ khoảng trắng hai đầu (tối đa 2000), trên
 *    bản ghi bất kỳ trạng thái nào CHƯA chuyển đổi. Audit `prospect_data_erased`: người làm (causer),
 *    lý do, mã bản ghi — KHÔNG giá trị đã xoá.
 * Bản ghi đã chuyển thành vụ việc (`won` hoặc có `matter_id`) bị từ chối: người đó đã là khách, dữ liệu
 * theo hồ sơ khách ({@see self::refusal()}). Cùng lý do dọc chuỗi gộp (fix vòng 1 — rà soát Task 7, I1):
 * bản đã gộp vào một bản mà bản cuối của chuỗi gộp đã thành vụ (`IntakeRequest::convertedMergeTarget()`)
 * bị từ chối ở cửa xoá (câu nêu mã bản đã thành vụ) và bỏ qua ở cửa hết hạn. Bản đã ẩn danh bị từ chối
 * (cửa xoá) hoặc bỏ qua (cửa hết hạn).
 *
 * **Ẩn danh là CẬP NHẬT, không bao giờ xoá dòng** (R7 — test cấu trúc `IntakeNoForceDeleteTest`): dòng,
 * mã, nguồn, trạng thái, vai dự kiến, lĩnh vực, phí đã báo, người ghi/được giao, mức và thời điểm kiểm
 * tra, cờ Đỏ đang chờ, cờ từ chối vì xung đột, ghi nhận thông báo và mọi mốc thời gian ở lại cho thống
 * kê (báo cáo Task 6 vẫn đếm). Về null:
 *  1. các cột cá nhân của bản ghi — tên (và tên chuẩn hoá), SĐT (và dạng chuẩn hoá), email, dấu băm CCCD,
 *     người giới thiệu, câu chuyện, lý do từ chối, lý do ghi đè Đỏ — và cả `conflict_result` (bản chụp
 *     kết quả mang tên người);
 *  2. tên, tên chuẩn hoá, SĐT chuẩn hoá và dấu băm CCCD của MỌI bên đối lập (dòng và vai ở lại). Dấu băm
 *     bị xoá luôn: SHA-256 không khoá của SĐT/CCCD dò ngược được, tức là giả danh chứ không phải ẩn
 *     danh, và việc giữ nó để dò xung đột còn CHỜ chủ văn phòng/luật sư xác nhận (R7b, mục 2) — nên người
 *     đã ẩn danh không còn là nguồn dò thứ hai của `RunConflictCheck` nữa.
 * Rồi ghi `anonymised_at`, `anonymised_by` (null ở cửa hết hạn), `anonymised_reason`.
 *
 * **Dữ liệu người đó để lại NGOÀI các cột của bản ghi** (rà theo mã thật, xem PROGRESS "Ghi chú M10",
 * Task 7) cũng được làm sạch trong CÙNG transaction — tên bị thay bằng chữ "(đã ẩn danh)", mã `TN-…`,
 * vai, mức, bậc khớp và ngày liên hệ ở lại làm bằng chứng đã kiểm tra:
 *  - nhật ký của CHÍNH bản ghi: mọi tên trong các dòng `conflict_check_run` (cả hai phía của từng khớp,
 *    và danh sách bên thiếu định danh) — kể cả dòng của một lần chuyển đổi KHÔNG thành vụ, mà
 *    `ConvertIntakeToMatter` để lại với bản ghi (`$checkSubject` của `OpenMatter`, fix vòng 1 — rà soát
 *    Task 7, C1: dòng đó không mang mã `TN-…` của bản ghi, nên chỉ tìm được theo chủ thể); lý do trong
 *    các dòng `intake_conflict_overridden`; và khoá `confirmed_pairs` (chữ ký HMAC của danh tính) của
 *    các dòng ghi đè và xác nhận;
 *  - bằng chứng kiểm tra của bản ghi KHÁC và vụ việc đã TÌM THẤY người này qua nguồn dò thứ hai: mỗi
 *    khớp mang `matter_code` = mã `TN-…` của bản ghi này thì mất `party_name` — trong `conflict_result`
 *    của các bản ghi tiếp nhận khác và trong mọi dòng `conflict_check_run` (chủ thể là bản ghi khác, vụ
 *    việc, hay rỗng — lần `OpenMatter` bị từ chối);
 *  - bên đối lập của bản ghi này đã được MANG SANG lần kiểm tra của một lần gọi lại cùng người
 *    (`IntakeRequest::sameCallerIntakes()`, khớp ở phía "của mình", không mang mã của bản này): trong
 *    `conflict_result` và các dòng `conflict_check_run` của mọi bản ghi khác cùng SĐT chuẩn hoá hoặc cùng
 *    dấu băm CCCD, khớp có `our_party_name` trùng (chuẩn hoá) tên một bên đối lập của bản này mà bản kia
 *    KHÔNG tự có (tên người liên hệ và tên bên đối lập của chính bản kia giữ nguyên — đó là dữ liệu của
 *    nó). Đọc định danh TRƯỚC khi xoá;
 *  - sổ tra khách (việc sau gộp M9 + M10, làn fu3, Task 1 mục E): mỗi lần ghi nhận và mỗi lần chuyển đổi
 *    chạy `FindClientByIdentifier`, thứ ghi dòng `client_lookup` (và `client_lookup_throttled` khi chạm
 *    trần) mang `identifier_hash` — HMAC của chữ số ĐÃ GÕ, cùng hàm băm với dấu băm CCCD của bản ghi.
 *    `identifier_hash` của mọi dòng như vậy khớp SĐT hoặc CCCD của bản ghi thành null; DÒNG ở lại (ai
 *    tra, lúc nào, trúng hay trượt, id khách khớp). SĐT tính theo mọi cách viết thường gặp của cùng số
 *    ({@see self::lookupHashesOf()}), vì nhân sự khác có thể đã gõ `+84 …` thay cho `0…`. Đọc định danh
 *    TRƯỚC khi xoá.
 * Những gì CỐ Ý còn giữ, và vì sao, ghi ở PROGRESS (chữ ký `confirmed_pairs` trong nhật ký của bản ghi
 * KHÁC, bản sao lưu cũ — 30 bản đêm, cộng khoảng 30 ngày trong Thùng rác của Google Drive). Dấu băm trong
 * sổ tra khách từng nằm trong danh sách này (Task 7);
 * làn fu3 xoá nó, và quyết định "giữ dấu băm để dò xung đột" vẫn chờ luật sư xác nhận.
 *
 * **Khoá.** Ẩn danh đổi đầu vào của kiểm tra xung đột (người này rời nguồn dò thứ hai), nên chạy dưới
 * khoá `conflict-check` — cùng khoá mà mọi đường GHI một lần kiểm tra giữ (`OpenMatter`, `AddMatterParty`,
 * `UpdateMatterParty`, các Action tiếp nhận, job kiểm tra lại khi hồ sơ khách đổi định danh): không lần
 * kiểm tra nào đã đọc người này lại ghi tên họ vào nhật ký SAU khi đã làm sạch. Câu đầu tiên của
 * transaction là lần đọc có khoá dòng bản ghi; cửa hết hạn đọc lại điều kiện hết hạn TRÊN dòng đó (cùng
 * scope), nên chạy hai lần không đổi gì.
 */
class AnonymiseProspect
{
    use HoldsConflictCheckLock;

    public const ERASE_REASON_MIN_LENGTH = 20;

    public const ERASE_REASON_MAX_LENGTH = 2000;

    /**
     * Cột cá nhân về null bằng `forceFill`. `contact_phone_normalized` và `contact_id_number_hash` không
     * có ở đây: `IntakeRequest::fill()` chặn chúng, đường ghi duy nhất là `identify()`.
     * `contact_name_normalized` theo `contact_name` (móc `saving` của model).
     */
    private const PERSONAL_COLUMNS = [
        'contact_name', 'contact_phone', 'contact_email', 'referred_by', 'summary',
        'decline_reason', 'conflict_override_reason', 'conflict_result',
    ];

    /** Các sự kiện nhật ký của chính bản ghi mang dữ liệu người liên hệ — xem docblock lớp. */
    private const OWN_TRAIL_EVENTS = ['conflict_check_run', 'intake_conflict_overridden', 'intake_conflict_acknowledged'];

    /**
     * Các sự kiện của sổ tra khách mang `identifier_hash` (`FindClientByIdentifier`, và `CreateClient` khi
     * chạm trần) — xem docblock lớp.
     */
    private const LOOKUP_EVENTS = ['client_lookup', 'client_lookup_throttled'];

    /**
     * Câu từ chối nếu bản ghi KHÔNG xoá theo yêu cầu được, null nếu được — MỘT định nghĩa cho Action và
     * cho màn hình (nút chỉ hiện khi null; trên bản đã chuyển đổi, hay đã gộp vào một bản đã thành vụ,
     * khối "Kết quả xử lý" nói câu này với admin).
     */
    public static function refusal(IntakeRequest $intake): ?string
    {
        if ($intake->status === IntakeStatus::Won || $intake->matter_id !== null) {
            return __('intake.anonymise.errors.converted');
        }

        if ($intake->anonymised_at !== null) {
            return __('intake.anonymise.errors.already');
        }

        // Fix vòng 1 (rà soát Task 7, I1): R7c dọc chuỗi gộp — câu nêu mã bản đã thành vụ.
        $client = $intake->convertedMergeTarget();

        return $client === null ? null : __('intake.anonymise.errors.converted_through_merge', ['code' => $client->code]);
    }

    /**
     * Xoá dữ liệu theo yêu cầu của người liên hệ (R7c) — xem docblock lớp. Thứ tự: quyền; trạng thái trên
     * bản đang cầm (để admin biết ngay một bản đã chuyển đổi không xoá ở đây, trước khi bị bắt sửa lý do);
     * lý do; rồi khoá dòng và đọc lại trạng thái trên dòng đó (bản ghi có thể vừa đổi ở tab khác).
     */
    public function erase(User $actor, IntakeRequest $intake, string $reason): IntakeRequest
    {
        Gate::forUser($actor)->authorize('erase', $intake);

        $this->refuseIfNotErasable($intake);

        $reason = trim($reason);

        if (mb_strlen($reason) < self::ERASE_REASON_MIN_LENGTH) {
            throw ValidationException::withMessages(['erase_reason' => [__('intake.anonymise.errors.reason_too_short', [
                'min' => self::ERASE_REASON_MIN_LENGTH,
            ])]]);
        }

        if (mb_strlen($reason) > self::ERASE_REASON_MAX_LENGTH) {
            throw ValidationException::withMessages(['erase_reason' => [__('intake.anonymise.errors.reason_too_long', [
                'max' => self::ERASE_REASON_MAX_LENGTH,
            ])]]);
        }

        return $this->underConflictCheckLock(fn (): IntakeRequest => DB::transaction(function () use ($actor, $intake, $reason): IntakeRequest {
            $locked = IntakeRequest::query()
                ->withoutGlobalScope(ClientPortalScope::class)
                ->whereKey($intake->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->refuseIfNotErasable($locked);

            $this->anonymise($locked, $actor, $reason);

            Audit::record('prospect_data_erased', $locked, ['code' => $locked->code, 'reason' => $reason], $actor);

            return $locked;
        }));
    }

    /**
     * Hết hạn lưu (R7b): ẩn danh `$intake` nếu — đọc lại trên dòng vừa khoá — nó còn thoả
     * `IntakeRequest::scopeRetentionExpired()` VÀ không được gộp vào một bản đã thành vụ việc
     * (`IntakeRequest::convertedMergeTarget()`, fix vòng 1 — rà soát Task 7, I1). Bản như vậy bình thường
     * không còn hạn để mà quá (`ConvertIntakeToMatter` xoá hạn của cả cây gộp); câu hỏi ở đây là lưới thứ
     * hai cho một hạn vẫn còn đó vì bất kỳ lý do gì. Trả true khi đã ẩn danh, false khi không còn gì để
     * làm (chưa tới hạn, đã ẩn danh, đã chuyển đổi — chính nó hay bản cuối của chuỗi gộp —, không còn
     * tồn tại). Bản đã xoá mềm vẫn mang dữ liệu cá nhân, nên vẫn được ẩn danh.
     */
    public function expire(IntakeRequest $intake): bool
    {
        return $this->underConflictCheckLock(fn (): bool => DB::transaction(function () use ($intake): bool {
            $locked = IntakeRequest::query()
                ->withoutGlobalScope(ClientPortalScope::class)
                ->withTrashed()
                ->whereKey($intake->getKey())
                ->retentionExpired()
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->convertedMergeTarget() !== null) {
                return false;
            }

            $this->anonymise($locked, null, __('intake.anonymise.retention_reason', [
                'date' => $locked->retention_until->format('d/m/Y'),
            ]));

            Audit::record('prospect_data_anonymised', $locked, [
                'code' => $locked->code,
                'retention_until' => $locked->retention_until->toDateString(),
            ]);

            return true;
        }));
    }

    private function refuseIfNotErasable(IntakeRequest $intake): void
    {
        $refusal = self::refusal($intake);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['intake' => [$refusal]]);
        }
    }

    /** Phần chung của hai cửa — người gọi đang giữ khoá `conflict-check` và dòng `$locked`. */
    private function anonymise(IntakeRequest $locked, ?User $actor, string $reason): void
    {
        $parties = fn () => IntakeParty::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->where('intake_request_id', $locked->getKey());

        // Đọc TRƯỚC khi xoá: mã, SĐT, dấu băm và tên các bên là chìa khoá để tìm dấu vết ở nơi khác.
        $partyNames = $parties()->pluck('name_normalized')->filter()->unique()->values()->all();

        $this->scrubOwnTrail($locked);
        $this->scrubFoundByOthers($locked->code);
        $this->scrubCarriedIntoRepeatCalls($locked, $partyNames);
        $this->scrubClientLookups(self::lookupHashesOf($locked));

        $parties()->update(['name' => null, 'name_normalized' => null, 'phone_normalized' => null, 'id_number_hash' => null]);

        $locked->forceFill(array_fill_keys(self::PERSONAL_COLUMNS, null));
        $locked->identify(null, null);
        $locked->forceFill([
            'anonymised_at' => now(),
            'anonymised_by' => $actor?->getKey(),
            'anonymised_reason' => $reason,
        ]);

        if ($actor !== null) {
            $locked->blameOn($actor);
        }

        // Nhật ký tự động của model không mang cột cá nhân nào (`getActivitylogOptions()`); dòng audit
        // tường minh của hai cửa là bản ghi duy nhất của việc này.
        $locked->disableLogging()->save();
        $locked->enableLogging();
    }

    /** Nhật ký của CHÍNH bản ghi — xem docblock lớp. */
    private function scrubOwnTrail(IntakeRequest $locked): void
    {
        $everyMatch = fn (array $match): bool => true;

        Activity::query()
            ->where('subject_type', $locked->getMorphClass())
            ->where('subject_id', $locked->getKey())
            ->whereIn('event', self::OWN_TRAIL_EVENTS)
            ->get()
            ->each(function (Activity $row) use ($everyMatch): void {
                $properties = $row->properties?->all() ?? [];

                if ($row->event === 'conflict_check_run') {
                    $this->saveProperties($row, $properties, self::scrubResult($properties, $everyMatch, $everyMatch, scrubIncomplete: true));

                    return;
                }

                $scrubbed = $properties;
                unset($scrubbed['confirmed_pairs']);

                if (($scrubbed['override_reason'] ?? null) !== null) {
                    $scrubbed['override_reason'] = __('intake.anonymise.placeholder');
                }

                $this->saveProperties($row, $properties, $scrubbed);
            });
    }

    /**
     * Bằng chứng kiểm tra của bản ghi KHÁC và vụ việc mà nguồn dò thứ hai đã tìm thấy người này: mọi khớp
     * mang mã `TN-…` của bản ghi. Mã sinh bởi `IntakeRequest::nextCode()` (`TN-YYYY-NNNN`) không có ký tự
     * đại diện của LIKE; LIKE chỉ lọc ứng viên, so khớp thật là phép so bằng trên `matter_code`.
     */
    private function scrubFoundByOthers(string $code): void
    {
        $pattern = '%"'.$code.'"%';
        $pointsHere = fn (array $match): bool => ($match['matter_code'] ?? null) === $code;
        $never = fn (array $match): bool => false;

        Activity::query()
            ->where('event', 'conflict_check_run')
            ->where('properties', 'like', $pattern)
            ->get()
            ->each(fn (Activity $row) => $this->saveProperties(
                $row, $row->properties?->all() ?? [], self::scrubResult($row->properties?->all() ?? [], $pointsHere, $never),
            ));

        IntakeRequest::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->withTrashed()
            ->where('conflict_result', 'like', $pattern)
            ->get()
            ->each(fn (IntakeRequest $other) => $this->saveConflictResult(
                $other, self::scrubResult($other->conflict_result ?? [], $pointsHere, $never),
            ));
    }

    /**
     * Bên đối lập của bản ghi này đã được mang sang lần kiểm tra của một lần gọi lại cùng người — xem
     * docblock lớp. `$partyNames`: tên chuẩn hoá các bên đối lập của bản này, đọc trước khi xoá.
     *
     * @param  list<string>  $partyNames
     */
    private function scrubCarriedIntoRepeatCalls(IntakeRequest $locked, array $partyNames): void
    {
        $phone = $locked->contact_phone_normalized;
        $hash = $locked->contact_id_number_hash;

        if ($partyNames === [] || ($phone === null && $hash === null)) {
            return;
        }

        $never = fn (array $match): bool => false;

        IntakeRequest::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->withTrashed()
            ->whereKeyNot($locked->getKey())
            ->where(fn ($q) => $q
                ->when($phone, fn ($w) => $w->orWhere('contact_phone_normalized', $phone))
                ->when($hash, fn ($w) => $w->orWhere('contact_id_number_hash', $hash)))
            ->get()
            ->each(function (IntakeRequest $other) use ($partyNames, $never): void {
                $ownNames = IntakeParty::query()
                    ->withoutGlobalScope(ClientPortalScope::class)
                    ->where('intake_request_id', $other->getKey())
                    ->pluck('name_normalized')
                    ->push($other->contact_name_normalized)
                    ->filter()
                    ->all();

                $carried = array_values(array_diff($partyNames, $ownNames));

                if ($carried === []) {
                    return;
                }

                $isCarried = fn (array $match): bool => in_array(Normalizer::name($match['our_party_name'] ?? null), $carried, true);

                if (is_array($other->conflict_result)) {
                    $this->saveConflictResult($other, self::scrubResult($other->conflict_result, $never, $isCarried));
                }

                Activity::query()
                    ->where('subject_type', $other->getMorphClass())
                    ->where('subject_id', $other->getKey())
                    ->where('event', 'conflict_check_run')
                    ->get()
                    ->each(fn (Activity $row) => $this->saveProperties(
                        $row, $row->properties?->all() ?? [], self::scrubResult($row->properties?->all() ?? [], $never, $isCarried),
                    ));
            });
    }

    /**
     * Sổ tra khách — xem docblock lớp: `identifier_hash` của mọi dòng `client_lookup`/
     * `client_lookup_throttled` mang một trong `$hashes` thành null; dòng và mọi khoá khác ở lại.
     *
     * @param  list<string>  $hashes
     */
    private function scrubClientLookups(array $hashes): void
    {
        if ($hashes === []) {
            return;
        }

        Activity::query()
            ->whereIn('event', self::LOOKUP_EVENTS)
            ->whereIn('properties->identifier_hash', $hashes)
            ->get()
            ->each(function (Activity $row): void {
                $properties = $row->properties?->all() ?? [];

                $this->saveProperties($row, $properties, [...$properties, 'identifier_hash' => null]);
            });
    }

    /**
     * Mọi `identifier_hash` mà một lần tra SĐT hay CCCD của người này có thể đã ghi. Sổ tra khách băm
     * CHỮ SỐ của đúng chuỗi đã gõ (`FindClientByIdentifier`), không băm dạng chuẩn hoá, nên một số điện
     * thoại cho nhiều dấu băm: chuỗi đã lưu của bản ghi (đúng thứ lần ghi nhận và lần chuyển đổi đã tra),
     * cộng các cách viết mà `Normalizer::phone()` đưa về cùng một số: dạng chuẩn hoá (`84…`, cả `+84 …`) và
     * `00` + dạng đó với mọi số; riêng số Việt Nam (dạng chuẩn hoá bắt đầu bằng `84`) thêm `0…`, số thuê
     * bao trần và `840…` (`+84 (0) …`) — với số nước ngoài, cắt hai chữ số đầu ra là số của người khác.
     * CCCD: dấu băm của bản ghi đã là HMAC của chữ số (`Normalizer::idNumberHash()` =
     * `Audit::identifierHash()`). Đọc TRƯỚC khi xoá cột.
     *
     * @return list<string>
     */
    private static function lookupHashesOf(IntakeRequest $intake): array
    {
        $digitForms = [preg_replace('/\D+/', '', (string) $intake->contact_phone) ?? ''];
        $normalized = $intake->contact_phone_normalized;

        if ($normalized !== null) {
            $digitForms[] = $normalized;
            $digitForms[] = '00'.$normalized;

            if (str_starts_with($normalized, '84')) {
                $subscriber = substr($normalized, 2);

                array_push($digitForms, '0'.$subscriber, $subscriber, '840'.$subscriber);
            }
        }

        $hashes = collect($digitForms)
            ->filter(fn (string $digits): bool => $digits !== '')
            ->map(fn (string $digits): string => Audit::identifierHash($digits));

        if ($intake->contact_id_number_hash !== null) {
            $hashes->push($intake->contact_id_number_hash);
        }

        return $hashes->unique()->values()->all();
    }

    /**
     * Thay tên trong một kết quả kiểm tra (`ConflictCheckResult::toArray()`, cùng hình dạng ở
     * `conflict_result` và ở dòng `conflict_check_run`) bằng chữ "(đã ẩn danh)": `party_name` (phía tìm
     * thấy) của khớp `$found` chọn, `our_party_name` (phía của mình) của khớp `$ours` chọn, và — nếu
     * `$scrubIncomplete` — từng tên trong `incomplete_parties` (giữ số lượng). Mọi khoá khác giữ nguyên.
     *
     * @param  array<string, mixed>  $result
     * @param  callable(array<string, mixed>): bool  $found
     * @param  callable(array<string, mixed>): bool  $ours
     * @return array<string, mixed>
     */
    private static function scrubResult(array $result, callable $found, callable $ours, bool $scrubIncomplete = false): array
    {
        $placeholder = __('intake.anonymise.placeholder');

        foreach (['matches', 'confirmed_matches'] as $key) {
            if (! is_array($result[$key] ?? null)) {
                continue;
            }

            $result[$key] = array_map(function (mixed $match) use ($found, $ours, $placeholder): mixed {
                if (! is_array($match)) {
                    return $match;
                }

                if (array_key_exists('party_name', $match) && $found($match)) {
                    $match['party_name'] = $placeholder;
                }

                if (array_key_exists('our_party_name', $match) && $ours($match)) {
                    $match['our_party_name'] = $placeholder;
                }

                return $match;
            }, $result[$key]);
        }

        if ($scrubIncomplete && is_array($result['incomplete_parties'] ?? null)) {
            $result['incomplete_parties'] = array_map(fn (): string => $placeholder, $result['incomplete_parties']);
        }

        return $result;
    }

    /**
     * Ghi lại `properties` của một dòng nhật ký khi có thay đổi. Câu UPDATE thẳng (không qua model): làm
     * sạch không phải một lần sửa của ai, nên không đổi `updated_at` và không bắn sự kiện. Cùng cách mã
     * hoá JSON với cast `collection` của Spatie.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function saveProperties(Activity $row, array $before, array $after): void
    {
        if ($after === $before) {
            return;
        }

        Activity::query()->whereKey($row->getKey())->toBase()->update(['properties' => json_encode($after, JSON_THROW_ON_ERROR)]);
    }

    /**
     * Ghi lại `conflict_result` của một bản ghi KHÁC khi có thay đổi — cùng lý do và cùng cách với
     * {@see self::saveProperties()}: không `updated_at`, không người sửa, không sự kiện (cột này không vào
     * nhật ký tự động, và dấu vân tay danh tính lưu kèm giữ nguyên nên bản kia không thành "cần kiểm tra
     * lại").
     *
     * @param  array<string, mixed>  $after
     */
    private function saveConflictResult(IntakeRequest $other, array $after): void
    {
        if ($after === $other->conflict_result) {
            return;
        }

        IntakeRequest::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->withTrashed()
            ->whereKey($other->getKey())
            ->toBase()
            ->update(['conflict_result' => json_encode($after, JSON_THROW_ON_ERROR)]);
    }
}
