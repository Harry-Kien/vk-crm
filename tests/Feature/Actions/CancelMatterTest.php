<?php

use App\Actions\Matter\CancelMatter;
use App\Actions\RunConflictCheck;
use App\Enums\ConflictLevel;
use App\Enums\InstalmentStatus;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use App\Support\Billing\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Finder\SplFileInfo;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** "Huỷ hồ sơ mở nhầm" — admin, xoá mềm kèm lý do bắt buộc, audit trong transaction. */
it('lets an admin cancel a wrongly opened matter with a reason, and records it on the audit trail', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create();

    $cancelled = app(CancelMatter::class)->handle($matter, $admin, 'Mở nhầm khách hàng, đã mở lại hồ sơ đúng.');

    expect($cancelled->trashed())->toBeTrue()
        ->and(Matter::withTrashed()->find($matter->id)->trashed())->toBeTrue();

    $activity = Activity::query()->where('event', 'matter_cancelled')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer?->is($admin))->toBeTrue()
        ->and($activity->properties->get('reason'))->toBe('Mở nhầm khách hàng, đã mở lại hồ sơ đúng.');
});

/** Cổng thật: chỉ admin — một trưởng phòng, dù có matter.update, không huỷ được. */
it('refuses a manager, who is not an admin', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $matter = Matter::factory()->create();

    expect(fn () => app(CancelMatter::class)->handle($matter, $manager, 'Lý do bất kỳ'))
        ->toThrow(AuthorizationException::class);

    expect($matter->fresh()->trashed())->toBeFalse();
});

/** Lý do là bắt buộc — không huỷ được với một chuỗi rỗng hoặc chỉ có khoảng trắng. */
it('refuses to cancel without a reason', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create();

    expect(fn () => app(CancelMatter::class)->handle($matter, $admin, '   '))
        ->toThrow(ValidationException::class);

    expect($matter->fresh()->trashed())->toBeFalse();
});

/** Cặp dương của test trên: một lý do thật, dù ngắn, vẫn đi qua được. */
it('accepts a short but real reason', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create();

    app(CancelMatter::class)->handle($matter, $admin, 'Trùng hồ sơ');

    expect($matter->fresh()->trashed())->toBeTrue();
});

/** Không huỷ được hai lần — vụ đã huỷ thì "chưa xong" không còn nghĩa gì để huỷ lại. */
it('refuses to cancel a matter that has already been cancelled', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create();
    $matter->delete();

    expect(fn () => app(CancelMatter::class)->handle($matter, $admin, 'Lý do thứ hai'))
        ->toThrow(ValidationException::class);
});

// -----------------------------------------------------------------------------------------
// Gộp M9 (xung đột 1): hồ sơ còn dư nợ trên hợp đồng đang có hiệu lực không huỷ được
// -----------------------------------------------------------------------------------------
//
// Hook `Matter::deleting` của M9 ném `MatterHasOutstandingBalance` (một DomainException) — một
// lỗi 500 trên hộp thoại "Huỷ hồ sơ", vì `EditMatter::cancelMatter` chỉ bắt ValidationException.
// CancelMatter tự hỏi `BillingSummary::outstandingForMatter()` (cùng MỘT định nghĩa dư nợ với
// hook) và từ chối trên ô `reason`, bằng tiếng Việt, trước khi ghi nhật ký hay xoá mềm.

/** Hồ sơ còn một đợt chưa thu trên hợp đồng active, cân bằng tổng (constraint (b) của M9). */
function matterWithOutstandingInstalment(InstalmentStatus $status = InstalmentStatus::Pending): Matter
{
    $matter = Matter::factory()->create();
    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => 10_000_000]);

    Instalment::factory()->for($contract)->create(array_merge(
        ['amount' => 10_000_000, 'status' => $status],
        $status === InstalmentStatus::Waived
            ? ['waived_reason' => str_repeat('a', 20), 'waived_at' => now()]
            : [],
    ));

    return $matter;
}

it('refuses to cancel a matter that still carries a balance, on the reason field, in vietnamese', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = matterWithOutstandingInstalment();

    try {
        app(CancelMatter::class)->handle($matter, $admin, 'Mở nhầm khách hàng, đã mở lại hồ sơ đúng.');

        test()->fail('CancelMatter phải từ chối một hồ sơ còn dư nợ.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe([
            'reason' => [__('actions.cancel_matter.outstanding_balance', [
                'code' => $matter->code,
                'amount' => Money::format(10_000_000),
                'count' => 1,
            ])],
        ]);
    }

    // Không xoá mềm, và không một dòng `matter_cancelled` nào nói điều ngược lại.
    expect($matter->fresh()->trashed())->toBeFalse()
        ->and(Activity::query()->where('event', 'matter_cancelled')->exists())->toBeFalse();
});

/** Cặp dương: miễn tường minh đợt còn lại là đường thoát nợ hợp lệ — rồi huỷ được. */
it('cancels a matter whose last instalment has been waived', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = matterWithOutstandingInstalment(InstalmentStatus::Waived);

    app(CancelMatter::class)->handle($matter, $admin, 'Mở nhầm khách hàng, đã mở lại hồ sơ đúng.');

    expect($matter->fresh()->trashed())->toBeTrue();
});

