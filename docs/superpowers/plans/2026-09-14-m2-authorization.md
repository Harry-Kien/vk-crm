# VK-CRM M2 — Kế hoạch phân quyền và cách ly dữ liệu

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Không có cách nào để một khách hàng nhìn thấy dữ liệu của khách hàng khác hoặc tài liệu nội bộ, và nhân sự chỉ thấy đúng vụ việc mình có quyền; bảo đảm bằng global scope, policy và bộ test bắt buộc ở SPEC §11.

**Architecture:** Hai tầng bảo vệ độc lập, cả hai đều có test.

1. **Tầng truy vấn (global scope).** Khi ngữ cảnh là portal khách, mọi model nghiệp vụ tự thêm điều kiện giới hạn vào truy vấn. Model nào khách không được thấy thì scope trả về rỗng tuyệt đối. Nhờ vậy `Model::find()` trả `null` và Filament tự sinh 404 — đúng SPEC §10.10 ("không có quyền và không tồn tại đều trả 404").
2. **Tầng quyền (policy).** Nhân sự dùng `spatie/laravel-permission` với đúng 13 quyền ở SPEC §5, cộng kiểm tra `matter_user` và `confidentiality`. Khách hàng không dùng spatie: policy của khách hỏi lại chính tầng scope (`Model::whereKey(...)->exists()`), nên hai tầng không bao giờ lệch nhau.

Thêm một lớp mỏng thứ ba: các cột chỉ dành cho nội bộ (`matters.description_internal`, `stage_logs.internal_note`, `clients.note`) bị loại khỏi `toArray()`/JSON khi đang ở ngữ cảnh portal, để một view quên lọc cũng không rò rỉ.

**Tech Stack:** PHP 8.3, Laravel 13.31, Filament 5.8, `spatie/laravel-permission` ^8 (8.3.0 đã kiểm tra tương thích), Pest 4, Pint. Mọi lệnh qua `bin/dev`.

**Spec:** `docs/SPEC.md` §5 (toàn bộ: bảng quyền nhân sự, quy tắc portal), §4.6 (`confidentiality`), §4.7 (`matter_user` quyết định ai thấy vụ nào), §4.8 (`internal_note`), §4.11 (nhóm tài liệu), §10.10 (404 thay vì 403), §11 bốn mục "Cách ly dữ liệu giữa khách hàng", "Tài liệu nội bộ", "Ghi chú nội bộ", "Quyền nội bộ", §13 dòng M2. `docs/superpowers/specs/2026-09-13-vk-crm-design.md` §6 (global scope đặt ở `app/Support/Scopes/`). `docs/superpowers/specs/2026-09-14-vk-crm-toolchain.md` §2 (M2).

## Ràng buộc toàn cục

- PHP sàn **8.3**. Chỉ cài đúng một gói mới: `spatie/laravel-permission` ^8.
- **Không cài `bezhansalleh/filament-shield` ở M2.** Quyết định (ghi lại ở PROGRESS): Shield sinh quyền từ Filament Resource, mà M2 chưa có resource nào; 6 trong 13 quyền của SPEC §5 (`matter.transitionStage`, `stageLog.publish`, `document.viewInternal`, `checklist.review`, `settings.manage`, `auditLog.view`) không phải cặp resource-action nên Shield không sinh được. Tên quyền theo SPEC là nguồn sự thật. Xét lại Shield ở M3 và chỉ dùng phần giao diện gán vai trò, cấu hình để **không** tự sinh lại tên quyền.
- Không Redis, Horizon, Octane, Reverb, Pulse, Scout, Telescope.
- Định danh mã tiếng Anh. Chuỗi hiển thị tiếng Việt qua `__()` trong `lang/vi/`.
- Guard `web` dùng spatie. Guard `client` **không bao giờ** dùng spatie: quyền của khách là cố định, cài bằng scope + policy (SPEC §5).
- Global scope đặt ở `app/Support/Scopes/`, đăng ký qua trait trong `booted()` của model (design spec §6).
- **Không gắn scope portal lên `User` và `ClientUser`.** Hai model này là model xác thực; gọi `auth()` bên trong scope của chúng gây đệ quy vô hạn khi guard nạp người dùng từ session.
- Ngữ cảnh portal = `auth('client')->check() && ! auth('web')->check()`. Vế thứ hai là bắt buộc: hai panel dùng chung cookie phiên `vk-crm-session`, nên một nhân sự đăng nhập cả `/admin` lẫn `/portal` sẽ có cả hai guard cùng xác thực; không có vế này thì truy vấn ở `/admin` bị giới hạn sai. Chỉ định nghĩa điều kiện này **một chỗ** duy nhất: `ClientPortalScope::isActive()`.
- TDD với Pest: viết test đỏ trước. Kết thúc mỗi task: `bin/dev test` xanh, `bin/dev pint` sạch, commit.
- Commit message kết thúc bằng `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>` (chép nguyên văn, không thay tên model khác).
- Lệnh: `bin/dev test --filter=<Tên>`, `bin/dev test`, `bin/dev pint`, `bin/dev artisan migrate:fresh --seed`.

---

## Cấu trúc tệp (trạng thái cuối M2)

| Đường dẫn | Trách nhiệm |
|---|---|
| `app/Support/Scopes/ClientPortalScope.php` | Global scope + `isActive()` — định nghĩa duy nhất của "ngữ cảnh portal" |
| `app/Models/Concerns/RestrictedToClientPortal.php` | Trait đăng ký scope, khai báo `applyClientPortalConstraints()` |
| `app/Models/Concerns/HidesInternalAttributesFromPortal.php` | Loại cột nội bộ khỏi `toArray()` khi ở portal |
| `app/Enums/Role.php` | 5 vai trò nội bộ, có `label()` và `permissions()` |
| `app/Enums/Permission.php` | 13 quyền SPEC §5, có `label()` |
| `database/seeders/RolesAndPermissionsSeeder.php` | Tạo quyền, vai trò, gán quyền; idempotent |
| `app/Policies/*.php` | 11 policy (xem Task 5–6) |
| `app/Policies/Concerns/ChecksMatterAccess.php` | Hàm dùng chung cho policy của các model con của `Matter` |
| `lang/vi/roles.php`, `lang/vi/permissions.php` | Nhãn tiếng Việt |
| `config/permission.php` | Bản publish của spatie, không sửa |
| `tests/Feature/Authorization/*.php` | Bộ test SPEC §11 |

---

### Task 1: Cài spatie, enum vai trò và quyền, seeder

**Files:**
- Modify: `composer.json`, `composer.lock`
- Create: `config/permission.php` (publish), 1 migration của spatie
- Create: `app/Enums/Role.php`, `app/Enums/Permission.php`
- Create: `lang/vi/roles.php`, `lang/vi/permissions.php`
- Create: `database/seeders/RolesAndPermissionsSeeder.php`
- Modify: `app/Models/User.php` (trait `HasRoles`), `database/seeders/DatabaseSeeder.php`, `database/seeders/StaffSeeder.php`, `database/seeders/DemoAccountsSeeder.php`, `database/factories/UserFactory.php`
- Test: `tests/Feature/Authorization/RolesAndPermissionsTest.php`

**Interfaces:**
- Produces: `App\Enums\Role` (Admin, Manager, Lawyer, Assistant, Accountant — backed string khớp `UserPosition`) với `permissions(): list<Permission>` và `label()`. `App\Enums\Permission` 13 case backed string đúng tên SPEC §5. `User` dùng `HasRoles`, có `assignRoleFromPosition(): void`. `UserFactory::withRole(Role)`. Seeder `RolesAndPermissionsSeeder` chạy trước `StaffSeeder`.

- [ ] **Bước 1: Cài gói và publish**

```bash
bin/dev composer require spatie/laravel-permission:^8.0
bin/dev artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
```
Expected: `config/permission.php` và một migration `*_create_permission_tables.php` xuất hiện. Không sửa nội dung hai tệp này.

Sau đó `bin/dev artisan migrate` và `bin/dev test` — toàn bộ 77 test cũ phải vẫn xanh.

- [ ] **Bước 2: Viết test đỏ**

`tests/Feature/Authorization/RolesAndPermissionsTest.php`:
```php
<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserPosition;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('creates every role and permission from the spec', function () {
    expect(Spatie\Permission\Models\Role::count())->toBe(count(Role::cases()))
        ->and(Spatie\Permission\Models\Permission::count())->toBe(count(Permission::cases()))
        ->and(Spatie\Permission\Models\Permission::pluck('name')->sort()->values()->all())
        ->toBe(collect(Permission::cases())->map->value->sort()->values()->all());
});

it('grants the admin every permission and the accountant almost none', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    foreach (Permission::cases() as $permission) {
        expect($admin->can($permission->value))->toBeTrue("admin thiếu {$permission->value}");
    }

    expect($accountant->can(Permission::MatterViewAny->value))->toBeTrue()
        ->and($accountant->can(Permission::MatterView->value))->toBeFalse()
        ->and($accountant->can(Permission::MatterCreate->value))->toBeFalse()
        ->and($accountant->can(Permission::SettingsManage->value))->toBeFalse();
});

it('matches the spec permission table for every role', function () {
    $expected = [
        Role::Admin->value => [
            'matter.viewAny', 'matter.view', 'matter.create', 'matter.update', 'matter.transitionStage',
            'stageLog.publish', 'document.viewInternal', 'document.publish', 'checklist.review',
            'client.manage', 'clientUser.manage', 'settings.manage', 'auditLog.view',
        ],
        Role::Manager->value => [
            'matter.viewAny', 'matter.view', 'matter.create', 'matter.update', 'matter.transitionStage',
            'stageLog.publish', 'document.viewInternal', 'document.publish', 'checklist.review',
            'client.manage', 'clientUser.manage', 'auditLog.view',
        ],
        Role::Lawyer->value => [
            'matter.view', 'matter.create', 'matter.update', 'matter.transitionStage',
            'stageLog.publish', 'document.viewInternal', 'document.publish', 'checklist.review',
            'clientUser.manage',
        ],
        Role::Assistant->value => [
            'matter.view', 'matter.update', 'checklist.review', 'client.manage', 'clientUser.manage',
        ],
        Role::Accountant->value => ['matter.viewAny'],
    ];

    foreach ($expected as $roleName => $permissions) {
        $granted = Spatie\Permission\Models\Role::findByName($roleName)
            ->permissions->pluck('name')->sort()->values()->all();

        expect($granted)->toBe(collect($permissions)->sort()->values()->all(), "vai trò {$roleName} sai bộ quyền");
    }
});

it('assigns a role matching the staff position', function () {
    $user = User::factory()->create(['position' => UserPosition::Manager]);
    $user->assignRoleFromPosition();

    expect($user->hasRole(Role::Manager->value))->toBeTrue()
        ->and($user->hasRole(Role::Admin->value))->toBeFalse();
});

it('is idempotent', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Spatie\Permission\Models\Permission::count())->toBe(count(Permission::cases()));
});
```

