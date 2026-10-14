<?php

namespace App\Actions\Intake;

use App\Actions\Intake\Concerns\HoldsConflictCheckLock;
use App\Actions\Intake\Concerns\ValidatesIntakeIdentity;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\PartyRole;
use App\Models\IntakeParty;
use App\Models\IntakeRequest;
use App\Models\User;
use App\Notifications\Staff\IntakeAssigned;
use App\Support\Audit;
use App\Support\ConflictCheckResult;
use App\Support\ConflictOverride;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Sửa PHẦN DANH TÍNH của một lần tiếp nhận đã ghi — người liên hệ, vai dự kiến, nguồn, người được
 * giao, phí đã báo, và các bên đối lập (M10 Task 3, màn hình sửa). Không bao giờ chạm `summary`
 * (đường duy nhất là {@see UpdateIntakeSummary}). `received_at` (con số đo phản hồi, R5) chỉ trưởng phòng/quản trị sửa
 * được (làn fa, mục nhỏ: giờ nhận gõ sai), không ở tương lai, không sau `first_response_at`, kèm từ/đến ở
 * dòng nhật ký — {@see self::applyReceivedAt()}.
 *
 * **Kiểm tra lại khi danh tính đổi, trong CÙNG khoá và CÙNG transaction với lần lưu.** Sau khi lưu,
 * nếu dấu vân tay danh tính hiện tại khác dấu vân tay mà lần kiểm tra đã lưu đã chạy trên đó
 * (`conflict_result.fingerprint`, {@see IntakeRequest::identityFingerprint()}) — tức tên, SĐT, CCCD,
 * vai hay một bên đối lập đổi, hoặc chưa từng kiểm tra — thì chạy {@see CheckIntakeConflict} ngay.
 * Đổi nguồn, người giới thiệu, lĩnh vực, phí hay người phụ trách thì không: kết quả cũ vẫn đúng cho
 * danh tính đó. Kiểm tra lại không bao giờ xử lý được một Đỏ (Đỏ dính, fix vòng 1 của Task 2).
 *
 * **Số gốc không được lưu (R7), nên "để trống" nghĩa là "giữ".** Ô CCCD của người liên hệ để trống
 * thì giữ dấu băm đã lưu; nhập số mới thì thay. Một bên đối lập đã có (mang `id`) để trống SĐT/CCCD
 * thì giữ SĐT chuẩn hoá/dấu băm đã lưu của bên đó; nhập thì thay. Muốn BỎ một định danh đã lưu của
 * bên đối lập thì gỡ dòng và thêm lại. SĐT của người liên hệ thì khác: dạng gõ (`contact_phone`) có
 * lưu, nên xoá ô là xoá số. Một `id` không thuộc bản ghi này (request sửa tay) bị từ chối, không đổi
 * gì. Bên đối lập vắng mặt trong danh sách gửi lên bị gỡ (policy `IntakeParty::delete` = sửa bản
 * ghi cha, đã kiểm ở trên). Mã số thuế của tổ chức người gọi đại diện (làn fa, A1) theo cùng luật "để
 * trống là giữ"; bỏ trống tên tổ chức thì bỏ cả dấu băm mã số thuế.
 *
 * **Hai người cùng sửa (làn fa, A2) — kiểm tra lạc quan.** `$expectedVersion` là
 * {@see IntakeRequest::identityEditVersion()} của bản ghi lúc form được nạp. Khác phiên bản của dòng
 * vừa khoá → `ValidationException` khoá `intake` (câu `intake_concurrency.errors.identity_stale`),
 * không đổi gì: trước bản sửa, form của người mở trang trước âm thầm GỠ bên đối lập người kia vừa thêm
 * (dòng mất hẳn, kiểm tra lại ra Xanh) và ghi đè người phụ trách hay phí người kia vừa đổi. `null` = không
 * so (lối vào không có form — lệnh, test Action cũ); màn hình sửa luôn truyền.
 *
 * Luật dữ liệu: {@see ValidatesIntakeIdentity} (cùng luật với lần ghi đầu). Quyền:
 * `IntakeRequestPolicy::update`. Bản ghi đã xong việc ({@see IntakeRequest::isClosedToChanges()}:
 * đã gộp, đã ẩn danh, đã chuyển thành vụ) bị từ chối. Câu đầu tiên của transaction là lần đọc có khoá
 * dòng bản ghi (luật dự án).
 *
 * **Sửa danh tính không được là đường rửa khoá người gọi lại (fix vòng 1 của Task 3, rà soát C1).**
 * Khoá đó ({@see IntakeRequest::sameCallerIntakes()}) nhận ra một cuộc gọi lại theo SĐT chuẩn hoá/dấu
 * băm CCCD và vai ĐÃ KHAI của lần gọi trước — đổi ba thứ đó trên lần gọi trước là thả mọi cuộc gọi lại.
 *  - Bản đã bị từ chối ({@see IntakeRequest::isClosedToIdentityEdits()}): không sửa gì ở phần danh
 *    tính, với bất kỳ ai, vì bất kỳ lý do từ chối nào (một câu cho mọi lý do, R8).
 *  - Bản đang khoá cuộc gọi lại (Đỏ chưa xử lý): người không xử lý được Đỏ không đổi hay xoá được SĐT,
 *    CCCD, vai ĐÃ CÓ ({@see self::guardsCallerKeys()}); thêm cái còn trống, gõ lại cùng số, và mọi ô
 *    khác (tên, email, bên đối lập…) vẫn sửa được — phán quyết fix vòng 1 của Task 2 "người không phải
 *    quản lý VẪN sửa được danh tính và bên đối lập của một bản Đỏ" chỉ hẹp lại ở đúng ba ô này. Quản
 *    lý/admin đổi được (họ là người xử lý Đỏ).
 *
 * Nhật ký: bản ghi tự động của model tắt (nó lấy causer theo phiên); dòng `intake_identity_updated`
 * mang causer = actor và CHỈ TÊN các ô đã đổi (`contact_id_number` thay cho cột dấu băm), số bên đối
 * lập, id các dòng bên đối lập vừa bị gỡ (`removed_party_ids`, làn fa A2), và lần này có kiểm tra lại
 * hay không — không bao giờ giá trị (R7: ẩn danh phải phủ được nhật ký). Không đổi gì thì không ghi dòng
 * nào. Đổi người phụ trách sang một người khác người sửa: người đó nhận thông báo trong hệ thống
 * `IntakeAssigned` sau commit (làn fa, mục nhỏ; người nhận qua `ResolveStaffRecipients::forIntakeAssignment()`).
 */
