<?php

use App\Enums\PreflightLevel;
use App\Support\Storage\CredentialFileInspector;
use App\Support\Storage\HttpTransportAvailability;
use Illuminate\Support\Facades\Http;
use Tests\Support\DocumentStoreFixtures as Store;
use Tests\Support\FakeCredentialFile;

/*
|--------------------------------------------------------------------------
| M14 Task 5 — ba dòng sẵn sàng không cần mạng: công tắc, khoá dịch vụ, HTTP client (R6, R7)
|--------------------------------------------------------------------------
|
| Mỗi vế ĐỎ/VÀNG/XANH đi qua CẢ HAI đường của kế hoạch: `StorageReadiness::rows()` và lệnh
| `vkcrm:storage:check` (`Store::expectRow()`), với `APP_ENV=testing` — kiểm tra sẵn sàng chạy ở mọi
| môi trường, không chỉ production.
|
| Không test nào ở đây chạm Google: chưa có mã Shared Drive nên các dòng mạng tự báo "chưa cấu
| hình" mà không gửi gì; `Http::preventStrayRequests()` biến một lời gọi lạc thành lỗi.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();

    config([
        'vkcrm.storage.driver' => 'local',
        'vkcrm.storage.google_drive.credentials_path' => '/etc/vkcrm/google-drive-key.json',
        'vkcrm.storage.google_drive.shared_drive_id' => null,
        'vkcrm.storage.google_drive.root_folder_id' => null,
    ]);

    app()->instance(CredentialFileInspector::class, new FakeCredentialFile);
});

function t5UseKey(FakeCredentialFile $file, ?string $path = null): void
{
    app()->instance(CredentialFileInspector::class, $file);

    if ($path !== null) {
        config(['vkcrm.storage.google_drive.credentials_path' => $path]);
    }
}

// ---------------------------------------------------------------------------------------------
// document_storage_driver (R7)
// ---------------------------------------------------------------------------------------------

it('document_storage_driver: local và google_drive là XANH', function (string $driver) {
    config(['vkcrm.storage.driver' => $driver]);

    Store::expectRow('document_storage_driver', PreflightLevel::Green);
})->with(['local', 'google_drive']);

it('document_storage_driver: giá trị gõ sai là ĐỎ và câu nêu đúng giá trị đó', function () {
    config(['vkcrm.storage.driver' => 'gooogle_drive']);

    $row = Store::expectRow('document_storage_driver', PreflightLevel::Red);

    expect($row['message'])->toContain('gooogle_drive');
});

// ---------------------------------------------------------------------------------------------
// drive_credentials (R6) — ĐỎ
// ---------------------------------------------------------------------------------------------

it('drive_credentials: đường dẫn trống là ĐỎ', function (?string $path) {
    config(['vkcrm.storage.google_drive.credentials_path' => $path]);

    Store::expectRow('drive_credentials', PreflightLevel::Red);
})->with(['null' => null, 'chuỗi rỗng' => '']);

it('drive_credentials: tệp không có là ĐỎ', function () {
    t5UseKey(new FakeCredentialFile(exists: false));

    $row = Store::expectRow('drive_credentials', PreflightLevel::Red);

    expect($row['message'])->toContain(__('document_store.readiness.credentials_not_found', ['path' => '/etc/vkcrm/google-drive-key.json']));
});

it('drive_credentials: tệp có mà PHP không đọc được là ĐỎ', function () {
    t5UseKey(new FakeCredentialFile(readable: false));

    $row = Store::expectRow('drive_credentials', PreflightLevel::Red);

    expect($row['message'])->toContain(__('document_store.readiness.credentials_unreadable', ['path' => '/etc/vkcrm/google-drive-key.json']));
});

/*
 * Thư mục gốc của ứng dụng được đặt qua bản giả: `base_path()` thật của container (`/var/www/html`) có
 * đoạn `www`, nên luật "thư mục gốc web" sẽ trả lời thay cho luật `base_path()`.
 */
const T5_ROOTS = ['base' => '/srv/vkcrm', 'public' => '/srv/vkcrm-web'];

it('drive_credentials: tệp nằm dưới base_path() là ĐỎ', function () {
    t5UseKey(new FakeCredentialFile(roots: T5_ROOTS), '/srv/vkcrm/storage/app/google-drive-key.json');

    $row = Store::expectRow('drive_credentials', PreflightLevel::Red);

    expect($row['message'])->toContain('/srv/vkcrm/storage/app/google-drive-key.json');
});

