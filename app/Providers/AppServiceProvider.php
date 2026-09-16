<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\StageLog;
use App\Models\User;
use App\Support\Files\ClamAvScanner;
use App\Support\Files\NullScanner;
use App\Support\Files\VirusScanner;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // SPEC §6.6 bước 5: implementation chọn theo `CLAMAV_ENABLED`, không sửa Action nào để
        // đổi. Đây là MỘT nơi duy nhất quyết định — `UploadStaffDocument`/`SubmitClientDocument`
        // (Task 3/4) chỉ khai báo phụ thuộc vào interface `VirusScanner`.
        $this->app->bind(VirusScanner::class, fn () => config('vkcrm.clamav.enabled')
            ? new ClamAvScanner(config('vkcrm.clamav.socket'))
            : new NullScanner);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'client_user' => ClientUser::class,
            'stage_log' => StageLog::class,
            'document' => Document::class,
            'matter' => Matter::class,
            'deadline' => Deadline::class,
            'client_request' => ClientRequest::class,
            'client' => Client::class,
            'matter_party' => MatterParty::class,
        ]);

        // Câu trả lời cho "virus scanning có thật sự bật không" phải lấy được từ chính hệ thống,
        // không phải từ việc đọc `.env` hay mã nguồn — `php artisan about` là chỗ một người vận
        // hành đã quen tra cứu tình trạng cấu hình của ứng dụng.
        AboutCommand::add('VK-CRM', fn () => [
            'Quét virus khi nộp tệp (VirusScanner)' => $this->app->make(VirusScanner::class)->isActive()
                ? 'BẬT — ClamAvScanner'
                : 'TẮT — NullScanner (không quét gì cả)',
        ]);
    }
}
