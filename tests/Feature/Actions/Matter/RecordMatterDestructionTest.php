<?php

use App\Actions\Matter\RecordMatterDestruction;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Exceptions\MatterDestructionNotAllowed;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * M7 Task 6 (R5) — `RecordMatterDestruction`, tầng Action. Màn hình được đo riêng qua Livewire ở
 * `tests/Feature/Filament/RecordMatterDestructionActionTest.php`.
 *
 * Action GHI một quyết định tiêu huỷ đã được người ra và lập biên bản ngoài hệ thống; nó KHÔNG
 * xoá gì. Mọi test thành công dưới đây khẳng định lại rằng vụ, tài liệu, tệp trên đĩa và bản ghi
 * lưu trữ vẫn còn nguyên.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['media-library.prefix' => 'test-'.Str::random(16)]);

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->create([
        'lead_lawyer_id' => $this->lawyer->id,
        'closed_at' => now()->subYears(11)->toDateString(),
    ]);
    $this->archive = MatterArchive::factory()->create([
        'matter_id' => $this->matter->id,
        'archived_by' => $this->lawyer->id,
        'client_access_until' => now()->subYears(11)->addDays(90)->toDateString(),
        'retention_until' => now()->subDay()->toDateString(),
    ]);
});

const RMD_REASON = 'Hết hạn lưu trữ 10 năm theo quy chế, Ban giám đốc đồng ý tiêu huỷ.';

function rmdRecord(object $test, ?User $actor = null, string $reason = RMD_REASON, string $recordNo = 'BB-TH-2026-001', ?Matter $matter = null): MatterArchive
{
    return app(RecordMatterDestruction::class)->handle(
        matterId: ($matter ?? $test->matter)->id,
        actor: $actor ?? $test->admin,
        reason: $reason,
        recordNo: $recordNo,
    );
}

function rmdDocumentWithFile(Matter $matter): Document
{
    $document = Document::factory()->create([
        'matter_id' => $matter->id,
        'group' => DocumentGroup::Issued,
        'status' => DocumentStatus::SignedFiled,
        'title' => 'Bản án phúc thẩm',
    ]);

    $document->addMediaFromString('noi dung ban an')
        ->usingFileName(Str::lower((string) Str::ulid()).'-ban-an.pdf')
        ->toMediaCollection('file');

    return $document->refresh();
}

it('admin ghi quyết định: destroyed_at, người quyết định, lý do và số biên bản, kèm một dòng audit', function () {
    $this->travelTo(now()->startOfSecond());

    $archive = rmdRecord($this, recordNo: '  BB-TH-2026-001  ');

    $fresh = $this->archive->fresh();
    expect($archive->is($fresh))->toBeTrue()
        ->and($fresh->destroyed_at?->equalTo(now()))->toBeTrue()
        ->and($fresh->destroyed_by)->toBe($this->admin->id)
        ->and($fresh->destruction_reason)->toBe(RMD_REASON)
        ->and($fresh->destruction_record_no)->toBe('BB-TH-2026-001');

    $audit = Activity::query()->where('event', 'matter_destruction_recorded')->sole();
    expect($audit->subject_type)->toBe($this->matter->getMorphClass())
        ->and($audit->subject_id)->toBe($this->matter->id)
        ->and($audit->causer_id)->toBe($this->admin->id)
        ->and($audit->properties['destruction_record_no'])->toBe('BB-TH-2026-001')
        ->and($audit->properties['retention_until'])->toBe($fresh->retention_until->toDateString());
});

it('không xoá gì: vụ việc, tài liệu, tệp trên đĩa và bản ghi lưu trữ còn nguyên sau khi ghi', function () {
    $document = rmdDocumentWithFile($this->matter);
    $media = $document->getFirstMedia('file');
    $disk = Storage::disk($media->disk);
    expect($disk->exists($media->getPathRelativeToRoot()))->toBeTrue();

    rmdRecord($this);

    expect(Matter::query()->withoutGlobalScopes()->whereKey($this->matter->id)->whereNull('deleted_at')->exists())->toBeTrue()
        ->and(Document::query()->withoutGlobalScopes()->whereKey($document->id)->whereNull('deleted_at')->exists())->toBeTrue()
        ->and(DB::table('media')->where('id', $media->id)->exists())->toBeTrue()
        ->and($disk->exists($media->getPathRelativeToRoot()))->toBeTrue()
        ->and(MatterArchive::query()->withoutGlobalScopes()->whereKey($this->archive->id)->whereNull('deleted_at')->exists())->toBeTrue();
});

