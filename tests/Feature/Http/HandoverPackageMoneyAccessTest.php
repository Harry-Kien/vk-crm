<?php

use App\Actions\Billing\CancelContract;
use App\Actions\Matter\BuildHandoverPackage;
use App\Enums\ContractStatus;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\HandoverPackageStatus;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Jobs\SendHandoverPackageReady;
use App\Mail\Staff\HandoverPackageReady;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\Document;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\PdfText;

/**
 * M9 Task 10, rà soát vòng 1 (C1) — gói bàn giao mang "Bảng kê thanh toán" trong `MUC-LUC.pdf`, nên
 * TẢI GÓI LÀ ĐỌC TIỀN của vụ. Nhân sự tải gói của một vụ có hợp đồng đã từng ký phải qua đúng định
 * nghĩa P3 của "ai thấy tiền của vụ nào" (`billing.view` + `Matter::listableBy()`, hỏi qua
 * `ContractPolicy::view`), không chỉ `matter.view`. Trợ lý (SPEC §5: không `billing.view`) trong
 * đội của vụ vẫn tải được mọi tài liệu nhóm B khác và gói của vụ chưa từng có hợp đồng đã ký.
 *
 * Đo trên đúng route ký `documents.download` (tầng HTTP) và nút "Tải" của tab Tài liệu (Livewire),
 * với một gói dựng thật bằng `BuildHandoverPackage` — có test khẳng định bảng kê thật sự nằm trong
 * mục lục của gói, để mọi vế "404" ở dưới nói về một tệp mang tiền chứ không về một tệp rỗng.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Queue::fake();
    config(['media-library.prefix' => 'test-'.Str::random(16)]);

    $this->workRoot = storage_path('framework/testing/handover-'.Str::random(16));
    config(['vkcrm.handover.work_dir' => $this->workRoot]);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();

    $this->matter = Matter::factory()->for(Client::factory())->create([
        'lead_lawyer_id' => $this->lead->id,
        'closed_at' => now()->subDay()->toDateString(),
    ]);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
});

afterEach(fn () => File::deleteDirectory($this->workRoot));

/** Hợp đồng của `$matter` với một đợt và một khoản thu, đưa tới `$status` (bản nháp: giữ nguyên). */
function hpmContract(Matter $matter, ContractStatus $status): Contract
{
    $contract = Contract::factory()->for($matter)->create(['code' => 'HD-HPM-0001', 'total_amount' => 9_000_000]);
    $instalment = Instalment::factory()->for($contract)->create([
        'name' => 'Đợt duy nhất HPMDOT',
        'amount' => 9_000_000,
        'due_date' => now()->subMonth()->toDateString(),
    ]);

    if ($status === ContractStatus::Active) {
        $contract->update(['status' => ContractStatus::Active, 'signed_at' => now()->subMonths(2)->toDateString()]);
    }

    Payment::factory()->for($instalment)->create(['amount' => 4_000_000, 'paid_on' => now()->subWeeks(3)->toDateString()]);

    return $contract->refresh();
}

/** Sinh gói bàn giao thật của `$matter` (cùng đường với job), trả tài liệu gói. */
function hpmPackage(Matter $matter, User $requester): Document
{
    $archive = MatterArchive::factory()->create([
        'matter_id' => $matter->id,
        'archived_by' => $requester->id,
        'handover_status' => HandoverPackageStatus::Generating,
        'handover_requested_at' => now()->startOfSecond(),
        'handover_requested_by' => $requester->id,
    ]);

    $package = app(BuildHandoverPackage::class)->handle($matter->id, $archive->fresh()->handover_requested_at->getTimestamp());

    expect($package)->not->toBeNull();

    return $package->refresh();
}

/** Chữ của `MUC-LUC.pdf` trong zip của gói, ép dẹt để so. */
function hpmIndexText(Document $package): string
{
    $zip = new ZipArchive;
    expect($zip->open($package->getFirstMedia('file')->getPath(), ZipArchive::RDONLY))->toBeTrue();
    $pdf = $zip->getFromName('MUC-LUC.pdf');
    $zip->close();

    return PdfText::squash(PdfText::extract((string) $pdf));
}

