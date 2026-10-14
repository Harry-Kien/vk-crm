<?php

use App\Enums\ChecklistItemStatus;
use App\Enums\Role;
use App\Filament\Portal\Pages\SubmitDocument;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Làn fa, mục A6 (phía cổng khách) — gửi thêm vào một đầu mục văn phòng ĐÃ NHẬN. Trước bản sửa cổng
 * không nói gì: một người nhà dùng chung tài khoản gửi một ảnh mờ và đầu mục thành "chờ duyệt" trên
 * version mới. Nay cổng nói rõ giấy này văn phòng đã nhận, và chỉ gửi khi khách xác nhận. Hàm toàn cục
 * mang tiền tố `rsa…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('private');
    config(['media-library.prefix' => 'test-'.Str::random(16)]);
    Filament::setCurrentPanel('portal');

    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);
    $this->matter = Matter::factory()->for($this->client)->create([
        'is_published_to_portal' => true,
        'lead_lawyer_id' => User::factory()->withRole(Role::Lawyer)->create()->id,
    ]);
    $this->item = MatterChecklistItem::factory()->for($this->matter)->create([
        'name' => 'Giấy chứng nhận quyền sử dụng đất',
        'status' => ChecklistItemStatus::Accepted,
    ]);
});

function rsaPdf(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('anh-them.pdf', "%PDF-1.4\n%âãÏÓ\n1 0 obj\n<< /Type /Catalog >>\nendobj\n");
}

function rsaPage()
{
    return test()->actingAs(test()->clientUser, 'client')
        ->livewire(SubmitDocument::class, ['record' => test()->matter->getKey(), 'item' => test()->item->getKey()]);
}

it('tells the client the office already has this paper, and sends nothing until they confirm', function () {
    rsaPage()
        ->assertSee(__('document_corrections.portal.already_accepted'))
        ->set('data.file', [rsaPdf()])
        ->call('submit')
        ->assertHasErrors(['item']);

    expect(Document::query()->count())->toBe(0)
        ->and($this->item->fresh()->status)->toBe(ChecklistItemStatus::Accepted);

    rsaPage()
        ->set('confirmResubmission', true)
        ->set('data.file', [rsaPdf()])
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('submitted', true);

    expect(Document::query()->count())->toBe(1)
        ->and($this->item->fresh()->status)->toBe(ChecklistItemStatus::PendingReview);
});

it('asks nothing for an item the office has not accepted', function () {
    $this->item->forceFill(['status' => ChecklistItemStatus::Missing])->save();

    rsaPage()
        ->assertDontSee(__('document_corrections.portal.already_accepted'))
        ->set('data.file', [rsaPdf()])
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('submitted', true);
});
