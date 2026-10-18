<?php

use App\Actions\Document\ReturnDocumentToDraft;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Jobs\SendDocumentReviewNotice;
use App\Mail\Staff\DocumentReviewRequest;
use App\Models\Document;
use App\Models\Matter;
use App\Models\User;
use App\Notifications\Staff\DocumentReviewAlert;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Làn fa2, phán quyết 7 — vòng trình duyệt văn bản nội bộ (nhóm B): "Trình duyệt" báo người duyệt (luật sư phụ trách;
 * không có thì người có quyền duyệt) bằng chuông + thư; "Đánh dấu đã ký, đã nộp" báo người trình; "Trả về bản nháp"
 * bắt buộc lý do, và người trình nhận chuông kèm lý do. Mọi đường qua tab "Tài liệu" (Livewire).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Mail::fake();

    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'VK-2026-DS-0404']);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
    $this->document = Document::factory()->create([
        'matter_id' => $this->matter->id,
        'group' => DocumentGroup::Issued,
        'status' => DocumentStatus::InternalDraft,
        'title' => 'Dự thảo đơn khởi kiện',
        'client_can_view' => false,
        'client_can_download' => false,
    ]);
});

function fa2DocumentsTab(Matter $matter)
{
    return test()->livewire(DocumentsRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class]);
}

function fa2ReviewBells(User $user): array
{
    return $user->notifications()->where('type', DocumentReviewAlert::class)->get()->pluck('data')->all();
}

function fa2Submit(): void
{
    test()->actingAs(test()->assistant, 'web');

    fa2DocumentsTab(test()->matter)->callTableAction('submitForApproval', test()->document)->assertHasNoTableActionErrors();

    expect(test()->document->fresh()->status)->toBe(DocumentStatus::PendingApproval);
}

it('tells the lead lawyer by bell and mail when a draft is submitted for approval', function () {
    fa2Submit();

    $bells = fa2ReviewBells($this->lead);

    expect($bells)->toHaveCount(1)
        ->and($bells[0]['title'])->toBe(__('internal_flow.document_review.submitted.title'))
        ->and($bells[0]['body'])->toContain('Dự thảo đơn khởi kiện')->toContain('VK-2026-DS-0404')->toContain('Trợ lý Lan')
        ->and(fa2ReviewBells($this->assistant))->toBe([]);

    Mail::assertSent(DocumentReviewRequest::class, fn (DocumentReviewRequest $mail): bool => $mail->hasTo($this->lead->email));
    Mail::assertSent(DocumentReviewRequest::class, 1);
});

it('falls back to a manager who can approve when the lead lawyer cannot', function () {
    $manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Hà']);
    $this->lead->forceFill(['is_active' => false])->save();

    fa2Submit();

    expect(fa2ReviewBells($this->lead))->toBe([])
        ->and(fa2ReviewBells($manager))->toHaveCount(1);

    Mail::assertSent(DocumentReviewRequest::class, fn (DocumentReviewRequest $mail): bool => $mail->hasTo($manager->email));
});

it('tells the submitter when the lawyer marks the document signed and filed', function () {
    fa2Submit();

    $this->actingAs($this->lead, 'web');

    fa2DocumentsTab($this->matter)->callTableAction('markSignedFiled', $this->document)->assertHasNoTableActionErrors();

    $bells = fa2ReviewBells($this->assistant);

    expect($bells)->toHaveCount(1)
        ->and($bells[0]['title'])->toBe(__('internal_flow.document_review.approved.title'))
        ->and($bells[0]['body'])->toContain('Dự thảo đơn khởi kiện')->toContain('Luật sư Khang');
});

