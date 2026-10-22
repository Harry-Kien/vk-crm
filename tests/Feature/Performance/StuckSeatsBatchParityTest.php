<?php

use App\Actions\Matter\SeatsNeedingReassignment;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\Confidentiality;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Matter;
use App\Models\MatterUser;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Rà soát toàn hệ thống 2026-10-22, làn perf2 — "Theo dõi đội ngũ" trên dữ liệu đo M13 (3.000 vụ, mọi ghế chờ
 * nhận) vẫn 6–9 giây sau làn ops: mỗi ghế chờ nhận hỏi `Gate::view()` của người ngồi ghế, khoảng bốn lần hỏi
 * quyền Spatie mỗi ghế. Nay mỗi người ngồi ghế MỘT truy vấn qua
 * {@see ResolveStaffRecipients::surelyQualifyingMatters()} (đúng câu EXISTS của `MatterPolicy::view()`, chỉ dùng
 * theo chiều "chắc chắn nhận được") và chỉ hỏi Gate cho các ghế còn lại. Tệp này ghim rằng đáp án KHÔNG đổi:
 * từng cặp (người, vụ) trên mọi loại người (đã nghỉ, đã xoá — kể cả xoá mà `is_active` còn bật, mất quyền, chỉ
 * có `matter.viewAny`, quyền trực tiếp không qua vai trò) và mọi loại vụ (thường, hạn chế, đã xoá mềm, đã kết
 * thúc), và cả danh sách "Hồ sơ cần phân công lại" so với định nghĩa từng ghế cũ.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $make = fn (Role $role, array $attributes = []): User => User::factory()->withRole($role)->create($attributes);

    $this->people = [
        'admin' => $make(Role::Admin),
        'manager' => $make(Role::Manager),
        'lead' => $make(Role::Lawyer),
        'associate' => $make(Role::Lawyer),
        'assistant' => $make(Role::Assistant),
        'accountant' => $make(Role::Accountant), // matter.viewAny KHÔNG kèm matter.view
        'sales' => $make(Role::Sales),
        'inactive' => $make(Role::Lawyer, ['is_active' => false]),
        'trashed' => $make(Role::Assistant),
        'trashedActive' => $make(Role::Manager), // xoá mềm mà cột is_active còn bật (hai cột khác nhau)
        'stripped' => $make(Role::Lawyer), // mất mọi vai trò sau khi được giao
        'moved' => $make(Role::Lawyer), // đổi chức danh sang kế toán, vẫn là luật sư phụ trách vụ cũ
        'direct' => User::factory()->create(), // matter.view trực tiếp, không qua vai trò
    ];
    $this->people['stripped']->syncRoles([]);
    $this->people['moved']->syncRoles([Role::Accountant->value]);
    $this->people['direct']->givePermissionTo(Permission::MatterView->value);

    $lead = $this->people['lead'];
    $this->matters = [
        'normal' => Matter::factory()->create(['lead_lawyer_id' => $lead->id, 'code' => 'VK-2026-PF-0001']),
        'restricted' => Matter::factory()->create(['lead_lawyer_id' => $lead->id, 'code' => 'VK-2026-PF-0002', 'confidentiality' => Confidentiality::Restricted]),
        'restrictedMoved' => Matter::factory()->create(['lead_lawyer_id' => $this->people['moved']->id, 'code' => 'VK-2026-PF-0003', 'confidentiality' => Confidentiality::Restricted]),
        'closed' => Matter::factory()->create(['lead_lawyer_id' => $lead->id, 'code' => 'VK-2026-PF-0004', 'closed_at' => now()->subDay()]),
        'trashedMatter' => Matter::factory()->create(['lead_lawyer_id' => $lead->id, 'code' => 'VK-2026-PF-0005']),
        'leadOnly' => Matter::factory()->create(['lead_lawyer_id' => $lead->id, 'code' => 'VK-2026-PF-0006']),
    ];

    // Ghế chờ nhận (accepted_at, declined_at rỗng) cho mọi người trên mọi vụ, trừ "leadOnly" chỉ có luật sư phụ trách.
    $stamp = now()->toDateTimeString();
    $seats = [];
    foreach ($this->matters as $key => $matter) {
        foreach ($key === 'leadOnly' ? ['lead'] : array_keys($this->people) as $person) {
            $seats[] = [
                'matter_id' => $matter->id, 'user_id' => $this->people[$person]->id,
                'role_in_matter' => $person === 'lead' ? 'lead' : 'associate', 'created_at' => $stamp, 'updated_at' => $stamp,
            ];
        }
    }
    DB::table('matter_user')->insertOrIgnore($seats); // luật sư phụ trách đã có ghế từ factory

    $this->people['trashed']->forceFill(['is_active' => false])->save();
    $this->people['trashed']->delete();
    $this->people['trashedActive']->delete();
    $this->matters['trashedMatter']->delete();
});