function hpmIssuedDocument(Matter $matter): Document
{
    $document = Document::factory()->for($matter)->group(DocumentGroup::Issued)->create([
        'title' => 'Bản án sơ thẩm',
        'status' => DocumentStatus::SignedFiled,
    ]);
    $document->addMediaFromString('ban an')->usingFileName('ban-an.pdf')->toMediaCollection('file');

    return $document->refresh();
}

function hpmDocumentsTab(Matter $matter)
{
    Filament::setCurrentPanel('admin');

    return test()->livewire(DocumentsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ]);
}

it('keeps the handover package, which prints the payment statement, from a team assistant who may not see the money', function () {
    $contract = hpmContract($this->matter, ContractStatus::Active);
    $package = hpmPackage($this->matter, $this->lead);
    $issued = hpmIssuedDocument($this->matter);

    // Tiền đề: gói thật sự mang tiền, và trợ lý thật sự không được thấy tiền đó ở nơi khác.
    expect(hpmIndexText($package))->toContain(PdfText::squash(__('handover.pdf.billing.heading')))
        ->toContain('HD-HPM-0001')
        ->and($this->assistant->can('view', $contract))->toBeFalse()
        ->and($this->lead->can('view', $contract))->toBeTrue();

    $this->actingAs($this->assistant, 'web')
        ->get($package->downloadUrlFor($this->assistant))
        ->assertNotFound();

    // Vế dương 1: luật sư phụ trách (thấy tiền của vụ) tải được chính gói đó.
    $this->actingAs($this->lead, 'web')
        ->get($package->downloadUrlFor($this->lead))
        ->assertOk();

    // Vế dương 2: luật chỉ chạm gói — trợ lý vẫn tải được tài liệu nhóm B khác của cùng vụ.
    $this->actingAs($this->assistant, 'web')
        ->get($issued->downloadUrlFor($this->assistant))
        ->assertOk();
});

it('hides the download button of the package from a team assistant on the documents tab, and only there', function () {
    hpmContract($this->matter, ContractStatus::Active);
    $package = hpmPackage($this->matter, $this->lead);
    $issued = hpmIssuedDocument($this->matter);

    $this->actingAs($this->assistant, 'web');

    hpmDocumentsTab($this->matter)
        ->assertCanSeeTableRecords([$package, $issued])
        ->assertActionHidden(TestAction::make('download')->table($package))
        ->assertActionVisible(TestAction::make('download')->table($issued));

    $this->actingAs($this->lead, 'web');

    hpmDocumentsTab($this->matter)
        ->assertActionVisible(TestAction::make('download')->table($package));
});

it('still lets a team assistant download the package of a matter that never had a signed contract', function (?ContractStatus $status) {
    if ($status !== null) {
        hpmContract($this->matter, $status);
    }

    $package = hpmPackage($this->matter, $this->lead);

    // Tiền đề: gói này không có bảng kê nào để giữ lại.
    expect(hpmIndexText($package))->not->toContain(PdfText::squash(__('handover.pdf.billing.heading')))
        ->toContain(PdfText::squash(__('handover.pdf.timeline_heading')));

    $this->actingAs($this->assistant, 'web')
        ->get($package->downloadUrlFor($this->assistant))
        ->assertOk();
})->with([
    'no contract at all' => [null],
    'only a draft contract' => [ContractStatus::Draft],
]);

it('keeps a package built before the contract was cancelled from the assistant, since that package still prints the statement', function () {
    $contract = hpmContract($this->matter, ContractStatus::Active);
    $package = hpmPackage($this->matter, $this->lead);

    app(CancelContract::class)->handle($this->lead, $contract, 'Khách hàng chấm dứt hợp đồng trước hạn theo thoả thuận hai bên.');

    expect($contract->refresh()->status)->toBe(ContractStatus::Cancelled)
        ->and(hpmIndexText($package))->toContain(PdfText::squash(__('handover.pdf.billing.heading')));

    $this->actingAs($this->assistant, 'web')
        ->get($package->downloadUrlFor($this->assistant))
        ->assertNotFound();

    $this->actingAs($this->lead, 'web')
        ->get($package->downloadUrlFor($this->lead))
        ->assertOk();
});

