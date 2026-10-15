<?php

namespace Database\Seeders;

use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Làn tk, Task 1 — dữ liệu mẫu của luồng giao việc nội bộ (chỉ local/testing: `DatabaseSeeder` gọi
 * `DemoDataSeeder`, nơi gọi lớp này). Việc ở MỌI trạng thái giữa các tài khoản demo, để chủ văn phòng
 * đăng nhập bằng từng vai là thấy được luồng:
 *
 *  - luật sư phụ trách → trợ lý trong đội ngũ vụ: một việc vừa giao (chờ nhận), một việc CHỜ NHẬN QUÁ LÂU
 *    (giao 3 ngày trước), một việc đang làm sắp hạn, một việc đang làm QUÁ HẠN, một việc BỊ TRẢ LẠI kèm
 *    lý do, một việc hoàn thành kèm ghi chú, một việc đã huỷ;
 *  - quản lý → kế toán: hai việc chung (một chờ nhận, một đang làm);
 *  - quản lý → luật sư đã nghỉ việc (`TeamPerformanceSeeder`): một việc chung còn mở — dòng của bộ lọc
 *    "Cần giao lại";
 *  - admin → luật sư phụ trách của vụ `restricted`: một việc mà quản lý KHÔNG thấy;
 *  - làn tk, Task 2: một hồ sơ chờ nhận quá lâu, một hồ sơ bị trả lại ({@see self::seedSeats()}), và việc đã
 *    xong / lần trả lại của tháng trước cho cột "Việc hoàn thành đúng hạn" ({@see self::seedLastMonth()}).
 *
 * Ghi dòng thẳng (không qua Action): dữ liệu mẫu cần mốc thời gian trong quá khứ (giao 3 ngày trước, quá
 * hạn), và không gửi thư cho ai. Mọi dòng vẫn theo đúng luật của Action — người nhận việc của vụ là thành
 * viên giữ việc của đội ngũ và mở được vụ (`TaskSeederTest` ghim). Chạy lại không thêm gì.
 */
class TaskSeeder extends Seeder
{
    private const DEPARTED_EMAIL = TeamPerformanceSeeder::DEPARTED_EMAIL;

    public function run(): void
    {
        if (Task::query()->exists()) {
            return;
        }

        $admin = User::query()->where('email', DemoAccountsSeeder::ADMIN_EMAIL)->firstOrFail();
        $manager = User::query()->where('email', 'quanly@luatvukhang.com')->firstOrFail();
        $accountant = User::query()->where('email', 'ketoan@luatvukhang.com')->firstOrFail();

        $now = CarbonImmutable::now();
        $pairs = $this->leadAndAssistantPairs();

        $scenarios = [
            ['Soạn đơn khởi kiện và bản tự khai', TaskStatus::Pending, TaskPriority::High, 3, 0, null],
            ['Thu thập giấy chứng nhận quyền sử dụng đất bản sao y', TaskStatus::Pending, TaskPriority::Normal, 5, 3, null],
            ['Liên hệ toà để lấy lịch hoà giải', TaskStatus::Accepted, TaskPriority::Normal, 1, 2, null],
            ['Nộp tạm ứng án phí', TaskStatus::Accepted, TaskPriority::Urgent, -2, 6, null],
            ['Gặp nhân chứng tại Bình Dương', TaskStatus::Declined, TaskPriority::Normal, 4, 1, 'Tuần này em đang trực ba phiên toà, nhờ anh giao người khác giúp em.'],
            ['Sao y hợp đồng mua bán và biên bản bàn giao', TaskStatus::Completed, TaskPriority::Normal, null, 8, 'Đã sao y 3 bộ, để trong tab Tài liệu của vụ.'],
            ['Dịch công chứng thư uỷ quyền', TaskStatus::Cancelled, TaskPriority::Normal, null, 10, 'Khách đã tự làm ở văn phòng công chứng.'],
        ];

        foreach ($scenarios as $index => [$title, $status, $priority, $dueInDays, $daysAgo, $text]) {
            [$matter, $lead, $assistant] = $pairs[$index % count($pairs)];
            $assignedAt = $now->subDays($daysAgo)->subHours(2);

            $this->create([
                'matter_id' => $matter->getKey(),
                'title' => $title,
                'description' => 'Việc mẫu của vụ '.$matter->code.' — dữ liệu demo.',
                'assigned_by' => $lead->getKey(),
                'assignee_id' => $assistant->getKey(),
                'priority' => $priority,
                'due_date' => $dueInDays === null ? null : $now->addDays($dueInDays)->toDateString(),
            ], $status, $assignedAt, $text);
        }

        $this->create([
            'title' => 'Đối chiếu công nợ khách hàng quý 3',
            'assigned_by' => $manager->getKey(),
            'assignee_id' => $accountant->getKey(),
            'priority' => TaskPriority::High,
            'due_date' => $now->addDays(2)->toDateString(),
        ], TaskStatus::Pending, $now->subHours(5), null);

        $this->create([
            'title' => 'Xuất hoá đơn cho các khoản thu tháng này',
            'assigned_by' => $manager->getKey(),
            'assignee_id' => $accountant->getKey(),
            'priority' => TaskPriority::Normal,
            'due_date' => $now->addDays(6)->toDateString(),
        ], TaskStatus::Accepted, $now->subDays(1), null);

        $departed = User::query()->where('email', self::DEPARTED_EMAIL)->first();

        if ($departed !== null) {
            $this->create([
                'title' => 'Tổng hợp án lệ về tranh chấp đất đai',
                'assigned_by' => $manager->getKey(),
                'assignee_id' => $departed->getKey(),
                'priority' => TaskPriority::Normal,
                'due_date' => $now->addDays(10)->toDateString(),
            ], TaskStatus::Accepted, $now->subDays(20), null);
        }

        $restricted = Matter::query()->open()->ofConfidentiality(Confidentiality::Restricted)->orderBy('id')->first();

        if ($restricted !== null) {
            $this->create([
                'matter_id' => $restricted->getKey(),
                'title' => 'Rà soát hồ sơ mật trước buổi làm việc với khách',
                'assigned_by' => $admin->getKey(),
                'assignee_id' => $restricted->lead_lawyer_id,
                'priority' => TaskPriority::High,
                'due_date' => $now->addDays(1)->toDateString(),
            ], TaskStatus::Pending, $now->subHours(3), null);
        }

        $this->seedSeats($pairs, $now);
        $this->seedLastMonth($pairs, $now);
    }

