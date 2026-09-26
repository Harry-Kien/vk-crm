<?php

use App\Enums\PartyRole;
use App\Enums\Role;
use App\Jobs\RecheckClientIdentityConflicts;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\Models\Activity;

/**
 * M6.5 Task 8, fix round 3, N1 — hành vi RIÊNG của job (khoá/thử lại/`failed()`), tách khỏi câu hỏi
 * "job có được dispatch đúng lúc hay không" (đó là việc của `tests/Feature/Models/
 * ClientIdentitySyncTest.php`, hai test "does not dispatch..."/"runs the identity recheck job
 * through the real dispatch path..."). Mọi test ở đây gọi `->handle()`/`->failed()` TRỰC TIẾP trên
 * một instance job tự dựng — không qua `dispatch()`, không phụ thuộc hàng đợi/transaction nào,
 * đúng cách kiểm thử một job idiomatic của Laravel khi cái cần đo là LOGIC bên trong nó.
 *
 * `$lockWaitSeconds = 1` (không phải `10` mặc định) ở các test cần giữ khoá bằng tay — xem docblock
 * lớp `RecheckClientIdentityConflicts` cho lý do không lặp lại phép đo "chờ rồi ném" ~10-17s thật
 * đã có sẵn ở `OpenMatterTest`/`AddMatterPartyTest`.
 */
it('lets a LockTimeoutException escape when the conflict-check lock is already held, so the queue can retry', function () {
    $client = Client::factory()->create();

    $job = new RecheckClientIdentityConflicts($client->getKey());
    $job->lockWaitSeconds = 1;

    $lock = Cache::store('database')->lock('conflict-check', 30);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => $job->handle())->toThrow(LockTimeoutException::class);
    } finally {
        $lock->release();
    }
});

/**
 * Đối xứng với test trên: khoá RẢNH thì `handle()` phải THẬT SỰ chạy lần rà và báo đúng vụ việc bị
 * ảnh hưởng — "the lock is later free → the recheck runs and notifies" của phán quyết N1.
 */
it('runs the recheck and notifies the affected matter when the lock is free', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $otherLead = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create(['id_number' => '090000000081']);
    $matter = Matter::factory()->create();
    MatterParty::factory()->for($matter)->ourClient($client, PartyRole::Plaintiff)->create();

    $otherMatter = Matter::factory()->create(['lead_lawyer_id' => $otherLead->id]);
    MatterParty::factory()->for($otherMatter)->create(['role' => PartyRole::Defendant, 'name' => 'Bị đơn khác'])
        ->identify('090000000082', null)->save();

    // Đúng những gì SyncClientPartyIdentities::handle() đã làm ở giai đoạn ĐỒNG BỘ trước khi
    // dispatch job — job không tự sửa định danh, nó chỉ RÀ LẠI những gì đã được ghi.
    MatterParty::where('matter_id', $matter->id)->first()->identify('090000000082', null)->save();

    $job = new RecheckClientIdentityConflicts($client->getKey());
    $job->handle();

    $otherMatterAudit = Activity::query()->where('event', 'client_identity_conflict_detected')
        ->where('subject_type', $otherMatter->getMorphClass())->where('subject_id', $otherMatter->id)->first();

    expect($otherMatterAudit)->not->toBeNull()
        ->and($otherLead->notifications()->count())->toBe(1);
});

/**
 * `failed()` — chỉ chạy SAU KHI cả `$tries` lần đều thất bại. Phải HIỆN RA trong ứng dụng: một
 * dòng audit `client_identity_recheck_failed` VÀ một thông báo trong ứng dụng cho MỌI admin đang
 * hoạt động — SPEC §2 (shared hosting, không ai đọc `laravel.log`) là lý do N1 tồn tại.
 */
it('writes an audit row and notifies every active admin when the job permanently fails', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $admin = User::factory()->withRole(Role::Admin)->create();
    $inactiveAdmin = User::factory()->withRole(Role::Admin)->create(['is_active' => false]);
    $client = Client::factory()->create();

    $job = new RecheckClientIdentityConflicts($client->getKey());
    $job->failed(new RuntimeException('Sự cố giả lập để kiểm tra failed().'));

    $audit = Activity::query()->where('event', 'client_identity_recheck_failed')->latest('id')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->properties->get('client_id'))->toBe($client->getKey())
        ->and($audit->properties->get('error_class'))->toBe(RuntimeException::class)
        ->and($admin->notifications()->count())->toBe(1)
        ->and($inactiveAdmin->notifications()->count())->toBe(0);
});

/**
 * `failed()` không được phép ném ra ngoài nếu KHÔNG tìm thấy hồ sơ khách hàng (đã xoá mềm SÂU, hay
 * id không còn tồn tại) — vẫn phải ghi audit + báo admin, chỉ là không gắn `subject` nào.
 */
it('still writes the failure audit and notification even when the client record cannot be found', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $admin = User::factory()->withRole(Role::Admin)->create();

    $job = new RecheckClientIdentityConflicts(999999999);
    $job->failed(new RuntimeException('Sự cố giả lập.'));

    expect(Activity::query()->where('event', 'client_identity_recheck_failed')->exists())->toBeTrue()
        ->and($admin->notifications()->count())->toBe(1);
});

/**
 * Cùng lỗ hổng quét thấy ở `recheckForQueuedClient()` (xem docblock test "bypasses the client
 * portal scope..." của `ClientIdentitySyncTest.php`) — `Client` mang `ClientPortalScope` qua trait
 * `RestrictedToClientPortal`. Nếu `failed()` không bỏ scope đó, dòng audit sẽ gắn `subject = null`
 * cho một khách hàng CÓ THẬT, chỉ vì một `ClientUser` khác tình cờ "đang đăng nhập" ở tiến trình
 * worker (hay tiến trình test).
 */
it('resolves the client for the failure audit even while an unrelated client guard is authenticated', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    User::factory()->withRole(Role::Admin)->create();
    $client = Client::factory()->create();

    $unrelatedClientUser = ClientUser::factory()->create();
    $this->actingAs($unrelatedClientUser, 'client');

    $job = new RecheckClientIdentityConflicts($client->getKey());
    $job->failed(new RuntimeException('Sự cố giả lập.'));

    $audit = Activity::query()->where('event', 'client_identity_recheck_failed')->latest('id')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->subject_type)->toBe($client->getMorphClass())
        ->and((int) $audit->subject_id)->toBe($client->getKey());
});