- [ ] **Bước 3: Chạy test, mong đợi đỏ**

Run: `bin/dev test --filter=RolesAndPermissionsTest`
Expected: FAIL, `App\Enums\Role` không tồn tại.

- [ ] **Bước 4: Enum `Permission`**

`app/Enums/Permission.php`:
```php
<?php

namespace App\Enums;

/**
 * Đúng 13 quyền ở SPEC §5. Tên quyền là nguồn sự thật, không sinh tự động từ resource.
 */
enum Permission: string
{
    case MatterViewAny = 'matter.viewAny';
    case MatterView = 'matter.view';
    case MatterCreate = 'matter.create';
    case MatterUpdate = 'matter.update';
    case MatterTransitionStage = 'matter.transitionStage';
    case StageLogPublish = 'stageLog.publish';
    case DocumentViewInternal = 'document.viewInternal';
    case DocumentPublish = 'document.publish';
    case ChecklistReview = 'checklist.review';
    case ClientManage = 'client.manage';
    case ClientUserManage = 'clientUser.manage';
    case SettingsManage = 'settings.manage';
    case AuditLogView = 'auditLog.view';

    public function label(): string
    {
        return __('permissions.'.$this->value);
    }
}
```

`lang/vi/permissions.php`:
```php
<?php

return [
    'matter.viewAny' => 'Xem danh sách toàn bộ vụ việc',
    'matter.view' => 'Xem chi tiết vụ việc được phân công',
    'matter.create' => 'Mở vụ việc mới',
    'matter.update' => 'Sửa thông tin vụ việc',
    'matter.transitionStage' => 'Chuyển giai đoạn vụ việc',
    'stageLog.publish' => 'Công bố tiến độ cho khách hàng',
    'document.viewInternal' => 'Xem hồ sơ công việc nội bộ',
    'document.publish' => 'Công bố tài liệu cho khách hàng',
    'checklist.review' => 'Duyệt giấy tờ khách nộp',
    'client.manage' => 'Quản lý hồ sơ khách hàng',
    'clientUser.manage' => 'Quản lý tài khoản tra cứu của khách',
    'settings.manage' => 'Quản trị cấu hình hệ thống',
    'auditLog.view' => 'Xem nhật ký hệ thống',
];
```

- [ ] **Bước 5: Enum `Role`**

`app/Enums/Role.php`:
```php
<?php

namespace App\Enums;

/**
 * Vai trò nội bộ. Giá trị trùng với UserPosition để một nhân sự luôn có vai trò
 * khớp chức danh (xem User::assignRoleFromPosition). Bộ quyền lấy nguyên từ bảng SPEC §5.
 */
enum Role: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Lawyer = 'lawyer';
    case Assistant = 'assistant';
    case Accountant = 'accountant';

    public function label(): string
    {
        return __('roles.'.$this->value);
    }

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),
            self::Manager => [
                Permission::MatterViewAny,
                Permission::MatterView,
                Permission::MatterCreate,
                Permission::MatterUpdate,
                Permission::MatterTransitionStage,
                Permission::StageLogPublish,
                Permission::DocumentViewInternal,
                Permission::DocumentPublish,
                Permission::ChecklistReview,
                Permission::ClientManage,
                Permission::ClientUserManage,
                Permission::AuditLogView,
            ],
            self::Lawyer => [
                Permission::MatterView,
                Permission::MatterCreate,
                Permission::MatterUpdate,
                Permission::MatterTransitionStage,
                Permission::StageLogPublish,
                Permission::DocumentViewInternal,
                Permission::DocumentPublish,
                Permission::ChecklistReview,
                Permission::ClientUserManage,
            ],
            self::Assistant => [
                Permission::MatterView,
                Permission::MatterUpdate,
                Permission::ChecklistReview,
                Permission::ClientManage,
                Permission::ClientUserManage,
            ],
            // Kế toán chỉ thấy danh sách rút gọn, không mở được nội dung hồ sơ (SPEC §5).
            self::Accountant => [
                Permission::MatterViewAny,
            ],
        };
    }

    public static function fromPosition(UserPosition $position): self
    {
        return self::from($position->value);
    }
}
```

`lang/vi/roles.php`:
```php
<?php

return [
    'admin' => 'Quản trị hệ thống',
    'manager' => 'Trưởng phòng',
    'lawyer' => 'Luật sư',
    'assistant' => 'Trợ lý',
    'accountant' => 'Kế toán',
];
```

- [ ] **Bước 6: Seeder**

`database/seeders/RolesAndPermissionsSeeder.php`:
```php
<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionEnum::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        foreach (RoleEnum::cases() as $roleEnum) {
            $role = Role::findOrCreate($roleEnum->value, 'web');
            $role->syncPermissions(array_map(fn (PermissionEnum $p) => $p->value, $roleEnum->permissions()));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
```

- [ ] **Bước 7: `User` và factory**

`app/Models/User.php`: thêm `use Spatie\Permission\Traits\HasRoles;` vào phần import, `use HasRoles;` trong class, và phương thức:
```php
    /**
     * Gán vai trò khớp chức danh. Vai trò và chức danh là hai khái niệm khác nhau nhưng
     * ở bản 1.0 luôn trùng giá trị; Action sửa nhân sự (M3) phải gọi lại hàm này.
     */
    public function assignRoleFromPosition(): void
    {
        $this->syncRoles([Role::fromPosition($this->position)->value]);
    }
```
với `use App\Enums\Role;` ở phần import.

`database/factories/UserFactory.php`: thêm state
```php
    public function withRole(Role $role): static
    {
        return $this->afterCreating(fn (User $user) => $user->syncRoles([$role->value]));
    }
```
với `use App\Enums\Role;`. Giữ nguyên `admin()` và `position()` đang có; đổi `admin()` thành `return $this->position(UserPosition::Admin)->withRole(Role::Admin);`.

- [ ] **Bước 8: Nối seeder**

`database/seeders/DatabaseSeeder.php`: chèn `RolesAndPermissionsSeeder::class` lên **đầu** danh sách, trước `DemoAccountsSeeder::class`.

`database/seeders/DemoAccountsSeeder.php`: sau khi `updateOrCreate` người dùng admin, thêm `$admin->assignRoleFromPosition();` (gán biến `$admin =` cho lời gọi đang có).

`database/seeders/StaffSeeder.php`: trong vòng lặp, gán biến cho `updateOrCreate` rồi gọi `$user->assignRoleFromPosition();`.

- [ ] **Bước 9: Chạy test, mong đợi xanh**

Run: `bin/dev test --filter=RolesAndPermissionsTest` → PASS (5 test).
Run: `bin/dev test` → toàn bộ xanh (77 cũ + 5 mới).
Run: `bin/dev artisan migrate:fresh --seed` → sạch; kiểm tra nhanh:
`bin/dev artisan tinker --execute="echo App\Models\User::where('email','luatsu1@luatvukhang.com')->first()->hasRole('lawyer') ? 'ok' : 'FAIL';"` → `ok`.

- [ ] **Bước 10: Pint và commit**

```bash
bin/dev pint
git add -A
git commit -m "feat: spatie roles and permissions per SPEC §5, seeded and bound to staff position

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: Ngữ cảnh portal và global scope cho `Matter`

**Files:**
- Create: `app/Support/Scopes/ClientPortalScope.php`, `app/Models/Concerns/RestrictedToClientPortal.php`
- Modify: `app/Models/Matter.php`
- Test: `tests/Feature/Authorization/ClientDataIsolationTest.php`

**Interfaces:**
- Produces: `ClientPortalScope::isActive(): bool` (định nghĩa duy nhất của ngữ cảnh portal) và `ClientPortalScope::clientUser(): ?ClientUser`. Trait `RestrictedToClientPortal` đăng ký scope và bắt model khai báo `applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void`. Task 3 gắn trait này lên 7 model khác.

- [ ] **Bước 1: Viết test đỏ**

`tests/Feature/Authorization/ClientDataIsolationTest.php`:
```php
<?php

use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;

beforeEach(function () {
    $this->clientA = Client::factory()->create();
    $this->clientB = Client::factory()->create();
    $this->userA = ClientUser::factory()->create(['client_id' => $this->clientA->id]);

    $this->matterA = Matter::factory()->for($this->clientA)->create();
    $this->matterB = Matter::factory()->for($this->clientB)->create();
    $this->hiddenA = Matter::factory()->for($this->clientA)->unpublished()->create();
});

it('hides other clients matters from a portal user', function () {
    $this->actingAs($this->userA, 'client');

    expect(Matter::pluck('id')->all())->toBe([$this->matterA->id])
        ->and(Matter::find($this->matterB->id))->toBeNull()
        ->and(Matter::whereKey($this->matterB->id)->exists())->toBeFalse();
});

it('cannot be defeated by a manual client_id filter', function () {
    $this->actingAs($this->userA, 'client');

    expect(Matter::where('client_id', $this->clientB->id)->get())->toBeEmpty()
        ->and(Matter::whereIn('client_id', [$this->clientA->id, $this->clientB->id])->pluck('id')->all())
        ->toBe([$this->matterA->id]);
});

