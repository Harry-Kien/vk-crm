<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
$storageRunToken = $_SERVER['TEST_TOKEN'] ?? ($_SERVER['TEST_TOKEN'] = (string) getmypid());

register_shutdown_function(function () use ($storageRunToken): void {
    foreach ((array) glob(__DIR__.'/../storage/framework/testing/disks/*_test_'.$storageRunToken) as $path) {
        if (is_dir($path)) {
            (new Filesystem)->deleteDirectory($path);
        }
    }
});
