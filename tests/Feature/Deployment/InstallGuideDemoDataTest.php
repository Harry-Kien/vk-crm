<?php

use App\Enums\UserPosition;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| `docs/CAI-DAT.md`, Bước 5 — dữ liệu mẫu trên máy chủ thật, và đường từ demo sang dùng thật
|--------------------------------------------------------------------------
|
| M8b Task 7, vòng sửa 1 (finding I1). Bước 5 cho chạy `db:seed --class=DemoDataSeeder --force`
| trên máy chủ thật "để demo cho khách trước khi dùng thật". Ngoài `local`/`testing` các tài khoản
| nhân sự demo ra đời với mật khẩu `password` và KHÔNG có secret 2FA, nên `/admin` bắt cài 2FA ở
| lần đăng nhập đầu — ai đăng nhập trước thì app xác thực của người đó được gắn vào tài khoản
| (trust-on-first-use). Trên một tên miền mở ra Internet, đó là chiếm trọn
| `admin@luatvukhang.com`. Và không có đường ra: `vkcrm:create-admin` (Bước 6) từ chối khi đã có
| quản trị viên, nên người vận hành đi tiếp trên CSDL demo.
|
| Hai test dưới đây đọc CHÍNH tài liệu:
|
|  - danh sách tài khoản demo không chép tay ở đây: chạy `DemoDataSeeder` ở `production` rồi đòi
|    tài liệu nêu đích danh từng email nó tạo — seeder thêm một nhân sự demo mà tài liệu không nói
|    thì test đỏ;
|  - chuỗi "hết demo, chuyển sang dùng thật" CHẠY THẬT, từng dòng đúng như tài liệu viết, trên một
|    CSDL đã seed demo ở `production`; kết quả phải giống hệt một máy chủ chưa từng demo đã đi
|    `migrate --force`, `db:seed --force`, `vkcrm:create-admin` — so số dòng của MỌI bảng, nên một
|    bảng mới của milestone sau (hợp đồng, tiếp nhận…) cũng tự được đòi xoá sạch.
|
| `migrate:fresh` không chạy được trên kết nối mà `RefreshDatabase` đang giữ transaction (SQLite
| `:memory:` cần `VACUUM`, MariaDB tự commit khi gặp DDL), nên test thứ hai đổi kết nối MẶC ĐỊNH
| sang một tệp SQLite tạm của riêng nó, và trả lại kết nối cũ trong `finally` — trước khi
| `RefreshDatabase` rollback ở cuối test.
*/

/** Đoạn `### Bước 5 …` của `docs/CAI-DAT.md`, tới trước `### Bước 6`. */
function installGuideStep5(): string
{
    $guide = (string) file_get_contents(base_path('docs/CAI-DAT.md'));
    $start = strpos($guide, "\n### Bước 5");
    $end = $start === false ? false : strpos($guide, "\n### Bước 6", $start);

    expect($start)->not->toBeFalse()
        ->and($end)->not->toBeFalse();

    return substr($guide, $start, $end - $start);
}

/**
 * Khối ```bash đầu tiên của `$section` có chứa `$needle`, đã nối dòng kết thúc bằng `\` và tách
 * theo `&&` hoặc xuống dòng — đúng thứ tự shell sẽ chạy. Không có khối nào thì trả mảng rỗng.
 *
 * @return list<string>
 */
function installGuideShellLines(string $section, string $needle): array
{
    preg_match_all('/```bash\n(.*?)```/s', $section, $blocks);

    foreach ($blocks[1] as $block) {
        if (! str_contains($block, $needle)) {
            continue;
        }

        $joined = (string) preg_replace('/\\\\\n\s*/', ' ', $block);

        return array_values(array_filter(
            array_map('trim', preg_split('/&&|\n/', $joined) ?: []),
            fn (string $line): bool => $line !== '',
        ));
    }

    return [];
}

