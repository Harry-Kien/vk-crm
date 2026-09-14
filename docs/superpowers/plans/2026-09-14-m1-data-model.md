# VK-CRM M1 — Kế hoạch cài đặt mô hình dữ liệu

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Toàn bộ 19 bảng của SPEC §4 có migration, model, enum, quan hệ, factory; seeder dựng được môi trường demo đầy đủ theo SPEC §12; đồng thời sửa bốn điểm lệch còn lại từ M0.

**Architecture:** Một ứng dụng Laravel 13. Mọi cột trạng thái là enum PHP backed string có `label()` lấy chuỗi từ `lang/vi/enums.php`. Mã tự sinh (`KH-2026-0001`, `VK-2026-DD-0147`) dùng bảng đếm `code_sequences` khoá dòng trong transaction nên không thể trùng khi hai người tạo cùng lúc. Cột `created_by`/`updated_by` điền tự động qua trait `HasBlameable` từ guard `web`. `stage_logs` bị chặn sửa nội dung và chặn xoá ngay ở model. Logic nghiệp vụ duy nhất cần cho seeder (sao chép danh mục hồ sơ vào vụ việc) đặt ở `app/Actions/ApplyChecklistTemplate.php` để M3 dùng lại.

**Tech Stack:** PHP 8.3, Laravel 13, Filament 5 (chưa đụng tới ở M1), MariaDB 11 (dev), SQLite in-memory (test), Pest 4, Pint. Mọi lệnh qua `bin/dev`.

**Spec:** `docs/SPEC.md` §4 (toàn bộ), §5 (chỉ để đặt tên cột đúng), §6.1 (sinh mã), §6.10 (chuẩn hoá dữ liệu các bên), §12 (dữ liệu mẫu), §13 dòng M1, §15 (chừa chỗ). `docs/superpowers/specs/2026-09-13-vk-crm-design.md` §2, §6. `docs/superpowers/specs/2026-09-14-vk-crm-toolchain.md` §2 (M1 không cài gói mới).

## Ràng buộc toàn cục

- PHP sàn **8.3**. Không cú pháp hay gói đòi 8.4+.
- Không cài gói Composer mới ở M1. Medialibrary, permission, activitylog thuộc M2–M4.
- Không Redis, Horizon, Octane, Reverb, Pulse, Scout, Telescope.
- Định danh mã tiếng Anh. Chuỗi hiển thị tiếng Việt qua `__()` trong `lang/vi/`.
- Enum backed string cho mọi cột trạng thái, có `label()`. Cột lưu `string(…)`, không dùng DB enum.
- Mọi bảng có `id`, `created_at`, `updated_at`. Bảng nghiệp vụ có thêm `deleted_at`. **Ngoại lệ có chủ đích:** bảng nhật ký thuần (`stage_logs`, `stage_log_views`, `document_downloads`, `client_request_replies`, `outbound_messages`) và pivot `matter_user` không có `deleted_at` vì không bao giờ xoá; `stage_logs` chặn xoá ở model.
- TDD: mỗi task viết test đỏ trước. Kết thúc task: `bin/dev test` xanh, `bin/dev pint` sạch, commit.
- Test chạy SQLite in-memory; `lockForUpdate()` là no-op trên SQLite, chấp nhận được vì tính duy nhất do bảng đếm bảo đảm chứ không do khoá.
- Commit message kết thúc bằng `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.
- Lệnh: `bin/dev test --filter=<Tên>` chạy một test; `bin/dev test` chạy hết; `bin/dev pint` sửa định dạng; `bin/dev artisan migrate:fresh --seed` dựng lại dữ liệu demo.

---

## Cấu trúc tệp (trạng thái cuối M1)

| Đường dẫn | Trách nhiệm |
|---|---|
| `app/Support/CodeSequence.php` | Bộ đếm tuần tự theo khoá, an toàn đồng thời |
| `app/Support/Normalizer.php` | Chuẩn hoá tên, số điện thoại, băm số căn cước (SPEC §6.10 bước 1) |
| `app/Support/StagePresets.php` | Bộ giai đoạn mẫu (dân sự, hình sự, doanh nghiệp) dùng chung cho factory và seeder |
| `app/Models/Concerns/HasBlameable.php` | Điền `created_by`/`updated_by` từ guard `web` |
| `app/Exceptions/StageLogImmutable.php` | Ném khi sửa nội dung hoặc xoá `stage_logs` |
| `app/Enums/*.php` | 12 enum mới (xem Task 3) |
| `app/Models/*.php` | 17 model mới + sửa `Client`, `User`, `ClientUser` |
| `app/Actions/ApplyChecklistTemplate.php` | Sao chép item từ template sang `matter_checklist_items` |
| `database/migrations/2026_09_14_*.php` | 18 migration mới (xem từng task) |
| `database/factories/*.php` | Factory cho mọi model |
| `database/seeders/{Staff,MatterType,ChecklistTemplate,Client,Matter}Seeder.php` | Dữ liệu mẫu SPEC §12 |
| `lang/vi/enums.php`, `lang/vi/exceptions.php` | Nhãn enum, thông điệp exception |
| `config/auth.php` | Broker `client_users` dùng bảng riêng |
| `tests/Unit/Support/*Test.php` | CodeSequence, Normalizer |
| `tests/Feature/Models/*Test.php` | Một tệp mỗi nhóm bảng |
| `tests/Feature/Actions/ApplyChecklistTemplateTest.php` | |
| `tests/Feature/Seeders/DemoDataSeederTest.php` | Khẳng định đủ số liệu SPEC §12 |
| `README.md`, `docs/PROGRESS.md` | Tài khoản demo, tiến độ |

---

### Task 0: Sửa bốn điểm lệch còn lại từ M0

**Files:**
- Modify: `.env` (không commit), `.env.example`
- Create: `database/migrations/2026_09_14_000001_create_client_password_reset_tokens_table.php`
- Modify: `config/auth.php:107-112`
- Create: `app/Models/Concerns/HasBlameable.php`
- Modify: `app/Models/Client.php`
- Test: `tests/Feature/Models/ClientBlameableTest.php`, `tests/Unit/AuthConfigTest.php`

**Interfaces:**
- Produces: trait `App\Models\Concerns\HasBlameable` với `creator(): BelongsTo`, `updater(): BelongsTo`; dùng cho mọi model có cột `created_by`/`updated_by` ở các task sau.

- [ ] **Bước 1: Sửa `APP_URL` local**

Trong `.env` đổi `APP_URL=http://localhost:8000` thành `APP_URL=http://localhost`. Không commit `.env`. Kiểm tra: `bin/dev artisan about | grep URL` phải in `localhost`.

- [ ] **Bước 2: Viết test cấu hình broker**

`tests/Unit/AuthConfigTest.php`:
```php
<?php

it('uses a separate password reset table for client users', function () {
    expect(config('auth.passwords.client_users.table'))->toBe('client_password_reset_tokens')
        ->and(config('auth.passwords.users.table'))->toBe('password_reset_tokens');
});
```

- [ ] **Bước 3: Chạy test, mong đợi đỏ**

Run: `bin/dev test --filter=AuthConfigTest`
Expected: FAIL, giá trị hiện tại là `password_reset_tokens`.

- [ ] **Bước 4: Tách bảng token**

`config/auth.php`, sửa khối `client_users` trong `passwords`:
```php
        'client_users' => [
            'provider' => 'client_users',
            'table' => 'client_password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
```

`database/migrations/2026_09_14_000001_create_client_password_reset_tokens_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tách khỏi password_reset_tokens của nhân sự: khoá chính là email, dùng chung sẽ ghi đè token nếu trùng email.
        Schema::create('client_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_password_reset_tokens');
    }
};
```

- [ ] **Bước 5: Viết test blameable**

`tests/Feature/Models/ClientBlameableTest.php`:
```php
<?php

use App\Models\Client;
use App\Models\User;

it('records who created and updated a client when a staff user is logged in', function () {
    $creator = User::factory()->create();
    $editor = User::factory()->create();

    $this->actingAs($creator, 'web');
    $client = Client::factory()->create();

    expect($client->created_by)->toBe($creator->id)
        ->and($client->updated_by)->toBe($creator->id)
        ->and($client->creator->is($creator))->toBeTrue();

    $this->actingAs($editor, 'web');
    $client->update(['note' => 'đã gọi lại']);

    expect($client->fresh()->updated_by)->toBe($editor->id)
        ->and($client->fresh()->created_by)->toBe($creator->id);
});

it('leaves blame columns null when nobody is logged in', function () {
    $client = Client::factory()->create();

    expect($client->created_by)->toBeNull()->and($client->updated_by)->toBeNull();
});
```

- [ ] **Bước 6: Chạy test, mong đợi đỏ**

Run: `bin/dev test --filter=ClientBlameableTest`
Expected: FAIL, `created_by` là null và `creator` không tồn tại.

- [ ] **Bước 7: Viết trait và gắn vào Client**

`app/Models/Concerns/HasBlameable.php`:
```php
<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Điền created_by / updated_by từ nhân sự đang đăng nhập (guard web).
 * Không đưa hai cột này vào $fillable: chỉ hệ thống mới được ghi.
 */