/**
 * Mọi cặp (người, vụ) của tập thử: `qualifies()` từng cặp (đường Gate, mỗi bản ghi đọc mới) và câu trả lời
 * của lối tắt — vụ có nằm trong `surelyQualifyingMatters()` của người đó không.
 *
 * @return array{qualifies: array<string, bool>, surely: array<string, bool>}
 */
function perf2Answers(): array
{
    $recipients = app(ResolveStaffRecipients::class);
    $answers = ['qualifies' => [], 'surely' => []];

    foreach (User::withTrashed()->orderBy('id')->get() as $user) {
        $surely = $recipients->surelyQualifyingMatters($user)?->pluck('matters.id')->map(fn ($id): int => (int) $id)->all() ?? [];

        foreach (Matter::withTrashed()->orderBy('id')->get() as $matter) {
            $key = "{$user->id}:{$matter->id}";
            $answers['qualifies'][$key] = $recipients->qualifies(User::withTrashed()->find($user->id), Matter::withTrashed()->find($matter->id));
            $answers['surely'][$key] = in_array((int) $matter->id, $surely, true);
        }
    }

    return $answers;
}

it('only calls a matter surely qualifying when qualifies() says so, and covers every matter qualifies() allows', function () {
    ['qualifies' => $qualifies, 'surely' => $surely] = perf2Answers();
    $people = $this->people;
    $matters = $this->matters;
    $pair = fn (string $person, string $matter): string => "{$people[$person]->id}:{$matters[$matter]->id}";

    // Chiều an toàn: lối tắt không bao giờ nói "nhận được" khi Gate nói không.
    foreach ($surely as $key => $answer) {
        expect(! $answer || $qualifies[$key])->toBeTrue("lối tắt nói nhận được, qualifies() nói không: {$key}");
    }

    // Không có `before` nào của Gate nới thêm ở đây, nên hai tập bằng nhau — lối tắt không bỏ sót ai (khỏi hỏi Gate).
    expect($surely)->toBe($qualifies)
        // Ca phải có mặt để phép so có nghĩa, mỗi lý do từ chối một ca.
        ->and($qualifies[$pair('accountant', 'normal')])->toBeFalse()      // matter.viewAny, không matter.view
        ->and($qualifies[$pair('moved', 'restrictedMoved')])->toBeFalse()  // luật sư phụ trách đã đổi sang kế toán
        ->and($qualifies[$pair('stripped', 'normal')])->toBeFalse()        // mất vai trò
        ->and($qualifies[$pair('inactive', 'normal')])->toBeFalse()        // đã nghỉ
        ->and($qualifies[$pair('trashed', 'normal')])->toBeFalse()         // đã xoá
        ->and($qualifies[$pair('trashedActive', 'normal')])->toBeFalse()   // đã xoá, is_active còn bật
        ->and($qualifies[$pair('associate', 'restricted')])->toBeFalse()   // vụ hạn chế, không phụ trách
        ->and($qualifies[$pair('direct', 'leadOnly')])->toBeFalse()        // không trong đội ngũ
        ->and($qualifies[$pair('sales', 'normal')])->toBeFalse()
        ->and($qualifies[$pair('direct', 'normal')])->toBeTrue()           // matter.view trực tiếp, trong đội ngũ
        ->and($qualifies[$pair('lead', 'restricted')])->toBeTrue()
        ->and($qualifies[$pair('admin', 'restricted')])->toBeTrue()
        ->and($qualifies[$pair('manager', 'trashedMatter')])->toBeTrue()   // vụ đã xoá mềm: policy vẫn cho xem
        ->and($qualifies[$pair('assistant', 'closed')])->toBeTrue();
});

it('lists the same seats needing reassignment as the one-seat-at-a-time definition', function () {
    // Định nghĩa cũ (làn ops): mọi ghế chờ nhận trên vụ còn mở người xem liệt kê được, lọc từng ghế bằng stuck().
    $perSeat = fn (User $viewer): array => MatterUser::query()
        ->onOpenMattersListableBy($viewer)
        ->awaitingAcceptance()
        ->with(['matter.team', 'user' => fn ($user) => $user->withTrashed()])
        ->get()
        ->filter(fn (MatterUser $seat): bool => SeatsNeedingReassignment::stuck($seat, $seat->user, $seat->matter))
        ->sortBy(fn (MatterUser $seat): string => (string) $seat->matter?->code)
        ->map(fn (MatterUser $seat): string => "{$seat->matter->code}/{$seat->user_id}")
        ->values()->all();

    foreach (['admin', 'manager'] as $who) {
        $viewer = $this->people[$who]->fresh();
        $seats = app(SeatsNeedingReassignment::class)->handle($viewer);

        expect($seats->map(fn (MatterUser $seat): string => "{$seat->matter->code}/{$seat->user_id}")->all())
            ->toBe($perSeat($viewer))
            ->not->toBeEmpty();
    }

    // Người xem không phân công lại được: vẫn null.
    expect(app(SeatsNeedingReassignment::class)->handle($this->people['lead']->fresh()))->toBeNull();
});
