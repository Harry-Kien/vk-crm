<?php

use App\Actions\Deadline\SetDeadlineCompletion;
use App\Enums\Role;
use App\Exceptions\MatterClosedForDeadlines;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\DeadlinesRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\StageLogDraft;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

/*
 * Làn fi, B4–B5 — hai thứ còn sót trên vụ đã kết thúc:
 *  B4. Nút "Mở lại" một mốc hạn đã xong không hiện trên vụ đã đóng, và `SetDeadlineCompletion` từ chối mở
 *      lại — mốc mở trên vụ đóng là mốc không bao giờ được nhắc (nhắc hạn chỉ chạy cho vụ đang mở).
 *  B5. Thẻ nháp AI trên tab Tiến độ không hiện "Dự kiến có tin tiếp theo" khi vụ đứng ở giai đoạn kết thúc
 *      — form "Thêm cập nhật" đã ẩn ô đó và không gửi nó.
 * Hàm toàn cục tiền tố `cml…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
});

function cmlMatter(bool $closed): Matter
{
    return $closed
        ? Matter::factory()->atStage('closed')->create(['lead_lawyer_id' => test()->lawyer->id, 'closed_at' => now()->subDay()->toDateString()])
        : Matter::factory()->atStage('intake')->create(['lead_lawyer_id' => test()->lawyer->id, 'closed_at' => null]);
}

function cmlCompletedDeadline(Matter $matter): Deadline
{
    return Deadline::factory()->for($matter)->create([
        'responsible_user_id' => test()->lawyer->id,
        'is_completed' => true,
        'completed_at' => now()->subDays(2),
    ]);
}

function cmlDeadlinesTab(Matter $matter)
{
    test()->actingAs(test()->lawyer, 'web');

    return test()->livewire(DeadlinesRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class]);
}

it('hides "Mở lại" on a completed deadline of a closed matter, and the action refuses it', function () {
    $matter = cmlMatter(closed: true);
    $deadline = cmlCompletedDeadline($matter);

    cmlDeadlinesTab($matter)->assertTableActionHidden('reopen', $deadline);

    // Vòng sửa 1 (I5): cùng MỘT luật của làn fm A1 (`MatterClosedForDeadlines`), câu cùng lối ra — chỉ
    // quản trị viên mở lại được vụ đã kết thúc (`TransitionMatterStage`), nên câu không bảo luật sư tự chuyển vụ.
    expect(fn () => app(SetDeadlineCompletion::class)->handle($deadline, false, $this->lawyer))
        ->toThrow(MatterClosedForDeadlines::class, __('lifecycle.deadlines.reopen_refused'));

    expect(__('lifecycle.deadlines.reopen_refused'))->toContain('Nhờ quản trị viên mở lại vụ qua "Chuyển giai đoạn"')
        ->and($deadline->fresh()->is_completed)->toBeTrue();
});

it('still reopens a completed deadline of an open matter', function () {
    $matter = cmlMatter(closed: false);
    $deadline = cmlCompletedDeadline($matter);

    cmlDeadlinesTab($matter)
        ->assertTableActionVisible('reopen', $deadline)
        ->callTableAction('reopen', $deadline)
        ->assertHasNoTableActionErrors();

    expect($deadline->fresh()->is_completed)->toBeFalse();
});

it('still lets a deadline of a closed matter be marked done', function () {
    $matter = cmlMatter(closed: true);
    $deadline = Deadline::factory()->for($matter)->create(['responsible_user_id' => $this->lawyer->id, 'is_completed' => false]);

    cmlDeadlinesTab($matter)->callTableAction('complete', $deadline)->assertHasNoTableActionErrors();

    expect($deadline->fresh()->is_completed)->toBeTrue();
});

it('leaves the expected next update out of an AI draft card on a closed matter, and keeps it on an open one', function (bool $closed) {
    $matter = cmlMatter($closed);
    StageLogDraft::factory()->create([
        'matter_id' => $matter->id,
        'created_by' => $this->lawyer->id,
        'public_content' => 'Văn phòng đã nhận bản án.',
        'expected_next_update_at' => now()->addDays(9)->toDateString(),
    ]);

    $this->actingAs($this->lawyer, 'web');

    $page = $this->livewire(StageLogsRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
        ->assertSee('Văn phòng đã nhận bản án.');

    $closed
        ? $page->assertDontSee(now()->addDays(9)->format('d/m/Y'))
        : $page->assertSee(now()->addDays(9)->format('d/m/Y'));
})->with(['closed matter' => true, 'open matter' => false]);