    /**
     * Làn tk, Task 2 — "Nhận hồ sơ": một hồ sơ CHỜ NHẬN quá lâu (luật sư được thêm vào đội ngũ 3 ngày trước,
     * chưa bấm "Đã nhận hồ sơ" — dòng của khối "Hồ sơ chờ tôi nhận" và của lịch nhắc) và một hồ sơ BỊ TRẢ
     * LẠI kèm lý do (trợ lý — dòng "Hồ sơ trả lại" của "Theo dõi đội ngũ"). Người được thêm đủ điều kiện của
     * `AddTeamMember` (vai hợp lệ, đang làm việc, vụ thường nên vào đội ngũ là mở được vụ).
     *
     * @param  list<array{0: Matter, 1: User, 2: User}>  $pairs
     */
    private function seedSeats(array $pairs, CarbonImmutable $now): void
    {
        $seats = [
            [$pairs[1] ?? $pairs[0] ?? null, Role::Lawyer, MatterRole::Associate, $now->subDays(3)->subHours(2), null],
            [$pairs[2] ?? $pairs[0] ?? null, Role::Assistant, MatterRole::Assistant, $now->subDays(2), 'Em đang theo ba vụ cùng giai đoạn xét xử, xin anh phân công người khác giúp em.'],
        ];

        foreach ($seats as [$pair, $role, $seatRole, $assignedAt, $declineReason]) {
            if ($pair === null) {
                continue;
            }

            [$matter, $lead] = $pair;
            $onTeam = $matter->team()->pluck('users.id')->all();
            // Người tạo SAU CÙNG đủ điều kiện: các tài khoản demo đầu tiên (luatsu1, troly1…) có số vụ được
            // xem đã ghim ở test phân quyền dữ liệu mẫu — một ghế mới không được làm đổi các số đó.
            $member = User::query()->role($role->value)->where('is_active', true)->whereNotIn('id', $onTeam)->orderByDesc('id')->first();

            if ($member === null) {
                continue;
            }

            $matter->team()->attach($member->getKey(), [
                'role_in_matter' => $seatRole->value,
                'assigned_by' => $lead->getKey(),
                'assigned_at' => $assignedAt,
                'accepted_at' => null,
                'declined_at' => $declineReason === null ? null : $assignedAt->addHours(4),
                'decline_reason' => $declineReason,
                'created_at' => $assignedAt,
                'updated_at' => $assignedAt,
            ]);
        }
    }

