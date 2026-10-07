<?php

use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Enums\UserPosition;
use App\Filament\Admin\Pages\Auth\Login as StaffLogin;
use App\Filament\Admin\Resources\ClientUsers\Pages\CreateClientUser;
use App\Filament\Admin\Resources\ClientUsers\Pages\EditClientUser;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Portal\Pages\Auth\Login as PortalLogin;
use App\Jobs\GenerateHandoverPackage;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\StageLog;
use App\Models\User;
use App\Notifications\Client\SendLoginCode;
use App\Support\ActivityOwningMatter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PragmaRX\Google2FAQRCode\Google2FA;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| SPEC §10.6 — nhật ký hoạt động: đủ TÁM loại sự kiện, mỗi loại một test đi qua màn hình thật
|--------------------------------------------------------------------------
|
| SPEC §10.6 liệt kê: đăng nhập (thành công/thất bại, cả hai guard), tải tài liệu, công bố tài
| liệu, công bố tiến độ, đổi phân quyền, tạo/vô hiệu hoá tài khoản portal, xuất dữ liệu. Mỗi mục
| có một test tên `§10.6 …` — vì §14 mục 1 đòi "checklist mục 10 tick hết", và một checklist tick
| bằng trí nhớ thì không phải checklist.
|
| Mọi test đi qua đúng màn hình/HTTP thật (Livewire trên trang, request tải tệp), KHÔNG gọi thẳng
| Action để chứng minh hành vi màn hình, và khẳng định dòng nhật ký: khoá sự kiện, chủ thể (subject),
| người thực hiện (causer), và IP khi có.
|
| Ba loại (`stage_log_published`, `permission_changed`, `portal_account_created/deactivated`) trước
| M8 Task 3 chỉ tồn tại GIÁN TIẾP (một diff `updated`, hoặc một cờ trong properties của sự kiện
| khác). Nay mỗi loại là một sự kiện tường minh; dòng `updated` của `LogsActivity` vẫn đứng cạnh.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Storage::fake('private');
    config(['media-library.prefix' => 'test-'.Str::random(16)]);
});

function lastActivity(string $event): ?Activity
{
    return Activity::query()->where('event', $event)->latest('id')->first();
}

/** Mọi dòng của một sự kiện — để khẳng định cả "có đúng một dòng" lẫn "không có dòng nào". */
function activitiesOf(string $event)
{
    return Activity::query()->where('event', $event)->orderBy('id')->get();
}

/*
|--------------------------------------------------------------------------
| 1. Đăng nhập thành công / thất bại — cả hai guard
|--------------------------------------------------------------------------
*/

it('§10.6 records a staff sign-in success on guard web with the address, subject and causer', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create(['password' => 'mat-khau-dung']);

    $this->livewire(StaffLogin::class)
        ->set('data.email', $staff->email)
        ->set('data.password', 'mat-khau-dung')
        ->call('authenticate')
        ->set('data.multiFactor.app.code', app(Google2FA::class)->getCurrentOtp($staff->two_factor_secret))
        ->call('authenticate')
        ->assertHasNoErrors();

    $row = lastActivity('login_success');

    expect($row)->not->toBeNull()
        ->and($row->properties->get('guard'))->toBe('web')
        ->and($row->properties->get('ip'))->toBe('127.0.0.1')
        ->and($row->subject?->is($staff))->toBeTrue()
        ->and($row->causer?->is($staff))->toBeTrue();
});

it('§10.6 records a staff sign-in failure on guard web with the address and the account when there is one', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();

    $this->livewire(StaffLogin::class)
        ->set('data.email', $staff->email)
        ->set('data.password', 'sai-mat-khau')
        ->call('authenticate')
        ->assertHasErrors();

    $row = lastActivity('login_failed');

    expect($row)->not->toBeNull()
        ->and($row->properties->get('guard'))->toBe('web')
        ->and($row->properties->get('ip'))->toBe('127.0.0.1')
        ->and($row->properties->get('step'))->toBe('password')
        ->and($row->causer?->is($staff))->toBeTrue();
});

