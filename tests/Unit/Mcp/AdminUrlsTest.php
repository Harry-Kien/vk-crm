<?php

use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\RelationManagers\ChecklistRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\ClientRequestsRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\DeadlinesRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Models\ClientRequest;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\StageLog;
use App\Support\Mcp\AdminUrls;

/*
| M11 bộ tool (Task 9): mỗi kết quả kèm `url` TUYỆT ĐỐI về trang `/admin` tương ứng; trang đó tự
| kiểm quyền. Yêu cầu khách, mốc, tài liệu, danh mục, dòng tiến độ là TAB của trang vụ việc (không có
| resource riêng), chọn bằng `?relation=<vị trí tab>` (Filament 5) — tra ngược vị trí như
| `App\Mail\Staff\InstalmentOverdue`, không gõ tay một số. Không bao giờ là đường tải tệp (R4).
*/

function matterTabUrl(int $matterId, string $relationManager): string
{
    $tab = array_search($relationManager, MatterResource::getRelations(), true);

    expect($tab)->not->toBeFalse();

    return MatterResource::getUrl('view', ['record' => $matterId, 'relation' => $tab], panel: 'admin');
}

it('links a matter to its view page in /admin, as an absolute URL', function () {
    $url = AdminUrls::matter(5);

    expect($url)->toBe(MatterResource::getUrl('view', ['record' => 5], panel: 'admin'))
        ->and($url)->toStartWith(rtrim((string) config('app.url'), '/').'/admin/')
        ->and(AdminUrls::matter((new Matter)->forceFill(['id' => 5])))->toBe($url);
});

it('links each child record to the right tab of its matter', function () {
    expect(AdminUrls::stageLog((new StageLog)->forceFill(['id' => 1, 'matter_id' => 5])))
        ->toBe(matterTabUrl(5, StageLogsRelationManager::class))
        ->and(AdminUrls::checklistItem((new MatterChecklistItem)->forceFill(['id' => 1, 'matter_id' => 5])))
        ->toBe(matterTabUrl(5, ChecklistRelationManager::class))
        ->and(AdminUrls::document((new Document)->forceFill(['id' => 1, 'matter_id' => 5])))
        ->toBe(matterTabUrl(5, DocumentsRelationManager::class))
        ->and(AdminUrls::clientRequest((new ClientRequest)->forceFill(['id' => 1, 'matter_id' => 5])))
        ->toBe(matterTabUrl(5, ClientRequestsRelationManager::class))
        ->and(AdminUrls::deadline((new Deadline)->forceFill(['id' => 1, 'matter_id' => 5])))
        ->toBe(matterTabUrl(5, DeadlinesRelationManager::class));
});

it('never hands out a file download link for a document', function () {
    $url = AdminUrls::document((new Document)->forceFill(['id' => 88, 'matter_id' => 5]));

    expect($url)->not->toContain('download')
        ->and($url)->not->toContain('signature')
        ->and($url)->not->toContain('/documents/');
});

it('refuses a child record that does not carry its matter id', function () {
    AdminUrls::deadline((new Deadline)->forceFill(['id' => 1]));
})->throws(LogicException::class);

it('refuses a tab the matter page does not have, instead of linking to the overview', function () {
    $matterTab = new ReflectionMethod(AdminUrls::class, 'matterTab');

    $matterTab->invoke(null, (new Deadline)->forceFill(['id' => 1, 'matter_id' => 5]), stdClass::class);
})->throws(LogicException::class);