it('hides a matter of the own client that is not published to the portal', function () {
    $this->actingAs($this->userA, 'client');

    expect(Matter::find($this->hiddenA->id))->toBeNull();
});

it('does not restrict staff on the web guard', function () {
    $this->actingAs(User::factory()->create(), 'web');

    expect(Matter::count())->toBe(3);
});

it('does not restrict an unauthenticated context such as a queue job', function () {
    expect(Matter::count())->toBe(3);
});

it('keeps the admin panel unrestricted when a staff user is also logged into the portal', function () {
    // Hai panel dùng chung cookie phiên nên cả hai guard có thể cùng xác thực.
    $this->actingAs(User::factory()->create(), 'web');
    $this->actingAs($this->userA, 'client');

    expect(auth('web')->check())->toBeTrue()
        ->and(auth('client')->check())->toBeTrue()
        ->and(Matter::count())->toBe(3);
});
```

- [ ] **Bước 2: Chạy test, mong đợi đỏ**

Run: `bin/dev test --filter=ClientDataIsolationTest`
Expected: FAIL — bốn test đầu đỏ vì chưa có scope (khách thấy cả 3 vụ việc).

- [ ] **Bước 3: Scope**

`app/Support/Scopes/ClientPortalScope.php`:
```php
<?php

namespace App\Support\Scopes;

use App\Models\ClientUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Giới hạn mọi truy vấn theo khách hàng đang đăng nhập ở portal (SPEC §5 phần Portal).
 *
 * Điều kiện kích hoạt được định nghĩa **duy nhất** ở đây. Vế `! auth('web')->check()` là bắt
 * buộc: hai panel dùng chung cookie phiên nên một nhân sự đăng nhập cả /admin lẫn /portal sẽ
 * có cả hai guard cùng xác thực, và nếu thiếu vế này thì truy vấn ở /admin bị giới hạn sai.
 *
 * Không gắn scope này lên User và ClientUser: gọi auth() bên trong scope của chính model xác
 * thực sẽ đệ quy vô hạn khi guard nạp người dùng từ session.
 */
class ClientPortalScope implements Scope
{
    public static function isActive(): bool
    {
        return auth('client')->check() && ! auth('web')->check();
    }

    public static function clientUser(): ?ClientUser
    {
        return self::isActive() ? auth('client')->user() : null;
    }

    public function apply(Builder $builder, Model $model): void
    {
        $clientUser = self::clientUser();

        if ($clientUser === null) {
            return;
        }

        $model->applyClientPortalConstraints($builder, $clientUser);
    }
}
```

`app/Models/Concerns/RestrictedToClientPortal.php`:
```php
<?php

namespace App\Models\Concerns;

use App\Models\ClientUser;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Model nào dùng trait này thì tự giới hạn truy vấn khi đang ở ngữ cảnh portal.
 * Điều kiện cụ thể do từng model khai báo; model khách không được thấy thì chặn sạch
 * bằng `$query->whereRaw('1 = 0')`.
 */
trait RestrictedToClientPortal
{
    public static function bootRestrictedToClientPortal(): void
    {
        static::addGlobalScope(new ClientPortalScope);
    }

    abstract public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void;
}
```

- [ ] **Bước 4: Gắn vào `Matter`**

`app/Models/Matter.php`: thêm import `use App\Models\Concerns\RestrictedToClientPortal;` và `use Illuminate\Database\Eloquent\Builder;`, thêm `use RestrictedToClientPortal;` vào danh sách trait, rồi thêm phương thức:
```php
    /**
     * Khách chỉ thấy vụ việc của chính mình và chỉ khi đã bật công tắc công bố (SPEC §5).
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->where($this->qualifyColumn('client_id'), $clientUser->client_id)
            ->where($this->qualifyColumn('is_published_to_portal'), true);
    }
```
Dùng `qualifyColumn()` để điều kiện không nhập nhằng khi truy vấn có join hoặc nằm trong subquery `whereHas`.

- [ ] **Bước 5: Chạy test, mong đợi xanh**

Run: `bin/dev test --filter=ClientDataIsolationTest` → PASS (6 test).
Run: `bin/dev test` → toàn bộ xanh. Chú ý test cũ `PortalPanelTest` vẫn phải xanh.

- [ ] **Bước 6: Pint và commit**

```bash
bin/dev pint
git add -A
git commit -m "feat: client portal global scope, matters restricted to the signed-in client

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: Giới hạn portal cho toàn bộ model còn lại

**Files:**
- Modify: `app/Models/{StageLog,Document,Deadline,MatterChecklistItem,ClientRequest,ClientRequestReply,CommunicationLog,MatterParty,Client,StageLogView,DocumentDownload,OutboundMessage,MatterArchive,ChecklistTemplate,ChecklistTemplateItem}.php`
- Test: `tests/Feature/Authorization/PortalVisibilityTest.php`

**Interfaces:**
- Consumes: `RestrictedToClientPortal` (Task 2).
- Produces: 15 model tự giới hạn ở ngữ cảnh portal. Quy ước: model con của `Matter` dùng `whereHas('matter')` để thừa hưởng điều kiện của `Matter` — một chỗ duy nhất định nghĩa "vụ việc nào khách được thấy", không lặp lại `client_id` ở 10 nơi. Model khách không bao giờ được thấy thì `whereRaw('1 = 0')`.

**Không gắn trait (có chủ đích, ghi rõ trong mã):**
- `User`, `ClientUser` — model xác thực; gọi `auth()` trong scope của chúng gây đệ quy.
- `MatterType`, `MatterTypeStage` — dữ liệu cấu hình, không chứa dữ liệu khách hàng, và portal **cần đọc** `client_label`/`client_description` để hiển thị giai đoạn (SPEC §8.3).

- [ ] **Bước 1: Viết test đỏ**

`tests/Feature/Authorization/PortalVisibilityTest.php`:
```php
<?php

use App\Enums\DocumentGroup;
use App\Models\ChecklistTemplate;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientUser;
use App\Models\CommunicationLog;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\MatterChecklistItem;
use App\Models\MatterParty;
use App\Models\OutboundMessage;
use App\Models\StageLog;
use App\Models\StageLogView;
use App\Models\User;

beforeEach(function () {
    $this->clientA = Client::factory()->create();
    $this->clientB = Client::factory()->create();
    $this->userA = ClientUser::factory()->create(['client_id' => $this->clientA->id]);

    $this->matterA = Matter::factory()->for($this->clientA)->create();
    $this->matterB = Matter::factory()->for($this->clientB)->create();

    $this->publishedLog = StageLog::factory()->for($this->matterA)->published()->create();
    $this->internalLog = StageLog::factory()->for($this->matterA)->internalOnly()->create();
    $this->foreignLog = StageLog::factory()->for($this->matterB)->published()->create();

    $this->visibleDoc = Document::factory()->for($this->matterA)->group(DocumentGroup::Issued)
        ->create(['client_can_view' => true]);
    $this->hiddenDoc = Document::factory()->for($this->matterA)->group(DocumentGroup::Issued)
        ->create(['client_can_view' => false]);
    $this->internalDoc = Document::factory()->for($this->matterA)->group(DocumentGroup::Internal)
        ->create(['client_can_view' => true]);
    $this->foreignDoc = Document::factory()->for($this->matterB)->group(DocumentGroup::Issued)
        ->create(['client_can_view' => true]);
});

it('shows only published stage logs of the own client', function () {
    $this->actingAs($this->userA, 'client');

    expect(StageLog::pluck('id')->all())->toBe([$this->publishedLog->id]);
});

it('shows only viewable non internal documents of the own client', function () {
    $this->actingAs($this->userA, 'client');

    expect(Document::pluck('id')->all())->toBe([$this->visibleDoc->id])
        ->and(Document::find($this->internalDoc->id))->toBeNull()
        ->and(Document::find($this->foreignDoc->id))->toBeNull();
});

it('shows only published deadlines of the own client', function () {
    $mine = Deadline::factory()->for($this->matterA)->published()->create();
    Deadline::factory()->for($this->matterA)->create(['is_published' => false]);
    Deadline::factory()->for($this->matterB)->published()->create();

    $this->actingAs($this->userA, 'client');

    expect(Deadline::pluck('id')->all())->toBe([$mine->id]);
});

it('shows checklist items of the own matters only', function () {
    $mine = MatterChecklistItem::factory()->for($this->matterA)->create();
    MatterChecklistItem::factory()->for($this->matterB)->create();

    $this->actingAs($this->userA, 'client');

    expect(MatterChecklistItem::pluck('id')->all())->toBe([$mine->id]);
});

it('shows client requests and replies of the own matters only', function () {
    $mine = ClientRequest::factory()->for($this->matterA)->create(['client_user_id' => $this->userA->id]);
    $foreign = ClientRequest::factory()->for($this->matterB)->create();
    $mineReply = ClientRequestReply::factory()->create(['request_id' => $mine->id]);
    ClientRequestReply::factory()->create(['request_id' => $foreign->id]);

    $this->actingAs($this->userA, 'client');

    expect(ClientRequest::pluck('id')->all())->toBe([$mine->id])
        ->and(ClientRequestReply::pluck('id')->all())->toBe([$mineReply->id]);
});

it('shows only communication logs marked visible to the client', function () {
    $visible = CommunicationLog::factory()->for($this->matterA)->create(['is_visible_to_client' => true]);
    CommunicationLog::factory()->for($this->matterA)->create(['is_visible_to_client' => false]);

    $this->actingAs($this->userA, 'client');

    expect(CommunicationLog::pluck('id')->all())->toBe([$visible->id]);
});

it('shows only the own client record', function () {
    $this->actingAs($this->userA, 'client');

    expect(Client::pluck('id')->all())->toBe([$this->clientA->id]);
});

it('shows only the own stage log view receipts', function () {
    $mine = StageLogView::factory()->create([
        'stage_log_id' => $this->publishedLog->id, 'client_user_id' => $this->userA->id,
    ]);
    StageLogView::factory()->create(['stage_log_id' => $this->foreignLog->id]);

    $this->actingAs($this->userA, 'client');

    expect(StageLogView::pluck('id')->all())->toBe([$mine->id]);
});

it('never shows tables the portal has no business reading', function () {
    MatterParty::factory()->for($this->matterA)->create();
    DocumentDownload::factory()->create(['document_id' => $this->visibleDoc->id]);
    OutboundMessage::factory()->create();
    MatterArchive::factory()->for($this->matterA)->create();
    $template = ChecklistTemplate::factory()->withItems(2)->create();

    $this->actingAs($this->userA, 'client');

    expect(MatterParty::count())->toBe(0)
        ->and(DocumentDownload::count())->toBe(0)
        ->and(OutboundMessage::count())->toBe(0)
        ->and(MatterArchive::count())->toBe(0)
        ->and(ChecklistTemplate::count())->toBe(0)
        ->and($template->items()->count())->toBe(0);
});

it('leaves stage configuration readable because the portal renders it', function () {
    $this->actingAs($this->userA, 'client');

    expect($this->matterA->matterType->stages()->count())->toBeGreaterThan(0)
        ->and($this->matterA->currentStage())->not->toBeNull();
});

it('restricts nothing for staff', function () {
    $this->actingAs(User::factory()->create(), 'web');

    expect(StageLog::count())->toBe(3)
        ->and(Document::count())->toBe(4)
        ->and(Client::count())->toBe(2)
        ->and(MatterParty::count())->toBe(0);
});
```

- [ ] **Bước 2: Chạy test, mong đợi đỏ**

Run: `bin/dev test --filter=PortalVisibilityTest`
Expected: FAIL trên hầu hết test (chưa model nào ngoài `Matter` có giới hạn).

- [ ] **Bước 3: Model con của `Matter` — thừa hưởng điều kiện qua `whereHas('matter')`**

Với mỗi model dưới đây: thêm import `use App\Models\Concerns\RestrictedToClientPortal;`, `use App\Models\ClientUser;` (nếu chưa có) và `use Illuminate\Database\Eloquent\Builder;`, thêm `use RestrictedToClientPortal;` vào danh sách trait, rồi thêm phương thức tương ứng.

`app/Models/StageLog.php`:
```php
    /**
     * Khách chỉ đọc dòng đã công bố, thuộc vụ việc mà Matter cho phép (SPEC §5).
     * `whereHas('matter')` kế thừa điều kiện của Matter nên không lặp lại client_id ở đây.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->where($this->qualifyColumn('is_published'), true)->whereHas('matter');
    }
```

`app/Models/Document.php`:
```php
    /**
     * Khách chỉ thấy tài liệu được bật cho xem và không bao giờ thấy nhóm D (SPEC §4.11, §5).
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->where($this->qualifyColumn('client_can_view'), true)
            ->where($this->qualifyColumn('group'), '!=', DocumentGroup::Internal->value)
            ->whereHas('matter');
    }
```

`app/Models/Deadline.php`:
```php
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->where($this->qualifyColumn('is_published'), true)->whereHas('matter');
    }
```

`app/Models/MatterChecklistItem.php`:
```php
    /** Khách thấy toàn bộ danh mục hồ sơ của vụ việc mình, kèm trạng thái và lý do từ chối (SPEC §8.3). */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereHas('matter');
    }