/*
 * Câu hỏi về NHÂN SỰ hỏi trong lúc một phiên cổng của khách khác đang mở mà guard `web` thì chưa
 * (một job, một Action gọi `Gate::forUser($staff)`): `ClientPortalScope` khi đó cắt truy vấn theo
 * khách của phiên kia. Hỏi hợp đồng QUA scope thì không thấy hợp đồng nào và trợ lý được tải; để
 * `$contract->matter` tự nạp QUA scope thì vụ ra `null` và luật sư phụ trách bị từ chối. Vụ của tài
 * liệu nạp sẵn trước khi phiên mở, để câu hỏi đi được tới phép thử hợp đồng.
 */
it('answers the staff download question the same while an unrelated client portal session is open', function () {
    hpmContract($this->matter, ContractStatus::Active);
    $package = hpmPackage($this->matter, $this->lead)->load('matter');
    $issued = hpmIssuedDocument($this->matter)->load('matter');

    $this->actingAs(ClientUser::factory()->activated()->create(), 'client');

    expect(Gate::forUser($this->assistant)->allows('download', $package))->toBeFalse()
        ->and(Gate::forUser($this->assistant)->allows('download', $issued))->toBeTrue()
        ->and(Gate::forUser($this->lead)->allows('download', $package))->toBeTrue();
});

it('keeps the package of a restricted matter from a former lead whose role was switched to assistant', function () {
    $formerLead = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->restricted()->for(Client::factory())->create([
        'lead_lawyer_id' => $formerLead->id,
        'closed_at' => now()->subDay()->toDateString(),
    ]);
    hpmContract($matter, ContractStatus::Active);
    $package = hpmPackage($matter, $formerLead);
    $issued = hpmIssuedDocument($matter);

    // Vế dương trước khi đổi vai trò: luật sư phụ trách tải được gói của vụ hạn chế của mình.
    $this->actingAs($formerLead, 'web')
        ->get($package->downloadUrlFor($formerLead))
        ->assertOk();

    $formerLead->syncRoles([Role::Assistant->value]);
    $formerLead = User::query()->findOrFail($formerLead->id);

    $this->actingAs($formerLead, 'web')
        ->get($package->downloadUrlFor($formerLead))
        ->assertNotFound();

    // Vẫn còn thấy vụ (matter.view + lead) — nên tài liệu nhóm B khác vẫn tải được.
    $this->actingAs($formerLead, 'web')
        ->get($issued->downloadUrlFor($formerLead))
        ->assertOk();
});

// ---------------------------------------------------------------------------------------------
// Việc sau gộp M9 + M10 (làn fu3, Task 1 mục C — dư r4 của làn m9f): chuông và thư "gói sẵn sàng"
// chỉ tới người MỞ ĐƯỢC và CÔNG BỐ ĐƯỢC chính gói đó. Thư bảo người nhận xem gói, công bố, rồi khách
// sẽ được báo; báo cho người không tải được (404, không có nút "Tải") hay không công bố được
// (thiếu `document.publish`) là để gói nằm đó, không ai làm gì, và khách không bao giờ nhận thư.
// Đi qua ĐÚNG job mà `BuildHandoverPackage` vừa xếp hàng (Queue::fake giữ nó lại).
// ---------------------------------------------------------------------------------------------

/**
 * Chạy job "gói sẵn sàng" mà lần sinh gói vừa rồi đã xếp hàng — đúng đối tượng job thật. Chuông của
 * Filament là một thông báo xếp hàng (`Filament\Notifications\DatabaseNotification` là `ShouldQueue`),
 * nên từ đây chỉ giữ lại chính job này trong hàng đợi giả và để hàng `sync` của bộ test ghi chuông.
 */
function hpmRunReadyJob(): void
{
    $job = Queue::pushed(SendHandoverPackageReady::class)->sole();

    Queue::fake([SendHandoverPackageReady::class]);

    app()->call([$job, 'handle']);
}