    /**
     * Làn tk, Task 2 — số liệu của THÁNG TRƯỚC (kỳ mặc định của "Hiệu suất theo kỳ") để cột "Việc hoàn thành
     * đúng hạn" có giá trị: năm việc có hạn trợ lý của vụ đầu tiên đã hoàn thành (bốn đúng hạn, một trễ một
     * ngày) — đủ ngưỡng 5 của tỉ lệ — và hai lần trợ lý đó trả lại việc (dòng nhật ký `task_declined` trong
     * kỳ; hai việc đó sau đã huỷ).
     *
     * @param  list<array{0: Matter, 1: User, 2: User}>  $pairs
     */
    private function seedLastMonth(array $pairs, CarbonImmutable $now): void
    {
        if ($pairs === []) {
            return;
        }

        [$matter, $lead, $assistant] = $pairs[0];
        $month = $now->subMonthNoOverflow()->startOfMonth();

        foreach ([3, 8, 13, 18, 22] as $index => $day) {
            $due = $month->addDays($day);
            $done = $index === 4 ? $due->addDay()->setTime(9, 0) : $due->setTime(15, 0);
            $assignedAt = $due->subDays(4)->setTime(9, 0);

            $task = new Task;
            $task->forceFill([
                'matter_id' => $matter->getKey(),
                'title' => 'Việc tháng trước '.($index + 1).': chuẩn bị tài liệu cho buổi làm việc',
                'assigned_by' => $lead->getKey(),
                'assignee_id' => $assistant->getKey(),
                'priority' => TaskPriority::Normal,
                'due_date' => $due->toDateString(),
                'status' => TaskStatus::Completed,
                'assigned_at' => $assignedAt,
                'accepted_at' => $assignedAt->addHour(),
                'completed_at' => $done,
                'created_at' => $assignedAt,
                'updated_at' => $done,
            ])->save();
        }

        foreach ([6, 20] as $day) {
            $at = $month->addDays($day)->setTime(10, 0);

            $task = new Task;
            $task->forceFill([
                'matter_id' => $matter->getKey(),
                'title' => 'Việc tháng trước bị trả lại rồi huỷ',
                'assigned_by' => $lead->getKey(),
                'assignee_id' => $assistant->getKey(),
                'priority' => TaskPriority::Normal,
                'status' => TaskStatus::Cancelled,
                'assigned_at' => $at->subHours(3),
                'cancelled_at' => $at->addDay(),
                'cancel_reason' => 'Đã giao việc khác thay.',
                'created_at' => $at->subHours(3),
                'updated_at' => $at->addDay(),
            ])->save();

            Audit::record('task_declined', $task, [
                'matter_id' => $matter->getKey(),
                'from' => TaskStatus::Pending->value,
                'to' => TaskStatus::Declined->value,
                'reason' => 'Đang quá tải.',
            ], causer: $assistant)?->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }
    }

    /**
     * Các vụ thường đang mở có luật sư phụ trách và một trợ lý trong đội ngũ, đều đang làm việc.
     *
     * @return list<array{0: Matter, 1: User, 2: User}>
     */
    private function leadAndAssistantPairs(): array
    {
        return Matter::query()
            ->open()
            ->ofConfidentiality(Confidentiality::Normal)
            ->with(['leadLawyer', 'team'])
            ->orderBy('id')
            ->get()
            ->map(function (Matter $matter): ?array {
                $assistant = $matter->team->first(fn (User $user): bool => $user->is_active
                    && $user->pivot->role_in_matter === MatterRole::Assistant);

                return $matter->leadLawyer?->is_active && $assistant !== null
                    ? [$matter, $matter->leadLawyer, $assistant]
                    : null;
            })
            ->filter()
            ->take(4)
            ->values()
            ->all();
    }

    private function create(array $attributes, TaskStatus $status, CarbonImmutable $assignedAt, ?string $text): void
    {
        $task = new Task;
        $task->forceFill([
            ...$attributes,
            'status' => $status,
            'assigned_at' => $assignedAt,
            'accepted_at' => in_array($status, [TaskStatus::Accepted, TaskStatus::Completed], true) ? $assignedAt->addHours(1) : null,
            'declined_at' => $status === TaskStatus::Declined ? $assignedAt->addHours(3) : null,
            'decline_reason' => $status === TaskStatus::Declined ? $text : null,
            'completed_at' => $status === TaskStatus::Completed ? $assignedAt->addDays(2) : null,
            'completion_note' => $status === TaskStatus::Completed ? $text : null,
            'cancelled_at' => $status === TaskStatus::Cancelled ? $assignedAt->addDays(1) : null,
            'cancel_reason' => $status === TaskStatus::Cancelled ? $text : null,
            'created_at' => $assignedAt,
            'updated_at' => $assignedAt,
        ])->save();
    }
}
