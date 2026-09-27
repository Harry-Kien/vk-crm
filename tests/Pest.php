<?php

use App\Models\Document;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    ->beforeEach(fn () => Storage::fake('private'))
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
| của làn, "backup-temp parallel race"): bốn tệp chạy `backup:run`/`backup:clean` THẬT
| (`BackupRunIntegrationTest`, `GuardBackupEncryptionTest`, `BackupCleanupTest`,
| `BackupDatabaseDumpTest`) đều dùng `Spatie\Backup\Tasks\Backup\BackupJob`, và job đó tạo RỒI XOÁ
| `config('backup.backup.temporary_directory')` (mặc định `storage_path('app/backup-temp')`,
| xem `config/backup.php`) ở mỗi lượt chạy — kể cả lượt bị `GuardBackupEncryption` chặn giữa
| chừng. Với `--parallel`, hai worker là hai TIẾN TRÌNH PHP riêng nhưng CÙNG đọc/ghi/xoá đúng một
| thư mục vật lý: worker A đang ghi dump CSDL vào `backup-temp/db-dumps/` thì worker B, ở một test
| khác, xoá sạch cả thư mục đó khi lượt `backup:run` của NÓ kết thúc — bài nào flake tuỳ thuộc
| worker nào xoá đúng lúc worker kia đang đọc, nên lần đỏ không cố định vào một test.
|
| Cách chữa CHỈ áp cho bộ test: mỗi tiến trình test được gán một thư mục tạm riêng, đặt tên bằng
| CHÍNH token đã dùng cho đĩa `private` giả ở trên (`ParallelTesting::token()`, đọc qua
| `$_SERVER['TEST_TOKEN']`) — hai worker chạy `--parallel` có hai token khác nhau, nên hai thư mục
| khác nhau, và không còn tài nguyên nào bị chia sẻ. KHÔNG đổi `config/backup.php`: hành vi thật
| (một máy chủ, một tiến trình `backup:run` mỗi đêm) không đổi, `temporary_directory` production
| vẫn là `storage_path('app/backup-temp')` mặc định.
|
| Bốn tệp test ở trên tự gọi hàm này trong `beforeEach` của CHÍNH chúng (không đặt Ở ĐÂY một
| `beforeEach` toàn cục áp cho mọi test Feature — phần lớn test Feature không đụng gói backup,
| và một `config()` thừa mỗi test là một chỗ nữa phải giải thích khi có ai đọc lại `tests/Pest.php`
| tìm hiểu vì sao một biến cấu hình không giữ nguyên giá trị mặc định).
*/
function backupTemporaryTestDirectory(): string
{
    $token = $_SERVER['TEST_TOKEN'] ?? (string) getmypid();

    return storage_path('app/backup-temp_test_'.$token);
}

register_shutdown_function(function () use ($storageRunToken): void {
    foreach ((array) glob(__DIR__.'/../storage/framework/testing/disks/*_test_'.$storageRunToken) as $path) {
        if (is_dir($path)) {
            (new Filesystem)->deleteDirectory($path);
        }
    }

    $backupTempDirectory = __DIR__.'/../storage/app/backup-temp_test_'.$storageRunToken;

    if (is_dir($backupTempDirectory)) {
        (new Filesystem)->deleteDirectory($backupTempDirectory);
    }
});