class UpdateIntakeIdentity
{
    use HoldsConflictCheckLock;
    use ValidatesIntakeIdentity;

    /**
     * @param  array<string, mixed>  $attributes  Cùng khoá với `RecordIntake`, trừ `received_at`.
     * @param  array<int, array<string, mixed>>  $opposingParties  Mỗi bên: `id` (dòng đã có, hoặc
     *                                                             null cho bên mới), `name`, `role`, `phone`, `id_number`.
     * @param  string|null  $expectedVersion  Làn fa (A2): phiên bản form đã nạp — xem docblock lớp.
     * @return ConflictCheckResult|null Kết quả kiểm tra lại, hoặc null khi danh tính không đổi.
     */
    public function handle(User $actor, IntakeRequest $intake, array $attributes, array $opposingParties, ?string $expectedVersion = null): ?ConflictCheckResult
    {
        Gate::forUser($actor)->authorize('update', $intake);

        $data = $this->validatedIdentity($attributes, $opposingParties, forUpdate: true);
        // Làn fa (mục nhỏ): chỉ trưởng phòng/quản trị sửa được giờ nhận; với người khác ô này bị BỎ QUA
        // như trước (hợp đồng cũ của Action: "không lấy received_at từ một lần sửa danh tính").
        $receivedAt = ConflictOverride::allowedFor($actor) ? $this->correctedReceivedAt($attributes) : null;
        $newlyAssigned = null;

        // Closure thường, không `fn`: `$newlyAssigned` phải đi ra ngoài theo THAM CHIẾU qua cả hai lớp.
        $result = $this->underConflictCheckLock(function () use ($actor, $intake, $data, $expectedVersion, $receivedAt, &$newlyAssigned): ?ConflictCheckResult {
            return DB::transaction(function () use ($actor, $intake, $data, $expectedVersion, $receivedAt, &$newlyAssigned): ?ConflictCheckResult {
                $locked = IntakeRequest::query()->whereKey($intake->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->isClosedToChanges()) {
                    throw ValidationException::withMessages(['intake' => [__('intake.errors.record_final')]]);
                }

                if ($locked->isClosedToIdentityEdits()) {
                    throw ValidationException::withMessages(['intake' => [__('intake.errors.identity_declined')]]);
                }

                // Làn fa (A2): kiểm tra lạc quan — xem docblock lớp. Đọc trên dòng vừa khoá, trước mọi thay đổi.
                if ($expectedVersion !== null && ! hash_equals($locked->identityEditVersion(), $expectedVersion)) {
                    throw ValidationException::withMessages(['intake' => [__('intake_concurrency.errors.identity_stale')]]);
                }

                $existing = $locked->parties()->get()->keyBy('id');

                foreach ($data['parties'] as $party) {
                    if ($party['id'] !== null && ! $existing->has($party['id'])) {
                        throw ValidationException::withMessages(['parties' => [__('intake.errors.party_not_on_record')]]);
                    }
                }

                $guardedKeys = static::guardsCallerKeys($actor, $locked) ? static::callerKeys($locked) : null;
                $previousHash = $locked->contact_id_number_hash;
                $previousOrganizationHash = $locked->organization_id_number_hash;

                $locked->fill([
                    'contact_name' => $data['contact_name'],
                    'contact_phone' => $data['contact_phone'],
                    'contact_email' => $data['contact_email'],
                    'contact_role' => $data['contact_role'],
                    'organization_name' => $data['organization_name'],
                    'source' => $data['source'],
                    'referred_by' => $data['referred_by'],
                    'matter_type_id' => $data['matter_type_id'],
                    'quoted_amount' => $data['quoted_amount'],
                    'assigned_to' => $data['assigned_to'],
                ]);
                $locked->identify($data['contact_id_number'], $data['contact_phone']);

                if ($data['contact_id_number'] === null) {
                    $locked->contact_id_number_hash = $previousHash;
                }

                // Làn fa (A1): mã số thuế của tổ chức — cùng luật "để trống là giữ" như CCCD; bỏ tên tổ
                // chức thì bỏ luôn mã số thuế (một dấu băm không còn tổ chức nào để thuộc về).
                $locked->identifyOrganization($data['organization_id_number']);

                if ($data['organization_id_number'] === null) {
                    $locked->organization_id_number_hash = $data['organization_name'] === null ? null : $previousOrganizationHash;
                }

                if ($guardedKeys !== null) {
                    $this->refuseDroppedCallerKeys($guardedKeys, $locked);
                }

                $receivedFrom = $locked->received_at;

                // So theo PHÚT: ô giờ nhận không có giây (`seconds(false)`), nên một form gửi lại đúng phút
                // đã lưu (giây bị cắt) là "không sửa" — không cắt giây của con số đo phản hồi (R5).
                if ($receivedAt !== null && ($locked->received_at === null
                    || ! $receivedAt->startOfMinute()->equalTo(CarbonImmutable::instance($locked->received_at)->startOfMinute()))) {
                    $this->applyReceivedAt($actor, $locked, $receivedAt);
                }

                $changed = $this->changedFields($locked);
                [$partiesChanged, $removedPartyIds] = $this->syncParties($locked, $existing, $data['parties']);

                if ($partiesChanged) {
                    $changed[] = 'parties';
                }

                if ($changed === []) {
                    return null;
                }

                $locked->blameOn($actor);
                $locked->disableLogging()->save();
                $locked->enableLogging();

                $stale = ($locked->conflict_result['fingerprint'] ?? null) !== $locked->identityFingerprint();
                $result = $stale ? app(CheckIntakeConflict::class)->handle($actor, $locked) : null;

                Audit::record('intake_identity_updated', $locked, [
                    'changed' => $changed,
                    'opposing_parties' => $locked->parties()->count(),
                    // Làn fa (A2): id các dòng bên đối lập lần lưu này đã GỠ (dòng mất hẳn — `IntakeParty`
                    // không xoá mềm). Id, không tên (R7: ẩn danh phải phủ được nhật ký).
                    'removed_party_ids' => $removedPartyIds,
                    'conflict_rechecked' => $result !== null,
                    // Làn fa (mục nhỏ): giờ nhận là con số đo phản hồi (R5) — lần sửa ghi từ/đến.
                    ...(in_array('received_at', $changed, true) ? [
                        'received_at_from' => $receivedFrom?->toIso8601String(),
                        'received_at_to' => $locked->received_at?->toIso8601String(),
                    ] : []),
                ], $actor);

                // Làn fa (mục nhỏ): người vừa được giao được báo — ở dưới, NGOÀI transaction, sau commit.
                if (in_array('assigned_to', $changed, true) && $locked->assigned_to !== null) {
                    $newlyAssigned = $locked;
                }

                return $result;
            });
        });

        if ($newlyAssigned !== null) {
            Notification::send(
                app(ResolveStaffRecipients::class)->forIntakeAssignment($newlyAssigned, $actor),
                new IntakeAssigned($newlyAssigned),
            );
        }

        return $result;
    }