```

`app/Models/ClientRequest.php`:
```php
    /**
     * Giới hạn theo vụ việc, tức theo client_id, không theo client_user_id: SPEC §4.3 nói rõ
     * mọi truy vấn portal giới hạn theo khách hàng, để hai tài khoản cùng một khách đọc chung.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereHas('matter');
    }
```

`app/Models/ClientRequestReply.php`:
```php
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereHas('request');
    }
```

`app/Models/CommunicationLog.php`:
```php
    /** Nhật ký liên lạc mặc định là nội bộ; chỉ dòng được đánh dấu mới ra portal (SPEC §4.17). */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->where($this->qualifyColumn('is_visible_to_client'), true)->whereHas('matter');
    }
```

`app/Models/StageLogView.php`:
```php
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereHas('stageLog');
    }
```

- [ ] **Bước 4: `Client` — chỉ hồ sơ của chính mình**

`app/Models/Client.php`:
```php
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereKey($clientUser->client_id);
    }
```

- [ ] **Bước 5: Các bảng portal không bao giờ được đọc**

Với `MatterParty`, `DocumentDownload`, `OutboundMessage`, `MatterArchive`, `ChecklistTemplate`, `ChecklistTemplateItem`: gắn trait và dùng cùng một thân hàm, đổi phần giải thích cho đúng model:
```php
    /**
     * Portal không bao giờ đọc bảng này (các bên trong vụ việc chỉ phục vụ kiểm tra xung đột
     * lợi ích ở SPEC §4.16). Chặn sạch ở tầng truy vấn thay vì trông vào việc không ai viết
     * resource cho nó.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereRaw('1 = 0');
    }
```
Phần giải thích cho từng model:
- `DocumentDownload`: "Nhật ký tải về là chứng cứ nội bộ (SPEC §4.12)."
- `OutboundMessage`: "Nhật ký thông báo gửi đi phục vụ tra cứu nội bộ (SPEC §4.15)."
- `MatterArchive`: "Hồ sơ lưu trữ và đường dẫn gói bàn giao là dữ liệu nội bộ (SPEC §4.19)."
- `ChecklistTemplate` / `ChecklistTemplateItem`: "Khách chỉ thấy bản sao trong matter_checklist_items, không thấy danh mục mẫu (SPEC §4.10)."

- [ ] **Bước 6: Ghi chú ở hai model cố ý không giới hạn**

Thêm vào docblock của class `MatterType` và `MatterTypeStage`:
```php
/**
 * ... (giữ nguyên mô tả cũ)
 *
 * Cố ý KHÔNG dùng RestrictedToClientPortal: đây là dữ liệu cấu hình, không chứa dữ liệu khách
 * hàng, và portal cần đọc client_label / client_description để hiển thị giai đoạn (SPEC §8.3).
 */
```

Và vào docblock của `User` và `ClientUser`:
```php
 * Cố ý KHÔNG dùng RestrictedToClientPortal: gọi auth() trong global scope của chính model xác
 * thực sẽ đệ quy vô hạn khi guard nạp người dùng từ session.
```

- [ ] **Bước 7: Chú thích chỗ M7 sẽ mở rộng**

Trong `Matter::applyClientPortalConstraints`, thêm dòng ghi chú cuối thân hàm:
```php
        // M7 bổ sung điều kiện client_access_until ở đây (SPEC §11 "Bàn giao và lưu trữ").
```

- [ ] **Bước 8: Chạy test, mong đợi xanh**

Run: `bin/dev test --filter=PortalVisibilityTest` → PASS (11 test).
Run: `bin/dev test` → toàn bộ xanh.

Lỗi hay gặp: nếu test "never shows tables the portal has no business reading" báo `MatterArchive::count()` khác 0, kiểm tra đã gắn trait chứ không chỉ viết phương thức. Nếu `DemoDataSeederTest` hỏng, nguyên nhân là seeder chạy khi guard client còn sót người dùng — không xảy ra vì seeder chỉ dùng guard `web`.

- [ ] **Bước 9: Pint và commit**

```bash
bin/dev pint
git add -A
git commit -m "feat: portal visibility rules for every business model, deny-by-default for internal tables

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 4: Che cột nội bộ khỏi JSON và HTML của portal

**Files:**
- Create: `app/Models/Concerns/HidesInternalAttributesFromPortal.php`
- Modify: `app/Models/{Matter,StageLog,Client}.php`
- Test: `tests/Feature/Authorization/InternalNotesTest.php`

**Interfaces:**
- Produces: trait `HidesInternalAttributesFromPortal` với `abstract protected function internalAttributes(): array`. Loại các cột đó khỏi `attributesToArray()` khi `ClientPortalScope::isActive()`, nên `toArray()`, `toJson()`, `json_encode()` và mọi view Blade lặp qua thuộc tính đều không thấy.

Đây là lớp phòng thủ thứ ba, độc lập với scope: một dòng tiến độ **đã công bố** vẫn có `internal_note` trong cùng bản ghi, nên scope không cứu được nếu view quên lọc cột.

- [ ] **Bước 1: Viết test đỏ**

`tests/Feature/Authorization/InternalNotesTest.php`:
```php
<?php

use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->marker = 'DAU-HIEU-NOI-BO-'.Str::random(12);

    $this->client = Client::factory()->create(['note' => $this->marker]);
    $this->clientUser = ClientUser::factory()->create(['client_id' => $this->client->id]);
    $this->matter = Matter::factory()->for($this->client)->create(['description_internal' => $this->marker]);
    $this->log = StageLog::factory()->for($this->matter)->published()->create(['internal_note' => $this->marker]);
});

it('keeps internal notes out of anything the portal serializes', function () {
    auth('client')->setUser($this->clientUser);

    $payload = json_encode([
        Matter::findOrFail($this->matter->id)->toArray(),
        StageLog::findOrFail($this->log->id)->toArray(),
        Client::findOrFail($this->client->id)->toArray(),
        Matter::with(['stageLogs', 'client'])->findOrFail($this->matter->id)->toArray(),
    ], JSON_UNESCAPED_UNICODE);

    expect($payload)->not->toContain($this->marker);
});

it('still lets staff read the internal columns', function () {
    $this->actingAs(User::factory()->create(), 'web');

    expect(json_encode(Matter::findOrFail($this->matter->id)->toArray()))->toContain($this->marker)
        ->and(json_encode(StageLog::findOrFail($this->log->id)->toArray()))->toContain($this->marker)
        ->and(json_encode(Client::findOrFail($this->client->id)->toArray()))->toContain($this->marker);
});

it('still exposes the internal value to code that asks for the attribute directly', function () {
    // Scope và trait bảo vệ tầng serialize, không làm hỏng nghiệp vụ nội bộ đọc thuộc tính.
    auth('client')->setUser($this->clientUser);

    expect(StageLog::findOrFail($this->log->id)->internal_note)->toBe($this->marker);
});

it('keeps the public content visible to the client', function () {
    auth('client')->setUser($this->clientUser);

    expect(StageLog::findOrFail($this->log->id)->toArray())
        ->toHaveKey('public_content')
        ->not->toHaveKey('internal_note');
});
```

