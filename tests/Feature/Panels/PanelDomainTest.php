<?php

use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\PortalPanelProvider;
use Filament\Panel;

it('serves both panels on one domain when no domain env is set', function () {
    config(['vkcrm.admin_domain' => null, 'vkcrm.portal_domain' => null]);

    $admin = (new AdminPanelProvider(app()))->panel(Panel::make());
    $portal = (new PortalPanelProvider(app()))->panel(Panel::make());

    expect($admin->getDomains())->toBe([])
        ->and($portal->getDomains())->toBe([])
        ->and($admin->getPath())->toBe('admin')
        ->and($portal->getPath())->toBe('portal');
});

it('binds each panel to its own domain when env is set', function () {
    config([
        'vkcrm.admin_domain' => 'crm.luatvukhang.test',
        'vkcrm.portal_domain' => 'khachhang.luatvukhang.test',
    ]);

    $admin = (new AdminPanelProvider(app()))->panel(Panel::make());
    $portal = (new PortalPanelProvider(app()))->panel(Panel::make());

    expect($admin->getDomains())->toBe(['crm.luatvukhang.test'])
        ->and($portal->getDomains())->toBe(['khachhang.luatvukhang.test']);
});