trait HasBlameable
{
    public static function bootHasBlameable(): void
    {
        static::creating(function (Model $model): void {
            $id = auth('web')->id();

            if ($id === null) {
                return;
            }

            $model->created_by ??= $id;
            $model->updated_by ??= $id;
        });

        static::updating(function (Model $model): void {
            $id = auth('web')->id();

            if ($id !== null) {
                $model->updated_by = $id;
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
```

`app/Models/Client.php`: thêm `use App\Models\Concerns\HasBlameable;` ở phần import và `use HasBlameable;` trong class ngay dưới `use SoftDeletes;`.

- [ ] **Bước 8: Chạy toàn bộ test, mong đợi xanh**

Run: `bin/dev test`
Expected: 26 passed (23 cũ + 3 mới).

- [ ] **Bước 9: Pint và commit**

```bash
bin/dev pint
git add config/auth.php database/migrations/2026_09_14_000001_create_client_password_reset_tokens_table.php app/Models/Concerns/HasBlameable.php app/Models/Client.php tests/Unit/AuthConfigTest.php tests/Feature/Models/ClientBlameableTest.php
git commit -m "fix: separate client password reset table, blameable columns on clients

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 1: Bộ đếm mã an toàn đồng thời `CodeSequence`

**Files:**
- Create: `database/migrations/2026_09_14_000002_create_code_sequences_table.php`
- Create: `app/Support/CodeSequence.php`
- Modify: `app/Models/Client.php` (thay `nextCode()`)
- Test: `tests/Unit/Support/CodeSequenceTest.php`

**Interfaces:**
- Produces: `CodeSequence::next(string $key): int` trả số kế tiếp bắt đầu từ 1 cho mỗi khoá; `CodeSequence::format(string $prefix, int $number): string` trả `prefix + 4 chữ số`. Task 5 dùng cho mã vụ việc với khoá `matter:{năm}:{mã loại}`.

- [ ] **Bước 1: Viết test**

`tests/Unit/Support/CodeSequenceTest.php` (đặt trong `tests/Unit` nhưng cần DB, nên khai báo dùng `RefreshDatabase` tại đầu tệp):
```php
<?php

use App\Support\CodeSequence;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('starts at one and increments per key', function () {
    expect(CodeSequence::next('client:2026'))->toBe(1)
        ->and(CodeSequence::next('client:2026'))->toBe(2)
        ->and(CodeSequence::next('client:2027'))->toBe(1)
        ->and(CodeSequence::next('matter:2026:DD'))->toBe(1);
});

it('formats with a four digit zero padded number', function () {
    expect(CodeSequence::format('KH-2026-', 7))->toBe('KH-2026-0007')
        ->and(CodeSequence::format('VK-2026-DD-', 147))->toBe('VK-2026-DD-0147')
        ->and(CodeSequence::format('VK-2026-DD-', 12345))->toBe('VK-2026-DD-12345');
});

it('rolls back the counter together with an outer transaction', function () {
    try {
        DB::transaction(function () {
            CodeSequence::next('client:2026');
            throw new RuntimeException('abort');
        });
    } catch (RuntimeException) {
    }

    expect(CodeSequence::next('client:2026'))->toBe(1);
});
```

- [ ] **Bước 2: Chạy test, mong đợi đỏ**

Run: `bin/dev test --filter=CodeSequenceTest`
Expected: FAIL, class không tồn tại.

- [ ] **Bước 3: Migration và class**

`database/migrations/2026_09_14_000002_create_code_sequences_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Một dòng cho mỗi dãy mã (ví dụ client:2026, matter:2026:DD). Khoá dòng khi lấy số kế tiếp.
        Schema::create('code_sequences', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('code_sequences');
    }
};
```

`app/Support/CodeSequence.php`:
```php
<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Sinh số thứ tự tuần tự theo khoá. Hai request cùng lúc luôn nhận hai số khác nhau
 * vì dòng đếm bị khoá trong transaction; nếu có transaction bao ngoài thì tham gia
 * transaction đó và cùng rollback.
 */
final class CodeSequence
{
    public static function next(string $key): int
    {
        return DB::transaction(function () use ($key): int {
            DB::table('code_sequences')->insertOrIgnore([
                'key' => $key,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $current = (int) DB::table('code_sequences')
                ->where('key', $key)
                ->lockForUpdate()
                ->value('last_number');

            $next = $current + 1;

            DB::table('code_sequences')
                ->where('key', $key)
                ->update(['last_number' => $next, 'updated_at' => now()]);

            return $next;
        });
    }

    public static function format(string $prefix, int $number): string
    {
        return $prefix.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }
}
```

- [ ] **Bước 4: Thay `Client::nextCode()`**

Trong `app/Models/Client.php` xoá import `Illuminate\Support\Facades\DB`, thêm `use App\Support\CodeSequence;`, và thay toàn bộ phương thức `nextCode()` bằng:
```php
    /**
     * KH-{YYYY}-{0001}; số thứ tự chạy lại từ đầu mỗi năm.
     */
    public static function nextCode(): string
    {
        $year = now()->format('Y');

        return CodeSequence::format("KH-{$year}-", CodeSequence::next("client:{$year}"));
    }
```

- [ ] **Bước 5: Chạy toàn bộ test**

Run: `bin/dev test`
Expected: 29 passed. Test cũ `generates a unique client code per client` và `seeds one admin ... KH-YYYY-0001` vẫn xanh.

- [ ] **Bước 6: Pint và commit**

```bash
bin/dev pint
git add app/Support/CodeSequence.php database/migrations/2026_09_14_000002_create_code_sequences_table.php app/Models/Client.php tests/Unit/Support/CodeSequenceTest.php
git commit -m "feat: concurrency-safe code sequences, client code uses counter table

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 2: Chuẩn hoá dữ liệu các bên `Normalizer`

**Files:**
- Create: `app/Support/Normalizer.php`
- Test: `tests/Unit/Support/NormalizerTest.php`

**Interfaces:**
- Produces: `Normalizer::name(?string): ?string`, `Normalizer::phone(?string): ?string` (dạng `84xxxxxxxxx`), `Normalizer::idNumberHash(?string): ?string` (SHA-256 hex, 64 ký tự). Task 10 (matter_parties) và Task 11 (seeder) dùng.

- [ ] **Bước 1: Viết test**

`tests/Unit/Support/NormalizerTest.php`:
```php
<?php

use App\Support\Normalizer;

it('normalizes vietnamese names to lowercase ascii with single spaces', function () {
    expect(Normalizer::name('  Nguyễn   Văn  An '))->toBe('nguyen van an')
        ->and(Normalizer::name('Trần Thị Bích Đào'))->toBe('tran thi bich dao')
        ->and(Normalizer::name(''))->toBeNull()
        ->and(Normalizer::name(null))->toBeNull();
});

it('normalizes phones to 84 prefix digits only', function () {
    expect(Normalizer::phone('0901 234 567'))->toBe('84901234567')
        ->and(Normalizer::phone('+84 901-234-567'))->toBe('84901234567')
        ->and(Normalizer::phone('84901234567'))->toBe('84901234567')
        ->and(Normalizer::phone('abc'))->toBeNull()
        ->and(Normalizer::phone(null))->toBeNull();
});

it('hashes id numbers after stripping non digits', function () {
    $expected = hash('sha256', '079090001234');

    expect(Normalizer::idNumberHash('079 090 001 234'))->toBe($expected)
        ->and(Normalizer::idNumberHash('079090001234'))->toBe($expected)
        ->and(strlen((string) Normalizer::idNumberHash('1')))->toBe(64)
        ->and(Normalizer::idNumberHash('---'))->toBeNull()
        ->and(Normalizer::idNumberHash(null))->toBeNull();
});
```

- [ ] **Bước 2: Chạy test, mong đợi đỏ**

Run: `bin/dev test --filter=NormalizerTest`
Expected: FAIL, class không tồn tại.

- [ ] **Bước 3: Cài đặt**

`app/Support/Normalizer.php`:
```php
<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Chuẩn hoá dữ liệu để so khớp xung đột lợi ích (SPEC §6.10 bước 1).
 * Không bao giờ lưu số căn cước gốc ở đây; chỉ lưu hash.
 */
final class Normalizer
{
    public static function name(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $ascii = Str::ascii(mb_strtolower(trim($value), 'UTF-8'));

        return preg_replace('/\s+/', ' ', $ascii) ?: null;
    }

    public static function phone(?string $value): ?string
    {
        $digits = self::digits($value);

        if ($digits === null) {
            return null;
        }

        if (str_starts_with($digits, '84')) {
            return $digits;
        }

        if (str_starts_with($digits, '0')) {
            return '84'.substr($digits, 1);
        }

        return $digits;
    }

    public static function idNumberHash(?string $value): ?string
    {
        $digits = self::digits($value);

        return $digits === null ? null : hash('sha256', $digits);
    }

    private static function digits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits === '' ? null : $digits;
    }
}
```

- [ ] **Bước 4: Chạy test, mong đợi xanh**

Run: `bin/dev test --filter=NormalizerTest`
Expected: PASS (3 test).

- [ ] **Bước 5: Pint và commit**

```bash
bin/dev pint
git add app/Support/Normalizer.php tests/Unit/Support/NormalizerTest.php
git commit -m "feat: Normalizer for party names, phones and id number hashes

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 3: Toàn bộ enum còn thiếu và nhãn tiếng Việt

**Files:**
- Create: `app/Enums/{MatterRole,Confidentiality,ChecklistItemStatus,DocumentGroup,DocumentStatus,DeadlineSeverity,ClientRequestStatus,MessageChannel,MessageStatus,PartyRole,CommunicationType}.php`
- Modify: `lang/vi/enums.php`
- Test: `tests/Unit/EnumLabelsTest.php`

**Interfaces:**
- Produces: 11 enum sau, mọi model ở các task sau cast bằng đúng tên này.

| Enum | Case → value |
|---|---|
| `MatterRole` | `Lead=lead`, `Associate=associate`, `Assistant=assistant`, `Observer=observer` |
| `Confidentiality` | `Normal=normal`, `Restricted=restricted` |
| `ChecklistItemStatus` | `Missing=missing`, `PendingReview=pending_review`, `Accepted=accepted`, `Rejected=rejected`, `NotApplicable=not_applicable` |
| `DocumentGroup` | `ClientProvided=A`, `Issued=B`, `Authority=C`, `Internal=D` |
| `DocumentStatus` | `InternalDraft=internal_draft`, `PendingApproval=pending_approval`, `SignedFiled=signed_filed`, `Published=published` |
| `DeadlineSeverity` | `Normal=normal`, `Critical=critical` |
| `ClientRequestStatus` | `New=new`, `InProgress=in_progress`, `Answered=answered`, `Closed=closed` |
| `MessageChannel` | `Email=email`, `Zns=zns`, `Sms=sms` |
| `MessageStatus` | `Queued=queued`, `Sent=sent`, `Failed=failed` |
| `PartyRole` | `Plaintiff=plaintiff`, `Defendant=defendant`, `Related=related`, `ThirdParty=third_party`, `OpposingCounsel=opposing_counsel` |
| `CommunicationType` | `CallIn=call_in`, `CallOut=call_out`, `Meeting=meeting`, `Email=email`, `Letter=letter`, `CourtVisit=court_visit` |

- [ ] **Bước 1: Viết test quét mọi enum**

`tests/Unit/EnumLabelsTest.php`:
```php
<?php

it('gives every enum case a vietnamese label', function () {
    $files = glob(app_path('Enums/*.php')) ?: [];
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $class = 'App\\Enums\\'.pathinfo($file, PATHINFO_FILENAME);
        expect(enum_exists($class))->toBeTrue("{$class} phải là enum");

        foreach ($class::cases() as $case) {
            $label = $case->label();
            expect($label)->toBeString()->not->toBeEmpty()
                ->and($label)->not->toStartWith('enums.', "{$class}::{$case->name} thiếu nhãn trong lang/vi/enums.php");
        }
    }
});

it('has the enums the data model requires', function () {
    foreach ([
        'MatterRole', 'Confidentiality', 'ChecklistItemStatus', 'DocumentGroup', 'DocumentStatus',
        'DeadlineSeverity', 'ClientRequestStatus', 'MessageChannel', 'MessageStatus', 'PartyRole', 'CommunicationType',
    ] as $name) {
        expect(enum_exists('App\\Enums\\'.$name))->toBeTrue("Thiếu enum {$name}");
    }

    expect(App\Enums\DocumentGroup::Internal->value)->toBe('D')
        ->and(App\Enums\PartyRole::OpposingCounsel->value)->toBe('opposing_counsel');
});
```

- [ ] **Bước 2: Chạy test, mong đợi đỏ**

Run: `bin/dev test --filter=EnumLabelsTest`
Expected: FAIL, thiếu enum.

- [ ] **Bước 3: Tạo 11 enum**

Mỗi enum theo đúng mẫu này (thay tên, case, khoá dịch). Khoá dịch là snake_case của tên enum.

`app/Enums/MatterRole.php`:
```php
<?php

namespace App\Enums;

enum MatterRole: string
{
    case Lead = 'lead';
    case Associate = 'associate';
    case Assistant = 'assistant';
    case Observer = 'observer';

    public function label(): string
    {
        return __('enums.matter_role.'.$this->value);
    }
}
```

`app/Enums/Confidentiality.php`:
```php
<?php

namespace App\Enums;

enum Confidentiality: string
{
    case Normal = 'normal';
    case Restricted = 'restricted';

    public function label(): string
    {
        return __('enums.confidentiality.'.$this->value);
    }
}
```

`app/Enums/ChecklistItemStatus.php`:
```php
<?php

namespace App\Enums;

enum ChecklistItemStatus: string
{
    case Missing = 'missing';
    case PendingReview = 'pending_review';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return __('enums.checklist_item_status.'.$this->value);
    }
}
```

`app/Enums/DocumentGroup.php`:
```php
<?php

namespace App\Enums;

enum DocumentGroup: string
{
    case ClientProvided = 'A';
    case Issued = 'B';
    case Authority = 'C';
    case Internal = 'D';

    public function label(): string
    {
        return __('enums.document_group.'.$this->value);
    }

    /** Nhóm D không bao giờ ra portal, kể cả chỉ xem. */
    public function isInternal(): bool
    {
        return $this === self::Internal;
    }
}
```

`app/Enums/DocumentStatus.php`:
```php
<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case InternalDraft = 'internal_draft';
    case PendingApproval = 'pending_approval';
    case SignedFiled = 'signed_filed';
    case Published = 'published';

    public function label(): string
    {
        return __('enums.document_status.'.$this->value);
    }
}
```

`app/Enums/DeadlineSeverity.php`:
```php
<?php

namespace App\Enums;

enum DeadlineSeverity: string
{
    case Normal = 'normal';
    case Critical = 'critical';

    public function label(): string
    {
        return __('enums.deadline_severity.'.$this->value);
    }
}
```

`app/Enums/ClientRequestStatus.php`:
```php
<?php

namespace App\Enums;

enum ClientRequestStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Answered = 'answered';
    case Closed = 'closed';

    public function label(): string
    {
        return __('enums.client_request_status.'.$this->value);
    }
}
```

`app/Enums/MessageChannel.php`:
```php
<?php

namespace App\Enums;

enum MessageChannel: string
{
    case Email = 'email';
    case Zns = 'zns';
    case Sms = 'sms';

    public function label(): string
    {
        return __('enums.message_channel.'.$this->value);
    }
}
```

`app/Enums/MessageStatus.php`:
```php
<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return __('enums.message_status.'.$this->value);
    }
}
```

`app/Enums/PartyRole.php`:
```php
<?php

namespace App\Enums;

enum PartyRole: string
{
    case Plaintiff = 'plaintiff';
    case Defendant = 'defendant';
    case Related = 'related';
    case ThirdParty = 'third_party';
    case OpposingCounsel = 'opposing_counsel';

    public function label(): string
    {
        return __('enums.party_role.'.$this->value);
    }
}
```

`app/Enums/CommunicationType.php`:
```php
<?php

namespace App\Enums;

enum CommunicationType: string
{
    case CallIn = 'call_in';
    case CallOut = 'call_out';
    case Meeting = 'meeting';
    case Email = 'email';
    case Letter = 'letter';
    case CourtVisit = 'court_visit';

    public function label(): string
    {
        return __('enums.communication_type.'.$this->value);
    }
}
```

- [ ] **Bước 4: Bổ sung nhãn**

Thay toàn bộ `lang/vi/enums.php`:
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
    'matter_role' => [
        'lead' => 'Luật sư phụ trách',
        'associate' => 'Luật sư cộng sự',
        'assistant' => 'Trợ lý',
        'observer' => 'Theo dõi',
    ],
    'confidentiality' => [
        'normal' => 'Thông thường',
        'restricted' => 'Hạn chế',
    ],
    'checklist_item_status' => [
        'missing' => 'Chưa nộp',
        'pending_review' => 'Chờ kiểm tra',
        'accepted' => 'Đã nhận',
        'rejected' => 'Cần nộp lại',
        'not_applicable' => 'Không cần',
    ],
    'document_group' => [
        'A' => 'Khách hàng cung cấp',
        'B' => 'Văn bản đã phát hành',
        'C' => 'Văn bản của cơ quan nhà nước',
        'D' => 'Hồ sơ công việc nội bộ',
    ],
    'document_status' => [
        'internal_draft' => 'Bản thảo nội bộ',
        'pending_approval' => 'Chờ duyệt',
        'signed_filed' => 'Đã ký, đã nộp',
        'published' => 'Đã công bố',
    ],
    'deadline_severity' => [
        'normal' => 'Thông thường',
        'critical' => 'Không thể gia hạn',
    ],
    'client_request_status' => [
        'new' => 'Mới',
        'in_progress' => 'Đang xử lý',
        'answered' => 'Đã trả lời',
        'closed' => 'Đã đóng',
    ],
    'message_channel' => [
        'email' => 'Email',
        'zns' => 'Zalo ZNS',
        'sms' => 'SMS',
    ],
    'message_status' => [
        'queued' => 'Chờ gửi',
        'sent' => 'Đã gửi',
        'failed' => 'Gửi lỗi',
    ],
    'party_role' => [
        'plaintiff' => 'Nguyên đơn',
        'defendant' => 'Bị đơn',
        'related' => 'Người có quyền lợi, nghĩa vụ liên quan',
        'third_party' => 'Bên thứ ba',
        'opposing_counsel' => 'Luật sư đối phương',
    ],
    'communication_type' => [
        'call_in' => 'Khách gọi đến',
        'call_out' => 'Gọi cho khách',
        'meeting' => 'Buổi làm việc',
        'email' => 'Email',
        'letter' => 'Công văn, thư',
        'court_visit' => 'Làm việc tại toà',
    ],
];
```

- [ ] **Bước 5: Chạy test, mong đợi xanh**

Run: `bin/dev test --filter=EnumLabelsTest`
Expected: PASS (2 test).

- [ ] **Bước 6: Pint và commit**

```bash
bin/dev pint
git add app/Enums lang/vi/enums.php tests/Unit/EnumLabelsTest.php
git commit -m "feat: status enums for matters, documents, deadlines, requests, parties, communications

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 4: Loại vụ việc và bộ giai đoạn (`matter_types`, `matter_type_stages`)

**Files:**
- Create: `database/migrations/2026_09_14_000003_create_matter_types_table.php`, `database/migrations/2026_09_14_000004_create_matter_type_stages_table.php`
- Create: `app/Support/StagePresets.php`, `app/Models/MatterType.php`, `app/Models/MatterTypeStage.php`
- Create: `database/factories/MatterTypeFactory.php`, `database/factories/MatterTypeStageFactory.php`
- Test: `tests/Feature/Models/MatterTypeTest.php`

**Interfaces:**
- Produces: `MatterType` (`code`, `name`, `stages(): HasMany` sắp theo `sort_order`, `firstStage(): ?MatterTypeStage`, `stage(string $key): ?MatterTypeStage`); `MatterTypeStage` (`allowed_next` cast array, `allows(string $key): bool`); `StagePresets::for(string $typeCode): array` và `StagePresets::civil()/criminal()/corporate()`; `MatterTypeFactory::withStages()` tạo type kèm bộ giai đoạn theo mã.

- [ ] **Bước 1: Viết test**

`tests/Feature/Models/MatterTypeTest.php`:
```php
<?php

use App\Models\MatterType;
use App\Support\StagePresets;

it('orders stages and exposes allowed transitions', function () {
    $type = MatterType::factory()->withStages()->create(['code' => 'DS']);

    $keys = $type->stages->pluck('key')->all();

    expect($keys)->toBe(array_column(StagePresets::civil(), 'key'))
        ->and($type->firstStage()->key)->toBe('intake')
        ->and($type->stage('intake')->allows('collecting_documents'))->toBeTrue()
        ->and($type->stage('intake')->allows('closed'))->toBeFalse()
        ->and($type->stage('closed')->is_terminal)->toBeTrue()
        ->and($type->stage('on_hold')->allowed_next)->toBe(['intake', 'collecting_documents']);
});

it('has a full civil preset matching the spec sequence', function () {
    $keys = array_column(StagePresets::civil(), 'key');

    expect($keys)->toBe([
        'intake', 'collecting_documents', 'drafting', 'filed', 'court_accepted',
        'mediation', 'first_instance', 'appeal', 'enforcement', 'closed', 'on_hold',
    ]);
});

it('maps every seeded type code to a preset', function () {
    foreach (['DD', 'DS', 'HS', 'DN', 'LD', 'HN'] as $code) {
        expect(StagePresets::for($code))->not->toBeEmpty();
    }
});

it('rejects a duplicate stage key inside one type', function () {
    $type = MatterType::factory()->withStages()->create();

    expect(fn () => $type->stages()->create([
        'key' => 'intake', 'label' => 'x', 'client_label' => 'x', 'sort_order' => 99, 'allowed_next' => [],
    ]))->toThrow(Illuminate\Database\QueryException::class);
});
```

- [ ] **Bước 2: Chạy test, mong đợi đỏ**

Run: `bin/dev test --filter=MatterTypeTest`
Expected: FAIL, class không tồn tại.

- [ ] **Bước 3: Migration**

`database/migrations/2026_09_14_000003_create_matter_types_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_types');
    }
};
```

`database/migrations/2026_09_14_000004_create_matter_type_stages_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Giai đoạn là dữ liệu cấu hình, không hardcode (SPEC §4.5).
        Schema::create('matter_type_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_type_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->string('label', 120);
            $table->string('client_label', 120);
            $table->text('client_description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_terminal')->default(false);
            $table->json('allowed_next');
            $table->unsignedSmallInteger('default_next_update_days')->default(14);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['matter_type_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_type_stages');
    }
};
```

- [ ] **Bước 4: Bộ giai đoạn mẫu**

`app/Support/StagePresets.php`:
```php
<?php

namespace App\Support;

/**
 * Bộ giai đoạn mẫu dùng cho seeder và factory. Sau khi seed, quản trị viên sửa tự do
 * trong bảng matter_type_stages; class này không được đọc ở runtime nghiệp vụ.
 *
 * @phpstan-type Stage array{key: string, label: string, client_label: string, client_description: ?string, is_terminal: bool, allowed_next: list<string>, default_next_update_days: int}
 */
final class StagePresets
{
    /** @return list<Stage> */
    public static function for(string $typeCode): array
    {
        return match ($typeCode) {
            'HS' => self::criminal(),
            'DN' => self::corporate(),
            default => self::civil(),
        };
    }

    /** Tranh chấp dân sự, đất đai, hôn nhân, lao động (SPEC §4.5). @return list<Stage> */
    public static function civil(): array
    {
        return [
            self::stage('intake', 'Tiếp nhận', 'Đã tiếp nhận yêu cầu', 'Văn phòng đã nhận yêu cầu và đang đánh giá vụ việc.', ['collecting_documents', 'on_hold']),
            self::stage('collecting_documents', 'Thu thập hồ sơ', 'Đang thu thập giấy tờ', 'Văn phòng cùng anh/chị chuẩn bị đầy đủ giấy tờ cần thiết.', ['drafting', 'on_hold']),
            self::stage('drafting', 'Soạn đơn', 'Đang soạn đơn khởi kiện', 'Luật sư đang soạn đơn và các tài liệu nộp toà.', ['filed']),
            self::stage('filed', 'Đã nộp đơn', 'Đã nộp đơn cho toà', 'Đơn đã được nộp, đang chờ toà xem xét thụ lý.', ['court_accepted'], 10),
            self::stage('court_accepted', 'Toà thụ lý', 'Toà đã nhận giải quyết', 'Toà án đã thụ lý vụ việc và sẽ tiến hành các bước tiếp theo.', ['mediation', 'first_instance'], 21),
            self::stage('mediation', 'Hoà giải', 'Đang hoà giải', 'Toà tổ chức hoà giải giữa các bên.', ['first_instance', 'closed'], 21),
            self::stage('first_instance', 'Sơ thẩm', 'Đang xét xử sơ thẩm', 'Vụ việc đang được xét xử lần đầu.', ['appeal', 'enforcement', 'closed'], 30),
            self::stage('appeal', 'Phúc thẩm', 'Đang xét xử phúc thẩm', 'Vụ việc được xem xét lại ở cấp cao hơn.', ['enforcement', 'closed'], 30),
            self::stage('enforcement', 'Thi hành án', 'Đang thi hành án', 'Bản án đã có hiệu lực, đang thực hiện thi hành.', ['closed'], 30),
            self::stage('closed', 'Kết thúc', 'Đã kết thúc', 'Vụ việc đã hoàn tất.', [], 14, true),
            self::stage('on_hold', 'Tạm dừng', 'Tạm dừng theo yêu cầu', 'Vụ việc tạm dừng, sẽ tiếp tục khi có đủ điều kiện.', ['intake', 'collecting_documents'], 30),
        ];
    }

    /** Hình sự. @return list<Stage> */
    public static function criminal(): array
    {
        return [
            self::stage('intake', 'Tiếp nhận', 'Đã tiếp nhận yêu cầu', 'Văn phòng đã nhận yêu cầu bào chữa hoặc bảo vệ.', ['investigation', 'on_hold']),
            self::stage('investigation', 'Điều tra', 'Giai đoạn điều tra', 'Cơ quan điều tra đang làm việc, luật sư tham gia bảo vệ quyền lợi.', ['prosecution', 'closed'], 30),
            self::stage('prosecution', 'Truy tố', 'Giai đoạn truy tố', 'Viện kiểm sát đang xem xét hồ sơ.', ['first_instance', 'closed'], 30),
            self::stage('first_instance', 'Sơ thẩm', 'Xét xử sơ thẩm', 'Toà xét xử lần đầu.', ['appeal', 'closed'], 30),
            self::stage('appeal', 'Phúc thẩm', 'Xét xử phúc thẩm', 'Toà cấp trên xem xét lại bản án.', ['closed'], 30),
            self::stage('closed', 'Kết thúc', 'Đã kết thúc', 'Vụ việc đã hoàn tất.', [], 14, true),
            self::stage('on_hold', 'Tạm dừng', 'Tạm dừng', 'Vụ việc tạm dừng.', ['intake'], 30),
        ];
    }

    /** Doanh nghiệp: thủ tục hành chính, không qua toà. @return list<Stage> */
    public static function corporate(): array
    {
        return [
            self::stage('intake', 'Tiếp nhận', 'Đã tiếp nhận yêu cầu', 'Văn phòng đã nhận yêu cầu.', ['collecting_documents']),
            self::stage('collecting_documents', 'Thu thập hồ sơ', 'Đang thu thập giấy tờ', 'Chuẩn bị hồ sơ theo quy định.', ['drafting']),
            self::stage('drafting', 'Soạn hồ sơ', 'Đang soạn hồ sơ', 'Luật sư soạn hồ sơ nộp cơ quan đăng ký.', ['submitted']),
            self::stage('submitted', 'Đã nộp', 'Đã nộp cơ quan nhà nước', 'Hồ sơ đã nộp, đang chờ kết quả.', ['completed', 'collecting_documents'], 7),
            self::stage('completed', 'Hoàn tất', 'Đã có kết quả', 'Đã nhận kết quả từ cơ quan nhà nước.', [], 14, true),
        ];
    }

    /** @param list<string> $allowedNext @return Stage */
    private static function stage(string $key, string $label, string $clientLabel, ?string $description, array $allowedNext, int $days = 14, bool $terminal = false): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'client_label' => $clientLabel,
            'client_description' => $description,
            'is_terminal' => $terminal,
            'allowed_next' => $allowedNext,
            'default_next_update_days' => $days,
        ];
    }
}
```

- [ ] **Bước 5: Model**

`app/Models/MatterType.php`:
```php
<?php

namespace App\Models;

use App\Models\Concerns\HasBlameable;
use Database\Factories\MatterTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MatterType extends Model
{
    /** @use HasFactory<MatterTypeFactory> */
    use HasFactory;

    use HasBlameable;
    use SoftDeletes;

    protected $fillable = ['code', 'name', 'description', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function stages(): HasMany
    {
        return $this->hasMany(MatterTypeStage::class)->orderBy('sort_order');
    }

    public function matters(): HasMany
    {
        return $this->hasMany(Matter::class);
    }

    public function checklistTemplates(): HasMany
    {
        return $this->hasMany(ChecklistTemplate::class);
    }

    public function firstStage(): ?MatterTypeStage
    {
        return $this->stages()->first();
    }

    public function stage(string $key): ?MatterTypeStage
    {
        return $this->stages->firstWhere('key', $key);
    }
}
```

`app/Models/MatterTypeStage.php`:
```php
<?php

namespace App\Models;

use Database\Factories\MatterTypeStageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MatterTypeStage extends Model
{
    /** @use HasFactory<MatterTypeStageFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'matter_type_id', 'key', 'label', 'client_label', 'client_description',
        'sort_order', 'is_terminal', 'allowed_next', 'default_next_update_days',
    ];

    protected function casts(): array
    {
        return [
            'is_terminal' => 'boolean',
            'allowed_next' => 'array',
            'sort_order' => 'integer',
            'default_next_update_days' => 'integer',
        ];
    }

    public function matterType(): BelongsTo
    {
        return $this->belongsTo(MatterType::class);
    }

    public function allows(string $nextKey): bool
    {
        return in_array($nextKey, $this->allowed_next ?? [], true);
    }
}
```

- [ ] **Bước 6: Factory**

`database/factories/MatterTypeFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\MatterType;
use App\Support\StagePresets;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatterType>
 */
class MatterTypeFactory extends Factory
{
    protected $model = MatterType::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('??')),
            'name' => 'Loại vụ việc '.fake()->unique()->numberBetween(1, 9999),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /** Tạo kèm bộ giai đoạn theo mã (mặc định dân sự). */
    public function withStages(): static
    {
        return $this->afterCreating(function (MatterType $type): void {
            foreach (StagePresets::for($type->code) as $index => $stage) {
                $type->stages()->create([...$stage, 'sort_order' => $index + 1]);
            }
            $type->unsetRelation('stages');
        });
    }
}
```

`database/factories/MatterTypeStageFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\MatterType;
use App\Models\MatterTypeStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatterTypeStage>
 */
class MatterTypeStageFactory extends Factory
{
    protected $model = MatterTypeStage::class;

    public function definition(): array
    {
        $key = fake()->unique()->lexify('stage_????');

        return [
            'matter_type_id' => MatterType::factory(),
            'key' => $key,
            'label' => ucfirst($key),
            'client_label' => ucfirst($key),
            'client_description' => fake()->sentence(),
            'sort_order' => fake()->numberBetween(1, 20),
            'is_terminal' => false,
            'allowed_next' => [],
            'default_next_update_days' => 14,
        ];
    }
}
```

- [ ] **Bước 7: Chạy test, mong đợi xanh**

Run: `bin/dev test --filter=MatterTypeTest`
Expected: PASS (4 test). Nếu test `rejects a duplicate stage key` không ném lỗi trên SQLite, kiểm tra migration có `unique(['matter_type_id','key'])`.

- [ ] **Bước 8: Pint và commit**

```bash
bin/dev pint
git add app/Support/StagePresets.php app/Models/MatterType.php app/Models/MatterTypeStage.php database/migrations/2026_09_14_000003_create_matter_types_table.php database/migrations/2026_09_14_000004_create_matter_type_stages_table.php database/factories/MatterTypeFactory.php database/factories/MatterTypeStageFactory.php tests/Feature/Models/MatterTypeTest.php
git commit -m "feat: matter types with configurable stage sets and presets

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 5: Vụ việc và đội ngũ (`matters`, `matter_user`)

**Files:**
- Create: `database/migrations/2026_09_14_000005_create_matters_table.php`, `database/migrations/2026_09_14_000006_create_matter_user_table.php`
- Create: `app/Models/Matter.php`, `app/Models/MatterUser.php`
- Modify: `app/Models/User.php`, `app/Models/Client.php`
- Create: `database/factories/MatterFactory.php`
- Test: `tests/Feature/Models/MatterTest.php`

**Interfaces:**
- Produces: `Matter` với `code` tự sinh `{prefix}-{YYYY}-{typeCode}-{0001}`, `stage` mặc định giai đoạn đầu, `currentStage(): ?MatterTypeStage`, `team(): BelongsToMany` (pivot `role_in_matter` cast `MatterRole`), `addTeamMember(User $user, MatterRole $role): void`; quan hệ `stageLogs()`, `checklistItems()`, `documents()`, `deadlines()`, `clientRequests()`, `parties()`, `communicationLogs()`, `archive()` (các model này tạo ở task sau, quan hệ khai báo sẵn để không phải sửa lại). `User::leadMatters()`, `User::teamMatters()`. `Client::matters()`. `MatterFactory` mặc định tạo type có giai đoạn và luật sư phụ trách.

- [ ] **Bước 1: Viết test**

`tests/Feature/Models/MatterTest.php`:
```php
<?php

use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;

it('generates a code per year and type and starts at the first stage', function () {
    $dd = MatterType::factory()->withStages()->create(['code' => 'DD']);
    $ds = MatterType::factory()->withStages()->create(['code' => 'DS']);
    $year = now()->format('Y');

    $a = Matter::factory()->for($dd, 'matterType')->create();
    $b = Matter::factory()->for($dd, 'matterType')->create();
    $c = Matter::factory()->for($ds, 'matterType')->create();

    expect($a->code)->toBe("VK-{$year}-DD-0001")
        ->and($b->code)->toBe("VK-{$year}-DD-0002")
        ->and($c->code)->toBe("VK-{$year}-DS-0001")
        ->and($a->stage)->toBe('intake')
        ->and($a->currentStage()->label)->toBe('Tiếp nhận')
        ->and($a->stage_entered_at)->not->toBeNull()
        ->and($a->opened_at->isToday())->toBeTrue()
        ->and($a->confidentiality)->toBe(Confidentiality::Normal)
        ->and(Matter::factory()->unpublished()->create()->is_published_to_portal)->toBeFalse();
});

it('uses the configured matter code prefix', function () {
    config(['vkcrm.matter_code_prefix' => 'LVK']);
    $matter = Matter::factory()->create();

    expect($matter->code)->toStartWith('LVK-');
});

it('keeps a team with roles', function () {
    $matter = Matter::factory()->create();
    $associate = User::factory()->create();

    $matter->addTeamMember($associate, MatterRole::Associate);

    $matter->refresh();

    expect($matter->team)->toHaveCount(2)
        ->and($matter->team->firstWhere('id', $matter->lead_lawyer_id)->pivot->role_in_matter)->toBe(MatterRole::Lead)
        ->and($matter->team->firstWhere('id', $associate->id)->pivot->role_in_matter)->toBe(MatterRole::Associate)
        ->and($associate->teamMatters->first()->is($matter))->toBeTrue()
        ->and($matter->leadLawyer->leadMatters->first()->is($matter))->toBeTrue();
});

it('rejects the same user twice in one team', function () {
    $matter = Matter::factory()->create();

    expect(fn () => $matter->addTeamMember($matter->leadLawyer, MatterRole::Observer))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('belongs to a client and a type', function () {
    $matter = Matter::factory()->create();

    expect($matter->client->matters->first()->is($matter))->toBeTrue()
        ->and($matter->matterType->matters->first()->is($matter))->toBeTrue();
});
```

- [ ] **Bước 2: Chạy test, mong đợi đỏ**

Run: `bin/dev test --filter=MatterTest`
Expected: FAIL, class không tồn tại.

- [ ] **Bước 3: Migration**

`database/migrations/2026_09_14_000005_create_matters_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matters', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('matter_type_id')->constrained()->restrictOnDelete();
            $table->string('title', 250);
            $table->text('description_internal')->nullable();
            $table->text('summary_for_client')->nullable();
            $table->string('stage', 40);
            $table->timestamp('stage_entered_at');
            $table->foreignId('lead_lawyer_id')->constrained('users')->restrictOnDelete();
            $table->date('opened_at');
            $table->date('closed_at')->nullable();
            $table->boolean('is_published_to_portal')->default(false);
            $table->string('court_name', 200)->nullable();
            $table->string('case_number', 80)->nullable();
            $table->timestamp('last_client_update_at')->nullable();
            $table->string('confidentiality', 20)->default('normal');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('lead_lawyer_id');
            $table->index('stage');
            $table->index('last_client_update_at');
            $table->index(['is_published_to_portal', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matters');
    }
};
```
Ghi chú: `client_id` đã có index nhờ FK; index ghép `(is_published_to_portal, client_id)` phục vụ truy vấn portal.

`database/migrations/2026_09_14_000006_create_matter_user_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bảng quyết định luật sư nào thấy vụ việc nào (SPEC §4.7).
        Schema::create('matter_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role_in_matter', 20);
            $table->timestamps();

            $table->unique(['matter_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_user');
    }
};
```

- [ ] **Bước 4: Model `Matter`, pivot `MatterUser`, bổ sung `User` và `Client`**

`app/Models/MatterUser.php`:
```php
<?php

namespace App\Models;

use App\Enums\MatterRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

class MatterUser extends Pivot
{
    protected $table = 'matter_user';

    public $incrementing = true;

    protected function casts(): array
    {
        return ['role_in_matter' => MatterRole::class];
    }
}
```

`app/Models/Matter.php`:
```php
<?php

namespace App\Models;

use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Models\Concerns\HasBlameable;
use App\Support\CodeSequence;
use Database\Factories\MatterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Matter extends Model
{
    /** @use HasFactory<MatterFactory> */
    use HasFactory;

    use HasBlameable;
    use SoftDeletes;

    protected $fillable = [
        'client_id', 'matter_type_id', 'title', 'description_internal', 'summary_for_client',
        'stage', 'stage_entered_at', 'lead_lawyer_id', 'opened_at', 'closed_at',
        'is_published_to_portal', 'court_name', 'case_number', 'last_client_update_at', 'confidentiality',
    ];

    protected function casts(): array
    {
        return [
            'stage_entered_at' => 'datetime',
            'opened_at' => 'date',
            'closed_at' => 'date',
            'is_published_to_portal' => 'boolean',
            'last_client_update_at' => 'datetime',
            'confidentiality' => Confidentiality::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Matter $matter): void {
            $type = $matter->matterType ?? MatterType::query()->findOrFail($matter->matter_type_id);

            $matter->code ??= static::nextCode($type);
            $matter->stage ??= $type->firstStage()?->key;
            $matter->stage_entered_at ??= now();
            $matter->opened_at ??= today();
            $matter->confidentiality ??= Confidentiality::Normal;
        });

        // Luật sư phụ trách luôn có mặt trong đội ngũ với vai lead.
        static::created(function (Matter $matter): void {
            $matter->team()->syncWithoutDetaching([
                $matter->lead_lawyer_id => ['role_in_matter' => MatterRole::Lead->value],
            ]);
        });
    }

    /**
     * {MATTER_CODE_PREFIX}-{YYYY}-{mã loại}-{0001}; số thứ tự theo năm và theo loại (SPEC §6.1).
     */
    public static function nextCode(MatterType $type): string
    {
        $prefix = config('vkcrm.matter_code_prefix', 'VK');
        $year = now()->format('Y');

        return CodeSequence::format("{$prefix}-{$year}-{$type->code}-", CodeSequence::next("matter:{$year}:{$type->code}"));
    }

    public function addTeamMember(User $user, MatterRole $role): void
    {
        $this->team()->attach($user->id, ['role_in_matter' => $role->value]);
    }

    public function currentStage(): ?MatterTypeStage
    {
        return $this->matterType->stage($this->stage);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function matterType(): BelongsTo
    {
        return $this->belongsTo(MatterType::class);
    }

    public function leadLawyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_lawyer_id');
    }

    public function team(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'matter_user')
            ->using(MatterUser::class)
            ->withPivot('role_in_matter')
            ->withTimestamps();
    }

    public function stageLogs(): HasMany
    {
        return $this->hasMany(StageLog::class)->orderByDesc('occurred_at');
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(MatterChecklistItem::class)->orderBy('sort_order');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function deadlines(): HasMany
    {
        return $this->hasMany(Deadline::class)->orderBy('due_date');
    }

    public function clientRequests(): HasMany
    {
        return $this->hasMany(ClientRequest::class);
    }

    public function parties(): HasMany
    {
        return $this->hasMany(MatterParty::class);
    }

    public function communicationLogs(): HasMany
    {
        return $this->hasMany(CommunicationLog::class)->orderByDesc('occurred_at');
    }

    public function archive(): HasOne
    {
        return $this->hasOne(MatterArchive::class);
    }
}
```
Ghi chú SPEC §15 yêu cầu chừa quan hệ `timeEntries()`. Không khai báo ở M1 vì chưa có model `TimeEntry`; một quan hệ trỏ tới class không tồn tại làm hỏng phân tích tĩnh. Ghi vào PROGRESS là việc giai đoạn 2.

Thêm vào `app/Models/User.php` (import `BelongsToMany`, `HasMany`):
```php
    public function leadMatters(): HasMany
    {
        return $this->hasMany(Matter::class, 'lead_lawyer_id');
    }

    public function teamMatters(): BelongsToMany
    {
        return $this->belongsToMany(Matter::class, 'matter_user')
            ->using(MatterUser::class)
            ->withPivot('role_in_matter')
            ->withTimestamps();
    }
```

Thêm vào `app/Models/Client.php`:
```php
    public function matters(): HasMany
    {
        return $this->hasMany(Matter::class);
    }
```

- [ ] **Bước 5: Factory**

`database/factories/MatterFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Enums\Confidentiality;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Matter>
 */
class MatterFactory extends Factory
{
    protected $model = Matter::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'matter_type_id' => MatterType::factory()->withStages(),
            'title' => 'Tranh chấp '.fake()->words(3, true),
            'description_internal' => fake()->paragraph(),
            'summary_for_client' => fake()->sentence(12),
            'lead_lawyer_id' => User::factory(),
            'is_published_to_portal' => true,
            'confidentiality' => Confidentiality::Normal,
        ];
    }

    public function restricted(): static
    {
        return $this->state(fn () => ['confidentiality' => Confidentiality::Restricted]);
    }

    public function unpublished(): static
    {
        return $this->state(fn () => ['is_published_to_portal' => false]);
    }

    public function atStage(string $key): static
    {
        return $this->state(fn () => ['stage' => $key]);
    }
}
```

- [ ] **Bước 6: Chạy test, mong đợi xanh**

Run: `bin/dev test --filter=MatterTest`
Expected: PASS (5 test). Các quan hệ tới model chưa tồn tại (StageLog, Document, ...) không được gọi trong test này nên chưa lỗi.

- [ ] **Bước 7: Pint và commit**

```bash
bin/dev pint
git add app/Models/Matter.php app/Models/MatterUser.php app/Models/User.php app/Models/Client.php database/migrations/2026_09_14_000005_create_matters_table.php database/migrations/2026_09_14_000006_create_matter_user_table.php database/factories/MatterFactory.php tests/Feature/Models/MatterTest.php
git commit -m "feat: matters with generated codes, stage defaults and team pivot

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 6: Nhật ký tiến độ bất biến (`stage_logs`, `stage_log_views`)

**Files:**
- Create: `database/migrations/2026_09_14_000007_create_stage_logs_table.php`, `database/migrations/2026_09_14_000008_create_stage_log_views_table.php`
- Create: `app/Models/StageLog.php`, `app/Models/StageLogView.php`, `app/Exceptions/StageLogImmutable.php`, `lang/vi/exceptions.php`
- Create: `database/factories/StageLogFactory.php`, `database/factories/StageLogViewFactory.php`
- Test: `tests/Feature/Models/StageLogTest.php`

**Interfaces:**
- Produces: `StageLog` chỉ cho sửa `is_published`, `published_at`, `notified_at`, `updated_by`; mọi sửa khác hoặc xoá ném `App\Exceptions\StageLogImmutable`. `StageLog::views()`, `StageLog::author()`. `StageLogView` unique `(stage_log_id, client_user_id)`. `StageLogFactory::published()`, `StageLogFactory::internalOnly()`.

- [ ] **Bước 1: Viết test**

`tests/Feature/Models/StageLogTest.php`:
```php
<?php

use App\Exceptions\StageLogImmutable;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\StageLogView;
use App\Models\User;

it('records who wrote the log and orders newest first on the matter', function () {
    $author = User::factory()->create();
    $this->actingAs($author, 'web');
    $matter = Matter::factory()->create();

    $old = StageLog::factory()->for($matter)->create(['occurred_at' => now()->subDays(5)]);
    $new = StageLog::factory()->for($matter)->create(['occurred_at' => now()->subDay()]);

    expect($old->created_by)->toBe($author->id)
        ->and($old->author->is($author))->toBeTrue()
        ->and($matter->stageLogs->first()->is($new))->toBeTrue();
});

it('allows publishing flags to change but nothing else', function () {
    $log = StageLog::factory()->internalOnly()->create();

    $log->update(['is_published' => true, 'published_at' => now(), 'notified_at' => now()]);
    expect($log->fresh()->is_published)->toBeTrue();

    expect(fn () => $log->update(['public_content' => 'Nội dung đã bị sửa sau khi công bố, không được phép']))
        ->toThrow(StageLogImmutable::class);
    expect(fn () => $log->update(['occurred_at' => now()]))->toThrow(StageLogImmutable::class);
});

it('cannot be deleted', function () {
    $log = StageLog::factory()->create();

    expect(fn () => $log->delete())->toThrow(StageLogImmutable::class)
        ->and(StageLog::count())->toBe(1);
});

it('stores one view per client user and log', function () {
    $log = StageLog::factory()->published()->create();
    $viewer = ClientUser::factory()->create(['client_id' => $log->matter->client_id]);

    StageLogView::factory()->create(['stage_log_id' => $log->id, 'client_user_id' => $viewer->id]);

    expect($log->views)->toHaveCount(1)
        ->and(fn () => StageLogView::factory()->create(['stage_log_id' => $log->id, 'client_user_id' => $viewer->id]))
        ->toThrow(Illuminate\Database\QueryException::class);
});
```

- [ ] **Bước 2: Chạy test, mong đợi đỏ**

Run: `bin/dev test --filter=StageLogTest`
Expected: FAIL, class không tồn tại.

- [ ] **Bước 3: Migration**

`database/migrations/2026_09_14_000007_create_stage_logs_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bảng quan trọng nhất hệ thống: chỉ thêm, không sửa nội dung, không xoá (SPEC §4.8).
        // Không có deleted_at có chủ đích; model chặn delete.
        Schema::create('stage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->string('from_stage', 40)->nullable();
            $table->string('to_stage', 40)->nullable();
            $table->dateTime('occurred_at');
            $table->text('internal_note')->nullable();
            $table->text('public_content')->nullable();
            $table->text('next_step')->nullable();
            $table->text('client_action')->nullable();
            $table->date('expected_next_update_at')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['matter_id', 'is_published', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_logs');
    }
};
```

`database/migrations/2026_09_14_000008_create_stage_log_views_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bằng chứng khách đã đọc, ghi một lần cho mỗi cặp (SPEC §4.18).
        Schema::create('stage_log_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_log_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_user_id')->constrained()->cascadeOnDelete();
            $table->dateTime('viewed_at');
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->unique(['stage_log_id', 'client_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_log_views');
    }
};
```

- [ ] **Bước 4: Exception, nhãn, model**

`lang/vi/exceptions.php`:
```php
<?php

return [
    'stage_log_immutable' => 'Nhật ký tiến độ không thể sửa nội dung hoặc xoá sau khi đã ghi.',
];
```

`app/Exceptions/StageLogImmutable.php`:
```php
<?php

namespace App\Exceptions;

use DomainException;

class StageLogImmutable extends DomainException
{
    public static function make(): self
    {
        return new self(__('exceptions.stage_log_immutable'));
    }
}
```

`app/Models/StageLog.php`:
```php
<?php

namespace App\Models;

use App\Exceptions\StageLogImmutable;
use App\Models\Concerns\HasBlameable;
use Database\Factories\StageLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StageLog extends Model
{
    /** @use HasFactory<StageLogFactory> */
    use HasFactory;

    use HasBlameable;

    /** Các cột được phép đổi sau khi ghi: chỉ trạng thái công bố và thông báo. */
    public const MUTABLE = ['is_published', 'published_at', 'notified_at', 'updated_by', 'updated_at'];

    protected $fillable = [
        'matter_id', 'from_stage', 'to_stage', 'occurred_at', 'internal_note', 'public_content',
        'next_step', 'client_action', 'expected_next_update_at', 'is_published', 'published_at', 'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'expected_next_update_at' => 'date',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (StageLog $log): void {
            $illegal = array_diff(array_keys($log->getDirty()), self::MUTABLE);

            if ($illegal !== []) {
                throw StageLogImmutable::make();
            }
        });

        static::deleting(function (): void {
            throw StageLogImmutable::make();
        });
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function views(): HasMany
    {
        return $this->hasMany(StageLogView::class);
    }
}
```

`app/Models/StageLogView.php`:
```php
<?php

namespace App\Models;

use Database\Factories\StageLogViewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StageLogView extends Model
{
    /** @use HasFactory<StageLogViewFactory> */
    use HasFactory;

    protected $fillable = ['stage_log_id', 'client_user_id', 'viewed_at', 'ip'];

    protected function casts(): array
    {
        return ['viewed_at' => 'datetime'];
    }

    public function stageLog(): BelongsTo
    {
        return $this->belongsTo(StageLog::class);
    }

    public function clientUser(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class);
    }
}
```

- [ ] **Bước 5: Factory**

`database/factories/StageLogFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Matter;
use App\Models\StageLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StageLog>
 */
class StageLogFactory extends Factory
{
    protected $model = StageLog::class;

    public function definition(): array
    {
        return [
            'matter_id' => Matter::factory(),
            'from_stage' => null,
            'to_stage' => null,
            'occurred_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'internal_note' => 'Ghi chú nội bộ: '.fake()->sentence(8),
            'public_content' => 'Văn phòng đã hoàn tất bước này và sẽ tiếp tục theo dõi vụ việc của anh/chị.',
            'next_step' => 'Chờ toà án phản hồi.',
            'client_action' => null,
            'expected_next_update_at' => now()->addDays(14)->toDateString(),
            'is_published' => true,
            'published_at' => now(),
            'notified_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['is_published' => true, 'published_at' => now()]);
    }

    public function internalOnly(): static
    {
        return $this->state(fn () => ['is_published' => false, 'published_at' => null, 'public_content' => null]);
    }

    public function transition(string $from, string $to): static
    {
        return $this->state(fn () => ['from_stage' => $from, 'to_stage' => $to]);
    }
}
```

`database/factories/StageLogViewFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\ClientUser;
use App\Models\StageLog;
use App\Models\StageLogView;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StageLogView>
 */
class StageLogViewFactory extends Factory
{
    protected $model = StageLogView::class;

    public function definition(): array
    {
        return [
            'stage_log_id' => StageLog::factory(),
            'client_user_id' => ClientUser::factory(),
            'viewed_at' => now(),
            'ip' => fake()->ipv4(),
        ];
    }
}
```

- [ ] **Bước 6: Chạy test, mong đợi xanh**

Run: `bin/dev test --filter=StageLogTest`
Expected: PASS (4 test).

- [ ] **Bước 7: Pint và commit**

```bash
bin/dev pint
git add app/Models/StageLog.php app/Models/StageLogView.php app/Exceptions/StageLogImmutable.php lang/vi/exceptions.php database/migrations/2026_09_14_000007_create_stage_logs_table.php database/migrations/2026_09_14_000008_create_stage_log_views_table.php database/factories/StageLogFactory.php database/factories/StageLogViewFactory.php tests/Feature/Models/StageLogTest.php
git commit -m "feat: append-only stage logs with client view receipts

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 7: Danh mục hồ sơ (`checklist_templates`, `checklist_template_items`, `matter_checklist_items`) và Action sao chép

**Files:**
- Create: `database/migrations/2026_09_14_000009_create_checklist_templates_table.php`, `database/migrations/2026_09_14_000010_create_checklist_template_items_table.php`, `database/migrations/2026_09_14_000011_create_matter_checklist_items_table.php`
- Create: `app/Models/ChecklistTemplate.php`, `app/Models/ChecklistTemplateItem.php`, `app/Models/MatterChecklistItem.php`
- Create: `app/Actions/ApplyChecklistTemplate.php`
- Create: `database/factories/ChecklistTemplateFactory.php`, `database/factories/ChecklistTemplateItemFactory.php`, `database/factories/MatterChecklistItemFactory.php`
- Test: `tests/Feature/Models/ChecklistTest.php`, `tests/Feature/Actions/ApplyChecklistTemplateTest.php`

**Interfaces:**
- Produces: `ApplyChecklistTemplate::handle(Matter $matter, ChecklistTemplate $template): Collection<MatterChecklistItem>` sao chép (không tham chiếu) mọi item với `status = Missing`; gọi lần hai không tạo trùng theo `name`. `MatterChecklistItem` casts `status` → `ChecklistItemStatus`, quan hệ `documents()`, `reviewer()`. `ChecklistTemplateFactory::withItems(int $count)`.

- [ ] **Bước 1: Viết test**

`tests/Feature/Models/ChecklistTest.php`:
```php
<?php

use App\Enums\ChecklistItemStatus;
use App\Models\ChecklistTemplate;
use App\Models\MatterChecklistItem;

it('orders template items and marks required ones', function () {
    $template = ChecklistTemplate::factory()->withItems(3)->create();

    expect($template->items)->toHaveCount(3)
        ->and($template->items->pluck('sort_order')->all())->toBe([1, 2, 3])
        ->and($template->matterType->checklistTemplates->first()->is($template))->toBeTrue();
});

it('defaults a matter checklist item to missing', function () {
    $item = MatterChecklistItem::factory()->create();

    expect($item->status)->toBe(ChecklistItemStatus::Missing)
        ->and($item->matter->checklistItems->first()->is($item))->toBeTrue()
        ->and($item->reviewer)->toBeNull();
});
```

`tests/Feature/Actions/ApplyChecklistTemplateTest.php`:
```php
<?php

use App\Actions\ApplyChecklistTemplate;
use App\Enums\ChecklistItemStatus;
use App\Models\ChecklistTemplate;
use App\Models\Matter;

it('copies template items into the matter as missing items', function () {
    $template = ChecklistTemplate::factory()->withItems(4)->create();
    $matter = Matter::factory()->for($template->matterType, 'matterType')->create();

    $items = app(ApplyChecklistTemplate::class)->handle($matter, $template);

    expect($items)->toHaveCount(4)
        ->and($matter->checklistItems()->count())->toBe(4)
        ->and($matter->checklistItems->pluck('name')->all())->toBe($template->items->pluck('name')->all())
        ->and($matter->checklistItems->every(fn ($i) => $i->status === ChecklistItemStatus::Missing))->toBeTrue()
        ->and($matter->checklistItems->pluck('is_required')->all())->toBe($template->items->pluck('is_required')->all());
});

it('is a copy, not a reference: editing the template later does not touch the matter', function () {
    $template = ChecklistTemplate::factory()->withItems(2)->create();
    $matter = Matter::factory()->for($template->matterType, 'matterType')->create();
    app(ApplyChecklistTemplate::class)->handle($matter, $template);

    $template->items->first()->update(['name' => 'Tên đã đổi']);

    expect($matter->checklistItems()->where('name', 'Tên đã đổi')->exists())->toBeFalse();
});

it('does not duplicate items when applied twice', function () {
    $template = ChecklistTemplate::factory()->withItems(3)->create();
    $matter = Matter::factory()->for($template->matterType, 'matterType')->create();

    app(ApplyChecklistTemplate::class)->handle($matter, $template);
    app(ApplyChecklistTemplate::class)->handle($matter, $template);

    expect($matter->checklistItems()->count())->toBe(3);
});
```

- [ ] **Bước 2: Chạy test, mong đợi đỏ**

Run: `bin/dev test --filter="ChecklistTest|ApplyChecklistTemplateTest"`
Expected: FAIL, class không tồn tại.

- [ ] **Bước 3: Migration**

`database/migrations/2026_09_14_000009_create_checklist_templates_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_type_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_templates');
    }
};
```

`database/migrations/2026_09_14_000010_create_checklist_template_items_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('checklist_templates')->cascadeOnDelete();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_template_items');
    }
};
```

`database/migrations/2026_09_14_000011_create_matter_checklist_items_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sao chép từ template khi mở vụ việc; sửa template sau không ảnh hưởng (SPEC §4.10).
        Schema::create('matter_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('missing');
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['matter_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_checklist_items');
    }
};
```

- [ ] **Bước 4: Model**

`app/Models/ChecklistTemplate.php`:
```php
<?php

