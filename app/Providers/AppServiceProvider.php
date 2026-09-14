<?php

namespace App\Providers;

use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
        ]);
    }
}
