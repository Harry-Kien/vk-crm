# VK-CRM M0 — Bootstrap Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A running Laravel 13 + Filament 5 application with two panels (`/admin` guard `web`, `/portal` guard `client`), seeded demo accounts for both, Pest + Pint wired, and a complete `.env.example`, so that `sail artisan test` is green and both panels open in a browser.

**Architecture:** Single Laravel app. Two Filament `PanelProvider`s, each bound to its own auth guard backed by its own Eloquent model (`User` on `users`, `ClientUser` on `client_users`). Panel domains are read from `config/vkcrm.php` (fed by `ADMIN_DOMAIN` / `PORTAL_DOMAIN`); empty means path-based routing on one domain. All tooling runs through Laravel Sail (Docker) because the dev machine has no native PHP.

**Tech Stack:** PHP 8.3 (Sail runtime 8.3), Laravel 13, Filament 5 (Livewire 4), MariaDB 10.11 (dev), SQLite in-memory (tests), Pest 4, Laravel Pint, Mailpit.

**Spec:** `docs/SPEC.md` (§2 infra constraints, §3 architecture, §4.1–4.3 users/clients/client_users, §13 M0 row) and `docs/superpowers/specs/2026-09-13-vk-crm-design.md` (§2 versions, §3 domains, §5 local env, §6 conventions).

## Global Constraints

