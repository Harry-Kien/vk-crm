<?php

use App\Actions\Document\SubmitClientDocument;
use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\ChecklistRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;

/*
|--------------------------------------------------------------------------
| M12 Task 3 — liên kết tải của app nội bộ mở trong CÙNG cửa sổ (Task 1, khảo sát mục 2.10)
|--------------------------------------------------------------------------
|
| Web app standalone không có tab: trên iPhone, một liên kết `target="_blank"` (hay
| `openUrlInNewTab()`) của app nội bộ đã cài đi RA NGOÀI cửa sổ app — trình duyệt trong app hoặc
| Safari — dù URL nằm trong scope `/admin`. Khi đó route bí danh `/admin/documents/{id}/download`
| một mình không cứu được nhân sự (câu hỏi cookie chưa đo của Task 1 quay lại nguyên vẹn). Bỏ tab
| mới không mất gì trên máy tính: response tải là `Content-Disposition: attachment`, trình duyệt
| tải tệp về và giữ nguyên trang (cả hộp duyệt đang mở).
|
| Hai chỗ, cả hai đi qua MÀN HÌNH THẬT (Livewire): nút "Tải tệp" của tab Tài liệu và danh sách
| "Tệp khách đã gửi" trong hộp duyệt "Đã nhận" của tab Danh mục hồ sơ.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->create(['client_id' => $this->client->id]);
    $this->matter = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lawyer->id]);

    $this->actingAs($this->lawyer, 'web');
});

it('opens the download button of the Documents tab in the same window, on the internal alias route', function () {
    $document = Document::factory()->for($this->matter)->group(DocumentGroup::Authority)->create();
    $document->addMedia(UploadedFile::fake()->createWithContent('nguon.pdf', '%PDF-1.4 noi dung that'))
        ->usingFileName('01k5g7q8wz0000000000000000.pdf')
        ->toMediaCollection('file');
    $document->refresh();

    $this->freezeTime();
    $url = $document->downloadUrlFor($this->lawyer);

    $this->livewire(DocumentsRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
        ->assertActionVisible(TestAction::make('download')->table($document))
        ->assertActionHasUrl(TestAction::make('download')->table($document), $url)
        ->assertActionShouldNotOpenUrlInNewTab(TestAction::make('download')->table($document));

    expect(parse_url($url, PHP_URL_PATH))->toBe("/admin/documents/{$document->id}/download");
});

it('lists the submitted files of the review modal as same-window links on the internal alias route', function () {
    $item = MatterChecklistItem::factory()->for($this->matter)->status(ChecklistItemStatus::Missing)->create();

    $documents = app(SubmitClientDocument::class)->handle(
        checklistItem: $item,
        actor: $this->clientUser,
        files: [
            UploadedFile::fake()->createWithContent('cccd-mat-truoc.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n"),
            UploadedFile::fake()->createWithContent('cccd-mat-sau.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n"),
        ],
    );

    // Nội dung hộp duyệt đi về trình duyệt dưới dạng "partial" `action-modals` của Livewire, không
    // nằm trong `->html()` của component — đọc đúng phần đó (`getMountedActionModalHtml()` của
    // Filament), tức HTML mà người duyệt thấy trong hộp.
    $html = $this->livewire(ChecklistRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
        ->mountAction(TestAction::make('accept')->table($item->fresh()))
        ->assertMountedActionModalSee(__('checklist.tab.fields.documents_label'))
        ->getMountedActionModalHtml();

    preg_match_all('/<a\b[^>]*\/documents\/\d+\/download[^>]*>/', $html, $links);

    expect($links[0])->toHaveCount(count($documents));

    foreach ($links[0] as $link) {
        expect($link)->not->toContain('target=')
            ->and($link)->toMatch('#href="[^"]*/admin/documents/\d+/download\?#');
    }

    foreach ($documents as $document) {
        expect($html)->toContain("/admin/documents/{$document->getKey()}/download");
    }
});
