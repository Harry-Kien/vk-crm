<?php

use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Schedule\FlagRetentionExpiry;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\User;
use App\Notifications\Staff\DeadlineOverdueAlert;
use App\Notifications\Staff\RetentionExpiryAlert;
use App\Support\Scopes\ClientPortalScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * M7 Task 6 (R5) — tác vụ hằng ngày `retention.flag`: cảnh báo quản trị khi hồ sơ quá hạn lưu trữ
 * mà chưa ghi quyết định tiêu huỷ. KHÔNG BAO GIỜ xoá hay sửa gì ngoài việc ghi thông báo.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['media-library.prefix' => 'test-'.Str::random(16)]);

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
});

function freArchive(object $test, array $archive = [], array $matter = []): MatterArchive
{
    $record = Matter::factory()->create([
        'lead_lawyer_id' => $test->lawyer->id,
        'closed_at' => now()->subYears(11)->toDateString(),
        ...$matter,
    ]);

    return MatterArchive::factory()->create([
        'matter_id' => $record->id,
        'archived_by' => $test->lawyer->id,
        'client_access_until' => now()->subYears(11)->addDays(90)->toDateString(),
        'retention_until' => now()->subDay()->toDateString(),
        ...$archive,
    ]);
}

function freRun(): array
{
    return (new FlagRetentionExpiry)->handle();
}

/** @return list<int> id người nhận cảnh báo cho vụ việc của `$archive` */
function freRecipients(MatterArchive $archive): array
{
    return DatabaseNotification::query()
        ->where('type', RetentionExpiryAlert::class)
        ->where('data->viewData->matter_id', $archive->matter_id)
        ->orderBy('notifiable_id')
        ->pluck('notifiable_id')
        ->map(fn ($id): int => (int) $id)
        ->all();
}

function freEvent(): ScheduledEvent
{
    $event = collect(Schedule::events())->first(fn (ScheduledEvent $event): bool => $event->description === 'retention.flag');

    expect($event)->not->toBeNull();

    return $event;
}

// =========================================================================================
// Lịch
// =========================================================================================

it('chạy hằng ngày lúc 01:00 giờ Việt Nam, không vào phút nào khác', function () {
    $event = freEvent();
    $day = Carbon::parse('2026-10-21')->startOfDay();

    $this->travelTo($day->copy()->setTime(1, 0));
    expect($event->isDue(app()))->toBeTrue('phải tới hạn lúc 01:00');

    foreach (['00:00', '00:30', '00:59', '01:01', '07:00', '23:59'] as $notDue) {
        [$h, $m] = explode(':', $notDue);
        $this->travelTo($day->copy()->setTime((int) $h, (int) $m));
        expect($event->isDue(app()))->toBeFalse("không được tới hạn lúc {$notDue}");
    }
});

it('khoá chống chồng lấn có hạn 60 phút, không 1440 mặc định', function () {
    $event = freEvent();

    expect($event)->toBeInstanceOf(CallbackEvent::class)
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(60);
});

it('chạy mục lịch đã đăng ký là chạy đúng FlagRetentionExpiry', function () {
    $archive = freArchive($this);

    freEvent()->run(app());

    expect(freRecipients($archive))->toBe([$this->admin->id]);
});

// =========================================================================================
// Chọn hồ sơ nào
// =========================================================================================

it('cảnh báo hồ sơ quá hạn lưu trữ mà destroyed_at còn rỗng', function () {
    $archive = freArchive($this);

    expect(freRun())->toMatchArray(['flagged' => 1, 'notified' => 1, 'failed' => 0])
        ->and(freRecipients($archive))->toBe([$this->admin->id]);
});

it('đúng ngày cuối của hạn lưu trữ thì chưa cảnh báo; ngày hôm sau thì có', function () {
    $archive = freArchive($this, ['retention_until' => today()->toDateString()]);

    freRun();
    expect(freRecipients($archive))->toBe([]);

    $this->travelTo(today()->addDay()->setTime(1, 0));
    freRun();
    expect(freRecipients($archive))->toBe([$this->admin->id]);
});

it('cách nói trên bản ghi của cùng luật: hết ngày cuối mới quá hạn, chưa có ngày thì không bao giờ', function () {
    $archive = freArchive($this, ['retention_until' => today()->toDateString()]);

    expect($archive->fresh()->isRetentionExpired())->toBeFalse()
        ->and((new MatterArchive)->isRetentionExpired())->toBeFalse();

    $this->travelTo(today()->addDay());

    expect($archive->fresh()->isRetentionExpired())->toBeTrue();
});