namespace App\Models;

use App\Models\Concerns\HasBlameable;
use Database\Factories\ChecklistTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChecklistTemplate extends Model
{
    /** @use HasFactory<ChecklistTemplateFactory> */
    use HasFactory;

    use HasBlameable;
    use SoftDeletes;

    protected $fillable = ['matter_type_id', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function matterType(): BelongsTo
    {
        return $this->belongsTo(MatterType::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChecklistTemplateItem::class, 'template_id')->orderBy('sort_order');
    }
}
```

`app/Models/ChecklistTemplateItem.php`:
```php
<?php

namespace App\Models;

use Database\Factories\ChecklistTemplateItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChecklistTemplateItem extends Model
{
    /** @use HasFactory<ChecklistTemplateItemFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = ['template_id', 'name', 'description', 'is_required', 'sort_order'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'sort_order' => 'integer'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'template_id');
    }
}
```

`app/Models/MatterChecklistItem.php`:
```php
<?php

namespace App\Models;

use App\Enums\ChecklistItemStatus;
use Database\Factories\MatterChecklistItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MatterChecklistItem extends Model
{
    /** @use HasFactory<MatterChecklistItemFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'matter_id', 'name', 'description', 'is_required', 'sort_order',
        'status', 'rejection_reason', 'reviewed_by', 'reviewed_at',
    ];

    protected $attributes = ['status' => 'missing'];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'sort_order' => 'integer',
            'status' => ChecklistItemStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'matter_checklist_item_id');
    }
}
```

- [ ] **Bước 5: Action**

`app/Actions/ApplyChecklistTemplate.php`:
```php
<?php

namespace App\Actions;

use App\Enums\ChecklistItemStatus;
use App\Models\ChecklistTemplate;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sao chép danh mục hồ sơ chuẩn vào vụ việc (SPEC §4.10). Sao chép, không tham chiếu.
 * Gọi lại với cùng template không tạo trùng: item đã có (theo tên) được giữ nguyên.
 */
class ApplyChecklistTemplate
{
    /** @return Collection<int, MatterChecklistItem> */
    public function handle(Matter $matter, ChecklistTemplate $template): Collection
    {
        return DB::transaction(function () use ($matter, $template): Collection {
            $existing = $matter->checklistItems()->pluck('name')->all();

            return $template->items
                ->reject(fn ($item) => in_array($item->name, $existing, true))
                ->map(fn ($item) => $matter->checklistItems()->create([
                    'name' => $item->name,
                    'description' => $item->description,
                    'is_required' => $item->is_required,
                    'sort_order' => $item->sort_order,
                    'status' => ChecklistItemStatus::Missing,
                ]))
                ->values();
        });
    }
}
```

- [ ] **Bước 6: Factory**

`database/factories/ChecklistTemplateFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateItem;
use App\Models\MatterType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChecklistTemplate>
 */
class ChecklistTemplateFactory extends Factory
{
    protected $model = ChecklistTemplate::class;

    public function definition(): array
    {
        return [
            'matter_type_id' => MatterType::factory()->withStages(),
            'name' => 'Danh mục '.fake()->words(2, true),
            'is_active' => true,
        ];
    }

    public function withItems(int $count = 3): static
    {
        return $this->afterCreating(function (ChecklistTemplate $template) use ($count): void {
            for ($i = 1; $i <= $count; $i++) {
                ChecklistTemplateItem::factory()->create([
                    'template_id' => $template->id,
                    'sort_order' => $i,
                    'is_required' => $i === 1,
                ]);
            }
            $template->unsetRelation('items');
        });
    }
}
```

`database/factories/ChecklistTemplateItemFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChecklistTemplateItem>
 */
class ChecklistTemplateItemFactory extends Factory
{
    protected $model = ChecklistTemplateItem::class;

    public function definition(): array
    {
        return [
            'template_id' => ChecklistTemplate::factory(),
            'name' => 'Giấy tờ '.fake()->unique()->numberBetween(1, 99999),
            'description' => 'Bản sao có chứng thực, còn hiệu lực.',
            'is_required' => false,
            'sort_order' => 1,
        ];
    }
}
```

`database/factories/MatterChecklistItemFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Enums\ChecklistItemStatus;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatterChecklistItem>
 */
class MatterChecklistItemFactory extends Factory
{
    protected $model = MatterChecklistItem::class;

    public function definition(): array
    {
        return [
            'matter_id' => Matter::factory(),
            'name' => 'Giấy tờ '.fake()->unique()->numberBetween(1, 99999),
            'description' => 'Bản sao có chứng thực.',
            'is_required' => true,
            'sort_order' => 1,
            'status' => ChecklistItemStatus::Missing,
        ];
    }

    public function status(ChecklistItemStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
```

- [ ] **Bước 7: Chạy test, mong đợi xanh**

Run: `bin/dev test --filter="ChecklistTest|ApplyChecklistTemplateTest"`
Expected: PASS (5 test).

- [ ] **Bước 8: Pint và commit**

```bash
bin/dev pint
git add app/Actions/ApplyChecklistTemplate.php app/Models/ChecklistTemplate.php app/Models/ChecklistTemplateItem.php app/Models/MatterChecklistItem.php database/migrations/2026_09_14_000009_create_checklist_templates_table.php database/migrations/2026_09_14_000010_create_checklist_template_items_table.php database/migrations/2026_09_14_000011_create_matter_checklist_items_table.php database/factories/ChecklistTemplateFactory.php database/factories/ChecklistTemplateItemFactory.php database/factories/MatterChecklistItemFactory.php tests/Feature/Models/ChecklistTest.php tests/Feature/Actions/ApplyChecklistTemplateTest.php
git commit -m "feat: checklist templates and per-matter checklist items with ApplyChecklistTemplate

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 8: Tài liệu (`documents`, `document_downloads`)

**Files:**
- Create: `database/migrations/2026_09_14_000012_create_documents_table.php`, `database/migrations/2026_09_14_000013_create_document_downloads_table.php`
- Create: `app/Models/Document.php`, `app/Models/DocumentDownload.php`
- Create: `database/factories/DocumentFactory.php`, `database/factories/DocumentDownloadFactory.php`
- Test: `tests/Feature/Models/DocumentTest.php`

**Interfaces:**
- Produces: `Document` casts `group` → `DocumentGroup`, `status` → `DocumentStatus`; `uploader(): MorphTo` (User hoặc ClientUser); `parent()`, `newerVersions()`, `checklistItem()`, `publisher()`, `downloads()`; scope `Document::query()->clientVisible()` (group != D và `client_can_view`). Tệp vật lý gắn ở M4 qua medialibrary; M1 chỉ có metadata. `DocumentFactory::group(DocumentGroup)`, `::uploadedBy(User|ClientUser)`, `::pendingReview()` (nhóm A, chờ duyệt).

- [ ] **Bước 1: Viết test**

`tests/Feature/Models/DocumentTest.php`:
```php
<?php

use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\Matter;
use App\Models\User;

it('records a polymorphic uploader for staff and clients', function () {
    $matter = Matter::factory()->create();
    $staff = User::factory()->create();
    $client = ClientUser::factory()->create(['client_id' => $matter->client_id]);

    $byStaff = Document::factory()->for($matter)->uploadedBy($staff)->create();
    $byClient = Document::factory()->for($matter)->uploadedBy($client)->create();

    expect($byStaff->uploader->is($staff))->toBeTrue()
        ->and($byClient->uploader->is($client))->toBeTrue()
        ->and($matter->documents)->toHaveCount(2);
});

it('links versions through parent_document_id', function () {
    $v1 = Document::factory()->create(['version' => 1]);
    $v2 = Document::factory()->for($v1->matter)->create(['version' => 2, 'parent_document_id' => $v1->id]);

    expect($v2->parent->is($v1))->toBeTrue()
        ->and($v1->newerVersions->first()->is($v2))->toBeTrue();
});

it('never exposes group D through the client visible scope', function () {
    $matter = Matter::factory()->create();
    Document::factory()->for($matter)->group(DocumentGroup::Internal)->create(['client_can_view' => true]);
    Document::factory()->for($matter)->group(DocumentGroup::Issued)->create(['client_can_view' => false]);
    $visible = Document::factory()->for($matter)->group(DocumentGroup::ClientProvided)->create(['client_can_view' => true]);

    expect(Document::query()->clientVisible()->pluck('id')->all())->toBe([$visible->id]);
});

it('casts group and status to enums', function () {
    $doc = Document::factory()->pendingReview()->create();

    expect($doc->group)->toBe(DocumentGroup::ClientProvided)
        ->and($doc->status)->toBe(DocumentStatus::Published)
        ->and($doc->checklistItem)->not->toBeNull();
});

it('logs every download with the downloader', function () {
    $doc = Document::factory()->create();
    $viewer = ClientUser::factory()->create();

    DocumentDownload::factory()->create(['document_id' => $doc->id, 'downloader_type' => $viewer->getMorphClass(), 'downloader_id' => $viewer->id]);

    expect($doc->downloads)->toHaveCount(1)
        ->and($doc->downloads->first()->downloader->is($viewer))->toBeTrue();
});
```

- [ ] **Bước 2: Chạy test, mong đợi đỏ**

Run: `bin/dev test --filter=DocumentTest`
Expected: FAIL, class không tồn tại.

- [ ] **Bước 3: Migration**

`database/migrations/2026_09_14_000012_create_documents_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('matter_checklist_item_id')->nullable()->constrained('matter_checklist_items')->nullOnDelete();
            $table->string('group', 1);
            $table->string('title', 250);
            $table->string('status', 20)->default('internal_draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('parent_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('uploader_type');
            $table->unsignedBigInteger('uploader_id');
            $table->boolean('client_can_view')->default(false);
            $table->boolean('client_can_download')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('issued_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['uploader_type', 'uploader_id']);
            $table->index(['matter_id', 'group', 'client_can_view']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
```

`database/migrations/2026_09_14_000013_create_document_downloads_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ghi mọi lượt tải, cả nội bộ lẫn khách (SPEC §4.12). Không xoá.
        Schema::create('document_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('downloader_type');
            $table->unsignedBigInteger('downloader_id');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->dateTime('downloaded_at');
            $table->timestamps();

            $table->index(['downloader_type', 'downloader_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_downloads');
    }
};
```

- [ ] **Bước 4: Model**

`app/Models/Document.php`:
```php
<?php

namespace App\Models;

use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'matter_id', 'matter_checklist_item_id', 'group', 'title', 'status', 'version',
        'parent_document_id', 'uploader_type', 'uploader_id', 'client_can_view', 'client_can_download',
        'published_at', 'published_by', 'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'group' => DocumentGroup::class,
            'status' => DocumentStatus::class,
            'version' => 'integer',
            'client_can_view' => 'boolean',
            'client_can_download' => 'boolean',
            'published_at' => 'datetime',
            'issued_at' => 'date',
        ];
    }

    /** Điều kiện khách được thấy (SPEC §5 portal). Global scope ở M2 sẽ dùng lại. */
    public function scopeClientVisible(Builder $query): Builder
    {
        return $query
            ->where('group', '!=', DocumentGroup::Internal->value)
            ->where('client_can_view', true);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function checklistItem(): BelongsTo
    {
        return $this->belongsTo(MatterChecklistItem::class, 'matter_checklist_item_id');
    }

    public function uploader(): MorphTo
    {
        return $this->morphTo();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'parent_document_id');
    }

    public function newerVersions(): HasMany
    {
        return $this->hasMany(Document::class, 'parent_document_id')->orderBy('version');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function downloads(): HasMany
    {
        return $this->hasMany(DocumentDownload::class);
    }
}
```

`app/Models/DocumentDownload.php`:
```php
<?php

namespace App\Models;

use Database\Factories\DocumentDownloadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class DocumentDownload extends Model
{
    /** @use HasFactory<DocumentDownloadFactory> */
    use HasFactory;

    protected $fillable = ['document_id', 'downloader_type', 'downloader_id', 'ip', 'user_agent', 'downloaded_at'];

    protected function casts(): array
    {
        return ['downloaded_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function downloader(): MorphTo
    {
        return $this->morphTo();
    }
}
```

- [ ] **Bước 5: Factory**

`database/factories/DocumentFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'matter_id' => Matter::factory(),
            'matter_checklist_item_id' => null,
            'group' => DocumentGroup::Issued,
            'title' => 'Văn bản '.fake()->words(3, true),
            'status' => DocumentStatus::InternalDraft,
            'version' => 1,
            'uploader_type' => (new User)->getMorphClass(),
            'uploader_id' => User::factory(),
            'client_can_view' => false,
            'client_can_download' => false,
            'issued_at' => now()->toDateString(),
        ];
    }

    public function group(DocumentGroup $group): static
    {
        return $this->state(fn () => ['group' => $group]);
    }

    public function uploadedBy(User|ClientUser $uploader): static
    {
        return $this->state(fn () => [
            'uploader_type' => $uploader->getMorphClass(),
            'uploader_id' => $uploader->id,
        ]);
    }

    /** Khách nộp qua portal: nhóm A, đã công bố, khách xem và tải được, gắn vào một đầu mục danh mục của đúng vụ việc. */
    public function pendingReview(): static
    {
        return $this
            ->state(fn () => [
                'group' => DocumentGroup::ClientProvided,
                'status' => DocumentStatus::Published,
                'client_can_view' => true,
                'client_can_download' => true,
                'uploader_type' => (new ClientUser)->getMorphClass(),
                'uploader_id' => ClientUser::factory(),
            ])
            ->afterMaking(function (Document $document): void {
                // Lúc này matter_id đã là id thật, nên đầu mục danh mục thuộc đúng vụ việc.
                $document->matter_checklist_item_id ??= MatterChecklistItem::factory()
                    ->status(ChecklistItemStatus::PendingReview)
                    ->create(['matter_id' => $document->matter_id])
                    ->id;
            });
    }
}
```

`database/factories/DocumentDownloadFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentDownload>
 */
class DocumentDownloadFactory extends Factory
{
    protected $model = DocumentDownload::class;

    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'downloader_type' => (new User)->getMorphClass(),
            'downloader_id' => User::factory(),
            'ip' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'downloaded_at' => now(),
        ];
    }
}
```

- [ ] **Bước 6: Chạy test, mong đợi xanh**

Run: `bin/dev test --filter=DocumentTest`
Expected: PASS (5 test).

- [ ] **Bước 7: Pint và commit**

```bash
bin/dev pint
git add app/Models/Document.php app/Models/DocumentDownload.php database/migrations/2026_09_14_000012_create_documents_table.php database/migrations/2026_09_14_000013_create_document_downloads_table.php database/factories/DocumentFactory.php database/factories/DocumentDownloadFactory.php tests/Feature/Models/DocumentTest.php
git commit -m "feat: documents with groups, versions, polymorphic uploader and download log

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 9: Hạn tố tụng và yêu cầu của khách (`deadlines`, `client_requests`, `client_request_replies`)

**Files:**
- Create: `database/migrations/2026_09_14_000014_create_deadlines_table.php`, `database/migrations/2026_09_14_000015_create_client_requests_table.php`, `database/migrations/2026_09_14_000016_create_client_request_replies_table.php`
- Create: `app/Models/Deadline.php`, `app/Models/ClientRequest.php`, `app/Models/ClientRequestReply.php`
- Create: `database/factories/DeadlineFactory.php`, `database/factories/ClientRequestFactory.php`, `database/factories/ClientRequestReplyFactory.php`
- Test: `tests/Feature/Models/DeadlineAndRequestTest.php`

**Interfaces:**
- Produces: `Deadline` casts `severity` → `DeadlineSeverity`, `reminders_sent` array mặc định `[]`, scope `upcoming(int $days)` (chưa xong, `due_date` trong N ngày), `markReminderSent(string $key): void`. `ClientRequest` casts `status` → `ClientRequestStatus`, `replies()`, `assignee()`. `ClientRequestReply::author(): MorphTo`. `DeadlineFactory::dueIn(int $days)`, `::critical()`.

- [ ] **Bước 1: Viết test**

`tests/Feature/Models/DeadlineAndRequestTest.php`:
```php
<?php

use App\Enums\ClientRequestStatus;
use App\Enums\DeadlineSeverity;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;

it('finds upcoming deadlines and tracks reminders sent', function () {
    $matter = Matter::factory()->create();
    $soon = Deadline::factory()->for($matter)->dueIn(2)->critical()->create();
    Deadline::factory()->for($matter)->dueIn(20)->create();
    Deadline::factory()->for($matter)->dueIn(1)->create(['is_completed' => true]);

    expect(Deadline::query()->upcoming(3)->pluck('id')->all())->toBe([$soon->id])
        ->and($soon->severity)->toBe(DeadlineSeverity::Critical)
        ->and($soon->reminders_sent)->toBe([]);

    $soon->markReminderSent('d3');
    $soon->markReminderSent('d3');

    expect($soon->fresh()->reminders_sent)->toBe(['d3'])
        ->and($soon->responsible)->toBeInstanceOf(User::class)
        ->and($matter->deadlines)->toHaveCount(3);
});

it('threads client requests with polymorphic replies', function () {
    $matter = Matter::factory()->create();
    $clientUser = ClientUser::factory()->create(['client_id' => $matter->client_id]);
    $lawyer = $matter->leadLawyer;

    $request = ClientRequest::factory()->for($matter)->create(['client_user_id' => $clientUser->id]);
    ClientRequestReply::factory()->create(['request_id' => $request->id, 'author_type' => $lawyer->getMorphClass(), 'author_id' => $lawyer->id]);
    ClientRequestReply::factory()->create(['request_id' => $request->id, 'author_type' => $clientUser->getMorphClass(), 'author_id' => $clientUser->id]);

    expect($request->status)->toBe(ClientRequestStatus::New)
        ->and($request->replies)->toHaveCount(2)
        ->and($request->replies->first()->author->is($lawyer))->toBeTrue()
        ->and($request->replies->last()->author->is($clientUser))->toBeTrue()
        ->and($request->clientUser->is($clientUser))->toBeTrue()
        ->and($matter->clientRequests->first()->is($request))->toBeTrue();
});
```

- [ ] **Bước 2: Chạy test, mong đợi đỏ**

Run: `bin/dev test --filter=DeadlineAndRequestTest`
Expected: FAIL, class không tồn tại.

- [ ] **Bước 3: Migration**

`database/migrations/2026_09_14_000014_create_deadlines_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deadlines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->date('due_date');
            $table->string('severity', 20)->default('normal');
            $table->foreignId('responsible_user_id')->constrained('users')->restrictOnDelete();
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->boolean('is_published')->default(false);
            $table->json('reminders_sent');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['due_date', 'is_completed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deadlines');
    }
};
```

`database/migrations/2026_09_14_000015_create_client_requests_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_user_id')->constrained()->cascadeOnDelete();
            $table->string('subject', 200);
            $table->text('content');
            $table->string('status', 20)->default('new');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['matter_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_requests');
    }
};
```

`database/migrations/2026_09_14_000016_create_client_request_replies_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_request_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('client_requests')->cascadeOnDelete();
            $table->string('author_type');
            $table->unsignedBigInteger('author_id');
            $table->text('content');
            $table->timestamps();

            $table->index(['author_type', 'author_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_request_replies');
    }
};
```

- [ ] **Bước 4: Model**

`app/Models/Deadline.php`:
```php
<?php

