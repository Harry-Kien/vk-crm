<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\BulkReassign;
use App\Jobs\SendReassignmentDigest;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
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

// -------------------------------------------------------------------------------------------
// Fix round 1 (task-2-fix1-findings.md)
// -------------------------------------------------------------------------------------------

/**
 * Finding 1 — kịch bản CHÍNH XÁC của controller: "một vụ thất bại (ví dụ đã bị bàn giao ở tab
 * khác) được báo riêng". Trước bản sửa này (probe đã dán vào báo cáo,
 * `.superpowers/sdd/m7/ReviewT2ProbeTest.php`), B bị bàn giao LẦN NỮA từ Z sang N và báo "Đã bàn
 * giao thành công." — đè mất bàn giao của tab kia mà không ai được báo. Test cũ
 * ("reports one failed matter...") KHÔNG bắt được lỗi này vì nó cho tab kia bàn giao B sang ĐÚNG
 * N — B thất bại "tình cờ đúng" nhờ kiểm tra "same_lead" có sẵn cho một lý do khác hẳn. Ở đây tab
 * kia chọn một lead THỨ BA (Z ≠ N) — chỉ `expectedLeadId` (fix round 1, finding 1) mới bắt được.
 *
 * Mutation probe (dán vào báo cáo): bỏ câu kiểm tra `$expectedLeadId` ở `ReassignMatter::handle()`
 * (hoặc gỡ tham số `expectedLeadId` khỏi lời gọi trong `ReassignMatters`) khiến CHÍNH test này đỏ
 * — `$matterB->fresh()->lead_lawyer_id` trả về `$newLead->id` thay vì `$thirdLawyer->id`.
 */
it('reports one failed matter and leaves its lead unchanged when another tab already reassigned it to a THIRD lawyer', function () {
    Queue::fake();

    $admin = User::factory()->withRole(Role::Admin)->create();
    $oldLead = User::factory()->withRole(Role::Lawyer)->create();
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $thirdLawyer = User::factory()->withRole(Role::Lawyer)->create();

    $matterA = Matter::factory()->create(['lead_lawyer_id' => $oldLead->id]);
    $matterB = Matter::factory()->create(['lead_lawyer_id' => $oldLead->id]);

    $this->actingAs($admin, 'web');

    $component = $this->livewire(BulkReassign::class)
        ->set('data.lead_lawyer_id', $oldLead->id)
        ->set('data.matter_ids', [$matterA->id, $matterB->id]);

    // "tab khác" bàn giao B sang MỘT LEAD THỨ BA — không phải N, lead sẽ chọn ở lượt hàng loạt này.
    $matterB->update(['lead_lawyer_id' => $thirdLawyer->id]);

    $component->set('data.new_lead_id', $newLead->id)
        ->set('data.reason', 'Bàn giao cả lô.')
        ->set('data.keep_old_lead_as_associate', false)
        ->call('reassignSelected')
        ->assertHasNoErrors()
        ->assertSee($matterA->code)
        ->assertSee($matterB->code);

    expect($matterA->fresh()->lead_lawyer_id)->toBe($newLead->id)
        // Z's reassignment không bị đè — B vẫn còn của Z, không rơi sang N.
        ->and($matterB->fresh()->lead_lawyer_id)->toBe($thirdLawyer->id);
});

/**
 * Finding 1 — "một id giả cho vụ của lead khác cũng bị bàn giao" (đọc từ mã, không có probe
 * riêng). `$foreign` không bao giờ nằm trong `matterOptions($oldLead)` (nó do `$otherLead` phụ
 * trách), nhưng `->in()` ghi đè ở `BulkReassign::form()` (mutation probe của test hiện có
 * "accepts a matter id that fell out of the rendered options...") chấp nhận MỌI id vụ việc còn
 * tồn tại — actor (admin) ép nó vào payload dù không hề tick trên UI. `expectedLeadId` chặn nó
 * đúng ở đây, vì `$foreign->lead_lawyer_id` (`$otherLead`) khác `$oldLead` đã chọn ở đầu trang.
 *
 * Mutation probe: bỏ câu kiểm tra `$expectedLeadId` khiến CHÍNH test này đỏ — `$foreign` bị bàn
 * giao sang `$newLead` dù chưa từng hiện trên màn hình cho lead `$oldLead` đang chọn.
 */