- [ ] **Bước 2: Chạy test, mong đợi đỏ**

Run: `bin/dev test --filter=InternalNotesTest`
Expected: FAIL — test 1 và 4 đỏ vì `internal_note` vẫn nằm trong mảng.

- [ ] **Bước 3: Trait**

`app/Models/Concerns/HidesInternalAttributesFromPortal.php`:
```php
<?php

namespace App\Models\Concerns;

use App\Support\Scopes\ClientPortalScope;

/**
 * Lớp phòng thủ thứ ba cho SPEC §11 ("Response JSON và HTML của portal không chứa chuỗi trong
 * stage_logs.internal_note"). Global scope không đủ: một dòng tiến độ đã công bố vẫn mang
 * internal_note trong cùng bản ghi, nên chỉ cần một view quên lọc cột là rò rỉ.
 *
 * Chỉ tác động lên tầng serialize; mã nghiệp vụ đọc thẳng thuộc tính vẫn nhận giá trị thật.
 */
trait HidesInternalAttributesFromPortal
{
    /** @return list<string> Tên cột không bao giờ được ra portal. */
    abstract protected function internalAttributes(): array;

    public function attributesToArray(): array
    {
        $attributes = parent::attributesToArray();

        if (! ClientPortalScope::isActive()) {
            return $attributes;
        }

        foreach ($this->internalAttributes() as $attribute) {
            unset($attributes[$attribute]);
        }

        return $attributes;
    }
}
```

- [ ] **Bước 4: Gắn vào ba model**

`app/Models/Matter.php` — thêm `use HidesInternalAttributesFromPortal;` và:
```php
    /** SPEC §4.6: description_internal không bao giờ ra portal. */
    protected function internalAttributes(): array
    {
        return ['description_internal'];
    }
```

`app/Models/StageLog.php`:
```php
    /** SPEC §4.8: internal_note chỉ dành cho nội bộ. */
    protected function internalAttributes(): array
    {
        return ['internal_note'];
    }
```

`app/Models/Client.php`:
```php
    /** SPEC §4.2: note là ghi chú nội bộ, không bao giờ ra portal. */
    protected function internalAttributes(): array
    {
        return ['note'];
    }
```

- [ ] **Bước 5: Chạy test, mong đợi xanh**

Run: `bin/dev test --filter=InternalNotesTest` → PASS (4 test).
Run: `bin/dev test` → toàn bộ xanh.

- [ ] **Bước 6: Pint và commit**

```bash
bin/dev pint
git add -A
git commit -m "feat: internal-only columns never serialize in portal context

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 5: `Matter::scopeListableBy` và `MatterPolicy`

**Files:**
- Modify: `app/Models/Matter.php`
- Create: `app/Policies/Concerns/ChecksPortalVisibility.php`, `app/Policies/MatterPolicy.php`
- Test: `tests/Feature/Authorization/MatterPolicyTest.php`

**Interfaces:**
- Produces: `Matter::scopeListableBy(Builder $query, User $user): Builder` — **định nghĩa duy nhất** của "nhân sự này được thấy vụ việc nào", dùng cho cả danh sách (M3 resource) lẫn policy. `MatterPolicy` với `viewAny/view/create/update/delete/restore/forceDelete/transitionStage`. Trait `ChecksPortalVisibility::visibleToPortal(ClientUser, Model): bool` dùng lại ở Task 6.

**Vì sao tách `listableBy` khỏi `view`:** SPEC §5 cho kế toán `matter.viewAny` ở dạng "danh sách rút gọn" nhưng không cho `matter.view`. Nếu gộp một hàm thì hoặc kế toán mất danh sách, hoặc kế toán mở được hồ sơ — cả hai đều sai. `listableBy` trả về dòng được thấy trong danh sách; `view` thêm điều kiện có quyền `matter.view`.

- [ ] **Bước 1: Viết test đỏ**

`tests/Feature/Authorization/MatterPolicyTest.php`:
```php
<?php

use App\Enums\MatterRole;
use App\Enums\Role;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->teammate = User::factory()->withRole(Role::Lawyer)->create();
    $this->outsider = User::factory()->withRole(Role::Lawyer)->create();
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->matter->addTeamMember($this->teammate, MatterRole::Associate);

    $this->restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->restricted->addTeamMember($this->teammate, MatterRole::Associate);
});

it('lets a lawyer named in the team see the matter and hides it from everyone else', function () {
    expect($this->lead->can('view', $this->matter))->toBeTrue()
        ->and($this->teammate->can('view', $this->matter))->toBeTrue()
        ->and($this->outsider->can('view', $this->matter))->toBeFalse();
});

it('keeps a matter out of the list of a lawyer who is not on the team', function () {
    expect(Matter::query()->listableBy($this->outsider)->count())->toBe(0)
        ->and(Matter::query()->listableBy($this->teammate)->pluck('id')->all())->toBe([$this->matter->id]);
});

it('shows a restricted matter only to the lead lawyer and the admin', function () {
    expect($this->lead->can('view', $this->restricted))->toBeTrue()
        ->and($this->admin->can('view', $this->restricted))->toBeTrue()
        ->and($this->manager->can('view', $this->restricted))->toBeFalse()
        ->and($this->teammate->can('view', $this->restricted))->toBeFalse()
        ->and(Matter::query()->listableBy($this->manager)->pluck('id')->all())->toBe([$this->matter->id])
        ->and(Matter::query()->listableBy($this->admin)->count())->toBe(2);
});

it('gives the accountant the list but never the content', function () {
    expect($this->accountant->can('viewAny', Matter::class))->toBeTrue()
        ->and(Matter::query()->listableBy($this->accountant)->pluck('id')->all())->toBe([$this->matter->id])
        ->and($this->accountant->can('view', $this->matter))->toBeFalse()
        ->and($this->accountant->can('update', $this->matter))->toBeFalse()
        ->and($this->accountant->can('create', Matter::class))->toBeFalse();
});

it('lets a manager see every ordinary matter without being on the team', function () {
    expect($this->manager->can('view', $this->matter))->toBeTrue()
        ->and($this->manager->can('update', $this->matter))->toBeTrue();
});

it('allows a lawyer to update and transition only their own matters', function () {
    expect($this->lead->can('update', $this->matter))->toBeTrue()
        ->and($this->lead->can('transitionStage', $this->matter))->toBeTrue()
        ->and($this->outsider->can('update', $this->matter))->toBeFalse()
        ->and($this->outsider->can('transitionStage', $this->matter))->toBeFalse()
        ->and($this->assistant->can('transitionStage', $this->matter))->toBeFalse();
});

it('restricts creation and deletion to the roles the spec names', function () {
    expect($this->lead->can('create', Matter::class))->toBeTrue()
        ->and($this->assistant->can('create', Matter::class))->toBeFalse()
        ->and($this->admin->can('delete', $this->matter))->toBeTrue()
        ->and($this->manager->can('delete', $this->matter))->toBeFalse()
        ->and($this->admin->can('forceDelete', $this->matter))->toBeFalse();
});
```

- [ ] **Bước 2: Chạy test, mong đợi đỏ**

Run: `bin/dev test --filter=MatterPolicyTest`
Expected: FAIL, `listableBy` không tồn tại và chưa có policy.

- [ ] **Bước 3: `scopeListableBy`**

`app/Models/Matter.php`, thêm (cần `use App\Enums\Permission;`, `use App\Enums\Role as StaffRole;`, `use Illuminate\Database\Eloquent\Builder;`):
```php
    /**
     * Định nghĩa duy nhất của "nhân sự này được thấy vụ việc nào" (SPEC §5).
     * Dùng cho danh sách ở panel admin và cho MatterPolicy::view, để hai nơi không lệch nhau.
     *
     * - Vụ thường: ai có matter.viewAny thấy tất cả; còn lại phải có tên trong matter_user.
     * - Vụ `restricted`: chỉ luật sư phụ trách và vai trò admin, kể cả trưởng phòng cũng không.
     * - Ai không có cả matter.viewAny lẫn matter.view (kế toán chỉ có viewAny) xử lý ở nhánh tương ứng.
     */
    public function scopeListableBy(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $outer) use ($user): void {
            $outer->where(function (Builder $normal) use ($user): void {
                $normal->where($this->qualifyColumn('confidentiality'), '!=', Confidentiality::Restricted->value);

                if ($user->can(Permission::MatterViewAny->value)) {
                    return;
                }

                $user->can(Permission::MatterView->value)
                    ? $normal->whereHas('team', fn (Builder $team) => $team->whereKey($user->getKey()))
                    : $normal->whereRaw('1 = 0');
            })->orWhere(function (Builder $restricted) use ($user): void {
                $restricted->where($this->qualifyColumn('confidentiality'), Confidentiality::Restricted->value);

                if (! $user->hasRole(StaffRole::Admin->value)) {
                    $restricted->where($this->qualifyColumn('lead_lawyer_id'), $user->getKey());
                }
            });
        });
    }
```

- [ ] **Bước 4: Trait dùng chung cho policy**

`app/Policies/Concerns/ChecksPortalVisibility.php`:
```php
<?php

namespace App\Policies\Concerns;

use App\Models\ClientUser;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Quyền của khách hàng không dùng spatie (SPEC §5). Policy hỏi lại chính điều kiện mà global
 * scope dùng, nên hai tầng không bao giờ lệch nhau.
 *
 * Áp điều kiện tường minh với $clientUser được truyền vào thay vì dựa vào auth() hiện hành,
 * để kết quả không phụ thuộc ngữ cảnh guard đang mở.
 */