namespace App\Models;

use App\Enums\DeadlineSeverity;
use App\Models\Concerns\HasBlameable;
use Database\Factories\DeadlineFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Deadline extends Model
{
    /** @use HasFactory<DeadlineFactory> */
    use HasFactory;

    use HasBlameable;
    use SoftDeletes;

    protected $fillable = [
        'matter_id', 'name', 'due_date', 'severity', 'responsible_user_id',
        'is_completed', 'completed_at', 'is_published', 'reminders_sent',
    ];

    protected $attributes = ['reminders_sent' => '[]', 'severity' => 'normal'];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'severity' => DeadlineSeverity::class,
            'is_completed' => 'boolean',
            'completed_at' => 'datetime',
            'is_published' => 'boolean',
            'reminders_sent' => 'array',
        ];
    }

    /** Hạn chưa xong, đến hạn trong N ngày tới (kể cả đã quá hạn). */
    public function scopeUpcoming(Builder $query, int $days): Builder
    {
        return $query
            ->where('is_completed', false)
            ->whereDate('due_date', '<=', today()->addDays($days));
    }

    /** Ghi mốc nhắc đã gửi (d7, d3, d1, overdue), không ghi trùng. */
    public function markReminderSent(string $key): void
    {
        $sent = $this->reminders_sent ?? [];

        if (! in_array($key, $sent, true)) {
            $sent[] = $key;
            $this->update(['reminders_sent' => $sent]);
        }
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }
}
```

`app/Models/ClientRequest.php`:
```php
<?php

