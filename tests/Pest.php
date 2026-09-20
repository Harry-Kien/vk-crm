<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

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
$storageRunToken = $_SERVER['TEST_TOKEN'] ?? ($_SERVER['TEST_TOKEN'] = (string) getmypid());

register_shutdown_function(function () use ($storageRunToken): void {
    foreach ((array) glob(__DIR__.'/../storage/framework/testing/disks/*_test_'.$storageRunToken) as $path) {
        if (is_dir($path)) {
            (new Filesystem)->deleteDirectory($path);
        }
    }
});
