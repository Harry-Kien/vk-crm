<?php

namespace App\Actions\Intake;

use App\Actions\Intake\Concerns\HoldsConflictCheckLock;
use App\Actions\Intake\Concerns\ValidatesIntakeIdentity;
use App\Enums\IntakeStatus;
use App\Models\IntakeParty;
use App\Models\IntakeRequest;
use App\Models\User;
use App\Support\Audit;
use App\Support\Intake\IntakeDuplicates;
use App\Support\Intake\RecordIntakeResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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
 * Luật kiểm tra dữ liệu (độ dài cột, vai, người được giao, phí) dùng chung với lần sửa
 * (`UpdateIntakeIdentity`): {@see ValidatesIntakeIdentity}.
 *
 * Phí đã báo (`quoted_amount`): số nguyên đồng, hoặc chuỗi qua `Money::parse()` (M9). Không thư nào
 * được gửi ở đây.
 */
class RecordIntake
{
    use HoldsConflictCheckLock;
    use ValidatesIntakeIdentity;

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

        $data = $this->validatedIdentity($attributes, $opposingParties);

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
                'received_at' => $data['received_at'] !== null ? CarbonImmutable::parse($data['received_at']) : now(),
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
}
