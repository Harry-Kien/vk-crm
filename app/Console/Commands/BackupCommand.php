<?php

namespace App\Console\Commands;

use App\Actions\Backup\GuardBackupEncryption;
use Spatie\Backup\Commands\BackupCommand as SpatieBackupCommand;

/**
 * Bọc `backup:run` của `spatie/laravel-backup` để chạy {@see GuardBackupEncryption} TRƯỚC khi
 * gói thật sự tạo archive (SPEC §10 mục 8, R3).
 *
 * # Vì sao không nghe sự kiện `CommandStarting`
 *
 * Cách "chuẩn" để chặn một lệnh Artisan trước khi nó chạy là nghe
 * `Illuminate\Console\Events\CommandStarting`. Cách đó KHÔNG dùng được ở đây: đo được bằng
 * `dd(app()->runningUnitTests())` trong `Illuminate\Foundation\Console\Kernel::
 * rerouteSymfonyCommandEvents()` — phương thức đó bọc sự kiện Console gốc của Symfony thành
 * `CommandStarting`, nhưng CHỈ KHI `! $this->app->runningUnitTests()`. `phpunit.xml` ghim
 * `APP_ENV=testing`, nên trong suốt bộ test của dự án, `CommandStarting` KHÔNG BAO GIỜ được
 * phát — một guard móc vào đó sẽ không có test nào thấy nó chạy, và "test cả hai chiều" (SPEC
 * §11, brief Task 1) sẽ không kiểm được gì cả.
 *
 * # Cách móc thật: ghi đè lớp qua container, không qua tên lệnh
 *
 * `Spatie\LaravelPackageTools\Concerns\PackageServiceProvider\ProcessCommands::
 * bootPackageCommands()` gọi `$this->commands([BackupCommand::class, ...])`, và
 * `Illuminate\Console\Application::resolveCommands()` phân giải từng lớp bằng
 * `$this->laravel->make($command)` — TỨC LÀ QUA CONTAINER, không phải `new $command`. Nên
 * `AppServiceProvider::register()` chỉ cần một dòng
 * `$this->app->bind(SpatieBackupCommand::class, self::class)` để MỌI lần ai đó (CLI, lịch ở
 * `routes/console.php`, `Artisan::call()` trong test) gọi `backup:run` đều nhận về THỂ HIỆN CỦA
 * LỚP NÀY thay vì bản gốc — cùng một tên lệnh `backup:run`, cùng chữ ký, chỉ khác `handle()`.
 * Đây là một binding container thường, không phụ thuộc `runningUnitTests()`, nên chạy giống hệt
 * nhau trong test lẫn khi vận hành thật.
 */
class BackupCommand extends SpatieBackupCommand
{
    public function handle(): int
    {
        app(GuardBackupEncryption::class)->handle();

        return parent::handle();
    }
}