/**
 * Xung đột 4 (thứ tự khoá, phán quyết toàn dự án): các Action của vụ việc mở transaction riêng và
 * khoá hàng `matters` trước — nên chúng KHÔNG được gọi một Action tiền nào bên trong (một Action
 * tiền lồng trong transaction của người khác không được Laravel chạy lại khi thua deadlock, và lần
 * thăm dò của nó chạy trong ảnh chụp của transaction ngoài — báo cáo lượt sửa M9). Đọc dư nợ thì
 * được; gọi `App\Actions\Billing\*` thì không. Bản chạy thật trên MariaDB (đọc query log) ở
 * `BillingLockOrderTest`.
 */
it('keeps every money action out of the matter actions that own their own transaction', function (string $file) {
    $source = (string) file_get_contents(base_path($file));

    expect($source)->not->toContain('App\\Actions\\Billing');
})->with([
    'app/Actions/Matter/CancelMatter.php',
    'app/Actions/Matter/ReassignMatter.php',
    'app/Actions/Matter/UpdateMatterDetails.php',
]);

/**
 * Rà mọi đường xoá mềm một vụ việc (xung đột 1): trong app/ chỉ `CancelMatter` gọi `delete()` lên
 * một vụ việc, và không màn hình nào của vụ việc có nút xoá dựng sẵn của Filament — một nút như
 * vậy sẽ gặp hook `Matter::deleting` (DomainException) mà không ai bắt, tức một lỗi 500 thay cho
 * câu "còn dư nợ". Thêm một nút xoá cho vụ việc thì phải đi qua CancelMatter, hoặc đỏ ở đây.
 */
it('offers no built-in delete, force delete or restore action anywhere on the matter screens', function () {
    // Trang và bảng mà bản ghi LÀ vụ việc — không tính relation manager (nút xoá ở đó xoá hàng con).
    // Chỉ đếm MÃ (`use …;` hoặc `…::make(`), không đếm docblock nhắc tên lớp.
    $offenders = collect(File::allFiles(app_path('Filament/Admin/Resources/Matters')))
        ->reject(fn (SplFileInfo $file): bool => str_starts_with($file->getRelativePathname(), 'RelationManagers'))
        ->filter(fn (SplFileInfo $file): bool => preg_match(
            '/(^use Filament\\\\Actions\\\\(Delete|ForceDelete|Restore)(Bulk)?Action;|\b(Delete|ForceDelete|Restore)(Bulk)?Action::make\()/m',
            (string) file_get_contents($file->getPathname()),
        ) === 1)
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

/**
 * Cách ly cổng vẫn đứng vững sau khi huỷ (SPEC §11): một vụ đã xoá mềm không còn nằm trong
 * ĐƯỜNG TRUY VẤN mà `Matter::applyClientPortalConstraints()` dùng cho cổng khách, kể cả khi vẫn
 * đang `is_published_to_portal = true`. Đây là tầng đầu tiên trong ba tầng cách ly (query,
 * policy, serialize) — không tầng nào được yếu đi vì tầng khác đã che.
 */
it('keeps a cancelled matter off the portal query even while still marked published', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create(['is_published_to_portal' => true]);
    $clientUser = ClientUser::factory()->activated()->create(['client_id' => $matter->client_id]);

    app(CancelMatter::class)->handle($matter, $admin, 'Mở nhầm, huỷ để mở lại đúng.');

    $visible = Matter::query();
    $matter->applyClientPortalConstraints($visible, $clientUser);

    expect($visible->whereKey($matter->getKey())->exists())->toBeFalse();
});

/**
 * Ruling của fix round 1: bên của một vụ ĐÃ HUỶ (qua chính `CancelMatter`, không phải một
 * `->delete()` viết tay) VẪN nằm trong dữ liệu đối chiếu xung đột — một dương tính giả (báo động
 * nhầm về một vụ đã huỷ) an toàn hơn một âm tính giả (bỏ sót một xung đột thật). Đây là bằng
 * chứng cho đúng câu docblock lớp (`CancelMatter.php`) vừa sửa; hành vi bên dưới đã đúng SẴN
 * trước fix round 1 (`RunConflictCheck::query()` tự `withTrashed()`), không phải một điều kiện
 * mới — nên không có mutation probe: Action này không viết thêm một dòng mã nào cho luật này,
 * đúng như phán quyết yêu cầu ("Add no code that removes them").
 */
function conflictCheckPartyStub(
    PartyRole $role,
    string $name,
    ?string $idNumber = null,
    bool $isOurClient = false,
): MatterParty {
    return (new MatterParty([
        'role' => $role,
        'name' => $name,
        'is_our_client' => $isOurClient,
    ]))->identify($idNumber, null);
}

it('keeps a cancelled matters party in conflict-check data instead of a false-clean result', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $existingClient = Client::factory()->create(['id_number' => '055566677788']);
    $matter = Matter::factory()->create();
    MatterParty::factory()->for($matter)->ourClient($existingClient)->create();

    app(CancelMatter::class)->handle($matter, $admin, 'Mở nhầm khách hàng, huỷ để mở lại đúng.');

    $ourNewClient = conflictCheckPartyStub(PartyRole::Plaintiff, 'Khách hàng khác', isOurClient: true);
    $opposingParty = conflictCheckPartyStub(PartyRole::Defendant, 'Bên trùng', '055566677788');

    $result = app(RunConflictCheck::class)->handle(collect([$ourNewClient, $opposingParty]));

    expect($result->level)->toBe(ConflictLevel::Red)
        ->and($result->matches->pluck('matterCode')->all())->toContain($matter->code);
});
