<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Admin\Resources\ClientUsers\ClientUserResource;
use App\Filament\Admin\Resources\ClientUsers\Pages\CreateClientUser;
use App\Filament\Admin\Resources\ClientUsers\Pages\EditClientUser;
use App\Filament\Admin\Resources\ClientUsers\Pages\ListClientUsers;
use App\Filament\Admin\Support\VisibleClientOptions;
use App\Filament\Portal\Pages\Auth\Login;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;
use App\Policies\ClientUserPolicy;
use App\Support\Audit;
use App\Support\PortalLoginThrottle;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Activitylog\Models\Activity;

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

/**
 * Task 2 (`roles/roles-02`, important) — ĐẢO NGƯỢC test cũ cùng vị trí, từng có tên
 * "lets a lawyer with clientUser.manage see every client user regardless of matter team" và ghim
 * đúng hành vi rò rỉ mà finding này báo: Lawyer có `clientUser.manage` (Role.php) nhưng KHÔNG có
 * `client.manage`, nên trước bản sửa này họ đọc được tên/email/điện thoại của MỌI tài khoản cổng
 * trong văn phòng qua đúng bảng này — con đường mà ClientResource/VisibleClientOptions đã chặn ở
 * trang Khách hàng bên cạnh vẫn còn hở ở đây. `ClientUserResource::getEloquentQuery()` giờ đổi
 * mốc "thấy tất cả" sang `client.manage`, nên khẳng định đúng bây giờ là NGƯỢC LẠI: ẩn.
 */
it('hides a client user of a client the lawyer has no matter with, even though the lawyer has clientUser.manage', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $clientUser = ClientUser::factory()->create();

    $this->actingAs($lawyer, 'web');

    $this->livewire(ListClientUsers::class)
        ->assertCanNotSeeTableRecords([$clientUser]);
});

/** Vế dương của test trên: cùng luật sư đó vẫn thấy tài khoản cổng của khách MÌNH liệt kê được. */
it('still shows a lawyer the client user of a client they do have a matter with', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $ownClient = Client::factory()->create();
    Matter::factory()->create(['client_id' => $ownClient->id, 'lead_lawyer_id' => $lawyer->id]);
    $clientUser = ClientUser::factory()->for($ownClient)->create();

    $this->actingAs($lawyer, 'web');

    $this->livewire(ListClientUsers::class)
        ->assertCanSeeTableRecords([$clientUser]);
});

/**
 * Cùng finding, phần SPEC §4.7 nêu tên rõ nhất: khách của một vụ HẠN CHẾ. Trước bản sửa này bảng
 * này đọc được cả tên "Khách Bí Mật Đặc Biệt" của roles-02, dù `Gate::allows('view', $matter)`
 * của chính vụ đó đã là `false` với luật sư đang xem.
 */
it('hides a client user of a client whose only matter is restricted and led by someone else', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $otherLawyer = User::factory()->withRole(Role::Lawyer)->create();

    $secretClient = Client::factory()->create(['name' => 'Khách Bí Mật Đặc Biệt']);
    Matter::factory()->restricted()->create(['client_id' => $secretClient->id, 'lead_lawyer_id' => $otherLawyer->id]);
    $secretAccount = ClientUser::factory()->for($secretClient)->create();

    $this->actingAs($lawyer, 'web');

    $this->livewire(ListClientUsers::class)
        ->assertCanNotSeeTableRecords([$secretAccount])
        ->assertDontSee('Khách Bí Mật Đặc Biệt');
});

/** Manager có cả `client.manage`, nên vẫn thấy toàn bộ văn phòng như trước bản sửa này. */
it('still lets a manager with client.manage see every client user regardless of matter team', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $clientUser = ClientUser::factory()->create();

    $this->actingAs($manager, 'web');

    $this->livewire(ListClientUsers::class)
        ->assertCanSeeTableRecords([$clientUser]);
});

