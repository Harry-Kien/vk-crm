<?php

use App\Enums\ClientRequestStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Mail\Client\StageUpdate;
use App\Mail\Staff\MatterReassigned;
use App\Models\ClientRequest;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\ExpectationFailedException;
use Spatie\Activitylog\Models\Activity;

/**
 * Header action "Bàn giao" trên `ViewMatter` (SPEC §6.11; M6.5 Task 4, R7). Mọi test đi qua
 * Livewire (`callAction`), KHÔNG gọi thẳng `App\Actions\Matter\ReassignMatter` — cùng bài học của
 * brief Task 3 (49 lời gọi `addTeamMember()` trong test cũ đã che một luồng không tồn tại ngoài
 * đời).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    Mail::fake();
    NotificationFacade::fake();
});

/**
 * M7 Task 1: bàn giao qua màn hình giờ CŨNG xếp một thư tổng hợp mốc hạn cho lead mới (SPEC
 * §6.11 bước 3, dựng trên `App\Jobs\SendReassignmentDigest` — xem tệp test riêng của job cho
 * hành vi "dựng lại lúc gửi"). `Mail::assertNothingSent()` không còn đúng nữa; vế "không mail nào
 * tới KHÁCH" của tên test này vẫn giữ nguyên — kiểm bằng cách phủ định đúng lớp thư gửi khách
 * (`App\Mail\Client\StageUpdate`), không phải phủ định TOÀN BỘ facade `Mail`.
 */
it('reassigns the lead through the header action: lead changes, unfinished deadlines move, an unpublished internal stage log is written, and no mail goes to the client', function () {
    $oldLead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Cũ']);
    $newLead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Mới']);
    $matter = Matter::factory()->create(['lead_lawyer_id' => $oldLead->id, 'is_published_to_portal' => true]);

    $unfinished = Deadline::factory()->for($matter)->create([
        'responsible_user_id' => $oldLead->id,
        'is_completed' => false,
    ]);
    $finished = Deadline::factory()->for($matter)->create([
        'responsible_user_id' => $oldLead->id,
        'is_completed' => true,
        'completed_at' => now(),
    ]);

    $this->actingAs($oldLead, 'web');

    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->callAction('reassignMatter', data: [
            'new_lead_id' => $newLead->id,
            'keep_old_lead_as_associate' => true,
            'reason' => 'Luật sư cũ chuyển công tác sang chi nhánh khác.',
        ])
        ->assertHasNoActionErrors();

    $matter->refresh();

    expect($matter->lead_lawyer_id)->toBe($newLead->id)
        ->and($matter->team()->whereKey($newLead->id)->where('role_in_matter', MatterRole::Lead->value)->exists())->toBeTrue()
        ->and($unfinished->fresh()->responsible_user_id)->toBe($newLead->id)
        // Mốc ĐÃ hoàn thành không bị "bàn giao" — brief Task 4 lệch có chủ đích với SPEC §6.11.
        ->and($finished->fresh()->responsible_user_id)->toBe($oldLead->id);

    $internalLog = StageLog::query()->where('matter_id', $matter->id)->latest('id')->first();

    expect($internalLog)->not->toBeNull()
        ->and($internalLog->is_published)->toBeFalse()
        ->and($internalLog->from_stage)->toBe($internalLog->to_stage)
        ->and($internalLog->internal_note)->toContain('Luật sư cũ chuyển công tác sang chi nhánh khác.');

    Mail::assertSent(MatterReassigned::class, function (MatterReassigned $mail) use ($newLead, $unfinished): bool {
        return $mail->hasTo($newLead->email)
            && collect($mail->blocks[0]['deadlines'])->pluck('id')->contains($unfinished->id);
    });
    Mail::assertNotSent(StageUpdate::class);
    NotificationFacade::assertNothingSent();
});

/**
 * Spec gap (fix round 1): SPEC §6.11 bước 4 — "gợi ý soạn một dòng cập nhật công bố giới thiệu
 * luật sư mới". Chỉ MỘT gợi ý trên giao diện (Filament Notification), KHÔNG BAO GIỜ tự gửi tới
 * KHÁCH — vế đó giờ kiểm bằng `Mail::assertNotSent(StageUpdate::class)` (M7 Task 1: thư tổng hợp
 * mốc hạn cho NHÂN SỰ giờ được gửi thật, nên `Mail::assertNothingSent()` không còn đúng nữa —
 * xem test đầu tệp). Chỉ hiện khi vụ việc ĐÃ công bố portal: gợi ý "giới thiệu luật sư mới cho
 * khách" không có nghĩa gì trên một vụ khách còn chưa thấy được.
 */
