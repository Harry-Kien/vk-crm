<?php

namespace App\Actions\Intake;

use App\Actions\Client\FindClientByIdentifier;
use App\Enums\Permission;
use App\Exceptions\ClientLookupThrottled;
use App\Exceptions\DuplicateClientNotVisible;
use App\Models\IntakeRequest;
use App\Models\User;
use App\Support\Intake\IntakeDuplicates;
use App\Support\Normalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Dò trùng lúc nhập (M10 R4): "một người gọi ba lần trong một tuần không được thành ba bản ghi
 * không liên quan". Chỉ ĐỌC (ngoài dòng audit `client_lookup` mà bước tra khách ghi) — gợi ý, không
 * chặn; gộp là thao tác riêng của màn hình (Task 3).
 *
 * **Chuẩn hoá bằng `Normalizer` có sẵn** — `0832270898`, `+84832270898`, `84832270898` và
 * `(+84) 832 270 898` là MỘT số; không có bộ chuẩn hoá thứ hai.
 *
 * **Ranh giới lộ thông tin (xem {@see IntakeDuplicates}):**
 *  - Bản cũ chỉ được gợi ý khi khớp ĐÚNG SĐT hoặc dấu băm CCCD, và chỉ những bản người nhập XEM
 *    ĐƯỢC (`visibleTo`, đã tính vụ `restricted`). Bản còn mở mà họ không xem được chỉ hiện dưới dạng
 *    một cờ `hasHiddenSameIdentity`; bản đã chuyển đổi mà họ không xem được thì KHÔNG thành cờ (nếu
 *    không, "số này đã liên hệ" lộ ra một khách của vụ `restricted`).
 *  - Khớp theo tên: chỉ cho người có `intake.viewAny`.
 *  - Khớp một KHÁCH HÀNG: chỉ một câu có/không, qua đúng `FindClientByIdentifier` (cùng giới hạn 20
 *    lần/giờ/nhân sự với ô "Tra khách hàng" và dò trùng khi tạo khách — một oracle, một bộ đếm; và
 *    cùng ranh giới `restricted`). Chạm trần thì gợi ý tắt (`clientLookupUnavailable`), không làm hỏng
 *    việc ghi nhận. Khách thuộc vụ `restricted` mà người nhập không được biết thì trả lời như "không".
 *
 * Bản đã gộp vào bản khác và bản đã ẩn danh không bao giờ là gợi ý.
 */
class FindIntakeDuplicates
{
    private const NAME_MATCH_LIMIT = 20;

    public function handle(User $actor, ?string $phone, ?string $idNumber, ?string $name, ?int $exceptIntakeId = null): IntakeDuplicates
    {
        Gate::forUser($actor)->authorize('create', IntakeRequest::class);

        $phoneNormalized = Normalizer::phone($phone);
        $idNumberHash = Normalizer::idNumberHash($idNumber);
        $nameNormalized = Normalizer::name($name);

        $live = fn (): Builder => IntakeRequest::query()
            ->whereNull('merged_into_id')
            ->whereNull('anonymised_at')
            ->when($exceptIntakeId !== null, fn (Builder $q) => $q->where('id', '!=', $exceptIntakeId));

        $sameIdentity = collect();
        $hasHidden = false;

        if ($phoneNormalized !== null || $idNumberHash !== null) {
            $identity = fn (): Builder => $live()->where(function (Builder $q) use ($phoneNormalized, $idNumberHash): void {
                $q->when($phoneNormalized, fn ($w) => $w->orWhere('contact_phone_normalized', $phoneNormalized))
                    ->when($idNumberHash, fn ($w) => $w->orWhere('contact_id_number_hash', $idNumberHash));
            });

            $sameIdentity = $identity()->visibleTo($actor)->orderByDesc('received_at')->get();

            $hasHidden = $identity()
                ->openForConflictCheck()
                ->whereNotIn('id', $sameIdentity->modelKeys())
                ->exists();
        }

        $sameName = collect();

        if ($nameNormalized !== null && $actor->can(Permission::IntakeViewAny->value)) {
            $sameName = $live()
                ->where('contact_name_normalized', $nameNormalized)
                ->whereNotIn('id', $sameIdentity->modelKeys())
                ->visibleTo($actor)
                ->orderByDesc('received_at')
                ->limit(self::NAME_MATCH_LIMIT)
                ->get();
        }

        [$isExistingClient, $lookupUnavailable] = $this->clientHint($actor, [$phone, $idNumber]);

        return new IntakeDuplicates($sameIdentity, $hasHidden, $sameName, $isExistingClient, $lookupUnavailable);
    }

    /**
     * @param  list<string|null>  $identifiers
     * @return array{0: bool, 1: bool} [là khách, tra khách không khả dụng]
     */
    private function clientHint(User $actor, array $identifiers): array
    {
        foreach ($identifiers as $identifier) {

            try {
                if (app(FindClientByIdentifier::class)->handle($actor, $identifier) !== null) {
                    return [true, false];
                }
            } catch (ClientLookupThrottled) {
                return [false, true];
            } catch (DuplicateClientNotVisible) {
                // Khách thuộc vụ `restricted` mà người này không được biết: trả lời như "không",
                // đúng câu trung lập của M6.5 — nói "đã là khách" ở đây là lộ chính điều đó.
            }
        }

        return [false, false];
    }
}
