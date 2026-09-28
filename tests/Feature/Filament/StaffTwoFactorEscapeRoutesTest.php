<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Http\Controllers\DocumentDownloadController;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| R2, §10.7 — quét đường tắt của 2FA bắt buộc, cùng lối M5 đã dùng cho
| ClientUser::toggleEmailAuthentication() (Route::has, không cột nào tắt được).
|--------------------------------------------------------------------------
| (a)/(b) — trang hồ sơ không có nút tắt: tests/Feature/Filament/StaffEditProfileTest.php.
| (c) — không lời gọi saveAppAuthenticationSecret(null) nào ngoài ResetStaffTwoFactor: dưới đây.
| (d) — request cập nhật Livewire của người vừa mất 2FA: dưới đây.
| (e) — route NGOÀI hai panel mà guard `web` dùng được: dưới đây.
*/

/**
 * Quét `app/` bằng chuỗi ĐÚNG DẠNG LỜI GỌI (`->saveAppAuthenticationSecret(`), không phải tên
 * phương thức trần — tên trần khớp luôn cả chữ ký hàm định nghĩa nó trên `App\Models\User`
 * (`public function saveAppAuthenticationSecret(...)`), thứ không phải một "lời gọi".
 *
 * @return list<string> đường dẫn tương đối các tệp có gọi (không phải định nghĩa)
 */
function filesCallingSaveAppAuthenticationSecret(): array
{
    $matches = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), RecursiveDirectoryIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $content = (string) file_get_contents($file->getPathname());

        if (str_contains($content, '->saveAppAuthenticationSecret(')) {
            $matches[] = str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    return $matches;
}

/** Cặp đôi cho cột mã khôi phục — cùng lý do, cùng cách quét. */
function filesCallingSaveAppAuthenticationRecoveryCodes(): array
{
    $matches = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), RecursiveDirectoryIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $content = (string) file_get_contents($file->getPathname());

        if (str_contains($content, '->saveAppAuthenticationRecoveryCodes(')) {
            $matches[] = str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    return $matches;
}

it('§10.7 tiền đề của phép quét: tìm được ít nhất một lời gọi thật (không rỗng vô nghĩa)', function () {
    expect(filesCallingSaveAppAuthenticationSecret())->not->toBeEmpty()
        ->and(filesCallingSaveAppAuthenticationRecoveryCodes())->not->toBeEmpty();
});

it('§10.7 không lời gọi saveAppAuthenticationSecret(null)/…RecoveryCodes(null) nào ngoài ResetStaffTwoFactor', function () {
    expect(filesCallingSaveAppAuthenticationSecret())
        ->toBe(['Actions'.DIRECTORY_SEPARATOR.'User'.DIRECTORY_SEPARATOR.'ResetStaffTwoFactor.php']);

    expect(filesCallingSaveAppAuthenticationRecoveryCodes())
        ->toBe(['Actions'.DIRECTORY_SEPARATOR.'User'.DIRECTORY_SEPARATOR.'ResetStaffTwoFactor.php']);
});

/*
|--------------------------------------------------------------------------
| (d) — /livewire/update của một người vừa mất 2FA (đặt lại, hay bị đổi CSDL trực tiếp)
|--------------------------------------------------------------------------
| Cùng khuôn `tests/Feature/Panels/AdminIpAllowlistTest.php` (đọc docblock dài ở đó cho lý do đầy
| đủ về "vacuous test" nếu POST payload rỗng): lấy `wire:snapshot` THẬT từ một trang admin đã
| render, rồi POST snapshot đó tới `Livewire::getUpdateUri()`.
*/

function twoFactorGapSnapshot(string $html, string $component): string
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);

        if ((json_decode($snapshot, true)['memo']['name'] ?? null) === $component) {
            return $snapshot;
        }
    }

    throw new RuntimeException("Không có snapshot Livewire của [{$component}] trong HTML đã render.");
}

function postTwoFactorGapLivewireUpdate(string $snapshot): TestResponse
{
    return test()
        ->withHeaders(['X-Livewire' => '1'])
        ->postJson(Livewire::getUpdateUri(), [
            'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]],
        ]);
}

/**
 * `EnsureMultiFactorAuthenticationIsEnabled` (Filament, đăng ký per-trang qua `isRequired: true`)
 * chỉ chặn LẦN TẢI TRANG ĐẦY ĐỦ theo mặc định — nó KHÔNG có trong danh sách middleware BỀN mặc
 * định của Filament (`FilamentServiceProvider::packageBooted()`). `AdminPanelProvider` đăng ký nó
 * BỀN riêng (`->persistentMiddleware([...])`) để lấp đúng khoảng trống này — xem docblock ở đó
 * cho lý lẽ đầy đủ. Test này đo HÀNH VI, không đo cấu hình: snapshot lấy TRƯỚC khi 2FA bị xoá
 * (trang tải được bình thường lúc đó), xoá secret sau, rồi POST snapshot CŨ — mô phỏng đúng tình
 * huống "tab trình duyệt đang mở sẵn khi admin khác bấm Đặt lại 2FA" mà
 * `App\Actions\User\ResetStaffTwoFactor` docblock nói tới.
 */
it('§10.7 chặn request cập nhật Livewire của người vừa mất secret 2FA (isPersistent)', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->withRole(Role::Admin)->create();
    $other = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($admin, 'web');

    $snapshot = twoFactorGapSnapshot(
        $this->get(UserResource::getUrl('edit', ['record' => $other], panel: 'admin'))->assertOk()->getContent(),
        EditUser::class,
    );

    $admin->forceFill(['two_factor_secret' => null])->save();
    $this->actingAs($admin->fresh(), 'web');

    $response = postTwoFactorGapLivewireUpdate($snapshot);

    Filament::setCurrentPanel('admin');
    $response->assertRedirect(Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl());

    // Vẫn còn đăng nhập — bị dồn về trang cài đặt, không bị đăng xuất (khác luật §10.9 của
    // client bị vô hiệu hoá, nơi đăng xuất mới là hành vi đúng).
    expect(auth('web')->check())->toBeTrue();
});

