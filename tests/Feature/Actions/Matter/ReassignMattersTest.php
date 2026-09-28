<?php

use App\Actions\Matter\BulkReassignMatterResult;
use App\Actions\Matter\ReassignMatters;
use App\Enums\Role;
use App\Jobs\SendReassignmentDigest;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;

/**
 * Test Ở TẦNG ACTION cho `App\Actions\Matter\ReassignMatters` (M7 Task 2) — vòng lặp gọi
 * `ReassignMatter::handle()` cho từng vụ, một transaction riêng mỗi vụ, gộp một thư tổng hợp DUY
 * NHẤT cho cả lô. Test màn hình (Livewire, cổng quyền, che giấu vụ `restricted`) ở
 * `tests/Feature/Filament/BulkReassignTest.php`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->oldLead = User::factory()->withRole(Role::Lawyer)->create();
    $this->newLead = User::factory()->withRole(Role::Lawyer)->create();
    $this->admin = User::factory()->withRole(Role::Admin)->create();
});

it('reassigns every matter in the batch, each with its own stage log and moved deadlines', function () {
    $matterA = Matter::factory()->create(['lead_lawyer_id' => $this->oldLead->id]);
    $matterB = Matter::factory()->create(['lead_lawyer_id' => $this->oldLead->id]);

    $deadlineA = Deadline::factory()->for($matterA)->create([
        'responsible_user_id' => $this->oldLead->id,
        'is_completed' => false,
    ]);
    $deadlineB = Deadline::factory()->for($matterB)->create([
        'responsible_user_id' => $this->oldLead->id,
        'is_completed' => false,
    ]);

    Queue::fake();

    $results = app(ReassignMatters::class)->handle(
        matterIds: [$matterA->id, $matterB->id],
        actor: $this->admin,
        newLead: $this->newLead,
        reason: 'Nghỉ việc, bàn giao cả lô.',
        keepOldLeadAsAssociate: false,
    );

    expect($results)->toHaveCount(2)
        ->and($results[0])->toBeInstanceOf(BulkReassignMatterResult::class)
        ->and($results[0]->success)->toBeTrue()
        ->and($results[1]->success)->toBeTrue();

    expect($matterA->fresh()->lead_lawyer_id)->toBe($this->newLead->id)
        ->and($matterB->fresh()->lead_lawyer_id)->toBe($this->newLead->id)
        ->and($matterA->fresh()->stageLogs()->count())->toBe(1)
        ->and($matterB->fresh()->stageLogs()->count())->toBe(1)
        ->and($deadlineA->fresh()->responsible_user_id)->toBe($this->newLead->id)
        ->and($deadlineB->fresh()->responsible_user_id)->toBe($this->newLead->id);
});

/**
 * Mutation probe cặp với test trên: một transaction NGOÀI bọc cả vòng lặp sẽ khiến lỗi ở vụ thứ
 * hai rollback luôn vụ đầu — test này xác nhận điều đó KHÔNG xảy ra.
 */
it('keeps a matter that already succeeded even when a later matter in the batch fails', function () {
    $matterA = Matter::factory()->create(['lead_lawyer_id' => $this->oldLead->id]);
    $matterB = Matter::factory()->create(['lead_lawyer_id' => $this->oldLead->id]);

    // Mô phỏng "đã bị bàn giao ở tab khác": vụ B đã được chuyển thẳng sang ĐÚNG lead mới trước khi
    // lượt hàng loạt này chạy tới — ReassignMatter::handle() sẽ từ chối với "same_lead".
    $matterB->update(['lead_lawyer_id' => $this->newLead->id]);

    $results = app(ReassignMatters::class)->handle(
        matterIds: [$matterA->id, $matterB->id],
        actor: $this->admin,
        newLead: $this->newLead,
        reason: 'Bàn giao cả lô.',
        keepOldLeadAsAssociate: false,
    );

    expect($results[0]->success)->toBeTrue()
        ->and($results[0]->matterId)->toBe($matterA->id)
        ->and($results[1]->success)->toBeFalse()
        ->and($results[1]->matterId)->toBe($matterB->id)
        ->and($results[1]->message)->not->toBe('');

    expect($matterA->fresh()->lead_lawyer_id)->toBe($this->newLead->id);
});