it('suggests introducing the new lead to the client when the matter is published to the portal', function () {
    $oldLead = User::factory()->withRole(Role::Lawyer)->create();
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $oldLead->id, 'is_published_to_portal' => true]);

    $this->actingAs($oldLead, 'web');

    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->callAction('reassignMatter', data: [
            'new_lead_id' => $newLead->id,
            'keep_old_lead_as_associate' => false,
            'reason' => 'Bàn giao.',
        ])
        ->assertHasNoActionErrors();

    Notification::assertNotified(__('reassign.action.suggest_introduction_title'));
    Mail::assertNotSent(StageUpdate::class);
});

/** Vế âm bắt buộc: một vụ CHƯA công bố portal không hiện gợi ý này. */
/**
 * `keep_old_lead_as_associate: true` — CỐ Ý, để cô lập ĐÚNG điều kiện đang đo (công bố portal).
 * Giữ lead cũ trong đội ngũ nghĩa là họ vẫn `view` được vụ việc sau khi bàn giao, nên không nhánh
 * "điều hướng về danh sách" (test riêng) chen vào chặn mất việc đọc lại notification ở dưới.
 */
it('does not suggest a client introduction when the matter is not published to the portal', function () {
    $oldLead = User::factory()->withRole(Role::Lawyer)->create();
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $oldLead->id, 'is_published_to_portal' => false]);

    $this->actingAs($oldLead, 'web');

    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->callAction('reassignMatter', data: [
            'new_lead_id' => $newLead->id,
            'keep_old_lead_as_associate' => true,
            'reason' => 'Bàn giao.',
        ])
        ->assertHasNoActionErrors();

    $component = new Notifications;
    $component->mount();

    // `pluck('title')` KHÔNG dùng được ở đây: `Notification::$title` là `protected`, nên
    // `data_get()` (thứ `pluck()` gọi) không đọc được nó từ NGOÀI lớp — luôn trả `null`, và một
    // khẳng định "không chứa" trên một cột toàn `null` xanh vô điều kiện, đo được gì cũng vậy.
    // `getTitle()` là getter công khai thật.
    expect($component->notifications->map(fn ($n): ?string => $n->getTitle()))
        ->not->toContain(__('reassign.action.suggest_introduction_title'));
});

it('moves only open client requests assigned to the old lead, leaving a closed one alone', function () {
    $oldLead = User::factory()->withRole(Role::Lawyer)->create();
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $oldLead->id]);

    $open = ClientRequest::factory()->for($matter)->create([
        'assigned_to' => $oldLead->id,
        'status' => ClientRequestStatus::InProgress,
    ]);
    $closed = ClientRequest::factory()->for($matter)->create([
        'assigned_to' => $oldLead->id,
        'status' => ClientRequestStatus::Closed,
    ]);

    $this->actingAs($oldLead, 'web');

    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->callAction('reassignMatter', data: [
            'new_lead_id' => $newLead->id,
            'keep_old_lead_as_associate' => true,
            'reason' => 'Bàn giao.',
        ])
        ->assertHasNoActionErrors();

    expect($open->fresh()->assigned_to)->toBe($newLead->id)
        ->and($closed->fresh()->assigned_to)->toBe($oldLead->id);
});

it('keeps the old lead on the team as an associate when chosen, on a normal matter', function () {
    $oldLead = User::factory()->withRole(Role::Lawyer)->create();
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $oldLead->id]);

    $this->actingAs($oldLead, 'web');

    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->callAction('reassignMatter', data: [
            'new_lead_id' => $newLead->id,
            'keep_old_lead_as_associate' => true,
            'reason' => 'Bàn giao, vẫn hỗ trợ.',
        ])
        ->assertHasNoActionErrors();

    expect($matter->team()->whereKey($oldLead->id)->where('role_in_matter', MatterRole::Associate->value)->exists())->toBeTrue();
});

it('detaches the old lead entirely when the form does not keep them', function () {
    $oldLead = User::factory()->withRole(Role::Lawyer)->create();
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $oldLead->id]);

    $this->actingAs($oldLead, 'web');

    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->callAction('reassignMatter', data: [
            'new_lead_id' => $newLead->id,
            'keep_old_lead_as_associate' => false,
            'reason' => 'Bàn giao hẳn.',
        ])
        ->assertHasNoActionErrors();

    expect($matter->team()->whereKey($oldLead->id)->exists())->toBeFalse();
});

/**
 * Vụ `restricted`: người mới xem được (họ là lead), người cũ (không còn trong đội) không xem
 * được. Công tắc "giữ lại" bị ẩn (visible() false) trên một vụ restricted, nên form thật gửi lên
 * `keep_old_lead_as_associate` vắng mặt — hành vi giống hệt chọn "không giữ".
 */
