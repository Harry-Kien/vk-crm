<?php

use App\Models\Document;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Đĩa `private` là đĩa GIẢ trong MỌI test, không phải trong những test nhớ gọi
|--------------------------------------------------------------------------
|
| `storage/app/private` là kho hồ sơ thật của máy đang chạy (SPEC §10.4). Một test ghi vào đó
| để lại tệp vĩnh viễn: không `RefreshDatabase` nào dọn đĩa, nên sau vài trăm lần chạy kho hồ sơ
| có hàng nghìn tệp rác nằm CÙNG thư mục `{media.id}/` với tệp của dữ liệu mẫu — không phân biệt
| được bằng mắt, và chỉ phân biệt được bằng cách đối chiếu với bảng `media`.
|
| Việc này đã xảy ra HAI lần trên nhánh M4, và cả hai lần đều theo cùng một hình dạng: một test
| gọi `$this->seed()` (tức `MatterSeeder`, thứ từ `d5a168e` đưa tệp thật vào qua hai Action nộp
| tệp) mà quên `Storage::fake('private')` ở `beforeEach` của CHÍNH tệp test đó. Lần thứ nhất
| được vá bằng cách thêm dòng gọi vào tệp test; vá như vậy là giao một bất biến của cả bộ test
| cho trí nhớ của người viết tệp test tiếp theo, và lần thứ hai chứng minh trí nhớ đó không đủ.
|
| Nên đĩa giả được đặt ở ĐÂY, một chỗ, cho mọi test. Hai lý do chọn phòng thay vì dò:
|
|  - một test khẳng định "không test nào ghi ra ngoài gốc giả" chỉ đỏ SAU KHI kho hồ sơ thật đã
|    bị ghi vào — nó báo cái đã xảy ra, không ngăn nó xảy ra;
|  - và nó không ngăn được lần chạy `--filter` của người đang sửa một tệp test khác.
|
| `Storage::fake()` gọi lại lần nữa trong `beforeEach` của một tệp test (nhiều tệp vẫn gọi, và
| chúng được giữ nguyên) là vô hại: nó chỉ dựng lại cùng cái gốc ấy một lần nữa, trước khi thân
| test chạy.
|
| Nhân chứng để dòng này không bị gỡ đi trong im lặng:
| `tests/Feature/Storage/PrivateDiskTest.php`, test "đĩa private trong test luôn là đĩa giả" —
| tệp đó KHÔNG tự gọi `Storage::fake()`, nên nó đọc đúng cái hook này đặt ra.
|
| `config('filesystems.disks.private.root')` KHÔNG bị `Storage::fake()` đổi (nó chỉ thay
| instance đã phân giải trong `FilesystemManager`), nên các test đọc cấu hình đĩa ở
| `PrivateDiskTest` vẫn nói về đĩa thật.
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // Một hook duy nhất: Pest giữ MỘT `beforeEach` toàn cục cho mỗi khối `extend()` (gọi lần hai là ghi đè).
    // M13 R11: số của "Hiệu suất theo kỳ" và trang một người giữ tạm theo người xem tới 5 phút trên máy
    // thật (`App\Support\Performance\PerformanceCache`). Trong test thì TẮT, để mọi test "đọc, đổi dữ liệu,
    // đọc lại" (R19, các lượt quét rò rỉ, nghiệm thu) đo phép tính chứ không đo bộ nhớ tạm — bật thì lần đọc
    // thứ hai trả lại số của lần đầu và các test đó xanh vô nghĩa. `PerformanceCacheTest` bật lại và đo nó.
    ->beforeEach(function () {
        Storage::fake('private');
        config(['vkcrm.performance.cache_seconds' => 0]);
    })
    ->in('Feature');