- PHP floor **8.3**. No package or syntax that requires 8.4+. (`design.md` §2)
- Laravel **13.x**, Filament **5.x**, Livewire **4**, Pest **4.x**. (`design.md` §2)
- Never install Redis, Horizon, Octane, Reverb, Pulse, Scout, Meilisearch. (`design.md` §2, SPEC §2)
- Queue driver `database`, cache driver `database`, session driver `database`. One cron line only. (SPEC §2)
- Panels: `/admin` guard `web`, `/portal` guard `client`, `/` → 302 `/portal`. `ADMIN_DOMAIN` / `PORTAL_DOMAIN` empty by default but honoured when set. (SPEC §3, `design.md` §3)
- Code identifiers in English; all user-facing strings in Vietnamese via `__()` keys under `lang/vi/`. (`design.md` §6)
- Every task ends with `sail artisan test` green and `sail bin pint --test` clean before commit.
- Run every command through Sail: from Git Bash use `bash vendor/bin/sail <cmd>`; the alias `sail` below means exactly that. Set `MSYS_NO_PATHCONV=1` in Git Bash when passing absolute paths to `docker`.
- Commit messages end with `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.

---

## File Structure (end state of M0)

| Path | Responsibility |
|---|---|
| `compose.yaml` | Sail services: `laravel.test` (php 8.3), `mariadb`, `mailpit` |
| `.env.example` | Every env key the whole project will need (SPEC-wide), with safe defaults |
| `config/vkcrm.php` | Project settings read from env: domains, matter code prefix, upload limit, retention, heartbeat, clamav |
| `config/auth.php` | Adds guard `client` + provider `client_users` |
| `app/Models/User.php` | Internal staff; implements `FilamentUser`; `canAccessPanel` only for `admin` |
| `app/Models/ClientUser.php` | Portal account; implements `FilamentUser`; `canAccessPanel` only for `portal` |
| `app/Models/Client.php` | Client (customer) record owning `ClientUser`s; minimal in M0, full columns already migrated |
| `app/Enums/UserPosition.php` | `lawyer|assistant|accountant|manager|admin` |
| `app/Enums/ClientType.php` | `individual|organization` |
| `app/Providers/Filament/AdminPanelProvider.php` | Panel `admin`, path `/admin`, guard `web`, neutral gray |
| `app/Providers/Filament/PortalPanelProvider.php` | Panel `portal`, path `/portal`, guard `client`, brand colour |
| `database/migrations/0001_01_01_000000_create_users_table.php` | Modified: adds SPEC §4.1 columns + soft deletes |
| `database/migrations/2026_09_13_000001_create_clients_table.php` | SPEC §4.2 |
| `database/migrations/2026_09_13_000002_create_client_users_table.php` | SPEC §4.3 |
| `database/factories/{User,Client,ClientUser}Factory.php` | Test data |
| `database/seeders/DemoAccountsSeeder.php` | 1 admin + 1 client + 1 client user (M1 will extend) |
| `routes/web.php` | Only the `/` → `/portal` redirect |
| `lang/vi/*.php` | Vietnamese strings (`auth.php`, `validation.php`, `panels.php`) |
| `tests/Feature/Panels/AdminPanelTest.php` | Access rules for `/admin` |
| `tests/Feature/Panels/PortalPanelTest.php` | Access rules for `/portal` |
| `tests/Feature/Panels/PanelDomainTest.php` | Domain env → panel domain wiring |
| `tests/Feature/RootRedirectTest.php` | `/` redirects |
| `docs/PROGRESS.md` | Milestone log |
| `README.md` | Local setup + demo accounts |

---

### Task 1: Create the Laravel 13 skeleton with Sail

**Files:**
- Create: entire Laravel skeleton at repo root (`app/`, `bootstrap/`, `config/`, …), `compose.yaml`
- Modify: `.gitignore` (keep ours, merge Laravel's if it differs)

**Interfaces:**
- Produces: a bootable app; `sail` script at `vendor/bin/sail`; DB service host `mariadb`, database `vk_crm`.

- [ ] **Step 1: Confirm Docker daemon is up**

Run: `docker info --format '{{.ServerVersion}}'`
Expected: a version string (e.g. `29.x`). If it errors, start Docker Desktop and retry until it answers.

- [ ] **Step 2: Create the project into a temp subfolder using the Sail composer image**

The repo root is not empty (docs, `.git`), so create into `_app` then move up.

Run (Git Bash):
```bash
MSYS_NO_PATHCONV=1 docker run --rm \
  -v "D:/crmkhachhang:/opt" -w /opt \
  laravelsail/php83-composer:latest \
  bash -c "composer create-project laravel/laravel:^13.0 _app --no-interaction --prefer-dist"
```
Expected: `_app/` exists with `artisan`, `composer.json` showing `"laravel/framework": "^13.0"`.

If the `php83-composer` tag is unavailable, use `laravelsail/php84-composer:latest` for this one-off step only; runtime PHP is fixed to 8.3 in Step 4.

- [ ] **Step 3: Move the skeleton to the repo root**

Run:
```bash
cd /d/crmkhachhang && shopt -s dotglob && \
  mv _app/.gitignore _app/.gitignore.laravel && \
  mv _app/* . && rmdir _app && \
  diff .gitignore .gitignore.laravel; rm .gitignore.laravel
```
Expected: `artisan` at repo root; `diff` shows no meaningful differences (ours already matches the Laravel 12/13 template). If Laravel's has extra lines, append them to `.gitignore`.

- [ ] **Step 4: Install Sail with MariaDB + Mailpit, PHP 8.3 runtime**

Run:
```bash
MSYS_NO_PATHCONV=1 docker run --rm -v "D:/crmkhachhang:/opt" -w /opt \
  laravelsail/php83-composer:latest \
  bash -c "composer require laravel/sail --dev --no-interaction && php artisan sail:install --with=mariadb,mailpit --no-interaction"
```
Then open `compose.yaml` and confirm the `laravel.test` service uses `context: ./vendor/laravel/sail/runtimes/8.3` and `image: sail-8.3/app`. If it says 8.4 or 8.5, change both to 8.3.

Expected: `compose.yaml` has services `laravel.test`, `mariadb`, `mailpit`.

- [ ] **Step 5: Configure `.env` for Sail and Vietnamese defaults**

Edit `.env` (created by create-project) so these keys read:
```dotenv
APP_NAME="VK-CRM"
APP_ENV=local
APP_URL=http://localhost
APP_LOCALE=vi
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=vi_VN
APP_TIMEZONE=Asia/Ho_Chi_Minh

DB_CONNECTION=mariadb
DB_HOST=mariadb
DB_PORT=3306
DB_DATABASE=vk_crm
DB_USERNAME=sail
DB_PASSWORD=password

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_FROM_ADDRESS="no-reply@luatvukhang.com"
MAIL_FROM_NAME="${APP_NAME}"
```
Note: `APP_TIMEZONE` is not read by default config; Task 3 wires it in `config/app.php`.

- [ ] **Step 6: Build and start the containers, run migrations**

Run:
```bash
bash vendor/bin/sail build --no-cache && bash vendor/bin/sail up -d && \
  bash vendor/bin/sail artisan migrate --force
```
Expected: three containers running (`sail ps`); migrate output lists `users`, `cache`, `jobs` tables. Sail's `mariadb` service creates database `vk_crm` from `DB_DATABASE` on first boot.

- [ ] **Step 7: Verify PHP version and the welcome page**

Run: `bash vendor/bin/sail php -v && curl -s -o /dev/null -w "%{http_code}\n" http://localhost`
Expected: `PHP 8.3.x` and `200`.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "chore: bootstrap Laravel 13 skeleton with Sail (php 8.3, mariadb, mailpit)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 2: Install Pest 4 and Pint, prove the test loop works

**Files:**
- Modify: `composer.json` (dev deps), `phpunit.xml`
- Create: `tests/Pest.php`, `tests/TestCase.php` (Pest init rewrites), `pint.json`
- Delete: `tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php` (replace with Pest style)

**Interfaces:**
- Produces: `sail artisan test` runs Pest; `sail bin pint --test` lints; `tests/Pest.php` binds `Tests\TestCase` + `RefreshDatabase` to `tests/Feature`.

- [ ] **Step 1: Remove PHPUnit example tests and install Pest**

Run:
```bash
bash vendor/bin/sail composer remove phpunit/phpunit --dev --no-interaction || true
bash vendor/bin/sail composer require pestphp/pest:^4.0 pestphp/pest-plugin-laravel:^4.0 --dev --with-all-dependencies --no-interaction
rm -f tests/Feature/ExampleTest.php tests/Unit/ExampleTest.php
bash vendor/bin/sail bin pest --init
```
Expected: `tests/Pest.php` created.

- [ ] **Step 2: Write `tests/Pest.php`**

Replace the generated file with:
```php
<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');
```

- [ ] **Step 3: Confirm `phpunit.xml` uses SQLite in memory and Vietnamese locale**

`phpunit.xml` `<php>` block must contain (add any missing line):
```xml
<env name="APP_ENV" value="testing"/>
<env name="APP_LOCALE" value="vi"/>
<env name="APP_MAINTENANCE_DRIVER" value="file"/>
<env name="BCRYPT_ROUNDS" value="4"/>
<env name="CACHE_STORE" value="array"/>
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
<env name="MAIL_MAILER" value="array"/>
<env name="QUEUE_CONNECTION" value="sync"/>
<env name="SESSION_DRIVER" value="array"/>
<env name="ADMIN_DOMAIN" value=""/>
<env name="PORTAL_DOMAIN" value=""/>
```

- [ ] **Step 4: Write a smoke test**

Create `tests/Feature/SmokeTest.php`:
```php
<?php

it('boots the application', function () {
    expect(app()->environment())->toBe('testing');
});
```

- [ ] **Step 5: Run the suite**

Run: `bash vendor/bin/sail artisan test`
Expected: `Tests: 1 passed`.

- [ ] **Step 6: Install Pint config and run it**

Create `pint.json`:
```json
{
    "preset": "laravel",
    "rules": {
        "declare_strict_types": false,
        "ordered_imports": { "sort_algorithm": "alpha" }
    }
}
```
Run: `bash vendor/bin/sail bin pint`
Expected: `PASS` or files fixed with no errors.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "chore: add Pest 4 and Pint

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 3: Project config (`config/vkcrm.php`) and complete `.env.example`

**Files:**
- Create: `config/vkcrm.php`
- Modify: `config/app.php` (timezone from env), `.env.example`, `.env`
- Test: `tests/Unit/VkcrmConfigTest.php`

**Interfaces:**
- Produces: `config('vkcrm.admin_domain')`, `config('vkcrm.portal_domain')` (string|null), `config('vkcrm.matter_code_prefix')` (string, default `VK`), `config('vkcrm.upload_max_mb')` (int, default 20), `config('vkcrm.retention_years')` (int, default 10), `config('vkcrm.client_access_days')` (int, default 90), `config('vkcrm.heartbeat_url')` (string|null), `config('vkcrm.clamav.enabled')` (bool), `config('vkcrm.clamav.socket')` (string), `config('vkcrm.brand_color')` (hex string).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/VkcrmConfigTest.php`:
```php
<?php

it('exposes project settings with safe defaults', function () {
    expect(config('vkcrm.admin_domain'))->toBeNull()
        ->and(config('vkcrm.portal_domain'))->toBeNull()
        ->and(config('vkcrm.matter_code_prefix'))->toBe('VK')
        ->and(config('vkcrm.upload_max_mb'))->toBe(20)
        ->and(config('vkcrm.retention_years'))->toBe(10)
        ->and(config('vkcrm.client_access_days'))->toBe(90)
        ->and(config('vkcrm.clamav.enabled'))->toBeFalse()
        ->and(config('vkcrm.brand_color'))->toMatch('/^#[0-9a-fA-F]{6}$/');
});

it('treats blank domain env as null', function () {
    // Mirrors the transform in config/vkcrm.php
    $normalize = fn (?string $v) => filled($v) ? $v : null;

    expect($normalize(''))->toBeNull()
        ->and($normalize('crm.example.test'))->toBe('crm.example.test');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `bash vendor/bin/sail artisan test --filter=VkcrmConfigTest`
Expected: FAIL (`config('vkcrm.matter_code_prefix')` is null).

- [ ] **Step 3: Create `config/vkcrm.php`**

```php
<?php

$domain = static fn (?string $value): ?string => filled($value) ? $value : null;

return [
    // Tên miền riêng cho từng panel. Để trống => chạy chung một tên miền theo đường dẫn.
    'admin_domain' => $domain(env('ADMIN_DOMAIN')),
    'portal_domain' => $domain(env('PORTAL_DOMAIN')),

    // Tiền tố mã hồ sơ: {prefix}-{YYYY}-{loại}-{0001}
    'matter_code_prefix' => env('MATTER_CODE_PREFIX', 'VK'),

    // Giới hạn tệp khách nộp (MB)
    'upload_max_mb' => (int) env('UPLOAD_MAX_MB', 20),

    // Chính sách lưu trữ
    'retention_years' => (int) env('RETENTION_YEARS', 10),
    'client_access_days' => (int) env('CLIENT_ACCESS_DAYS', 90),

    // Giám sát cron
    'heartbeat_url' => $domain(env('HEARTBEAT_URL')),

    'clamav' => [
        'enabled' => (bool) env('CLAMAV_ENABLED', false),
        'socket' => env('CLAMAV_SOCKET', '/var/run/clamav/clamd.ctl'),
    ],

    // Màu thương hiệu dùng cho panel portal
    'brand_color' => env('BRAND_COLOR', '#1e3a8a'),
];
```

- [ ] **Step 4: Wire timezone in `config/app.php`**

Change `'timezone' => 'UTC',` to `'timezone' => env('APP_TIMEZONE', 'Asia/Ho_Chi_Minh'),`.

- [ ] **Step 5: Write the full `.env.example`**

Replace `.env.example` with:
```dotenv
APP_NAME="VK-CRM"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost
APP_TIMEZONE=Asia/Ho_Chi_Minh

APP_LOCALE=vi
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=vi_VN

APP_MAINTENANCE_DRIVER=file
PHP_CLI_SERVER_WORKERS=4
BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

# --- Cơ sở dữ liệu (Sail: host mariadb; shared hosting: localhost) ---
DB_CONNECTION=mariadb
DB_HOST=mariadb
DB_PORT=3306
DB_DATABASE=vk_crm
DB_USERNAME=sail
DB_PASSWORD=password

# --- Bắt buộc dùng database cho session/queue/cache (không Redis) ---
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null
QUEUE_CONNECTION=database
CACHE_STORE=database
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local

# --- Email qua SMTP tên miền riêng ---
MAIL_MAILER=smtp
MAIL_SCHEME=null
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="no-reply@luatvukhang.com"
MAIL_FROM_NAME="${APP_NAME}"

# --- VK-CRM ---
# Để trống cả hai => /admin và /portal chạy chung APP_URL. Điền để tách subdomain.
ADMIN_DOMAIN=
PORTAL_DOMAIN=
MATTER_CODE_PREFIX=VK
UPLOAD_MAX_MB=20
RETENTION_YEARS=10
CLIENT_ACCESS_DAYS=90
BRAND_COLOR=#1e3a8a
# URL ping của dịch vụ giám sát cron (ví dụ healthchecks.io). Để trống để tắt.
HEARTBEAT_URL=
CLAMAV_ENABLED=false
CLAMAV_SOCKET=/var/run/clamav/clamd.ctl

# --- Sao lưu ngoài máy chủ (S3-compatible: R2/B2/S3) ---
BACKUP_DISK=s3
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=auto
AWS_BUCKET=
AWS_ENDPOINT=
AWS_USE_PATH_STYLE_ENDPOINT=true

# --- Sail ---
APP_PORT=80
FORWARD_DB_PORT=3306
FORWARD_MAILPIT_PORT=1025
FORWARD_MAILPIT_DASHBOARD_PORT=8025
WWWUSER=1000
WWWGROUP=1000

VITE_APP_NAME="${APP_NAME}"
```
Then copy the new `VK-CRM` and `Sao lưu` blocks into `.env` as well.

- [ ] **Step 6: Run tests and Pint**

Run: `bash vendor/bin/sail artisan config:clear && bash vendor/bin/sail artisan test && bash vendor/bin/sail bin pint --test`
Expected: all pass.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: add vkcrm config and complete .env.example

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 4: Users, clients, client_users schema + models + enums + factories

**Files:**
- Modify: `database/migrations/0001_01_01_000000_create_users_table.php`
- Create: `database/migrations/2026_09_13_000001_create_clients_table.php`, `database/migrations/2026_09_13_000002_create_client_users_table.php`
- Create: `app/Enums/UserPosition.php`, `app/Enums/ClientType.php`
- Modify: `app/Models/User.php`; Create: `app/Models/Client.php`, `app/Models/ClientUser.php`
- Modify: `database/factories/UserFactory.php`; Create: `database/factories/ClientFactory.php`, `database/factories/ClientUserFactory.php`
- Test: `tests/Feature/Models/UserModelTest.php`, `tests/Feature/Models/ClientUserModelTest.php`

**Interfaces:**
- Produces:
  - `App\Enums\UserPosition: string { Lawyer='lawyer'; Assistant='assistant'; Accountant='accountant'; Manager='manager'; Admin='admin' }` with `label(): string`
  - `App\Enums\ClientType: string { Individual='individual'; Organization='organization' }` with `label(): string`
  - `App\Models\User` (`SoftDeletes`; casts `position` → `UserPosition`, `is_active` → bool, `last_login_at` → datetime; `password` hashed)
  - `App\Models\Client` (`SoftDeletes`; `id_number` cast `encrypted`; `hasMany(ClientUser::class) clientUsers()`)
  - `App\Models\ClientUser extends Authenticatable` (`SoftDeletes`; `belongsTo(Client::class) client()`; casts `is_active`, `must_change_password` → bool)
  - Factories: `User::factory()`, `Client::factory()`, `ClientUser::factory()` (auto-creates a `Client`)

- [ ] **Step 1: Write the failing model tests**

Create `tests/Feature/Models/UserModelTest.php`:
```php
<?php

use App\Enums\UserPosition;
use App\Models\User;

it('stores position as an enum and defaults to active', function () {
    $user = User::factory()->create(['position' => UserPosition::Lawyer]);

    expect($user->fresh()->position)->toBe(UserPosition::Lawyer)
        ->and($user->is_active)->toBeTrue()
        ->and($user->deleted_at)->toBeNull();
});

it('soft deletes users', function () {
    $user = User::factory()->create();
    $user->delete();

    expect(User::count())->toBe(0)
        ->and(User::withTrashed()->count())->toBe(1);
});
```

Create `tests/Feature/Models/ClientUserModelTest.php`:
```php
<?php

use App\Enums\ClientType;
use App\Models\Client;
use App\Models\ClientUser;

it('belongs to a client and must change password on creation', function () {
    $clientUser = ClientUser::factory()->create();

    expect($clientUser->client)->toBeInstanceOf(Client::class)
        ->and($clientUser->must_change_password)->toBeTrue()
        ->and($clientUser->is_active)->toBeTrue();
});

it('encrypts the client id_number at rest', function () {
    $client = Client::factory()->create([
        'type' => ClientType::Individual,
        'id_number' => '079123456789',
    ]);

    $raw = \DB::table('clients')->where('id', $client->id)->value('id_number');

    expect($raw)->not->toBe('079123456789')
        ->and($client->fresh()->id_number)->toBe('079123456789');
});

it('generates a unique client code per client', function () {
    $a = Client::factory()->create();
    $b = Client::factory()->create();

    expect($a->code)->toMatch('/^KH-\d{4}-\d{4}$/')
        ->and($a->code)->not->toBe($b->code);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `bash vendor/bin/sail artisan test --filter=ModelTest`
Expected: FAIL (`Class "App\Enums\UserPosition" not found`).

- [ ] **Step 3: Create enums**

`app/Enums/UserPosition.php`:
```php
<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum UserPosition: string implements HasLabel
{
    case Lawyer = 'lawyer';
    case Assistant = 'assistant';
    case Accountant = 'accountant';
    case Manager = 'manager';
    case Admin = 'admin';

    public function label(): string
    {
        return __('enums.user_position.'.$this->value);
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
```
Note: `Filament\Support\Contracts\HasLabel` only exists after Task 5 installs Filament. To keep this task independent, **omit `implements HasLabel` and `getLabel()` now**; Task 5 Step 9 adds them.

`app/Enums/ClientType.php`:
```php
<?php

namespace App\Enums;

enum ClientType: string
{
    case Individual = 'individual';
    case Organization = 'organization';

    public function label(): string
    {
        return __('enums.client_type.'.$this->value);
    }
}
```

Create `lang/vi/enums.php`:
```php
<?php

return [
    'user_position' => [
        'lawyer' => 'Luật sư',
        'assistant' => 'Trợ lý',
        'accountant' => 'Kế toán',
        'manager' => 'Trưởng phòng',
        'admin' => 'Quản trị',
    ],
    'client_type' => [
        'individual' => 'Cá nhân',
        'organization' => 'Tổ chức',
    ],
];
```

- [ ] **Step 4: Rewrite the users migration**

Replace the `users` table block in `database/migrations/0001_01_01_000000_create_users_table.php` (keep the `password_reset_tokens` and `sessions` blocks as generated):
```php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('name', 100);
    $table->string('email', 150)->unique();
    $table->timestamp('email_verified_at')->nullable();
    $table->string('password');
    $table->string('phone', 20)->nullable();
    $table->string('position', 20)->default('lawyer');
    $table->string('bar_number', 50)->nullable();
    $table->boolean('is_active')->default(true);
    $table->text('two_factor_secret')->nullable();
    $table->text('two_factor_recovery_codes')->nullable();
    $table->timestamp('two_factor_confirmed_at')->nullable();
    $table->timestamp('last_login_at')->nullable();
    $table->rememberToken();
    $table->timestamps();
    $table->softDeletes();
});
```
(`position` is a string column, not a DB enum, so adding roles later needs no migration; the PHP enum is the source of truth.)

- [ ] **Step 5: Create clients and client_users migrations**

`database/migrations/2026_09_13_000001_create_clients_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('type', 20);
            $table->string('name', 200);
            $table->text('id_number')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address', 300)->nullable();
            $table->string('representative_name', 120)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
```

`database/migrations/2026_09_13_000002_create_client_users_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('email', 150)->unique();
            $table->string('phone', 20)->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(true);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_users');
    }
};
```

- [ ] **Step 6: Write the models**

`app/Models/User.php`:
```php
<?php

namespace App\Models;

use App\Enums\UserPosition;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'name', 'email', 'password', 'phone', 'position', 'bar_number', 'is_active', 'last_login_at',
    ];

    protected $hidden = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'position' => UserPosition::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
```

`app/Models/Client.php`:
```php
<?php

namespace App\Models;

use App\Enums\ClientType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Client extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'code', 'type', 'name', 'id_number', 'phone', 'email', 'address', 'representative_name', 'note',
    ];

    protected function casts(): array
    {
        return [
            'type' => ClientType::class,
            'id_number' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Client $client): void {
            $client->code ??= static::nextCode();
        });
    }

    public function clientUsers(): HasMany
    {
        return $this->hasMany(ClientUser::class);
    }

    /**
     * KH-{YYYY}-{0001}, sequence restarts each year. Locks the latest row for the year.
     */
    public static function nextCode(): string
    {
        $year = now()->format('Y');
        $prefix = "KH-{$year}-";

        return DB::transaction(function () use ($prefix): string {
            $last = static::withTrashed()
                ->where('code', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('code')
                ->value('code');

            $next = $last ? ((int) substr($last, -4)) + 1 : 1;

            return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        });
    }
}
```

`app/Models/ClientUser.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class ClientUser extends Authenticatable
{
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'client_id', 'name', 'email', 'phone', 'password', 'is_active',
        'must_change_password', 'activated_at', 'last_login_at', 'last_login_ip',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'activated_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
```

- [ ] **Step 7: Write factories**

`database/factories/UserFactory.php` (replace `definition()`):
```php
public function definition(): array
{
    return [
        'name' => fake()->name(),
        'email' => fake()->unique()->safeEmail(),
        'email_verified_at' => now(),
        'password' => static::$password ??= Hash::make('password'),
        'phone' => fake()->numerify('09########'),
        'position' => UserPosition::Lawyer,
        'is_active' => true,
        'remember_token' => Str::random(10),
    ];
}

public function admin(): static
{
    return $this->state(fn () => ['position' => UserPosition::Admin]);
}

public function position(UserPosition $position): static
{
    return $this->state(fn () => ['position' => $position]);
}
```
Add `use App\Enums\UserPosition;` at the top.

`database/factories/ClientFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Enums\ClientType;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Client> */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'type' => ClientType::Individual,
            'name' => fake()->name(),
            'id_number' => fake()->numerify('0############'),
            'phone' => fake()->numerify('09########'),
            'email' => fake()->unique()->safeEmail(),
            'address' => fake()->address(),
        ];
    }

    public function organization(): static
    {
        return $this->state(fn () => [
            'type' => ClientType::Organization,
            'name' => fake()->company(),
            'representative_name' => fake()->name(),
        ]);
    }
}
```

`database/factories/ClientUserFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<ClientUser> */
class ClientUserFactory extends Factory
{
    protected $model = ClientUser::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('09########'),
            'password' => static::$password ??= Hash::make('password'),
            'is_active' => true,
            'must_change_password' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function activated(): static
    {
        return $this->state(fn () => ['must_change_password' => false, 'activated_at' => now()]);
    }
}
```

- [ ] **Step 8: Run tests**

Run: `bash vendor/bin/sail artisan migrate:fresh && bash vendor/bin/sail artisan test`
Expected: all pass, including the 5 model tests.

- [ ] **Step 9: Pint and commit**

```bash
bash vendor/bin/sail bin pint
git add -A
git commit -m "feat: users, clients, client_users schema with models, enums, factories

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 5: Install Filament 5 and configure the `client` guard

