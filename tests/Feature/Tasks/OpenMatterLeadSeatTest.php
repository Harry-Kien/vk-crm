<?php

use App\Actions\Intake\RecordIntake;
use App\Actions\Intake\RecordPrivacyNotice;
use App\Actions\Intake\UpdateIntakeSummary;
use App\Enums\IntakeSource;
use App\Enums\MatterAssignmentStatus;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\IntakeRequests\Pages\ConvertIntakeRequest;
use App\Filament\Admin\Resources\Matters\Pages\CreateMatter;
use App\Mail\Staff\MatterAssignmentNotice;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\MatterUser;
use App\Models\User;
use App\Notifications\Staff\MatterAssignmentAlert;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;

/**
 * Làn fa2, phán quyết 4 — mở vụ (màn hình "Tạo vụ việc") hay chuyển lead thành vụ mà đặt NGƯỜI KHÁC làm luật
 * sư phụ trách: ghế của người đó CHỜ NHẬN (không tự coi là đã nhận), và họ nhận chuông + thư như khi được bàn
 * giao. Người tạo tự làm phụ trách thì ghế đã nhận, không chuông, không thư — như trước.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Mail::fake();

    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Hà']);
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Minh']);
    $this->client = Client::factory()->create();
    // Khách phải nằm trong tầm nhìn của luật sư tự mở vụ (`VisibleClientOptions`).
    Matter::factory()->create(['client_id' => $this->client->id, 'lead_lawyer_id' => $this->lawyer->id]);
    $this->type = MatterType::factory()->withStages()->create(['is_active' => true, 'name' => 'Dân sự']);
});

function fa2CreateMatter(User $actor, User $lead, string $title): Matter
{
    test()->actingAs($actor, 'web');

    test()->livewire(CreateMatter::class)
        ->fillForm([
            'client_id' => test()->client->id,
            'client_role' => PartyRole::Plaintiff->value,
            'matter_type_id' => test()->type->id,
            'title' => $title,
            'lead_lawyer_id' => $lead->id,
            'summary_for_client' => 'Tóm tắt gửi khách hàng.',
            'other_parties' => [],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    return Matter::query()->where('title', $title)->sole();
}

function fa2LeadSeat(Matter $matter): MatterUser
{
    return MatterUser::query()->where('matter_id', $matter->id)->where('user_id', $matter->lead_lawyer_id)->sole();
}

it('makes the lead seat wait for a colleague named lead on a new matter, and tells them by bell and mail', function () {
    $matter = fa2CreateMatter($this->manager, $this->lawyer, 'Tranh chấp hợp đồng thuê nhà');

    $seat = fa2LeadSeat($matter);

    expect($seat->assignmentStatus())->toBe(MatterAssignmentStatus::Pending)
        ->and($seat->assigned_by)->toBe($this->manager->id);

    $bell = $this->lawyer->notifications()->where('type', MatterAssignmentAlert::class)->sole();

    expect($bell->data['title'])->toBe(__('tasks.seat.alert.assigned.title', ['code' => $matter->code]))
        ->and($bell->data['body'])->toContain('Trưởng phòng Hà');

    Mail::assertSent(MatterAssignmentNotice::class, fn (MatterAssignmentNotice $mail): bool => $mail->hasTo($this->lawyer->email)
        && $mail->kind === MatterAssignmentNotice::KIND_ASSIGNED);
    Mail::assertSent(MatterAssignmentNotice::class, 1);

    expect($this->manager->notifications()->count())->toBe(0);
});

it('keeps the lead seat accepted, with no bell and no mail, when the creator leads the matter', function () {
    $matter = fa2CreateMatter($this->lawyer, $this->lawyer, 'Ly hôn thuận tình');

    expect(fa2LeadSeat($matter)->assignmentStatus())->toBe(MatterAssignmentStatus::Accepted)
        ->and($this->lawyer->notifications()->count())->toBe(0);

    Mail::assertNotSent(MatterAssignmentNotice::class);
});

it('does the same when a lead is converted into a matter with a colleague as lead', function () {
    $intake = app(RecordIntake::class)->handle($this->manager, [
        'contact_name' => 'Trần Thị Mới',
        'contact_phone' => '0832 270 898',
        'contact_email' => 'moi@example.test',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
        'matter_type_id' => $this->type->id,
        'quoted_amount' => '15.000.000',
    ], [
        ['name' => 'Công Ty Bên Kia', 'role' => PartyRole::Defendant, 'phone' => '0977 000 222', 'id_number' => '079088000111'],
    ])->intake;
    app(RecordPrivacyNotice::class)->handle($this->manager, $intake, true);
    app(UpdateIntakeSummary::class)->handle($this->manager, $intake->fresh(), 'Hợp đồng mua bán bị bên kia vi phạm thời hạn giao hàng.');

    $this->actingAs($this->manager, 'web');

    $this->livewire(ConvertIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->fillForm(['lead_lawyer_id' => $this->lawyer->id])
        ->call('convert')
        ->assertHasNoFormErrors();

    $matter = Matter::query()->whereKey($intake->fresh()->matter_id)->sole();

    expect($matter->lead_lawyer_id)->toBe($this->lawyer->id)
        ->and(fa2LeadSeat($matter)->assignmentStatus())->toBe(MatterAssignmentStatus::Pending)
        ->and($this->lawyer->notifications()->where('type', MatterAssignmentAlert::class)->count())->toBe(1);

    Mail::assertSent(MatterAssignmentNotice::class, fn (MatterAssignmentNotice $mail): bool => $mail->hasTo($this->lawyer->email)
        && $mail->kind === MatterAssignmentNotice::KIND_ASSIGNED);
});
