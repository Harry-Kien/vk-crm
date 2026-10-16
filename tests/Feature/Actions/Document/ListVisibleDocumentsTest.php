<?php

use App\Actions\Document\ListVisibleDocuments;
use App\Enums\DocumentGroup;
use App\Enums\Role;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| Làn tl — truy vấn gốc của trang "Tài liệu", ở hai chỗ màn hình không tới được
|--------------------------------------------------------------------------
|
| Hành vi MÀN HÌNH (ma trận vai, tìm, lọc, tải) đo ở `tests/Feature/Filament/DocumentLibraryPageTest.php`.
| Ở đây chỉ hai điều kiện mà panel không bao giờ đưa tới: (1) người không có `matter.view` (cổng của
| trang chặn họ trước) và (2) một phiên cổng khách mở song song khi guard `web` chưa xác thực (trong
| panel admin guard `web` luôn đã xác thực).
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('không trả tài liệu nào cho người không có matter.view, kể cả kế toán có matter.viewAny', function () {
    Document::factory()->for(Matter::factory()->create())->create();

    $accountant = User::factory()->withRole(Role::Accountant)->create();

    expect(app(ListVisibleDocuments::class)->query($accountant)->count())->toBe(0);

    // Cặp dương: cùng dữ liệu, một trưởng phòng (`matter.viewAny` + `matter.view`) thấy tài liệu đó.
    expect(app(ListVisibleDocuments::class)->query(User::factory()->withRole(Role::Manager)->create())->count())->toBe(1);
});

it('câu trả lời về nhân sự không đổi theo một phiên cổng khách đang mở song song', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();

    $portalMatter = Matter::factory()->create(['is_published_to_portal' => true]);
    $portalClient = ClientUser::factory()->for($portalMatter->client)->create(['activated_at' => now()]);
    $ofPortalClient = Document::factory()->for($portalMatter)->group(DocumentGroup::Issued)->create(['title' => 'Của khách đang mở cổng']);

    $otherMatter = Matter::factory()->create();
    $ofOtherClient = Document::factory()->for($otherMatter)->group(DocumentGroup::Issued)->create(['title' => 'Của khách khác']);

    $destroyedMatter = Matter::factory()->create(['closed_at' => now()->subYears(11)]);
    MatterArchive::factory()->create(['matter_id' => $destroyedMatter->id, 'destroyed_at' => now()->subDay()]);
    $onDestroyed = Document::factory()->for($destroyedMatter)->group(DocumentGroup::Issued)->create(['title' => 'Của vụ đã tiêu huỷ']);

    $withoutSession = app(ListVisibleDocuments::class)->query($manager)->pluck('id')->sort()->values()->all();

    $this->actingAs($portalClient, 'client');

    $withSession = app(ListVisibleDocuments::class)->query($manager)->pluck('id')->sort()->values()->all();

    expect($withoutSession)->toBe(collect([$ofPortalClient->id, $ofOtherClient->id])->sort()->values()->all())
        ->and($withSession)->toBe($withoutSession)
        ->and($withSession)->not->toContain($onDestroyed->id);
});
