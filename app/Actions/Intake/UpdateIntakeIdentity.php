<?php

namespace App\Actions\Intake;

use App\Actions\Intake\Concerns\HoldsConflictCheckLock;
use App\Actions\Intake\Concerns\ValidatesIntakeIdentity;
use App\Models\IntakeParty;
use App\Models\IntakeRequest;
use App\Models\User;
use App\Support\Audit;
use App\Support\ConflictCheckResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Sửa PHẦN DANH TÍNH của một lần tiếp nhận đã ghi — người liên hệ, vai dự kiến, nguồn, người được
 * giao, phí đã báo, và các bên đối lập (M10 Task 3, màn hình sửa). Không bao giờ chạm `summary`
 * (đường duy nhất là {@see UpdateIntakeSummary}) hay `received_at` (con số đo phản hồi, R5).
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
 * ghi cha, đã kiểm ở trên).
 *
 * Luật dữ liệu: {@see ValidatesIntakeIdentity} (cùng luật với lần ghi đầu). Quyền:
 * `IntakeRequestPolicy::update`. Bản ghi đã xong việc ({@see IntakeRequest::isClosedToChanges()}:
 * đã gộp, đã ẩn danh, đã chuyển thành vụ) bị từ chối. Câu đầu tiên của transaction là lần đọc có khoá
 * dòng bản ghi (luật dự án).
 *
 * Nhật ký: bản ghi tự động của model tắt (nó lấy causer theo phiên); dòng `intake_identity_updated`
 * mang causer = actor và CHỈ TÊN các ô đã đổi (`contact_id_number` thay cho cột dấu băm), số bên đối
 * lập, và lần này có kiểm tra lại hay không — không bao giờ giá trị (R7: ẩn danh phải phủ được nhật
 * ký). Không đổi gì thì không ghi dòng nào.
 */
class UpdateIntakeIdentity
{
    use HoldsConflictCheckLock;
    use ValidatesIntakeIdentity;

    /**
     * @param  array<string, mixed>  $attributes  Cùng khoá với `RecordIntake`, trừ `received_at`.
     * @param  array<int, array<string, mixed>>  $opposingParties  Mỗi bên: `id` (dòng đã có, hoặc
     *                                                             null cho bên mới), `name`, `role`, `phone`, `id_number`.
     * @return ConflictCheckResult|null Kết quả kiểm tra lại, hoặc null khi danh tính không đổi.
     */
    public function handle(User $actor, IntakeRequest $intake, array $attributes, array $opposingParties): ?ConflictCheckResult
    {
        Gate::forUser($actor)->authorize('update', $intake);

        $data = $this->validatedIdentity($attributes, $opposingParties, forUpdate: true);

        return $this->underConflictCheckLock(fn (): ?ConflictCheckResult => DB::transaction(function () use ($actor, $intake, $data): ?ConflictCheckResult {
            $locked = IntakeRequest::query()->whereKey($intake->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isClosedToChanges()) {
                throw ValidationException::withMessages(['intake' => [__('intake.errors.record_final')]]);
            }

            $existing = $locked->parties()->get()->keyBy('id');

            foreach ($data['parties'] as $party) {
                if ($party['id'] !== null && ! $existing->has($party['id'])) {
                    throw ValidationException::withMessages(['parties' => [__('intake.errors.party_not_on_record')]]);
                }
            }

            $previousHash = $locked->contact_id_number_hash;

            $locked->fill([
                'contact_name' => $data['contact_name'],
                'contact_phone' => $data['contact_phone'],
                'contact_email' => $data['contact_email'],
                'contact_role' => $data['contact_role'],
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

            $changed = $this->changedFields($locked);
            $partiesChanged = $this->syncParties($locked, $existing, $data['parties']);

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
                'conflict_rechecked' => $result !== null,
            ], $actor);

            return $result;
        }));
    }

    /**
     * Tên các ô người dùng đã đổi, theo tên Ô (không theo tên cột nội bộ): dấu băm CCCD đọc là
     * `contact_id_number`; SĐT chuẩn hoá đi theo `contact_phone` nên không kể riêng.
     *
     * @return list<string>
     */
    private function changedFields(IntakeRequest $intake): array
    {
        return collect(array_keys($intake->getDirty()))
            ->map(fn (string $column): ?string => match ($column) {
                'contact_id_number_hash' => 'contact_id_number',
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
     * @return bool có dòng nào được thêm, sửa hay gỡ
     */
    private function syncParties(IntakeRequest $intake, $existing, array $parties): bool
    {
        $changed = false;
        $keptIds = array_values(array_filter(array_column($parties, 'id')));

        foreach ($existing->except($keptIds) as $removed) {
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

        return $changed;
    }
}
