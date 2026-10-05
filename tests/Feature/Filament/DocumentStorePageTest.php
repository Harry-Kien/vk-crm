<?php

use App\Enums\PreflightLevel;
use App\Enums\Role;
use App\Filament\Admin\Pages\DocumentStorePage;
use App\Models\Client;
use App\Models\Document;
use App\Models\Matter;
use App\Models\Setting;
use App\Models\SystemHealth;
use App\Models\User;
use App\Support\Files\FreeSpace;
use App\Support\Storage\DocumentStore;
use App\Support\Storage\TransferDossier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\DocumentStoreFixtures as Store;
use Tests\Support\FakeGoogleDrive;

/*
|--------------------------------------------------------------------------
| M14 Task 5 — trang "Kho tài liệu" (admin; kế hoạch R13, R14)
|--------------------------------------------------------------------------
|
| Mọi hành vi đo qua HTTP hoặc Livewire, không gọi thẳng Action. Trang chỉ hiện SỐ ĐẾM và trạng thái:
| không tiêu đề tài liệu, không mã hồ sơ, không tên khách, không mã tệp Drive. Không lệnh gọi Drive
| nào (`Http::preventStrayRequests()`).
*/

beforeEach(function () {
    Http::preventStrayRequests();

    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    app()->instance(FreeSpace::class, new FreeSpace(fn (string $path) => 40 * 1024 ** 3));
});

function t5PageSnapshot(string $html): string
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);

        if ((json_decode($snapshot, true)['memo']['name'] ?? null) === DocumentStorePage::class) {
            return $snapshot;
        }
    }

    throw new RuntimeException('Không có snapshot Livewire của trang Kho tài liệu trong HTML.');
}

function t5PostPageUpdate(string $snapshot, array $updates = [], array $calls = []): TestResponse
{
    return test()->withHeaders(['X-Livewire' => '1'])->postJson(Livewire::getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => $calls]],
    ]);
}

/** @return array<string, string> năm ô của hồ sơ chuyển dữ liệu ra nước ngoài */
function t5DossierValues(): array
{
    return [
        'transfer_dossier_on' => '2026-10-02',
        'transfer_dossier_reference' => 'A05-2026-0042',
        'dpa_accepted_on' => '2026-09-30',
        'transfer_before_dossier_on' => '2026-09-29',
        'transfer_before_dossier_basis' => 'Ý kiến pháp lý số 12/2026/YK ngày 29/9/2026',
    ];
}

// ---------------------------------------------------------------------------------------------
// Cổng: chỉ admin (settings.manage); mọi người khác 404, kể cả đường Livewire update
// ---------------------------------------------------------------------------------------------

it('mở được cho admin, hiện các con số và form hồ sơ', function () {
    $this->actingAs($this->admin, 'web')
        ->get(DocumentStorePage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('document_store.page.title'))
        ->assertSee(__('document_store.page.figures.pending_new'))
        ->assertSee(__('document_store.page.fields.transfer_dossier_reference'));
});

it('trả 404 cho luật sư, trưởng phòng, trợ lý, kế toán và không hiện trên thanh điều hướng của họ', function (Role $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user, 'web')
        ->get(DocumentStorePage::getUrl(panel: 'admin'))
        ->assertNotFound();

    expect(DocumentStorePage::shouldRegisterNavigation())->toBeFalse();
})->with([Role::Lawyer, Role::Manager, Role::Assistant, Role::Accountant]);

it('trả 404 cho một request cập nhật Livewire từ người đã mất quyền, và không lưu gì', function (Role $role) {
    $snapshot = t5PageSnapshot(
        $this->actingAs($this->admin, 'web')->get(DocumentStorePage::getUrl(panel: 'admin'))->assertOk()->getContent()
    );

    $this->admin->syncRoles([$role->value]);
    $this->actingAs($this->admin->fresh(), 'web');

    t5PostPageUpdate($snapshot, ['data.transfer_dossier_on' => '2026-10-02'])->assertNotFound();
    t5PostPageUpdate($snapshot, [], [['path' => '', 'method' => 'save', 'params' => []]])->assertNotFound();

    expect(Setting::query()->where('key', 'like', 'storage.%')->count())->toBe(0);
})->with([Role::Lawyer, Role::Manager, Role::Assistant, Role::Accountant]);

