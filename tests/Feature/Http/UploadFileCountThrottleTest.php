<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\User;
use App\Support\UploadThrottle;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;
use Livewire\Features\SupportTesting\Testable;

/**
 * SPEC §10.3 (M8 Task 3) — "nộp tài liệu 20 TỆP / giờ / tài khoản": endpoint tải lên đếm TỆP,
 * không đếm request.
 *
 * Mọi test đi qua **HTTP thật, đúng URL đã ký** của `livewire.upload-file` — không phải
 * `Testable::upload()` (gọi thẳng `validateAndStore()`, đi vòng cả route lẫn middleware). Bộ đếm
 * nằm ở middleware của route, nên chỉ đo được từ đây. Một request mang `files[]` nhiều tệp đúng
 * như Livewire gửi khi ô chọn tệp có `multiple()`.
 *
 * Trước M8 Task 3 bộ đếm là `throttle:livewire-upload` của framework: MỘT đơn vị cho mỗi request.
 * Test đầu dưới đây đỏ trên bản đó (5 tệp trong một request chỉ tốn 1 suất), và đó là bằng chứng.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);
    $this->staff = User::factory()->withRole(Role::Lawyer)->create();
});

function uploadEndpointUrl(): string
{
    FileUploadConfiguration::storage();

    return app(GenerateSignedUploadUrl::class)->forLocal();
}

/**
 * POST `$files` tệp nhỏ trong MỘT request, dưới danh nghĩa `$actor`, và **giữ guard mặc định là
 * `web` như production** (`actingAs()` đổi guard mặc định; xem `submitPostBytesAs()` ở
 * `SubmitDocumentTest` cho lý do đầy đủ).
 */
function uploadFilesAs(ClientUser|User $actor, int $files, ?string $url = null): TestResponse
{
    // Chỉ MỘT guard có người trong mỗi request (một trình duyệt thật đăng nhập một panel): nếu
    // guard kia còn nhớ người của lần gọi trước, `UploadThrottle::keyFor()` — nhân sự trước, khách
    // sau, cùng thứ tự với `Audit::record()` — sẽ lấy nhầm người đó làm chủ khoá.
    auth($actor instanceof User ? 'client' : 'web')->forgetUser();

    test()->actingAs($actor, $actor instanceof User ? 'web' : 'client');
    app('auth')->shouldUse('web');

    return test()->post(
        $url ?? uploadEndpointUrl(),
        ['files' => array_map(
            fn (int $i): UploadedFile => UploadedFile::fake()->create("giay-to-{$i}.jpg", 1, 'image/jpeg'),
            range(1, $files),
        )],
        ['Accept' => 'application/json'],
    );
}

function uploadCounter(ClientUser|User $actor): int
{
    return RateLimiter::attempts(UploadThrottle::cacheKeyFor(Document::recipientToken($actor)));
}

it('§10.3 counts FILES, not requests: one POST carrying five files spends five of the twenty', function () {
    expect(uploadFilesAs($this->clientUser, 5)->status())->toBe(200)
        ->and(uploadCounter($this->clientUser))->toBe(5);
});

it('§10.3 lets a client upload exactly twenty files an hour across requests, then refuses the twenty-first', function () {
    $url = uploadEndpointUrl();

    foreach ([5, 5, 5, 4] as $files) {
        expect(uploadFilesAs($this->clientUser, $files, $url)->status())->toBe(200);
    }

    // 19 rồi: đúng một tệp nữa còn qua được...
    expect(uploadCounter($this->clientUser))->toBe(19)
        ->and(uploadFilesAs($this->clientUser, 1, $url)->status())->toBe(200)
        // ...và tệp thứ 21 thì không.
        ->and(uploadFilesAs($this->clientUser, 1, $url)->status())->toBe(429);
});

it('§10.3 refuses a whole request whose files would cross the limit, and spends nothing on it', function () {
    $url = uploadEndpointUrl();

    expect(uploadFilesAs($this->clientUser, 15, $url)->status())->toBe(200);

    // Lô 6 tệp làm 15 + 6 = 21 > 20: CẢ LÔ bị từ chối, không nhận 5 tệp rồi cắt tệp thứ 6.
    $refused = uploadFilesAs($this->clientUser, 6, $url);

    expect($refused->status())->toBe(429)
        ->and(uploadCounter($this->clientUser))->toBe(15)
        ->and((int) $refused->headers->get('Retry-After'))->toBeGreaterThan(3500)
        ->and((int) $refused->headers->get('Retry-After'))->toBeLessThanOrEqual(3600);

    // Suất còn lại (5) vẫn dùng được nguyên vẹn ngay sau đó — lô bị từ chối không tiêu gì.
    expect(uploadFilesAs($this->clientUser, 5, $url)->status())->toBe(200)
        ->and(uploadCounter($this->clientUser))->toBe(20);
});

it('§10.3 refuses one request carrying more files than the hourly limit even for a fresh account', function () {
    expect(uploadFilesAs($this->clientUser, 21)->status())->toBe(429)
        ->and(uploadCounter($this->clientUser))->toBe(0);
});