it('§10.6 records a portal sign-in failure and success on guard client with the address', function () {
    Notification::fake();
    Filament::setCurrentPanel('portal');

    $client = ClientUser::factory()->activated()->create();

    $this->livewire(PortalLogin::class)
        ->set('data.email', $client->email)
        ->set('data.password', 'sai-mat-khau')
        ->call('authenticate');

    $failed = lastActivity('login_failed');

    expect($failed)->not->toBeNull()
        ->and($failed->properties->get('guard'))->toBe('client')
        ->and($failed->properties->get('ip'))->toBe('127.0.0.1')
        ->and($failed->causer?->is($client))->toBeTrue();

    $component = $this->livewire(PortalLogin::class)
        ->set('data.email', $client->email)
        ->set('data.password', 'password')
        ->call('authenticate');

    $code = Notification::sent($client, SendLoginCode::class)->last()->code();

    $component->set('data.multiFactor.email_code.code', $code)->call('authenticate')->assertHasNoErrors();

    $success = lastActivity('login_success');

    expect($success)->not->toBeNull()
        ->and($success->properties->get('guard'))->toBe('client')
        ->and($success->properties->get('ip'))->toBe('127.0.0.1')
        ->and($success->causer?->is($client))->toBeTrue()
        ->and($success->subject?->is($client))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 2. Tải tài liệu — HTTP thật, chữ ký gắn người nhận
|--------------------------------------------------------------------------
*/

it('§10.6 records a document download over the real signed route, with the downloader as causer', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $clientUser = ClientUser::factory()->activated()->create(['client_id' => $client->id]);
    $matter = Matter::factory()->for($client)->create(['lead_lawyer_id' => $lawyer->id, 'is_published_to_portal' => true]);

    $document = Document::factory()->create([
        'matter_id' => $matter->id,
        'group' => DocumentGroup::ClientProvided,
        'status' => 'published',
        'client_can_view' => true,
        'client_can_download' => true,
    ]);
    $document->addMedia(UploadedFile::fake()->createWithContent('nguon.pdf', '%PDF-1.4 noi dung'))
        ->usingFileName('01k5g7q8wz0000000000000000.pdf')
        ->toMediaCollection('file');

    $this->actingAs($clientUser, 'client')->get($document->downloadUrlFor($clientUser))->assertOk();

    $row = lastActivity('document_downloaded');

    expect($row)->not->toBeNull()
        ->and($row->subject?->is($document))->toBeTrue()
        ->and($row->causer?->is($clientUser))->toBeTrue()
        ->and($row->properties->get('matter_id'))->toBe($matter->id);
});

/*
|--------------------------------------------------------------------------
| 3. Công bố tài liệu — nút "Công bố" của tab Tài liệu
|--------------------------------------------------------------------------
*/

it('§10.6 records document publication when a lawyer presses publish on the documents tab', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $document = Document::factory()->for($matter)->group(DocumentGroup::Authority)->create();
    $document->addMedia(UploadedFile::fake()->createWithContent('nguon.pdf', '%PDF-1.4 noi dung'))
        ->usingFileName('01k5g7q8wz0000000000000001.pdf')
        ->toMediaCollection('file');

    $this->actingAs($lawyer, 'web');

    $this->livewire(DocumentsRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
        ->callAction(TestAction::make('publish')->table($document->refresh()), data: [
            'client_can_view' => true,
            'client_can_download' => false,
        ])
        ->assertHasNoActionErrors();

    $row = lastActivity('document_published');

    expect($row)->not->toBeNull()
        ->and($row->subject?->is($document))->toBeTrue()
        ->and($row->causer?->is($lawyer))->toBeTrue()
        ->and($row->properties->get('matter_id'))->toBe($matter->id);
});

/*
|--------------------------------------------------------------------------
| 4. Công bố tiến độ — `stage_log_published`, tường minh (M8 Task 3)
|--------------------------------------------------------------------------
*/

function publishStageLogThrough(User $lawyer, Matter $matter, string $action, array $data)
{
    test()->actingAs($lawyer, 'web');

    test()->livewire(StageLogsRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
        ->callTableAction($action, data: $data);
}

it('§10.6 records progress publication when a lawyer moves a published matter to a new stage and publishes', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('intake')->create(['lead_lawyer_id' => $lawyer->id, 'is_published_to_portal' => true]);

    publishStageLogThrough($lawyer, $matter, 'transitionStage', [
        'to_stage' => 'collecting_documents',
        'occurred_at' => today()->toDateString(),
        'public_content' => 'Chúng tôi đã tiếp nhận hồ sơ và đang thu thập giấy tờ cần thiết.',
        'publish' => true,
    ]);

    $log = StageLog::query()->where('matter_id', $matter->id)->latest('id')->firstOrFail();
    $row = lastActivity('stage_log_published');

    expect($row)->not->toBeNull()
        ->and($row->subject?->is($log))->toBeTrue()
        ->and($row->causer?->is($lawyer))->toBeTrue()
        ->and($row->properties->get('matter_id'))->toBe($matter->id)
        ->and($row->properties->get('stage_log_id'))->toBe($log->id)
        ->and($row->properties->get('to_stage'))->toBe('collecting_documents');
});

it('§10.6 records progress publication for the add-update button too, which changes no stage', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('intake')->create(['lead_lawyer_id' => $lawyer->id, 'is_published_to_portal' => true]);

    publishStageLogThrough($lawyer, $matter, 'addUpdate', [
        'occurred_at' => today()->toDateString(),
        'public_content' => 'Tuần này chưa có văn bản mới từ toà, đây là điều bình thường ở giai đoạn này.',
        'publish' => true,
    ]);

    $row = lastActivity('stage_log_published');

    expect($row)->not->toBeNull()
        ->and($row->properties->get('same_stage'))->toBeTrue()
        ->and($matter->refresh()->stage)->toBe('intake');
});

