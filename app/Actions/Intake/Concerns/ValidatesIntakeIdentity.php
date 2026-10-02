<?php

namespace App\Actions\Intake\Concerns;

use App\Enums\IntakeSource;
use App\Enums\PartyRole;
use App\Enums\Permission;
use App\Models\IntakeRequest;
use App\Models\MatterType;
use App\Models\User;
use App\Support\Billing\Money;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * MỘT bộ luật kiểm tra PHẦN DANH TÍNH của một lần tiếp nhận, dùng chung cho lần ghi đầu
 * (`RecordIntake`) và lần sửa (`UpdateIntakeIdentity`, M10 Task 3) — hai bộ luật là hai định nghĩa
 * "danh tính hợp lệ" sẽ lệch nhau, và màn hình sửa sẽ lưu được thứ màn hình tạo từ chối.
 *
 * Độ dài = độ dài cột (MariaDB strict: vượt là lỗi 1406 thành trang 500, SQLite không thấy) — với SĐT
 * là cả dạng gõ (`max:20`) lẫn dạng CHUẨN HOÁ ({@see IntakeRequest::normalizedPhoneFits()}). Số CCCD
 * gốc không có cột — trần 30 chỉ để không tính băm một chuỗi rác dài. `contact_role =
 * opposing_counsel` bị từ chối (M6.5 R13f). Người được giao phải đang hoạt động và có
 * `intake.create`. Phí đã báo: số nguyên đồng trong `[0, Money::MAX]`, hoặc chuỗi qua `Money::parse()`.
 * Tối đa {@see IntakeRequest::MAX_OPPOSING_PARTIES} bên đối lập (cùng trần với form và với gộp).
 *
 * Khác nhau giữa hai lần, có chủ đích:
 *  - `received_at` chỉ có ở lần ghi đầu (`$forUpdate = false`): sửa nó sau đó là sửa con số đo thời
 *    gian phản hồi (R5).
 *  - Ở lần sửa, mỗi bên đối lập mang thêm `id` (dòng `intake_parties` đã có) — Action sửa tự kiểm
 *    dòng đó có thuộc đúng bản ghi không.
 */
