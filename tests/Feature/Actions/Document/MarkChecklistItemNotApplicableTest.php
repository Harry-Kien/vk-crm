<?php

use App\Actions\Document\MarkChecklistItemNotApplicable;
use App\Enums\ChecklistItemStatus;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\ChecklistItemNotReviewable;
use App\Exceptions\MatterChecklistReadOnly;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->create(['client_id' => $this->client->id]);
    $this->matter = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lawyer->id]);
    $this->item = MatterChecklistItem::factory()->for($this->matter)
        ->status(ChecklistItemStatus::Missing)
        ->create();
});

function markNotApplicable(MatterChecklistItem $item, User $actor): MatterChecklistItem
{
    return app(MarkChecklistItemNotApplicable::class)->handle($item, $actor);
}

/** Cùng câu, cùng lớp cho cả ba tình huống của SPEC §10.10 — xem `OpensChecklistItem`. */
function expectMarkRefusal(Closure $call): void
{
    expect($call)->toThrow(fn (ChecklistItemNotReviewable $exception) => expect($exception->getMessage())
        ->toBe(__('checklist.review.item_unavailable')));
}

// ---------------------------------------------------------------------------------------------
// SPEC §4.10: `not_applicable` là một trạng thái có thật của đầu mục, và tới hôm nay không có
// một đường nào trong hệ thống ĐẶT được nó.
// ---------------------------------------------------------------------------------------------

it('đặt đầu mục sang not_applicable kèm người quyết định và thời điểm', function () {
    $item = markNotApplicable($this->item, $this->lawyer);

    expect($item->status)->toBe(ChecklistItemStatus::NotApplicable)
        ->and($item->reviewed_by)->toBe($this->lawyer->id)
        ->and($item->reviewed_at)->not->toBeNull()
        ->and($this->item->fresh()->status)->toBe(ChecklistItemStatus::NotApplicable);
});

it('xoá lý do từ chối cũ: câu đó hiện thẳng cho khách', function () {
    $this->item->update([
        'status' => ChecklistItemStatus::Rejected,
        'rejection_reason' => 'Ảnh bị mờ ở góc trên nên không đọc được số thửa.',
    ]);

    // Một đầu mục đã "không cần nộp" mà còn treo câu "ảnh bị mờ" là một dòng nói dối, và người
    // đọc nó là khách hàng (SPEC §6.7, §8.3 mục 4).
    expect(markNotApplicable($this->item, $this->lawyer)->rejection_reason)->toBeNull();
});

it('không đánh dấu được một đầu mục đang có tệp chờ duyệt', function () {
    $this->item->update(['status' => ChecklistItemStatus::PendingReview]);

    expect(fn () => markNotApplicable($this->item, $this->lawyer))
        ->toThrow(fn (ChecklistItemNotReviewable $exception) => expect($exception->getMessage())
            ->toBe(__('checklist.not_applicable.awaiting_review')));

    expect($this->item->fresh()->status)->toBe(ChecklistItemStatus::PendingReview);
});

it('đánh dấu được từ những trạng thái không có gì đang chờ — cặp dương', function (ChecklistItemStatus $status) {
    $this->item->update(['status' => $status]);

    expect(markNotApplicable($this->item, $this->lawyer)->status)
        ->toBe(ChecklistItemStatus::NotApplicable);
})->with([
    'missing' => [ChecklistItemStatus::Missing],
    'rejected' => [ChecklistItemStatus::Rejected],
    'accepted' => [ChecklistItemStatus::Accepted],
    'not_applicable' => [ChecklistItemStatus::NotApplicable],
]);

// ---------------------------------------------------------------------------------------------
// Quyền: cùng cổng `checklist.review` với `ReviewChecklistItem` — xem docblock Action.
// ---------------------------------------------------------------------------------------------

it('người thấy và sửa được hồ sơ nhưng không có checklist.review thì không đánh dấu được', function () {
    // Không vai trò nào ở SPEC §5 có `matter.view` mà thiếu `checklist.review`, nên nhân chứng
    // phải dựng bằng cách cấp quyền thẳng cho một tài khoản — nếu không, test sẽ xanh vì một lý
    // do KHÁC (không thấy hồ sơ) và cái cổng `checklist.review` không ai kiểm.
    $actor = User::factory()->create();
    $actor->givePermissionTo([Permission::MatterView->value, Permission::MatterUpdate->value]);
    $this->matter->addTeamMember($actor, MatterRole::Assistant);

    expectMarkRefusal(fn () => markNotApplicable($this->item, $actor));

    expect($this->item->fresh()->status)->toBe(ChecklistItemStatus::Missing);
});

it('cùng người đó có thêm checklist.review thì đánh dấu được — cặp dương', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo([
        Permission::MatterView->value,
        Permission::MatterUpdate->value,
        Permission::ChecklistReview->value,
    ]);
    $this->matter->addTeamMember($actor, MatterRole::Assistant);

    expect(markNotApplicable($this->item, $actor)->status)->toBe(ChecklistItemStatus::NotApplicable);
});

