<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Admin\Resources\ClientUsers\ClientUserResource;
use App\Filament\Admin\Resources\ClientUsers\Pages\CreateClientUser;
use App\Filament\Admin\Resources\ClientUsers\Pages\EditClientUser;
use App\Filament\Admin\Resources\ClientUsers\Pages\ListClientUsers;
use App\Filament\Admin\Support\VisibleClientOptions;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;
use App\Policies\ClientUserPolicy;
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