it('admin ghi được cho vụ restricted', function () {
    $restricted = Matter::factory()->restricted()->create([
        'lead_lawyer_id' => $this->lawyer->id,
        'closed_at' => now()->subYears(11)->toDateString(),
    ]);
    $archive = MatterArchive::factory()->create([
        'matter_id' => $restricted->id,
        'archived_by' => $this->lawyer->id,
        'retention_until' => now()->subDay()->toDateString(),
    ]);

    rmdRecord($this, matter: $restricted);

    expect($archive->fresh()->destroyed_at)->not->toBeNull();
});

it('người không phải admin không gọi được Action — kể cả luật sư phụ trách và trưởng phòng', function (Role $role, bool $asLead) {
    $actor = User::factory()->withRole($role)->create();

    if ($asLead) {
        $this->matter->update(['lead_lawyer_id' => $actor->id]);
    }

    expect(fn () => rmdRecord($this, actor: $actor))->toThrow(AuthorizationException::class);

    expect($this->archive->fresh()->destroyed_at)->toBeNull()
        ->and(Activity::query()->where('event', 'matter_destruction_recorded')->exists())->toBeFalse();
})->with([
    'trưởng phòng' => [Role::Manager, false],
    'luật sư phụ trách' => [Role::Lawyer, true],
    'trợ lý' => [Role::Assistant, false],
    'kế toán' => [Role::Accountant, false],
]);

it('đọc lại người thực hiện từ CSDL: admin vừa bị gỡ vai (đối tượng trong tay còn cũ) bị từ chối', function () {
    $stale = User::query()->find($this->admin->id);
    $stale->load('roles');

    // Một tiến trình khác gỡ vai admin (đối tượng `$stale` không hay biết).
    User::query()->find($this->admin->id)->syncRoles([Role::Lawyer->value]);

    expect(fn () => rmdRecord($this, actor: $stale))->toThrow(AuthorizationException::class);
    expect($this->archive->fresh()->destroyed_at)->toBeNull();
});

it('admin vừa bị vô hiệu hoá hoặc xoá (đối tượng trong tay còn cũ) bị từ chối', function (string $how) {
    $stale = User::query()->find($this->admin->id);

    $how === 'deactivated'
        ? User::query()->whereKey($this->admin->id)->update(['is_active' => false])
        : User::query()->find($this->admin->id)->delete();

    expect(fn () => rmdRecord($this, actor: $stale))->toThrow(AuthorizationException::class);
    expect($this->archive->fresh()->destroyed_at)->toBeNull();
})->with(['deactivated', 'deleted']);

it('vụ không tồn tại: từ chối như không có quyền (không phân biệt "không có" với "không được")', function () {
    expect(fn () => app(RecordMatterDestruction::class)->handle(
        matterId: 999999,
        actor: $this->admin,
        reason: RMD_REASON,
        recordNo: 'BB-1',
    ))->toThrow(AuthorizationException::class);
});

/**
 * Nhánh "vụ không tồn tại" không dựa vào việc `Gate` từ chối một ability không có đối tượng để tìm
 * policy. Một `Gate::before` cho admin qua mọi ability (cấu hình rất thường gặp) sẽ để lời gọi đi
 * tiếp với `$matter === null` và thành lỗi 500 thay vì một lời từ chối.
 */
it('vụ không tồn tại vẫn bị từ chối khi có một Gate::before cho admin qua mọi ability', function () {
    Gate::before(fn (User $user): ?bool => $user->hasRole(Role::Admin->value) ? true : null);

    expect(fn () => app(RecordMatterDestruction::class)->handle(
        matterId: 999999,
        actor: $this->admin,
        reason: RMD_REASON,
        recordNo: 'BB-1',
    ))->toThrow(AuthorizationException::class);
});

it('chưa quá hạn lưu trữ: từ chối bằng thông điệp tiếng Việt, kể cả đúng ngày cuối của hạn', function () {
    $this->archive->update(['retention_until' => today()->toDateString()]);

    expect(fn () => rmdRecord($this))->toThrow(
        MatterDestructionNotAllowed::class,
        'Hồ sơ còn trong hạn lưu trữ tới hết ngày '.today()->format('d/m/Y'),
    );

    $this->archive->update(['retention_until' => now()->addYears(3)->toDateString()]);
    expect(fn () => rmdRecord($this))->toThrow(MatterDestructionNotAllowed::class, 'còn trong hạn lưu trữ');

    expect($this->archive->fresh()->destroyed_at)->toBeNull();
});