it('requires a reason to return a draft, records it, and tells the submitter with the reason', function () {
    fa2Submit();

    $this->actingAs($this->lead, 'web');

    fa2DocumentsTab($this->matter)
        ->callTableAction('returnToDraft', $this->document, data: ['reason' => ''])
        ->assertHasTableActionErrors(['reason' => 'required']);

    expect($this->document->fresh()->status)->toBe(DocumentStatus::PendingApproval);

    fa2DocumentsTab($this->matter)
        ->callTableAction('returnToDraft', $this->document, data: ['reason' => 'Sửa lại phần yêu cầu khởi kiện cho đúng số tiền.'])
        ->assertHasNoTableActionErrors();

    expect($this->document->fresh()->status)->toBe(DocumentStatus::InternalDraft)
        ->and(Activity::query()->where('event', 'document_returned_to_draft')->sole()->getExtraProperty('reason'))
        ->toBe('Sửa lại phần yêu cầu khởi kiện cho đúng số tiền.');

    $bells = fa2ReviewBells($this->assistant);

    expect($bells)->toHaveCount(1)
        ->and($bells[0]['title'])->toBe(__('internal_flow.document_review.returned.title'))
        ->and($bells[0]['body'])->toContain('Sửa lại phần yêu cầu khởi kiện cho đúng số tiền.');
});

it('does not tell the lawyer about their own submission, nor the submitter about their own decision', function () {
    $this->actingAs($this->lead, 'web');

    fa2DocumentsTab($this->matter)->callTableAction('submitForApproval', $this->document)->assertHasNoTableActionErrors();
    fa2DocumentsTab($this->matter)->callTableAction('markSignedFiled', $this->document)->assertHasNoTableActionErrors();

    expect(fa2ReviewBells($this->lead))->toBe([]);
    Mail::assertNotSent(DocumentReviewRequest::class);
});

it('skips a lead lawyer who cannot approve documents and asks someone who can', function () {
    $manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Hà']);
    SpatieRole::findByName(Role::Lawyer->value, 'web')->revokePermissionTo(Permission::DocumentPublish->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    fa2Submit();

    expect(fa2ReviewBells($this->lead))->toBe([])
        ->and(fa2ReviewBells($manager))->toHaveCount(1);
});

it('refuses a blank reason in the action itself, not only on the form', function () {
    $this->document->forceFill(['status' => DocumentStatus::PendingApproval])->save();

    expect(fn () => app(ReturnDocumentToDraft::class)->handle($this->document, $this->lead, '   '))
        ->toThrow(ValidationException::class);

    expect($this->document->fresh()->status)->toBe(DocumentStatus::PendingApproval);
});

it('does not tell a submitter who has since left the matter team', function () {
    fa2Submit();

    $this->matter->team()->detach($this->assistant->id);

    $this->actingAs($this->lead, 'web');

    fa2DocumentsTab($this->matter)
        ->callTableAction('returnToDraft', $this->document, data: ['reason' => 'Thiếu chữ ký của khách.'])
        ->assertHasNoTableActionErrors();

    expect(fa2ReviewBells($this->assistant))->toBe([]);
});

it('drops a stale notice once the document has moved on', function () {
    Queue::fake();

    fa2Submit();

    $job = Queue::pushed(SendDocumentReviewNotice::class)->sole();

    $this->document->fresh()->forceFill(['status' => DocumentStatus::InternalDraft])->save();

    $job->handle(app(ResolveStaffRecipients::class));

    expect(fa2ReviewBells($this->lead))->toBe([]);
    Mail::assertNotSent(DocumentReviewRequest::class);
});

// Làn fa2, Task 1, vòng sửa 1 (minor 7): người trình bị bỏ TRƯỚC khi dựng chuỗi dự phòng — trưởng phòng duy nhất tự
// trình khi luật sư phụ trách không duyệt được thì quản trị viên (duyệt được) vẫn nhận lời báo.
it('still reaches an admin when the only manager on the fallback chain is the one who submitted', function () {
    $manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Hà']);
    $admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản trị Viên']);
    $this->lead->forceFill(['is_active' => false])->save();

    $this->actingAs($manager, 'web');

    fa2DocumentsTab($this->matter)->callTableAction('submitForApproval', $this->document)->assertHasNoTableActionErrors();

    expect(fa2ReviewBells($manager))->toBe([])
        ->and(fa2ReviewBells($admin))->toHaveCount(1);

    Mail::assertSent(DocumentReviewRequest::class, fn (DocumentReviewRequest $mail): bool => $mail->hasTo($admin->email));
});