it('refuses a forged matter id that belongs to a different lead than the one selected on screen', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $oldLead = User::factory()->withRole(Role::Lawyer)->create();
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $otherLead = User::factory()->withRole(Role::Lawyer)->create();

    $matterA = Matter::factory()->create(['lead_lawyer_id' => $oldLead->id]);
    $foreign = Matter::factory()->create(['lead_lawyer_id' => $otherLead->id]);

    $this->actingAs($admin, 'web');

    $this->livewire(BulkReassign::class)
        ->set('data.lead_lawyer_id', $oldLead->id)
        // $foreign ép thẳng vào payload — chưa từng tick trên UI (nó thuộc $otherLead, không
        // phải $oldLead đang chọn).
        ->set('data.matter_ids', [$matterA->id, $foreign->id])
        ->set('data.new_lead_id', $newLead->id)
        ->set('data.reason', 'Bàn giao cả lô.')
        ->set('data.keep_old_lead_as_associate', false)
        ->call('reassignSelected')
        ->assertHasNoErrors();

    expect($matterA->fresh()->lead_lawyer_id)->toBe($newLead->id)
        ->and($foreign->fresh()->lead_lawyer_id)->toBe($otherLead->id);
});

/**
 * Finding 1 — vụ ĐÃ ĐÓNG (`closed_at` khác `null`) ép vào payload. `Matter::open()` đã loại nó
 * khỏi `matterOptions()` từ đầu (không bao giờ hiện để tick), nhưng `->in()` ghi đè vẫn chấp nhận
 * id của nó — actor (admin, `manageTeam` qua được vì vụ không `restricted`) ép nó vào payload.
 * `expectedLeadId` (điều kiện thứ hai: `closed_at` khác `null`) chặn nó dù `lead_lawyer_id` của
 * vụ đóng này VẪN đúng là lead đang chọn (không phải trường hợp trôi lead).
 *
 * Mutation probe: bỏ nhánh `$locked->closed_at !== null` (giữ lại nhánh so lead) khiến CHÍNH test
 * này đỏ — vụ đã đóng vẫn bị bàn giao sang lead mới.
 */
it('refuses a closed matter forged into the payload, without reopening or moving it', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $oldLead = User::factory()->withRole(Role::Lawyer)->create();
    $newLead = User::factory()->withRole(Role::Lawyer)->create();

    $matterA = Matter::factory()->create(['lead_lawyer_id' => $oldLead->id]);
    $closed = Matter::factory()->create(['lead_lawyer_id' => $oldLead->id, 'closed_at' => now()]);

    $this->actingAs($admin, 'web');

    $this->livewire(BulkReassign::class)
        ->set('data.lead_lawyer_id', $oldLead->id)
        // $closed ép thẳng vào payload — Matter::open() đã loại nó khỏi matterOptions() từ đầu.
        ->set('data.matter_ids', [$matterA->id, $closed->id])
        ->set('data.new_lead_id', $newLead->id)
        ->set('data.reason', 'Bàn giao cả lô.')
        ->set('data.keep_old_lead_as_associate', false)
        ->call('reassignSelected')
        ->assertHasNoErrors();

    expect($matterA->fresh()->lead_lawyer_id)->toBe($newLead->id)
        ->and($closed->fresh()->lead_lawyer_id)->toBe($oldLead->id);
});

