<?php

use App\Actions\Matter\RetractStageLog;
use App\Actions\Notification\NotifyClientOfStageUpdate;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Exceptions\StageLogNotRetractable;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Mail\Client\StageUpdate;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

/*
 * Làn fm, mục A2 (kiểm tra nghiệp vụ 2026-10-09): một dòng tiến độ đã công bố (ví dụ đăng nhầm sang vụ
 * của khách khác) rút được khỏi cổng mà không xoá: dòng ở lại trong sổ, mang người rút, lúc rút và lý
 * do (nội bộ), không còn hiện cho khách, không vào mục lục gói bàn giao, không được gửi thư lại.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

function publishedLine(Matter $matter, User $author): StageLog
{
    return StageLog::factory()->for($matter)->create([
        'from_stage' => $matter->stage,
        'to_stage' => $matter->stage,
        'public_content' => 'Văn phòng đã nộp đơn và chờ toà thụ lý, số tiền tạm ứng 12.000.000 đồng.',
        'is_published' => true,
        'published_at' => now()->subHour(),
        'created_by' => $author->id,
    ]);
}

const RETRACT_REASON = 'Đăng nhầm cập nhật của một vụ khác sang vụ này.';

it('retracts a published progress line through the timeline, keeping the row and logging who and why', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'is_published_to_portal' => true]);
    $line = publishedLine($matter, $lawyer);

    $this->actingAs($lawyer, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->assertTableActionVisible('retractStageLog', $line)
        ->callTableAction('retractStageLog', $line, data: ['retraction_reason' => RETRACT_REASON])
        ->assertHasNoTableActionErrors();

    $fresh = StageLog::query()->withoutGlobalScope(ClientPortalScope::class)->find($line->id);

    expect($fresh)->not->toBeNull()
        ->and($fresh->is_published)->toBeFalse()
        ->and($fresh->retracted_at)->not->toBeNull()
        ->and($fresh->retracted_by)->toBe($lawyer->id)
        ->and($fresh->retraction_reason)->toBe(RETRACT_REASON)
        // Nội dung gốc không bị sửa: sổ tiến độ chỉ ghi thêm.
        ->and($fresh->public_content)->toBe($line->public_content);

    $activity = Activity::query()->where('event', 'stage_log_retracted')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->subject_id)->toBe($line->id)
        ->and($activity->causer_id)->toBe($lawyer->id)
        ->and($activity->properties['matter_id'])->toBe($matter->id)
        // Không chép nội dung công bố vào nhật ký.
        ->and(json_encode($activity->properties->all(), JSON_UNESCAPED_UNICODE))->not->toContain('12.000.000');
});

it('takes the retracted line off the client portal and never mails it', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'is_published_to_portal' => true]);
    $clientUser = ClientUser::factory()->activated()->create(['client_id' => $matter->client_id]);
    $line = publishedLine($matter, $lawyer);

    $portalQuery = fn () => tap(StageLog::query()->withoutGlobalScope(ClientPortalScope::class), fn ($q) => (new StageLog)->applyClientPortalConstraints($q, $clientUser));

    expect($portalQuery()->whereKey($line->id)->exists())->toBeTrue();

    app(RetractStageLog::class)->handle($line, $lawyer, RETRACT_REASON);

    expect($portalQuery()->whereKey($line->id)->exists())->toBeFalse()
        ->and(app(NotifyClientOfStageUpdate::class)->handle($line->fresh()))->toBe(0);

    Mail::assertNothingSent();
    Mail::assertNotQueued(StageUpdate::class);
});

it('hides the retract button from an assistant, who cannot publish to clients', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'is_published_to_portal' => true]);
    $matter->addTeamMember($assistant, MatterRole::Assistant);
    $line = publishedLine($matter, $lawyer);

    $this->actingAs($assistant, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->assertTableActionHidden('retractStageLog', $line);

    expect(fn () => app(RetractStageLog::class)->handle($line, $assistant, RETRACT_REASON))
        ->toThrow(AuthorizationException::class);
});

it('offers no retract button on an internal line or one already retracted, and the action refuses both', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'is_published_to_portal' => true]);
    $internal = StageLog::factory()->for($matter)->create(['is_published' => false]);
    $retracted = publishedLine($matter, $lawyer);
    app(RetractStageLog::class)->handle($retracted, $lawyer, RETRACT_REASON);

    $this->actingAs($lawyer, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->assertTableActionHidden('retractStageLog', $internal)
        ->assertTableActionHidden('retractStageLog', $retracted->fresh());

    // Hai câu khác nhau: "chưa công bố" và "đã rút trước đó" — người bấm biết vì sao.
    expect(fn () => app(RetractStageLog::class)->handle($internal, $lawyer, RETRACT_REASON))
        ->toThrow(StageLogNotRetractable::class, __('lifecycle.stage_log.not_published'))
        ->and(fn () => app(RetractStageLog::class)->handle($retracted, $lawyer, RETRACT_REASON))
        ->toThrow(StageLogNotRetractable::class, __('lifecycle.stage_log.already_retracted'));
});

it('asks for a real reason on the retract dialog', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'is_published_to_portal' => true]);
    $line = publishedLine($matter, $lawyer);

    $this->actingAs($lawyer, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('retractStageLog', $line, data: ['retraction_reason' => 'Nhầm'])
        ->assertHasTableActionErrors(['retraction_reason']);

    expect($line->fresh()->is_published)->toBeTrue();
});

it('marks a retracted line on the staff timeline with when and why', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Rút Dòng']);
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'is_published_to_portal' => true]);
    $line = publishedLine($matter, $lawyer);
    app(RetractStageLog::class)->handle($line, $lawyer, RETRACT_REASON);

    $html = StageLogsRelationManager::renderPublicContent($line->fresh()->public_content, $line->fresh())->toHtml();

    expect($html)->toContain(e(RETRACT_REASON))
        ->toContain(e(__('lifecycle.stage_log.retracted_marker', [
            'date' => $line->fresh()->retracted_at->format('H:i d/m/Y'),
            'by' => 'Luật sư Rút Dòng',
        ])));
});