it('reassigns a restricted matter to another lawyer: the new lead can see it, the old lead cannot', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $oldLead = User::factory()->withRole(Role::Lawyer)->create();
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->restricted()->create(['lead_lawyer_id' => $oldLead->id]);

    $this->actingAs($admin, 'web');

    // Không gửi `keep_old_lead_as_associate` — đúng những gì form thật gửi lên khi công tắc bị
    // `visible()` false trên một vụ `restricted` (trường ẩn không được Filament dehydrate).
    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->callAction('reassignMatter', data: [
            'new_lead_id' => $newLead->id,
            'reason' => 'Bàn giao vụ hạn chế.',
        ])
        ->assertHasNoActionErrors();

    $matter->refresh();

    expect($matter->team()->whereKey($oldLead->id)->exists())->toBeFalse();

    $this->actingAs($newLead, 'web')
        ->get(MatterResource::getUrl('view', ['record' => $matter], panel: 'admin'))
        ->assertOk();

    $this->actingAs($oldLead, 'web')
        ->get(MatterResource::getUrl('view', ['record' => $matter], panel: 'admin'))
        ->assertNotFound();

    // M7 Task 1: một vụ `restricted` vẫn xếp thư tổng hợp cho lead mới như thường — họ CHÍNH là
    // người vừa được cấp quyền xem vụ này (lead_lawyer_id), nên qualifies() ở job không loại họ.
    Mail::assertSent(MatterReassigned::class, fn (MatterReassigned $mail): bool => $mail->hasTo($newLead->email));
});

/**
 * Minor (fix round 1): sau khi TỰ bàn giao một vụ `restricted`, chính lead cũ đang đứng trên
 * trang này mất luôn quyền xem nó (họ không được giữ lại làm associate — công tắc đó bị ẩn trên
 * vụ hạn chế). Ở lại trên một trang họ không còn mở được là một trang 404 chờ sẵn ở lần
 * Livewire re-render kế tiếp; điều hướng ngay về danh sách vụ việc, kèm thông báo thành công.
 */
it('redirects a lead to the matter list after handing off a restricted matter they can no longer view', function () {
    $oldLead = User::factory()->withRole(Role::Lawyer)->create();
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->restricted()->create(['lead_lawyer_id' => $oldLead->id]);

    $this->actingAs($oldLead, 'web');

    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->callAction('reassignMatter', data: [
            'new_lead_id' => $newLead->id,
            'reason' => 'Bàn giao vụ hạn chế, tự bàn giao.',
        ])
        ->assertHasNoActionErrors()
        ->assertRedirect(MatterResource::getUrl('index', panel: 'admin'));

    Notification::assertNotified(__('reassign.action.success'));
});

/** Vế dương: admin vẫn xem được vụ hạn chế sau khi bàn giao (nhánh admin của isListableBy), nên KHÔNG bị điều hướng đi. */
it('does not redirect an admin away after reassigning a restricted matter, since they can still view it', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $oldLead = User::factory()->withRole(Role::Lawyer)->create();
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->restricted()->create(['lead_lawyer_id' => $oldLead->id]);

    $this->actingAs($admin, 'web');

    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->callAction('reassignMatter', data: [
            'new_lead_id' => $newLead->id,
            'reason' => 'Bàn giao vụ hạn chế.',
        ])
        ->assertHasNoActionErrors()
        ->assertNoRedirect();
});

/**
 * Lưới an toàn NẰM DƯỚI Filament: một field `visible()` false không bao giờ dehydrate được giá
 * trị `true` qua form thật (đã xác nhận bằng chính test "reassigns a restricted matter..." ở
 * trên — form thật không gửi khoá này lên). Nên để đo lớp phòng thủ THẬT SỰ bên trong
 * `ReassignMatter::handle()` (không phải một chi tiết dehydrate của framework), test này gọi
 * thẳng `ViewMatter::submitReassign()` — cùng thành ngữ `ViewMatterTest`
 * ("refuses a forged client id on the add-party form, in the layer below Filaments own option
 * rule", `PartiesRelationManager::createParty()`) — với một payload GIẢ ép
 * `keep_old_lead_as_associate = true` cho một vụ `restricted`. Toàn bộ transaction phải rollback:
 * lead KHÔNG đổi, kể cả khi phần đầu (bước 1) đã chạy trước khi Action ném lỗi ở bước "giữ lại".
 */