/**
 * Cặp dương: CÙNG snapshot thật, CÙNG tài khoản, KHÔNG xoá secret — chứng minh middleware không
 * chỉ luôn luôn chuyển hướng (một cách khác một test có thể xanh giả), mà thật sự phân biệt theo
 * trạng thái 2FA ở đúng request cập nhật Livewire.
 */
it('§10.7 người còn 2FA vẫn cập nhật Livewire bình thường (isPersistent)', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->withRole(Role::Admin)->create();
    $other = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($admin, 'web');

    $snapshot = twoFactorGapSnapshot(
        $this->get(UserResource::getUrl('edit', ['record' => $other], panel: 'admin'))->assertOk()->getContent(),
        EditUser::class,
    );

    postTwoFactorGapLivewireUpdate($snapshot)->assertOk();
});

/*
|--------------------------------------------------------------------------
| (e) — route NGOÀI hai panel mà guard `web` dùng được
|--------------------------------------------------------------------------
| Router thật chỉ có BỐN route như vậy (đo dưới, không phải liệt kê bằng trí nhớ):
| `documents.download`, `livewire.upload-file`, `livewire.preview-file`,
| `default-livewire.update` (route cập nhật Livewire — đã đo riêng ở mục (d) trên, liệt kê lại ở
| đây cho đủ danh sách). Filament middleware của TỪNG TRANG (bao gồm
| `EnsureMultiFactorAuthenticationIsEnabled`) không với tới route ngoài panel — ba route đầu PHẢI
| tự đứng vững bằng lý lẽ riêng, không phải bằng middleware 2FA.
*/

it('§10.7 danh sách đầy đủ route ngoài hai panel mà guard web/không guard nào dùng được', function () {
    $names = collect(Route::getRoutes())
        ->map(fn ($route) => $route->getName())
        ->filter()
        ->filter(fn (string $name): bool => ! str_starts_with($name, 'filament.'))
        ->reject(fn (string $name): bool => str_starts_with($name, 'livewire.') && str_ends_with($name, '.js'))
        ->reject(fn (string $name): bool => in_array($name, ['generated::assets.', 'storage.local'], true))
        ->values()
        ->all();

    // Tên bất ổn định của bộ nhớ đệm asset (`generated::assets.{hash}`) và các tên khác không
    // phải route thật bị loại ở trên. Còn lại đúng bốn cái — nếu ai thêm một route mới ngoài
    // panel, test này đỏ và buộc phải tới đây thêm lý lẽ, không lặng lẽ trôi qua.
    expect($names)->toEqualCanonicalizing([
        'documents.download',
        'livewire.upload-file',
        'livewire.preview-file',
        'default-livewire.update',
    ]);
});

/**
 * `documents.download` — CÙNG lý lẽ Task 1 đã ghi cho `RestrictAdminIpAllowlist` (không phủ route
 * này): URL sống 5 phút, ký bởi `Document::downloadUrlFor()`, và chữ ký khoá cứng người nhận
 * (`recipient`) — controller đòi khớp người đang đăng nhập. Một admin CHƯA cài 2FA không tự sinh
 * được một chữ ký cho chính mình (mọi trang panel sinh liên kết tải đều bị chặn trước — xem test
 * "chặn request cập nhật Livewire" ở trên), nên không có chữ ký hợp lệ nào để mà dùng. Ghim CẤU
 * HÌNH (middleware của route), không dựng cả một lượt tải tài liệu thật — cùng cách
 * `AdminIpAllowlistTest` đã làm cho đúng route này.
 */
it('§10.7 documents.download đòi chữ ký gắn với người nhận, không đòi 2FA', function () {
    $middleware = Route::getRoutes()->getByName('documents.download')?->gatherMiddleware() ?? [];

    expect($middleware)->toContain('signed')
        ->and(class_exists(DocumentDownloadController::class))->toBeTrue();
});

/**
 * `livewire.upload-file`/`livewire.preview-file` — middleware của Livewire, KHÔNG có `auth` nào
 * (đọc `route:list -v`: chỉ `web` + `throttle`). Một người 2FA-chưa-cài không có gì MỚI để lấy ở
 * đây so với một khách vãng lai chưa đăng nhập — cả hai đều gọi được, cả hai đều chỉ chạm tới một
 * tệp TẠM chưa gắn vào bất cứ bản ghi nào (phải qua một component Livewire cụ thể mới gắn được,
 * và mọi trang panel admin đã bị chặn trước đó). Không có 2FA nào để mà thiếu ở đây — route này
 * không phân biệt được ai đang gọi.
 */
it('§10.7 livewire.upload-file/preview-file không đòi xác thực nào — 2FA không phải thứ thiếu ở đây', function () {
    $uploadMiddleware = Route::getRoutes()->getByName('livewire.upload-file')?->gatherMiddleware() ?? [];
    $previewMiddleware = Route::getRoutes()->getByName('livewire.preview-file')?->gatherMiddleware() ?? [];

    foreach ([$uploadMiddleware, $previewMiddleware] as $middleware) {
        expect($middleware)->not->toContain('auth')
            ->and(collect($middleware)->contains(fn (string $m): bool => str_starts_with($m, 'auth:')))->toBeFalse();
    }
});