trait ChecksPortalVisibility
{
    protected function visibleToPortal(ClientUser $clientUser, Model $record): bool
    {
        return $record->newQuery()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->tap(fn (Builder $query) => $record->applyClientPortalConstraints($query, $clientUser))
            ->whereKey($record->getKey())
            ->exists();
    }
}
```

- [ ] **Bước 5: `MatterPolicy`**

`app/Policies/MatterPolicy.php`:
```php
<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;
use App\Policies\Concerns\ChecksPortalVisibility;

class MatterPolicy
{
    use ChecksPortalVisibility;

    public function viewAny(User|ClientUser $user): bool
    {
        if ($user instanceof ClientUser) {
            return true;
        }

        return $user->can(Permission::MatterViewAny->value) || $user->can(Permission::MatterView->value);
    }

    public function view(User|ClientUser $user, Matter $matter): bool
    {
        if ($user instanceof ClientUser) {
            return $this->visibleToPortal($user, $matter);
        }

        return $user->can(Permission::MatterView->value)
            && Matter::query()->listableBy($user)->whereKey($matter->getKey())->exists();
    }

    public function create(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::MatterCreate->value);
    }

    public function update(User|ClientUser $user, Matter $matter): bool
    {
        return $user instanceof User
            && $user->can(Permission::MatterUpdate->value)
            && $this->view($user, $matter);
    }

    public function transitionStage(User|ClientUser $user, Matter $matter): bool
    {
        return $user instanceof User
            && $user->can(Permission::MatterTransitionStage->value)
            && $this->view($user, $matter);
    }

    /** Xoá mềm vụ việc là việc hệ trọng: chỉ quản trị. */
    public function delete(User|ClientUser $user, Matter $matter): bool
    {
        return $user instanceof User && $user->hasRole(Role::Admin->value);
    }

    public function restore(User|ClientUser $user, Matter $matter): bool
    {
        return $this->delete($user, $matter);
    }

    /** Không ai xoá vĩnh viễn được: model cũng chặn (MatterNotDestroyable). */
    public function forceDelete(User|ClientUser $user, Matter $matter): bool
    {
        return false;
    }
}
```

- [ ] **Bước 6: Chạy test, mong đợi xanh**

Run: `bin/dev test --filter=MatterPolicyTest` → PASS (7 test).
Run: `bin/dev test` → toàn bộ xanh.

Gợi ý gỡ lỗi: nếu quyền trả sai sau khi seed trong cùng một test, gọi `app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();` — seeder đã gọi sẵn nên thường không cần.

- [ ] **Bước 7: Pint và commit**

```bash
bin/dev pint
git add -A
git commit -m "feat: matter visibility scope and policy per SPEC §5

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 6: Policy cho các model còn lại

**Files:**
- Create: `app/Policies/Concerns/ChecksMatterAccess.php`
- Create: `app/Policies/{StageLog,Document,MatterChecklistItem,Deadline,ClientRequest,CommunicationLog,MatterParty,Client,ClientUser,MatterType}Policy.php`
- Test: `tests/Feature/Authorization/ChildPolicyTest.php`

**Interfaces:**
- Consumes: `ChecksPortalVisibility` (Task 5), `Matter::scopeListableBy`.
- Produces: 10 policy. Quy ước chung: **nhân sự** phải xem được vụ việc cha (`$user->can('view', $matter)`) rồi mới xét quyền riêng; **khách** dùng `visibleToPortal()`. Không policy nào tự viết lại điều kiện của `Matter`.

- [ ] **Bước 1: Viết test đỏ**

`tests/Feature/Authorization/ChildPolicyTest.php`:
```php
<?php

use App\Enums\DocumentGroup;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\MatterParty;
use App\Models\MatterType;
use App\Models\StageLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->outsider = User::factory()->withRole(Role::Lawyer)->create();
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->create(['client_id' => $this->client->id]);
    $this->matter = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lead->id]);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);

    $this->log = StageLog::factory()->for($this->matter)->published()->create();
    $this->internalDoc = Document::factory()->for($this->matter)->group(DocumentGroup::Internal)->create();
    $this->clientDoc = Document::factory()->for($this->matter)->group(DocumentGroup::Issued)
        ->create(['client_can_view' => true, 'client_can_download' => false]);
});

it('ties every child record to the visibility of its matter', function () {
    expect($this->lead->can('view', $this->log))->toBeTrue()
        ->and($this->outsider->can('view', $this->log))->toBeFalse()
        ->and($this->outsider->can('view', $this->clientDoc))->toBeFalse()
        ->and($this->outsider->can('view', MatterChecklistItem::factory()->for($this->matter)->create()))->toBeFalse()
        ->and($this->outsider->can('view', Deadline::factory()->for($this->matter)->create()))->toBeFalse();
});

it('limits publishing progress to the roles that may', function () {
    expect($this->lead->can('publish', $this->log))->toBeTrue()
        ->and($this->assistant->can('publish', $this->log))->toBeFalse()
        ->and($this->outsider->can('publish', $this->log))->toBeFalse();
});

it('keeps group D documents away from anyone without document.viewInternal', function () {
    expect($this->lead->can('view', $this->internalDoc))->toBeTrue()
        ->and($this->assistant->can('view', $this->internalDoc))->toBeFalse()
        ->and($this->accountant->can('view', $this->internalDoc))->toBeFalse();
});

it('never lets a client user see a group D document or download what is not downloadable', function () {
    expect($this->clientUser->can('view', $this->internalDoc))->toBeFalse()
        ->and($this->clientUser->can('view', $this->clientDoc))->toBeTrue()
        ->and($this->clientUser->can('download', $this->clientDoc))->toBeFalse();

    $this->clientDoc->update(['client_can_download' => true]);

    expect($this->clientUser->fresh()->can('download', $this->clientDoc->fresh()))->toBeTrue();
});

it('never lets a client user see data of another client', function () {
    $otherMatter = Matter::factory()->create();
    $otherLog = StageLog::factory()->for($otherMatter)->published()->create();

    expect($this->clientUser->can('view', $otherMatter))->toBeFalse()
        ->and($this->clientUser->can('view', $otherLog))->toBeFalse();
});

it('never lets a client user write to internal records', function () {
    expect($this->clientUser->can('update', $this->matter))->toBeFalse()
        ->and($this->clientUser->can('create', StageLog::class))->toBeFalse()
        ->and($this->clientUser->can('view', MatterParty::factory()->for($this->matter)->create()))->toBeFalse()
        ->and($this->clientUser->can('viewAny', MatterParty::class))->toBeFalse();
});

it('lets a client user raise and read their own requests', function () {
    $request = ClientRequest::factory()->for($this->matter)->create(['client_user_id' => $this->clientUser->id]);

    expect($this->clientUser->can('create', ClientRequest::class))->toBeTrue()
        ->and($this->clientUser->can('view', $request))->toBeTrue();
});

it('gates client and settings management by permission', function () {
    expect($this->assistant->can('viewAny', Client::class))->toBeTrue()
        ->and($this->lead->can('viewAny', Client::class))->toBeFalse()
        ->and($this->lead->can('create', ClientUser::class))->toBeTrue()
        ->and($this->admin->can('create', MatterType::class))->toBeTrue()
        ->and($this->lead->can('create', MatterType::class))->toBeFalse();
});
```

- [ ] **Bước 2: Chạy test, mong đợi đỏ**

Run: `bin/dev test --filter=ChildPolicyTest` → FAIL, chưa có policy nào.

- [ ] **Bước 3: Trait dùng chung**

`app/Policies/Concerns/ChecksMatterAccess.php`:
```php
<?php

namespace App\Policies\Concerns;

use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;

/**
 * Mọi bản ghi con của Matter đều thừa hưởng quyền xem của vụ việc cha. Không policy nào
 * được viết lại điều kiện đội ngũ / confidentiality: một chỗ duy nhất là MatterPolicy::view.
 */
trait ChecksMatterAccess
{
    protected function canSeeMatter(User|ClientUser $user, ?Matter $matter): bool
    {
        return $matter !== null && $user->can('view', $matter);
    }
}
```

- [ ] **Bước 4: Policy của model con**

Mẫu chung (áp cho `StageLog`, `MatterChecklistItem`, `Deadline`, `CommunicationLog`): `viewAny` theo quyền/kiểu người dùng, `view` = xem được vụ cha (khách thì thêm `visibleToPortal`), `create`/`update` theo quyền nhân sự.

`app/Policies/StageLogPolicy.php`:
```php
<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\StageLog;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;
use App\Policies\Concerns\ChecksPortalVisibility;

class StageLogPolicy
{
    use ChecksMatterAccess;
    use ChecksPortalVisibility;

    public function viewAny(User|ClientUser $user): bool
    {
        return true;
    }

    public function view(User|ClientUser $user, StageLog $stageLog): bool
    {
        return $user instanceof ClientUser
            ? $this->visibleToPortal($user, $stageLog) && $this->canSeeMatter($user, $stageLog->matter)
            : $this->canSeeMatter($user, $stageLog->matter);
    }

    public function create(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::MatterUpdate->value);
    }

    /** Công bố tiến độ cho khách — SPEC §5 stageLog.publish. */
    public function publish(User|ClientUser $user, StageLog $stageLog): bool
    {
        return $user instanceof User
            && $user->can(Permission::StageLogPublish->value)
            && $this->canSeeMatter($user, $stageLog->matter);
    }

    /** Nhật ký chỉ thêm (SPEC §4.8); model cũng chặn. */
    public function update(User|ClientUser $user, StageLog $stageLog): bool
    {
        return false;
    }

    public function delete(User|ClientUser $user, StageLog $stageLog): bool
    {
        return false;
    }
}
```