pest()->extend(TestCase::class)
    ->beforeEach(fn () => Storage::fake('private'))
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Gốc đĩa giả RIÊNG cho từng lần chạy bộ test
|--------------------------------------------------------------------------
|
| `Storage::fake('private')` dọn sạch `storage/framework/testing/disks/private` — MỘT thư mục,
| dùng chung cho mọi tiến trình. Hai lần chạy bộ test đồng thời trong cùng một container (hai
| người, hai agent, một cửa sổ chạy `--filter` trong khi cửa sổ kia chạy cả bộ) vì thế xoá tệp
| của nhau NGAY GIỮA một test: một tệp vừa được ghi xong biến mất trước khi route tải đọc nó, và
| kết quả là một 404 hoặc một phản hồi rỗng ở một test không liên quan gì tới nhau. Đo được
| nhiều lần trên nhánh này, lần nào cũng ở một test khác nhau, và lần nào cũng xanh khi chạy một
| mình — đúng chữ ký của một tài nguyên dùng chung chứ không phải một lỗi trong mã sản phẩm.
|
| `Storage::fake()` đã có sẵn chỗ để cắm: nó nối `_test_{token}` vào gốc khi
| `ParallelTesting::token()` trả về một giá trị, và hàm đó đọc `$_SERVER['TEST_TOKEN']` khi không
| có resolver nào được đặt. Cắm mã tiến trình vào đó cho mỗi lần chạy một cây thư mục riêng.
|
| Đặt bằng superglobal chứ không bằng `ParallelTesting::resolveTokenUsing()`: tệp này được nạp
| TRƯỚC khi ứng dụng khởi động, nên một facade ở đây chưa có gì để phân giải.
|
| Đây KHÔNG bật chế độ chạy song song của Laravel: `ParallelTesting::inParallel()` đòi
| `$_SERVER['LARAVEL_PARALLEL_TESTING']`, và mọi thứ nó đổi tên theo token (cơ sở dữ liệu, cache,
| view) đều nằm sau `whenRunningInParallel()`. Chỉ `Storage::fake()` đọc token vô điều kiện.
|
| Việc này KHÔNG thay thế `media-library.prefix` riêng cho từng test ở `DocumentDownloadTest` và
| `DocumentsRelationManagerTest`: cái đó chữa một vấn đề khác, trong CÙNG một lần chạy (mọi test
| ghi vào đúng đường dẫn `{media.id}/{file_name}` vì `RefreshDatabase` trả id về 1, nên chuỗi
| xoá-tạo-lại-ghi lặp lại trên một đường dẫn duy nhất). Hai lớp, hai nguyên nhân.
|
| Thư mục được dọn khi tiến trình kết thúc để `storage/framework/testing` không phình ra theo số
| lần chạy.
*/
/*
|--------------------------------------------------------------------------
| Dựng một dòng nhóm D "nói dối", bằng cách đi VÒNG QUA model
|--------------------------------------------------------------------------
|
| Hook `saving` của `App\Models\Document` hạ cả `client_can_view` lẫn `client_can_download` về
| false trên mọi dòng nhóm D (SPEC §4.11 "vĩnh viễn false"). Hệ quả cho bộ test: một fixture
| `Document::factory()->group(D)->create(['client_can_view' => true])` KHÔNG dựng ra được dòng nó
| định dựng — nó lưu ra một dòng `false`, và mọi khẳng định "khách vẫn không thấy" sau đó xanh
| nhờ cái cờ, không nhờ ĐIỀU KIỆN NHÓM mà test có mặt để canh.
|
| Nên những test muốn cô lập điều kiện nhóm phải ghi thẳng vào bảng. Đó cũng đúng là tình huống
| mà ba tầng phòng thủ của SPEC §11 tồn tại để chặn: một dòng đã lọt vào cơ sở dữ liệu bằng một
| đường không đi qua model (`DB::table()->update()`, một lần sửa tay, một migration cũ).
*/
/*
|--------------------------------------------------------------------------
| Biến màu CSS mà panel THẬT SỰ đăng ký
|--------------------------------------------------------------------------
|
| Dự án không có bước dựng CSS (CLAUDE.md), nên mọi mảng màu viết tay trong mã PHP phải đi qua
| biến CSS của Filament (`var(--gray-500)`, `var(--primary-500)`, …) chứ không qua lớp tiện ích
| Tailwind. Hai hàm dưới đây biến câu đó thành một phép ĐO thay vì một khẳng định chuỗi: lấy từng
| tên biến ra khỏi markup thật, rồi hỏi `FilamentColor` — nguồn duy nhất sinh ra khối `:root` mà
| panel in ra — xem nó có đăng ký sắc độ đó không.
|
| Một biến gõ nhầm (`--grey-500`, `--primary-450`) hay một màu của bảng chưa đăng ký sẽ cho
| `var()` rỗng, tức không tô gì — đúng hạng lỗi mà `text-amber-600` và `bg-gray-100` đã gây ra ở
| M3 và không ai nhìn thấy suốt hai milestone.
*/
function colourVariablesIn(string $html): array
{
    preg_match_all('/var\(--([a-zA-Z0-9-]+)\)/', $html, $matches);

    return array_values(array_unique($matches[1]));
}

function unregisteredColourVariables(string $html): array
{
    $colours = FilamentColor::getColors();

    return array_values(array_filter(
        colourVariablesIn($html),
        function (string $variable) use ($colours): bool {
            if (preg_match('/^([a-z]+)-(\d+)$/', $variable, $parts) !== 1) {
                return true;
            }

            return ! isset($colours[$parts[1]][(int) $parts[2]]);
        },
    ));
}

function forceClientFlags(Document $document): Document
{
    DB::table('documents')
        ->where('id', $document->getKey())
        ->update(['client_can_view' => true, 'client_can_download' => true]);

    return $document->fresh();
}

