<?php

use App\Support\Backup\RcloneArchives;
use Spatie\Backup\Tasks\Backup\BackupJob;

/*
|--------------------------------------------------------------------------
| §10.8 — quy ước archive trên đích rclone (fix I3, lượt rà soát cuối M8a)
|--------------------------------------------------------------------------
*/

it('§10.8 ghim định dạng tên archive của gói — nâng cấp gói đổi định dạng thì phải sửa RcloneArchives', function () {
    expect(BackupJob::FILENAME_FORMAT)->toBe('Y-m-d-H-i-s.\z\i\p');
});

it('§10.8 mỗi môi trường một thư mục {remote}/{slug(backup.name)}', function () {
    expect(RcloneArchives::folder('gdrive:VK-CRM-backups', 'VK-CRM'))->toBe('gdrive:VK-CRM-backups/vk-crm')
        ->and(RcloneArchives::folder('gdrive:VK-CRM-backups/', 'VK-CRM Staging'))->toBe('gdrive:VK-CRM-backups/vk-crm-staging');
});

it('§10.8 chỉ khớp ĐÚNG {prefix}Y-m-d-H-i-s.zip', function () {
    expect(RcloneArchives::matches('vk-crm-2026-09-27-02-00-03.zip', 'vk-crm-'))->toBeTrue()
        ->and(RcloneArchives::matches('vk-crm-staging-2026-09-27-02-00-03.zip', 'vk-crm-'))->toBeFalse()
        ->and(RcloneArchives::matches('vk-crm-2026-09-27-02-00-03.zip.part', 'vk-crm-'))->toBeFalse()
        ->and(RcloneArchives::matches('vk-crm-ban-chep-tay.zip', 'vk-crm-'))->toBeFalse()
        ->and(RcloneArchives::matches('x-vk-crm-2026-09-27-02-00-03.zip', 'vk-crm-'))->toBeFalse();
});

it('§10.8 đọc lại mốc thời gian trong tên theo APP_TIMEZONE', function () {
    $createdAt = RcloneArchives::createdAt('vk-crm-2026-09-27-02-00-03.zip', 'vk-crm-');

    expect($createdAt->format('Y-m-d H:i:s'))->toBe('2026-09-27 02:00:03')
        ->and($createdAt->getTimezone()->getName())->toBe(config('app.timezone'));
});
