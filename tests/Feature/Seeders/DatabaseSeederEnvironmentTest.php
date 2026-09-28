<?php

use App\Models\ChecklistTemplate;
use App\Models\ClientUser;
use App\Models\MatterType;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Task 19 (rà soát cuối, "Cấu hình không phá dữ liệu đang chạy" — finding "tài khoản demo và dữ
 * liệu mẫu chạy chung với dữ liệu tham chiếu khi cài production"): trước bản vá này,
 * `DatabaseSeeder::run()` gọi thẳng bảy seeder không điều kiện, nên `migrate:fresh --seed` trên
 * MỘT tên miền production tạo đúng `admin@luatvukhang.com`/mật khẩu `password` — tài khoản demo
 * của docs/CAI-DAT.md — trên dữ liệu THẬT.
 *
 * `DemoDataSeederTest.php` (tên cũ, đã có từ trước task này) giữ nguyên phép đo cho môi trường
 * `local`/`testing`: nó seed qua `DatabaseSeeder::class` và mong đủ 8 nhân sự + 21 vụ việc + tài
 * khoản demo — bài kiểm đó vẫn phải xanh sau bản vá này, cùng lúc với các test dưới đây.
 *
 * `$this->seed()` (helper của Laravel) không truyền `--force`, và `db:seed` trên môi trường
 * `production` hỏi xác nhận qua `ConfirmableTrait` — chạy trong test thì đó là một
 * `OutputStyle` giả không có kỳ vọng nào được đặt, nên nó ném lỗi thay vì hỏi. Gọi thẳng
 * `Artisan` với `--force` để bỏ qua đúng bước xác nhận đó, giống hệt cách vận hành thật sẽ chạy
 * (`docs/CAI-DAT.md`: `php artisan db:seed --force`).
 */
function seedForced(string $class): void
{
    test()->artisan('db:seed', ['--class' => $class, '--force' => true])->assertSuccessful();
}

it('never seeds the demo admin account with the password "password" on production, but still seeds reference data', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(app()->environment())->toBe('production');

    seedForced(DatabaseSeeder::class);

    expect(User::count())->toBe(0)
        ->and(ClientUser::count())->toBe(0)
        ->and(User::where('email', 'admin@luatvukhang.com')->exists())->toBeFalse()
        // Dữ liệu THAM CHIẾU vẫn đủ: vai trò, quyền, 6 loại vụ việc kèm giai đoạn, danh mục mẫu.
        ->and(Role::count())->toBeGreaterThan(0)
        ->and(Permission::count())->toBeGreaterThan(0)
        ->and(MatterType::count())->toBe(6)
        ->and(MatterType::query()->get()->every(fn (MatterType $t) => $t->stages()->count() >= 5))->toBeTrue()
        ->and(ChecklistTemplate::count())->toBe(3);
});

/** Vế dương: `local`/`testing` (bộ test, máy dev) vẫn seed đủ dữ liệu mẫu như trước bản vá này. */
it('still seeds demo accounts in the testing environment', function () {
    expect(app()->environment())->toBe('testing');

    $this->seed(DatabaseSeeder::class);

    $admin = User::where('email', 'admin@luatvukhang.com')->first();

    expect($admin)->not->toBeNull()
        ->and(Hash::check('password', $admin->password))->toBeTrue();
});

/**
 * Một văn phòng muốn dữ liệu mẫu trên production (demo cho khách trước khi dùng thật) vẫn gọi
 * được: `--class=DemoDataSeeder` đi thẳng vào lớp đó, không qua `DatabaseSeeder::run()` nên
 * không bị chặn bởi kiểm tra môi trường.
 */
it('still seeds demo accounts on production when the demo seeder class is named explicitly', function () {
    // MatterSeeder nộp tệp thật qua UploadStaffDocument/SubmitClientDocument (GHI RA ĐĨA) —
    // cùng lý do beforeEach() của DemoDataSeederTest.php.
    Storage::fake('private');

    app()->detectEnvironment(fn () => 'production');

    // Dữ liệu tham chiếu phải có trước (MatterSeeder/ClientSeeder cần MatterType/vai trò).
    seedForced(ReferenceDataSeeder::class);

    seedForced(DemoDataSeeder::class);

    expect(User::where('email', 'admin@luatvukhang.com')->exists())->toBeTrue();
});