namespace App\Models;

use App\Enums\ClientRequestStatus;
use Database\Factories\ClientRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientRequest extends Model
{
    /** @use HasFactory<ClientRequestFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = ['matter_id', 'client_user_id', 'subject', 'content', 'status', 'assigned_to', 'answered_at'];

    protected $attributes = ['status' => 'new'];

    protected function casts(): array
    {
        return [
            'status' => ClientRequestStatus::class,
            'answered_at' => 'datetime',
        ];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function clientUser(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(ClientRequestReply::class, 'request_id')->orderBy('created_at');
    }
}
```

`app/Models/ClientRequestReply.php`:
```php
<?php

namespace App\Models;

use Database\Factories\ClientRequestReplyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ClientRequestReply extends Model
{
    /** @use HasFactory<ClientRequestReplyFactory> */
    use HasFactory;

    protected $fillable = ['request_id', 'author_type', 'author_id', 'content'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(ClientRequest::class, 'request_id');
    }

    public function author(): MorphTo
    {
        return $this->morphTo();
    }
}
```

- [ ] **Bước 5: Factory**

`database/factories/DeadlineFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Enums\DeadlineSeverity;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deadline>
 */
class DeadlineFactory extends Factory
{
    protected $model = Deadline::class;

    public function definition(): array
    {
        return [
            'matter_id' => Matter::factory(),
            'name' => 'Hạn nộp '.fake()->words(2, true),
            'due_date' => now()->addDays(10)->toDateString(),
            'severity' => DeadlineSeverity::Normal,
            'responsible_user_id' => User::factory(),
            'is_completed' => false,
            'is_published' => false,
            'reminders_sent' => [],
        ];
    }

    public function dueIn(int $days): static
    {
        return $this->state(fn () => ['due_date' => now()->addDays($days)->toDateString()]);
    }

    public function critical(): static
    {
        return $this->state(fn () => ['severity' => DeadlineSeverity::Critical]);
    }

    public function published(): static
    {
        return $this->state(fn () => ['is_published' => true]);
    }
}
```

`database/factories/ClientRequestFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Enums\ClientRequestStatus;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Matter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientRequest>
 */
class ClientRequestFactory extends Factory
{
    protected $model = ClientRequest::class;

    public function definition(): array
    {
        return [
            'matter_id' => Matter::factory(),
            'client_user_id' => ClientUser::factory(),
            'subject' => 'Hỏi về '.fake()->words(3, true),
            'content' => fake()->paragraph(),
            'status' => ClientRequestStatus::New,
        ];
    }
}
```

`database/factories/ClientRequestReplyFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientRequestReply>
 */
class ClientRequestReplyFactory extends Factory
{
    protected $model = ClientRequestReply::class;

    public function definition(): array
    {
        return [
            'request_id' => ClientRequest::factory(),
            'author_type' => (new User)->getMorphClass(),
            'author_id' => User::factory(),
            'content' => fake()->paragraph(),
        ];
    }
}
```

- [ ] **Bước 6: Chạy test, mong đợi xanh**

Run: `bin/dev test --filter=DeadlineAndRequestTest`
Expected: PASS (2 test).

- [ ] **Bước 7: Pint và commit**

```bash
bin/dev pint
git add app/Models/Deadline.php app/Models/ClientRequest.php app/Models/ClientRequestReply.php database/migrations/2026_09_14_000014_create_deadlines_table.php database/migrations/2026_09_14_000015_create_client_requests_table.php database/migrations/2026_09_14_000016_create_client_request_replies_table.php database/factories/DeadlineFactory.php database/factories/ClientRequestFactory.php database/factories/ClientRequestReplyFactory.php tests/Feature/Models/DeadlineAndRequestTest.php
git commit -m "feat: deadlines with reminder tracking, client requests with threaded replies

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 10: Các bên, liên lạc, thông báo gửi đi, lưu trữ (`matter_parties`, `communication_logs`, `outbound_messages`, `matter_archives`)

**Files:**
- Create: `database/migrations/2026_09_14_000017_create_matter_parties_table.php`, `database/migrations/2026_09_14_000018_create_communication_logs_table.php`, `database/migrations/2026_09_14_000019_create_outbound_messages_table.php`, `database/migrations/2026_09_14_000020_create_matter_archives_table.php`
- Create: `app/Models/MatterParty.php`, `app/Models/CommunicationLog.php`, `app/Models/OutboundMessage.php`, `app/Models/MatterArchive.php`
- Create: `database/factories/MatterPartyFactory.php`, `database/factories/CommunicationLogFactory.php`, `database/factories/OutboundMessageFactory.php`, `database/factories/MatterArchiveFactory.php`
- Test: `tests/Feature/Models/PartiesAndLogsTest.php`