it('§10.3 gives every account its own counter even on one address', function () {
    $spouse = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);
    $url = uploadEndpointUrl();

    expect(uploadFilesAs($this->clientUser, 20, $url)->status())->toBe(200)
        ->and(uploadFilesAs($this->clientUser, 1, $url)->status())->toBe(429)
        // Người thứ hai của cùng khách hàng, cùng đường truyền, vẫn còn nguyên mức của mình.
        ->and(uploadFilesAs($spouse, 20, $url)->status())->toBe(200);
});

/**
 * Phán quyết "có áp cho nhân sự không": 20 tệp/giờ là luật nộp tài liệu của KHÁCH; nhân sự có trần
 * riêng 200 trên cùng endpoint. Hai vế: nhân sự KHÔNG bị chặn ở 20 (vế mà luật sư tải bộ hồ sơ toà
 * cần), và VẪN bị chặn ở 200 (không bỏ trần — mỗi POST ghi đĩa).
 */
it('§10.3 lets staff upload far more than a client an hour, up to a ceiling of two hundred files', function () {
    $url = uploadEndpointUrl();

    // 30 tệp trong MỘT request: gấp rưỡi trần của khách, vẫn qua.
    expect(uploadFilesAs($this->staff, 30, $url)->status())->toBe(200);

    for ($request = 0; $request < 8; $request++) {
        expect(uploadFilesAs($this->staff, 20, $url)->status())->toBe(200);
    }

    // 30 + 8×20 = 190. Còn đúng 10 tệp: lô 11 tệp bị từ chối cả lô, lô 10 tệp thì qua.
    expect(uploadCounter($this->staff))->toBe(190)
        ->and(uploadFilesAs($this->staff, 11, $url)->status())->toBe(429)
        ->and(uploadFilesAs($this->staff, 10, $url)->status())->toBe(200)
        ->and(uploadCounter($this->staff))->toBe(UploadThrottle::STAFF_FILES_PER_HOUR)
        ->and(uploadFilesAs($this->staff, 1, $url)->status())->toBe(429);
});

it('§10.3 keeps the staff counter and the client counter apart', function () {
    $url = uploadEndpointUrl();

    expect(uploadFilesAs($this->staff, 20, $url)->status())->toBe(200)
        // Nhân sự đã tải 20 tệp, khách vẫn còn nguyên 20 của mình.
        ->and(uploadFilesAs($this->clientUser, 20, $url)->status())->toBe(200)
        ->and(uploadFilesAs($this->clientUser, 1, $url)->status())->toBe(429)
        // Và nhân sự vẫn tải tiếp được.
        ->and(uploadFilesAs($this->staff, 1, $url)->status())->toBe(200);
});

it('§10.3 still refuses a visitor with nobody signed in, keyed by address', function () {
    $url = uploadEndpointUrl();
    $post = fn (int $files): TestResponse => test()->post(
        $url,
        ['files' => array_map(fn (int $i): UploadedFile => UploadedFile::fake()->create("a{$i}.jpg", 1, 'image/jpeg'), range(1, $files))],
        ['Accept' => 'application/json'],
    );

    expect($post(20)->status())->toBe(200)
        ->and($post(1)->status())->toBe(429);
});