    /**
     * Làn fa (kiểm tra nghiệp vụ 2026-10-09, mục nhỏ): giờ nhận cuộc gọi muốn sửa, hoặc null khi lần lưu
     * không gửi ô này (người không phải trưởng phòng/quản trị không thấy ô trên trang sửa). Chuỗi hỏng hay
     * thời điểm ở tương lai là lỗi ở ô `received_at` — cùng luật lần ghi đầu (`ValidatesIntakeIdentity`).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function correctedReceivedAt(array $attributes): ?CarbonImmutable
    {
        $raw = $attributes['received_at'] ?? null;

        if (! filled($raw)) {
            return null;
        }

        try {
            $value = CarbonImmutable::parse($raw);
        } catch (InvalidFormatException) {
            throw ValidationException::withMessages(['received_at' => [__('intake_reopen.errors.received_at_invalid')]]);
        }

        if ($value->isFuture()) {
            throw ValidationException::withMessages(['received_at' => [__('intake.errors.received_at_in_future')]]);
        }

        return $value;
    }

    /**
     * Sửa giờ nhận (mục nhỏ, làn fa) trên dòng vừa khoá — người gọi đã lọc: chỉ trưởng phòng/quản trị
     * (`ConflictOverride`; người được giao hay người nhấc máy không tự sửa con số đo phản hồi của chính
     * mình, R5, và với họ ô bị bỏ qua). Không sau lần phản hồi đầu (`first_response_at`) — giờ nhận sau giờ phản hồi làm thời gian phản hồi âm.
     */
    private function applyReceivedAt(User $actor, IntakeRequest $locked, CarbonImmutable $receivedAt): void
    {
        if ($locked->first_response_at !== null && $receivedAt->greaterThan($locked->first_response_at)) {
            throw ValidationException::withMessages(['received_at' => [__('intake_reopen.errors.received_at_after_response', [
                'at' => $locked->first_response_at->format('H:i d/m/Y'),
            ])]]);
        }

        $locked->received_at = $receivedAt;
    }

