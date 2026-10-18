<?php

use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Pages\BulkReassign;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Models\Matter;
use App\Models\User;
use App\Notifications\Staff\LeadChangeAlert;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;

/**
 * Làn fa2, phán quyết 5 — đổi luật sư phụ trách: người phụ trách cũ (còn hoạt động, không phải người bấm)
 * nhận chuông "hồ sơ … đã được giao cho X"; thành viên còn lại của đội nhận một chuông "đổi luật sư phụ
 * trách". Người không còn xem được hồ sơ thì câu chung không mã. Bàn giao hàng loạt: một chuông mỗi người.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Mail::fake();

    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Hà']);
    $this->oldLead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Cũ']);
    $this->newLead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Mới']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->oldLead->id, 'code' => 'VK-2026-DS-0101', 'title' => 'DAUVET-TIEUDE']);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
});

function fa2LeadBells(User $user): array
{
    return $user->notifications()->where('type', LeadChangeAlert::class)->get()->pluck('data')->all();
}

function fa2Reassign(User $actor, Matter $matter, User $to, bool $keep): void
{
    test()->actingAs($actor, 'web');

    test()->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->callAction('reassignMatter', data: [
            'new_lead_id' => $to->id,
            'keep_old_lead_as_associate' => $keep,
            'reason' => 'Luật sư cũ nghỉ phép dài ngày.',
        ])
        ->assertHasNoActionErrors();
}

it('tells the old lead which matter now belongs to whom, that they left its team, and tells the team', function () {
    fa2Reassign($this->manager, $this->matter, $this->newLead, keep: false);

    $old = fa2LeadBells($this->oldLead);
    $matterUrl = ViewMatter::getUrl(['record' => $this->matter->getKey()], panel: 'admin');

    // Vụ thường: người vừa phụ trách đã biết mã — chuông nói đúng hồ sơ nào; họ không mở được nữa nên nút "Mở" không
    // trỏ tới hồ sơ (sẽ 404), và câu nói việc nội bộ của họ cũng đã chuyển. Không bao giờ có tiêu đề hồ sơ.
    expect($old)->toHaveCount(1)
        ->and($old[0]['body'])->toContain('Luật sư Mới')->toContain('VK-2026-DS-0101')->toContain('rời đội ngũ')
        ->and($old[0]['actions'][0]['url'])->not->toBe($matterUrl)
        ->and(json_encode($old[0], JSON_UNESCAPED_UNICODE))->not->toContain('DAUVET');

    $team = fa2LeadBells($this->assistant);

    expect($team)->toHaveCount(1)
        ->and($team[0]['body'])->toContain('VK-2026-DS-0101')->toContain('Luật sư Cũ')->toContain('Luật sư Mới')
        ->and(fa2LeadBells($this->newLead))->toBe([])
        ->and(fa2LeadBells($this->manager))->toBe([]);
});

it('names the matter to an old lead kept on the team as associate', function () {
    fa2Reassign($this->manager, $this->matter, $this->newLead, keep: true);

    $old = fa2LeadBells($this->oldLead);

    expect($old)->toHaveCount(1)
        ->and($old[0]['body'])->toContain('VK-2026-DS-0101')->toContain('Luật sư Mới')->not->toContain('rời đội ngũ')
        ->and($old[0]['actions'][0]['url'])->toBe(ViewMatter::getUrl(['record' => $this->matter->getKey()], panel: 'admin'));
});

it('names the ordinary matters of a bulk handover to the old lead but only counts a restricted one', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $secret = Matter::factory()->create(['lead_lawyer_id' => $this->oldLead->id, 'code' => 'VK-2026-HC-0777', 'confidentiality' => Confidentiality::Restricted]);

    $this->actingAs($admin, 'web');

    $this->livewire(BulkReassign::class)
        ->set('data.lead_lawyer_id', $this->oldLead->id)
        ->set('data.matter_ids', [$this->matter->id, $secret->id])
        ->set('data.new_lead_id', $this->newLead->id)
        ->set('data.reason', 'Nghỉ việc, bàn giao cả lô.')
        ->set('data.keep_old_lead_as_associate', false)
        ->call('reassignSelected')
        ->assertHasNoErrors();

    $old = fa2LeadBells($this->oldLead);

    expect($old)->toHaveCount(1)
        ->and($old[0]['body'])->toContain('VK-2026-DS-0101')->toContain(__('internal_flow.lead_change.and_others', ['count' => 1]))
        ->and(json_encode($old[0], JSON_UNESCAPED_UNICODE))->not->toContain('VK-2026-HC-0777');
});

it('does not tell the old lead who handed the matter over themselves', function () {
    fa2Reassign($this->oldLead, $this->matter, $this->newLead, keep: true);

    expect(fa2LeadBells($this->oldLead))->toBe([])
        ->and(fa2LeadBells($this->assistant))->toHaveCount(1);
});

it('leaks nothing of a restricted matter to team members who cannot open it', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->matter->forceFill(['confidentiality' => Confidentiality::Restricted])->save();

    fa2Reassign($admin, $this->matter, $this->newLead, keep: false);

    expect(fa2LeadBells($this->assistant))->toBe([]);

    $old = fa2LeadBells($this->oldLead);

    expect($old)->toHaveCount(1)
        ->and(json_encode($old[0], JSON_UNESCAPED_UNICODE))->not->toContain('VK-2026-DS-0101');
});

it('sends one bell per person for a bulk handover of several matters', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $second = Matter::factory()->create(['lead_lawyer_id' => $this->oldLead->id, 'code' => 'VK-2026-DS-0102']);
    $second->addTeamMember($this->assistant, MatterRole::Assistant);

    $this->actingAs($admin, 'web');

    $this->livewire(BulkReassign::class)
        ->set('data.lead_lawyer_id', $this->oldLead->id)
        ->set('data.matter_ids', [$this->matter->id, $second->id])
        ->set('data.new_lead_id', $this->newLead->id)
        ->set('data.reason', 'Nghỉ việc, bàn giao cả lô.')
        ->set('data.keep_old_lead_as_associate', false)
        ->call('reassignSelected')
        ->assertHasNoErrors();

    $old = fa2LeadBells($this->oldLead);
    $team = fa2LeadBells($this->assistant);

    expect($old)->toHaveCount(1)
        ->and($old[0]['body'])->toContain('2')->toContain('Luật sư Mới')
        ->and($team)->toHaveCount(1)
        ->and($team[0]['body'])->toContain('VK-2026-DS-0101')->toContain('VK-2026-DS-0102');
});
