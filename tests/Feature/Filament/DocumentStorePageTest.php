<?php

use App\Enums\PreflightLevel;
use App\Enums\Role;
use App\Filament\Admin\Pages\DocumentLibrary;
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
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\DB;
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

/*
| Ba ô ngày ghi việc ĐÃ xảy ra (vòng sửa 1 của Task 5, I1): một ngày hồ sơ hay ngày ý kiến "dự kiến" mở
| cổng R13 trên production và dừng đồng hồ 60 ngày khi hồ sơ chưa tồn tại. 00:30 sáng 5/10 giờ Việt Nam
| là 4/10 theo UTC: "hôm nay" là ngày theo múi giờ ứng dụng.
*/

it('ba ô ngày không cho chọn sau hôm nay (ngày tối đa của lịch = hôm nay theo múi giờ ứng dụng)', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 00:30', config('app.timezone')));
    $this->actingAs($this->admin, 'web');

    $page = Livewire::test(DocumentStorePage::class);

    foreach (['transfer_dossier_on', 'dpa_accepted_on', 'transfer_before_dossier_on'] as $field) {
        $page->assertFormFieldExists($field, fn (DatePicker $picker): bool => $picker->getMaxDate() === '2026-10-05');
    }
});

it('ngày mai ở một ô ngày → lỗi tiếng Việt đúng ô đó, không lưu gì, dòng data_transfer_dossier vẫn ĐỎ', function (string $field) {
    config(['app.env' => 'production', 'vkcrm.storage.driver' => DocumentStore::DRIVER_GOOGLE_DRIVE]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 00:30', config('app.timezone')));
    $this->actingAs($this->admin, 'web');

    $page = Livewire::test(DocumentStorePage::class)
        ->fillForm([
            'transfer_dossier_reference' => 'A05-2026-0042',
            'transfer_before_dossier_basis' => 'Ý kiến pháp lý số 12/2026/YK',
            $field => '2026-10-06',
        ])
        ->call('save')
        ->assertHasFormErrors([$field]);

    expect($page->errors()->first("data.{$field}"))->toContain('không được là ngày trong tương lai')
        ->and(Setting::query()->where('key', 'like', 'storage.%')->count())->toBe(0)
        ->and(Activity::query()->where('event', 'data_transfer_dossier_recorded')->count())->toBe(0);

    Store::expectRow('data_transfer_dossier', PreflightLevel::Red, state: true);
})->with(['transfer_dossier_on', 'dpa_accepted_on', 'transfer_before_dossier_on']);

it('ngày hôm nay ở cả ba ô ngày được lưu và mở cổng (biên của "không sau hôm nay")', function () {
    config(['app.env' => 'production', 'vkcrm.storage.driver' => DocumentStore::DRIVER_GOOGLE_DRIVE]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 00:30', config('app.timezone')));
    $this->actingAs($this->admin, 'web');

    Livewire::test(DocumentStorePage::class)
        ->fillForm([
            'transfer_dossier_on' => '2026-10-05',
            'transfer_dossier_reference' => 'A05-2026-0042',
            'dpa_accepted_on' => '2026-10-05',
            'transfer_before_dossier_on' => '2026-10-05',
            'transfer_before_dossier_basis' => 'Ý kiến pháp lý số 12/2026/YK',
        ])
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

// ---------------------------------------------------------------------------------------------
// Làn tl (2026-10-15) — trang nói tiếng thường: dải trạng thái, các bước bật Drive, bốn ô số có màu,
// "Chi tiết kỹ thuật" thu gọn, hồ sơ pháp lý thu gọn, ô ngày dd/mm/yyyy. Mọi con số và hành vi giữ nguyên.
// ---------------------------------------------------------------------------------------------

function tlStoreHtml(User $admin): string
{
    return test()->actingAs($admin, 'web')->get(DocumentStorePage::getUrl(panel: 'admin'))->assertOk()->getContent();
}

/** Giá trị thuộc tính `$attribute` trên thẻ mở đầu tiên chứa `$marker`. */
function tlStoreAttribute(string $html, string $marker, string $attribute): ?string
{
    $tag = (string) str($html)->after($marker)->before('>');

    return preg_match('/'.preg_quote($attribute, '/').'="([^"]*)"/', $tag, $m) === 1 ? $m[1] : null;
}

/** HTML của trang bỏ đi khối "Chi tiết kỹ thuật". */
function tlStoreWithoutTechnical(string $html): string
{
    return (string) preg_replace('/<details data-section="technical".*?<\/details>/s', '', $html);
}

it('dải trạng thái: một câu cho mỗi tình huống, xanh/vàng/đỏ, kèm "cần làm gì tiếp"', function (Closure $arrange, string $state, string $situation, array $replace) {
    $this->freezeTime();
    $arrange();

    $html = html_entity_decode(tlStoreHtml($this->admin), ENT_QUOTES);

    expect(tlStoreAttribute($html, 'data-overview', 'data-state'))->toBe($state)
        ->and(tlStoreAttribute($html, 'data-overview', 'data-situation'))->toBe($situation)
        ->and($html)->toContain(__('document_store.page.overview.headline.'.$situation, $replace))
        ->and($html)->toContain(__('document_store.page.overview.next.'.$situation));
})->with([
    'chỉ lưu trên máy chủ' => [fn () => null, 'ok', 'local', []],
    'chế độ máy chủ mà còn tệp trên Drive' => [function () {
        Store::remoteMedia();
        Store::remoteMedia();
    }, 'warning', 'remote_while_local', ['count' => '2']],
    'giá trị chế độ lạ' => [fn () => config(['vkcrm.storage.driver' => 'drive']), 'error', 'driver_invalid', []],
    'đã chọn Drive nhưng chưa bật' => [fn () => config(['vkcrm.storage.driver' => DocumentStore::DRIVER_GOOGLE_DRIVE]), 'warning', 'not_enabled', []],
    'kiểm tra báo lỗi cấu hình' => [function () {
        Store::enableRemote(now()->subDay());
        SystemHealth::current()->forceFill(['document_store_status' => 'misconfigured', 'document_store_checked_at' => now()])->save();
    }, 'error', 'check_failed', []],
    'kiểm tra báo không truy cập được' => [function () {
        Store::enableRemote(now()->subDay());
        SystemHealth::current()->forceFill(['document_store_status' => 'unavailable', 'document_store_checked_at' => now()])->save();
    }, 'error', 'check_failed', []],
    'kiểm tra báo có việc cần xem' => [function () {
        Store::enableRemote(now()->subDay());
        SystemHealth::current()->forceFill(['document_store_status' => 'degraded', 'document_store_checked_at' => now()])->save();
    }, 'warning', 'degraded', []],
    'chưa có lần kiểm tra nào sau khi bật' => [fn () => Store::enableRemote(now()->subDay()), 'warning', 'not_checked', []],
    'còn tệp cũ chưa chuyển' => [function () {
        Store::enableRemote(now()->subDay());
        Store::media(['created_at' => now()->subDays(30)]);
        Store::media(['created_at' => now()->subDays(20)]);
        SystemHealth::current()->forceFill(['document_store_status' => 'ok', 'document_store_checked_at' => now()])->save();
    }, 'warning', 'legacy_pending', ['count' => '2']],
    'Drive chạy ổn, tệp cũ đã chuyển hết' => [function () {
        Store::enableRemote(now()->subDay());
        SystemHealth::current()->forceFill(['document_store_status' => 'ok', 'document_store_checked_at' => now()])->save();
    }, 'ok', 'remote_ok', []],
    'quá hạn nộp hồ sơ (production)' => [function () {
        config(['app.env' => 'production']);
        Store::enableRemote(now()->subDays(70));
        Store::setting(TransferDossier::FIRST_TRANSFER_AT_KEY, now()->subDays(63)->toIso8601String());
        Store::setting(TransferDossier::KEYS['transfer_before_dossier_on'], now()->subDays(70)->toDateString());
        Store::setting(TransferDossier::KEYS['transfer_before_dossier_basis'], 'Ý kiến số 12');
        SystemHealth::current()->forceFill(['document_store_status' => 'ok', 'document_store_checked_at' => now()])->save();
    }, 'error', 'dossier_overdue', ['days' => 3]],
    'quá 60 ngày ngoài production: không có hạn pháp lý nào' => [function () {
        Store::enableRemote(now()->subDays(70));
        Store::setting(TransferDossier::FIRST_TRANSFER_AT_KEY, now()->subDays(63)->toIso8601String());
        SystemHealth::current()->forceFill(['document_store_status' => 'ok', 'document_store_checked_at' => now()])->save();
    }, 'ok', 'remote_ok', []],
    'sắp tới hạn nộp hồ sơ (production)' => [function () {
        config(['app.env' => 'production']);
        Store::enableRemote(now()->subDays(60));
        Store::setting(TransferDossier::FIRST_TRANSFER_AT_KEY, now()->subDays(57)->toIso8601String());
        Store::setting(TransferDossier::KEYS['transfer_before_dossier_on'], now()->subDays(60)->toDateString());
        Store::setting(TransferDossier::KEYS['transfer_before_dossier_basis'], 'Ý kiến số 12');
        SystemHealth::current()->forceFill(['document_store_status' => 'ok', 'document_store_checked_at' => now()])->save();
    }, 'warning', 'dossier_due', ['days' => 3]],
    // Rà soát cuối làn tl, I1: production, kho đang bật, lần kiểm tra mỗi giờ gần nhất còn `ok`, nhưng
    // không còn ngày hồ sơ lẫn ý kiến luật sư → lượt đẩy từ chối, `storage:migrate` trả mã 2. Dải không
    // được nói "Không cần làm gì" hay bảo chạy lệnh chuyển tệp cũ trong lúc chờ lần kiểm tra kế tiếp.
    'kho đang bật mà không còn ngày hồ sơ lẫn ý kiến luật sư (production)' => [function () {
        config(['app.env' => 'production']);
        Store::enableRemote(now()->subDay());
        SystemHealth::current()->forceFill(['document_store_status' => 'ok', 'document_store_checked_at' => now()])->save();
    }, 'error', 'transfer_blocked', []],
    'như trên, còn tệp cũ chưa chuyển: không bảo chạy lệnh chuyển tệp cũ' => [function () {
        config(['app.env' => 'production']);
        Store::enableRemote(now()->subDay());
        Store::media(['created_at' => now()->subDays(30)]);
        SystemHealth::current()->forceFill(['document_store_status' => 'ok', 'document_store_checked_at' => now()])->save();
    }, 'error', 'transfer_blocked', []],
    'production, kho đang bật, chỉ có ý kiến luật sư cho chuyển trước: cổng mở' => [function () {
        config(['app.env' => 'production']);
        Store::enableRemote(now()->subDay());
        Store::setting(TransferDossier::KEYS['transfer_before_dossier_on'], now()->subDays(2)->toDateString());
        Store::setting(TransferDossier::KEYS['transfer_before_dossier_basis'], 'Ý kiến số 12');
        SystemHealth::current()->forceFill(['document_store_status' => 'ok', 'document_store_checked_at' => now()])->save();
    }, 'ok', 'remote_ok', []],
    'production, đã chọn Drive nhưng chưa bật, chưa có hồ sơ: chưa bật, không phải bị chặn' => [
        fn () => config(['app.env' => 'production', 'vkcrm.storage.driver' => DocumentStore::DRIVER_GOOGLE_DRIVE]),
        'warning', 'not_enabled', [],
    ],
    'production, chỉ lưu trên máy chủ, chưa có hồ sơ' => [fn () => config(['app.env' => 'production']), 'ok', 'local', []],
    // Vòng sửa polish 1, m3: quay lui về máy chủ khi đồng hồ 60 ngày còn chạy. Lần kiểm tra mỗi giờ
    // báo `degraded` và gửi thư nhắc hồ sơ mỗi ngày từ ngày 45 — dải không được nói "Không cần làm gì".
    'quay lui về máy chủ, đồng hồ 60 ngày ở ngày 50 (production): sắp tới hạn' => [function () {
        config(['app.env' => 'production']);
        Store::setting(TransferDossier::FIRST_TRANSFER_AT_KEY, now()->subDays(50)->toIso8601String());
        SystemHealth::current()->forceFill(['document_store_status' => 'degraded', 'document_store_checked_at' => now()])->save();
    }, 'warning', 'dossier_due', ['days' => 10]],
    'như trên, còn tệp trên Drive: hạn hồ sơ trước' => [function () {
        config(['app.env' => 'production']);
        Store::setting(TransferDossier::FIRST_TRANSFER_AT_KEY, now()->subDays(50)->toIso8601String());
        Store::remoteMedia();
        SystemHealth::current()->forceFill(['document_store_status' => 'degraded', 'document_store_checked_at' => now()])->save();
    }, 'warning', 'dossier_due', ['days' => 10]],
    'quay lui về máy chủ, đồng hồ mới ở ngày 30 (production): chỉ lưu trên máy chủ' => [function () {
        config(['app.env' => 'production']);
        Store::setting(TransferDossier::FIRST_TRANSFER_AT_KEY, now()->subDays(30)->toIso8601String());
    }, 'ok', 'local', []],
    'quay lui về máy chủ, ngày 50 ngoài production: không có hạn pháp lý nào' => [
        fn () => Store::setting(TransferDossier::FIRST_TRANSFER_AT_KEY, now()->subDays(50)->toIso8601String()),
        'ok', 'local', [],
    ],
    // Vòng sửa polish 1, m4: kiểm tra mỗi giờ còn `ok` nhưng đã có tệp mới chờ đẩy quá ngưỡng (ô số đỏ).
    'Drive chạy ổn nhưng có tệp mới chờ đẩy quá ngưỡng: vàng' => [function () {
        Store::enableRemote(now()->subDay());
        Store::media(['created_at' => now()->subMinutes((int) config('vkcrm.storage.push_alert_minutes') + 5)]);
        Store::media(['created_at' => now()->subMinutes((int) config('vkcrm.storage.push_alert_minutes') + 9)]);
        Store::media(['created_at' => now()->subMinute()]);
        SystemHealth::current()->forceFill(['document_store_status' => 'ok', 'document_store_checked_at' => now()])->save();
    }, 'warning', 'push_backlog', ['count' => '2', 'minutes' => '60']],
    'Drive chạy ổn, tệp mới vừa tải lên còn trong ngưỡng: xanh' => [function () {
        Store::enableRemote(now()->subDay());
        Store::media(['created_at' => now()->subMinute()]);
        SystemHealth::current()->forceFill(['document_store_status' => 'ok', 'document_store_checked_at' => now()])->save();
    }, 'ok', 'remote_ok', []],
]);

it('m4: ô "Tệp chờ đẩy lên Drive" đỏ thì dải trạng thái không bao giờ xanh', function () {
    $this->freezeTime();
    Store::enableRemote(now()->subDay());
    SystemHealth::current()->forceFill(['document_store_status' => 'ok', 'document_store_checked_at' => now()])->save();

    $html = tlStoreHtml($this->admin);
    expect(tlStoreAttribute($html, 'data-figure="pending_new"', 'data-tone'))->toBe('good')
        ->and(tlStoreAttribute($html, 'data-overview', 'data-state'))->toBe('ok');

    Store::media(['created_at' => now()->subMinutes((int) config('vkcrm.storage.push_alert_minutes') + 5)]);

    $html = tlStoreHtml($this->admin);
    expect(tlStoreAttribute($html, 'data-figure="pending_new"', 'data-tone'))->toBe('danger')
        ->and(tlStoreAttribute($html, 'data-overview', 'data-state'))->toBe('warning')
        ->and(html_entity_decode($html, ENT_QUOTES))->not->toContain(__('document_store.page.overview.next.remote_ok'));
});

it('m5: nút "Lưu" của hồ sơ nằm trong mục thu gọn — mục đóng thì nút đóng theo', function () {
    $html = html_entity_decode(tlStoreHtml($this->admin), ENT_QUOTES);
    $submit = __('document_store.page.submit');

    // Phần thân thu gọn của mục (`fi-section-content-ctn`; mặc định đóng nên mang `x-cloak`) chứa nút …
    $body = (string) str($html)->after('class="fi-section-content-ctn"')->before('</section>');
    // … và sau mục, tới hết form, không còn nút lưu nào.
    $afterSection = (string) str($html)->after('class="fi-section-content-ctn"')->after('</section>')->before('</form>');

    expect($html)->toMatch('/x-cloak\s+class="fi-section-content-ctn"/')
        ->and($body)->toContain($submit)
        ->and($body)->toContain('type="submit"')
        ->and($afterSection)->not->toContain($submit);
});

it('admin xoá cả ngày hồ sơ lẫn ngày ý kiến luật sư trên trang (production, kho đang bật): dải đổi ngay sang ĐỎ "đã dừng chuyển"', function () {
    $this->freezeTime();
    config(['app.env' => 'production']);
    Store::enableRemote(now()->subDay());
    foreach (t5DossierValues() as $field => $value) {
        Store::setting(TransferDossier::KEYS[$field], $value);
    }
    SystemHealth::current()->forceFill(['document_store_status' => 'ok', 'document_store_checked_at' => now()])->save();

    expect(tlStoreAttribute(tlStoreHtml($this->admin), 'data-overview', 'data-situation'))->toBe('remote_ok');

    Livewire::test(DocumentStorePage::class)
        ->fillForm(['transfer_dossier_on' => null, 'transfer_before_dossier_on' => null, 'transfer_before_dossier_basis' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    $html = html_entity_decode(tlStoreHtml($this->admin), ENT_QUOTES);

    expect(tlStoreAttribute($html, 'data-overview', 'data-state'))->toBe('error')
        ->and(tlStoreAttribute($html, 'data-overview', 'data-situation'))->toBe('transfer_blocked')
        ->and($html)->toContain(__('document_store.page.overview.headline.transfer_blocked'))
        ->and($html)->not->toContain(__('document_store.page.overview.next.remote_ok'));
});

it('nói ngay đầu trang đây là trang kỹ thuật, và dẫn nhân sự tới trang Tài liệu', function () {
    $html = tlStoreHtml($this->admin);

    expect(html_entity_decode($html, ENT_QUOTES))->toContain(__('document_store.page.overview.intro'))
        ->and($html)->toContain('href="'.DocumentLibrary::getUrl(panel: 'admin').'"');
});

it('các bước bật Google Drive đánh dấu đúng bước đã xong', function (Closure $arrange, array $done) {
    $this->freezeTime();
    $arrange();

    $html = tlStoreHtml($this->admin);

    foreach (['server', 'shared_drive', 'dossier', 'enabled_checked', 'migrated'] as $step) {
        expect(tlStoreAttribute($html, 'data-step="'.$step.'"', 'data-done'))
            ->toBe(in_array($step, $done, true) ? '1' : '0', "bước {$step}");
    }
})->with([
    'chưa làm gì' => [fn () => null, []],
    'đã đặt khoá và Shared Drive' => [function () {
        config([
            'vkcrm.storage.google_drive.credentials_path' => '/etc/vkcrm/google-drive-key.json',
            'vkcrm.storage.google_drive.shared_drive_id' => FakeGoogleDrive::DRIVE_ID,
            'vkcrm.storage.google_drive.root_folder_id' => FakeGoogleDrive::ROOT_FOLDER_ID,
        ]);
    }, ['server', 'shared_drive']],
    'Shared Drive thiếu thư mục gốc thì chưa xong' => [function () {
        config(['vkcrm.storage.google_drive.shared_drive_id' => FakeGoogleDrive::DRIVE_ID]);
    }, []],
    'có ý kiến luật sư cho chuyển trước' => [function () {
        Store::setting(TransferDossier::KEYS['transfer_before_dossier_on'], '2026-09-29');
        Store::setting(TransferDossier::KEYS['transfer_before_dossier_basis'], 'Ý kiến số 12');
    }, ['dossier']],
    'có ngày hồ sơ' => [fn () => Store::setting(TransferDossier::KEYS['transfer_dossier_on'], '2026-10-02'), ['dossier']],
    'đã bật, tệp cũ chuyển hết, nhưng lần kiểm tra chưa đạt' => [function () {
        Store::enableRemote(now()->subDay());
        SystemHealth::current()->forceFill(['document_store_status' => 'degraded', 'document_store_checked_at' => now()])->save();
    }, ['migrated']],
    'đã bật, kiểm tra đạt, còn tệp cũ' => [function () {
        Store::enableRemote(now()->subDay());
        Store::media(['created_at' => now()->subDays(30)]);
        SystemHealth::current()->forceFill(['document_store_status' => 'ok', 'document_store_checked_at' => now()])->save();
    }, ['enabled_checked']],
    'còn mốc bật và kiểm tra đạt mà công tắc đã về máy chủ: chưa bật' => [function () {
        Store::setting(DocumentStore::REMOTE_ENABLED_AT_KEY, now()->subDay()->toIso8601String());
        SystemHealth::current()->forceFill(['document_store_status' => 'ok', 'document_store_checked_at' => now()])->save();
    }, []],
]);

it('bốn ô số nói tốt hay cần xem bằng màu', function () {
    $this->freezeTime();
    config(['vkcrm.storage.google_drive.shared_drive_id' => FakeGoogleDrive::DRIVE_ID]);

    // Chế độ máy chủ: tệp cũ trên máy chủ là bình thường, không phải việc cần làm.
    Store::media(['created_at' => now()->subDays(30)]);
    $html = tlStoreHtml($this->admin);
    expect(tlStoreAttribute($html, 'data-figure="legacy"', 'data-tone'))->toBe('neutral')
        ->and(tlStoreAttribute($html, 'data-figure="pending_new"', 'data-tone'))->toBe('neutral')
        ->and(tlStoreAttribute($html, 'data-figure="unreceipted"', 'data-tone'))->toBe('neutral');

    // Drive đã bật: còn tệp cũ là việc cần làm; không tệp mới nào chờ là tốt; tệp trên Drive chưa có
    // bản ở máy văn phòng là việc cần xem.
    Store::enableRemote(now()->subDay());
    Store::remoteMedia();
    $html = tlStoreHtml($this->admin);
    expect(tlStoreAttribute($html, 'data-figure="legacy"', 'data-tone'))->toBe('warning')
        ->and(tlStoreAttribute($html, 'data-figure="pending_new"', 'data-tone'))->toBe('good')
        ->and(tlStoreAttribute($html, 'data-figure="unreceipted"', 'data-tone'))->toBe('warning')
        ->and(tlStoreAttribute($html, 'data-figure="local_copies"', 'data-tone'))->toBe('neutral');

    // Tệp mới vừa tạo đang chờ đẩy: bình thường. Chờ quá ngưỡng báo động: xấu.
    Store::media(['created_at' => now()->subMinute()]);
    expect(tlStoreAttribute(tlStoreHtml($this->admin), 'data-figure="pending_new"', 'data-tone'))->toBe('neutral');

    Store::media(['created_at' => now()->subMinutes((int) config('vkcrm.storage.push_alert_minutes') + 5)]);
    expect(tlStoreAttribute(tlStoreHtml($this->admin), 'data-figure="pending_new"', 'data-tone'))->toBe('danger');

    // Mọi tệp trên Drive đã có bản ở máy văn phòng: tốt.
    DB::table('drive_objects')->update(['office_copied_at' => now()]);
    expect(tlStoreAttribute(tlStoreHtml($this->admin), 'data-figure="unreceipted"', 'data-tone'))->toBe('good');
});

it('tên biến môi trường và lệnh artisan chỉ nằm trong mục "Chi tiết kỹ thuật", mặc định đóng', function () {
    $html = html_entity_decode(tlStoreHtml($this->admin), ENT_QUOTES);

    preg_match('/<details data-section="technical"([^>]*)>(.*?)<\/details>/s', $html, $technical);

    expect($technical)->not->toBeEmpty()
        ->and($technical[1])->not->toContain('open')
        ->and($technical[2])->toContain('DOCUMENT_STORAGE')
        ->and($technical[2])->toContain('php artisan vkcrm:storage:check')
        ->and($technical[2])->toContain('vkcrm:storage:migrate')
        ->and($technical[2])->toContain('vkcrm:storage:enable');

    $plain = tlStoreWithoutTechnical($html);

    foreach (['DOCUMENT_STORAGE', 'GOOGLE_DRIVE_', 'vkcrm:storage', 'php artisan', 'Cloud Data Processing Addendum', 'Media trên kho'] as $jargon) {
        expect($plain)->not->toContain($jargon);
    }
});

it('mục hồ sơ chuyển dữ liệu ra nước ngoài thu gọn, chỉ mở sẵn khi đã chọn Drive mà chưa có ngày hồ sơ lẫn ý kiến luật sư', function () {
    $this->actingAs($this->admin, 'web');

    // Mục hồ sơ là Section DUY NHẤT của form (không đặt `key()`: một khoá trên Section đổi khoá của mọi ô
    // con thành `<khoá>.<ô>`).
    $section = fn (): Section => Livewire::test(DocumentStorePage::class)->instance()->form
        ->getComponent(fn ($component): bool => $component instanceof Section, withHidden: true);

    expect($section()->isCollapsible())->toBeTrue()
        ->and($section()->isCollapsed())->toBeTrue();

    config(['vkcrm.storage.driver' => DocumentStore::DRIVER_GOOGLE_DRIVE]);

    expect($section()->isCollapsed())->toBeFalse();

    Store::setting(TransferDossier::KEYS['transfer_before_dossier_on'], '2026-09-29');

    expect($section()->isCollapsed())->toBeTrue();
});

it('ba ô ngày của hồ sơ hiện dd/mm/yyyy như phần còn lại của hệ thống', function () {
    $this->actingAs($this->admin, 'web');

    $page = Livewire::test(DocumentStorePage::class);

    foreach (['transfer_dossier_on', 'dpa_accepted_on', 'transfer_before_dossier_on'] as $field) {
        $page->assertFormFieldExists($field, fn (DatePicker $picker): bool => ! $picker->isNative() && $picker->getDisplayFormat() === 'd/m/Y');
    }
});
