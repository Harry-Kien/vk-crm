<?php

use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Filament\Admin\Pages\Performance;
use App\Filament\Admin\Resources\ClientUsers\Pages\EditClientUser;
use App\Filament\Admin\Resources\IntakeRequests\IntakeRequestResource;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Portal\Pages\MatterProgress;
use App\Filament\Portal\Pages\MyMatters;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\WebPushTestKeys;

/*
|--------------------------------------------------------------------------
| SPEC §10.9 — lượt quét toàn hệ thống trước bản 1.0 (M8 Task 6, R5; lane v1 Task 1)
|--------------------------------------------------------------------------
|
| "Khi `client_users.is_active = false`, mọi phiên đang mở phải bị vô hiệu ngay ở request kế tiếp."
| Kiểm chứng lại có đo đạc, sau mọi milestone đã thêm bề mặt mới: mỗi ĐƯỜNG vô hiệu hoá (nút trên
| màn hình của văn phòng, khách hàng bị xoá mềm, tác vụ đêm `client-access.expire` của M7) nhân với
| mỗi LOẠI request kế tiếp của một trình duyệt đang đăng nhập (tải trang cổng, request cập nhật
| Livewire, đăng ký thiết bị nhận thông báo đẩy của M12, tải tài liệu bằng đường dẫn ký TRƯỚC lúc bị
| vô hiệu). Sau request đó phiên không còn đăng nhập, và không request nào trả dữ liệu.
|
| Cùng lời hứa cho tài khoản NHÂN SỰ bị vô hiệu (nút "Hoạt động" trên trang sửa nhân sự): trang tiếp
| nhận (M10), trang theo dõi đội ngũ và hiệu suất (M13), request cập nhật Livewire, thiết bị (M12),
| tải tài liệu. Trước lượt quét này nhân sự bị vô hiệu nhận 404 ở mọi trang nhưng phiên vẫn đăng nhập
| (`EndDisabledStaffSessions` đăng xuất nó, như `EnsurePortalAccountIsActive` đăng xuất khách).
|
| Mọi request đi qua HTTP thật. Sau khi cắt, `actingAs($tàiKhoản->fresh())` mô phỏng một request mới
| đọc lại tài khoản từ CSDL (guard của bộ test giữ đối tượng cũ trong bộ nhớ giữa các request).
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(WebPushTestKeys::config());
    config(['media-library.prefix' => 'test-'.Str::random(16)]);

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->lead = User::factory()->withRole(Role::Lawyer)->create();

    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);
    $this->matter = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lead->id]);

    $this->document = Document::factory()->create([
        'matter_id' => $this->matter->id,
        'group' => DocumentGroup::ClientProvided,
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
    ]);
    $this->document->addMedia(UploadedFile::fake()->createWithContent('nguon.pdf', '%PDF-1.4 noi dung'))
        ->usingFileName('spec109-'.Str::random(12).'.pdf')
        ->toMediaCollection('file');
});

/** `wire:snapshot` của đúng component trong HTML đã render (khuôn của `DenialCodeTest`). */
function spec109Snapshot(string $html, string $component): string
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

    $snapshot = collect($matches[1])
        ->map(fn (string $encoded): string => html_entity_decode($encoded, ENT_QUOTES))
        ->first(fn (string $json): bool => (json_decode($json, true)['memo']['name'] ?? null) === $component);

    expect($snapshot)->not->toBeNull();

    return $snapshot;
}

function spec109MatterUrl(): string
{
    return MatterProgress::getUrl(['record' => test()->matter], panel: 'portal');
}

function spec109LivewireUpdate(string $snapshot): TestResponse
{
    return test()->withHeaders(['X-Livewire' => '1'])->postJson(Livewire::getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]],
    ]);
}

function spec109PushBody(string $suffix): array
{
    return [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/spec109-'.$suffix.':APA91b',
        'keys' => WebPushTestKeys::subscription(),
        'contentEncoding' => 'aes128gcm',
    ];
}