it('vụ đã ghi quyết định tiêu huỷ (destroyed_at) không còn bị cảnh báo', function () {
    $archive = freArchive($this, ['destroyed_at' => now()->subDay(), 'destroyed_by' => $this->admin->id]);

    expect(freRun()['flagged'])->toBe(0)
        ->and(freRecipients($archive))->toBe([]);
});

it('vụ đang được mở lại (closed_at rỗng) không bị cảnh báo', function () {
    $archive = freArchive($this, matter: ['closed_at' => null]);

    freRun();

    expect(freRecipients($archive))->toBe([]);
});

it('bản ghi lưu trữ đã xoá mềm, hoặc vụ việc đã xoá mềm, không bị cảnh báo', function () {
    $archivedTrashed = freArchive($this);
    $archivedTrashed->delete();

    $matterTrashed = freArchive($this);
    Matter::query()->whereKey($matterTrashed->matter_id)->first()->delete();

    expect(freRun()['flagged'])->toBe(0)
        ->and(freRecipients($archivedTrashed))->toBe([])
        ->and(freRecipients($matterTrashed))->toBe([]);
});

// =========================================================================================
// Ai nhận
// =========================================================================================

it('chỉ admin đang hoạt động nhận — không trưởng phòng, không luật sư phụ trách, không admin bị vô hiệu hoá', function () {
    User::factory()->withRole(Role::Manager)->create();
    $inactiveAdmin = User::factory()->withRole(Role::Admin)->create(['is_active' => false]);
    $deletedAdmin = User::factory()->withRole(Role::Admin)->create();
    $deletedAdmin->delete();
    $secondAdmin = User::factory()->withRole(Role::Admin)->create();

    $archive = freArchive($this);
    freRun();

    expect(freRecipients($archive))->toBe([$this->admin->id, $secondAdmin->id])
        ->and(freRecipients($archive))->not->toContain($inactiveAdmin->id);
});

it('vụ restricted: chỉ tới admin', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $archive = freArchive($this, matter: ['confidentiality' => 'restricted']);

    freRun();

    expect(freRecipients($archive))->toBe([$this->admin->id])
        ->and($manager->notifications()->count())->toBe(0)
        ->and($this->lawyer->notifications()->count())->toBe(0);
});

it('không cảnh báo lặp: chạy nhiều ngày liền vẫn một thông báo mỗi người cho mỗi hồ sơ', function () {
    $archive = freArchive($this);

    freRun();
    $this->travelTo(now()->addDay());
    $second = freRun();
    $this->travelTo(now()->addDay());
    freRun();

    expect(freRecipients($archive))->toBe([$this->admin->id])
        ->and($second['notified'])->toBe(0);
});

it('admin được thêm sau vẫn nhận một lần, admin cũ không nhận lại', function () {
    $archive = freArchive($this);
    freRun();

    $newAdmin = User::factory()->withRole(Role::Admin)->create();
    freRun();

    expect(freRecipients($archive))->toBe([$this->admin->id, $newAdmin->id]);
});

it('một hồ sơ được đóng lại với hạn lưu trữ mới rồi quá hạn lần nữa thì được cảnh báo lần nữa', function () {
    $archive = freArchive($this);
    freRun();

    $archive->update(['retention_until' => now()->subDays(2)->toDateString()]);
    freRun();

    expect(DatabaseNotification::query()->where('type', RetentionExpiryAlert::class)->count())->toBe(2);
});

it('một người nhận lỗi không làm người khác và hồ sơ khác mất cảnh báo; lỗi được report', function () {
    Exceptions::fake();
    $secondAdmin = User::factory()->withRole(Role::Admin)->create();
    $first = freArchive($this);
    $second = freArchive($this);

    Event::listen(NotificationSending::class, function ($event) use ($first): void {
        if ($event->notification instanceof RetentionExpiryAlert
            && $event->notifiable->getKey() === $this->admin->id
            && $event->notification->toDatabase($event->notifiable)['viewData']['matter_id'] === $first->matter_id) {
            throw new RuntimeException('database channel down');
        }
    });

    $result = freRun();

    expect(freRecipients($first))->toBe([$secondAdmin->id])
        ->and(freRecipients($second))->toBe([$this->admin->id, $secondAdmin->id])
        ->and($result['failed'])->toBe(1);

    Exceptions::assertReported(RuntimeException::class);
});

it('lỗi ở cả một hồ sơ (khi hỏi người nhận) không dừng các hồ sơ còn lại; lỗi được report', function () {
    Exceptions::fake();
    $first = freArchive($this);
    $second = freArchive($this);

    $this->partialMock(ResolveStaffRecipients::class, function ($mock) use ($first): void {
        $mock->shouldReceive('activeAdminsFor')->andReturnUsing(function (Matter $matter) use ($first) {
            if ($matter->id === $first->matter_id) {
                throw new RuntimeException('recipient lookup failed');
            }

            return collect([$this->admin]);
        });
    });

    $result = freRun();

    expect(freRecipients($first))->toBe([])
        ->and(freRecipients($second))->toBe([$this->admin->id])
        ->and($result)->toMatchArray(['flagged' => 2, 'notified' => 1, 'failed' => 1]);

    Exceptions::assertReported(RuntimeException::class);
});