$storageRunToken = $_SERVER['TEST_TOKEN'] ?? ($_SERVER['TEST_TOKEN'] = (string) getmypid());

/*
|--------------------------------------------------------------------------
| Thư mục tạm RIÊNG cho `backup:run` ở mỗi tiến trình test chạy `--parallel`
|--------------------------------------------------------------------------
|
| M8a Task 1/2, quan sát thấy flake thật ở `--parallel --processes=2` (ghi lại trong SDD ledger
| của làn, "backup-temp parallel race"): NHIỀU tệp chạy `backup:run`/`backup:clean` THẬT
| (`BackupRunIntegrationTest`, `GuardBackupEncryptionTest`, `BackupCleanupTest`,
| `BackupDatabaseDumpTest`, `GuardRcloneDestinationReachableTest`, và bất kỳ tệp Backup nào sau
| này gọi `Artisan::call('backup:run', ...)` thật) đều dùng `Spatie\Backup\Tasks\Backup\BackupJob`,
| và job đó tạo RỒI XOÁ `config('backup.backup.temporary_directory')` (mặc định
| `storage_path('app/backup-temp')`, xem `config/backup.php`) ở mỗi lượt chạy — kể cả lượt bị
| `GuardBackupEncryption` chặn giữa chừng. Với `--parallel`, hai worker là hai TIẾN TRÌNH PHP riêng
| nhưng CÙNG đọc/ghi/xoá đúng một thư mục vật lý: worker A đang ghi dump CSDL vào
| `backup-temp/db-dumps/` thì worker B, ở một test khác, xoá sạch cả thư mục đó khi lượt
| `backup:run` của NÓ kết thúc — bài nào flake tuỳ thuộc worker nào xoá đúng lúc worker kia đang
| đọc, nên lần đỏ không cố định vào một test.
|
| **Vòng sửa đầu (M8a Task 3, bản đầu) đặt lời gọi này trong `beforeEach` của TỪNG tệp trong bốn
| tệp trên — SAI: `GuardRcloneDestinationReachableTest.php` (M8a Task 2) cũng gọi `backup:run`
| thật ba lần và không nằm trong danh sách bốn tệp, nên nó vẫn đua trên
| `storage_path('app/backup-temp')` sau vòng sửa đầu.** Một danh sách tệp liệt kê tay là một bất
| biến giao cho trí nhớ người viết tệp test TIẾP THEO nhớ thêm dòng gọi — đúng lớp lỗi mà đĩa
| `private` giả ở trên đã từng mắc và được sửa bằng cách chuyển sang một `beforeEach` TOÀN CỤC
| (xem docblock ngay trên). Sửa đúng cách theo đúng bài học đó: một `beforeEach` áp cho CẢ THƯ MỤC
| `Feature/Backup`, không phải liệt kê tệp.
|
| Đặt tên thư mục bằng CẢ `getmypid()` LẪN token `--parallel` (đọc qua `$_SERVER['TEST_TOKEN']` —
| chính giá trị `ParallelTesting::token()` trả về khi không có resolver tuỳ biến nào được đặt,
| xem `Illuminate\Testing\ParallelTesting::token()`; đọc thẳng superglobal ở ĐÂY, cùng lý do với
| `$storageRunToken` ở trên, để không phụ thuộc thứ tự khởi động ứng dụng). CHỈ token thôi không
| đủ: Laravel/Paratest đánh số worker theo CHỈ SỐ (`1`, `2`, ...) trong PHẠM VI một lần chạy
| `--processes=N`, không phải một mã toàn cục — hai TIẾN TRÌNH chạy `--parallel --processes=2`
| ĐỒNG THỜI trong CÙNG worktree này (ví dụ hai agent làm hai task khác nhau cùng lúc) đều có worker
| mang token `1` và token `2`, và nếu thư mục chỉ đặt tên theo token, hai tiến trình đó xoá tệp của
| nhau y hệt lỗi ban đầu — chỉ đổi từ "hai worker cùng tiến trình" thành "hai tiến trình khác
| nhau". `getmypid()` phân biệt được hai tiến trình đó; token phân biệt được hai worker cùng tiến
| trình. Cần cả hai.
|
| KHÔNG đổi `config/backup.php`: hành vi production (một máy chủ, một tiến trình `backup:run` mỗi
| đêm) không đổi, `temporary_directory` production vẫn là `storage_path('app/backup-temp')` mặc
| định.
|
| Nhân chứng để cái `beforeEach` toàn cục này không bị gỡ đi trong im lặng (cùng thành ngữ với
| `PrivateDiskTest`): `tests/Feature/Backup/BackupTestTempDirectoryTest.php` — tệp đó KHÔNG tự đặt
| `backup.backup.temporary_directory`, nên nó chỉ xanh khi hook Ở ĐÂY còn hoạt động.
*/
function backupTemporaryTestDirectory(): string
{
    $token = $_SERVER['TEST_TOKEN'] ?? '0';

    return storage_path('app/backup-temp_test_'.getmypid().'_'.$token);
}