it('đã ghi rồi: lần ghi thứ hai bị từ chối, giữ nguyên quyết định đầu', function () {
    rmdRecord($this, recordNo: 'BB-1');

    expect(fn () => rmdRecord($this, recordNo: 'BB-2'))->toThrow(
        MatterDestructionNotAllowed::class,
        'Quyết định tiêu huỷ của hồ sơ này đã được ghi',
    );

    expect($this->archive->fresh()->destruction_record_no)->toBe('BB-1')
        ->and(Activity::query()->where('event', 'matter_destruction_recorded')->count())->toBe(1);
});

it('vụ chưa có bản ghi lưu trữ (chưa từng kết thúc): từ chối', function () {
    $open = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);

    expect(fn () => rmdRecord($this, matter: $open))->toThrow(
        MatterDestructionNotAllowed::class,
        'chưa có hồ sơ lưu trữ',
    );
});

it('bản ghi lưu trữ đã xoá mềm: coi như không có', function () {
    $this->archive->delete();

    expect(fn () => rmdRecord($this))->toThrow(MatterDestructionNotAllowed::class, 'chưa có hồ sơ lưu trữ');
});

it('vụ đã được mở lại (closed_at rỗng): từ chối — hồ sơ đang xử lý không phải hồ sơ chờ tiêu huỷ', function () {
    $this->matter->update(['closed_at' => null]);

    expect(fn () => rmdRecord($this))->toThrow(MatterDestructionNotAllowed::class, 'đang được xử lý');
    expect($this->archive->fresh()->destroyed_at)->toBeNull();
});

it('vụ đã bị xoá mềm: từ chối, yêu cầu khôi phục trước', function () {
    $this->matter->delete();

    expect(fn () => rmdRecord($this))->toThrow(MatterDestructionNotAllowed::class, 'đã bị xoá');
    expect($this->archive->fresh()->destroyed_at)->toBeNull();
});

it('lý do bắt buộc và tối thiểu 20 ký tự (đếm theo ký tự, sau khi bỏ khoảng trắng hai đầu)', function () {
    // 19 ký tự có dấu (nhiều byte) bọc trong khoảng trắng: dài hơn 20 byte nhưng chỉ 19 ký tự.
    $short = '   '.str_repeat('ệ', 19).'   ';

    try {
        rmdRecord($this, reason: $short);
        $this->fail('Lý do 19 ký tự phải bị từ chối.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('destruction_reason');
    }

    $archive = rmdRecord($this, reason: '  '.str_repeat('ệ', 20).'  ');
    expect($archive->destruction_reason)->toBe(str_repeat('ệ', 20));
});

it('lý do quá 5000 ký tự bị từ chối', function () {
    expect(fn () => rmdRecord($this, reason: str_repeat('a', 5001)))->toThrow(ValidationException::class);
    expect($this->archive->fresh()->destroyed_at)->toBeNull();
});

it('số biên bản bắt buộc và không quá 50 ký tự (độ dài cột)', function (string $recordNo) {
    try {
        rmdRecord($this, recordNo: $recordNo);
        $this->fail('Số biên bản không hợp lệ phải bị từ chối.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('destruction_record_no');
    }

    expect($this->archive->fresh()->destroyed_at)->toBeNull();
})->with([
    'rỗng' => [''],
    'chỉ khoảng trắng' => ['    '],
    '51 ký tự' => [str_repeat('Đ', 51)],
]);

it('số biên bản đúng 50 ký tự có dấu được nhận', function () {
    $archive = rmdRecord($this, recordNo: str_repeat('Đ', 50));

    expect($archive->fresh()->destruction_record_no)->toBe(str_repeat('Đ', 50));
});

/**
 * Thứ tự khoá toàn cục: dòng `matters` trước, rồi `matter_archives`, và câu đầu tiên trong
 * transaction là câu đọc có khoá. Chỉ đo được trên MariaDB — SQLite trong bộ nhớ không sinh
 * `for update`.
 */
it('khoá dòng matters trước, rồi matter_archives (MariaDB)', function () {
    $driver = DB::connection()->getDriverName();

    if (! in_array($driver, ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped("Cần MariaDB (lockForUpdate không sinh khoá trên [{$driver}]). Chạy bằng test:mariadb.");
    }

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = strtolower($query->sql);
    });

    rmdRecord($this);

    $locking = array_values(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'for update')));

    expect($statements[0])->toContain('from `matters`')->toContain('for update')
        ->and($locking[0])->toContain('from `matters`')
        ->and($locking[1])->toContain('from `matter_archives`');
});

it('tài khoản cổng của khách không bao giờ qua được ability recordDestruction', function () {
    $account = ClientUser::factory()->activated()->create(['client_id' => $this->matter->client_id]);

    expect(Gate::forUser($account)->allows('recordDestruction', $this->matter))->toBeFalse();
});
