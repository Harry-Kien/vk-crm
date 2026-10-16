<?php

use App\Actions\Intake\RecordIntake;
use App\Actions\Matter\SyncMatterArchive;
use App\Actions\Schedule\CheckStaleMatters;
use App\Actions\Schedule\RemindMissingDocuments;
use App\Actions\Schedule\RemindUnansweredIntakes;
use App\Enums\ChecklistItemStatus;
use App\Enums\ClientRequestStatus;
use App\Enums\IntakeSource;
use App\Enums\OutboundStatus;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Exceptions\FileRejected;
use App\Filament\Admin\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Filament\Portal\Pages\MyRequests;
use App\Filament\Portal\Pages\SubmitDocument;
use App\Jobs\SendMissingDocumentsMail;
use App\Jobs\SendStaleMatterMail;
use App\Mail\Client\MissingDocuments;
use App\Mail\Staff\IntakeUnanswered;
use App\Mail\Staff\StaleMatterReminder;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\Staff\StaleMatterAlert;
use App\Support\Files\FileGuard;
use App\Support\Intake\FirstResponseClock;
use App\Support\Scopes\ClientPortalScope;
use App\Support\Settings\BusinessSettings;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\Support\BusinessSettingsScreen;

/**
 * Làn st Task 1, mục 2 — mỗi thông số của "Cài đặt nghiệp vụ" ĐƯỢC NỐI THẬT vào chỗ đang đọc nó: chưa
 * lưu gì thì đúng như hôm nay (cấu hình/.env, công tắc bật), đổi trên màn hình thì hành vi đổi đúng chỗ
 * đó. Mọi lần đổi đi qua trang (`BusinessSettingsScreen::save()` — Livewire), không gọi thẳng Action.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

// ---------------------------------------------------------------------------------------------
// Số ngày khách còn xem hồ sơ sau khi vụ kết thúc → SyncMatterArchive
// ---------------------------------------------------------------------------------------------

it('keeps the client access window of .env when nothing is saved, and follows the screen once saved', function () {
    config(['vkcrm.client_access_days' => 45]);

    $closedAt = now()->subDay()->startOfDay();
    $first = Matter::factory()->create(['closed_at' => $closedAt->toDateString()]);

    expect(app(SyncMatterArchive::class)->handle($first->id, null)->client_access_until->toDateString())
        ->toBe($closedAt->copy()->addDays(45)->toDateString());

    BusinessSettingsScreen::save(['client_access_days' => 200]);

    $second = Matter::factory()->create(['closed_at' => $closedAt->toDateString()]);

    expect(app(SyncMatterArchive::class)->handle($second->id, null)->client_access_until->toDateString())
        ->toBe($closedAt->copy()->addDays(200)->toDateString());
});

// ---------------------------------------------------------------------------------------------
// Dung lượng tệp tải lên → FileGuard + câu "tối đa N MB" ở ô tải tệp
// ---------------------------------------------------------------------------------------------

it('keeps the upload limit of .env when nothing is saved, and lowers it from the screen', function () {
    config([
        'vkcrm.upload_max_mb' => 3,
        'livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.(3 * 1024)],
    ]);

    // 1,5 MB, byte đầu là chữ ký PDF thật để qua lần đọc MIME của FileGuard.
    $file = fn () => UploadedFile::fake()->createWithContent('hop-dong.pdf', "%PDF-1.4\n".str_repeat('0', 1536 * 1024));

    FileGuard::check($file());
    expect(DocumentsRelationManager::maxUploadMegabytes())->toBe(3);

    BusinessSettingsScreen::save(['upload_max_mb' => 1]);

    expect(fn () => FileGuard::check($file()))->toThrow(FileRejected::class)
        ->and(DocumentsRelationManager::maxUploadMegabytes())->toBe(1);
});

/**
 * Cổng khách: cửa sớm nhất cho kích thước là `SubmitDocument::_startUpload()` (trước khi một byte rời
 * điện thoại). Mutation probe: `SubmitDocument::maxMegabytes()` trả lại 20 → tệp 2 MB vẫn qua sau khi hạ
 * trần xuống 1 MB.
 */
it('refuses an oversized file on the client portal once the limit is lowered on screen', function () {
    config([
        'vkcrm.upload_max_mb' => 3,
        'livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.(3 * 1024)],
    ]);

    $client = Client::factory()->create();
    $account = ClientUser::factory()->activated()->create(['client_id' => $client->id]);
    $matter = Matter::factory()->for($client)->create([
        'is_published_to_portal' => true,
        'lead_lawyer_id' => User::factory()->withRole(Role::Lawyer)->create()->id,
    ]);
    $item = MatterChecklistItem::factory()->for($matter)->create(['status' => ChecklistItemStatus::Missing]);

    $startUpload = function () use ($account, $matter, $item) {
        Filament::setCurrentPanel('portal');

        return test()->actingAs($account, 'client')
            ->livewire(SubmitDocument::class, ['record' => $matter->getKey()])
            ->call('chooseItem', $item->getKey())
            ->call('_startUpload', 'data.file', [[
                'name' => 'so-do.jpg',
                'size' => 2 * 1024 * 1024,
                'type' => 'image/jpeg',
            ]], false);
    };

    $startUpload()->assertHasNoErrors('data.file');

    BusinessSettingsScreen::save(['upload_max_mb' => 1]);

    $refused = $startUpload()->assertHasErrors('data.file');

    expect($refused->errors()->first('data.file'))->toContain('vượt quá 1 MB');
});

