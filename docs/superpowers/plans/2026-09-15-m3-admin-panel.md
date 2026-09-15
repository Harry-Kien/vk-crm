# VK-CRM M3 — Kế hoạch panel nội bộ, chuyển giai đoạn, kiểm tra xung đột lợi ích

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Luật sư mở được vụ việc mới và chuyển giai đoạn từ đầu đến cuối trên giao diện, mỗi lần mở vụ đều chạy kiểm tra xung đột lợi ích có dấu vết, và toàn bộ test phần "Xung đột lợi ích" của SPEC §11 xanh.

**Architecture:** Nghiệp vụ nằm ở `app/Actions/`; Filament resource chỉ gọi Action (CLAUDE.md). Ba Action mới: `RunConflictCheck` (thuần đọc, trả về kết quả phân mức), `OpenMatter` (mở vụ việc: sinh mã, sao chép danh mục, ghi các bên, chạy kiểm tra xung đột, chặn mức đỏ), `TransitionMatterStage` (chuyển giai đoạn hoặc thêm dòng cập nhật không đổi giai đoạn — cùng một Action, `to_stage` bằng giai đoạn hiện tại là trường hợp §6.3). Nhật ký hệ thống dùng `spatie/laravel-activitylog` 4.x.

Mọi resource của panel admin phải giới hạn truy vấn: `Matter` qua `listableBy`, các model con qua `whereHas('matter', listableBy)`. M2 chỉ cài tầng policy cho từng bản ghi; danh sách không tự giới hạn, nên quên một chỗ là rò rỉ vụ việc ngoài đội ngũ (SPEC §4.7 "kể cả trong kết quả tìm kiếm").

**Tech Stack:** PHP 8.3, Laravel 13.31, Filament 5.8, `spatie/laravel-activitylog` ^4 (4.12.3 đã kiểm tra tương thích), Pest 4, Pint. Mọi lệnh qua `bin/dev`.

**Spec:** `docs/SPEC.md` §6.1 (mã hồ sơ — đã có), §6.2 (`TransitionMatterStage`), §6.3 (dòng cập nhật không đổi giai đoạn), §6.10 (`RunConflictCheck`, toàn bộ), §7.1–7.4 (giao diện admin), §10.6 (activity log bắt buộc ghi những gì), §11 mục "Xung đột lợi ích" và "Quyền nội bộ", §13 dòng M3. `docs/superpowers/specs/2026-09-14-vk-crm-toolchain.md` §2 (M3).

## Ràng buộc toàn cục