it('§10.6 writes no progress publication row when nothing reaches the client', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('intake')->create(['lead_lawyer_id' => $lawyer->id, 'is_published_to_portal' => true]);

    // Không công bố: dòng tiến độ chỉ để nội bộ.
    publishStageLogThrough($lawyer, $matter, 'addUpdate', [
        'occurred_at' => today()->toDateString(),
        'next_step' => 'Tuần này chưa có văn bản mới từ toà, đây là điều bình thường ở giai đoạn này.',
        'publish' => false,
    ]);

    expect(activitiesOf('stage_log_published'))->toHaveCount(0);
});

it('§10.6 hides a progress publication row of a restricted matter from a manager who cannot view it, and shows it to an admin', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->restricted()->atStage('intake')->create([
        'lead_lawyer_id' => $lead->id,
        'is_published_to_portal' => true,
        'code' => 'VU-MAT-9781',
        'title' => 'Vụ mật không được lộ',
    ]);

    publishStageLogThrough($lead, $matter, 'addUpdate', [
        'occurred_at' => today()->toDateString(),
        'public_content' => 'Chúng tôi vừa nộp bổ sung chứng cứ cho toà và đang chờ phản hồi.',
        'publish' => true,
    ]);

    $row = lastActivity('stage_log_published');

    expect($row)->not->toBeNull()
        ->and(ActivityOwningMatter::owningMatterId($row))->toBe($matter->id)
        ->and(ActivityOwningMatter::canView($manager, $row))->toBeFalse()
        ->and(ActivityOwningMatter::canView($admin, $row))->toBeTrue()
        // Dòng không mang mã hay tên vụ: người có quyền xem nhật ký nhưng không xem được vụ không có
        // gì để đọc, và người xem được cũng chỉ thấy id.
        ->and(json_encode($row->properties))->not->toContain('VU-MAT-9781')
        ->and(json_encode($row->properties))->not->toContain('Vụ mật');
});

/*
|--------------------------------------------------------------------------
| 5. Đổi phân quyền — `permission_changed`, kèm cũ → mới (M8 Task 3)
|--------------------------------------------------------------------------
*/

it('§10.6 records a permission change with old and new position and roles when an admin changes a position', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $staff = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $staff->getRouteKey()])
        ->fillForm(['position' => UserPosition::Manager->value])
        ->call('save')
        ->assertHasNoFormErrors();

    $row = lastActivity('permission_changed');

    expect($row)->not->toBeNull()
        ->and($row->subject?->is($staff))->toBeTrue()
        ->and($row->causer?->is($admin))->toBeTrue()
        ->and($row->properties->get('position_from'))->toBe(UserPosition::Lawyer->value)
        ->and($row->properties->get('position_to'))->toBe(UserPosition::Manager->value)
        ->and($row->properties->get('roles_from'))->toBe([Role::Lawyer->value])
        ->and($row->properties->get('roles_to'))->toBe([Role::Manager->value])
        // Dòng `updated` của LogsActivity vẫn đứng cạnh (chấp nhận được, không thay thế).
        ->and(Activity::query()->where('event', 'updated')->where('subject_id', $staff->id)->where('subject_type', 'user')->exists())->toBeTrue();
});

it('§10.6 writes no permission-change row when a staff edit changes only the name', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $staff = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $staff->getRouteKey()])
        ->fillForm(['name' => 'Tên mới của luật sư'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($staff->refresh()->name)->toBe('Tên mới của luật sư')
        ->and(activitiesOf('permission_changed'))->toHaveCount(0);
});

/*
|--------------------------------------------------------------------------
| 6. Tạo / vô hiệu hoá tài khoản portal — tường minh (M8 Task 3)
|--------------------------------------------------------------------------
*/