it('never lets a stored upload limit exceed what the server accepts today', function () {
    config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.(10 * 1024)]]);

    BusinessSettingsScreen::save(['upload_max_mb' => 8]);

    // Máy chủ hạ trần sau lần lưu (UPLOAD_MAX_MB nhỏ đi).
    config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.(5 * 1024)]]);

    expect(BusinessSettings::uploadMaxMegabytes())->toBe(min(5, BusinessSettings::phpLimitMegabytes() ?? 5));
});

// ---------------------------------------------------------------------------------------------
// Giờ làm việc để phản hồi lần đầu → FirstResponseClock (tác vụ nhắc)
// ---------------------------------------------------------------------------------------------

function wiringIntakeMailRows(User $recipient): int
{
    return OutboundMessage::query()->withoutGlobalScopes()
        ->where('template', IntakeUnanswered::TEMPLATE)
        ->where('recipient', $recipient->email)
        ->where('status', OutboundStatus::Sent)
        ->count();
}

it('reminds about an unanswered contact after the working hours set on screen', function () {
    config(['vkcrm.intake_response_hours' => 4]);
    $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00', 'Asia/Ho_Chi_Minh'));

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    app(RecordIntake::class)->handle($lawyer, [
        'contact_name' => 'Người Gọi Mẫu',
        'contact_phone' => '0832270898',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
        'assigned_to' => $lawyer->id,
    ], []);

    expect(FirstResponseClock::fromConfig()->thresholdHours)->toBe(4);

    $this->travelTo(CarbonImmutable::parse('2026-10-07 11:00', 'Asia/Ho_Chi_Minh'));
    app(RemindUnansweredIntakes::class)->handle();
    expect(wiringIntakeMailRows($lawyer))->toBe(0);

    BusinessSettingsScreen::save(['intake_response_hours' => 2]);

    expect(FirstResponseClock::fromConfig()->thresholdHours)->toBe(2);

    app(RemindUnansweredIntakes::class)->handle();
    expect(wiringIntakeMailRows($lawyer))->toBe(1);
});

// ---------------------------------------------------------------------------------------------
// Công tắc thư "hồ sơ quá 21 ngày chưa cập nhật" → CheckStaleMatters + SendStaleMatterMail
// ---------------------------------------------------------------------------------------------

it('mails the 21-day stale reminder by default, and stops mailing — but keeps the 14-day bell — when switched off', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'last_client_update_at' => now()->subDays(22)]);

    expect((new CheckStaleMatters)->handle()['mailed'])->toBe(1);
    Mail::assertSent(StaleMatterReminder::class, fn ($mail) => $mail->hasTo($lawyer->email));

    Mail::fake();
    $lawyer->notifications()->delete();
    BusinessSettingsScreen::save(['stale_matter_mail' => false]);

    $result = (new CheckStaleMatters)->handle();

    expect($result['mailed'])->toBe(0)
        ->and($lawyer->fresh()->notifications->where('type', StaleMatterAlert::class))->toHaveCount(1);
    Mail::assertNothingSent();
});

/** Mutation probe: bỏ lần hỏi công tắc trong `SendStaleMatterMail::context()` → thư đã xếp vẫn đi. */
it('does not send a queued stale reminder once the switch is turned off before it runs', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'last_client_update_at' => now()->subDays(22)]);

    BusinessSettingsScreen::save(['stale_matter_mail' => false]);

    (new SendStaleMatterMail($matter->id))->handle();

    Mail::assertNothingSent();
});

// ---------------------------------------------------------------------------------------------
// Công tắc thư nhắc khách nộp giấy tờ → RemindMissingDocuments + SendMissingDocumentsMail
// ---------------------------------------------------------------------------------------------

/** @return array{0: Matter, 1: ClientUser} */
function wiringAwaitingMatter(): array
{
    $client = Client::factory()->create();
    $account = ClientUser::factory()->activated()->create(['client_id' => $client->id, 'is_active' => true]);
    $matter = Matter::factory()->create([
        'client_id' => $client->id,
        'lead_lawyer_id' => User::factory()->withRole(Role::Lawyer)->create()->id,
    ]);

    MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::Missing)->create(['is_required' => true]);

    return [$matter, $account];
}

