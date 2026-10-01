<?php

namespace App\Actions\Intake;

use App\Actions\Intake\Concerns\HoldsConflictCheckLock;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\PartyRole;
use App\Enums\Permission;
use App\Models\IntakeParty;
use App\Models\IntakeRequest;
use App\Models\MatterType;
use App\Models\User;
use App\Support\Audit;
use App\Support\Billing\Money;
use App\Support\Intake\IntakeDuplicates;
use App\Support\Intake\RecordIntakeResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ghi nhận MỘT lần có người liên hệ văn phòng và chạy kiểm tra xung đột lợi ích NGAY Ở LẦN CHẠM ĐẦU
 * (M10 R1, R4, R6, R7a) — trước lúc nghe câu chuyện, không phải lúc mở hồ sơ: nếu văn phòng nghe hết
 * chuyện rồi mới biết bên kia là khách hiện hữu thì thông tin bí mật đã nghe, không rút lại được.
 *
 * **Chỉ ghi PHẦN DANH TÍNH.** Người liên hệ, vai dự kiến, các bên đối lập. Action này KHÔNG BAO GIỜ
 * ghi `summary` (câu chuyện): khoá `summary` nếu có trong `$attributes` bị bỏ qua. Ô câu chuyện chỉ
 * mở qua {@see UpdateIntakeSummary}, sau các cổng của {@see IntakeSummaryGate}.
 *
 * **Số CCCD gốc không được lưu** (R7): `contact_id_number` và `id_number` của bên đối lập chỉ dùng để
 * tính dấu băm (`identify()`); dòng lưu chỉ có dấu băm và số điện thoại chuẩn hoá. SĐT dạng gõ của
 * người liên hệ (`contact_phone`) thì lưu để gọi lại. Bên đối lập không có SĐT gốc: bên thứ ba không
 * thể đồng ý, nên chỉ giữ thứ kiểm tra xung đột cần.
 *
 * **Người liên hệ CHƯA là khách hàng** (R2): không có hàng `clients` nào. Kiểm tra xung đột dựng các
 * `MatterParty` chưa lưu từ dữ liệu đã lưu ({@see CheckIntakeConflict}); dò cả các lần tiếp nhận cũ
 * qua nguồn thứ hai của `RunConflictCheck`.
 *
 * **`?User $actor` (R6).** `null` dành cho một lối vào không có nhân sự (form website, sau này): khi
 * đó không kiểm quyền và không dò trùng gì cả (gợi ý trùng và tra khách đều phụ thuộc người hỏi được
 * thấy gì, nên cần một nhân sự; `duplicates` rỗng với `clientLookupUnavailable = true`). Có actor
 * thì phải có `intake.create` (`IntakeRequestPolicy::create`, `AuthorizationException` nếu không).
 * `created_by` và causer của mọi dòng nhật ký là ACTOR, không phải người đang đăng nhập: bản ghi tự
 * động của model bị tắt ở lần tạo và dòng `intake_recorded` thay thế nó, mang `actor_explicit` như
 * `conflict_check_run` để người đọc kiểm toán biết danh tính là khẳng định hay chỉ suy từ phiên.
 *
 * **Khoá và transaction.** Toàn bộ chạy dưới `Cache::lock('conflict-check')` (cùng khoá `OpenMatter`,
 * xem {@see HoldsConflictCheckLock}); trong khoá là MỘT transaction bao cả việc sinh mã, lưu, kiểm tra
 * và ghi kết quả — hai người nhận hai cuộc gọi đối nhau cùng lúc được tuần tự hoá, lần thứ hai thấy
 * lần thứ nhất. Dò trùng chạy SAU, ngoài khoá.
 *
 * **Kiểm tra xong KHÔNG có nghĩa là ô câu chuyện mở**: xem {@see IntakeSummaryGate}. Đỏ vẫn lưu được
 * bản ghi danh tính — nếu không, lần liên hệ đó sẽ biến mất khỏi mắt kiểm tra xung đột về sau.
 *
 * Phí đã báo (`quoted_amount`): số nguyên đồng, hoặc chuỗi qua `Money::parse()` (M9). Không thư nào
 * được gửi ở đây.
 */
class RecordIntake
{
    use HoldsConflictCheckLock;

    private const MAX_OPPOSING_PARTIES = 10;