**Interfaces:**
- Produces: `MatterParty::identify(?string $idNumber, ?string $phone): static` điền `id_number_hash` và `phone_normalized` qua `Normalizer` (không lưu số gốc); `MatterParty::name_normalized` cột phụ để so tên; scope `MatterParty::query()->matchingIdentity(?string $hash, ?string $phone)`. `CommunicationLog` casts `type` → `CommunicationType`. `OutboundMessage::related(): MorphTo`, casts `channel`, `status`, `payload` array. `MatterArchive` unique theo `matter_id`, casts ngày. `MatterPartyFactory::ourClient(Client)`, `::defendant()`.

- [ ] **Bước 1: Viết test**

`tests/Feature/Models/PartiesAndLogsTest.php`:
```php
<?php

use App\Enums\CommunicationType;
use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Enums\PartyRole;
use App\Models\Client;
use App\Models\CommunicationLog;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\MatterParty;
use App\Models\OutboundMessage;
use App\Models\StageLog;
use App\Support\Normalizer;

it('stores only hashed and normalized identity for parties', function () {
    $matter = Matter::factory()->create();

    $party = MatterParty::factory()->for($matter)->defendant()
        ->identify('079 090 001 234', '0901234567')
        ->create(['name' => 'Trần Thị Bích Đào']);

    expect($party->id_number_hash)->toBe(hash('sha256', '079090001234'))
        ->and($party->phone_normalized)->toBe('84901234567')
        ->and($party->name_normalized)->toBe('tran thi bich dao')
        ->and($party->role)->toBe(PartyRole::Defendant)
        ->and($party->is_our_client)->toBeFalse()
        ->and(Schema::hasColumn('matter_parties', 'id_number'))->toBeFalse();
});

it('links our client as a party with client_id', function () {
    $client = Client::factory()->create(['id_number' => '012345678901', 'phone' => '0912345678']);
    $matter = Matter::factory()->for($client)->create();

    $party = MatterParty::factory()->ourClient($client)->for($matter)->create();

    expect($party->is_our_client)->toBeTrue()
        ->and($party->client->is($client))->toBeTrue()
        ->and($party->id_number_hash)->toBe(Normalizer::idNumberHash('012345678901'))
        ->and($matter->parties)->toHaveCount(1);
});

it('finds parties matching an identity across matters', function () {
    $a = MatterParty::factory()->identify('111', '0900000001')->create();
    MatterParty::factory()->identify('222', '0900000002')->create();

    expect(MatterParty::query()->matchingIdentity(Normalizer::idNumberHash('111'), null)->pluck('id')->all())->toBe([$a->id])
        ->and(MatterParty::query()->matchingIdentity(null, '84900000001')->pluck('id')->all())->toBe([$a->id])
        ->and(MatterParty::query()->matchingIdentity(null, null)->count())->toBe(0);
});

it('records communication logs with a type and author', function () {
    $log = CommunicationLog::factory()->create(['type' => CommunicationType::CallOut]);

    expect($log->type)->toBe(CommunicationType::CallOut)
        ->and($log->is_visible_to_client)->toBeFalse()
        ->and($log->matter->communicationLogs->first()->is($log))->toBeTrue();
});

it('records outbound messages against a related model', function () {
    $stageLog = StageLog::factory()->create();
    $message = OutboundMessage::factory()->create([
        'related_type' => $stageLog->getMorphClass(), 'related_id' => $stageLog->id,
        'payload' => ['matter_code' => 'VK-2026-DD-0001'],
    ]);

    expect($message->channel)->toBe(MessageChannel::Email)
        ->and($message->status)->toBe(MessageStatus::Queued)
        ->and($message->payload)->toBe(['matter_code' => 'VK-2026-DD-0001'])
        ->and($message->related->is($stageLog))->toBeTrue();
});

it('allows one archive per matter', function () {
    $archive = MatterArchive::factory()->create();

    expect($archive->matter->archive->is($archive))->toBeTrue()
        ->and($archive->retention_until->year)->toBe(now()->addYears(config('vkcrm.retention_years'))->year)
        ->and(fn () => MatterArchive::factory()->create(['matter_id' => $archive->matter_id]))
        ->toThrow(Illuminate\Database\QueryException::class);
});
```

- [ ] **Bước 2: Chạy test, mong đợi đỏ**

Run: `bin/dev test --filter=PartiesAndLogsTest`
Expected: FAIL, class không tồn tại.

- [ ] **Bước 3: Migration**

`database/migrations/2026_09_14_000017_create_matter_parties_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tồn tại vì kiểm tra xung đột lợi ích (SPEC §4.16). Không có cột số căn cước gốc, chỉ hash.
        Schema::create('matter_parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->boolean('is_our_client')->default(false);
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 200);
            $table->string('name_normalized', 200)->nullable();
            $table->string('id_number_hash', 64)->nullable();
            $table->string('phone_normalized', 20)->nullable();
            $table->string('address', 300)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('id_number_hash');
            $table->index('phone_normalized');
            $table->index('name_normalized');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_parties');
    }
};
```
Ghi chú: `name_normalized` không có trong SPEC §4.16 nhưng SPEC §6.10 bước 2 so khớp "tên đã chuẩn hoá"; lưu sẵn để tìm bằng index thay vì chuẩn hoá toàn bảng mỗi lần.

`database/migrations/2026_09_14_000018_create_communication_logs_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->dateTime('occurred_at');
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('counterpart', 200);
            $table->text('summary');
            $table->boolean('is_visible_to_client')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['matter_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_logs');
    }
};
```

`database/migrations/2026_09_14_000019_create_outbound_messages_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Khi khách nói "tôi không nhận được thông báo", tra ở đây (SPEC §4.15).
        Schema::create('outbound_messages', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 10);
            $table->string('recipient', 200);
            $table->string('template', 80);
            $table->json('payload')->nullable();
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('status', 10)->default('queued');
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['related_type', 'related_id']);
            $table->index(['recipient', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_messages');
    }
};
```

`database/migrations/2026_09_14_000020_create_matter_archives_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_archives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('archived_at');
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('handover_package_path')->nullable();
            $table->timestamp('handover_generated_at')->nullable();
            $table->date('client_access_until')->nullable();
            $table->date('retention_until');
            $table->timestamp('destroyed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_archives');
    }
};
```

- [ ] **Bước 4: Model**

`app/Models/MatterParty.php`:
```php
<?php

namespace App\Models;

use App\Enums\PartyRole;
use App\Models\Concerns\HasBlameable;
use App\Support\Normalizer;
use Database\Factories\MatterPartyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MatterParty extends Model
{
    /** @use HasFactory<MatterPartyFactory> */
    use HasFactory;

    use HasBlameable;
    use SoftDeletes;

    protected $fillable = [
        'matter_id', 'role', 'is_our_client', 'client_id', 'name',
        'id_number_hash', 'phone_normalized', 'address', 'note',
    ];

    protected function casts(): array
    {
        return [
            'role' => PartyRole::class,
            'is_our_client' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (MatterParty $party): void {
            $party->name_normalized = Normalizer::name($party->name);
        });
    }

    /** Điền định danh đã chuẩn hoá từ dữ liệu gốc; dữ liệu gốc không được lưu. */
    public function identify(?string $idNumber, ?string $phone): static
    {
        $this->id_number_hash = Normalizer::idNumberHash($idNumber);
        $this->phone_normalized = Normalizer::phone($phone);

        return $this;
    }

    /** Tìm bản ghi trùng hash căn cước hoặc trùng số điện thoại đã chuẩn hoá (SPEC §6.10 bước 2). */
    public function scopeMatchingIdentity(Builder $query, ?string $idNumberHash, ?string $phoneNormalized): Builder
    {
        if ($idNumberHash === null && $phoneNormalized === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($idNumberHash, $phoneNormalized): void {
            if ($idNumberHash !== null) {
                $q->orWhere('id_number_hash', $idNumberHash);
            }
            if ($phoneNormalized !== null) {
                $q->orWhere('phone_normalized', $phoneNormalized);
            }
        });
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
```

`app/Models/CommunicationLog.php`:
```php
<?php

namespace App\Models;

use App\Enums\CommunicationType;
use App\Models\Concerns\HasBlameable;
use Database\Factories\CommunicationLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommunicationLog extends Model
{
    /** @use HasFactory<CommunicationLogFactory> */
    use HasFactory;

    use HasBlameable;
    use SoftDeletes;

    protected $fillable = [
        'matter_id', 'type', 'occurred_at', 'duration_minutes', 'counterpart', 'summary', 'is_visible_to_client',
    ];

    protected function casts(): array
    {
        return [
            'type' => CommunicationType::class,
            'occurred_at' => 'datetime',
            'duration_minutes' => 'integer',
            'is_visible_to_client' => 'boolean',
        ];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

`app/Models/OutboundMessage.php`:
```php
<?php

namespace App\Models;