it('drive_credentials: tệp nằm dưới public_path() (gốc web ngoài thư mục mã nguồn) là ĐỎ', function () {
    t5UseKey(new FakeCredentialFile(roots: T5_ROOTS), '/srv/vkcrm-web/google-drive-key.json');

    Store::expectRow('drive_credentials', PreflightLevel::Red);
});

it('drive_credentials: đường dẫn khai ở ngoài nhưng là symlink trỏ vào base_path() là ĐỎ', function () {
    t5UseKey(new FakeCredentialFile(roots: T5_ROOTS, resolved: '/srv/vkcrm/storage/google-drive-key.json'), '/etc/vkcrm/google-drive-key.json');

    Store::expectRow('drive_credentials', PreflightLevel::Red);
});

it('drive_credentials: cạnh thư mục mã nguồn (tiền tố chung, không phải thư mục con) không bị coi là bên trong', function () {
    t5UseKey(new FakeCredentialFile(roots: T5_ROOTS), '/srv/vkcrm-keys/google-drive-key.json');

    Store::expectRow('drive_credentials', PreflightLevel::Green);
});

it('drive_credentials: lớp thật hỏi đúng base_path() và public_path() của ứng dụng', function () {
    expect((new CredentialFileInspector)->applicationRoots())->toBe(['base' => base_path(), 'public' => public_path()]);
});

it('drive_credentials: tệp dưới một thư mục gốc web của shared hosting là ĐỎ', function (string $directory) {
    t5UseKey(new FakeCredentialFile, "/home/vkcrm/{$directory}/keys/google-drive-key.json");

    Store::expectRow('drive_credentials', PreflightLevel::Red);
})->with(['public_html', 'www', 'htdocs']);

it('drive_credentials: tên thư mục chỉ CHỨA public_html (public_html_old) không phải gốc web', function () {
    t5UseKey(new FakeCredentialFile, '/home/vkcrm/public_html_old/google-drive-key.json');

    Store::expectRow('drive_credentials', PreflightLevel::Green);
});

it('drive_credentials: quyền 0644 (người khác đọc được) là ĐỎ', function () {
    t5UseKey(new FakeCredentialFile(mode: 0o644));

    $row = Store::expectRow('drive_credentials', PreflightLevel::Red);

    expect($row['message'])->toContain('0644');
});

it('drive_credentials: quyền 0602 (người khác GHI được) là ĐỎ', function () {
    t5UseKey(new FakeCredentialFile(mode: 0o602));

    Store::expectRow('drive_credentials', PreflightLevel::Red);
});

it('drive_credentials: quyền 0460 (nhóm ghi được) là ĐỎ', function () {
    t5UseKey(new FakeCredentialFile(mode: 0o460));

    Store::expectRow('drive_credentials', PreflightLevel::Red);
});

it('drive_credentials: JSON không đúng khoá tài khoản dịch vụ là ĐỎ', function (array $json) {
    t5UseKey(new FakeCredentialFile(json: (string) json_encode($json)));

    Store::expectRow('drive_credentials', PreflightLevel::Red);
})->with([
    'thiếu private_key' => [fn () => array_diff_key(FakeCredentialFile::validKey(), ['private_key' => true])],
    'private_key rỗng' => [fn () => ['private_key' => ''] + FakeCredentialFile::validKey()],
    'thiếu client_email' => [fn () => array_diff_key(FakeCredentialFile::validKey(), ['client_email' => true])],
    'type không phải service_account' => [fn () => ['type' => 'authorized_user'] + FakeCredentialFile::validKey()],
]);

it('drive_credentials: tệp không phải JSON là ĐỎ', function () {
    t5UseKey(new FakeCredentialFile(json: 'khong phai json'));

    Store::expectRow('drive_credentials', PreflightLevel::Red);
});

// ---------------------------------------------------------------------------------------------
// drive_credentials (R6) — VÀNG: nhóm đọc được mà không chứng minh được nhóm là riêng
// ---------------------------------------------------------------------------------------------

it('drive_credentials: 0440 với nhóm có thành viên khác là VÀNG', function () {
    t5UseKey(new FakeCredentialFile(mode: 0o440, members: ['vkcrm', 'nguyenvana']));

    $row = Store::expectRow('drive_credentials', PreflightLevel::Yellow);

    expect($row['message'])->toContain('nguyenvana');
});

it('drive_credentials: 0440 khi thiếu extension posix là VÀNG, câu nói đúng lý do', function () {
    t5UseKey(new FakeCredentialFile(mode: 0o440, posix: false));

    $row = Store::expectRow('drive_credentials', PreflightLevel::Yellow);

    expect($row['message'])->toContain(__('document_store.readiness.group_no_posix'));
});