/*
|--------------------------------------------------------------------------
| Hai hàng rào nữa cho `Feature/Backup` (fix I2, lượt rà soát cuối M8a)
|--------------------------------------------------------------------------
|
| 1. `Process::preventStrayProcesses()` + `Process::fake([])` — mọi lời gọi facade `Process` mà
|    test không TỰ giả (bằng `Process::fake(...)` của chính nó, thứ thay bộ giả rỗng này) ném
|    `RuntimeException` "without a matching fake" thay vì chạy tiến trình thật. `RcloneProcess`
|    là nơi duy nhất trong mã gọi facade đó, nên không test nào ở thư mục này còn chạy được
|    `rclone` thật: trước fix này, `GuardRcloneDestinationReachableTest` chạy `backup:run` trên
|    `local_backups` với `BACKUP_RCLONE_REMOTE=gdrive:...` mà không giả gì — trên một máy có
|    `rclone` và remote `gdrive`, test đẩy archive thật lên Google Drive thật; và vì test đó dùng
|    `--disable-notifications`, lỗi của lượt đẩy cũng không lộ ra. `preventStrayProcesses()` MỘT
|    MÌNH không làm gì cả: `PendingProcess::run()` chỉ chặn khi factory ĐANG GHI (`isRecording()`),
|    tức sau một lời gọi `fake()` — vì vậy cần cả `fake([])` (ghi, không đăng ký lệnh giả nào).
|
|    Giới hạn, ghi rõ: `mariadb-dump` của `BackupDatabaseDumpTest` chạy qua
|    `Symfony\Component\Process\Process` bên trong `spatie/db-dumper`, không qua facade — hàng
|    rào này không chặn nó. Nó dump đúng CSDL test của lần chạy (`test:dump`), không phải một đích
|    từ xa.
| 2. `backup.backup.source.files.include` trỏ vào một thư mục NGUỒN TẠM riêng cho tiến trình (có
|    một tệp nhỏ, để `backup:run --only-files` có gì để nén) — `backup:run` thật trong thư mục này
|    không bao giờ nén `storage/app/private` thật của máy đang chạy test. Test cấu hình nguồn thật
|    (`BackupConfigTest`) đọc thẳng `config/backup.php`, không đọc giá trị bị hook này đổi.
|
| Nhân chứng: `tests/Feature/Backup/BackupTestIsolationTest.php` (không tự giả gì).
*/
function backupSourceTestDirectory(): string
{
    $token = $_SERVER['TEST_TOKEN'] ?? '0';

    return storage_path('app/backup-source_test_'.getmypid().'_'.$token);
}

pest()->in('Feature/Backup')->beforeEach(function (): void {
    config(['backup.backup.temporary_directory' => backupTemporaryTestDirectory()]);

    Process::preventStrayProcesses();
    Process::fake([]);

    $source = backupSourceTestDirectory();

    if (! is_dir($source)) {
        mkdir($source, 0777, true);
    }

    file_put_contents($source.'/tep-nguon-thu.txt', 'Tệp nguồn giả cho backup:run trong test — không phải hồ sơ thật.');

    config(['backup.backup.source.files.include' => [$source]]);
});

register_shutdown_function(function () use ($storageRunToken): void {
    foreach ((array) glob(__DIR__.'/../storage/framework/testing/disks/*_test_'.$storageRunToken) as $path) {
        if (is_dir($path)) {
            (new Filesystem)->deleteDirectory($path);
        }
    }

    // Cùng cách đặt tên với `backupTemporaryTestDirectory()` (pid + token), tính lại trực tiếp ở
    // đây thay vì gọi lại hàm đó: `storage_path()` cần `app()` đã dựng, và một shutdown handler
    // chạy ở CUỐI tiến trình không đảm bảo container còn sống — `__DIR__` thì luôn có.
    $backupTempToken = $_SERVER['TEST_TOKEN'] ?? '0';
    $backupTempDirectory = __DIR__.'/../storage/app/backup-temp_test_'.getmypid().'_'.$backupTempToken;

    if (is_dir($backupTempDirectory)) {
        (new Filesystem)->deleteDirectory($backupTempDirectory);
    }

    // Thư mục nguồn tạm của `backupSourceTestDirectory()` — cùng cách đặt tên, cùng lý do tính lại.
    $backupSourceDirectory = __DIR__.'/../storage/app/backup-source_test_'.getmypid().'_'.$backupTempToken;

    if (is_dir($backupSourceDirectory)) {
        (new Filesystem)->deleteDirectory($backupSourceDirectory);
    }
});