    /**
     * Người này có bị giữ ngoài SĐT, CCCD và vai của người liên hệ trên bản ghi này không (fix vòng 1 —
     * rà soát Task 3, C1): bản ghi đang khoá các lần gọi lại của cùng người
     * ({@see IntakeRequest::locksRepeatCalls()}) và người này không xử lý được Đỏ
     * (`IntakeRequestPolicy::resolveConflict`). MỘT định nghĩa cho lời từ chối của Action và, qua
     * {@see self::guardsCallerKey()}, cho các ô bị khoá trên form sửa (`IntakeRequestForm`).
     */
    public static function guardsCallerKeys(User $actor, IntakeRequest $intake): bool
    {
        return $intake->locksRepeatCalls() && Gate::forUser($actor)->denies('resolveConflict', $intake);
    }

    /**
     * Một ô cụ thể (`contact_phone`, `contact_id_number` hoặc `contact_role`) có bị giữ khỏi người này
     * không: {@see self::guardsCallerKeys()} VÀ ô đó ĐÃ CÓ giá trị đã lưu — đúng điều kiện mà
     * {@see self::refuseDroppedCallerKeys()} từ chối một thay đổi (ô còn trống thì thêm được). Form
     * trang sửa (`IntakeRequestForm`) khoá từng ô theo hàm này, để ô nó mở là ô Action nhận.
     */
    public static function guardsCallerKey(User $actor, IntakeRequest $intake, string $field): bool
    {
        return static::callerKeys($intake)[$field] !== null && static::guardsCallerKeys($actor, $intake);
    }