/*
|--------------------------------------------------------------------------
| Fix round 1 (F2) — lời từ chối 429 của nhân sự phải bằng tiếng Việt và nói rõ lý do
|--------------------------------------------------------------------------
|
| JS của Livewire gọi `_uploadErrored` với `errors = null` cho MỌI mã khác 422, nên với một 429 thì
| component không có gì để đọc; bản gốc chỉ ném "The … failed to upload" — không lý do, không thời
| gian chờ — và luật sư bấm thử lại mãi. Bản của khách (`SubmitDocument`) đã có câu riêng; nhân sự
| (tab "Tài liệu" của hồ sơ, ô tải tệp duy nhất của panel /admin) thì chưa. Test lái bằng một 429
| THẬT từ endpoint rồi gọi `_uploadErrored` với `null`, đúng như trình duyệt làm.
*/
function staffUploadModal(User $staff): Testable
{
    $matter = Matter::factory()->create(['lead_lawyer_id' => $staff->id]);
    Filament::setCurrentPanel('admin');

    test()->actingAs($staff, 'web');

    return test()->livewire(DocumentsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->mountAction(TestAction::make('upload')->table());
}

function staffUploadStatePath(): string
{
    return 'mountedActions.0.data.file';
}

/** Đẩy nhân sự tới đúng trần 200 tệp/giờ rồi lấy một lời từ chối 429 thật. */
function staffUploadRefused(User $staff): TestResponse
{
    $url = uploadEndpointUrl();

    for ($request = 0; $request < 10; $request++) {
        expect(uploadFilesAs($staff, 20, $url)->status())->toBe(200);
    }

    $refusal = uploadFilesAs($staff, 1, $url);

    expect($refusal->status())->toBe(429);

    return $refusal;
}

it('§10.3 tells a lawyer in Vietnamese that the hourly file ceiling was hit, with the number and the wait, instead of a bare upload failure', function () {
    staffUploadRefused($this->staff);

    $message = staffUploadModal($this->staff)
        // TRUYỀN `null`, ĐÚNG NHƯ TRÌNH DUYỆT LÀM cho mọi mã khác 422.
        ->call('_uploadErrored', staffUploadStatePath(), null, false)
        ->errors()->first(staffUploadStatePath());

    expect($message)
        ->toBe(__('documents.errors.staff_upload_rate_limited', [
            'limit' => UploadThrottle::STAFF_FILES_PER_HOUR,
            'minutes' => 60,
        ]))
        ->toContain((string) UploadThrottle::STAFF_FILES_PER_HOUR)
        ->toContain('phút')
        // Không mượn câu của khách: không số điện thoại văn phòng, không "sóng"/"HDR".
        ->not->toContain((string) config('vkcrm.brand.hotline'))
        ->not->toContain('failed to upload')
        ->not->toContain('sóng');
});

it('§10.3 also names the ceiling when a batch that would cross it was refused although the counter is not full', function () {
    $url = uploadEndpointUrl();

    // 195/200: còn 5 suất, đủ để một tệp qua nhưng một lô 6 tệp thì bị từ chối cả lô (429) mà bộ đếm
    // KHÔNG đầy — chỉ dấu "vừa từ chối" cho biết.
    foreach ([20, 20, 20, 20, 20, 20, 20, 20, 20, 15] as $files) {
        expect(uploadFilesAs($this->staff, $files, $url)->status())->toBe(200);
    }

    expect(uploadFilesAs($this->staff, 6, $url)->status())->toBe(429)
        ->and(uploadCounter($this->staff))->toBe(195);

    $message = staffUploadModal($this->staff)
        ->call('_uploadErrored', staffUploadStatePath(), null, false)
        ->errors()->first(staffUploadStatePath());

    expect($message)->toContain((string) UploadThrottle::STAFF_FILES_PER_HOUR)->toContain('phút');
});

it('§10.3 leaves the framework message alone when the upload failed for some other reason and no ceiling was hit', function () {
    $message = staffUploadModal($this->staff)
        ->call('_uploadErrored', staffUploadStatePath(), null, false)
        ->errors()->first(staffUploadStatePath());

    expect($message)->not->toBeNull()
        ->and($message)->not->toContain('phút')
        ->and($message)->not->toBe(__('documents.errors.staff_upload_rate_limited', [
            'limit' => UploadThrottle::STAFF_FILES_PER_HOUR,
            'minutes' => 60,
        ]));
});

it('§10.3 keeps a real validation failure (422) as the validation message even right after a ceiling refusal', function () {
    staffUploadRefused($this->staff);

    $errors = json_encode(['errors' => ['files.0' => ['Tệp này quá lớn.']]]);

    $message = staffUploadModal($this->staff)
        ->call('_uploadErrored', staffUploadStatePath(), $errors, false)
        ->errors()->first(staffUploadStatePath());

    expect($message)->toBe('Tệp này quá lớn.');
});

it('§10.3 does not tell one staff member about a ceiling that a colleague hit', function () {
    $colleague = User::factory()->withRole(Role::Lawyer)->create();

    staffUploadRefused($colleague);

    $message = staffUploadModal($this->staff)
        ->call('_uploadErrored', staffUploadStatePath(), null, false)
        ->errors()->first(staffUploadStatePath());

    expect($message)->not->toContain('phút');
});

it('§10.3 still names the ceiling after the "just refused" mark has expired, as long as the counter itself is full', function () {
    staffUploadRefused($this->staff);

    // Dấu "vừa từ chối" chỉ sống 60 giây; bộ đếm thì sống cả giờ.
    Cache::forget(UploadThrottle::cacheKeyFor(Document::recipientToken($this->staff)).':refused');

    expect(UploadThrottle::wasRecentlyRefused(Document::recipientToken($this->staff)))->toBeFalse();

    $message = staffUploadModal($this->staff)
        ->call('_uploadErrored', staffUploadStatePath(), null, false)
        ->errors()->first(staffUploadStatePath());

    expect($message)->toContain((string) UploadThrottle::STAFF_FILES_PER_HOUR)->toContain('phút');
});

it('§10.3 judges a staff member against the staff ceiling, not the client one, so 30 files an hour is no refusal', function () {
    expect(uploadFilesAs($this->staff, 30)->status())->toBe(200)
        ->and(uploadCounter($this->staff))->toBe(30);

    // 30 > 20 (trần của khách) nhưng < 200 (trần của nhân sự): một lỗi tệp khác không được đọc là hết suất.
    $message = staffUploadModal($this->staff)
        ->call('_uploadErrored', staffUploadStatePath(), null, false)
        ->errors()->first(staffUploadStatePath());

    expect($message)->not->toContain('phút');
});
