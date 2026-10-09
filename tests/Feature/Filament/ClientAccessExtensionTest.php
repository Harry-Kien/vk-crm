<?php

use App\Actions\Matter\ExtendClientAccess;
use App\Actions\Matter\SyncMatterArchive;
use App\Enums\DocumentGroup;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Spatie\Activitylog\Models\Activity;

/*
 * Làn fm, mục A5 (kiểm tra nghiệp vụ 2026-10-09): hạn khách tra cứu hồ sơ đã kết thúc phải hiện cho
 * nhân sự, gia hạn được (luật sư phụ trách hoặc quản trị viên, có lý do và nhật ký), và hộp thoại công
 * bố tài liệu trên vụ đã hết hạn tra cứu phải nói thẳng khách sẽ không thấy gì — không báo "thành công"
 * suông.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Storage::fake('private');
    config(['media-library.prefix' => 'test-'.Str::random(16)]);
    Carbon::setTestNow('2026-06-05 09:00:00');
});

/**
 * Vụ đóng ngày 01/03/2026, hạn tra cứu 30/05/2026 (đã hết vào "hôm nay" 05/06), khách có tài khoản.
 *
 * @return array{0: User, 1: Matter, 2: ClientUser}
 */
function fmExpiredClosedMatter(): array
{
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('closed')->create([
        'lead_lawyer_id' => $lawyer->id,
        'is_published_to_portal' => true,
        'closed_at' => '2026-03-01',
    ]);
    MatterArchive::factory()->create([
        'matter_id' => $matter->id,
        'client_access_until' => '2026-05-30',
        'retention_until' => '2036-03-01',
    ]);
    $clientUser = ClientUser::factory()->activated()->create(['client_id' => $matter->client_id]);

    return [$lawyer, $matter->fresh(), $clientUser];
}

it('shows the client access deadline and that it has passed on the overview tab', function () {
    [$lawyer, $matter] = fmExpiredClosedMatter();

    $this->actingAs($lawyer, 'web');

    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->assertSee(__('lifecycle.access.until_label'))
        ->assertSee('30/05/2026')
        ->assertSee(__('lifecycle.access.expired_hint'));
});

it('lets the lead lawyer extend the client access with a reason, which puts the matter back on the portal', function () {
    [$lawyer, $matter, $clientUser] = fmExpiredClosedMatter();

    expect(Gate::forUser($clientUser)->allows('view', $matter))->toBeFalse();

    $this->actingAs($lawyer, 'web');

    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->assertActionVisible('extendClientAccess')
        ->callAction('extendClientAccess', data: [
            'until' => '2026-07-05',
            'reason' => 'Khách xin thêm thời gian để tải gói bàn giao.',
        ])
        ->assertHasNoActionErrors();

    expect(MatterArchive::query()->where('matter_id', $matter->id)->first()->client_access_until->toDateString())->toBe('2026-07-05')
        ->and(Gate::forUser($clientUser)->allows('view', $matter->fresh()))->toBeTrue();

    $activity = Activity::query()->where('event', 'client_access_extended')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($lawyer->id)
        ->and($activity->properties['from'])->toBe('2026-05-30')
        ->and($activity->properties['to'])->toBe('2026-07-05')
        ->and($activity->properties['reason'])->toBe('Khách xin thêm thời gian để tải gói bàn giao.');
});

it('keeps the extension out of reach of a team member who is not the lead', function () {
    [$lawyer, $matter] = fmExpiredClosedMatter();
    $associate = User::factory()->withRole(Role::Lawyer)->create();
    $matter->addTeamMember($associate, MatterRole::Associate);

    $this->actingAs($associate, 'web');

    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->assertActionHidden('extendClientAccess');

    expect(fn () => app(ExtendClientAccess::class)->handle($matter, $associate, '2026-07-05', 'Khách xin thêm thời gian để tải.'))
        ->toThrow(AuthorizationException::class);
});

it('hides the extension on an open matter and refuses it in the action', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create();

    $this->actingAs($admin, 'web');

    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->assertActionHidden('extendClientAccess');

    expect(fn () => app(ExtendClientAccess::class)->handle($matter, $admin, '2026-07-05', 'Khách xin thêm thời gian để tải.'))
        ->toThrow(ValidationException::class);
});

it('refuses a date that is not later than both today and the current deadline, or too far away', function (string $until) {
    [$lawyer, $matter] = fmExpiredClosedMatter();

    $this->actingAs($lawyer, 'web');

    expect(fn () => app(ExtendClientAccess::class)->handle($matter, $lawyer, $until, 'Khách xin thêm thời gian để tải.'))
        ->toThrow(ValidationException::class);

    expect(MatterArchive::query()->where('matter_id', $matter->id)->first()->client_access_until->toDateString())->toBe('2026-05-30');
})->with([
    'hôm nay' => ['2026-06-05'],
    'trước hạn cũ' => ['2026-05-20'],
    'quá một năm' => ['2027-06-06'],
    'chuỗi hỏng' => ['tuần sau'],
]);