/** Một id đã bị xoá/không tồn tại vẫn có một dòng kết quả, không làm vỡ vòng lặp. */
it('reports a missing matter id without breaking the rest of the batch', function () {
    $matterA = Matter::factory()->create(['lead_lawyer_id' => $this->oldLead->id]);
    $missingId = $matterA->id + 999_000;

    $results = app(ReassignMatters::class)->handle(
        matterIds: [$missingId, $matterA->id],
        actor: $this->admin,
        newLead: $this->newLead,
        reason: 'Bàn giao cả lô.',
        keepOldLeadAsAssociate: false,
    );

    expect($results[0]->success)->toBeFalse()
        ->and($results[0]->matterId)->toBe($missingId)
        ->and($results[0]->matterCode)->toBeNull()
        ->and($results[1]->success)->toBeTrue();
});

/**
 * `AuthorizationException` (manageTeam từ chối, ví dụ vụ `restricted` mà actor không được bàn
 * giao) không được gắn mã/tiêu đề — xem docblock `BulkReassignMatterResult`. Actor là TRƯỞNG
 * PHÒNG (qua được cổng vào trang BulkReassign — xem test Livewire — nhưng KHÔNG qua được
 * `manageTeam` trên một vụ `restricted` mà họ không phải lead/admin).
 */
it('redacts the matter code and title when the actor is not authorized to manage that matter', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->oldLead->id]);

    $results = app(ReassignMatters::class)->handle(
        matterIds: [$restricted->id],
        actor: $manager,
        newLead: $this->newLead,
        reason: 'Ép bàn giao vụ hạn chế.',
        keepOldLeadAsAssociate: false,
    );

    expect($results[0]->success)->toBeFalse()
        ->and($results[0]->matterCode)->toBeNull()
        ->and($results[0]->matterTitle)->toBeNull()
        ->and($results[0]->message)->not->toContain($restricted->code)
        ->and($results[0]->message)->not->toContain($restricted->title);

    expect($restricted->fresh()->lead_lawyer_id)->toBe($this->oldLead->id);
});

/**
 * Vụ `restricted` LUÔN gỡ lead cũ, bất kể công tắc "giữ lại" của cả lô đang bật — actor là ADMIN
 * (qua được manageTeam trên vụ restricted). Mutation probe: bỏ điều kiện ép này khiến
 * `ReassignMatter::handle()` ném "old_lead_would_not_see_matter" (lead cũ không phải admin sẽ
 * không còn xem được vụ restricted với vai associate), biến một vụ lẽ ra thành công thành thất
 * bại — chính test này đỏ nếu thiếu điều kiện ép.
 */
it('forces the old lead off a restricted matter even when the batch toggle asked to keep them', function () {
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->oldLead->id]);

    $results = app(ReassignMatters::class)->handle(
        matterIds: [$restricted->id],
        actor: $this->admin,
        newLead: $this->newLead,
        reason: 'Bàn giao vụ hạn chế, giữ lại lead cũ (sẽ bị ép tắt).',
        keepOldLeadAsAssociate: true,
    );

    expect($results[0]->success)->toBeTrue();

    expect($restricted->fresh()->team()->whereKey($this->oldLead->id)->exists())->toBeFalse();
});

it('dispatches exactly one digest for the whole batch, after commit, listing only the successful matters', function () {
    Queue::fake();

    $matterA = Matter::factory()->create(['lead_lawyer_id' => $this->oldLead->id]);
    $matterB = Matter::factory()->create(['lead_lawyer_id' => $this->oldLead->id]);
    $matterB->update(['lead_lawyer_id' => $this->newLead->id]); // déjà bàn giao — sẽ thất bại (same_lead)

    app(ReassignMatters::class)->handle(
        matterIds: [$matterA->id, $matterB->id],
        actor: $this->admin,
        newLead: $this->newLead,
        reason: 'Bàn giao cả lô.',
        keepOldLeadAsAssociate: false,
    );

    Queue::assertPushed(SendReassignmentDigest::class, function (SendReassignmentDigest $job) use ($matterA, $matterB): bool {
        return $job->newLeadId === $this->newLead->id
            && array_key_exists($matterA->id, $job->matters)
            && ! array_key_exists($matterB->id, $job->matters)
            && $job->afterCommit === true;
    });

    Queue::assertPushed(SendReassignmentDigest::class, 1);
});

/** Không vụ nào thành công thì không dispatch gì — cùng "không còn gì thì không gửi" của Task 1. */
it('does not dispatch any digest when every matter in the batch failed', function () {
    Queue::fake();

    $manager = User::factory()->withRole(Role::Manager)->create();
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->oldLead->id]);

    app(ReassignMatters::class)->handle(
        matterIds: [$restricted->id],
        actor: $manager,
        newLead: $this->newLead,
        reason: 'Ép bàn giao vụ hạn chế.',
        keepOldLeadAsAssociate: false,
    );

    Queue::assertNotPushed(SendReassignmentDigest::class);
});