**Files:**
- Modify: `composer.json`, `config/auth.php`, `app/Enums/UserPosition.php`, `app/Enums/ClientType.php`
- Create (by installer): `app/Providers/Filament/AdminPanelProvider.php`, `bootstrap/providers.php` entry, `public/css/filament/*`, `public/js/filament/*`
- Test: `tests/Feature/AuthGuardTest.php`

**Interfaces:**
- Produces: guard `client` (session driver, provider `client_users` → `App\Models\ClientUser`); `auth('client')` usable everywhere. `AdminPanelProvider` registered with id `admin`, path `admin`.

- [ ] **Step 1: Write the failing guard test**

Create `tests/Feature/AuthGuardTest.php`:
```php
<?php

use App\Models\ClientUser;
use App\Models\User;

it('authenticates staff on the web guard only', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web');

    expect(auth('web')->check())->toBeTrue()
        ->and(auth('client')->check())->toBeFalse();
});

it('authenticates clients on the client guard only', function () {
    $clientUser = ClientUser::factory()->create();

    $this->actingAs($clientUser, 'client');

    expect(auth('client')->check())->toBeTrue()
        ->and(auth('client')->user()->is($clientUser))->toBeTrue()
        ->and(auth('web')->check())->toBeFalse();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `bash vendor/bin/sail artisan test --filter=AuthGuardTest`
Expected: FAIL with `Auth guard [client] is not defined`.

- [ ] **Step 3: Add the guard and provider to `config/auth.php`**

In `guards` add:
```php
'client' => [
    'driver' => 'session',
    'provider' => 'client_users',
],
```
In `providers` add:
```php
'client_users' => [
    'driver' => 'eloquent',
    'model' => App\Models\ClientUser::class,
],
```
In `passwords` add:
```php
'client_users' => [
    'provider' => 'client_users',
    'table' => 'password_reset_tokens',
    'expire' => 60,
    'throttle' => 60,
],
```

- [ ] **Step 4: Run the guard test**

Run: `bash vendor/bin/sail artisan test --filter=AuthGuardTest`
Expected: PASS.

- [ ] **Step 5: Install Filament 5 with the admin panel**

Run:
```bash
bash vendor/bin/sail composer require filament/filament:"^5.0" --with-all-dependencies --no-interaction
bash vendor/bin/sail artisan filament:install --panels --no-interaction
```
Expected: `app/Providers/Filament/AdminPanelProvider.php` created with `->id('admin')->path('admin')`; `bootstrap/providers.php` lists it. Confirm `composer show livewire/livewire` reports `4.x`.

- [ ] **Step 6: Add Filament label contract to enums**

Change `app/Enums/UserPosition.php` header to `enum UserPosition: string implements \Filament\Support\Contracts\HasLabel` and add:
```php
public function getLabel(): string
{
    return $this->label();
}
```
Do the same for `ClientType`.

- [ ] **Step 7: Verify the admin panel boots**

Run: `bash vendor/bin/sail artisan route:list --path=admin | head -20 && curl -s -o /dev/null -w "%{http_code}\n" -L http://localhost/admin`
Expected: routes `admin/login`, `admin` listed; final HTTP 200 (login page).