it('requires a reason for the extension', function () {
    [$lawyer, $matter] = fmExpiredClosedMatter();

    expect(fn () => app(ExtendClientAccess::class)->handle($matter, $lawyer, '2026-07-05', '  '))
        ->toThrow(ValidationException::class);
});

it('keeps an extended deadline when the archive row is synced again', function () {
    [$lawyer, $matter] = fmExpiredClosedMatter();
    app(ExtendClientAccess::class)->handle($matter, $lawyer, '2026-07-05', 'Khách xin thêm thời gian để tải.');

    app(SyncMatterArchive::class)->handle($matter->getKey(), $lawyer);

    expect(MatterArchive::query()->where('matter_id', $matter->id)->first()->client_access_until->toDateString())->toBe('2026-07-05');
});

/** @return list<string> */
function fmPublishDialogWarnings(Testable $component): array
{
    $formName = $component->instance()->getMountedActionSchemaName();
    /** @var Schema $schema */
    $schema = $component->instance()->{$formName};

    return collect($schema->getFlatComponents())
        ->filter(fn ($c) => $c instanceof Text && $c->isVisible())
        ->map(fn ($c) => (string) $c->getContent())
        ->values()
        ->all();
}

function fmAuthorityDocument(Matter $matter): Document
{
    $document = Document::factory()->for($matter)->group(DocumentGroup::Authority)->create();
    $document->addMedia(UploadedFile::fake()->createWithContent('nguon.pdf', '%PDF-1.4 noi dung that'))
        ->usingName('Ban an.pdf')
        ->usingFileName('01k5g7q8wz0000000000000001.pdf')
        ->toMediaCollection('file');

    return $document->refresh();
}

it('warns on the publish dialog when the client access has expired, and does not claim success', function () {
    [$lawyer, $matter] = fmExpiredClosedMatter();
    $document = fmAuthorityDocument($matter);

    $this->actingAs($lawyer, 'web');

    $manager = $this->livewire(DocumentsRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class]);
    $manager->mountAction(TestAction::make('publish')->table($document));

    expect(fmPublishDialogWarnings($manager))->toContain(__('lifecycle.access.publish_warning', ['date' => '30/05/2026']));

    $this->livewire(DocumentsRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
        ->callAction(TestAction::make('publish')->table($document), data: ['client_can_view' => true, 'client_can_download' => true])
        ->assertHasNoActionErrors()
        ->assertNotified(__('lifecycle.access.published_not_visible'));
});

it('shows no expiry warning on the publish dialog while the client can still look the matter up', function () {
    [$lawyer, $matter] = fmExpiredClosedMatter();
    MatterArchive::query()->where('matter_id', $matter->id)->update(['client_access_until' => '2026-06-05']);
    $document = fmAuthorityDocument($matter);

    $this->actingAs($lawyer, 'web');

    $manager = $this->livewire(DocumentsRelationManager::class, ['ownerRecord' => $matter->fresh(), 'pageClass' => ViewMatter::class]);
    $manager->mountAction(TestAction::make('publish')->table($document));

    expect(fmPublishDialogWarnings($manager))->not->toContain(__('lifecycle.access.publish_warning', ['date' => '05/06/2026']));
});

it('refuses to extend a reopened matter that still has its archive row', function () {
    [$lawyer, $matter] = fmExpiredClosedMatter();
    $matter->forceFill(['closed_at' => null, 'stage' => 'intake'])->save();
    MatterArchive::query()->where('matter_id', $matter->id)->update(['client_access_until' => null]);

    expect(fn () => app(ExtendClientAccess::class)->handle($matter->fresh(), $lawyer, '2026-07-05', 'Khách xin thêm thời gian để tải.'))
        ->toThrow(ValidationException::class, __('lifecycle.access.not_extendable'));
});

it('never shortens a deadline that is still running', function () {
    [$lawyer, $matter] = fmExpiredClosedMatter();
    MatterArchive::query()->where('matter_id', $matter->id)->update(['client_access_until' => '2026-06-20']);

    expect(fn () => app(ExtendClientAccess::class)->handle($matter, $lawyer, '2026-06-10', 'Khách xin thêm thời gian để tải.'))
        ->toThrow(ValidationException::class, __('lifecycle.access.until_too_early', ['date' => '20/06/2026']));

    expect(MatterArchive::query()->where('matter_id', $matter->id)->first()->client_access_until->toDateString())->toBe('2026-06-20');
});
