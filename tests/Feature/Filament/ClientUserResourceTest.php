<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Admin\Resources\ClientUsers\ClientUserResource;
use App\Filament\Admin\Resources\ClientUsers\Pages\CreateClientUser;
use App\Filament\Admin\Resources\ClientUsers\Pages\ListClientUsers;
use App\Filament\Admin\Support\VisibleClientOptions;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

/**
 * Review fix round 1, Important #3: SPEC §5 cho kế toán "—" ở cả client.manage lẫn
 * clientUser.manage. matter.viewAny (thứ duy nhất kế toán có) bỏ qua điều kiện đội ngũ trong
 * scopeListableBy nên từng lọt gần như toàn bộ tài khoản portal — số điện thoại, email — cho một
 * vai trò SPEC không cấp quyền đó. canAccess() giờ không còn xét matter.viewAny, nên kế toán bị
 * chặn ngay ở trang danh sách, không phải chỉ lọc dòng.
 */
it('hides the client user list from the accountant entirely', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    // Panel từ chối bằng 404 (SPEC §10.10, xem DenialCodeTest và
    // AnswerDeniedPanelRequestsWithNotFound).
    $this->actingAs($accountant, 'web')->get(ClientUserResource::getUrl('index', panel: 'admin'))->assertNotFound();
});

/**
 * Không vai trò nào trong 5 vai SPEC §5 vừa có matter.view vừa thiếu clientUser.manage (Lawyer,
 * Assistant, Manager, Admin đều có cả hai; Accountant có matter.viewAny chứ không phải
 * matter.view). Nhánh whereHas('client.matters', listableBy) của
 * ClientUserResource::getEloquentQuery() vẫn là code thật cần phủ — gán thẳng quyền
 * matter.view (không qua vai trò) để kiểm tra đúng nhánh đó, không phụ thuộc một vai trò tương
 * lai nào đó cấp matter.view mà không cấp clientUser.manage.
 */
it('excludes a client user of a client the actor has no matter with, for an actor who can list matters but not manage client users', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo(Permission::MatterView->value);

    $withMatter = Client::factory()->create();
    Matter::factory()->create(['client_id' => $withMatter->id, 'lead_lawyer_id' => $actor->id]);
    $visibleUser = ClientUser::factory()->for($withMatter)->create();

    $withoutMatter = Client::factory()->create();
    $hiddenUser = ClientUser::factory()->for($withoutMatter)->create();

    $this->actingAs($actor, 'web');

    $this->livewire(ListClientUsers::class)
        ->assertCanSeeTableRecords([$visibleUser])
        ->assertCanNotSeeTableRecords([$hiddenUser]);
});

it('lets a lawyer with clientUser.manage see every client user regardless of matter team', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $clientUser = ClientUser::factory()->create();

    $this->actingAs($lawyer, 'web');

    $this->livewire(ListClientUsers::class)
        ->assertCanSeeTableRecords([$clientUser]);
});

it('hides the client user create page from the accountant', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    // Panel từ chối bằng 404 (SPEC §10.10, xem DenialCodeTest và
    // AnswerDeniedPanelRequestsWithNotFound).
    $this->actingAs($accountant, 'web')->get(ClientUserResource::getUrl('create', panel: 'admin'))->assertNotFound();
});

/**
 * Review fix round 1, Important #2: VisibleClientOptions — dùng chung bởi ClientUserForm và
 * ClientUsersTable's filter — phải loại đúng khách hàng ngoài tầm nhìn của actor, không chỉ
 * "trông giống đúng" ở hai nơi gọi nó.
 */
it("excludes a client the lawyer has no matter with from the client-user form's client options", function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $ownClient = Client::factory()->create();
    Matter::factory()->create(['client_id' => $ownClient->id, 'lead_lawyer_id' => $lawyer->id]);

    $strangerClient = Client::factory()->create();

    $this->actingAs($lawyer, 'web');

    $options = VisibleClientOptions::forCurrentUser();

    expect($options)->toHaveKey($ownClient->id)
        ->and($options)->not->toHaveKey($strangerClient->id);
});

