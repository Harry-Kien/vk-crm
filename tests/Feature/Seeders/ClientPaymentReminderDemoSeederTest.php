<?php

use App\Actions\Schedule\RemindClientsOfUpcomingInstalments;
use App\Enums\Confidentiality;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\BillingRelationManager;
use App\Models\Instalment;
use App\Models\Setting;
use App\Models\User;
use App\Support\Billing\ClientPaymentSettings;
use Database\Seeders\ClientPaymentReminderDemoSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAccountsSeeder;
use Database\Seeders\DemoDataSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/*
| Làn nt Task 2 — dữ liệu mẫu: công tắc vẫn TẮT sau khi seed; bật nó lên trên bản xem thử thì chủ văn
| phòng thấy ngay một đợt sắp đến hạn (thư báo trước hạn tới khách demo `khach1`) và một đợt quá hạn có
| nút "Gửi thư nhắc".
*/

beforeEach(function () {
    Storage::fake('private');

    $this->seed(DatabaseSeeder::class);
});

it('leaves the switch OFF, so nothing goes to any client until the office turns it on', function () {
    expect(ClientPaymentSettings::enabled())->toBeFalse()
        ->and(app(RemindClientsOfUpcomingInstalments::class)->handle())->toBe(['queued' => 0, 'skipped' => 0]);
});

it('mails the demo client about an instalment due soon as soon as the switch is ON', function () {
    Setting::query()->where('key', ClientPaymentSettings::ENABLED)->update(['value' => ClientPaymentSettings::ON]);

    app(RemindClientsOfUpcomingInstalments::class)->handle();

    $dueOn = today()->addDays(ClientPaymentReminderDemoSeeder::DUE_SOON_IN_DAYS)->format('d/m/Y');

    $toDemo = Mail::mailer()->getSymfonyTransport()->innerTransport()->messages()
        ->map(fn ($sent) => $sent->getOriginalMessage())
        ->filter(fn ($mail) => collect($mail->getTo())->map->getAddress()->contains(ClientPaymentReminderDemoSeeder::DEMO_CLIENT_EMAIL));

    // Đúng đợt seeder dời về hôm nay + 2 ngày (không seeder thì đợt đó hạn sau 7 ngày, ngoài cửa sổ 3 ngày).
    expect($toDemo->filter(fn ($mail) => str_contains((string) $mail->getTextBody(), $dueOn)))->not->toBeEmpty();
});

it('has an overdue instalment on a published matter that shows the "Gửi thư nhắc" button once the switch is ON', function () {
    Setting::query()->where('key', ClientPaymentSettings::ENABLED)->update(['value' => ClientPaymentSettings::ON]);
    Filament::setCurrentPanel('admin');

    $overdue = Instalment::query()->overdue()
        ->whereHas('contract.matter', fn ($query) => $query->where('is_published_to_portal', true)->where('confidentiality', Confidentiality::Normal->value))
        ->with('contract.matter')
        ->orderBy('id')
        ->firstOrFail();

    $this->actingAs(User::query()->where('email', DemoAccountsSeeder::ADMIN_EMAIL)->firstOrFail(), 'web');

    $this->livewire(BillingRelationManager::class, ['ownerRecord' => $overdue->contract->matter, 'pageClass' => ViewMatter::class])
        ->assertActionVisible(TestAction::make('remindClient')->table($overdue));
});

it('does not reschedule again when the demo seeder runs a second time the same day', function () {
    $before = Activity::query()->where('event', 'instalment_rescheduled')->count();

    $this->seed(DemoDataSeeder::class);

    expect(Activity::query()->where('event', 'instalment_rescheduled')->count())->toBe($before)
        ->and(ClientPaymentSettings::enabled())->toBeFalse();
});