- [ ] **Step 8: Run full suite, Pint, commit**

```bash
bash vendor/bin/sail artisan test && bash vendor/bin/sail bin pint
git add -A
git commit -m "feat: install Filament 5 and add client auth guard

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 6: Admin panel — guard `web`, neutral colours, `FilamentUser` on `User`

**Files:**
- Modify: `app/Providers/Filament/AdminPanelProvider.php`, `app/Models/User.php`
- Create: `lang/vi/panels.php`
- Test: `tests/Feature/Panels/AdminPanelTest.php`

**Interfaces:**
- Consumes: `User::factory()`, guard `web`.
- Produces: `User implements Filament\Models\Contracts\FilamentUser`; `canAccessPanel(Panel $panel): bool` = `$panel->getId() === 'admin' && $this->is_active`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Panels/AdminPanelTest.php`:
```php
<?php

use App\Models\ClientUser;
use App\Models\User;

it('redirects guests to the admin login', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('lets an active staff user open the admin dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')->get('/admin')->assertOk();
});

it('blocks an inactive staff user', function () {
    $user = User::factory()->create(['is_active' => false]);

    $this->actingAs($user, 'web')->get('/admin')->assertForbidden();
});

it('does not accept a client session on the admin panel', function () {
    $clientUser = ClientUser::factory()->create();

    $this->actingAs($clientUser, 'client')->get('/admin')->assertRedirect('/admin/login');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `bash vendor/bin/sail artisan test --filter=AdminPanelTest`
Expected: at least "lets an active staff user" FAILS (Filament returns 403 because `User` does not implement `FilamentUser` in non-local env).

- [ ] **Step 3: Implement `FilamentUser` on `User`**

In `app/Models/User.php` add imports and contract:
```php
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