it('refuses to keep the old lead as an associate on a restricted matter even when forced, and rolls back the whole handover', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $oldLead = User::factory()->withRole(Role::Lawyer)->create();
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->restricted()->create(['lead_lawyer_id' => $oldLead->id]);

    $this->actingAs($admin, 'web');

    $page = $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])->instance();

    $submitReassign = Closure::bind(
        fn (array $data) => $this->submitReassign($data),
        $page,
        ViewMatter::class,
    );

    expect(fn () => $submitReassign([
        'new_lead_id' => $newLead->id,
        'keep_old_lead_as_associate' => true,
        'reason' => 'Bàn giao vụ hạn chế, ép giữ lại.',
    ]))->toThrow(ValidationException::class);

    $matter->refresh();

    expect($matter->lead_lawyer_id)->toBe($oldLead->id)
        ->and($matter->team()->whereKey($oldLead->id)->where('role_in_matter', MatterRole::Lead->value)->exists())->toBeTrue();

    // M7 Task 1: toàn bộ transaction rollback (kể cả bước dispatch thư tổng hợp, đứng SAU trong
    // cùng transaction) — không có thư nào được xếp hàng cho một lần bàn giao đã bị từ chối.
    Mail::assertNothingSent();
});

it('writes a matter_reassigned audit entry', function () {
    $oldLead = User::factory()->withRole(Role::Lawyer)->create();
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $oldLead->id]);

    $this->actingAs($oldLead, 'web');

    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->callAction('reassignMatter', data: [
            'new_lead_id' => $newLead->id,
            'keep_old_lead_as_associate' => false,
            'reason' => 'Bàn giao.',
        ])
        ->assertHasNoActionErrors();

    expect(Activity::query()->where('event', 'matter_reassigned')
        ->where('properties->from_user_id', $oldLead->id)
        ->where('properties->to_user_id', $newLead->id)
        ->exists())->toBeTrue();
});

/**
 * Cổng: cùng `manageTeam` (R5) — trợ lý không quản lý được đội ngũ dù đang trong đội, nên không
 * bàn giao được.
 */
it('hides the reassign button from an assistant and blocks a forced call', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter->addTeamMember($assistant, MatterRole::Assistant);
    $newLead = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($assistant, 'web');

    $component = $this->livewire(ViewMatter::class, ['record' => $matter->getKey()]);
    $component->assertActionHidden('reassignMatter');

    expect(fn () => $component->callAction('reassignMatter', data: [
        'new_lead_id' => $newLead->id,
        'reason' => 'Không được phép.',
    ]))->toThrow(ExpectationFailedException::class);

    expect($matter->fresh()->lead_lawyer_id)->toBe($lead->id);
});

/**
 * Lớp phòng thủ NẰM DƯỚI Filament (defense-in-depth): `ReassignMatter::handle()` tự hỏi lại
 * `Gate::forUser($actor)->authorize('manageTeam', $matter)`, không tin trang đã lọc đúng — cùng
 * kỷ luật `AddTeamMember`/`RemoveTeamMember`. Test trên đã đo lớp UI (nút ẩn); test này gọi thẳng
 * `ViewMatter::submitReassign()` (bỏ qua tầng `->authorize()` của chính header action) để đo
 * ĐÚNG lớp bên dưới, cùng thành ngữ test "refuses to keep the old lead... even when forced".
 */
it('still refuses a forced reassignment call from an assistant, in the layer below the header actions own authorize()', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter->addTeamMember($assistant, MatterRole::Assistant);
    $newLead = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($assistant, 'web');

    $page = $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])->instance();

    $submitReassign = Closure::bind(
        fn (array $data) => $this->submitReassign($data),
        $page,
        ViewMatter::class,
    );

    expect(fn () => $submitReassign([
        'new_lead_id' => $newLead->id,
        'reason' => 'Ép bàn giao không có quyền.',
    ]))->toThrow(AuthorizationException::class);

    expect($matter->fresh()->lead_lawyer_id)->toBe($lead->id);
});

/**
 * Mutation probe (CLAUDE.md/brief): xoá điều kiện "chỉ deadline CHƯA HOÀN THÀNH" (đổi
 * `->where('is_completed', false)` thành không lọc gì) và cho test trên đỏ — bằng chứng dán vào
 * báo cáo. Test này giữ vế DƯƠNG (mốc chưa xong vẫn chuyển) để cặp với vế âm ở trên.
 */
it('still moves the unfinished deadline (positive pair for the mutation probe)', function () {
    $oldLead = User::factory()->withRole(Role::Lawyer)->create();
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $oldLead->id]);

    $unfinished = Deadline::factory()->for($matter)->create([
        'responsible_user_id' => $oldLead->id,
        'is_completed' => false,
    ]);

    $this->actingAs($oldLead, 'web');

    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->callAction('reassignMatter', data: [
            'new_lead_id' => $newLead->id,
            'keep_old_lead_as_associate' => false,
            'reason' => 'Bàn giao.',
        ])
        ->assertHasNoActionErrors();

    expect($unfinished->fresh()->responsible_user_id)->toBe($newLead->id);
});