it('luật sư ngoài đội ngũ không đánh dấu được', function () {
    expectMarkRefusal(fn () => markNotApplicable($this->item, User::factory()->withRole(Role::Lawyer)->create()));
});

it('tài khoản nhân sự đã bị vô hiệu hoá thì không đánh dấu được', function () {
    $this->lawyer->update(['is_active' => false]);

    expectMarkRefusal(fn () => markNotApplicable($this->item, $this->lawyer->fresh()));
});

it('ba tình huống "không mở được mục này" trả lời giống hệt nhau — SPEC §10.10', function (string $situation) {
    $actor = $this->lawyer;

    match ($situation) {
        'đã xoá khỏi danh mục' => $this->item->delete(),
        'không còn tồn tại' => $this->item->forceDelete(),
        'của hồ sơ người khác' => $actor = User::factory()->withRole(Role::Lawyer)->create(),
    };

    expectMarkRefusal(fn () => markNotApplicable($this->item, $actor));
})->with(['đã xoá khỏi danh mục', 'không còn tồn tại', 'của hồ sơ người khác']);

it('không đánh dấu được đầu mục của một hồ sơ đã xoá mềm', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->matter->delete();

    expect(fn () => markNotApplicable($this->item->fresh(), $admin))
        ->toThrow(fn (ChecklistItemNotReviewable $exception) => expect($exception->getMessage())
            ->toBe(__('checklist.review.matter_unavailable')));
});

/**
 * M7 Task 3 — cùng cổng chung ở `OpensChecklistItem` mà `ReviewChecklistItemTest` đã đo (xem test
 * cùng tên ở đó cho docblock đầy đủ). `$this->item` ở `Missing` (không `pending_review`), nên lời
 * gọi chạm tới đúng cổng "vụ đã đóng" thay vì bị chặn sớm hơn ở `refuseWhileAwaitingReview()`.
 */
it('không đánh dấu được đầu mục của một vụ đã đóng', function () {
    $this->matter->update(['closed_at' => now()->subDay()]);

    expect(fn () => markNotApplicable($this->item, $this->lawyer))
        ->toThrow(MatterChecklistReadOnly::class);

    expect($this->item->fresh()->status)->toBe(ChecklistItemStatus::Missing);
});

it('tin dòng dữ liệu thật chứ không tin đối tượng caller cầm trong tay', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $otherMatter = Matter::factory()->create(['lead_lawyer_id' => $outsider->id]);

    $tampered = $this->item->replicate();
    $tampered->id = $this->item->id;
    $tampered->exists = true;
    $tampered->matter_id = $otherMatter->id;

    expectMarkRefusal(fn () => markNotApplicable($tampered, $outsider));

    expect($this->item->fresh()->status)->toBe(ChecklistItemStatus::Missing);
});

// ---------------------------------------------------------------------------------------------
// Dấu vết. `matter_checklist_items` chưa dùng `LogsActivity`, nên dòng này là dấu vết DUY NHẤT.
// ---------------------------------------------------------------------------------------------

it('ghi dòng nhật ký với actor tường minh và trạng thái trước đó', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($admin, 'web');

    $this->item->update(['status' => ChecklistItemStatus::Rejected]);

    // Đối tượng caller đưa vào KHAI một trạng thái khác với dòng dữ liệu thật. `previous_status`
    // phải đọc từ bản ghi đã đọc lại, không từ đối tượng trong bộ nhớ — một dòng nhật ký lấy giá
    // trị từ thứ người gọi cầm trong tay là một dòng nhật ký người gọi viết được.
    $this->item->status = ChecklistItemStatus::Accepted;

    $item = markNotApplicable($this->item, $this->lawyer);

    $activity = Activity::query()->where('event', 'checklist_item_marked_not_applicable')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer?->is($this->lawyer))->toBeTrue()
        ->and($activity->subject?->is($item))->toBeTrue()
        ->and($activity->properties->get('matter_id'))->toBe($this->matter->id)
        // `matters.client_id` là một cột sửa được, nên "đầu mục này của khách nào" suy qua hồ sơ
        // không ổn định qua thời gian — cùng lý lẽ với `PublishDocument`.
        ->and($activity->properties->get('client_id'))->toBe($this->client->id)
        // Trạng thái cũ không đọc lại được từ đâu khác sau khi cột đã bị ghi đè.
        ->and($activity->properties->get('previous_status'))->toBe(ChecklistItemStatus::Rejected->value);
});

it('không ghi dòng nhật ký nào khi lời gọi bị từ chối', function () {
    $this->item->update(['status' => ChecklistItemStatus::PendingReview]);

    expect(fn () => markNotApplicable($this->item, $this->lawyer))
        ->toThrow(ChecklistItemNotReviewable::class);

    expect(Activity::query()->where('event', 'checklist_item_marked_not_applicable')->count())->toBe(0);
});