class User extends Authenticatable implements FilamentUser
{
    // ...existing...

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin' && $this->is_active;
    }
}
```

- [ ] **Step 4: Configure the admin panel provider**

Replace `panel()` in `app/Providers/Filament/AdminPanelProvider.php`:
```php
public function panel(Panel $panel): Panel
{
    return $panel
        ->id('admin')
        ->path('admin')
        ->domain(config('vkcrm.admin_domain'))
        ->authGuard('web')
        ->authPasswordBroker('users')
        ->login()
        ->brandName(__('panels.admin.brand'))
        ->colors([
            'primary' => Color::Slate,
            'gray' => Color::Zinc,
        ])
        ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
        ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
        ->pages([
            Dashboard::class,
        ])
        ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
        ->widgets([
            AccountWidget::class,
        ])
        ->middleware([
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            AuthenticateSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            SubstituteBindings::class,
            DisableBladeIconComponents::class,
            DispatchServingFilamentEvent::class,
        ])
        ->authMiddleware([
            Authenticate::class,
        ]);
}
```
Keep the generated `use` statements; the installer already imports `Color`, `Dashboard`, `AccountWidget`, and the middleware classes. Create the directories `app/Filament/Admin/{Resources,Pages,Widgets}` with a `.gitkeep` each.

- [ ] **Step 5: Vietnamese panel strings**

Create `lang/vi/panels.php`:
```php
<?php

