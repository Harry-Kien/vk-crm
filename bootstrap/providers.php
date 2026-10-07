<?php

use App\Providers\AppServiceProvider;
use App\Providers\DocumentStorageServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\PortalPanelProvider;
use App\Providers\WebPushServiceProvider;

return [
    AppServiceProvider::class,
    WebPushServiceProvider::class,
    AdminPanelProvider::class,
    PortalPanelProvider::class,
    DocumentStorageServiceProvider::class,
];