/** Số chuông "gói sẵn sàng" của `$user` về đúng gói này (khoá `viewData.handover_document_id`). */
function hpmReadyBells(User $user, Document $package): int
{
    return $user->notifications()->where('data->viewData->handover_document_id', $package->getKey())->count();
}

/**
 * Kịch bản của rà soát gộp M9: vụ hạn chế có hợp đồng đã ký, luật sư phụ trách bị đổi vai thành trợ
 * lý. Họ vẫn qua `MatterPolicy::view` (matter.view + là lead), nên `ResolveStaffRecipients::handle()`
 * trả đúng [họ] và chuỗi dự phòng không bao giờ chạy — dù họ không tải được gói (gói in tiền) và không
 * công bố được. Người nhận phải là admin (người duy nhất ngoài lead thấy vụ hạn chế), không phải quản
 * lý (không thấy vụ hạn chế).
 *
 * Mutation probe: bỏ bộ lọc khả năng khỏi lời gọi `ResolveStaffRecipients::handle()` của
 * `SendHandoverPackageReady` — test này ĐỎ (người cũ nhận chuông và thư, admin không).
 */
it('tells the admin, not the restricted matter lead switched to assistant, that the package is ready', function () {
    Mail::fake();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();
    $formerLead = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->restricted()->for(Client::factory())->create([
        'lead_lawyer_id' => $formerLead->id,
        'closed_at' => now()->subDay()->toDateString(),
    ]);
    hpmContract($matter, ContractStatus::Active);

    $formerLead->syncRoles([Role::Assistant->value]);
    $formerLead = User::query()->findOrFail($formerLead->id);

    $package = hpmPackage($matter, $formerLead);

    // Tiền đề: vẫn xem được vụ, nhưng không mở được gói, không công bố được gói; admin làm được cả hai.
    expect(Gate::forUser($formerLead)->allows('view', $matter))->toBeTrue()
        ->and(Gate::forUser($formerLead)->allows('download', $package))->toBeFalse()
        ->and(Gate::forUser($formerLead)->allows('publish', $package))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('download', $package))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('publish', $package))->toBeTrue();

    hpmRunReadyJob();

    expect(hpmReadyBells($formerLead, $package))->toBe(0)
        ->and(hpmReadyBells($manager, $package))->toBe(0)
        ->and(hpmReadyBells($admin, $package))->toBe(1);

    Mail::assertSent(HandoverPackageReady::class, 1);
    Mail::assertSent(HandoverPackageReady::class, fn (HandoverPackageReady $mail): bool => $mail->hasTo($admin->email));
});

/**
 * Vế dương: ca thường không đổi — luật sư phụ trách (tải được, công bố được gói in tiền) và quản lý đã
 * bấm sinh gói đều được báo; trợ lý trong đội (không thấy tiền, không công bố) không có mặt trong danh
 * sách ưu tiên nên vốn không được báo.
 */
it('still tells the lead and the manager who asked for it when both can open and publish the package', function () {
    Mail::fake();
    $manager = User::factory()->withRole(Role::Manager)->create();
    hpmContract($this->matter, ContractStatus::Active);
    $package = hpmPackage($this->matter, $manager);

    hpmRunReadyJob();

    expect(hpmReadyBells($this->lead, $package))->toBe(1)
        ->and(hpmReadyBells($manager, $package))->toBe(1)
        ->and(hpmReadyBells($this->assistant, $package))->toBe(0);

    Mail::assertSent(HandoverPackageReady::class, 2);
    Mail::assertSent(HandoverPackageReady::class, fn (HandoverPackageReady $mail): bool => $mail->hasTo($this->lead->email));
    Mail::assertSent(HandoverPackageReady::class, fn (HandoverPackageReady $mail): bool => $mail->hasTo($manager->email));
});