it('§10.6 records the creation of a portal account made from the create page', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $client = Client::factory()->create();

    $this->actingAs($admin, 'web');

    $this->livewire(CreateClientUser::class)
        ->fillForm([
            'client_id' => $client->id,
            'name' => 'Nguyễn Văn A',
            'email' => 'khach-moi@example.com',
            'password' => 'mat-khau-khoi-tao-1',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $account = ClientUser::query()->where('email', 'khach-moi@example.com')->firstOrFail();
    $row = lastActivity('portal_account_created');

    expect($row)->not->toBeNull()
        ->and($row->subject?->is($account))->toBeTrue()
        ->and($row->causer?->is($admin))->toBeTrue()
        ->and($row->properties->get('client_id'))->toBe($client->id)
        // Không bao giờ ghi mật khẩu, thô hay đã băm.
        ->and(json_encode($row->properties))->not->toContain('mat-khau-khoi-tao-1')
        ->and(json_encode($row->properties))->not->toContain('$2y$');
});

it('§10.6 records the deactivation of a portal account, once, and only when is_active really flips off', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $account = ClientUser::factory()->activated()->create();

    $this->actingAs($admin, 'web');

    // Sửa tên, không đụng is_active: không có dòng vô hiệu hoá.
    $this->livewire(EditClientUser::class, ['record' => $account->getKey()])
        ->fillForm(['name' => 'Tên đã sửa'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(activitiesOf('portal_account_deactivated'))->toHaveCount(0);

    $this->livewire(EditClientUser::class, ['record' => $account->getKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    $row = lastActivity('portal_account_deactivated');

    expect($account->refresh()->is_active)->toBeFalse()
        ->and($row)->not->toBeNull()
        ->and($row->subject?->is($account))->toBeTrue()
        ->and($row->causer?->is($admin))->toBeTrue()
        ->and($row->properties->get('client_id'))->toBe($account->client_id);

    // Lưu lại lần nữa khi đã tắt: không sinh dòng thứ hai cho một việc không xảy ra.
    $this->livewire(EditClientUser::class, ['record' => $account->getKey()])
        ->fillForm(['name' => 'Tên đã sửa lần hai'])
        ->call('save');

    expect(activitiesOf('portal_account_deactivated'))->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| 7. Xuất dữ liệu — tập MỌI đường xuất = {documents.download: tài liệu thường + gói bàn giao} (+ hai bí danh M12)
|--------------------------------------------------------------------------
|
| "Xuất dữ liệu" của SPEC §10.6 là mọi đường để một byte dữ liệu rời hệ thống theo yêu cầu của người
| dùng. Vẫn chỉ MỘT route: `documents.download`. Qua nó có hai thứ rời hệ thống: một tài liệu thường
| (ghi `document_downloaded`) và — từ M7 Task 4 — gói bàn giao hồ sơ, một tệp zip gom các tài liệu
| nhóm A/B/C của cả vụ (ghi THÊM `data_exported`: một lần khi gói sinh xong, một lần mỗi lượt tải).
| Không có Export action của Filament, không có route xuất báo cáo, và bản sao lưu không tải được
| từ app. Hai test đầu đóng băng tập đó ở HAI phía độc lập — router và mã nguồn — để một đường xuất
| MỚI buộc người thêm nó phải tới đây, ghi `data_exported`, và nới test có chủ đích.
|
| Việc sau gộp M7 (làn fu2): ca chỉ kiểm nhãn "sẵn sàng cho đường xuất đầu tiên" được thay bằng ca đi
| hết đường thật — nút "Sinh gói bàn giao" trên trang vụ việc, job của hàng `handover`, rồi route
| tải ký — đúng như ghi chú M8b đã hẹn cho lần gộp M7.
*/

it('§10.6 the only way to take data out of the app is the document download route', function () {
    $exportLike = collect(Route::getRoutes())
        ->filter(fn ($route): bool => preg_match('/download|export|backup|archive|handover|\.zip|\.csv|\.xlsx/i', $route->uri().' '.$route->getName()) === 1)
        ->reject(fn ($route): bool => str_contains($route->uri(), 'livewire-') || str_contains($route->uri(), 'livewire/'))
        ->map(fn ($route): string => (string) ($route->getName() ?: $route->uri()))
        ->sort()
        ->values()
        ->all();

    // Hai route của Filament có mặt ở router nhưng KHÔNG phục vụ được gì: app/ không có Exporter/
    // Importer nào và CSDL không có bảng exports/imports (đã đo ở StaffTwoFactorEscapeRoutesTest).
    // Tập đường xuất THẬT là phần còn lại.
    $inertFilament = ['filament.exports.download', 'filament.imports.failed-rows.download'];

    // M12 Task 3: hai BÍ DANH trong scope của app trên điện thoại (`/admin/…`, `/portal/…`) là CÙNG
    // đường xuất, không phải đường mới — cùng `DocumentDownloadController` (khẳng định ngay dưới),
    // nên cùng dòng `document_downloaded`; hành vi ở tests/Feature/Pwa/DocumentDownloadAliasTest.php.
    expect(array_values(array_diff($exportLike, $inertFilament)))
        ->toBe(['documents.download', 'documents.download.admin', 'documents.download.portal']);

    foreach (['documents.download.admin', 'documents.download.portal'] as $alias) {
        expect(Route::getRoutes()->getByName($alias)->getActionName())
            ->toBe(Route::getRoutes()->getByName('documents.download')->getActionName());
    }
});

it('§10.6 no source file streams a file or data body out except the document download controller', function () {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), RecursiveDirectoryIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $code = (string) file_get_contents($file->getPathname());

        // Chỉ xét mã, không xét chú thích: loại bỏ /* … */, /** … */ và // … trước khi quét.
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);
        $code = (string) preg_replace('#^\s*//.*$#m', '', $code);

        if (preg_match('/->download\(|streamDownload\(|response\(\)->file\(|BinaryFileResponse|StreamedResponse|ExportAction|ExportBulkAction|extends Exporter|extends Importer|->toInlineResponse\(|->toResponse\(/', $code) === 1) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    expect(array_map(fn (string $path): string => str_replace('\\', '/', $path), $offenders))
        ->toBe(['app/Http/Controllers/DocumentDownloadController.php']);
});

/**
 * Đường thật, ba chặng: luật sư bấm "Sinh gói bàn giao" trên trang vụ đã kết thúc (Livewire), job
 * `GenerateHandoverPackage` mà nút xếp lên hàng `handover` chạy như worker chạy nó, rồi luật sư tải gói
 * qua route ký `documents.download`. Mỗi chặng xuất dữ liệu đúng MỘT dòng `data_exported`, có chủ thể
 * (tài liệu gói), người thực hiện, và `action` nói chặng nào. Vế âm cùng test: tải một tài liệu
 * THƯỜNG của cùng vụ không ghi `data_exported`.
 */
it('§10.6 records data_exported when a handover package is generated and when it is downloaded', function () {
    Queue::fake();
    $workRoot = storage_path('framework/testing/handover-'.Str::random(16));
    config(['vkcrm.handover.work_dir' => $workRoot]);

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'closed_at' => now()->subDay()->toDateString()]);
    MatterArchive::factory()->create(['matter_id' => $matter->id, 'archived_by' => $lawyer->id]);

    $ordinary = Document::factory()->for($matter)->group(DocumentGroup::Issued)->create(['status' => DocumentStatus::SignedFiled]);
    $ordinary->addMedia(UploadedFile::fake()->createWithContent('ban-an.pdf', '%PDF-1.4 noi dung'))->toMediaCollection('file');

    try {
        $this->actingAs($lawyer, 'web');

        $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
            ->callAction('generateHandoverPackage')
            ->assertHasNoActionErrors();

        expect(activitiesOf('data_exported'))->toHaveCount(0);

        // Worker chạy job mà nút vừa xếp.
        $job = Queue::pushed(GenerateHandoverPackage::class)->sole();
        app()->call([$job, 'handle']);

        $package = Document::query()->findOrFail(MatterArchive::query()->where('matter_id', $matter->id)->value('handover_document_id'));
        $generated = activitiesOf('data_exported')->sole();

        expect($generated->subject?->is($package))->toBeTrue()
            ->and($generated->causer?->is($lawyer))->toBeTrue()
            ->and($generated->properties->get('action'))->toBe('generated')
            ->and($generated->properties->get('kind'))->toBe('handover_package')
            ->and($generated->properties->get('matter_id'))->toBe($matter->id);

        $this->get($ordinary->fresh()->downloadUrlFor($lawyer))->assertOk();

        expect(activitiesOf('data_exported'))->toHaveCount(1);

        $this->get($package->downloadUrlFor($lawyer))->assertOk();

        $downloaded = activitiesOf('data_exported')->last();

        expect(activitiesOf('data_exported'))->toHaveCount(2)
            ->and($downloaded->subject?->is($package))->toBeTrue()
            ->and($downloaded->causer?->is($lawyer))->toBeTrue()
            ->and($downloaded->properties->get('action'))->toBe('downloaded')
            ->and(__('activity.events.data_exported'))->toBe('Xuất dữ liệu');
    } finally {
        File::deleteDirectory($workRoot);
    }
});
