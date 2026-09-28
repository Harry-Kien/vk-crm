<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\BulkReassign;
use App\Jobs\SendReassignmentDigest;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

it('lets an admin open the bulk reassign screen', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();

    $this->actingAs($admin, 'web')
        ->get(BulkReassign::getUrl(panel: 'admin'))
        ->assertOk();
});

it('lets a manager open the bulk reassign screen', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();

    $this->actingAs($manager, 'web')
        ->get(BulkReassign::getUrl(panel: 'admin'))
        ->assertOk();
});

/**
 * Cùng thành ngữ `MatterProgressTest`'s "refuses again when a fresh component is rebuilt from the
 * id alone" — dựng thẳng component (bỏ qua middleware 404 của panel, thứ KHÔNG phủ được request
 * cập nhật Livewire) và tự gọi `mount()`/hành động thật, để đo đúng cổng BÊN TRONG chính component
 * chứ không phải cổng của tầng routing.
 */
it('404s for a lawyer both on mount and on the real reassign action, even bypassing the page route', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($lawyer, 'web');

    $this->get(BulkReassign::getUrl(panel: 'admin'))->assertNotFound();

    $mount = function (): void {
        (new BulkReassign)->mount();
    };

    expect($mount)->toThrow(NotFoundHttpException::class);

    // Hành động thật tự hỏi lại Gate độc lập với mount() — mô phỏng một request cập nhật Livewire
    // giả mạo gọi thẳng phương thức này mà không đi qua mount() trước.
    $callAction = function (): void {
        (new BulkReassign)->reassignSelected();
    };

    expect($callAction)->toThrow(NotFoundHttpException::class);
});

it('404s for an assistant and an accountant', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->actingAs($assistant, 'web');
    $this->get(BulkReassign::getUrl(panel: 'admin'))->assertNotFound();

    $this->actingAs($accountant, 'web');
    $this->get(BulkReassign::getUrl(panel: 'admin'))->assertNotFound();
});

it('bulk-reassigns several open matters of one lawyer to a new lead, each with its own stage log', function () {
    Queue::fake();

    $admin = User::factory()->withRole(Role::Admin)->create();
    $oldLead = User::factory()->withRole(Role::Lawyer)->create();
    $newLead = User::factory()->withRole(Role::Lawyer)->create();

    $matterA = Matter::factory()->create(['lead_lawyer_id' => $oldLead->id]);
    $matterB = Matter::factory()->create(['lead_lawyer_id' => $oldLead->id]);
    $matterC = Matter::factory()->create(['lead_lawyer_id' => $oldLead->id]);

    $deadlineA = Deadline::factory()->for($matterA)->create([
        'responsible_user_id' => $oldLead->id,
        'is_completed' => false,
    ]);

    $this->actingAs($admin, 'web');

    $this->livewire(BulkReassign::class)
        ->set('data.lead_lawyer_id', $oldLead->id)
        ->set('data.matter_ids', [$matterA->id, $matterB->id, $matterC->id])
        ->set('data.new_lead_id', $newLead->id)
        ->set('data.reason', 'Nghỉ việc, bàn giao cả lô.')
        ->set('data.keep_old_lead_as_associate', false)
        ->call('reassignSelected')
        ->assertHasNoErrors();

    expect($matterA->fresh()->lead_lawyer_id)->toBe($newLead->id)
        ->and($matterB->fresh()->lead_lawyer_id)->toBe($newLead->id)
        ->and($matterC->fresh()->lead_lawyer_id)->toBe($newLead->id)
        ->and($matterA->fresh()->stageLogs()->count())->toBe(1)
        ->and($matterB->fresh()->stageLogs()->count())->toBe(1)
        ->and($matterC->fresh()->stageLogs()->count())->toBe(1)
        ->and($deadlineA->fresh()->responsible_user_id)->toBe($newLead->id);

    Queue::assertPushed(SendReassignmentDigest::class, 1);
});

/**
 * "Vụ restricted chỉ chọn được khi người bấm được bàn giao nó" (brief Task 2) — thân của việc lọc
 * nằm ở `BulkReassign::matterOptions()`. Mutation probe (dán vào báo cáo): xoá lần lọc
 * `Gate::allows('manageTeam', ...)` khỏi hàm đó khiến CHÍNH test này đỏ (mã/tiêu đề vụ restricted
 * lộ ra cho trưởng phòng).
 */
it('shows a restricted matter to an admin but never to a manager when picking the batch', function () {
    $oldLead = User::factory()->withRole(Role::Lawyer)->create();
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $oldLead->id]);

    $manager = User::factory()->withRole(Role::Manager)->create();
    $this->actingAs($manager, 'web');

    $this->livewire(BulkReassign::class)
        ->set('data.lead_lawyer_id', $oldLead->id)
        ->assertDontSee($restricted->code)
        ->assertDontSee($restricted->title);

    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($admin, 'web');

    $this->livewire(BulkReassign::class)
        ->set('data.lead_lawyer_id', $oldLead->id)
        ->assertSee($restricted->code);
});

/**
 * Payload Livewire ép một id KHÔNG nằm trong `matterOptions()` đã render cho trưởng phòng (vụ
 * `restricted`, không phải lead/admin) — bị từ chối, và không mã/tiêu đề nào của nó rời khỏi
 * response (kể cả trong danh sách kết quả).
 */