/**
 * Job chạy trong một tiến trình còn treo phiên cổng của một khách KHÁC (cùng điều kiện test "…while an
 * unrelated client portal session is open" ở trên): `ClientPortalScope` khi đó cắt truy vấn vụ theo
 * khách của phiên kia. Để `$document->matter` tự nạp qua scope thì vụ ra `null`, không ai qua được
 * `download`/`publish` — kể cả cả chuỗi dự phòng — và KHÔNG AI được báo. Job gắn sẵn vụ đã nạp không
 * qua scope vào tài liệu gói.
 *
 * Mutation probe: bỏ `$document->setRelation('matter', $matter)` khỏi
 * `SendHandoverPackageReady::handle()` — test này ĐỎ.
 */
it('still tells the lead while an unrelated client portal session is open in the same process', function () {
    Mail::fake();
    hpmContract($this->matter, ContractStatus::Active);
    $package = hpmPackage($this->matter, $this->lead);

    $this->actingAs(ClientUser::factory()->activated()->create(), 'client');

    hpmRunReadyJob();

    expect(hpmReadyBells($this->lead, $package))->toBe(1);

    Mail::assertSent(HandoverPackageReady::class, 1);
    Mail::assertSent(HandoverPackageReady::class, fn (HandoverPackageReady $mail): bool => $mail->hasTo($this->lead->email));
});

/**
 * Riêng vế "công bố được": vụ thường chưa từng có hợp đồng đã ký, nên gói không in tiền và người
 * phụ trách đã bị đổi vai thành trợ lý vẫn TẢI được gói — nhưng không công bố được. Người nhận là
 * quản lý (tầng kế của chuỗi dự phòng R3), không phải người không làm được bước kế tiếp.
 *
 * Mutation probe: bỏ `publish` khỏi bộ lọc khả năng của `SendHandoverPackageReady` — test này ĐỎ.
 */
it('does not tell a lead switched to assistant who can still download a package without money but cannot publish it', function () {
    Mail::fake();
    $manager = User::factory()->withRole(Role::Manager)->create();
    $this->lead->syncRoles([Role::Assistant->value]);
    $formerLead = User::query()->findOrFail($this->lead->id);

    $package = hpmPackage($this->matter, $formerLead);

    expect(Gate::forUser($formerLead)->allows('download', $package))->toBeTrue()
        ->and(Gate::forUser($formerLead)->allows('publish', $package))->toBeFalse();

    hpmRunReadyJob();

    expect(hpmReadyBells($formerLead, $package))->toBe(0)
        ->and(hpmReadyBells($manager, $package))->toBe(1);

    Mail::assertSent(HandoverPackageReady::class, 1);
    Mail::assertSent(HandoverPackageReady::class, fn (HandoverPackageReady $mail): bool => $mail->hasTo($manager->email));
});

/**
 * Riêng vế "tải được": với bảng quyền mặc định, mọi vai có `document.publish` (luật sư, quản lý,
 * admin) đều có `billing.view`, nên "công bố được mà không tải được gói in tiền" chỉ dựng được bằng
 * một quyền gán thẳng — ở đây một trợ lý trong đội được gán riêng `document.publish` và là người bấm
 * sinh gói. Hai câu hỏi là hai cổng khác nhau của `DocumentPolicy`; bộ lọc hỏi cả hai, không suy cái
 * này từ cái kia.
 *
 * Mutation probe: bỏ `download` khỏi bộ lọc khả năng của `SendHandoverPackageReady` — test này ĐỎ.
 */
it('does not tell a requester who may publish the package but may not download it, since it prints the money', function () {
    Mail::fake();
    hpmContract($this->matter, ContractStatus::Active);
    $this->assistant->givePermissionTo(Permission::DocumentPublish->value);
    $assistant = User::query()->findOrFail($this->assistant->id);

    $package = hpmPackage($this->matter, $assistant);

    expect(Gate::forUser($assistant)->allows('publish', $package))->toBeTrue()
        ->and(Gate::forUser($assistant)->allows('download', $package))->toBeFalse();

    hpmRunReadyJob();

    expect(hpmReadyBells($assistant, $package))->toBe(0)
        ->and(hpmReadyBells($this->lead, $package))->toBe(1);

    Mail::assertSent(HandoverPackageReady::class, 1);
    Mail::assertSent(HandoverPackageReady::class, fn (HandoverPackageReady $mail): bool => $mail->hasTo($this->lead->email));
});