    /**
     * @param  array<string, mixed>  $attributes  `contact_name` (bắt buộc), `source` (bắt buộc),
     *                                            `contact_phone`, `contact_email`, `contact_id_number`, `contact_role`, `referred_by`,
     *                                            `matter_type_id`, `quoted_amount`, `assigned_to`, `received_at`. Khoá khác, kể cả
     *                                            `summary`, bị bỏ qua.
     * @param  array<int, array<string, mixed>>  $opposingParties  Mỗi bên: `name`, `role` (bắt
     *                                                             buộc), `phone`, `id_number` (không bắt buộc — người gọi thường không biết; thiếu cả hai
     *                                                             thì lần kiểm tra chỉ ra "thiếu định danh", tức ô câu chuyện đi qua cổng xác nhận).
     */
    public function handle(?User $actor, array $attributes, array $opposingParties = []): RecordIntakeResult
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('create', IntakeRequest::class);
        }

        $data = $this->validated($attributes, $opposingParties);

        [$intake, $conflict] = $this->underConflictCheckLock(fn (): array => DB::transaction(function () use ($actor, $data): array {
            $intake = new IntakeRequest([
                'contact_name' => $data['contact_name'],
                'contact_phone' => $data['contact_phone'],
                'contact_email' => $data['contact_email'],
                'contact_role' => $data['contact_role'],
                'source' => $data['source'],
                'referred_by' => $data['referred_by'],
                'matter_type_id' => $data['matter_type_id'],
                'quoted_amount' => $data['quoted_amount'],
                'assigned_to' => $data['assigned_to'],
                'status' => IntakeStatus::New,
                'received_at' => $data['received_at'],
            ]);
            $intake->identify($data['contact_id_number'], $data['contact_phone']);

            // Actor tường minh: `created_by` không suy từ phiên. Bản ghi tự động của model bị tắt —
            // nó gán causer theo phiên; dòng `intake_recorded` bên dưới thay nó với đúng actor.
            if ($actor !== null) {
                $intake->blameOn($actor);
            }
            $intake->disableLogging()->save();

            foreach ($data['parties'] as $partyData) {
                $party = new IntakeParty(['role' => $partyData['role'], 'name' => $partyData['name']]);
                $party->identify($partyData['id_number'], $partyData['phone']);
                $intake->parties()->save($party);
            }

            $conflict = app(CheckIntakeConflict::class)->handle($actor, $intake);

            Audit::record('intake_recorded', $intake, [
                'source' => $intake->source->value,
                'status' => $intake->status->value,
                'opposing_parties' => count($data['parties']),
                'conflict_level' => $conflict->level->value,
                'actor_explicit' => $actor !== null,
            ], $actor);

            return [$intake, $conflict];
        }));

        $duplicates = $actor === null
            ? new IntakeDuplicates(collect(), false, collect(), false, true)
            : app(FindIntakeDuplicates::class)->handle(
                $actor, $data['contact_phone'], $data['contact_id_number'], $data['contact_name'], $intake->getKey(),
            );

        return new RecordIntakeResult($intake->fresh(), $conflict, $duplicates);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $opposingParties
     * @return array<string, mixed>
     */
    private function validated(array $attributes, array $opposingParties): array
    {
        $input = [
            ...array_intersect_key($attributes, array_flip([
                'contact_name', 'contact_phone', 'contact_email', 'contact_id_number', 'contact_role',
                'source', 'referred_by', 'matter_type_id', 'assigned_to', 'received_at',
            ])),
            'parties' => array_values(array_map(
                fn (array $party): array => array_intersect_key($party, array_flip(['name', 'role', 'phone', 'id_number'])),
                $opposingParties,
            )),
        ];

        // Độ dài = độ dài cột (MariaDB strict: vượt là 500, SQLite không thấy). Số CCCD gốc không có
        // cột — trần 30 chỉ để không tính băm một chuỗi rác dài.
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
            'parties' => ['array', 'max:'.self::MAX_OPPOSING_PARTIES],
            'parties.*.name' => ['required', 'string', 'max:200'],
            'parties.*.role' => ['required', Rule::enum(PartyRole::class)],
            'parties.*.phone' => ['nullable', 'string', 'max:20'],
            'parties.*.id_number' => ['nullable', 'string', 'max:30'],
        ], [], __('intake.attributes'));

        $validator->validate();

        $role = filled($input['contact_role'] ?? null) ? $this->role($input['contact_role']) : null;

        // M6.5 R13f: khách hàng của văn phòng không bao giờ là "luật sư đối phương" của chính vụ mình —
        // chọn vai đó âm thầm tắt Đỏ. Cổng thật ở Action, không chỉ ở ô chọn.
        if ($role === PartyRole::OpposingCounsel) {
            throw ValidationException::withMessages(['contact_role' => [__('intake.errors.contact_role_opposing_counsel')]]);
        }

        $assignedTo = filled($input['assigned_to'] ?? null) ? (int) $input['assigned_to'] : null;

        if ($assignedTo !== null && ! $this->mayHold(User::query()->findOrFail($assignedTo))) {
            throw ValidationException::withMessages(['assigned_to' => [__('intake.errors.assignee_cannot_see')]]);
        }

        return [
            'contact_name' => trim((string) $input['contact_name']),
            'contact_phone' => $this->nullable($input['contact_phone'] ?? null),
            'contact_email' => $this->nullable($input['contact_email'] ?? null),
            'contact_id_number' => $this->nullable($input['contact_id_number'] ?? null),
            'contact_role' => $role,
            'source' => $input['source'] instanceof IntakeSource ? $input['source'] : IntakeSource::from($input['source']),
            'referred_by' => $this->nullable($input['referred_by'] ?? null),
            'matter_type_id' => filled($input['matter_type_id'] ?? null) ? (int) $input['matter_type_id'] : null,
            'quoted_amount' => $this->quotedAmount($attributes['quoted_amount'] ?? null),
            'assigned_to' => $assignedTo,
            'received_at' => filled($input['received_at'] ?? null) ? CarbonImmutable::parse($input['received_at']) : now(),
            'parties' => array_map(fn (array $party): array => [
                'name' => trim((string) $party['name']),
                'role' => $this->role($party['role']),
                'phone' => $this->nullable($party['phone'] ?? null),
                'id_number' => $this->nullable($party['id_number'] ?? null),
            ], $input['parties']),
        ];
    }

    private function role(PartyRole|string $value): PartyRole
    {
        return $value instanceof PartyRole ? $value : PartyRole::from($value);
    }

    /**
     * Người được giao phải thấy được bản ghi: có `intake.create` (viewAny kéo theo). "Đang hoạt động"
     * đã do luật `exists` của `assigned_to` kiểm (`is_active = true`), nên không kiểm lại ở đây.
     */
    private function mayHold(User $user): bool
    {
        return $user->can(Permission::IntakeCreate->value);
    }

    private function nullable(mixed $value): ?string
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