trait ValidatesIntakeIdentity
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $opposingParties
     * @return array<string, mixed>
     */
    protected function validatedIdentity(array $attributes, array $opposingParties, bool $forUpdate = false): array
    {
        $attributeKeys = [
            'contact_name', 'contact_phone', 'contact_email', 'contact_id_number', 'contact_role',
            'source', 'referred_by', 'matter_type_id', 'assigned_to',
            ...($forUpdate ? [] : ['received_at']),
        ];
        $partyKeys = ['name', 'role', 'phone', 'id_number', ...($forUpdate ? ['id'] : [])];

        $input = [
            ...array_intersect_key($attributes, array_flip($attributeKeys)),
            'parties' => array_values(array_map(
                fn (array $party): array => array_intersect_key($party, array_flip($partyKeys)),
                $opposingParties,
            )),
        ];

        $validator = Validator::make($input, [
            'contact_name' => ['required', 'string', 'max:200'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
            'contact_email' => ['nullable', 'string', 'email', 'max:150'],
            'contact_id_number' => ['nullable', 'string', 'max:30'],
            'contact_role' => ['nullable', Rule::enum(PartyRole::class)],
            'source' => ['required', Rule::enum(IntakeSource::class)],
            'referred_by' => ['nullable', 'string', 'max:200'],
            'matter_type_id' => ['nullable', 'integer', Rule::exists(MatterType::class, 'id')->whereNull('deleted_at')],
            'assigned_to' => ['nullable', 'integer', Rule::exists(User::class, 'id')->where('is_active', true)],
            'received_at' => ['nullable', 'date'],
            'parties' => ['array', 'max:'.IntakeRequest::MAX_OPPOSING_PARTIES],
            'parties.*.id' => ['nullable', 'integer'],
            'parties.*.name' => ['required', 'string', 'max:200'],
            'parties.*.role' => ['required', Rule::enum(PartyRole::class)],
            'parties.*.phone' => ['nullable', 'string', 'max:20'],
            'parties.*.id_number' => ['nullable', 'string', 'max:30'],
        ], [], __('intake.attributes'));

        $validator->validate();

        // `max:20` giữ cột của dạng GÕ; dạng chuẩn hoá có thể dài hơn một ký tự (số 0 đầu thành `84`) và
        // có cột riêng 20 ký tự — xem IntakeRequest::normalizedPhoneFits() (rà soát Task 2, m2).
        $phonesTooLong = [];

        if (! IntakeRequest::normalizedPhoneFits($input['contact_phone'] ?? null)) {
            $phonesTooLong['contact_phone'] = [__('intake.errors.phone_too_long')];
        }

        foreach ($input['parties'] as $index => $party) {
            if (! IntakeRequest::normalizedPhoneFits($party['phone'] ?? null)) {
                $phonesTooLong["parties.{$index}.phone"] = [__('intake.errors.phone_too_long')];
            }
        }

        if ($phonesTooLong !== []) {
            throw ValidationException::withMessages($phonesTooLong);
        }

        $role = filled($input['contact_role'] ?? null) ? $this->intakeRole($input['contact_role']) : null;

        // M6.5 R13f: khách hàng của văn phòng không bao giờ là "luật sư đối phương" của chính vụ mình —
        // chọn vai đó âm thầm tắt Đỏ. Cổng thật ở Action, không chỉ ở ô chọn.
        if ($role === PartyRole::OpposingCounsel) {
            throw ValidationException::withMessages(['contact_role' => [__('intake.errors.contact_role_opposing_counsel')]]);
        }

        $assignedTo = filled($input['assigned_to'] ?? null) ? (int) $input['assigned_to'] : null;

        if ($assignedTo !== null && ! User::query()->findOrFail($assignedTo)->can(Permission::IntakeCreate->value)) {
            throw ValidationException::withMessages(['assigned_to' => [__('intake.errors.assignee_cannot_see')]]);
        }

        return [
            'contact_name' => trim((string) $input['contact_name']),
            'contact_phone' => $this->nullableText($input['contact_phone'] ?? null),
            'contact_email' => $this->nullableText($input['contact_email'] ?? null),
            'contact_id_number' => $this->nullableText($input['contact_id_number'] ?? null),
            'contact_role' => $role,
            'source' => $input['source'] instanceof IntakeSource ? $input['source'] : IntakeSource::from($input['source']),
            'referred_by' => $this->nullableText($input['referred_by'] ?? null),
            'matter_type_id' => filled($input['matter_type_id'] ?? null) ? (int) $input['matter_type_id'] : null,
            'quoted_amount' => $this->quotedAmount($attributes['quoted_amount'] ?? null),
            'assigned_to' => $assignedTo,
            'received_at' => filled($input['received_at'] ?? null) ? $input['received_at'] : null,
            'parties' => array_map(fn (array $party): array => [
                'id' => filled($party['id'] ?? null) ? (int) $party['id'] : null,
                'name' => trim((string) $party['name']),
                'role' => $this->intakeRole($party['role']),
                'phone' => $this->nullableText($party['phone'] ?? null),
                'id_number' => $this->nullableText($party['id_number'] ?? null),
            ], $input['parties']),
        ];
    }

    private function intakeRole(PartyRole|string $value): PartyRole
    {
        return $value instanceof PartyRole ? $value : PartyRole::from($value);
    }

    private function nullableText(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }

    private function quotedAmount(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            if ($value < 0 || $value > Money::MAX) {
                throw ValidationException::withMessages(['quoted_amount' => [__('intake.errors.quoted_amount_invalid')]]);
            }

            return $value;
        }

        if (! is_string($value)) {
            throw ValidationException::withMessages(['quoted_amount' => [__('intake.errors.quoted_amount_invalid')]]);
        }

        return Money::parse($value, 'quoted_amount');
    }
}