    /**
     * Ba thông tin mà khoá người gọi lại đọc ở lần gọi trước ({@see IntakeRequest::sameCallerIntakes()}),
     * theo tên Ô trên form.
     *
     * @return array{contact_phone: ?string, contact_id_number: ?string, contact_role: ?PartyRole}
     */
    private static function callerKeys(IntakeRequest $intake): array
    {
        return [
            'contact_phone' => $intake->contact_phone_normalized,
            'contact_id_number' => $intake->contact_id_number_hash,
            'contact_role' => $intake->contact_role,
        ];
    }

    /**
     * Từ chối khi một thông tin ĐÃ CÓ bị đổi hoặc xoá — tức các lần gọi lại theo giá trị cũ không còn
     * ghép được với bản này. Thêm một thông tin còn trống, hay gõ lại cùng một số theo dạng khác (cùng
     * dạng chuẩn hoá), thì không: bản này chỉ bắt được NHIỀU lần gọi lại hơn. Lỗi gắn vào đúng ô.
     *
     * @param  array<string, mixed>  $before
     */
    private function refuseDroppedCallerKeys(array $before, IntakeRequest $intake): void
    {
        $dropped = array_keys(array_filter(
            static::callerKeys($intake),
            fn (mixed $now, string $field): bool => $before[$field] !== null && $now !== $before[$field],
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($dropped !== []) {
            throw ValidationException::withMessages(array_fill_keys($dropped, [__('intake.errors.caller_keys_locked')]));
        }
    }

    /**
     * Tên các ô người dùng đã đổi, theo tên Ô (không theo tên cột nội bộ): dấu băm CCCD đọc là
     * `contact_id_number` (mã số thuế của tổ chức: `organization_id_number`); SĐT chuẩn hoá đi theo
     * `contact_phone` nên không kể riêng.
     *
     * @return list<string>
     */
    private function changedFields(IntakeRequest $intake): array
    {
        return collect(array_keys($intake->getDirty()))
            ->map(fn (string $column): ?string => match ($column) {
                'contact_id_number_hash' => 'contact_id_number',
                'organization_id_number_hash' => 'organization_id_number',
                'contact_phone_normalized' => null,
                default => $column,
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, IntakeParty>  $existing
     * @param  list<array<string, mixed>>  $parties
     * @return array{0: bool, 1: list<int>} có dòng nào được thêm, sửa hay gỡ; id các dòng đã gỡ
     */
    private function syncParties(IntakeRequest $intake, $existing, array $parties): array
    {
        $changed = false;
        $removedIds = [];
        $keptIds = array_values(array_filter(array_column($parties, 'id')));

        foreach ($existing->except($keptIds) as $removed) {
            $removedIds[] = (int) $removed->getKey();
            $removed->delete();
            $changed = true;
        }

        foreach ($parties as $partyData) {
            $party = $partyData['id'] !== null ? $existing->get($partyData['id']) : new IntakeParty;
            $storedHash = $party->id_number_hash;
            $storedPhone = $party->phone_normalized;

            $party->fill(['role' => $partyData['role'], 'name' => $partyData['name']]);
            $party->identify($partyData['id_number'], $partyData['phone']);

            if ($partyData['id_number'] === null) {
                $party->id_number_hash = $storedHash;
            }

            if ($partyData['phone'] === null) {
                $party->phone_normalized = $storedPhone;
            }

            if (! $party->exists) {
                $intake->parties()->save($party);
                $changed = true;
            } elseif ($party->isDirty()) {
                $party->save();
                $changed = true;
            }
        }

        return [$changed, $removedIds];
    }
}