/**
 * Khoá chống lặp là (loại thông báo, `matter_id`, `retention_until`). Một thông báo LOẠI KHÁC tình
 * cờ mang cùng hai khoá `viewData` không được nuốt mất cảnh báo hồ sơ quá hạn.
 */
it('một thông báo loại khác mang cùng khoá viewData không chặn cảnh báo này', function () {
    $archive = freArchive($this);

    $this->admin->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => DeadlineOverdueAlert::class,
        'data' => ['viewData' => [
            'matter_id' => $archive->matter_id,
            'retention_until' => $archive->retention_until->toDateString(),
        ]],
    ]);

    expect(freRun()['notified'])->toBe(1)
        ->and(freRecipients($archive))->toBe([$this->admin->id]);
});

/**
 * Lịch chạy ngoài mọi phiên, nhưng tác vụ không được tin điều đó: `MatterArchive` và `Matter` mang
 * `ClientPortalScope`, và dưới một phiên cổng đang mở trong cùng tiến trình scope đó cắt sạch bản
 * ghi lưu trữ (`1 = 0`) và mọi vụ không phải của khách đó. Mỗi truy vấn của tác vụ gỡ scope tường
 * minh.
 */
it('chạy trong lúc một phiên cổng đang mở trong cùng tiến trình: scope của phiên đó không cắt tập hồ sơ', function () {
    $archive = freArchive($this);
    $account = ClientUser::factory()->activated()->create(['client_id' => Client::factory()->create()->id]);

    $result = ClientPortalScope::actingAs($account, fn (): array => freRun());

    expect($result)->toMatchArray(['flagged' => 1, 'notified' => 1, 'failed' => 0])
        ->and(freRecipients($archive))->toBe([$this->admin->id]);
});

// =========================================================================================
// Không xoá, không sửa
// =========================================================================================

it('không xoá, không sửa gì: vụ, tài liệu, tệp trên đĩa và bản ghi lưu trữ còn nguyên sau khi chạy', function () {
    Mail::fake();
    Queue::fake();
    $archive = freArchive($this);
    $document = Document::factory()->create([
        'matter_id' => $archive->matter_id,
        'group' => DocumentGroup::Issued,
        'status' => DocumentStatus::SignedFiled,
    ]);
    $document->addMediaFromString('noi dung')->usingFileName(Str::lower((string) Str::ulid()).'.pdf')->toMediaCollection('file');
    $media = $document->refresh()->getFirstMedia('file');

    $before = [
        'matters' => DB::table('matters')->where('id', $archive->matter_id)->first(),
        'archives' => DB::table('matter_archives')->where('id', $archive->id)->first(),
        'documents' => DB::table('documents')->where('id', $document->id)->first(),
        'media' => DB::table('media')->where('id', $media->id)->first(),
    ];

    freRun();

    expect(DB::table('matters')->where('id', $archive->matter_id)->first())->toEqual($before['matters'])
        ->and(DB::table('matter_archives')->where('id', $archive->id)->first())->toEqual($before['archives'])
        ->and(DB::table('documents')->where('id', $document->id)->first())->toEqual($before['documents'])
        ->and(DB::table('media')->where('id', $media->id)->first())->toEqual($before['media'])
        ->and(Storage::disk($media->disk)->exists($media->getPathRelativeToRoot()))->toBeTrue();

    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

// =========================================================================================
// Thông báo hiện được trên chuông của panel admin
// =========================================================================================

it('ghi một thông báo mà chuông của panel admin hiện được, có mã hồ sơ, ngày hết hạn và liên kết tới vụ việc', function () {
    $archive = freArchive($this);
    freRun();

    $row = DatabaseNotification::query()->where('type', RetentionExpiryAlert::class)->sole();
    $rendered = FilamentNotification::fromDatabase($row);
    $matter = $archive->matter;

    expect($rendered->getTitle())->toBe('Hồ sơ đã quá hạn lưu trữ')
        ->and($rendered->getBody())->toContain($matter->code)
        ->and($rendered->getBody())->toContain($archive->retention_until->format('d/m/Y'))
        ->and($rendered->getBody())->toContain('không tự xoá')
        ->and($rendered->getColor())->toBe('warning');

    $action = collect($rendered->getActions())->sole();
    expect($action->getUrl())->toBe(route('filament.admin.resources.matters.view', ['record' => $matter->id]));
});