use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use Database\Factories\OutboundMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class OutboundMessage extends Model
{
    /** @use HasFactory<OutboundMessageFactory> */
    use HasFactory;

    protected $fillable = [
        'channel', 'recipient', 'template', 'payload', 'related_type', 'related_id', 'status', 'sent_at', 'error',
    ];

    protected $attributes = ['status' => 'queued', 'channel' => 'email'];

    protected function casts(): array
    {
        return [
            'channel' => MessageChannel::class,
            'status' => MessageStatus::class,
            'payload' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }
}
```

`app/Models/MatterArchive.php`:
```php
<?php

namespace App\Models;

use Database\Factories\MatterArchiveFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MatterArchive extends Model
{
    /** @use HasFactory<MatterArchiveFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'matter_id', 'archived_at', 'archived_by', 'handover_package_path', 'handover_generated_at',
        'client_access_until', 'retention_until', 'destroyed_at',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
            'handover_generated_at' => 'datetime',
            'client_access_until' => 'date',
            'retention_until' => 'date',
            'destroyed_at' => 'datetime',
        ];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }
}
```

- [ ] **Bước 5: Factory**

`database/factories/MatterPartyFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Enums\PartyRole;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Support\Normalizer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatterParty>
 */
class MatterPartyFactory extends Factory
{
    protected $model = MatterParty::class;

    public function definition(): array
    {
        return [
            'matter_id' => Matter::factory(),
            'role' => PartyRole::Related,
            'is_our_client' => false,
            'client_id' => null,
            'name' => fake()->name(),
            'address' => fake()->address(),
        ];
    }

    public function defendant(): static
    {
        return $this->state(fn () => ['role' => PartyRole::Defendant]);
    }

    public function identify(?string $idNumber, ?string $phone): static
    {
        return $this->state(fn () => [
            'id_number_hash' => Normalizer::idNumberHash($idNumber),
            'phone_normalized' => Normalizer::phone($phone),
        ]);
    }

    /** Khách hàng của văn phòng đứng vai nguyên đơn, định danh lấy từ hồ sơ khách. */
    public function ourClient(Client $client, PartyRole $role = PartyRole::Plaintiff): static
    {
        return $this->state(fn () => [
            'role' => $role,
            'is_our_client' => true,
            'client_id' => $client->id,
            'name' => $client->name,
            'address' => $client->address,
            'id_number_hash' => Normalizer::idNumberHash($client->id_number),
            'phone_normalized' => Normalizer::phone($client->phone),
        ]);
    }
}
```

`database/factories/CommunicationLogFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Enums\CommunicationType;
use App\Models\CommunicationLog;
use App\Models\Matter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunicationLog>
 */
class CommunicationLogFactory extends Factory
{
    protected $model = CommunicationLog::class;

    public function definition(): array
    {
        return [
            'matter_id' => Matter::factory(),
            'type' => CommunicationType::CallOut,
            'occurred_at' => fake()->dateTimeBetween('-20 days', 'now'),
            'duration_minutes' => fake()->numberBetween(5, 45),
            'counterpart' => fake()->name(),
            'summary' => 'Trao đổi về tiến độ và giấy tờ còn thiếu.',
            'is_visible_to_client' => false,
        ];
    }
}
```

`database/factories/OutboundMessageFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Models\OutboundMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutboundMessage>
 */
class OutboundMessageFactory extends Factory
{
    protected $model = OutboundMessage::class;

    public function definition(): array
    {
        return [
            'channel' => MessageChannel::Email,
            'recipient' => fake()->safeEmail(),
            'template' => 'client.stage_update',
            'payload' => [],
            'status' => MessageStatus::Queued,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn () => ['status' => MessageStatus::Sent, 'sent_at' => now()]);
    }
}
```

`database/factories/MatterArchiveFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatterArchive>
 */
class MatterArchiveFactory extends Factory
{
    protected $model = MatterArchive::class;

    public function definition(): array
    {
        return [
            'matter_id' => Matter::factory(),
            'archived_at' => now(),
            'archived_by' => User::factory(),
            'client_access_until' => now()->addDays((int) config('vkcrm.client_access_days', 90))->toDateString(),
            'retention_until' => now()->addYears((int) config('vkcrm.retention_years', 10))->toDateString(),
        ];
    }
}
```

- [ ] **Bước 6: Chạy test, mong đợi xanh**

Run: `bin/dev test --filter=PartiesAndLogsTest`
Expected: PASS (6 test).

- [ ] **Bước 7: Chạy toàn bộ test và kiểm tra migrate trên MariaDB thật**

Run: `bin/dev test` rồi `bin/dev artisan migrate:fresh --seed`
Expected: toàn bộ xanh; migrate chạy sạch 20 migration mới trên MariaDB, seeder M0 vẫn chạy. Nếu MariaDB báo lỗi cột `group` là từ khoá, kiểm tra query builder có bọc backtick; không đổi tên cột (SPEC).

- [ ] **Bước 8: Pint và commit**

```bash
bin/dev pint
git add app/Models/MatterParty.php app/Models/CommunicationLog.php app/Models/OutboundMessage.php app/Models/MatterArchive.php database/migrations/2026_09_14_000017_create_matter_parties_table.php database/migrations/2026_09_14_000018_create_communication_logs_table.php database/migrations/2026_09_14_000019_create_outbound_messages_table.php database/migrations/2026_09_14_000020_create_matter_archives_table.php database/factories/MatterPartyFactory.php database/factories/CommunicationLogFactory.php database/factories/OutboundMessageFactory.php database/factories/MatterArchiveFactory.php tests/Feature/Models/PartiesAndLogsTest.php
git commit -m "feat: matter parties with hashed identity, communication logs, outbound messages, archives

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 11: Dữ liệu mẫu đầy đủ theo SPEC §12

**Files:**
- Create: `database/seeders/StaffSeeder.php`, `database/seeders/MatterTypeSeeder.php`, `database/seeders/ChecklistTemplateSeeder.php`, `database/seeders/ClientSeeder.php`, `database/seeders/MatterSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/Seeders/DemoDataSeederTest.php`

**Interfaces:**
- Consumes: mọi model và factory ở Task 4–10, `ApplyChecklistTemplate`, `StagePresets`, `Normalizer`.
- Produces: `bin/dev artisan migrate:fresh --seed` dựng môi trường demo: 8 nhân sự, 6 loại vụ việc có giai đoạn, 3 danh mục chuẩn, 12 khách với 16 tài khoản portal, 20 vụ việc với đủ tình huống cố ý. `DemoAccountsSeeder` giữ nguyên hai tài khoản đăng nhập M0. Seeder xác định (faker có seed cố định) và an toàn khi chạy lại từng seeder con nhờ `updateOrCreate`; `MatterSeeder` bỏ qua nếu đã có 20 vụ việc.

- [ ] **Bước 1: Viết test**

`tests/Feature/Seeders/DemoDataSeederTest.php`:
```php
<?php

use App\Enums\ChecklistItemStatus;
use App\Enums\UserPosition;
use App\Models\ChecklistTemplate;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\MatterParty;
use App\Models\MatterType;
use App\Models\StageLog;
use App\Models\StageLogView;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

it('seeds the staff roster', function () {
    expect(User::count())->toBe(8)
        ->and(User::where('position', UserPosition::Admin)->count())->toBe(1)
        ->and(User::where('position', UserPosition::Manager)->count())->toBe(1)
        ->and(User::where('position', UserPosition::Lawyer)->count())->toBe(3)
        ->and(User::where('position', UserPosition::Assistant)->count())->toBe(2)
        ->and(User::where('position', UserPosition::Accountant)->count())->toBe(1)
        ->and(User::where('email', 'admin@luatvukhang.com')->exists())->toBeTrue();
});

it('seeds six matter types each with a stage set', function () {
    expect(MatterType::count())->toBe(6)
        ->and(MatterType::pluck('code')->sort()->values()->all())->toBe(['DD', 'DN', 'DS', 'HN', 'HS', 'LD'])
        ->and(MatterType::all()->every(fn ($t) => $t->stages()->count() >= 5))->toBeTrue()
        ->and(MatterType::where('code', 'DS')->first()->stages()->count())->toBe(11);
});

it('seeds the land dispute checklist with twelve items and two more templates', function () {
    $land = ChecklistTemplate::whereHas('matterType', fn ($q) => $q->where('code', 'DD'))->firstOrFail();

    expect(ChecklistTemplate::count())->toBe(3)
        ->and($land->items)->toHaveCount(12)
        ->and($land->items->where('is_required', true))->toHaveCount(4)
        ->and($land->items->first()->name)->toContain('Giấy tờ tuỳ thân');
});

it('seeds twelve clients each with one or two portal accounts', function () {
    expect(Client::count())->toBe(12)
        ->and(ClientUser::count())->toBe(16)
        ->and(Client::doesntHave('clientUsers')->count())->toBe(0)
        ->and(Client::withCount('clientUsers')->get()->max('client_users_count'))->toBe(2)
        ->and(ClientUser::where('email', 'khach1@example.com')->exists())->toBeTrue();
});

it('seeds twenty matters with the deliberate situations from the spec', function () {
    expect(Matter::count())->toBe(20)
        ->and(Matter::where('last_client_update_at', '<', now()->subDays(14))->count())->toBeGreaterThanOrEqual(3)
        ->and(Deadline::query()->upcoming(3)->distinct('matter_id')->count('matter_id'))->toBeGreaterThanOrEqual(2)
        ->and(MatterChecklistItem::where('status', ChecklistItemStatus::Missing)->distinct('matter_id')->count('matter_id'))->toBeGreaterThanOrEqual(4)
        ->and(MatterChecklistItem::where('status', ChecklistItemStatus::PendingReview)->distinct('matter_id')->count('matter_id'))->toBeGreaterThanOrEqual(5)
        ->and(Matter::pluck('stage')->unique()->count())->toBeGreaterThanOrEqual(4);
});

it('gives every matter a lead in the team, three to eight stage logs and two to four parties', function () {
    Matter::with(['team', 'stageLogs', 'parties'])->get()->each(function (Matter $m) {
        expect($m->team->pluck('id'))->toContain($m->lead_lawyer_id)
            ->and($m->stageLogs->count())->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(8)
            ->and($m->parties->count())->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(4)
            ->and($m->parties->where('is_our_client', true)->count())->toBe(1);
    });

    expect(StageLog::where('is_published', true)->count())->toBeGreaterThan(0)
        ->and(StageLog::where('is_published', false)->count())->toBeGreaterThan(0);
});

it('plants one red conflict of interest between two matters', function () {
    $ours = MatterParty::where('is_our_client', true)->whereNotNull('id_number_hash')->get();

    $conflicts = MatterParty::where('is_our_client', false)
        ->whereIn('id_number_hash', $ours->pluck('id_number_hash'))
        ->get()
        ->filter(fn ($p) => $ours->where('id_number_hash', $p->id_number_hash)->where('matter_id', '!=', $p->matter_id)->isNotEmpty());

    expect($conflicts)->toHaveCount(1);
});

it('leaves some published logs unread so the dashboard has data', function () {
    $published = StageLog::where('is_published', true)->pluck('id');
    $viewed = StageLogView::whereIn('stage_log_id', $published)->pluck('stage_log_id')->unique();

    expect($viewed->count())->toBeGreaterThan(0)
        ->and($published->diff($viewed)->count())->toBeGreaterThan(0);
});
```

- [ ] **Bước 2: Chạy test, mong đợi đỏ**

Run: `bin/dev test --filter=DemoDataSeederTest`
Expected: FAIL, class seeder không tồn tại.

- [ ] **Bước 3: `StaffSeeder`**

`database/seeders/StaffSeeder.php`:
```php
<?php

namespace Database\Seeders;

use App\Enums\UserPosition;
use App\Models\User;
use Illuminate\Database\Seeder;

class StaffSeeder extends Seeder
{
    /** @return list<array{email: string, name: string, position: UserPosition, bar_number?: string}> */
    public static function roster(): array
    {
        return [
            ['email' => 'quanly@luatvukhang.com', 'name' => 'Lê Minh Quản', 'position' => UserPosition::Manager],
            ['email' => 'luatsu1@luatvukhang.com', 'name' => 'Vũ Đức Khang', 'position' => UserPosition::Lawyer, 'bar_number' => 'LS-1001'],
            ['email' => 'luatsu2@luatvukhang.com', 'name' => 'Phạm Thu Hà', 'position' => UserPosition::Lawyer, 'bar_number' => 'LS-1002'],
            ['email' => 'luatsu3@luatvukhang.com', 'name' => 'Đỗ Quốc Bảo', 'position' => UserPosition::Lawyer, 'bar_number' => 'LS-1003'],
            ['email' => 'troly1@luatvukhang.com', 'name' => 'Ngô Thị Lan', 'position' => UserPosition::Assistant],
            ['email' => 'troly2@luatvukhang.com', 'name' => 'Bùi Văn Tùng', 'position' => UserPosition::Assistant],
            ['email' => 'ketoan@luatvukhang.com', 'name' => 'Hoàng Kim Ngân', 'position' => UserPosition::Accountant],
        ];
    }

    public function run(): void
    {
        foreach (self::roster() as $index => $person) {
            User::query()->updateOrCreate(
                ['email' => $person['email']],
                [
                    'name' => $person['name'],
                    'password' => 'password',
                    'position' => $person['position'],
                    'bar_number' => $person['bar_number'] ?? null,
                    'phone' => '09'.str_pad((string) (10000000 + $index), 8, '0', STR_PAD_LEFT),
                    'is_active' => true,
                ],
            );
        }
    }
}
```

- [ ] **Bước 4: `MatterTypeSeeder`**

`database/seeders/MatterTypeSeeder.php`:
```php
<?php

namespace Database\Seeders;

use App\Models\MatterType;
use App\Support\StagePresets;
use Illuminate\Database\Seeder;

class MatterTypeSeeder extends Seeder
{
    /** @return list<array{code: string, name: string}> */
    public static function types(): array
    {
        return [
            ['code' => 'DD', 'name' => 'Tranh chấp đất đai'],
            ['code' => 'DS', 'name' => 'Tranh chấp dân sự'],
            ['code' => 'HS', 'name' => 'Hình sự'],
            ['code' => 'DN', 'name' => 'Doanh nghiệp'],
            ['code' => 'LD', 'name' => 'Lao động'],
            ['code' => 'HN', 'name' => 'Hôn nhân và gia đình'],
        ];
    }

    public function run(): void
    {
        foreach (self::types() as $index => $data) {
            $type = MatterType::query()->updateOrCreate(
                ['code' => $data['code']],
                ['name' => $data['name'], 'is_active' => true, 'sort_order' => $index + 1],
            );

            foreach (StagePresets::for($type->code) as $order => $stage) {
                $type->stages()->updateOrCreate(
                    ['key' => $stage['key']],
                    [...$stage, 'sort_order' => $order + 1],
                );
            }
        }
    }
}
```

- [ ] **Bước 5: `ChecklistTemplateSeeder`**

`database/seeders/ChecklistTemplateSeeder.php`:
```php
<?php

namespace Database\Seeders;

use App\Models\ChecklistTemplate;
use App\Models\MatterType;
use Illuminate\Database\Seeder;

class ChecklistTemplateSeeder extends Seeder
{
    /** SPEC §4.9: 12 đầu mục cho tranh chấp đất đai, mục bắt buộc 1, 2, 3, 10. @return list<array{0: string, 1: bool, 2: string}> */
    public static function landDisputeItems(): array
    {
        return [
            ['Giấy tờ tuỳ thân của người khởi kiện, bản sao chứng thực', true, 'Căn cước công dân hoặc hộ chiếu còn hiệu lực, sao y tại UBND hoặc văn phòng công chứng.'],
            ['Giấy chứng nhận quyền sử dụng đất hoặc giấy tờ về quyền sử dụng đất', true, 'Sổ đỏ, sổ hồng hoặc giấy tờ tương đương; nộp bản sao chứng thực, giữ bản chính.'],
            ['Biên bản hoà giải tại Uỷ ban nhân dân cấp xã', true, 'Bắt buộc phải có trước khi khởi kiện tranh chấp đất đai.'],
            ['Hợp đồng chuyển nhượng, tặng cho hoặc văn bản về thừa kế liên quan', false, 'Nếu có.'],
            ['Trích lục bản đồ địa chính, trích đo thửa đất', false, 'Xin tại văn phòng đăng ký đất đai.'],
            ['Văn bản, quyết định của cơ quan nhà nước liên quan đến thửa đất', false, 'Quyết định giao đất, thu hồi, xử phạt nếu có.'],
            ['Chứng cứ về quá trình sử dụng đất: biên lai thuế, hoá đơn điện nước', false, 'Càng nhiều năm càng tốt.'],
            ['Ảnh hiện trạng thửa đất và công trình trên đất', false, 'Chụp rõ ranh giới, mốc giới.'],
            ['Danh sách, địa chỉ người có quyền lợi và nghĩa vụ liên quan', false, 'Họ tên, địa chỉ, số điện thoại nếu biết.'],
            ['Hợp đồng dịch vụ pháp lý và giấy uỷ quyền', true, 'Văn phòng soạn, anh/chị ký.'],
            ['Giấy chứng tử và văn bản kê khai di sản, nếu có yếu tố thừa kế', false, 'Chỉ khi tranh chấp liên quan thừa kế.'],
            ['Tài liệu khác theo yêu cầu của toà án', false, 'Bổ sung khi toà yêu cầu.'],
        ];
    }

    public function run(): void
    {
        $this->template('DD', 'Danh mục hồ sơ tranh chấp đất đai', self::landDisputeItems());

        $this->template('DS', 'Danh mục hồ sơ tranh chấp dân sự', [
            ['Giấy tờ tuỳ thân của người khởi kiện, bản sao chứng thực', true, 'Căn cước hoặc hộ chiếu còn hiệu lực.'],
            ['Hợp đồng, giấy vay, biên nhận hoặc văn bản làm phát sinh tranh chấp', true, 'Bản gốc hoặc bản sao chứng thực.'],
            ['Chứng cứ giao dịch: chuyển khoản, tin nhắn, email', false, 'Chụp màn hình rõ ngày giờ.'],
            ['Hợp đồng dịch vụ pháp lý và giấy uỷ quyền', true, 'Văn phòng soạn, anh/chị ký.'],
            ['Tài liệu khác theo yêu cầu của toà án', false, 'Bổ sung khi toà yêu cầu.'],
        ]);

        $this->template('HN', 'Danh mục hồ sơ hôn nhân và gia đình', [
            ['Giấy tờ tuỳ thân hai bên, bản sao chứng thực', true, 'Căn cước hoặc hộ chiếu.'],
            ['Giấy chứng nhận kết hôn', true, 'Bản chính hoặc trích lục.'],
            ['Giấy khai sinh của con chung', false, 'Nếu có con chung.'],
            ['Giấy tờ về tài sản chung', false, 'Sổ đỏ, đăng ký xe, sổ tiết kiệm.'],
            ['Hợp đồng dịch vụ pháp lý và giấy uỷ quyền', true, 'Văn phòng soạn, anh/chị ký.'],
        ]);
    }

    /** @param list<array{0: string, 1: bool, 2: string}> $items */
    private function template(string $typeCode, string $name, array $items): void
    {
        $type = MatterType::query()->where('code', $typeCode)->firstOrFail();

        $template = ChecklistTemplate::query()->updateOrCreate(
            ['matter_type_id' => $type->id, 'name' => $name],
            ['is_active' => true],
        );

        foreach ($items as $index => [$itemName, $required, $description]) {
            $template->items()->updateOrCreate(
                ['name' => $itemName],
                ['description' => $description, 'is_required' => $required, 'sort_order' => $index + 1],
            );
        }
    }
}
```

- [ ] **Bước 6: `ClientSeeder`**

`database/seeders/ClientSeeder.php`:
```php
<?php

namespace Database\Seeders;

use App\Enums\ClientType;
use App\Models\Client;
use App\Models\ClientUser;
use Illuminate\Database\Seeder;

class ClientSeeder extends Seeder
{
    /**
     * 12 khách cố định. Khách 1 trùng với DemoAccountsSeeder (khach1@example.com).
     * Khách 2, 5, 8, 11 có hai tài khoản portal.
     *
     * @return list<array{name: string, type: ClientType, id_number: string, phone: string, address: string, representative?: string}>
     */
    public static function clients(): array
    {
        return [
            ['name' => 'Nguyễn Văn An', 'type' => ClientType::Individual, 'id_number' => '079090001234', 'phone' => '0901234567', 'address' => 'Quận 1, TP. Hồ Chí Minh'],
            ['name' => 'Trần Thị Bình', 'type' => ClientType::Individual, 'id_number' => '079185002345', 'phone' => '0902345678', 'address' => 'Quận 3, TP. Hồ Chí Minh'],
            ['name' => 'Lê Hoàng Cường', 'type' => ClientType::Individual, 'id_number' => '001088003456', 'phone' => '0903456789', 'address' => 'Quận Hà Đông, Hà Nội'],
            ['name' => 'Công ty TNHH Xây dựng Đại Phát', 'type' => ClientType::Organization, 'id_number' => '0312345678', 'phone' => '02838123456', 'address' => 'Quận Bình Thạnh, TP. Hồ Chí Minh', 'representative' => 'Phạm Đại Phát'],
            ['name' => 'Phạm Thị Dung', 'type' => ClientType::Individual, 'id_number' => '079192004567', 'phone' => '0905678901', 'address' => 'TP. Thủ Đức, TP. Hồ Chí Minh'],
            ['name' => 'Hoàng Minh Đức', 'type' => ClientType::Individual, 'id_number' => '048095005678', 'phone' => '0906789012', 'address' => 'Quận Hải Châu, Đà Nẵng'],
            ['name' => 'Vũ Thị Em', 'type' => ClientType::Individual, 'id_number' => '079078006789', 'phone' => '0907890123', 'address' => 'Quận 7, TP. Hồ Chí Minh'],
            ['name' => 'Công ty Cổ phần Thương mại Sao Việt', 'type' => ClientType::Organization, 'id_number' => '0109876543', 'phone' => '02439876543', 'address' => 'Quận Cầu Giấy, Hà Nội', 'representative' => 'Đặng Sao Việt'],
            ['name' => 'Đặng Văn Giang', 'type' => ClientType::Individual, 'id_number' => '079083007890', 'phone' => '0908901234', 'address' => 'Quận Gò Vấp, TP. Hồ Chí Minh'],
            ['name' => 'Bùi Thị Hạnh', 'type' => ClientType::Individual, 'id_number' => '075091008901', 'phone' => '0909012345', 'address' => 'TP. Biên Hoà, Đồng Nai'],
            ['name' => 'Hộ kinh doanh Minh Khang', 'type' => ClientType::Organization, 'id_number' => '8123456789', 'phone' => '0910123456', 'address' => 'Quận Tân Bình, TP. Hồ Chí Minh', 'representative' => 'Lý Minh Khang'],
            ['name' => 'Ngô Thanh Kiên', 'type' => ClientType::Individual, 'id_number' => '079096009012', 'phone' => '0911234567', 'address' => 'Quận 10, TP. Hồ Chí Minh'],
        ];
    }

    public function run(): void
    {
        foreach (self::clients() as $index => $data) {
            $n = $index + 1;
            $email = "khach{$n}@example.com";

            $client = Client::query()->firstOrCreate(
                ['email' => $email],
                [
                    'type' => $data['type'],
                    'name' => $data['name'],
                    'id_number' => $data['id_number'],
                    'phone' => $data['phone'],
                    'address' => $data['address'],
                    'representative_name' => $data['representative'] ?? null,
                ],
            );

            $this->portalUser($client, $email, $data['representative'] ?? $data['name']);

            if (in_array($n, [2, 5, 8, 11], true)) {
                $this->portalUser($client, "khach{$n}b@example.com", 'Người thân của '.$data['name']);
            }
        }
    }

    private function portalUser(Client $client, string $email, string $name): void
    {
        ClientUser::query()->updateOrCreate(
            ['email' => $email],
            [
                'client_id' => $client->id,
                'name' => $name,
                'password' => 'password',
                'is_active' => true,
                'must_change_password' => false,
                'activated_at' => now(),
            ],
        );
    }
}
```

- [ ] **Bước 7: `MatterSeeder`**

`database/seeders/MatterSeeder.php`:
```php
<?php

namespace Database\Seeders;

use App\Actions\ApplyChecklistTemplate;
use App\Enums\ChecklistItemStatus;
use App\Enums\CommunicationType;
use App\Enums\DeadlineSeverity;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\MatterRole;
use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Enums\PartyRole;
use App\Enums\UserPosition;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\CommunicationLog;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterType;
use App\Models\OutboundMessage;
use App\Models\StageLog;
use App\Models\StageLogView;
use App\Models\User;
use App\Support\Normalizer;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * 20 vụ việc với các tình huống cố ý theo SPEC §12:
 *  - vụ 1–3: quá 14 ngày chưa cập nhật cho khách
 *  - vụ 4–5: có hạn tố tụng trong 3 ngày tới
 *  - vụ 6–9: còn giấy tờ chưa nộp
 *  - vụ 10–14: có tài liệu khách nộp chờ duyệt
 *  - vụ 20: bị đơn trùng căn cước với khách hàng số 2 (đang là khách trong vụ 2) => xung đột đỏ
 *  - vụ 1–10: khách đã xem các dòng công bố; vụ 11–20: chưa xem
 * Chạy lại không tạo thêm nếu đã đủ 20 vụ. Tệp vật lý gắn ở M4.
 */
class MatterSeeder extends Seeder
{
    private const TITLES = [
        'DD' => 'Tranh chấp ranh giới thửa đất tại %s',
        'DS' => 'Tranh chấp hợp đồng vay tài sản với %s',
        'HS' => 'Bào chữa trong vụ án liên quan %s',
        'DN' => 'Thay đổi đăng ký doanh nghiệp cho %s',
        'LD' => 'Tranh chấp chấm dứt hợp đồng lao động với %s',
        'HN' => 'Ly hôn và chia tài sản chung với %s',
    ];

    /**
     * Loại vụ việc theo thứ tự 1..20. Vụ 6–14 phải là loại có danh mục hồ sơ (DD, DS, HN)
     * để tình huống "thiếu giấy tờ" và "chờ duyệt" đủ số theo SPEC §12.
     */
    private const TYPE_SEQUENCE = [
        'DD', 'DS', 'HN', 'LD', 'DN', 'DD', 'DS', 'HN', 'DD', 'DS',
        'HN', 'DD', 'DS', 'HN', 'HS', 'LD', 'DN', 'HS', 'DD', 'DS',
    ];

    private const OPPONENTS = [
        'Lý Văn Lâm', 'Trương Thị Mai', 'Công ty TNHH Nam Phong', 'Đinh Quốc Nam', 'Hồ Thị Oanh',
        'Mai Văn Phúc', 'Công ty CP Quang Minh', 'Tạ Thị Quỳnh', 'Dương Văn Sơn', 'Lâm Thị Tuyết',
        'Chu Văn Uy', 'Công ty TNHH Vạn Xuân', 'Phan Thị Yến', 'Quách Văn Ân', 'Kiều Thị Bảo',
        'Tô Văn Chiến', 'La Thị Diệu', 'Ông Văn Đông', 'Từ Thị Giang', 'Trần Thị Bình',
    ];

    public function run(): void
    {
        if (Matter::count() >= 20) {
            return;
        }

        fake()->seed(20260914);

        $admin = User::query()->where('email', 'admin@luatvukhang.com')->firstOrFail();
        auth('web')->setUser($admin);

        $lawyers = User::query()->where('position', UserPosition::Lawyer)->orderBy('email')->get()->values();
        $assistants = User::query()->where('position', UserPosition::Assistant)->orderBy('email')->get()->values();
        $clients = Client::query()->orderBy('id')->get()->values();
        $types = MatterType::query()->with(['stages', 'checklistTemplates.items'])->get()->keyBy('code');

        for ($i = 1; $i <= 20; $i++) {
            $client = $clients[($i - 1) % $clients->count()];
            $type = $types[self::TYPE_SEQUENCE[$i - 1]];
            $lead = $lawyers[($i - 1) % $lawyers->count()];
            $opponent = self::OPPONENTS[$i - 1];

            $workingStages = $type->stages->reject(fn ($s) => $s->is_terminal || $s->key === 'on_hold')->values();
            $stage = $workingStages[$i % $workingStages->count()];
            $openedAt = now()->subDays(30 + $i * 7);

            $matter = Matter::query()->create([
                'client_id' => $client->id,
                'matter_type_id' => $type->id,
                'title' => sprintf(self::TITLES[$type->code], $opponent),
                'description_internal' => 'Ghi chú nội bộ vụ '.$i.': đánh giá sơ bộ khả năng thắng kiện trung bình.',
                'summary_for_client' => 'Văn phòng đang đại diện anh/chị trong vụ việc này và sẽ cập nhật từng bước.',
                'stage' => $stage->key,
                'stage_entered_at' => now()->subDays($i % 10 + 1),
                'lead_lawyer_id' => $lead->id,
                'opened_at' => $openedAt->toDateString(),
                'is_published_to_portal' => true,
                'court_name' => in_array($type->code, ['DN'], true) ? null : 'Toà án nhân dân Quận '.($i % 12 + 1).', TP. Hồ Chí Minh',
                'case_number' => $i % 2 === 0 ? sprintf('%02d/2026/TLST-DS', $i) : null,
                'last_client_update_at' => $i <= 3 ? now()->subDays(20) : now()->subDays($i % 10),
            ]);

            $matter->addTeamMember($assistants[$i % $assistants->count()], MatterRole::Assistant);
            if ($i % 4 === 0) {
                $matter->addTeamMember($lawyers[$i % $lawyers->count()], MatterRole::Associate);
            }

            $this->parties($matter, $client, $opponent, $i, $clients);
            $this->stageLogs($matter, $stage->key, $i, $openedAt);
            $this->checklist($matter, $type, $i, $client);
            $this->deadlines($matter, $lead, $i);
            $this->communications($matter, $client, $i);
            $this->requests($matter, $client, $lead, $i);
        }

        $this->views();
    }

    private function parties(Matter $matter, Client $client, string $opponent, int $i, Collection $clients): void
    {
        MatterParty::factory()->ourClient($client)->for($matter)->create();

        // Vụ 20: bị đơn chính là khách hàng số 2 của văn phòng => xung đột lợi ích đỏ.
        $conflictClient = $i === 20 ? $clients[1] : null;

        MatterParty::factory()->for($matter)->defendant()->create([
            'name' => $conflictClient?->name ?? $opponent,
            'id_number_hash' => Normalizer::idNumberHash($conflictClient?->id_number ?? sprintf('0%011d', 900000000000 + $i)),
            'phone_normalized' => Normalizer::phone($conflictClient?->phone ?? sprintf('093%07d', $i)),
            'address' => 'TP. Hồ Chí Minh',
        ]);

        if ($i % 3 === 0) {
            MatterParty::factory()->for($matter)->create(['role' => PartyRole::Related, 'name' => 'Người liên quan vụ '.$i]);
        }
        if ($i % 4 === 0) {
            MatterParty::factory()->for($matter)->create(['role' => PartyRole::OpposingCounsel, 'name' => 'Luật sư đối phương vụ '.$i]);
        }
    }

    private function stageLogs(Matter $matter, string $currentStage, int $i, CarbonInterface $openedAt): void
    {
        $count = 3 + ($i % 6); // 3..8
        $stageKeys = $matter->matterType->stages->pluck('key')->all();
        $currentIndex = max(0, (int) array_search($currentStage, $stageKeys, true));

        for ($n = 0; $n < $count; $n++) {
            $occurredAt = $openedAt->copy()->addDays($n * 5);
            $isTransition = $n === 0 || $n % 3 === 0;
            $published = $n % 2 === 0;
            $toIndex = min($currentIndex, intdiv($n, 3));

            $log = StageLog::factory()->for($matter)->create([
                'from_stage' => $isTransition ? ($n === 0 ? null : $stageKeys[max(0, $toIndex - 1)]) : null,
                'to_stage' => $isTransition ? $stageKeys[$toIndex] : null,
                'occurred_at' => $occurredAt,
                'internal_note' => 'Nội bộ vụ '.$i.' dòng '.($n + 1).': đã trao đổi với đồng nghiệp về chiến lược.',
                'public_content' => $published
                    ? 'Văn phòng đã hoàn tất bước công việc số '.($n + 1).' và tiếp tục theo dõi sát vụ việc của anh/chị.'
                    : null,
                'next_step' => $published ? 'Chờ phản hồi của cơ quan có thẩm quyền.' : null,
                'client_action' => $published && $n === $count - 1 && $i % 2 === 0 ? 'Vui lòng bổ sung bản sao chứng thực giấy tờ còn thiếu.' : null,
                'expected_next_update_at' => $published ? $occurredAt->copy()->addDays(14)->toDateString() : null,
                'is_published' => $published,
                'published_at' => $published ? $occurredAt : null,
                'notified_at' => $published ? $occurredAt : null,
            ]);

            if ($published) {
                OutboundMessage::factory()->sent()->create([
                    'channel' => MessageChannel::Email,
                    'recipient' => $matter->client->clientUsers()->first()->email,
                    'template' => 'client.stage_update',
                    'payload' => ['matter_code' => $matter->code],
                    'related_type' => $log->getMorphClass(),
                    'related_id' => $log->id,
                    'status' => MessageStatus::Sent,
                    'sent_at' => $occurredAt,
                ]);
            }
        }
    }

    private function checklist(Matter $matter, MatterType $type, int $i, Client $client): void
    {
        $template = $type->checklistTemplates->first();

        if ($template === null) {
            return;
        }

        $items = app(ApplyChecklistTemplate::class)->handle($matter, $template);
        $clientUser = $client->clientUsers()->first();

        foreach ($items as $index => $item) {
            if ($i >= 6 && $i <= 9) {
                // Còn thiếu giấy tờ: chỉ mục đầu tiên đã nhận, còn lại chưa nộp.
                $item->update(['status' => $index === 0 ? ChecklistItemStatus::Accepted : ChecklistItemStatus::Missing]);

                continue;
            }

            if ($i >= 10 && $i <= 14 && $index === 0) {
                // Khách vừa nộp, chờ văn phòng kiểm tra.
                $item->update(['status' => ChecklistItemStatus::PendingReview]);
                Document::factory()->for($matter)->uploadedBy($clientUser)->create([
                    'matter_checklist_item_id' => $item->id,
                    'group' => DocumentGroup::ClientProvided,
                    'title' => $item->name,
                    'status' => DocumentStatus::Published,
                    'client_can_view' => true,
                    'client_can_download' => true,
                    'published_at' => now()->subDay(),
                ]);

                continue;
            }

            $item->update(['status' => $item->is_required ? ChecklistItemStatus::Accepted : ChecklistItemStatus::NotApplicable]);
        }
    }

    private function deadlines(Matter $matter, User $lead, int $i): void
    {
        Deadline::factory()->for($matter)->create([
            'name' => 'Nộp bổ sung tài liệu theo yêu cầu của toà',
            'due_date' => now()->addDays(10 + $i)->toDateString(),
            'responsible_user_id' => $lead->id,
            'is_published' => true,
        ]);

        if ($i === 4 || $i === 5) {
            Deadline::factory()->for($matter)->critical()->published()->create([
                'name' => 'Hết thời hạn kháng cáo',
                'due_date' => now()->addDays(2)->toDateString(),
                'responsible_user_id' => $lead->id,
            ]);
        }
    }

    private function communications(Matter $matter, Client $client, int $i): void
    {
        CommunicationLog::factory()->for($matter)->create([
            'type' => $i % 2 === 0 ? CommunicationType::CallOut : CommunicationType::Meeting,
            'occurred_at' => now()->subDays($i % 7 + 1),
            'counterpart' => $client->name,
            'summary' => 'Trao đổi tiến độ vụ việc và hướng dẫn chuẩn bị giấy tờ.',
            'is_visible_to_client' => $i % 5 === 0,
        ]);
    }

    private function requests(Matter $matter, Client $client, User $lead, int $i): void
    {
        if ($i > 3) {
            return;
        }

        $clientUser = $client->clientUsers()->first();
        $request = ClientRequest::factory()->for($matter)->create([
            'client_user_id' => $clientUser->id,
            'subject' => 'Hỏi về thời gian dự kiến xét xử',
            'content' => 'Luật sư cho tôi hỏi bao lâu nữa thì toà xử ạ?',
            'assigned_to' => $lead->id,
        ]);

        ClientRequestReply::factory()->create([
            'request_id' => $request->id,
            'author_type' => $lead->getMorphClass(),
            'author_id' => $lead->id,
            'content' => 'Chào anh/chị, dự kiến toà sẽ mở phiên trong tháng tới, văn phòng sẽ báo ngay khi có lịch.',
        ]);
    }

    private function views(): void
    {
        Matter::query()->orderBy('id')->take(10)->with(['stageLogs', 'client.clientUsers'])->get()
            ->each(function (Matter $matter): void {
                $viewer = $matter->client->clientUsers->first();

                $matter->stageLogs->where('is_published', true)->each(fn (StageLog $log) => StageLogView::factory()->create([
                    'stage_log_id' => $log->id,
                    'client_user_id' => $viewer->id,
                    'viewed_at' => $log->published_at->copy()->addHours(3),
                ]));
            });
    }
}
```

- [ ] **Bước 8: Nối `DatabaseSeeder`**

Thay `database/seeders/DatabaseSeeder.php`:
```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DemoAccountsSeeder::class,   // giữ hai tài khoản đăng nhập M0
            StaffSeeder::class,
            MatterTypeSeeder::class,
            ChecklistTemplateSeeder::class,
            ClientSeeder::class,
            MatterSeeder::class,
        ]);
    }
}
```

- [ ] **Bước 9: Chạy test seeder, mong đợi xanh**

Run: `bin/dev test --filter=DemoDataSeederTest`
Expected: PASS (8 test). Lỗi hay gặp và cách xử lý:
- `ClientUser::count()` khác 16: DemoAccountsSeeder tạo `khach1@example.com`, ClientSeeder dùng `updateOrCreate` cùng email nên không trùng; kiểm tra danh sách `[2, 5, 8, 11]`.
- Conflict test đếm khác 1: kiểm tra chỉ vụ 20 dùng `$clients[1]`, và hash của khách 2 (`079185002345`) không trùng chuỗi giả `0900000000xx`.
- `Deadline::upcoming(3)` dưới 2: kiểm tra `due_date` vụ 4 và 5 là `now()->addDays(2)`.

- [ ] **Bước 10: Chạy seeder thật trên MariaDB và mở hai panel**

Run: `bin/dev artisan migrate:fresh --seed` rồi `bin/dev artisan tinker --execute="echo App\Models\Matter::count().' matters, '.App\Models\StageLog::count().' logs';"`
Expected: `20 matters, ...` (số log trong khoảng 60–160). Mở http://localhost/admin đăng nhập `admin@luatvukhang.com`, mở http://localhost/portal đăng nhập `khach1@example.com`; cả hai dashboard vẫn hiện (chưa có resource, chỉ kiểm tra không lỗi).

- [ ] **Bước 11: Chạy toàn bộ test, Pint, commit**

Run: `bin/dev test` — Expected: toàn bộ xanh. Ghi lại tổng số test.

```bash
bin/dev pint
git add database/seeders tests/Feature/Seeders/DemoDataSeederTest.php
git commit -m "feat: full demo dataset seeder per SPEC §12

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 12: Kết thúc M1 — tài liệu và báo cáo

**Files:**
- Modify: `README.md`, `docs/PROGRESS.md`, `docs/superpowers/specs/2026-09-14-vk-crm-toolchain.md` (mục 9)

- [ ] **Bước 1: README — bảng tài khoản demo**

Thay bảng tài khoản trong `README.md` bằng:
```markdown
| Panel | URL | Tài khoản demo | Mật khẩu |
|---|---|---|---|
| Nội bộ, quản trị | http://localhost/admin | `admin@luatvukhang.com` | `password` |
| Nội bộ, trưởng phòng | http://localhost/admin | `quanly@luatvukhang.com` | `password` |
| Nội bộ, luật sư | http://localhost/admin | `luatsu1@luatvukhang.com`, `luatsu2@…`, `luatsu3@…` | `password` |
| Nội bộ, trợ lý | http://localhost/admin | `troly1@luatvukhang.com`, `troly2@…` | `password` |
| Nội bộ, kế toán | http://localhost/admin | `ketoan@luatvukhang.com` | `password` |
| Khách hàng | http://localhost/portal | `khach1@example.com` … `khach12@example.com` (thêm `khach2b@`, `khach5b@`, `khach8b@`, `khach11b@`) | `password` |
| Mailpit | http://localhost:8025 | — | — |

Dữ liệu mẫu có 20 vụ việc với các tình huống cố ý: vụ 1–3 quá hạn cập nhật, vụ 4–5 có hạn trong 3 ngày,
vụ 6–9 thiếu giấy tờ, vụ 10–14 có tài liệu chờ duyệt, vụ 20 xung đột lợi ích với khách hàng số 2.
```
Trong mục "Cấu trúc", đổi dòng `Actions/` thành `Toàn bộ logic nghiệp vụ (ApplyChecklistTemplate từ M1)`.

- [ ] **Bước 2: PROGRESS**

Trong `docs/PROGRESS.md` đổi dòng M1 thành:
```markdown
| M1 Migration / model / enum / factory / seeder | ✅ Xong | 2026-09-XX | 19 bảng SPEC §4 + `code_sequences` + `client_password_reset_tokens`; 13 enum; seeder đủ SPEC §12; N test xanh |
```
(điền ngày và số test thật). Thêm mục "Ghi chú M1":
```markdown
## Ghi chú M1

- Mã tự sinh dùng bảng đếm `code_sequences` khoá dòng, không còn kẽ hở đồng thời. Có thể có khoảng trống số nếu insert lỗi sau khi lấy số; SPEC không yêu cầu liên tục.
- `stage_logs` không có `deleted_at`, model chặn sửa nội dung và xoá (`StageLogImmutable`). Chỉ `is_published`, `published_at`, `notified_at` được đổi.
- `matter_parties` có thêm `name_normalized` để so tên có index (SPEC §6.10 bước 2).
- Bảng nhật ký thuần và pivot không có `deleted_at` (xem "Ràng buộc toàn cục" trong kế hoạch M1).
- Chưa khai báo `Matter::timeEntries()` (SPEC §15) vì chưa có bảng; làm ở giai đoạn 2 cùng bảng `time_entries`.
- Tệp vật lý của `documents` gắn ở M4 qua medialibrary; M1 chỉ có metadata.
- Broker đặt lại mật khẩu của khách dùng bảng `client_password_reset_tokens` riêng.
```

- [ ] **Bước 3: Toolchain mục 9**

Thêm dòng vào bảng mục 9 của `docs/superpowers/specs/2026-09-14-vk-crm-toolchain.md`:
```markdown
| 2026-09-XX | (không cài gói mới ở M1) | — | Đúng kế hoạch |
```

- [ ] **Bước 4: Kiểm tra cuối và commit**

```bash
bin/dev test
bin/dev pint --test
git add README.md docs/PROGRESS.md docs/superpowers/specs/2026-09-14-vk-crm-toolchain.md
git commit -m "docs: M1 complete — data model, enums, factories, demo seeder

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

- [ ] **Bước 5: Dừng lại báo cáo**

Theo CLAUDE.md: kết thúc milestone, báo cáo cho người dùng số test, số bảng, các quyết định lệch SPEC (ghi ở PROGRESS), và đề nghị bắt đầu M2 (phân quyền: `spatie/laravel-permission`, Shield, Policy, global scope `client`).

---

## Tự rà soát kế hoạch

**Độ phủ SPEC §4:** 4.1–4.3 có từ M0 (Task 0 bổ sung blameable, broker). 4.4–4.5 Task 4. 4.6–4.7 Task 5. 4.8, 4.18 Task 6. 4.9–4.10 Task 7. 4.11–4.12 Task 8. 4.13–4.14 Task 9. 4.15–4.17, 4.19 Task 10. §6.1 Task 1 + 5. §6.10 bước 1 Task 2 + 10. §12 Task 11. §13 dòng M1 Task 12. §15: `outbound_messages.channel` có `zns`, `sms` (Task 3); `timeEntries()` hoãn có ghi chú.

**Nhất quán tên gọi:** `CodeSequence::next/format` dùng ở Task 1 và 5. `Normalizer::name/phone/idNumberHash` dùng ở Task 2, 10, 11. `StagePresets::for/civil` dùng ở Task 4, 11. `HasBlameable` gắn ở Client, MatterType, Matter, StageLog, ChecklistTemplate, Deadline, MatterParty, CommunicationLog; mỗi bảng tương ứng đều có cột `created_by`, `updated_by`. `StageLog::MUTABLE` chứa `updated_by` để trait không làm guard ném lỗi. `ApplyChecklistTemplate::handle(Matter, ChecklistTemplate): Collection` dùng ở Task 7 và 11. Factory state: `MatterType::withStages`, `Matter::restricted/unpublished/atStage`, `StageLog::published/internalOnly/transition`, `Document::group/uploadedBy/pendingReview`, `Deadline::dueIn/critical/published`, `MatterParty::defendant/identify/ourClient`, `OutboundMessage::sent`, `ChecklistTemplate::withItems`.

**Không có placeholder:** mọi bước có mã đầy đủ; các giá trị "N", "XX" ở Task 12 là số đo thật điền lúc chạy, không phải mã.

**Điểm cần chú ý khi thực thi:**
- Test seeder chạy toàn bộ DatabaseSeeder trong `beforeEach` nên chậm (vài giây mỗi test); chấp nhận ở M1, có thể gộp thành một test nếu vượt 30 giây.
- `fake()->seed()` đặt trong MatterSeeder để tên, địa chỉ ổn định giữa các lần chạy.
- Trên SQLite, unique constraint vẫn hoạt động; các test `toThrow(QueryException)` dựa vào điều đó.