/**
 * `ClientUserResource` không đăng ký trang hay action nào gọi ability `view` (chỉ `index`,
 * `create`, `edit` — xem `getPages()`), nên nhánh mới của `ClientUserPolicy::view()` không có
 * màn hình nào tự đo được nó. Đo thẳng qua `Gate`, cùng lý do và cùng thành ngữ với test
 * `directly denies the update ability…` ở dưới: một lớp phòng thủ độc lập với UI hiện tại vẫn
 * phải đúng, vì đây là ability mà bất kỳ code nào khác (kể cả một ViewAction thêm sau này) sẽ
 * gọi lại.
 *
 * Mutation probe: đổi vế `ClientManage` trong `view()` lại thành `ClientUserManage` (bản cũ) thì
 * test này đỏ.
 */
it('directly denies the view ability for a client-user outside reach and allows it within reach', function () {
    $lawyerA = User::factory()->withRole(Role::Lawyer)->create();
    $lawyerB = User::factory()->withRole(Role::Lawyer)->create();

    $clientB = Client::factory()->create();
    Matter::factory()->create(['client_id' => $clientB->id, 'lead_lawyer_id' => $lawyerB->id]);
    $accountB = ClientUser::factory()->for($clientB)->create();

    expect($lawyerA->can('view', $accountB))->toBeFalse()
        ->and($lawyerB->can('view', $accountB))->toBeTrue();
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

// =========================================================================================
// Task 2 (`roles/roles-01`, critical): cách ly tài khoản cổng giữa các khách hàng
// =========================================================================================

/**
 * Luật sư A mở trang sửa tài khoản cổng của khách của luật sư B. Trước bản sửa này, trang này mở
 * bình thường (200, kèm cả email của khách B trong HTML) vì `ClientUserPolicy::update()` chỉ gọi
 * `create($user)` KHÔNG kèm khách hàng, nên bất kỳ ai có `clientUser.manage` đều sửa được MỌI tài
 * khoản. Giờ `update()` hỏi lại đúng client_id hiện tại của bản ghi (qua `view()`), nên luật sư A
 * không liệt kê được khách của B thì trang trả 404 (SPEC §10.10, AnswerDeniedPanelRequestsWithNotFound).
 */
it('answers 404 when a lawyer opens the edit page of another lawyers client-user account', function () {
    $lawyerA = User::factory()->withRole(Role::Lawyer)->create();
    $lawyerB = User::factory()->withRole(Role::Lawyer)->create();

    $clientB = Client::factory()->create();
    Matter::factory()->create(['client_id' => $clientB->id, 'lead_lawyer_id' => $lawyerB->id]);
    $accountB = ClientUser::factory()->for($clientB)->create();

    $this->actingAs($lawyerA, 'web');

    $this->get(ClientUserResource::getUrl('edit', ['record' => $accountB], panel: 'admin'))->assertNotFound();
});

/** Vế dương: cùng luật sư A mở được trang sửa tài khoản cổng của khách CHÍNH MÌNH. */
it('lets a lawyer open the edit page of their own clients client-user account', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $ownClient = Client::factory()->create();
    Matter::factory()->create(['client_id' => $ownClient->id, 'lead_lawyer_id' => $lawyer->id]);
    $account = ClientUser::factory()->for($ownClient)->create();

    $this->actingAs($lawyer, 'web');

    $this->get(ClientUserResource::getUrl('edit', ['record' => $account], panel: 'admin'))->assertOk();
});

/**
 * `ClientUserResource::getEloquentQuery()` (roles-02) đã đủ để chặn 404 ở test trên — route
 * model binding không tìm thấy bản ghi ngoài tầm nhìn thì `update()`/`canEdit()` không bao giờ
 * được hỏi tới. Test này đo THẲNG `ClientUserPolicy::update()` qua `Gate`, bỏ qua route binding
 * và toàn bộ vòng Livewire, để chứng minh chính ability đó — không phải chỉ tầng truy vấn đứng
 * trước nó — mới là thứ từ chối (carry-forward review M2/M3: `ClientUserPolicy::view` chạy
 * exists() cho mỗi bản ghi, nên một nơi khác gọi `Gate::authorize('update', $record)` trực tiếp,
 * bỏ qua getEloquentQuery(), vẫn phải đúng).
 *
 * Mutation probe: đổi lại `update()` thành `return $this->create($user);` (bản cũ) thì test này
 * đỏ — `create($user)` không kèm $client trả `true` cho bất kỳ ai có `clientUser.manage`.
 */
it('directly denies the update ability for a client-user outside reach and allows it within reach', function () {
    $lawyerA = User::factory()->withRole(Role::Lawyer)->create();
    $lawyerB = User::factory()->withRole(Role::Lawyer)->create();

    $clientB = Client::factory()->create();
    Matter::factory()->create(['client_id' => $clientB->id, 'lead_lawyer_id' => $lawyerB->id]);
    $accountB = ClientUser::factory()->for($clientB)->create();

    expect($lawyerA->can('update', $accountB))->toBeFalse()
        ->and($lawyerB->can('update', $accountB))->toBeTrue();
});

/**
 * Trọng tâm của roles-01: lưu form sửa tài khoản của CHÍNH khách mình, với một `client_id` KHÁC
 * trong payload — cố ý là một khách khác mà luật sư CŨNG liệt kê được (không phải một id ngoài
 * tầm với), đúng hình dạng cuộc tấn công gốc: chọn khách của CHÍNH MÌNH làm đích, thứ Select's
 * own "in:options" validation không chặn được vì giá trị đó hợp lệ với chính luật sư này. Chỉ
 * `EditClientUser::mutateFormDataBeforeSave()` mới chặn được, bằng cách bỏ qua hẳn client_id
 * trong $data và luôn ghi đè lại giá trị hiện có trên bản ghi.
 *
 * Mutation probe: bỏ dòng `$data['client_id'] = $this->record->client_id;` (trả `return $data;`
 * trần như bản cũ) thì test này đỏ — client_id đổi thật sang $otherOwnClient.
 */
it('never changes client_id on save, even when the payload carries a different client the lawyer can also reach', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $ownClient = Client::factory()->create();
    Matter::factory()->create(['client_id' => $ownClient->id, 'lead_lawyer_id' => $lawyer->id]);
    $account = ClientUser::factory()->for($ownClient)->create();

    $otherOwnClient = Client::factory()->create();
    Matter::factory()->create(['client_id' => $otherOwnClient->id, 'lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(EditClientUser::class, ['record' => $account->getKey()])
        ->fillForm([
            'client_id' => $otherOwnClient->id,
            'name' => $account->name,
            'email' => $account->email,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($account->fresh()->client_id)->toBe($ownClient->id);
});

/**
 * Cùng bất biến, đo thẳng vào hook — độc lập với Select/`disabled()` (đúng thành ngữ của tệp này
 * cho `CreateClientUser::mutateFormDataBeforeCreate()` ở trên): gọi
 * `EditClientUser::mutateFormDataBeforeSave()` qua reflection với một payload đã "chỉnh sửa tay",
 * bỏ qua toàn bộ vòng Livewire/Select.
 */
it('the edit-page mutate hook itself restores the original client_id, independent of form validation', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $ownClient = Client::factory()->create();
    Matter::factory()->create(['client_id' => $ownClient->id, 'lead_lawyer_id' => $lawyer->id]);
    $account = ClientUser::factory()->for($ownClient)->create();

    $otherOwnClient = Client::factory()->create();
    Matter::factory()->create(['client_id' => $otherOwnClient->id, 'lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');
    Filament::setCurrentPanel('admin');

    $page = new EditClientUser;
    $page->record = $account;

    $method = new ReflectionMethod($page, 'mutateFormDataBeforeSave');
    $method->setAccessible(true);

    $result = $method->invoke($page, ['client_id' => $otherOwnClient->id, 'name' => 'Tên đã sửa']);

    expect($result['client_id'])->toBe($ownClient->id);
});

// =========================================================================================
// Task 7 (R12, `intake/intake-04`, `intake/intake-05`): must_change_password luôn true, mật khẩu
// tạm theo PasswordRule::default(), activated_at chỉ hệ thống ghi
// =========================================================================================

/**
 * Ô mật khẩu ở form admin trước bản sửa này không có luật độ mạnh nào (chỉ required/maxLength),
 * trong khi cổng khách (ChangePassword) đã dùng PasswordRule::default(). Cùng một luật ở cả hai
 * nơi khách/nhân sự đặt mật khẩu cho tài khoản cổng.
 */
it('rejects a weak temporary password on the create form, the same strength rule as the portal', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $ownClient = Client::factory()->create();
    Matter::factory()->create(['client_id' => $ownClient->id, 'lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(CreateClientUser::class)
        ->fillForm([
            'client_id' => $ownClient->id,
            'name' => 'Tài khoản mật khẩu yếu',
            'email' => 'mat-khau-yeu@example.com',
            'password' => '1',
        ])
        ->call('create')
        ->assertHasFormErrors(['password']);

    expect(ClientUser::where('email', 'mat-khau-yeu@example.com')->exists())->toBeFalse();
});

/**
 * R12: "must_change_password luôn là true khi tạo… Không còn công tắc trên form." Trước bản sửa
 * này có một Toggle mà nhân sự tắt được ngay lúc tạo — khi đó khách dùng mãi một mật khẩu do nhân
 * sự chọn và biết, và cánh cổng SPEC §8.1 "lần đầu đăng nhập bắt buộc đổi mật khẩu" bị vượt qua
 * ngay từ form quản trị (phát hiện `intake/intake-05`). Cũng không còn ô activated_at để nhân sự
 * gõ tay (chỉ hệ thống ghi, xem ChangePasswordTest ở LoginTest.php).
 */
it('has no must_change_password toggle or activated_at field on the create form', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $ownClient = Client::factory()->create();
    Matter::factory()->create(['client_id' => $ownClient->id, 'lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(CreateClientUser::class)
        ->assertFormFieldDoesNotExist('must_change_password')
        ->assertFormFieldDoesNotExist('activated_at');
});

/**
 * Vế "payload giả gửi false thì vẫn lưu true" của test bắt buộc: gỡ Toggle khỏi schema đã chặn
 * đường tấn công qua UI (test trên), nhưng đây là lớp phòng thủ ĐỘC LẬP bên dưới — cùng thành ngữ
 * `mutateFormDataBeforeCreate` đã dùng cho `client_id` ở trên (Task 2): gọi thẳng hook qua
 * reflection với một payload đã "chỉnh sửa tay", bỏ qua toàn bộ vòng Livewire/schema.
 *
 * Mutation probe: xoá dòng `$data['must_change_password'] = true;` (giữ nguyên $data như cũ) thì
 * test này đỏ — trả lại `false`.
 */
it('the create-page mutate hook forces must_change_password true, independent of whatever the payload carries', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $ownClient = Client::factory()->create();
    Matter::factory()->create(['client_id' => $ownClient->id, 'lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');
    Filament::setCurrentPanel('admin');

    $page = new CreateClientUser;
    $method = new ReflectionMethod($page, 'mutateFormDataBeforeCreate');
    $method->setAccessible(true);

    $result = $method->invoke($page, [
        'client_id' => $ownClient->id,
        'must_change_password' => false,
    ]);

    expect($result['must_change_password'])->toBeTrue();
});

/** Đầu-cuối thật qua Livewire: tạo xong, phải đọc lại bản ghi và thấy must_change_password=true. */
it('always saves must_change_password true from the create form, regardless of the toggle that no longer exists', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $ownClient = Client::factory()->create();
    Matter::factory()->create(['client_id' => $ownClient->id, 'lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(CreateClientUser::class)
        ->fillForm([
            'client_id' => $ownClient->id,
            'name' => 'Tài khoản mới',
            'email' => 'tai-khoan-moi-2026@example.com',
            'password' => 'MatKhauTamThoi2026',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = ClientUser::where('email', 'tai-khoan-moi-2026@example.com')->firstOrFail();

    expect($created->must_change_password)->toBeTrue()
        ->and($created->activated_at)->toBeNull();
});

it('has no must_change_password toggle or activated_at field on the edit form either', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $ownClient = Client::factory()->create();
    Matter::factory()->create(['client_id' => $ownClient->id, 'lead_lawyer_id' => $lawyer->id]);
    $account = ClientUser::factory()->for($ownClient)->activated()->create();

    $this->actingAs($lawyer, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(EditClientUser::class, ['record' => $account->getKey()])
        ->assertFormFieldDoesNotExist('must_change_password')
        ->assertFormFieldDoesNotExist('activated_at');
});

/**
 * R12: "khi nhân sự đặt lại mật khẩu" thì must_change_password bật lại — trước bản sửa này, đặt
 * lại mật khẩu qua trang sửa không tự bật lại cờ này (nửa còn lại của `intake/intake-05`).
 */
it('turns must_change_password back on when staff resets the password on the edit form', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $ownClient = Client::factory()->create();
    Matter::factory()->create(['client_id' => $ownClient->id, 'lead_lawyer_id' => $lawyer->id]);
    $account = ClientUser::factory()->for($ownClient)->activated()->create();

    expect($account->must_change_password)->toBeFalse();

    $this->actingAs($lawyer, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(EditClientUser::class, ['record' => $account->getKey()])
        ->fillForm([
            'name' => $account->name,
            'email' => $account->email,
            'password' => 'MatKhauTamMoi2026',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($account->fresh()->must_change_password)->toBeTrue();
});

/**
 * Twin âm của test trên: sửa các trường KHÁC mà không đổi mật khẩu thì không đụng tới
 * must_change_password — không phải mọi lần lưu trang sửa đều là một lần "đặt lại mật khẩu".
 *
 * Mutation probe: đổi điều kiện `filled($data['password'] ?? null)` thành luôn `true` (bật lại
 * must_change_password ở MỌI lần lưu) thì test này đỏ.
 */
it('leaves must_change_password alone when the edit form saves without touching the password', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $ownClient = Client::factory()->create();
    Matter::factory()->create(['client_id' => $ownClient->id, 'lead_lawyer_id' => $lawyer->id]);
    $account = ClientUser::factory()->for($ownClient)->activated()->create();

    $this->actingAs($lawyer, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(EditClientUser::class, ['record' => $account->getKey()])
        ->fillForm([
            'name' => 'Tên đã đổi, không đổi mật khẩu',
            'email' => $account->email,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($account->fresh()->must_change_password)->toBeFalse();
});

// =========================================================================================
// Task 2, vòng sửa 1 (Important #3): ClientUserPolicy::deleteAny()/restoreAny()/forceDeleteAny()
// + ClientUsersTable::toolbarActions() authorizeIndividualRecords()
// =========================================================================================

/**
 * Trước bản sửa này, `ClientUserPolicy` không định nghĩa `deleteAny()`/`restoreAny()`/
 * `forceDeleteAny()` — cùng lỗ hổng hệt `ClientPolicy` (đọc docblock `ClientPolicy::deleteAny()`).
 * Một luật sư (không phải admin, `can('delete', $account)` = `false` khi hỏi thẳng) vẫn xoá hàng
 * loạt trót lọt được tài khoản cổng của khách BẤT KỲ qua `ListClientUsers`, kể cả khách ngoài tầm
 * quản lý của mình.
 */
it('hides every bulk action from a lawyer, who is not an admin', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $ownClient = Client::factory()->create();
    Matter::factory()->create(['client_id' => $ownClient->id, 'lead_lawyer_id' => $lawyer->id]);
    ClientUser::factory()->for($ownClient)->create();

    $this->actingAs($lawyer, 'web');

    // `filterTable('trashed', true)` bắt buộc, không phải trang trí — cùng lý do đã giải thích
    // trong `ClientResourceTest`: `RestoreBulkAction`/`ForceDeleteBulkAction` của Filament tự ẩn
    // khi bộ lọc "đã xoá" ở giá trị mặc định, bất kể quyền, nên thiếu dòng này thì hai khẳng định
    // dưới xanh dù `restoreAny()`/`forceDeleteAny()` không hề tồn tại.
    $this->livewire(ListClientUsers::class)
        ->filterTable('trashed', true)
        ->assertTableBulkActionHidden('delete')
        ->assertTableBulkActionHidden('restore')
        ->assertTableBulkActionHidden('forceDelete');
});

/** Lớp phòng thủ thứ hai, độc lập với việc ẩn nút: hỏi thẳng ability, bỏ qua toàn bộ UI. */
it('directly denies deleteAny for a lawyer and allows it for the admin', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();

    expect($lawyer->can('deleteAny', ClientUser::class))->toBeFalse()
        ->and($admin->can('deleteAny', ClientUser::class))->toBeTrue();
});

/** Vế dương: admin vẫn thấy nút xoá hàng loạt và xoá được như trước bản sửa này. */
it('lets the admin bulk delete a client-user account', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $account = ClientUser::factory()->create();

    $this->actingAs($admin, 'web');

    $this->livewire(ListClientUsers::class)
        ->assertTableBulkActionVisible('delete')
        ->callTableBulkAction('delete', [$account]);

    expect($account->fresh()->trashed())->toBeTrue();
});

/** Vế dương của restore: admin khôi phục hàng loạt một tài khoản đã xoá mềm. */
it('lets the admin bulk restore a soft deleted client-user account', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $account = ClientUser::factory()->create();
    $account->delete();

    $this->actingAs($admin, 'web');

    $this->livewire(ListClientUsers::class)
        ->filterTable('trashed', true)
        ->callTableBulkAction('restore', [$account]);

    expect($account->fresh()->trashed())->toBeFalse();
});

/**
 * Không ai xoá vĩnh viễn một tài khoản cổng được, kể cả admin: trước bản sửa này, bộ lọc "đã xoá"
 * cộng xoá vĩnh viễn hàng loạt cuốn theo cả `stage_log_views` (sổ "đã xem" — bằng chứng khách đã
 * đọc một cập nhật) lẫn `client_requests` liên đới (cascade), không qua bất kỳ policy nào.
 */
it('hides the bulk force-delete action from everyone, including the admin', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();

    $this->actingAs($admin, 'web');

    $this->livewire(ListClientUsers::class)
        ->filterTable('trashed', true)
        ->assertTableBulkActionHidden('forceDelete');
});

/**
 * Task 2, vòng sửa 1 (Important #3) — bằng chứng riêng cho việc `->authorizeIndividualRecords('delete')`
 * thật sự CÓ TÁC DỤNG, không chỉ tồn tại trên dòng code: `ClientUserPolicy::delete()` và
 * `deleteAny()` hiện tại đều là "chỉ admin", nên hai test "lets the admin bulk delete..."/"hides
 * every bulk action from a lawyer" ở trên KHÔNG phân biệt được việc lọc per-record có chạy hay
 * không — cùng đúng kết quả dù xoá hẳn `authorizeIndividualRecords('delete')` (đã tự kiểm bằng
 * đột biến thật, xem báo cáo). Test này giả một `ClientUserPolicy::delete()` từ chối một bản ghi
 * CỤ THỂ trong khi `deleteAny()` (cổng nút) vẫn cho phép — đúng hình dạng một điều kiện per-record
 * trong tương lai (vd. tài khoản đang giữ bằng chứng "đã xem" một tài liệu đang tranh chấp) — và
 * đo thẳng: bản ghi bị `delete()` từ chối phải SỐNG SÓT qua bulk delete, bản ghi còn lại vẫn mất.
 *
 * `makePartial()`: chỉ `delete()` bị giả, mọi ability khác (`deleteAny`, `update` cho
 * `EditAction` trên mỗi dòng…) vẫn chạy đúng code thật.
 */
it('authorizes each record individually against ClientUserPolicy::delete(), not only the deleteAny() button gate', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $protected = ClientUser::factory()->create();
    $deletable = ClientUser::factory()->create();

    $policy = Mockery::mock(ClientUserPolicy::class)->makePartial();
    $policy->shouldReceive('delete')
        ->withArgs(fn ($user, $record) => $record->is($protected))
        ->andReturn(false);
    $policy->shouldReceive('delete')
        ->withArgs(fn ($user, $record) => $record->is($deletable))
        ->andReturn(true);

    app()->instance(ClientUserPolicy::class, $policy);

    $this->actingAs($admin, 'web');

    $this->livewire(ListClientUsers::class)
        ->callTableBulkAction('delete', [$protected, $deletable]);

    expect($protected->fresh()->trashed())->toBeFalse()
        ->and($deletable->fresh()->trashed())->toBeTrue();
});

/** Cùng lý do và cùng kỹ thuật với test trên, cho `RestoreBulkAction::authorizeIndividualRecords('restore')`. */
it('authorizes each record individually against ClientUserPolicy::restore(), not only the restoreAny() button gate', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $stillLocked = ClientUser::factory()->create();
    $stillLocked->delete();
    $restorable = ClientUser::factory()->create();
    $restorable->delete();

    $policy = Mockery::mock(ClientUserPolicy::class)->makePartial();
    $policy->shouldReceive('restore')
        ->withArgs(fn ($user, $record) => $record->is($stillLocked))
        ->andReturn(false);
    $policy->shouldReceive('restore')
        ->withArgs(fn ($user, $record) => $record->is($restorable))
        ->andReturn(true);

    app()->instance(ClientUserPolicy::class, $policy);

    $this->actingAs($admin, 'web');

    $this->livewire(ListClientUsers::class)
        ->filterTable('trashed', true)
        ->callTableBulkAction('restore', [$stillLocked, $restorable]);

    expect($stillLocked->fresh()->trashed())->toBeTrue()
        ->and($restorable->fresh()->trashed())->toBeFalse();
});

// =========================================================================================
// Task 7 (R12, phát hiện `portal/portal-4`): nút "Mở khoá đăng nhập" trên trang sửa tài khoản
// cổng — xoá khoá đếm THEO TÀI KHOẢN (App\Support\PortalLoginThrottle) và ghi audit.
// =========================================================================================

/**
 * Test bắt buộc của brief: "sau 5 lần sai, nhân sự bấm mở khoá, khách đăng nhập được ngay."
 *
 * Khoá CHỈ chiều TÀI KHOẢN trực tiếp qua RateLimiter (đúng khoá PortalLoginThrottle dùng thật) —
 * cố ý không đụng chiều IP — để phép đo cô lập đúng thứ Unlock sửa. Chiều IP có bài riêng bên
 * dưới ("tells staff the IP lock is still up…"), vì Unlock cố ý không đụng chiều đó (xem docblock
 * UnlockPortalLogin và PortalLoginThrottle::clearPasswordAccount()).
 */
it('lets a client sign in again immediately once staff unlocks the account, after five wrong passwords', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $ownClient = Client::factory()->create();
    Matter::factory()->create(['client_id' => $ownClient->id, 'lead_lawyer_id' => $lawyer->id]);
    $account = ClientUser::factory()->for($ownClient)->activated()->create();

    $accountKey = PortalLoginThrottle::passwordAccountKey($account->email);

    foreach (range(1, 5) as $ignored) {
        RateLimiter::hit($accountKey, PortalLoginThrottle::DECAY_SECONDS);
    }

    expect(PortalLoginThrottle::tooManyAttempts([$accountKey]))->toBeTrue();

    // Trước khi mở khoá: đúng mật khẩu vẫn bị chặn ngay ở bước mật khẩu.
    Filament::setCurrentPanel('portal');

    $this->livewire(Login::class)
        ->set('data.email', $account->email)
        ->set('data.password', 'password')
        ->call('authenticate')
        ->assertHasErrors(['data.email']);

    expect(auth('client')->check())->toBeFalse();

    // Nhân sự bấm "Mở khoá đăng nhập" trên trang sửa tài khoản.
    Filament::setCurrentPanel('admin');
    $this->actingAs($lawyer, 'web');

    $this->livewire(EditClientUser::class, ['record' => $account->getKey()])
        ->assertActionVisible('unlockLogin')
        ->callAction('unlockLogin');

    expect(PortalLoginThrottle::tooManyAttempts([$accountKey]))->toBeFalse();

    // Khách thử lại ngay: đúng mật khẩu đi qua được, không còn lỗi khoá tạm.
    Filament::setCurrentPanel('portal');

    $this->livewire(Login::class)
        ->set('data.email', $account->email)
        ->set('data.password', 'password')
        ->call('authenticate')
        ->assertHasNoErrors();
});

/**
 * R12: "Nếu khoá theo IP vẫn còn, câu trả về cho nhân sự nói rõ điều đó." Mở khoá cố ý CHỈ xoá
 * chiều tài khoản — dựng lại đúng năm dòng nhật ký `login_failed` từ MỘT địa chỉ mạng (đúng
 * nguồn UnlockPortalLogin đọc để biết địa chỉ nào vừa gây khoá) và khoá chiều IP của chính địa
 * chỉ đó, để chứng minh câu trả lời phản ánh đúng trạng thái thật.
 */
it('tells staff the IP lock is still up after unlocking the account, when the failures came from an IP still over the limit', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $ownClient = Client::factory()->create();
    Matter::factory()->create(['client_id' => $ownClient->id, 'lead_lawyer_id' => $lawyer->id]);
    $account = ClientUser::factory()->for($ownClient)->activated()->create();

    $ip = '198.51.100.77';

    foreach (range(1, 5) as $ignored) {
        Audit::record('login_failed', $account, [
            'guard' => 'client',
            'email' => $account->email,
            'ip' => $ip,
        ], $account);

        RateLimiter::hit(PortalLoginThrottle::passwordIpKeyFor($ip), PortalLoginThrottle::DECAY_SECONDS);
    }

    $this->actingAs($lawyer, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(EditClientUser::class, ['record' => $account->getKey()])
        ->callAction('unlockLogin')
        ->assertNotified(__('client_users.actions.unlock_login_success_ip_still_locked'));
});

/** Vế dương của test trên: không có khoá IP nào để báo thì câu trả lời là câu đơn giản. */
it('tells staff the plain unlock message when there is no IP lock to report', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $ownClient = Client::factory()->create();
    Matter::factory()->create(['client_id' => $ownClient->id, 'lead_lawyer_id' => $lawyer->id]);
    $account = ClientUser::factory()->for($ownClient)->activated()->create();

    $this->actingAs($lawyer, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(EditClientUser::class, ['record' => $account->getKey()])
        ->callAction('unlockLogin')
        ->assertNotified(__('client_users.actions.unlock_login_success'));
});

/** SPEC §10.6: xoá khoá thay mặt khách phải để lại dấu vết — ai bấm, cho tài khoản nào. */
it('writes an audit line when staff unlocks a portal account', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $ownClient = Client::factory()->create();
    Matter::factory()->create(['client_id' => $ownClient->id, 'lead_lawyer_id' => $lawyer->id]);
    $account = ClientUser::factory()->for($ownClient)->activated()->create();

    $this->actingAs($lawyer, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(EditClientUser::class, ['record' => $account->getKey()])
        ->callAction('unlockLogin');

    $activity = Activity::query()->where('event', 'portal_login_unlocked')->sole();

    expect($activity->causer)->toBeInstanceOf(User::class)
        ->and($activity->causer->is($lawyer))->toBeTrue()
        ->and($activity->subject)->toBeInstanceOf(ClientUser::class)
        ->and($activity->subject->is($account))->toBeTrue();
});

/**
 * Lớp phòng thủ thứ hai, độc lập với việc ẩn nút: hỏi thẳng `unlockLogin` qua `Gate`, bỏ qua toàn
 * bộ vòng Livewire — cùng thành ngữ "directly denies the update ability…" ở trên. Đây cũng là
 * ability mà `HeaderActionsAreReachableTest` đòi phải tồn tại trên `ClientUserPolicy` vì Action
 * "Mở khoá đăng nhập" mang đúng tên `unlockLogin`.
 *
 * Mutation probe: xoá `ClientUserPolicy::unlockLogin()` thì cả test này lẫn
 * `HeaderActionsAreReachableTest` đều đỏ (xem báo cáo).
 */
it('directly denies the unlockLogin ability for a client-user outside reach and allows it within reach', function () {
    $lawyerA = User::factory()->withRole(Role::Lawyer)->create();
    $lawyerB = User::factory()->withRole(Role::Lawyer)->create();

    $clientB = Client::factory()->create();
    Matter::factory()->create(['client_id' => $clientB->id, 'lead_lawyer_id' => $lawyerB->id]);
    $accountB = ClientUser::factory()->for($clientB)->activated()->create();

    expect($lawyerA->can('unlockLogin', $accountB))->toBeFalse()
        ->and($lawyerB->can('unlockLogin', $accountB))->toBeTrue();
});