it('trả 404 khi gọi save qua Livewire dưới một tài khoản không phải admin', function () {
    $this->actingAs($this->admin, 'web');

    $page = Livewire::test(DocumentStorePage::class)->fillForm(t5DossierValues());

    $this->actingAs(User::factory()->withRole(Role::Lawyer)->create(), 'web');

    $page->call('save')->assertNotFound();

    expect(Setting::query()->where('key', 'like', 'storage.%')->count())->toBe(0);
});

it('save() tự hỏi lại cổng, kể cả khi vòng đời Livewire bị bỏ qua', function () {
    $this->actingAs(User::factory()->withRole(Role::Manager)->create(), 'web');

    expect(fn () => (new DocumentStorePage)->save())->toThrow(NotFoundHttpException::class);
});

// ---------------------------------------------------------------------------------------------
// Form R13
// ---------------------------------------------------------------------------------------------

it('admin lưu hồ sơ: năm khoá settings, một dòng audit data_transfer_dossier_recorded nêu tên trường', function () {
    $this->actingAs($this->admin, 'web');

    Livewire::test(DocumentStorePage::class)
        ->fillForm(t5DossierValues())
        ->call('save')
        ->assertHasNoFormErrors();

    foreach (t5DossierValues() as $field => $value) {
        expect(Setting::query()->where('key', TransferDossier::KEYS[$field])->value('value'))->toBe($value);
    }

    $activity = Activity::query()->where('event', 'data_transfer_dossier_recorded')->sole();

    expect($activity->causer_id)->toBe($this->admin->id)
        ->and($activity->properties['changed_fields'])->toBe(array_keys(t5DossierValues()));
});

it('lưu lại đúng giá trị cũ không ghi audit thứ hai', function () {
    $this->actingAs($this->admin, 'web');

    foreach ([1, 2] as $round) {
        Livewire::test(DocumentStorePage::class)->fillForm(t5DossierValues())->call('save')->assertHasNoFormErrors();
    }

    expect(Activity::query()->where('event', 'data_transfer_dossier_recorded')->count())->toBe(1);
});

it('giới hạn ô mã hồ sơ 100 và ô căn cứ 200 ký tự, đúng giới hạn của Action', function () {
    $this->actingAs($this->admin, 'web');

    Livewire::test(DocumentStorePage::class)
        ->assertFormFieldExists('transfer_dossier_reference', fn (Field $field): bool => $field->getMaxLength() === 100)
        ->assertFormFieldExists('transfer_before_dossier_basis', fn (Field $field): bool => $field->getMaxLength() === 200);
});

it('mã hồ sơ 101 ký tự và căn cứ 201 ký tự → lỗi tiếng Việt, không lưu gì', function () {
    $this->actingAs($this->admin, 'web');

    $page = Livewire::test(DocumentStorePage::class)
        ->fillForm([
            ...t5DossierValues(),
            'transfer_dossier_reference' => str_repeat('a', 101),
            'transfer_before_dossier_basis' => str_repeat('b', 201),
        ])
        ->call('save')
        ->assertHasFormErrors(['transfer_dossier_reference' => 'max', 'transfer_before_dossier_basis' => 'max']);

    $errors = $page->errors();

    expect($errors->first('data.transfer_dossier_reference'))->toContain('không được dài hơn 100 ký tự')
        ->and($errors->first('data.transfer_before_dossier_basis'))->toContain('không được dài hơn 200 ký tự')
        ->and(Setting::query()->where('key', 'like', 'storage.%')->count())->toBe(0);
});

it('ghi ngày ý kiến luật sư mà thiếu căn cứ → lỗi đúng ô căn cứ, không lưu gì', function () {
    $this->actingAs($this->admin, 'web');

    Livewire::test(DocumentStorePage::class)
        ->fillForm(['transfer_before_dossier_on' => '2026-09-29', 'transfer_before_dossier_basis' => ''])
        ->call('save')
        ->assertHasFormErrors(['transfer_before_dossier_basis']);

    expect(Setting::query()->where('key', 'like', 'storage.%')->count())->toBe(0);
});

it('sau khi admin lưu ngày hồ sơ trên trang, dòng data_transfer_dossier từ ĐỎ thành XANH', function () {
    config(['app.env' => 'production', 'vkcrm.storage.driver' => DocumentStore::DRIVER_GOOGLE_DRIVE]);

    expect(Store::state('data_transfer_dossier')['level'])->toBe(PreflightLevel::Red);

    $this->actingAs($this->admin, 'web');

    Livewire::test(DocumentStorePage::class)
        ->fillForm(['transfer_dossier_on' => '2026-10-02', 'transfer_dossier_reference' => 'A05-2026-0042'])
        ->call('save')
        ->assertHasNoFormErrors();

    Store::expectRow('data_transfer_dossier', PreflightLevel::Green, state: true);
});