return [
    'admin' => [
        'brand' => 'VK-CRM · Nội bộ',
    ],
    'portal' => [
        'brand' => 'Luật Vũ Khang · Tra cứu hồ sơ',
    ],
];
```

- [ ] **Step 6: Run the tests**

Run: `bash vendor/bin/sail artisan test --filter=AdminPanelTest`
Expected: 4 passed.

- [ ] **Step 7: Pint and commit**

```bash
bash vendor/bin/sail bin pint
git add -A
git commit -m "feat: admin panel bound to web guard with FilamentUser access rule

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 7: Portal panel — guard `client`, brand colour, root redirect

**Files:**
- Create: `app/Providers/Filament/PortalPanelProvider.php`, `app/Filament/Portal/{Resources,Pages,Widgets}/.gitkeep`
- Modify: `bootstrap/providers.php`, `app/Models/ClientUser.php`, `routes/web.php`
- Test: `tests/Feature/Panels/PortalPanelTest.php`, `tests/Feature/RootRedirectTest.php`

**Interfaces:**
- Consumes: guard `client`, `ClientUser::factory()`, `config('vkcrm.portal_domain')`, `config('vkcrm.brand_color')`.
- Produces: panel id `portal`, path `portal`; `ClientUser implements FilamentUser` with `canAccessPanel` = `$panel->getId() === 'portal' && $this->is_active`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Panels/PortalPanelTest.php`:
```php
<?php

use App\Models\ClientUser;
use App\Models\User;

it('redirects guests to the portal login', function () {
    $this->get('/portal')->assertRedirect('/portal/login');
});

it('lets an active client user open the portal', function () {
    $clientUser = ClientUser::factory()->create();

    $this->actingAs($clientUser, 'client')->get('/portal')->assertOk();
});

it('blocks an inactive client user', function () {
    $clientUser = ClientUser::factory()->create(['is_active' => false]);

    $this->actingAs($clientUser, 'client')->get('/portal')->assertForbidden();
});

it('does not accept a staff session on the portal', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')->get('/portal')->assertRedirect('/portal/login');
});
```

Create `tests/Feature/RootRedirectTest.php`:
```php
<?php