`app/Policies/DocumentPolicy.php`:
```php
<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;
use App\Policies\Concerns\ChecksPortalVisibility;

class DocumentPolicy
{
    use ChecksMatterAccess;
    use ChecksPortalVisibility;

    public function viewAny(User|ClientUser $user): bool
    {
        return true;
    }

    public function view(User|ClientUser $user, Document $document): bool
    {
        if ($user instanceof ClientUser) {
            return $this->visibleToPortal($user, $document) && $this->canSeeMatter($user, $document->matter);
        }

        if ($document->group->isInternal() && ! $user->can(Permission::DocumentViewInternal->value)) {
            return false;
        }

        return $this->canSeeMatter($user, $document->matter);
    }

    /** Tải tệp: khách phải được bật thêm client_can_download (SPEC §5). */
    public function download(User|ClientUser $user, Document $document): bool
    {
        if (! $this->view($user, $document)) {
            return false;
        }

        return $user instanceof ClientUser ? $document->client_can_download : true;
    }

    public function create(User|ClientUser $user): bool
    {
        return true; // khách nộp tài liệu vào danh mục, nhân sự tải lên; ràng buộc chi tiết ở M4
    }

    public function publish(User|ClientUser $user, Document $document): bool
    {
        return $user instanceof User
            && $user->can(Permission::DocumentPublish->value)
            && $this->canSeeMatter($user, $document->matter);
    }

    public function update(User|ClientUser $user, Document $document): bool
    {
        return $user instanceof User && $this->canSeeMatter($user, $document->matter);
    }

    public function delete(User|ClientUser $user, Document $document): bool
    {
        return $user instanceof User && $this->canSeeMatter($user, $document->matter);
    }
}
```

`app/Policies/MatterChecklistItemPolicy.php`:
```php
<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;
use App\Policies\Concerns\ChecksPortalVisibility;

class MatterChecklistItemPolicy
{
    use ChecksMatterAccess;
    use ChecksPortalVisibility;

    public function viewAny(User|ClientUser $user): bool
    {
        return true;
    }

    public function view(User|ClientUser $user, MatterChecklistItem $item): bool
    {
        return $user instanceof ClientUser
            ? $this->visibleToPortal($user, $item) && $this->canSeeMatter($user, $item->matter)
            : $this->canSeeMatter($user, $item->matter);
    }

    /** Duyệt giấy tờ khách nộp — SPEC §5 checklist.review. */
    public function review(User|ClientUser $user, MatterChecklistItem $item): bool
    {
        return $user instanceof User
            && $user->can(Permission::ChecklistReview->value)
            && $this->canSeeMatter($user, $item->matter);
    }

    public function update(User|ClientUser $user, MatterChecklistItem $item): bool
    {
        return $this->review($user, $item);
    }
}
```

`app/Policies/DeadlinePolicy.php` và `app/Policies/CommunicationLogPolicy.php`: cùng khuôn với `MatterChecklistItemPolicy` nhưng bỏ `review`; `create`/`update`/`delete` = `$user instanceof User && $this->canSeeMatter($user, $record->matter)`.

`app/Policies/ClientRequestPolicy.php`:
```php
<?php

namespace App\Policies;

use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;
use App\Policies\Concerns\ChecksPortalVisibility;

class ClientRequestPolicy
{
    use ChecksMatterAccess;
    use ChecksPortalVisibility;

    public function viewAny(User|ClientUser $user): bool
    {
        return true;
    }

    public function view(User|ClientUser $user, ClientRequest $request): bool
    {
        return $user instanceof ClientUser
            ? $this->visibleToPortal($user, $request) && $this->canSeeMatter($user, $request->matter)
            : $this->canSeeMatter($user, $request->matter);
    }

    /** Khách gửi yêu cầu; nhân sự trả lời (SPEC §5 portal). */
    public function create(User|ClientUser $user): bool
    {
        return true;
    }

    public function update(User|ClientUser $user, ClientRequest $request): bool
    {
        return $user instanceof User && $this->canSeeMatter($user, $request->matter);
    }
}
```

`app/Policies/MatterPartyPolicy.php` — khách không bao giờ chạm tới:
```php
<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\MatterParty;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;

class MatterPartyPolicy
{
    use ChecksMatterAccess;

    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User;
    }

    public function view(User|ClientUser $user, MatterParty $party): bool
    {
        return $user instanceof User && $this->canSeeMatter($user, $party->matter);
    }

    public function create(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::MatterUpdate->value);
    }

    public function update(User|ClientUser $user, MatterParty $party): bool
    {
        return $this->view($user, $party) && $user->can(Permission::MatterUpdate->value);
    }

    public function delete(User|ClientUser $user, MatterParty $party): bool
    {
        return $this->update($user, $party);
    }
}
```

`app/Policies/ClientPolicy.php` (`client.manage`), `app/Policies/ClientUserPolicy.php` (`clientUser.manage`), `app/Policies/MatterTypePolicy.php` (`settings.manage`): cùng khuôn đơn giản —
```php
    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::ClientManage->value);
    }

    public function view(User|ClientUser $user, Client $client): bool
    {
        // Khách xem được hồ sơ của chính mình; scope đã giới hạn, policy xác nhận lại.
        return $user instanceof ClientUser
            ? $this->visibleToPortal($user, $client)
            : $user->can(Permission::ClientManage->value);
    }

    public function create(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::ClientManage->value);
    }

    public function update(User|ClientUser $user, Client $client): bool
    {
        return $this->create($user);
    }

    public function delete(User|ClientUser $user, Client $client): bool
    {
        return $user instanceof User && $user->hasRole(Role::Admin->value);
    }
```
`ClientUserPolicy` dùng `Permission::ClientUserManage` và **không** cho khách (`viewAny`/`view` chỉ `User`). `MatterTypePolicy` dùng `Permission::SettingsManage` cho mọi hành động ghi, còn `viewAny`/`view` trả `true` cho mọi người vì portal cần đọc nhãn giai đoạn.

- [ ] **Bước 5: Chạy test, mong đợi xanh**

Run: `bin/dev test --filter=ChildPolicyTest` → PASS (8 test).
Run: `bin/dev test` → toàn bộ xanh.

Lỗi hay gặp: Laravel tự tìm policy theo quy ước `App\Models\X` → `App\Policies\XPolicy`; nếu một policy không được nhận, kiểm tra đúng tên tệp và namespace, không cần đăng ký tay.

- [ ] **Bước 6: Pint và commit**

```bash
bin/dev pint
git add -A
git commit -m "feat: policies for every business model, children inherit matter visibility

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 7: Lưới an toàn và nghiệm thu trên dữ liệu mẫu

**Files:**
- Create: `tests/Feature/Authorization/PortalCoverageTest.php`, `tests/Feature/Authorization/DemoDataAuthorizationTest.php`
- Modify: `app/Models/*` nếu lưới an toàn phát hiện model bị bỏ sót

**Interfaces:**
- Consumes: mọi thứ từ Task 1–6.
- Produces: một test cấu trúc bắt mọi model mới ở M3–M8 phải quyết định rõ ràng về khả năng hiển thị ở portal, và một test nghiệm thu chạy trên bộ dữ liệu mẫu đầy đủ.

- [ ] **Bước 1: Lưới an toàn — mọi model phải có quyết định rõ ràng**

`tests/Feature/Authorization/PortalCoverageTest.php`:
```php
<?php

use App\Models\ClientUser;
use App\Models\Concerns\RestrictedToClientPortal;
use App\Models\MatterType;
use App\Models\MatterTypeStage;
use App\Models\User;

it('makes every model state whether the portal may read it', function () {
    /**
     * Cố ý không giới hạn, có lý do ghi trong docblock của từng class:
     * - User, ClientUser: model xác thực, gọi auth() trong scope của chúng sẽ đệ quy.
     * - MatterType, MatterTypeStage: dữ liệu cấu hình, portal cần đọc nhãn giai đoạn (SPEC §8.3).
     */
    $exempt = [User::class, ClientUser::class, MatterType::class, MatterTypeStage::class];

    $files = glob(app_path('Models/*.php')) ?: [];
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $class = 'App\\Models\\'.pathinfo($file, PATHINFO_FILENAME);
        $uses = class_uses_recursive($class);
        $restricted = in_array(RestrictedToClientPortal::class, $uses, true);

        in_array($class, $exempt, true)
            ? expect($restricted)->toBeFalse("{$class} nằm trong danh sách miễn trừ nhưng lại dùng trait")
            : expect($restricted)->toBeTrue("{$class} chưa quyết định: dùng RestrictedToClientPortal, hoặc thêm vào danh sách miễn trừ kèm lý do");
    }
});

it('gives every restricted model a policy', function () {
    $files = glob(app_path('Models/*.php')) ?: [];

    foreach ($files as $file) {
        $name = pathinfo($file, PATHINFO_FILENAME);
        $class = 'App\\Models\\'.$name;

        if (! in_array(RestrictedToClientPortal::class, class_uses_recursive($class), true)) {
            continue;
        }

        expect(class_exists('App\\Policies\\'.$name.'Policy'))
            ->toBeTrue("Thiếu App\\Policies\\{$name}Policy cho model bị giới hạn portal");
    }
});
```

Chạy: `bin/dev test --filter=PortalCoverageTest`. Nếu đỏ, **không** nới danh sách miễn trừ cho tiện: hoặc gắn trait cho model bị bỏ sót (Task 3 đã liệt kê 15 model), hoặc viết policy còn thiếu (Task 6 liệt kê 10 policy, cộng `MatterPolicy`). Các model bị giới hạn nhưng Task 6 chưa có policy — `ClientRequestReply`, `StageLogView`, `DocumentDownload`, `OutboundMessage`, `MatterArchive`, `ChecklistTemplate`, `ChecklistTemplateItem` — viết policy tối thiểu theo mẫu:
```php
class OutboundMessagePolicy
{
    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::AuditLogView->value);
    }

    public function view(User|ClientUser $user, OutboundMessage $message): bool
    {
        return $this->viewAny($user);
    }
}
```
- `DocumentDownload`, `OutboundMessage`: `auditLog.view`.
- `MatterArchive`, `StageLogView`, `ClientRequestReply`: `$user instanceof User && $this->canSeeMatter(...)` theo vụ việc cha (`$record->matter`, `$record->stageLog->matter`, `$record->request->matter`).
- `ChecklistTemplate`, `ChecklistTemplateItem`: `settings.manage`.

- [ ] **Bước 2: Test nghiệm thu trên dữ liệu mẫu**

`tests/Feature/Authorization/DemoDataAuthorizationTest.php`:
```php
<?php