/*
| Ba đường vô hiệu hoá một tài khoản cổng.
*/
dataset('spec109 client cuts', [
    'nút "Hoạt động" trên trang sửa tài khoản cổng' => [function (): void {
        // Request trước đó là của cổng khách, nên panel hiện hành đang là `portal`.
        Filament::setCurrentPanel('admin');

        Livewire::actingAs(test()->admin, 'web')
            ->test(EditClientUser::class, ['record' => test()->clientUser->getKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();
    }],
    'khách hàng bị xoá mềm' => [function (): void {
        test()->client->delete();
    }],
    'tác vụ đêm client-access.expire (M7)' => [function (): void {
        MatterArchive::factory()->create([
            'matter_id' => test()->matter->id,
            'client_access_until' => today()->subDay()->toDateString(),
        ]);

        test()->artisan('schedule:test', ['--name' => 'client-access.expire'])->assertSuccessful();
    }],
]);

/*
| Bốn loại request kế tiếp của trình duyệt khách đang đăng nhập. Mỗi closure nhận ngữ cảnh dựng
| TRƯỚC lúc cắt (snapshot Livewire, đường dẫn tải đã ký) và tự khẳng định câu trả lời.
*/
dataset('spec109 client next requests', [
    'tải trang hồ sơ trên cổng' => [function (array $before): void {
        test()->get(spec109MatterUrl())
            ->assertRedirect(Filament::getPanel('portal')->getLoginUrl());
    }],
    'request cập nhật Livewire của trang đang mở' => [function (array $before): void {
        spec109LivewireUpdate($before['snapshot'])
            ->assertRedirect(Filament::getPanel('portal')->getLoginUrl());
    }],
    'bật thông báo trên máy này (M12)' => [function (array $before): void {
        $response = test()->postJson('/portal/push/subscriptions', spec109PushBody('khach'));

        expect($response->getStatusCode())->not->toBe(201)
            ->and(DB::table('push_subscriptions')->count())->toBe(0);
    }],
    'tải tài liệu bằng đường dẫn ký trước lúc bị vô hiệu' => [function (array $before): void {
        test()->get($before['download'])->assertNotFound();
    }],
]);

it('cắt phiên khách ở request kế tiếp, mọi đường vô hiệu hoá, mọi loại request', function (Closure $cut, Closure $next) {
    $this->actingAs($this->clientUser, 'client');

    // Trang hồ sơ, không trang danh sách: khách chỉ có MỘT vụ thì danh sách tự chuyển sang hồ sơ đó.
    $before = [
        'snapshot' => spec109Snapshot($this->get(spec109MatterUrl())->assertOk()->getContent(), MatterProgress::class),
        'download' => $this->document->downloadUrlFor($this->clientUser),
    ];

    $cut();

    // Request mới: guard đọc lại tài khoản (và khách hàng) từ CSDL.
    $this->actingAs($this->clientUser->fresh(), 'client');

    $next($before);

    expect(auth('client')->check())->toBeFalse('phiên khách vẫn còn đăng nhập sau request kế tiếp');

    // Và request SAU đó cũng không còn gì: không đăng nhập lại ngầm qua cookie "ghi nhớ".
    $this->get(MyMatters::getUrl(panel: 'portal'))->assertRedirect(Filament::getPanel('portal')->getLoginUrl());
})->with('spec109 client cuts')->with('spec109 client next requests');

/** Cặp dương: không cắt gì thì cùng bốn request đó đều được phục vụ và phiên còn nguyên. */
it('giữ nguyên phiên khách còn hoạt động qua cùng bốn loại request', function () {
    $this->actingAs($this->clientUser, 'client');

    $snapshot = spec109Snapshot($this->get(spec109MatterUrl())->assertOk()->getContent(), MatterProgress::class);

    spec109LivewireUpdate($snapshot)->assertOk();
    $this->postJson('/portal/push/subscriptions', spec109PushBody('khach-con-hoat-dong'))->assertCreated();
    $this->get($this->document->downloadUrlFor($this->clientUser))->assertOk();

    expect(auth('client')->check())->toBeTrue();
});

/*
| Nhân sự bị vô hiệu: năm loại request kế tiếp, gồm hai trang của M10/M13.
*/
dataset('spec109 staff next requests', [
    'trang tiếp nhận (M10)' => [function (array $before): void {
        test()->get(IntakeRequestResource::getUrl('index', panel: 'admin'))->assertRedirect('/admin/login');
    }],
    'trang theo dõi đội ngũ (M13)' => [function (array $before): void {
        test()->get('/admin/team')->assertRedirect('/admin/login');
    }],
    'request cập nhật Livewire của trang "Hiệu suất theo kỳ" đang mở (M13)' => [function (array $before): void {
        // Request JSON không đăng nhập: `Authenticate` trả 401 thay cho chuyển hướng; Livewire tải lại
        // trang, và trang đó chuyển về đăng nhập.
        spec109LivewireUpdate($before['snapshot'])->assertUnauthorized();
    }],
    'bật thông báo trên máy này (M12)' => [function (array $before): void {
        $response = test()->postJson('/admin/push/subscriptions', spec109PushBody('nhan-su'));

        expect($response->getStatusCode())->not->toBe(201)
            ->and(DB::table('push_subscriptions')->count())->toBe(0);
    }],
    'tải tài liệu bằng đường dẫn ký trước lúc bị vô hiệu' => [function (array $before): void {
        test()->get($before['download'])->assertNotFound();
    }],
]);

it('cắt phiên nhân sự bị vô hiệu trên màn hình ở request kế tiếp', function (Closure $next) {
    $manager = User::factory()->withRole(Role::Manager)->create();

    $this->actingAs($manager, 'web');

    $before = [
        'snapshot' => spec109Snapshot($this->get(Performance::getUrl(panel: 'admin'))->assertOk()->getContent(), Performance::class),
        'download' => $this->document->downloadUrlFor($manager),
    ];

    Livewire::actingAs($this->admin, 'web')
        ->test(EditUser::class, ['record' => $manager->getKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($manager->fresh()->is_active)->toBeFalse();

    $this->actingAs($manager->fresh(), 'web');

    $next($before);

    expect(auth('web')->check())->toBeFalse('phiên nhân sự vẫn còn đăng nhập sau request kế tiếp');
})->with('spec109 staff next requests');

/** Cặp dương cho nhân sự: tài khoản còn hoạt động đi qua cùng các request và còn đăng nhập. */
it('giữ nguyên phiên nhân sự còn hoạt động', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();

    $this->actingAs($manager, 'web');
    $snapshot = spec109Snapshot($this->get(Performance::getUrl(panel: 'admin'))->assertOk()->getContent(), Performance::class);

    $this->get(IntakeRequestResource::getUrl('index', panel: 'admin'))->assertOk();
    $this->get('/admin/team')->assertOk();
    spec109LivewireUpdate($snapshot)->assertOk();
    $this->postJson('/admin/push/subscriptions', spec109PushBody('nhan-su-con'))->assertCreated();
    $this->get($this->document->downloadUrlFor($manager))->assertOk();

    expect(auth('web')->check())->toBeTrue();
});
