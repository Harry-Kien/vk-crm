<?php

namespace Database\Seeders;

use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
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
 *  - admin → luật sư phụ trách của vụ `restricted`: một việc mà quản lý KHÔNG thấy.
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