it('sends the root url to the portal', function () {
    $this->get('/')->assertRedirect('/portal');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `bash vendor/bin/sail artisan test --filter="PortalPanelTest|RootRedirectTest"`
Expected: FAIL (404 on `/portal`; `/` returns 200 welcome page).

- [ ] **Step 3: Generate and configure the portal panel**

Run: `bash vendor/bin/sail artisan make:filament-panel portal --no-interaction`

Replace `panel()` in `app/Providers/Filament/PortalPanelProvider.php`:
```php
public function panel(Panel $panel): Panel
{
    return $panel
        ->id('portal')
        ->path('portal')
        ->domain(config('vkcrm.portal_domain'))
        ->authGuard('client')
        ->authPasswordBroker('client_users')
        ->login()
        ->brandName(__('panels.portal.brand'))
        ->colors([
            'primary' => Color::hex(config('vkcrm.brand_color')),
        ])
        ->discoverResources(in: app_path('Filament/Portal/Resources'), for: 'App\Filament\Portal\Resources')
        ->discoverPages(in: app_path('Filament/Portal/Pages'), for: 'App\Filament\Portal\Pages')
        ->pages([
            Dashboard::class,
        ])
        ->discoverWidgets(in: app_path('Filament/Portal/Widgets'), for: 'App\Filament\Portal\Widgets')
        ->widgets([])
        ->middleware([
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            AuthenticateSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            SubstituteBindings::class,
            DisableBladeIconComponents::class,
            DispatchServingFilamentEvent::class,
        ])
        ->authMiddleware([
            Authenticate::class,
        ]);
}
```
Ensure `bootstrap/providers.php` contains `App\Providers\Filament\PortalPanelProvider::class` (the `make:filament-panel` command adds it; verify). Create `app/Filament/Portal/{Resources,Pages,Widgets}/.gitkeep`.

- [ ] **Step 4: Implement `FilamentUser` on `ClientUser`**

In `app/Models/ClientUser.php`:
```php
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

class ClientUser extends Authenticatable implements FilamentUser
{
    // ...existing...

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'portal' && $this->is_active;
    }
}
```

- [ ] **Step 5: Root redirect**

Replace `routes/web.php` with:
```php
<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/portal');
```
Delete `resources/views/welcome.blade.php`.

- [ ] **Step 6: Run the tests**

Run: `bash vendor/bin/sail artisan test`
Expected: all green (config, models, guards, admin 4, portal 4, redirect 1, smoke 1).

- [ ] **Step 7: Manual check in browser**

Run: `curl -s -o /dev/null -w "%{http_code} %{redirect_url}\n" http://localhost/ && curl -s -o /dev/null -w "%{http_code}\n" http://localhost/portal/login`
Expected: `302 http://localhost/portal` then `200`.

- [ ] **Step 8: Pint and commit**

```bash
bash vendor/bin/sail bin pint
git add -A
git commit -m "feat: portal panel bound to client guard, root redirects to portal

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 8: Panel domain wiring test (path mode vs subdomain mode)

**Files:**
- Test: `tests/Feature/Panels/PanelDomainTest.php`

**Interfaces:**
- Consumes: `AdminPanelProvider::panel(Panel)`, `PortalPanelProvider::panel(Panel)` (public, from Filament's `PanelProvider`), `config('vkcrm.*_domain')`.

- [ ] **Step 1: Write the test**

Create `tests/Feature/Panels/PanelDomainTest.php`:
```php
<?php

use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\PortalPanelProvider;
use Filament\Panel;

it('serves both panels on one domain when no domain env is set', function () {
    config(['vkcrm.admin_domain' => null, 'vkcrm.portal_domain' => null]);

    $admin = (new AdminPanelProvider(app()))->panel(Panel::make());
    $portal = (new PortalPanelProvider(app()))->panel(Panel::make());

    expect($admin->getDomains())->toBe([])
        ->and($portal->getDomains())->toBe([])
        ->and($admin->getPath())->toBe('admin')
        ->and($portal->getPath())->toBe('portal');
});

it('binds each panel to its own domain when env is set', function () {
    config([
        'vkcrm.admin_domain' => 'crm.luatvukhang.test',
        'vkcrm.portal_domain' => 'khachhang.luatvukhang.test',
    ]);

    $admin = (new AdminPanelProvider(app()))->panel(Panel::make());
    $portal = (new PortalPanelProvider(app()))->panel(Panel::make());

    expect($admin->getDomains())->toBe(['crm.luatvukhang.test'])
        ->and($portal->getDomains())->toBe(['khachhang.luatvukhang.test']);
});
```

- [ ] **Step 2: Run it**

Run: `bash vendor/bin/sail artisan test --filter=PanelDomainTest`
Expected: PASS. If `getDomains()` returns `[null]` in path mode, change both providers to `->domain(config('vkcrm.admin_domain') ?: null)` is not enough; instead wrap: `->domains(array_filter([config('vkcrm.admin_domain')]))` and re-run.

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "test: panel domain wiring for path and subdomain modes

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 9: Demo seeder, README, PROGRESS, and M0 acceptance

**Files:**
- Create: `database/seeders/DemoAccountsSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Create: `README.md` (replace Laravel default), `docs/PROGRESS.md`
- Test: `tests/Feature/Seeders/DemoAccountsSeederTest.php`

**Interfaces:**
- Produces: seeded accounts:
  - admin `admin@luatvukhang.com` / `password` (position `admin`)
  - client user `khach1@example.com` / `password` (client `KH-{year}-0001`, `must_change_password = false`)
  - M1 will replace this seeder with the full §12 dataset but must keep these two logins.

- [ ] **Step 1: Write the failing seeder test**

Create `tests/Feature/Seeders/DemoAccountsSeederTest.php`:
```php
<?php

use App\Enums\UserPosition;
use App\Models\ClientUser;
use App\Models\User;
use Database\Seeders\DemoAccountsSeeder;

it('seeds one admin and one activated client login', function () {
    $this->seed(DemoAccountsSeeder::class);

    $admin = User::where('email', 'admin@luatvukhang.com')->firstOrFail();
    $client = ClientUser::where('email', 'khach1@example.com')->firstOrFail();

    expect($admin->position)->toBe(UserPosition::Admin)
        ->and(Hash::check('password', $admin->password))->toBeTrue()
        ->and($client->must_change_password)->toBeFalse()
        ->and($client->client->code)->toBe('KH-'.now()->format('Y').'-0001');
});

it('is idempotent', function () {
    $this->seed(DemoAccountsSeeder::class);
    $this->seed(DemoAccountsSeeder::class);

    expect(User::count())->toBe(1)->and(ClientUser::count())->toBe(1);
});
```
Add `use Illuminate\Support\Facades\Hash;` at the top.

- [ ] **Step 2: Run to verify failure**

Run: `bash vendor/bin/sail artisan test --filter=DemoAccountsSeederTest`
Expected: FAIL (`Class "Database\Seeders\DemoAccountsSeeder" not found`).

- [ ] **Step 3: Write the seeder**

`database/seeders/DemoAccountsSeeder.php`:
```php
<?php

namespace Database\Seeders;

use App\Enums\ClientType;
use App\Enums\UserPosition;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoAccountsSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@luatvukhang.com'],
            [
                'name' => 'Quản trị hệ thống',
                'password' => 'password',
                'position' => UserPosition::Admin,
                'is_active' => true,
            ],
        );

        $client = Client::query()->firstOrCreate(
            ['email' => 'khach1@example.com'],
            [
                'type' => ClientType::Individual,
                'name' => 'Nguyễn Văn An',
                'id_number' => '079090001234',
                'phone' => '0901234567',
                'address' => 'Quận 1, TP. Hồ Chí Minh',
            ],
        );

        ClientUser::query()->updateOrCreate(
            ['email' => 'khach1@example.com'],
            [
                'client_id' => $client->id,
                'name' => 'Nguyễn Văn An',
                'password' => 'password',
                'is_active' => true,
                'must_change_password' => false,
                'activated_at' => now(),
            ],
        );
    }
}
```

`database/seeders/DatabaseSeeder.php`:
```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DemoAccountsSeeder::class,
        ]);
    }
}
```

- [ ] **Step 4: Run the seeder test and full suite**

Run: `bash vendor/bin/sail artisan test`
Expected: all green.

- [ ] **Step 5: Seed the dev database and log in through the browser**

Run: `bash vendor/bin/sail artisan migrate:fresh --seed`
Then, using the Browser pane:
1. Open `http://localhost/admin/login`, sign in with `admin@luatvukhang.com` / `password`. Expect the Filament dashboard with brand "VK-CRM · Nội bộ".
2. Open `http://localhost/portal/login`, sign in with `khach1@example.com` / `password`. Expect the portal dashboard with brand "Luật Vũ Khang · Tra cứu hồ sơ".
3. While signed in to the portal, open `http://localhost/admin`. Expect redirect to `/admin/login`.

- [ ] **Step 6: Write `README.md`**

Replace the Laravel README with:
````markdown
# VK-CRM — Quản lý hồ sơ vụ việc Công ty Luật Vũ Khang

Đặc tả: [`docs/SPEC.md`](docs/SPEC.md). Quyết định kỹ thuật:
[`docs/superpowers/specs/2026-09-13-vk-crm-design.md`](docs/superpowers/specs/2026-09-13-vk-crm-design.md).
Tiến độ: [`docs/PROGRESS.md`](docs/PROGRESS.md).

## Yêu cầu

- Docker Desktop (phát triển local qua Laravel Sail)
- Production: PHP ≥ 8.3, MariaDB ≥ 10.3 hoặc MySQL ≥ 8.0, cron mỗi phút

## Chạy local

```bash
cp .env.example .env
docker run --rm -v "$PWD:/opt" -w /opt laravelsail/php83-composer:latest composer install --ignore-platform-reqs
bash vendor/bin/sail up -d
bash vendor/bin/sail artisan key:generate
bash vendor/bin/sail artisan migrate:fresh --seed
```

| Panel | URL | Tài khoản demo |
|---|---|---|
| Nội bộ | http://localhost/admin | `admin@luatvukhang.com` / `password` |
| Khách hàng | http://localhost/portal | `khach1@example.com` / `password` |
| Mailpit | http://localhost:8025 | — |

## Kiểm thử và định dạng mã

```bash
bash vendor/bin/sail artisan test
bash vendor/bin/sail bin pint
```

## Tên miền

Mặc định cả hai panel chạy chung một tên miền theo đường dẫn (`/admin`, `/portal`).
Điền `ADMIN_DOMAIN` và `PORTAL_DOMAIN` trong `.env` để tách subdomain.
````

- [ ] **Step 7: Write `docs/PROGRESS.md`**

```markdown
# Tiến độ VK-CRM

| Milestone | Trạng thái | Ngày | Ghi chú |
|---|---|---|---|
| M0 Khởi tạo | ✅ Xong | 2026-09-13 | Laravel 13, Filament 5, hai panel, hai guard, Pest, Pint, seed demo |
| M1 Migration/model/seeder | ⬜ | | |
| M2 Phân quyền | ⬜ | | |
| M3 Panel admin + chuyển giai đoạn + xung đột lợi ích | ⬜ | | |
| M4 Danh mục hồ sơ + tài liệu | ⬜ | | |
| M5 Portal khách | ⬜ | | |
| M6 Thông báo + tác vụ định kỳ | ⬜ | | |
| M7 Bàn giao + lưu trữ + tìm kiếm | ⬜ | | |
| M8 Bảo mật + hoàn thiện | ⬜ | | |
```

- [ ] **Step 8: Final acceptance run and commit**

Run: `bash vendor/bin/sail artisan test && bash vendor/bin/sail bin pint --test`
Expected: all tests pass, Pint clean.

```bash
git add -A
git commit -m "feat: demo accounts seeder, README, PROGRESS — M0 complete

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Self-review

**Spec coverage (SPEC §13 M0 row):** Laravel 13 + Filament v5 (Task 1, 5), two panels (Task 6, 7), two guards (Task 5–7), `.env.example` (Task 3), Pint + Pest (Task 2), `php artisan test` runs (Task 2 onward), both panels open with seeded accounts (Task 9). Design §3 domain behaviour in both modes (Task 8). Design §6 Vietnamese strings via `lang/vi` (Tasks 4, 6). SPEC §4.1–4.3 columns migrated now so M1 does not rewrite them (Task 4). Fortify 2FA and portal OTP are deliberately **not** in M0 (SPEC puts them in M5/M8).

**Placeholder scan:** none.

**Type consistency:** `canAccessPanel(Panel $panel): bool` identical on both models; config keys used in Tasks 6–8 all defined in Task 3; seeder emails in Task 9 match README; `ClientUser::factory()` auto-creates `Client` (Task 4) and is used that way in Tasks 5–7.