it('mails the client about missing documents by default, and not once switched off', function () {
    Mail::fake();
    [$matter, $account] = wiringAwaitingMatter();

    expect((new RemindMissingDocuments)->handle()['mailed'])->toBe(1);
    Mail::assertSent(MissingDocuments::class, fn ($mail) => $mail->hasTo($account->email));

    Mail::fake();
    BusinessSettingsScreen::save(['missing_documents_mail' => false]);

    expect((new RemindMissingDocuments)->handle()['mailed'])->toBe(0);
    Mail::assertNothingSent();
});

/** Mutation probe: bỏ lần hỏi công tắc trong `SendMissingDocumentsMail::context()` → thư đã xếp vẫn đi. */
it('does not send a queued missing-documents mail once the switch is turned off before it runs', function () {
    Mail::fake();
    [$matter] = wiringAwaitingMatter();

    BusinessSettingsScreen::save(['missing_documents_mail' => false]);

    (new SendMissingDocumentsMail($matter->id))->handle();

    Mail::assertNothingSent();
});

// ---------------------------------------------------------------------------------------------
// Công tắc "khách mở yêu cầu mới trên cổng" → MyRequests + OpenClientRequest
// ---------------------------------------------------------------------------------------------

/** @return array{0: Matter, 1: ClientUser} */
function wiringPortalMatter(): array
{
    $client = Client::factory()->create();
    $account = ClientUser::factory()->activated()->create(['client_id' => $client->id]);
    $matter = Matter::factory()->for($client)->create([
        'is_published_to_portal' => true,
        'lead_lawyer_id' => User::factory()->withRole(Role::Lawyer)->create()->id,
    ]);

    return [$matter, $account];
}

function wiringRequestsPage(ClientUser $viewer, Matter $matter)
{
    Filament::setCurrentPanel('portal');

    return test()->actingAs($viewer, 'client')->livewire(MyRequests::class, ['record' => $matter->getKey()]);
}

it('lets the client open a new request by default', function () {
    [$matter, $account] = wiringPortalMatter();

    wiringRequestsPage($account, $matter)
        ->assertSeeHtml('wire:submit="submitRequest"')
        ->set('subject', 'Xin hỏi về ngày hoà giải')
        ->set('content', 'Văn phòng cho tôi hỏi ngày hoà giải đã có chưa ạ.')
        ->call('submitRequest')
        ->assertHasNoErrors();

    expect(ClientRequest::query()->withoutGlobalScope(ClientPortalScope::class)->count())->toBe(1);
});

/**
 * Mutation probe (hai điều kiện): bỏ `@if` quanh ô gửi trong view → `assertDontSeeHtml` đỏ; bỏ lần hỏi
 * công tắc trong `OpenClientRequest` → một yêu cầu vẫn được tạo.
 */
it('hides the new-request form and refuses a new request when switched off, but keeps replies open', function () {
    [$matter, $account] = wiringPortalMatter();
    $thread = ClientRequest::factory()->for($matter)->create([
        'client_user_id' => $account->id,
        'status' => ClientRequestStatus::Answered,
    ]);

    BusinessSettingsScreen::save(['client_requests' => false]);

    wiringRequestsPage($account, $matter)
        ->assertDontSeeHtml('wire:submit="submitRequest"')
        ->assertSee(__('settings.business.portal_requests_off'))
        ->set('subject', 'Xin hỏi về án phí')
        ->set('content', 'Án phí bao nhiêu ạ?')
        ->call('submitRequest')
        ->assertNotified(__('actions.failed_title'));

    expect(ClientRequest::query()->withoutGlobalScope(ClientPortalScope::class)->count())->toBe(1);

    wiringRequestsPage($account, $matter)
        ->set('replies.'.$thread->getKey(), 'Tôi xin bổ sung thêm một câu hỏi.')
        ->call('submitReply', $thread->getKey())
        ->assertHasNoErrors();

    expect(ClientRequestReply::query()->withoutGlobalScopes()->where('request_id', $thread->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------------------------
// Một chỗ đọc duy nhất
// ---------------------------------------------------------------------------------------------

/**
 * Ba khoá cấu hình mà giao diện nay thắng chỉ được đọc ở `BusinessSettings` — một nơi đọc thẳng
 * `config('vkcrm.client_access_days')` sẽ lặng lẽ bỏ qua giá trị trên màn hình. Mutation probe: trả lại
 * `config('vkcrm.client_access_days')` vào `SyncMatterArchive` → đỏ, nêu đích danh tệp.
 */
it('reads the three server values only through BusinessSettings', function () {
    $offenders = [];

    foreach ([app_path(), resource_path('views')] as $root) {
        foreach (File::allFiles($root) as $file) {
            if ($file->getRealPath() === realpath(app_path('Support/Settings/BusinessSettings.php'))) {
                continue;
            }

            if (preg_match('/vkcrm\.(client_access_days|upload_max_mb|intake_response_hours)\b/', $file->getContents(), $match) === 1) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).' ('.$match[1].')';
            }
        }
    }

    expect($offenders)->toBe([]);
});