use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

it('gives each role the reach the spec describes on the seeded office', function () {
    $admin = User::where('email', 'admin@luatvukhang.com')->firstOrFail();
    $lawyer = User::where('email', 'luatsu1@luatvukhang.com')->firstOrFail();
    $accountant = User::where('email', 'ketoan@luatvukhang.com')->firstOrFail();

    $all = Matter::count();
    $lawyerMatters = Matter::query()->listableBy($lawyer)->count();

    expect($all)->toBe(20)
        ->and(Matter::query()->listableBy($admin)->count())->toBe($all)
        ->and(Matter::query()->listableBy($accountant)->count())->toBe($all)
        ->and($lawyerMatters)->toBeGreaterThan(0)->toBeLessThan($all)
        ->and($accountant->can('view', Matter::first()))->toBeFalse();

    Matter::query()->listableBy($lawyer)->get()
        ->each(fn (Matter $matter) => expect($lawyer->can('view', $matter))->toBeTrue());
});

it('shows a seeded client only their own matters and nothing internal', function () {
    $clientUser = ClientUser::where('email', 'khach1@example.com')->firstOrFail();
    $this->actingAs($clientUser, 'client');

    $matters = Matter::get();

    expect($matters)->not->toBeEmpty()
        ->and($matters->pluck('client_id')->unique()->all())->toBe([$clientUser->client_id])
        ->and(StageLog::where('is_published', false)->count())->toBe(0)
        ->and(Document::where('group', 'D')->count())->toBe(0);
});

it('never leaks an internal note into what the portal serializes', function () {
    $clientUser = ClientUser::where('email', 'khach1@example.com')->firstOrFail();
    $notes = StageLog::withoutGlobalScopes()->whereNotNull('internal_note')->pluck('internal_note');

    $this->actingAs($clientUser, 'client');
    $payload = json_encode(Matter::with(['stageLogs', 'client', 'checklistItems'])->get()->toArray(), JSON_UNESCAPED_UNICODE);

    expect($notes)->not->toBeEmpty();
    $notes->each(fn (string $note) => expect($payload)->not->toContain($note));
});
```

- [ ] **Bước 3: Chạy toàn bộ và kiểm tra tay**

Run: `bin/dev test` → toàn bộ xanh.
Run: `bin/dev artisan migrate:fresh --seed` → sạch.
Mở `http://localhost/admin` đăng nhập `ketoan@luatvukhang.com` / `password` và `http://localhost/portal` đăng nhập `khach1@example.com` / `password`; cả hai dashboard phải mở được không lỗi (chưa có resource nên chỉ kiểm tra không vỡ).

- [ ] **Bước 4: Pint và commit**

```bash
bin/dev pint
git add -A
git commit -m "test: portal coverage safety net and seeded acceptance checks for SPEC §11

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 8: Kết thúc M2 — tài liệu

**Files:**
- Modify: `docs/PROGRESS.md`, `README.md`, `docs/superpowers/specs/2026-09-14-vk-crm-toolchain.md`

- [ ] **Bước 1: PROGRESS**

Đổi dòng M2 thành `✅ Xong` với ngày thật và số test thật, rồi thêm mục:
```markdown
## Ghi chú M2

- Ngữ cảnh portal định nghĩa một chỗ duy nhất ở `ClientPortalScope::isActive()`:
  `auth('client')->check() && ! auth('web')->check()`. Vế thứ hai là bắt buộc vì hai panel dùng
  chung cookie phiên; thiếu nó thì nhân sự đăng nhập cả hai panel sẽ bị giới hạn sai ở `/admin`.
- `User` và `ClientUser` cố ý không có scope portal: gọi `auth()` trong global scope của chính
  model xác thực sẽ đệ quy vô hạn. `MatterType`, `MatterTypeStage` cũng không, vì portal cần đọc
  nhãn giai đoạn.
- Ba lớp bảo vệ độc lập: global scope (truy vấn), policy (hành động), và
  `HidesInternalAttributesFromPortal` (serialize). Lớp thứ ba tồn tại vì một dòng tiến độ đã công
  bố vẫn mang `internal_note` trong cùng bản ghi.
- Policy của khách hàng không dùng spatie; nó áp lại chính điều kiện của global scope qua
  `ChecksPortalVisibility::visibleToPortal()`, nên hai tầng không thể lệch nhau.
- `Matter::scopeListableBy()` là định nghĩa duy nhất của "nhân sự thấy vụ việc nào". Mọi resource
  ở M3 trở đi **phải** dùng nó trong `getEloquentQuery()`, nếu không danh sách sẽ rò rỉ vụ việc
  ngoài đội ngũ (SPEC §11 "kể cả trong kết quả tìm kiếm").
- Tách `listableBy` (dòng thấy trong danh sách) khỏi `view` (mở được hồ sơ) vì SPEC §5 cho kế toán
  danh sách rút gọn nhưng không cho xem nội dung.
- **Không cài `filament-shield` ở M2.** Shield sinh quyền từ Filament Resource mà M2 chưa có
  resource; 6 trong 13 quyền SPEC §5 không phải cặp resource-action nên Shield không sinh được.
  Xét lại ở M3, chỉ dùng giao diện gán vai trò và cấu hình để không sinh lại tên quyền.
- Vai trò gán theo chức danh qua `User::assignRoleFromPosition()`. Action sửa nhân sự ở M3 phải
  gọi lại hàm này, nếu không đổi chức danh sẽ không đổi quyền.
- `PortalCoverageTest` là lưới an toàn: mọi model mới ở M3–M8 phải hoặc dùng
  `RestrictedToClientPortal`, hoặc được thêm vào danh sách miễn trừ kèm lý do.
```

- [ ] **Bước 2: README**

Trong bảng tài khoản demo, thêm cột "Vai trò" ứng với từng tài khoản (admin, manager, lawyer, assistant, accountant). Thêm một đoạn ngắn dưới bảng:
```markdown
Phân quyền theo SPEC §5: kế toán chỉ thấy danh sách vụ việc rút gọn và không mở được nội dung;
luật sư chỉ thấy vụ việc có tên mình trong đội ngũ; vụ việc đánh dấu hạn chế chỉ luật sư phụ trách
và quản trị thấy. Khách hàng chỉ thấy hồ sơ của chính mình, chỉ những gì đã được công bố.
```

- [ ] **Bước 3: Toolchain mục 9**

Thêm dòng:
```markdown
| 2026-09-XX | spatie/laravel-permission ^8 | Cài (8.3.0) | Tương thích PHP 8.3 + Laravel 13 |
| 2026-09-XX | bezhansalleh/filament-shield | Hoãn sang M3 | M2 chưa có resource để Shield sinh quyền; tên quyền theo SPEC §5 là nguồn sự thật |
```

- [ ] **Bước 4: Kiểm tra cuối và commit**

```bash
bin/dev test
bin/dev pint --test
git add -A
git commit -m "docs: M2 complete — roles, policies, client data isolation

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Tự rà soát kế hoạch

**Độ phủ SPEC §5:** bảng quyền nhân sự → Task 1 (enum + seeder, test so từng vai trò với bảng) và Task 5–6 (policy). `MatterPolicy::view` kiểm tra `matter.viewAny` hoặc `matter_user`, cộng `confidentiality` → Task 5 `scopeListableBy`. Quy tắc portal (6 gạch đầu dòng) → Task 2–3, mỗi gạch một `applyClientPortalConstraints`. "Phải nằm trong global scope, không viết `where()` ở từng resource" → Task 2–3 và lưới an toàn Task 7.

**Độ phủ SPEC §11:** "Cách ly dữ liệu" → `ClientDataIsolationTest` (kể cả lọc thủ công) + `PortalVisibilityTest`; phần URL 404 thuộc M5 khi có resource, cơ chế đã sẵn vì `find()` trả null. "Tài liệu nội bộ" phần nhóm D dưới guard client → `PortalVisibilityTest` và `ChildPolicyTest`; hai phần còn lại (`PublishDocument`, trạng thái nhóm B) là M4. "Ghi chú nội bộ" → `InternalNotesTest` + `DemoDataAuthorizationTest`. "Quyền nội bộ" ba gạch → `MatterPolicyTest`.

**Nhất quán tên gọi:** `ClientPortalScope::isActive()` dùng ở scope và ở `HidesInternalAttributesFromPortal`. `applyClientPortalConstraints(Builder, ClientUser)` cùng chữ ký ở 15 model và ở `ChecksPortalVisibility`. `Matter::scopeListableBy(Builder, User)` dùng ở `MatterPolicy::view`, `DemoDataAuthorizationTest`, và sẽ dùng ở M3. `Permission::*` và `Role::*` là enum, luôn gọi `->value` khi truyền cho spatie. `ChecksMatterAccess::canSeeMatter()` dùng ở mọi policy con.

**Rủi ro đã lường trước:**
- `whereHas('matter')` có kế thừa global scope của `Matter` không — test ở Task 3 chứng minh trực tiếp; nếu không kế thừa thì phải lặp điều kiện `client_id` ở từng model con và ghi lại quyết định.
- Cache quyền của spatie giữa các test — seeder gọi `forgetCachedPermissions()` hai lần.
- Đệ quy `auth()` trong scope — tránh bằng cách không gắn trait lên hai model xác thực.
- Kế toán: dễ làm sai thành thấy hết hoặc không thấy gì; tách `listableBy` và `view` cùng test riêng.

**Việc cố ý để lại cho milestone sau:** điều kiện `client_access_until` trong `Matter` (M7, đã ghi chú sẵn trong mã); `Document::scopeClientVisible` chưa xét `status` (M4 `PublishDocument`); trang gán vai trò trên giao diện (M3); rate limit và 2FA (M8).

