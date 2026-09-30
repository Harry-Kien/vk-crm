<?php

use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\HandoverPackageStatus;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Jobs\SendHandoverPackageReady;
use App\Mail\Staff\HandoverPackageReady;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\OutboundMessage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * `SendHandoverPackageReady` (M7 Task 4): báo luật sư phụ trách gói bàn giao đã sinh xong — thông
 * báo trong hệ thống và thư XẾP HÀNG cho mỗi người nhận, người nhận qua `ResolveStaffRecipients`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Hương']);
    $this->matter = Matter::factory()->create([
        'lead_lawyer_id' => $this->lawyer->id,
        'title' => 'Tranh chấp thừa kế',
        'closed_at' => now()->subDay()->toDateString(),
    ]);
    $this->package = Document::factory()->create(['matter_id' => $this->matter->id]);
    $this->archive = MatterArchive::factory()->create([
        'matter_id' => $this->matter->id,
        'handover_document_id' => $this->package->id,
        'handover_status' => HandoverPackageStatus::Ready,
    ]);
});

function shprRun(object $test, ?int $documentId = null): void
{
    (new SendHandoverPackageReady($test->matter->id, $documentId ?? $test->package->id))
        ->handle(app(ResolveStaffRecipients::class));
}

function shprNotifications(User $user): int
{
    return DB::table('notifications')->where('notifiable_id', $user->id)->count();
}

it('báo luật sư phụ trách bằng thông báo trong hệ thống và MỘT thư xếp hàng, không gửi đồng bộ', function () {
    shprRun($this);

    expect(shprNotifications($this->lawyer))->toBe(1);

    Mail::assertQueued(HandoverPackageReady::class, 1);
    Mail::assertQueued(HandoverPackageReady::class, fn (HandoverPackageReady $mail): bool => $mail->hasTo($this->lawyer->email));
    Mail::assertNothingSent();
});

it('người bấm và luật sư phụ trách đều được báo; cùng một người thì chỉ một lần', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $this->archive->update(['handover_requested_by' => $manager->id]);

    shprRun($this);

    expect(shprNotifications($this->lawyer))->toBe(1)
        ->and(shprNotifications($manager))->toBe(1);

    Mail::assertQueued(HandoverPackageReady::class, 2);

    Mail::fake();
    $this->archive->update(['handover_requested_by' => $this->lawyer->id]);

    shprRun($this);

    Mail::assertQueued(HandoverPackageReady::class, 1);
});

it('luật sư phụ trách nghỉ việc thì người nhận là manager (chuỗi dự phòng của ResolveStaffRecipients)', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $this->lawyer->update(['is_active' => false]);

    shprRun($this);

    Mail::assertQueued(HandoverPackageReady::class, 1);
    Mail::assertQueued(HandoverPackageReady::class, fn (HandoverPackageReady $mail): bool => $mail->hasTo($manager->email));
    expect(shprNotifications($this->lawyer))->toBe(0);
});

it('vụ restricted: manager không xem được vụ thì không nhận thư, người nhận dự phòng là admin', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->matter->update(['confidentiality' => 'restricted']);
    $this->lawyer->update(['is_active' => false]);

    shprRun($this);

    Mail::assertQueued(HandoverPackageReady::class, 1);
    Mail::assertQueued(HandoverPackageReady::class, fn (HandoverPackageReady $mail): bool => $mail->hasTo($admin->email));
    expect(shprNotifications($manager))->toBe(0);
});

it('không gửi cho khách hàng', function () {
    $clientUser = ClientUser::factory()->create(['client_id' => $this->matter->client_id]);

    shprRun($this);

    Mail::assertNotQueued(HandoverPackageReady::class, fn (HandoverPackageReady $mail): bool => $mail->hasTo($clientUser->email));
});

it('bỏ qua khi tài liệu này không còn là gói mới nhất của vụ, hoặc vụ/tài liệu không còn', function () {
    $newer = Document::factory()->create(['matter_id' => $this->matter->id]);
    $this->archive->update(['handover_document_id' => $newer->id]);

    shprRun($this);

    Mail::assertNothingQueued();
    expect(shprNotifications($this->lawyer))->toBe(0);

    shprRun($this, $newer->id);
    Mail::assertQueued(HandoverPackageReady::class, 1);

    Mail::fake();
    $newer->forceDelete();

    shprRun($this, $newer->id);
    Mail::assertNothingQueued();
});

it('thư mang mã và tên vụ, liên kết tới trang vụ việc, và không đính kèm gói', function () {
    $mail = new HandoverPackageReady($this->lawyer, $this->matter, $this->package);

    $html = $mail->render();
    $url = MatterResource::getUrl('view', ['record' => $this->matter], panel: 'admin');

    expect($mail->envelope()->subject)->toBe('Gói bàn giao hồ sơ '.$this->matter->code.' đã sẵn sàng')
        ->and($html)->toContain($this->matter->code)
        ->and($html)->toContain('Tranh chấp thừa kế')
        ->and($html)->toContain('Luật sư Hương')
        ->and($html)->toContain($url)
        ->and($html)->toContain('MUC-LUC.pdf')
        ->and($mail->attachments)->toBe([]);
});

it('thư ghi vào sổ thư đi với mẫu staff.handover_ready và liên kết tới tài liệu gói', function () {
    // `Mail::fake()` (beforeEach) đã thay cả facade lẫn binding `mail.manager` trong container: bỏ
    // instance giả để gọi lại bản thật (transport `array` của phpunit.xml) — cần cho sổ thư đi.
    app()->forgetInstance('mail.manager');
    Mail::clearResolvedInstances();

    Mail::to($this->lawyer->email)->send(new HandoverPackageReady($this->lawyer, $this->matter, $this->package));

    $message = OutboundMessage::query()->withoutGlobalScopes()->sole();

    expect($message->template)->toBe('staff.handover_ready')
        ->and($message->recipient)->toBe($this->lawyer->email)
        ->and($message->related_type)->toBe('document')
        ->and($message->related_id)->toBe($this->package->id);
});