/**
 * Finding 2 — `currentLeadOptions()` phải lọc CÙNG bộ `manageTeam` như `matterOptions()`, không
 * liệt kê MỌI lead của vụ đang mở. Trước bản sửa này, một lead CHỈ dẫn một vụ `restricted` vẫn
 * xuất hiện trong ô "Luật sư đang phụ trách" cho một trưởng phòng không `manageTeam` được vụ đó
 * — chọn xong danh sách vụ việc lại rỗng (`matterOptions()` đã lọc đúng), nhưng SỰ CÓ MẶT của cái
 * tên đã lộ "người này đang phụ trách ít nhất một vụ hạn chế": một kênh rò rỉ MỚI, vì trang quản
 * lý nhân sự (nơi trưởng phòng có thể thấy lại đúng tên này) vốn chỉ admin xem được.
 *
 * Mutation probe: bỏ `->filter(fn (Matter $matter) => Gate::forUser($actor)->allows('manageTeam',
 * $matter))` khỏi `currentLeadOptions()` khiến CHÍNH test này đỏ — tên lead lại hiện cho manager.
 *
 * `$restrictedOnlyLead` VÔ HIỆU HOÁ (`is_active: false`) — CHỈ để loại tên khỏi ô "Luật sư phụ
 * trách mới" ({@see BulkReassign::newLeadOptions()}, lọc `is_active`, KHÔNG liên quan finding
 * này) — nếu không, tên vẫn xuất hiện trên trang qua ô ĐÓ, làm `assertDontSee` đỏ vì một kênh khác
 * hẳn, không phải vì `currentLeadOptions()` còn rò. `currentLeadOptions()` tự nó KHÔNG lọc
 * `is_active` (xem docblock hàm đó — `withTrashed()`, giữ cả tài khoản đã nghỉ), nên việc vô hiệu
 * hoá ở đây không ảnh hưởng gì tới điều finding 2 đang đo.
 */
it('hides a lead who only leads a restricted matter from a manager picking the current-lead dropdown, but shows them to an admin', function () {
    $restrictedOnlyLead = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    Matter::factory()->restricted()->create(['lead_lawyer_id' => $restrictedOnlyLead->id]);

    $manager = User::factory()->withRole(Role::Manager)->create();
    $this->actingAs($manager, 'web');

    $this->livewire(BulkReassign::class)
        ->assertDontSee($restrictedOnlyLead->name);

    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($admin, 'web');

    $this->livewire(BulkReassign::class)
        ->assertSee($restrictedOnlyLead->name);
});

/**
 * Finding 4 — khi TOÀN BỘ vụ trong lô thất bại, thông báo tổng kết không được nói
 * "Đã bàn giao vụ việc." (bản trước dùng cứng `reassign.action.success` + `->success()` cho MỌI
 * trường hợp — một lời hứa sai khi $successCount === 0). Ép cả lô thất bại bằng một id giả cho vụ
 * `restricted` của một trưởng phòng không `manageTeam` được.
 *
 * `$oldLead` CŨNG dẫn một vụ THƯỜNG (`$manageable`, không ép vào `matter_ids`) — chỉ để họ còn
 * nằm trong {@see BulkReassign::currentLeadOptions()} của chính `$manager` này SAU fix round 1,
 * finding 2 (nếu `$oldLead` chỉ dẫn đúng vụ `restricted`, ô "Luật sư đang phụ trách" sẽ tự chặn
 * `lead_lawyer_id` này ở CHÍNH bước `$this->form->getState()`, trước cả khi chạm tới lô — che mất
 * thứ finding 4 thật sự muốn đo). Lô bàn giao chỉ gồm ĐÚNG `$restricted` (ép vào `matter_ids`,
 * không tick `$manageable`), nên 100% lô thất bại.
 *
 * Mutation probe: khôi phục lại `->title(__('reassign.action.success'))->success()` cố định
 * (bản trước fix round 1) khiến CHÍNH test này đỏ — assertNotified không còn khớp tiêu đề mới.
 */
it('shows a failure notification, not a success one, when every matter in the batch fails', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $oldLead = User::factory()->withRole(Role::Lawyer)->create();
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    Matter::factory()->create(['lead_lawyer_id' => $oldLead->id]);
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $oldLead->id]);

    $this->actingAs($manager, 'web');

    $this->livewire(BulkReassign::class)
        ->set('data.lead_lawyer_id', $oldLead->id)
        ->set('data.matter_ids', [$restricted->id])
        ->set('data.new_lead_id', $newLead->id)
        ->set('data.reason', 'Ép bàn giao vụ hạn chế.')
        ->set('data.keep_old_lead_as_associate', false)
        ->call('reassignSelected')
        ->assertHasNoErrors()
        ->assertNotified(__('reassign.bulk.notification_titles.failure'));

    Notification::assertNotNotified(__('reassign.action.success'));

    expect($restricted->fresh()->lead_lawyer_id)->toBe($oldLead->id);
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