- PHP sàn **8.3**. Gói mới duy nhất bắt buộc: `spatie/laravel-activitylog` ^4 (bản 5 đòi PHP 8.4). Các gói giao diện phụ (`filament-shield`, `filament-fullcalendar`, `filament-table-repeater`) **chỉ cài nếu thực sự cần và có bản Filament 5 ổn định**; mặc định là không cài.
- Không Redis, Horizon, Octane, Reverb, Pulse, Scout, Telescope.
- Định danh mã tiếng Anh. **Mọi chuỗi hiển thị tiếng Việt qua `__()` và `lang/vi/`** — không hardcode tiếng Việt trong class Filament (design spec §6).
- Nghiệp vụ chỉ ở `app/Actions/`. Resource, page, widget chỉ gọi Action. Action ném exception có tên rõ.
- **Filament 5 khác Filament 3/4 rất nhiều.** Cấu trúc do `make:filament-resource` sinh ra là chuẩn: `app/Filament/Admin/Resources/<Số nhiều>/` chứa `<Model>Resource.php`, `Schemas/<Model>Form.php`, `Tables/<Số nhiều>Table.php`, `Pages/`. Form nhận `Filament\Schemas\Schema` (không phải `Forms\Form`). Action nằm ở `Filament\Actions\` (không phải `Filament\Tables\Actions\`). Icon là enum `Filament\Support\Icons\Heroicon`.
  **Không đoán API.** Trước khi viết bất kỳ đoạn Filament nào: chạy `bin/dev artisan make:filament-resource <Model> --panel=admin` để lấy khung thật, đọc `vendor/filament/` khi cần, hoặc tra tài liệu qua context7. Kế hoạch này mô tả **hành vi và luật nghiệp vụ**; chi tiết API do người cài đặt xác minh trên bản đang cài.
- TDD với Pest: test đỏ trước. Test giao diện dùng `livewire()` helper của `pestphp/pest-plugin-livewire` (cài ở Task 1) cho resource; test Action là test thường.
- Kết thúc mỗi task: `bin/dev test` xanh, `bin/dev pint` sạch, commit.
- Commit message kết thúc bằng `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>` (chép nguyên văn).

## Việc bắt buộc mang sang từ rà soát M1/M2

Ghi ở đây để không rơi: mỗi mục có task phụ trách.

| Việc | Task |
|---|---|
| Mọi resource `getEloquentQuery()` dùng `listableBy`; resource con lọc qua `whereHas('matter')` | 3, 6, 9 |
| `MatterPolicy::view` chạy một EXISTS mỗi lần gọi → thêm đường kiểm tra trong bộ nhớ khi `team` đã nạp | 3 |
| Kế toán: danh sách rút gọn (ẩn cột nội dung) | 3 |
| Luật sư không có `client.manage` nên không xem được hồ sơ khách của vụ mình → tách quyền xem khỏi quyền quản lý | 2 |
| `RunConflictCheck` phải so khớp **tên đã chuẩn hoá** là ưu tiên thứ ba | 7 |
| Kết quả kiểm tra xung đột chỉ được lộ mã hồ sơ, loại vụ việc và vai của bên đó | 7 |
| Seeder tạo sẵn một vụ `restricted` | 10 |
| Khoá `unique(matter_type_id, key)` tính cả dòng đã xoá mềm; `stages()` chưa có tiêu chí phụ khi trùng `sort_order`; `MatterType` không có giai đoạn nào thì `matters.stage` vi phạm NOT NULL | 4 |
| Khoá dòng vụ việc trước khi sao chép danh mục hồ sơ | 5 |

---

## Cấu trúc tệp (trạng thái cuối M3)

| Đường dẫn | Trách nhiệm |
|---|---|
| `app/Actions/RunConflictCheck.php` | Thuật toán SPEC §6.10, thuần đọc |
| `app/Support/ConflictCheckResult.php`, `ConflictMatch.php`, `ConflictLevel.php` (enum) | Kiểu trả về của kiểm tra xung đột |
| `app/Actions/OpenMatter.php` | Mở vụ việc: mã, các bên, kiểm tra xung đột, danh mục hồ sơ, activity log |
| `app/Actions/TransitionMatterStage.php` | SPEC §6.2 và §6.3 |
| `app/Exceptions/{InvalidStageTransition,ConflictBlocked,StageNotConfigured}.php` | |
| `app/Events/StageLogPublished.php` | Dispatch ở §6.2 bước 6; listener ở M6 |
| `app/Filament/Admin/Resources/...` | Client, ClientUser, MatterType (+ relation manager stages), User, Matter |
| `app/Filament/Admin/Resources/Matters/Pages/ViewMatter.php` | Trang chi tiết có tab Tổng quan / Tiến độ / Các bên |
| `app/Filament/Admin/Widgets/StaleMattersWidget.php`, `MattersByStageWidget.php` | SPEC §7.1 mục 1 và 6 |
| `app/Filament/Admin/Concerns/ScopesToVisibleMatters.php` | Dùng chung cho mọi resource con |
| `lang/vi/{matters,clients,conflicts,actions,widgets}.php` | Chuỗi giao diện |
| `tests/Feature/Actions/*`, `tests/Feature/Filament/*` | |

---

### Task 1: Cài activitylog và công cụ test giao diện, bật nhật ký cho model

**Files:** `composer.json`, migration của activitylog, `config/activitylog.php`, `app/Models/{Matter,Client,ClientUser,User,StageLog,Document,MatterParty}.php`, `lang/vi/activity.php`, `tests/Feature/ActivityLogTest.php`

**Interfaces:** Produces: trait `LogsActivity` trên các model SPEC §10.6 yêu cầu; helper `App\Support\Audit::record(string $event, ?Model $subject, array $properties)` để Action ghi nhật ký có cấu trúc thống nhất.

- [ ] **Bước 1: Cài**

```bash
bin/dev composer require spatie/laravel-activitylog:^4.0
bin/dev composer require --dev pestphp/pest-plugin-livewire
bin/dev artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag=activitylog-migrations
bin/dev artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag=activitylog-config
bin/dev artisan migrate
bin/dev test
```
Toàn bộ 132 test cũ phải vẫn xanh.

- [ ] **Bước 2: Test đỏ**

`tests/Feature/ActivityLogTest.php` — khẳng định: sửa `Matter` ghi một activity gắn `causer` là người đang đăng nhập và `subject` là vụ việc; `Audit::record()` ghi được sự kiện không gắn model; nhật ký ghi cả khi không có ai đăng nhập (causer null) mà không ném lỗi.

- [ ] **Bước 3: Cài đặt**

Thêm `Spatie\Activitylog\Traits\LogsActivity` vào `Matter`, `Client`, `ClientUser`, `User`, `MatterParty` với `getActivitylogOptions()` khai báo `logOnly([...])` các cột nghiệp vụ (không log cột nội bộ dài như `description_internal`), `logOnlyDirty()`, `dontSubmitEmptyLogs()`.

**Không** thêm vào `StageLog` (bảng này đã bất biến và tự nó là nhật ký) và **không** log giá trị `clients.id_number` (SPEC §10.5) — loại khỏi `logOnly`.

`app/Support/Audit.php`:
```php
final class Audit
{
    public static function record(string $event, ?Model $subject = null, array $properties = []): void
    {
        $log = activity()->event($event)->withProperties($properties);

        if ($subject !== null) {
            $log->performedOn($subject);
        }

        if (($user = auth('web')->user()) !== null) {
            $log->causedBy($user);
        }

        $log->log($event);
    }
}
```

- [ ] **Bước 4:** test xanh, `bin/dev pint`, commit `feat: activity log for business models and a structured audit helper`.

---

### Task 2: Tách quyền xem khách hàng khỏi quyền quản lý

**Files:** `app/Policies/ClientPolicy.php`, `app/Policies/ClientUserPolicy.php`, `tests/Feature/Authorization/ChildPolicyTest.php`

**Bối cảnh:** Rà soát M2 phát hiện luật sư không có `client.manage` nên `ClientPolicy::view` trả false — trang chi tiết vụ việc không hiển thị được thông tin liên hệ của chính khách hàng trong vụ mình phụ trách.

**Ruling:** không thêm quyền mới vào SPEC §5. Thay vào đó `ClientPolicy::view` chấp nhận thêm một đường: nhân sự xem được hồ sơ khách nếu họ xem được **ít nhất một vụ việc** của khách đó.

- [ ] **Bước 1: Test đỏ** trong `ChildPolicyTest`: luật sư phụ trách xem được `Client` của vụ mình; luật sư ngoài đội ngũ **không** xem được `Client` đó; trợ lý (có `client.manage`) xem được mọi khách; luật sư vẫn **không** `update`/`create`/`delete` được khách.

- [ ] **Bước 2: Cài đặt** — `ClientPolicy::view`:
```php
        return $user->can(Permission::ClientManage->value)
            || Matter::query()->listableBy($user)->where('client_id', $client->getKey())->exists();
```
giữ nguyên nhánh `ClientUser`. `update`/`create`/`delete` không đổi. Làm tương tự cho `ClientUserPolicy::view` (qua `$clientUser->client_id`).

- [ ] **Bước 3:** test xanh, pint, commit `feat: staff may read the client of a matter they can see`.

---

### Task 3: Resource `Matter` — danh sách có giới hạn và cột theo vai trò

**Files:** `app/Filament/Admin/Resources/Matters/**`, `app/Filament/Admin/Concerns/ScopesToVisibleMatters.php`, `app/Models/Matter.php` (đường kiểm tra trong bộ nhớ), `app/Policies/MatterPolicy.php`, `lang/vi/matters.php`, `tests/Feature/Filament/MatterResourceTest.php`

**Interfaces:** Produces: `MatterResource` với `getEloquentQuery()` = `parent::getEloquentQuery()->listableBy(auth()->user())` và `getRecordRouteBindingEloquentQuery()` cùng điều kiện (nếu không, mở thẳng URL sẽ vượt qua danh sách). `Matter::isListableBy(User): bool` — bản kiểm tra trong bộ nhớ dùng quan hệ `team` đã nạp. Trait `ScopesToVisibleMatters` cho resource con dùng lại ở Task 6, 9.

- [ ] **Bước 1: Test đỏ** `tests/Feature/Filament/MatterResourceTest.php`, dùng `livewire(ListMatters::class)`:
  - luật sư ngoài đội ngũ không thấy vụ việc trong bảng (`assertCanNotSeeTableRecords`)
  - luật sư trong đội ngũ thấy đúng vụ của mình
  - trưởng phòng thấy vụ thường nhưng không thấy vụ `restricted` không phải của mình
  - kế toán thấy danh sách nhưng **không** thấy cột `title` (danh sách rút gọn, SPEC §5)
  - mở thẳng URL trang xem của một vụ ngoài quyền → 404 (`assertNotFound` trên trang `ViewMatter`)
  - `Matter::isListableBy()` trả cùng kết quả với `scopeListableBy` cho cả 5 vai trò

- [ ] **Bước 2: `Matter::isListableBy`** — cùng ba điều kiện với `scopeListableBy`, đọc từ quan hệ `team` đã nạp:
```php
    public function isListableBy(User $user): bool
    {
        if ($this->confidentiality === Confidentiality::Restricted) {
            return $user->hasRole(StaffRole::Admin->value)
                || ($user->can(Permission::MatterView->value) && $this->lead_lawyer_id === $user->getKey());
        }

        if ($user->can(Permission::MatterViewAny->value)) {
            return true;
        }

        return $user->can(Permission::MatterView->value)
            && ($this->relationLoaded('team')
                ? $this->team->contains('id', $user->getKey())
                : $this->team()->whereKey($user->getKey())->exists());
    }
```
`MatterPolicy::view` dùng nó khi bản ghi đã nạp `team`, ngược lại giữ truy vấn cũ. Test phải khẳng định hai đường cho cùng kết quả (đó là lý do có test cuối ở bước 1).

- [ ] **Bước 3: Resource.** Sinh khung bằng `make:filament-resource Matter --panel=admin`, rồi:
  - Bảng theo SPEC §7.2: mã, khách, loại, tiêu đề, giai đoạn (badge), luật sư phụ trách, "cập nhật gần nhất cho khách" hiển thị dạng tương đối, tô vàng > 10 ngày, đỏ > 14.
  - Cột `title` và `summary_for_client` ẩn với ai không có `matter.view` (kế toán).
  - Bộ lọc: giai đoạn, loại, luật sư, đã công bố portal.
  - `getEloquentQuery()` và `getRecordRouteBindingEloquentQuery()` đều `listableBy`, và eager-load `['client','matterType','leadLawyer','team']` để tránh N+1.
  - Không có trang `edit` mặc định ở task này; trang chi tiết làm ở Task 6.

- [ ] **Bước 4:** test xanh, pint, commit `feat: matter list scoped to what each role may see`.

---

### Task 4: Resource `MatterType` và giai đoạn, cùng ba lỗ hổng cấu hình từ M1

**Files:** `app/Filament/Admin/Resources/MatterTypes/**` (kèm relation manager cho `stages`), `app/Models/{MatterType,MatterTypeStage}.php`, migration sửa unique, `app/Exceptions/StageNotConfigured.php`, `tests/Feature/Filament/MatterTypeResourceTest.php`, `tests/Feature/Models/MatterTypeTest.php`

**Ba việc mang sang từ M1:**
1. `unique(matter_type_id, key)` tính cả dòng đã xoá mềm → quản trị xoá một giai đoạn rồi tạo lại cùng `key` sẽ lỗi. Sửa: thay bằng unique một phần, hoặc bỏ unique ở DB và kiểm tra trong Action/form với điều kiện `whereNull('deleted_at')`. **Chọn cách hai** vì MariaDB không có unique một phần: bỏ ràng buộc DB, thêm rule `unique` của Laravel có `whereNull('deleted_at')` trong form, và một test khẳng định tạo lại `key` đã xoá mềm thành công.
2. `stages()` sắp theo `sort_order` không có tiêu chí phụ → thêm `->orderBy('id')`.
3. `MatterType` không có giai đoạn nào thì `Matter::creating` gán `stage = null` và vi phạm NOT NULL. Sửa: ném `StageNotConfigured` có thông điệp tiếng Việt rõ ràng, và form tạo vụ việc chỉ cho chọn loại đã có giai đoạn.

- [ ] Test đỏ cho cả ba, cộng test resource: chỉ vai trò có `settings.manage` mở được `MatterTypeResource` (`assertForbidden` cho luật sư).
- [ ] Cài đặt, test xanh, pint, commit `feat: matter type and stage administration, config gaps closed`.

---

### Task 5: `OpenMatter` — mở vụ việc

**Files:** `app/Actions/OpenMatter.php`, `tests/Feature/Actions/OpenMatterTest.php`

**Interfaces:** `OpenMatter::handle(array $attributes, array $parties, ?string $overrideReason = null): Matter`. Trong một transaction: khoá dòng khách hàng, chạy `RunConflictCheck` (Task 7) trên danh sách các bên, chặn mức đỏ trừ khi người dùng có vai trò `manager`/`admin` **và** có `$overrideReason` không rỗng, tạo `Matter`, ghi `matter_parties`, gọi `ApplyChecklistTemplate` với template đang hoạt động của loại vụ việc (khoá dòng vụ việc trước khi sao chép — việc mang sang từ M1), ghi activity log kèm kết quả kiểm tra xung đột và lý do ghi đè nếu có.

- [ ] Test đỏ theo SPEC §11 mục "Xung đột lợi ích": bị đơn trùng số căn cước với khách hiện hữu → `ConflictBlocked`, **không lưu gì**; cùng tình huống nhưng `manager` có lý do → lưu được và activity log chứa lý do; trùng tên khác căn cước khác điện thoại → chỉ cảnh báo vàng, lưu được; mọi lần chạy đều sinh activity log kể cả kết quả xanh.
- [ ] Cài đặt, test xanh, pint, commit `feat: OpenMatter action with blocking conflict check`.

---

### Task 6: Trang chi tiết vụ việc — tab Tổng quan, Tiến độ, Các bên

**Files:** `app/Filament/Admin/Resources/Matters/Pages/ViewMatter.php`, các Schema/Table phụ, `lang/vi/matters.php`, `tests/Feature/Filament/ViewMatterTest.php`

Theo SPEC §7.2. Ba tab ở M3 (Danh mục hồ sơ và Tài liệu là M4; Mốc thời hạn, Liên lạc, Yêu cầu là M6/M7):
- **Tổng quan** — thông tin vụ việc, đội ngũ, công tắc công bố portal (chỉ ai có `matter.update`).
- **Tiến độ** — dòng thời gian `stage_logs` mới nhất trên cùng. Ghi chú nội bộ nền xám có nhãn "Nội bộ"; nội dung đã công bố nền trắng kèm nhãn trạng thái đọc ("Khách đã xem lúc …" / "Khách chưa xem", tô vàng khi chưa xem quá 5 ngày). Hai nút lớn ngang nhau: *Chuyển giai đoạn* và *Thêm cập nhật*.
- **Các bên** — bảng `matter_parties`, thêm một bên thì chạy lại `RunConflictCheck` và hiện kết quả tại chỗ.

- [ ] Test đỏ: trang trả 404 với vụ ngoài quyền; ghi chú nội bộ **không** xuất hiện khi render dưới guard `client` (dù trang này là admin, test khẳng định chuỗi đánh dấu chỉ có mặt cho nhân sự có quyền); trợ lý không thấy nút *Chuyển giai đoạn*.
- [ ] Cài đặt, test xanh, pint, commit `feat: matter detail page with overview, progress and parties tabs`.

---

### Task 7: `RunConflictCheck`

**Files:** `app/Actions/RunConflictCheck.php`, `app/Support/{ConflictCheckResult,ConflictMatch}.php`, `app/Enums/ConflictLevel.php`, `lang/vi/conflicts.php`, `tests/Feature/Actions/RunConflictCheckTest.php`

**Thuật toán SPEC §6.10** — chuẩn hoá đầu vào bằng `Normalizer` (đã có từ M1), rồi tìm trong toàn bộ `matter_parties` theo ba mức ưu tiên: trùng `id_number_hash` (chắc chắn), trùng `phone_normalized` (rất khả nghi), trùng `name_normalized` (cần người xem xét — **việc mang sang từ M1, chưa cài**).

Phân mức:
- **Đỏ (chặn)**: bên trùng đang là khách hàng của văn phòng ở một vụ khác **và** trong vụ mới họ ở vai đối lập với khách hàng mới. "Đối lập" = một bên `plaintiff` còn bên kia `defendant`, hoặc ngược lại.
- **Vàng (cảnh báo)**: từng xuất hiện ở hồ sơ khác với bất kỳ vai nào.
- **Xanh**: không tìm thấy.

**Ràng buộc lộ thông tin (SPEC §6.10 cuối, việc mang sang từ M2):** `ConflictMatch` chỉ được mang `matter_code`, `matter_type_name`, `party_role`, `party_name` và mức khớp. **Không** mang tiêu đề, nội dung hay id tài liệu. Đây là ngoại lệ có chủ đích của phân quyền: đủ để nhận ra xung đột, không đủ để lộ bí mật hồ sơ khác. Vì vậy Action truy vấn **không** qua `listableBy`.

Mỗi lần chạy ghi activity log kèm danh sách khớp, kể cả khi xanh.

- [ ] Test đỏ đầy đủ bốn mục SPEC §11 "Xung đột lợi ích" cộng: khớp theo tên đã chuẩn hoá cho ra mức vàng; kết quả không chứa tiêu đề vụ việc nào; chạy trên vụ việc mà người dùng không có quyền vẫn trả mã hồ sơ.
- [ ] Cài đặt, test xanh, pint, commit `feat: RunConflictCheck per SPEC §6.10 with hash, phone and name matching`.

---

### Task 8: `TransitionMatterStage` và dòng cập nhật không đổi giai đoạn

**Files:** `app/Actions/TransitionMatterStage.php`, `app/Exceptions/InvalidStageTransition.php`, `app/Events/StageLogPublished.php`, `lang/vi/actions.php`, `tests/Feature/Actions/TransitionMatterStageTest.php`

Bảy bước SPEC §6.2 nguyên văn, trong một transaction. Điểm phải đúng:
- `to_stage` bằng giai đoạn hiện tại là hợp lệ và **không** cần nằm trong `allowed_next` (SPEC §6.3).
- `admin` bỏ qua được kiểm tra `allowed_next` nhưng activity log phải ghi là đã bỏ qua.
- `publish = true` đòi `public_content` ≥ 30 ký tự, ném lỗi xác thực.
- `expected_next_update_at` để trống thì tự tính `now() + default_next_update_days` của giai đoạn **mới**.
- Chỉ cập nhật `last_client_update_at` và dispatch `StageLogPublished` khi `publish` **và** `is_published_to_portal`.

- [ ] Test đỏ: chuyển sai `allowed_next` → `InvalidStageTransition`; admin bỏ qua được và log ghi lại; `public_content` 29 ký tự → lỗi; dòng cập nhật không đổi giai đoạn tạo `StageLog` có `from_stage == to_stage == stage hiện tại`; event chỉ dispatch khi đủ hai điều kiện (`Event::fake`).
- [ ] Cài đặt, test xanh, pint, commit `feat: TransitionMatterStage covering stage changes and plain updates`.

---

### Task 9: Form chuyển giai đoạn trên giao diện

**Files:** `app/Filament/Admin/Resources/Matters/Actions/{TransitionStageAction,AddUpdateAction}.php`, `resources/views/filament/client-preview.blade.php`, `tests/Feature/Filament/TransitionStageActionTest.php`

Theo SPEC §7.3: chỉ hiện giai đoạn hợp lệ theo `allowed_next`; ngày xảy ra mặc định hôm nay; ô ghi chú nội bộ có nhãn "Chỉ nội bộ, khách không đọc được"; ô công bố gợi ý mẫu từ `matter_type_stages.client_description`; công tắc "Công bố cho khách ngay" **mặc định bật** khi vụ việc đã bật portal; bên dưới form là **bản xem trước đúng như khách sẽ thấy**, cập nhật khi gõ (`live()` + một view Blade).

- [ ] Test đỏ: form chỉ liệt kê giai đoạn hợp lệ; gửi form gọi đúng Action và tạo `StageLog`; trợ lý không mở được action; công tắc công bố mặc định tắt khi vụ chưa bật portal.
- [ ] Cài đặt, test xanh, pint, commit `feat: stage transition form with live client preview`.

---

### Task 10: Resource còn lại, widget, seeder

**Files:** resource `Client`, `ClientUser`, `User`; `app/Filament/Admin/Widgets/{StaleMattersWidget,MattersByStageWidget}.php`; trang xem `ActivityLog`; `database/seeders/MatterSeeder.php`; `tests/Feature/Filament/*`

- Resource `Client`, `ClientUser`, `User` theo SPEC §7.4, mỗi cái `getEloquentQuery()` đúng policy tương ứng.
- Widget SPEC §7.1 mục 1 ("Hồ sơ quá hạn cập nhật", đặt trên cùng) và mục 6 ("Thống kê nhanh" theo giai đoạn). Các widget còn lại thuộc M4/M6 vì cần dữ liệu chưa có.
- Trang chỉ đọc cho activity log, giới hạn bằng quyền `auditLog.view`.
- **Seeder:** thêm một vụ việc `restricted` (việc mang sang từ M2) — nhớ cập nhật các khẳng định đếm trong `DemoDataAuthorizationTest` (hiện là 8 trên 20 cho `luatsu1` và 20 cho kế toán).

- [ ] Test đỏ, cài đặt, test xanh, pint, commit `feat: supporting resources, dashboard widgets, restricted demo matter`.

---

### Task 11: Kiểm tra tay và tài liệu

- [ ] `bin/dev artisan migrate:fresh --seed`, mở `/admin` bằng từng vai trò (admin, manager, luatsu1, troly1, ketoan) và xác nhận: kế toán thấy danh sách rút gọn và không mở được vụ việc; luật sư chỉ thấy vụ của mình; mở được trang chi tiết, chuyển được giai đoạn, thấy bản xem trước.
- [ ] Mở `/portal` bằng `khach1@example.com` xác nhận không vỡ.
- [ ] Cập nhật `docs/PROGRESS.md` dòng M3 và mục "Ghi chú M3" (mọi phán quyết và việc hoãn sang M4–M8), `README.md` nếu tài khoản demo đổi, mục 9 của tài liệu bộ công cụ.
- [ ] `bin/dev test`, `bin/dev pint --test`, commit `docs: M3 complete — admin panel, stage transitions, conflict check`.

---

## Tự rà soát kế hoạch

**Độ phủ SPEC §13 dòng M3:** resource Client (Task 10), MatterType (Task 4), Matter với ba tab (Task 3, 6), `TransitionMatterStage` (Task 8, 9), `RunConflictCheck` (Task 7). Tiêu chí "tạo được vụ việc và chuyển giai đoạn end-to-end" → Task 5 + 9 + kiểm tra tay Task 11. "Test phần xung đột lợi ích xanh" → Task 7.

**Độ phủ §7:** 7.1 mục 1 và 6 ở Task 10 (mục 2–5, 7 cần dữ liệu của M4/M6); 7.2 bảng ở Task 3, ba tab ở Task 6; 7.3 ở Task 9; 7.4 ở Task 4 và 10.

**Nhất quán tên gọi:** `RunConflictCheck::handle` trả `ConflictCheckResult` dùng ở Task 5, 6, 7. `Matter::isListableBy` (Task 3) dùng ở `MatterPolicy` và mọi bảng. `ScopesToVisibleMatters` (Task 3) dùng ở Task 6, 9, 10. `Audit::record` (Task 1) dùng ở Task 5, 7, 8.

**Rủi ro đã lường trước:** Filament 5 API khác các bản trước và tôi không viết sẵn mã Filament trong kế hoạch — người cài đặt phải lấy khung từ `make:filament-resource` và xác minh trên bản đang cài; nếu một tính năng (ví dụ ẩn cột theo vai trò) không có API trực tiếp thì báo lại thay vì tự chế. Test Livewire cần `pest-plugin-livewire` (Task 1). Seeder đổi ở Task 10 sẽ làm hỏng hai khẳng định đếm trong `DemoDataAuthorizationTest` — đó là dự kiến, phải cập nhật cùng lúc.

**Cố ý để lại:** danh mục hồ sơ và tài liệu trên trang chi tiết (M4); mốc thời hạn, liên lạc, yêu cầu từ khách (M6, M7); các widget cần dữ liệu tài liệu và hạn (M4, M6); `filament-shield` (xét lại chỉ cho giao diện gán vai trò, cấu hình để không sinh lại tên quyền).