/**
 * Review fix round 1, Important #2 (nửa còn lại — write side, phần 1): trước khi tới
 * CreateClientUser::mutateFormDataBeforeCreate(), Filament tự validate submitted state của một
 * Select khớp với chính options() đã khai báo (options() cũng chạy lại phía server ở mỗi
 * request, dùng Auth::user() thật — không tin dữ liệu client gửi lên) — tự xác nhận bằng cách
 * thử gửi thẳng client_id ngoài tầm nhìn (bỏ qua UI, giả lập request bị chỉnh sửa): bị chặn ngay
 * ở bước validate form, chưa cần tới lớp Gate/policy bên dưới.
 */
it('refuses to create a client user for a client outside the lawyers reach even when the request is tampered with', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $strangerClient = Client::factory()->create();

    $this->actingAs($lawyer, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(CreateClientUser::class)
        ->fillForm([
            'client_id' => $strangerClient->id,
            'name' => 'Tài khoản giả mạo',
            'email' => 'gia-mao@example.com',
            'password' => 'password',
        ])
        ->call('create')
        ->assertHasFormErrors(['client_id']);

    expect(ClientUser::where('email', 'gia-mao@example.com')->exists())->toBeFalse();
});

/**
 * Review fix round 1, Important #2 (nửa còn lại — write side, phần 2): lớp validate của Select
 * ở test trên đủ để chặn con đường tấn công duy nhất hiện có qua chính form này, nhưng
 * mutateFormDataBeforeCreate() là lớp phòng thủ độc lập bên dưới nó — không phụ thuộc field đó
 * mãi mãi ở dạng Select tĩnh (searchable() với option cố định vẫn validate được; một
 * getSearchResultsUsing() từ xa sau này sẽ không). Gọi thẳng hook đó qua reflection, bỏ qua toàn
 * bộ vòng Livewire/Select, để chứng minh chính code trong hook thật sự chặn, không phải "chết"
 * (không bao giờ chạy tới) đằng sau lớp validate ở trên.
 */
it('the create-page mutate hook itself rejects a client_id outside the lawyers reach, independent of form validation', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $strangerClient = Client::factory()->create();

    $this->actingAs($lawyer, 'web');
    Filament::setCurrentPanel('admin');

    $page = new CreateClientUser;
    $method = new ReflectionMethod($page, 'mutateFormDataBeforeCreate');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($page, ['client_id' => $strangerClient->id]))
        ->toThrow(AuthorizationException::class);
});

/** Kiểm tra trực tiếp ability đã siết (bỏ qua toàn bộ vòng Livewire), độc lập với UI/form. */
it('directly denies the create ability for a client outside reach and allows it for one within reach', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $strangerClient = Client::factory()->create();
    $ownClient = Client::factory()->create();
    Matter::factory()->create(['client_id' => $ownClient->id, 'lead_lawyer_id' => $lawyer->id]);

    expect($lawyer->can('create', [ClientUser::class, $strangerClient]))->toBeFalse()
        ->and($lawyer->can('create', [ClientUser::class, $ownClient]))->toBeTrue()
        // Không kèm $client (đúng cách Filament tự gọi canCreate() để quyết định hiện nút "Tạo"):
        // vẫn true vì lawyer có clientUser.manage — không phải nơi chặn theo từng khách hàng.
        ->and($lawyer->can('create', ClientUser::class))->toBeTrue();
});

it('lets a lawyer create a client user for a client of a matter they can see', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $ownClient = Client::factory()->create();
    Matter::factory()->create(['client_id' => $ownClient->id, 'lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(CreateClientUser::class)
        ->fillForm([
            'client_id' => $ownClient->id,
            'name' => 'Tài khoản hợp lệ',
            'email' => 'hop-le@example.com',
            'password' => 'password',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ClientUser::where('email', 'hop-le@example.com')->where('client_id', $ownClient->id)->exists())->toBeTrue();
});