// ---------------------------------------------------------------------------------------------
// Con số
// ---------------------------------------------------------------------------------------------

it('hiện chế độ, mốc bật kho, trạng thái, tồn đọng, tệp cũ, bản cục bộ, biên nhận, số mục và đồng hồ 60 ngày', function () {
    $this->freezeTime();
    config(['vkcrm.storage.google_drive.shared_drive_id' => FakeGoogleDrive::DRIVE_ID]);
    Store::enableRemote(now()->subDays(10));
    Store::setting(TransferDossier::FIRST_TRANSFER_AT_KEY, now()->subDays(20)->toIso8601String());

    Store::media(['created_at' => now()->subDays(30)]);                       // tệp cũ chờ chuyển
    Store::media(['created_at' => now()->subHours(3)]);                       // tệp mới chờ đẩy
    Store::media(['created_at' => now()->subHours(2)]);                       // tệp mới chờ đẩy
    Store::remoteMedia(now()->subDay(), ['local_purge_after' => now()->addHours(5)]); // bản cục bộ còn giữ
    Store::remoteMedia();                                                       // chưa có biên nhận

    SystemHealth::current()->forceFill([
        'document_store_status' => 'degraded',
        'document_store_checked_at' => now()->subMinutes(20),
        'document_store_detail' => 'Có 2 tệp mới chờ đẩy.',
        'last_office_receipt_at' => now()->subHours(6),
    ])->save();

    $html = $this->actingAs($this->admin, 'web')
        ->get(DocumentStorePage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('document_store.page.mode.google_drive'))
        ->assertSee(__('enums.document_store_status.degraded'))
        ->assertSee('Có 2 tệp mới chờ đẩy.')
        ->getContent();

    // Giá trị của một con số: chữ trong `div data-figure`, bỏ thẻ và bỏ nhãn.
    $figure = fn (string $key): string => trim((string) str(html_entity_decode(strip_tags(
        '<div '.str($html)->after('data-figure="'.$key.'"')->before('</div>').'</div>'
    )))->after(__('document_store.page.figures.'.$key)));

    expect($figure('pending_new'))->toBe('2')
        ->and($figure('legacy'))->toBe('1')
        ->and($figure('local_copies'))->toBe('1')
        ->and($figure('unreceipted'))->toBe('1')
        ->and($figure('items'))->toBe(__('document_store.page.values.items', ['count' => '2', 'limit' => '400.000']))
        ->and($figure('dossier_clock'))->toBe(__('document_store.page.values.clock_days_left', ['days' => 40]));
});

it('trang không mang tiêu đề tài liệu, mã hồ sơ, tên khách hay file_id', function () {
    $client = Client::factory()->create(['name' => 'KHACH-DAU-T5']);
    $matter = Matter::factory()->create(['client_id' => $client->id, 'code' => 'HS-DAU-T5', 'title' => 'VU-DAU-T5']);
    Document::factory()->create(['matter_id' => $matter->id, 'title' => 'TAILIEU-DAU-T5']);
    Store::enableRemote(now()->subDay());
    Store::remoteMedia(object: ['file_id' => 'FILEID-DAU-T5']);
    Store::media(['name' => 'TENTEP-DAU-T5', 'created_at' => now()->subHours(3)]);

    $html = $this->actingAs($this->admin, 'web')->get(DocumentStorePage::getUrl(panel: 'admin'))->assertOk()->getContent();

    foreach (['KHACH-DAU-T5', 'HS-DAU-T5', 'VU-DAU-T5', 'TAILIEU-DAU-T5', 'FILEID-DAU-T5', 'TENTEP-DAU-T5'] as $marker) {
        expect($html)->not->toContain($marker);
    }
});

it('trang dùng biến màu Filament đã đăng ký, không class viết tay trong phần của dự án', function () {
    $this->freezeTime();
    Store::enableRemote(now()->subDay());

    $this->actingAs($this->admin, 'web');

    $markup = view('filament.admin.pages.document-store-figures', app(DocumentStorePage::class)->figures())->render();

    expect(unregisteredColourVariables($markup))->toBe([])
        ->and($markup)->not->toContain('class=');
});