it('drive_credentials: 0440 với nhóm của tệp khác nhóm của tiến trình PHP là VÀNG', function () {
    t5UseKey(new FakeCredentialFile(mode: 0o440, fileGroup: 1001, processGroup: 33));

    Store::expectRow('drive_credentials', PreflightLevel::Yellow);
});

it('drive_credentials: 0440 khi không đọc được danh sách thành viên nhóm là VÀNG', function () {
    t5UseKey(new FakeCredentialFile(mode: 0o440, members: null));

    Store::expectRow('drive_credentials', PreflightLevel::Yellow);
});

// ---------------------------------------------------------------------------------------------
// drive_credentials (R6) — XANH
// ---------------------------------------------------------------------------------------------

it('drive_credentials: 0400 và 0600 của chính người dùng chạy PHP là XANH', function (int $mode) {
    t5UseKey(new FakeCredentialFile(mode: $mode, posix: false));

    Store::expectRow('drive_credentials', PreflightLevel::Green);
})->with(['0400' => 0o400, '0600' => 0o600]);

it('drive_credentials: 0440 và 0640 với nhóm riêng là XANH', function (int $mode, array $members) {
    t5UseKey(new FakeCredentialFile(mode: $mode, members: $members));

    Store::expectRow('drive_credentials', PreflightLevel::Green);
})->with([
    '0440, nhóm không thành viên phụ' => [0o440, []],
    '0640, nhóm chỉ có chính người dùng' => [0o640, ['vkcrm']],
]);

/**
 * Lớp thật trên một tệp thật: khoá `0600` trong thư mục tạm của hệ thống (ngoài `base_path()`), chủ là
 * người dùng của tiến trình test. Đo rằng `CredentialFileInspector` mặc định đọc đúng quyền và nội
 * dung, không chỉ bản giả.
 */
it('drive_credentials: lớp kiểm thật trên một tệp 0600 thật là XANH, cùng tệp 0644 là ĐỎ', function () {
    app()->forgetInstance(CredentialFileInspector::class);

    $path = sys_get_temp_dir().'/vkcrm-t5-key-'.getmypid().'.json';
    file_put_contents($path, json_encode(FakeCredentialFile::validKey()));
    config(['vkcrm.storage.google_drive.credentials_path' => $path]);

    try {
        chmod($path, 0600);
        Store::expectRow('drive_credentials', PreflightLevel::Green);

        chmod($path, 0644);
        Store::expectRow('drive_credentials', PreflightLevel::Red);
    } finally {
        @unlink($path);
    }
});

// ---------------------------------------------------------------------------------------------
// drive_http_client (Phụ lục A, kế hoạch Task 5)
// ---------------------------------------------------------------------------------------------

function t5HttpTransport(bool $curl, bool $urlFopen): void
{
    app()->instance(HttpTransportAvailability::class, new class($curl, $urlFopen) extends HttpTransportAvailability
    {
        public function __construct(private bool $hasCurl, private bool $hasUrlFopen) {}

        public function curl(): bool
        {
            return $this->hasCurl;
        }

        public function urlFopen(): bool
        {
            return $this->hasUrlFopen;
        }
    });
}

it('drive_http_client: thiếu cả curl lẫn allow_url_fopen là ĐỎ', function () {
    t5HttpTransport(false, false);

    Store::expectRow('drive_http_client', PreflightLevel::Red);
});

it('drive_http_client: có một trong hai là XANH', function (bool $curl, bool $urlFopen) {
    t5HttpTransport($curl, $urlFopen);

    Store::expectRow('drive_http_client', PreflightLevel::Green);
})->with([
    'chỉ curl' => [true, false],
    'chỉ allow_url_fopen' => [false, true],
]);

it('drive_http_client: lớp thật trong container test có curl, nên XANH', function () {
    Store::expectRow('drive_http_client', PreflightLevel::Green);
});

// ---------------------------------------------------------------------------------------------
// Lệnh: mã thoát như vkcrm:preflight, hai nhóm dòng, mọi APP_ENV
// ---------------------------------------------------------------------------------------------

it('vkcrm:storage:check thoát khác 0 khi có dòng ĐỎ (ở đây: chưa có mã Shared Drive)', function () {
    [$exit, $output] = Store::check();

    expect(config('app.env'))->toBe('testing')
        ->and($exit)->not->toBe(0)
        ->and($output)->toContain(__('document_store.check.readiness_heading'))
        ->and($output)->toContain(__('document_store.check.state_heading'))
        ->and($output)->toContain(__('preflight.summary_red'));

    Http::assertNothingSent();
});
