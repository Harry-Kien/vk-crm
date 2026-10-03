<?php

use App\Actions\Billing\CancelContract;
use App\Actions\Matter\BuildHandoverPackage;
use App\Enums\ContractStatus;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\HandoverPackageStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\DocumentsRelationManager;
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