it('refuses a forged restricted matter id in the payload without leaking its code or title', function () {
    $oldLead = User::factory()->withRole(Role::Lawyer)->create();
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $oldLead->id]);

    $manager = User::factory()->withRole(Role::Manager)->create();
    $this->actingAs($manager, 'web');

    $component = $this->livewire(BulkReassign::class)
        ->set('data.lead_lawyer_id', $oldLead->id)
        ->set('data.matter_ids', [$restricted->id])
        ->set('data.new_lead_id', $newLead->id)
        ->set('data.reason', 'Ép bàn giao vụ hạn chế.')
        ->set('data.keep_old_lead_as_associate', false)
        ->call('reassignSelected');

    $component->assertDontSee($restricted->code)
        ->assertDontSee($restricted->title);

    expect($restricted->fresh()->lead_lawyer_id)->toBe($oldLead->id);
});

/**
 * "Một vụ thất bại (ví dụ đã bị bàn giao ở tab khác) được báo riêng, các vụ khác vẫn xong" (brief
 * Task 2) — vụ B đã được một tab khác chuyển thẳng sang ĐÚNG lead mới trước khi lượt này bấm gửi.
 */
it('reports one failed matter on its own while the rest of the batch still succeeds', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $oldLead = User::factory()->withRole(Role::Lawyer)->create();
    $newLead = User::factory()->withRole(Role::Lawyer)->create();

    $matterA = Matter::factory()->create(['lead_lawyer_id' => $oldLead->id]);
    $matterB = Matter::factory()->create(['lead_lawyer_id' => $oldLead->id]);
    $matterB->update(['lead_lawyer_id' => $newLead->id]); // "đã bị bàn giao ở tab khác"

    $this->actingAs($admin, 'web');

    $this->livewire(BulkReassign::class)
        ->set('data.lead_lawyer_id', $oldLead->id)
        ->set('data.matter_ids', [$matterA->id, $matterB->id])
        ->set('data.new_lead_id', $newLead->id)
        ->set('data.reason', 'Bàn giao cả lô.')
        ->set('data.keep_old_lead_as_associate', false)
        ->call('reassignSelected')
        ->assertSee($matterA->code)
        ->assertSee($matterB->code);

    expect($matterA->fresh()->lead_lawyer_id)->toBe($newLead->id);
});

/**
 * Gợi ý giới thiệu chỉ hiện cho vụ ĐÃ công bố portal — không hiện cho vụ chưa công bố. Đếm số lần
 * xuất hiện của câu gợi ý (không phải chỉ `assertSee`, vì câu đó không mang thông tin riêng vụ
 * nào) — đúng MỘT trong hai vụ đã bàn giao được công bố, nên câu đó phải xuất hiện đúng MỘT lần.
 */
it('shows the client-introduction suggestion only for a matter already published to the portal', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $oldLead = User::factory()->withRole(Role::Lawyer)->create();
    $newLead = User::factory()->withRole(Role::Lawyer)->create();

    $published = Matter::factory()->create(['lead_lawyer_id' => $oldLead->id, 'is_published_to_portal' => true]);
    $unpublished = Matter::factory()->unpublished()->create(['lead_lawyer_id' => $oldLead->id]);

    $this->actingAs($admin, 'web');

    $html = $this->livewire(BulkReassign::class)
        ->set('data.lead_lawyer_id', $oldLead->id)
        ->set('data.matter_ids', [$published->id, $unpublished->id])
        ->set('data.new_lead_id', $newLead->id)
        ->set('data.reason', 'Bàn giao cả lô.')
        ->set('data.keep_old_lead_as_associate', false)
        ->call('reassignSelected')
        ->assertSee($published->code)
        ->assertSee($unpublished->code)
        ->html();

    expect(substr_count($html, __('reassign.bulk.suggest_introduction')))->toBe(1);
});

/** `?from=<user_id>` mở sẵn trang với đúng người đó đã chọn. */
it('preselects the current lead from the ?from= mount parameter', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $oldLead = User::factory()->withRole(Role::Lawyer)->create();
    Matter::factory()->create(['lead_lawyer_id' => $oldLead->id]);

    $this->actingAs($admin, 'web');

    $this->livewire(BulkReassign::class, ['from' => $oldLead->id])
        ->assertSet('data.lead_lawyer_id', $oldLead->id);
});

/**
 * Mutation probe cho `->in()` ghi đè ở `BulkReassign::form()` — bỏ nó (khôi phục lại mặc định của
 * `CheckboxList`, tự suy luật "in:" từ `matterOptions()` LÚC RENDER) khiến CHÍNH test này đỏ: vụ B
 * đã rời khỏi `matterOptions(oldLead)` (đổi lead ở "tab khác") nên bị Livewire chặn NGAY TỪ
 * `$this->form->getState()`, cả lô bị chặn theo — không chỉ vụ B — và `$this->results` không bao
 * giờ được gán, nên response không còn thấy mã của CẢ HAI vụ (kể cả vụ A vẫn lẽ ra thành công).
 */
it('accepts a matter id that fell out of the rendered options because another tab reassigned it first', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $oldLead = User::factory()->withRole(Role::Lawyer)->create();
    $newLead = User::factory()->withRole(Role::Lawyer)->create();

    $matterA = Matter::factory()->create(['lead_lawyer_id' => $oldLead->id]);
    $matterB = Matter::factory()->create(['lead_lawyer_id' => $oldLead->id]);
    $matterB->update(['lead_lawyer_id' => $newLead->id]); // "đã bị bàn giao ở tab khác"

    $this->actingAs($admin, 'web');

    $this->livewire(BulkReassign::class)
        ->set('data.lead_lawyer_id', $oldLead->id)
        ->set('data.matter_ids', [$matterA->id, $matterB->id])
        ->set('data.new_lead_id', $newLead->id)
        ->set('data.reason', 'Bàn giao cả lô.')
        ->set('data.keep_old_lead_as_associate', false)
        ->call('reassignSelected')
        ->assertHasNoErrors();

    expect($matterA->fresh()->lead_lawyer_id)->toBe($newLead->id);
});