/** Đổi kết nối mặc định sang một tệp SQLite tạm, rỗng. Trả đường dẫn tệp để xoá sau. */
function installGuideUseScratchDatabase(string $connection): string
{
    $file = (string) tempnam(sys_get_temp_dir(), "vkcrm-{$connection}-");

    config([
        "database.connections.{$connection}" => [
            'driver' => 'sqlite',
            'database' => $file,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
        'database.default' => $connection,
    ]);

    return $file;
}

/**
 * Số dòng của mọi bảng trong kết nối mặc định, theo tên bảng.
 *
 * @return array<string, int>
 */
function installGuideRowCounts(): array
{
    $counts = [];

    foreach (Schema::getTableListing(schemaQualified: false) as $table) {
        $counts[$table] = DB::table($table)->count();
    }

    ksort($counts);

    return $counts;
}

/** `vkcrm:create-admin` trả lời đủ bốn câu hỏi, như người vận hành gõ trong phiên SSH. */
function installGuideCreateAdmin(): void
{
    test()->artisan('vkcrm:create-admin')
        ->expectsQuestion(__('users.create_admin.ask_name'), 'Trần Quản Trị')
        ->expectsQuestion(__('users.create_admin.ask_email'), 'quantri@luatvukhang.com')
        ->expectsQuestion(__('users.create_admin.ask_password'), 'Mat-khau-that-12')
        ->expectsQuestion(__('users.create_admin.ask_password_confirmation'), 'Mat-khau-that-12')
        ->assertSuccessful()
        ->run();
}

/**
 * Chạy MỘT dòng shell của tài liệu: `php artisan <lệnh> [--cờ…]` qua Artisan thật, hoặc
 * `rm -rf storage/app/private/<mẫu glob>` áp đúng mẫu đó lên gốc của đĩa `private` (đĩa giả trong
 * test). Dòng nào khác là test đỏ — tài liệu không được có bước mà test này không kiểm.
 */
function installGuideRunShellLine(string $line): void
{
    if (preg_match('#^rm -rf storage/app/private/(\S+)$#', $line, $rm) === 1) {
        foreach (glob(Storage::disk('private')->path('').$rm[1]) ?: [] as $path) {
            is_dir($path) ? File::deleteDirectory($path) : File::delete($path);
        }

        return;
    }

    expect($line)->toStartWith('php artisan ');

    $tokens = preg_split('/\s+/', trim(substr($line, strlen('php artisan '))));
    $command = array_shift($tokens);
    $parameters = [];

    foreach ($tokens as $token) {
        expect($token)->toStartWith('--');
        [$key, $value] = array_pad(explode('=', $token, 2), 2, true);
        $parameters[$key] = $value;
    }

    if ($command === 'vkcrm:create-admin') {
        expect($parameters)->toBe([]);
        installGuideCreateAdmin();

        return;
    }

    test()->artisan($command, $parameters)->assertSuccessful()->run();
}

it('Bước 5 chỉ đưa dữ liệu mẫu lên máy chủ thật sau ADMIN_IP_ALLOWLIST, nêu đích danh mọi tài khoản demo và nói rõ ai đăng nhập trước là người cài 2FA', function () {
    app()->detectEnvironment(fn () => 'production');

    test()->artisan('db:seed', ['--class' => ReferenceDataSeeder::class, '--force' => true])->assertSuccessful()->run();
    test()->artisan('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true])->assertSuccessful()->run();

    $demoStaff = User::query()->orderBy('id')->get();
    $step5 = installGuideStep5();

    // Tiền đề của cảnh báo: mật khẩu `password`, KHÔNG secret 2FA ngoài local/testing.
    expect($demoStaff)->not->toBeEmpty()
        ->and($demoStaff->where('position', UserPosition::Admin)->pluck('email')->all())->toBe(['admin@luatvukhang.com']);

    foreach ($demoStaff as $user) {
        expect(Hash::check('password', $user->password))->toBeTrue()
            ->and($user->two_factor_secret)->toBeNull()
            ->and($step5)->toContain("`{$user->email}`");
    }

    $allowlist = strpos($step5, 'ADMIN_IP_ALLOWLIST');
    $demoCommand = strpos($step5, 'php artisan db:seed --class=DemoDataSeeder --force');

    expect($allowlist)->not->toBeFalse()
        ->and($demoCommand)->not->toBeFalse()
        ->and($allowlist)->toBeLessThan($demoCommand)
        ->and($step5)->toContain('trust-on-first-use');
});

it('chuỗi "hết demo, chuyển sang dùng thật" của Bước 5, chạy đúng như viết, đưa một CSDL demo về y hệt máy chủ chưa từng demo — cả tệp hồ sơ mẫu', function () {
    $lines = installGuideShellLines(installGuideStep5(), 'migrate:fresh');

    // Thứ tự là một phần của lời hứa: xoá trước, seed tham chiếu, dọn tệp, rồi mới tạo admin.
    expect($lines)->toBe([
        'php artisan migrate:fresh --force',
        'php artisan db:seed --force',
        'rm -rf storage/app/private/[0-9]*',
        'php artisan vkcrm:create-admin',
    ]);

    $originalDefault = config('database.default');
    $files = [];

    try {
        app()->detectEnvironment(fn () => 'production');

        // Mốc so sánh: một máy chủ chưa từng demo (Bước 5 + Bước 6 không có dữ liệu mẫu).
        $files[] = installGuideUseScratchDatabase('install_guide_clean');
        test()->artisan('migrate', ['--force' => true])->assertSuccessful()->run();
        test()->artisan('db:seed', ['--force' => true])->assertSuccessful()->run();
        installGuideCreateAdmin();
        $clean = installGuideRowCounts();

        // Máy chủ đã demo: tham chiếu + dữ liệu mẫu, kèm hai tệp mà repo để sẵn trong
        // storage/app/private (`.gitignore`, `.htaccess` — luật chặn của Apache).
        $files[] = installGuideUseScratchDatabase('install_guide_demo');
        test()->artisan('migrate', ['--force' => true])->assertSuccessful()->run();
        test()->artisan('db:seed', ['--force' => true])->assertSuccessful()->run();
        test()->artisan('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true])->assertSuccessful()->run();
        Storage::disk('private')->put('.gitignore', "*\n!.gitignore\n!.htaccess\n");
        Storage::disk('private')->put('.htaccess', "Require all denied\n");

        expect(User::query()->where('email', 'admin@luatvukhang.com')->exists())->toBeTrue()
            ->and(count(Storage::disk('private')->allFiles()))->toBeGreaterThan(2);

        // Cái bẫy tài liệu nói tới: trên CSDL demo, Bước 6 từ chối.
        test()->artisan('vkcrm:create-admin')
            ->expectsOutputToContain(__('users.create_admin.admins_exist', ['count' => 1]))
            ->assertFailed()
            ->run();

        foreach ($lines as $line) {
            installGuideRunShellLine($line);
        }

        expect(installGuideRowCounts())->toBe($clean)
            ->and(User::query()->pluck('email')->all())->toBe(['quantri@luatvukhang.com'])
            ->and(collect(Storage::disk('private')->allFiles())->sort()->values()->all())->toBe(['.gitignore', '.htaccess']);
    } finally {
        config(['database.default' => $originalDefault]);
        DB::purge('install_guide_clean');
        DB::purge('install_guide_demo');

        foreach ($files as $file) {
            File::delete($file);
        }
    }
});
