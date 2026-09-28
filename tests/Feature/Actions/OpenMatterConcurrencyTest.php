<?php

use App\Enums\Role;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Spatie\Activitylog\Models\Activity;

/**
 * R13(g)/`conflict-11` (M6.5 Task 8, brief test (g)): "hai tiến trình mở hai vụ đối nhau cùng lúc
 * thì ít nhất một vụ ra đỏ." `RunConflictCheck` chỉ so được với những gì ĐÃ COMMIT — trước bản sửa
 * này, `OpenMatter::lockClients()` chỉ khoá dòng `clients` mà CHÍNH lần mở vụ đó đọc, nên hai
 * người mở đồng thời hai vụ đối nhau cho hai khách hàng CHƯA từng có vụ nào (A mở "X kiện W", B mở
 * "W kiện X") không khoá chung dòng nào để mà chờ nhau: giai đoạn kiểm tra của A có thể chạy TRƯỚC
 * khi giai đoạn lưu của B commit (và ngược lại), nên cả hai đều thấy sạch và cả hai đều lưu XANH —
 * một xung đột lợi ích thật bị bỏ sót vì thời điểm, không phải vì dữ liệu.
 *
 * Bản sửa (R13g): toàn bộ `OpenMatter::handle()` (và `AddMatterParty::handle()`) giờ chạy dưới
 * MỘT khoá ứng dụng dùng chung, `Cache::store('database')->lock('conflict-check', ...)`. Chỉ một
 * test THẬT, hai tiến trình hệ điều hành riêng, hai kết nối DB riêng, trên MariaDB thật mới đo
 * được khoá đó có tác dụng hay không — một test giả lập "gọi Action hai lần" trong cùng một tiến
 * trình PHP (như phần lớn test khác của nhánh này) không chạm được transaction/lock thật. Hai tiến
 * trình con là `tests/concurrency/open_matter_probe.php`, đọc docblock ở đó cho hợp đồng của nó.
 *
 * **`DB::commit()` giữa bài test — cố ý, không phải một tai nạn (khác MỌI tệp Feature khác).**
 * `tests/Pest.php` bọc CẢ thư mục `Feature` trong `RefreshDatabase`, và Pest không cho một tệp con
 * "huỷ" ràng buộc `uses()` của thư mục cha (đã thử: `uses(Tests\TestCase::class)` lại ở đây ném
 * "Test case đã dùng Tests\TestCase" — một lớp TestCase chỉ được đăng ký một lần cho một đường
 * dẫn). Nghĩa là transaction bọc bài test này LUÔN mở khi thân test bắt đầu chạy, và mọi dòng
 * `Client`/`MatterType`/`User` dựng bên dưới nằm TRONG transaction đó — vô hình với hai tiến trình
 * con, vốn mở KẾT NỐI DB RIÊNG (một transaction chưa `COMMIT` không bao giờ lọt qua ranh giới kết
 * nối, kể cả khi trỏ cùng một CSDL). `DB::commit()` gọi ĐÚNG API của Laravel (không phải `COMMIT`
 * SQL trần): nó giảm bộ đếm nội bộ `$connection->transactions` từ 1 về 0, nên lệnh `rollBack()` mà
 * `RefreshDatabase` gọi ở cuối bài test (qua `beforeApplicationDestroyed`) đọc đúng bộ đếm đó, thấy
 * không còn gì để rollback, và tự bỏ qua — không exception, không cảnh báo (xem `ManagesTransactions
 * ::rollBack()`: `$toLevel = $this->transactions - 1`, âm thì `return` ngay).
 *
 * **Ghi nhận trung thực — hai hệ quả của việc commit sớm.**
 *  (1) `RefreshDatabaseState::$migrated` bị đặt lại `false` ở cuối bài test này (`beforeApplication
 *  Destroyed` thấy kết nối không còn `inTransaction()`), nên LẦN TIẾP THEO `RefreshDatabase` chạy
 *  (bài test kế tiếp trong cùng lượt `test:mariadb`, nếu có) sẽ `migrate:fresh` lại — một chi phí,
 *  không phải một lỗi. Không tránh được nếu vẫn muốn dữ liệu chuẩn bị ở đây SỐNG SÓT qua ranh giới
 *  transaction để hai tiến trình con nhìn thấy.
 *  (2) `Matter::forceDeleting` bị chặn có chủ đích (vụ việc không bao giờ thật sự biến mất — xem
 *  `RunConflictCheck`), và dữ liệu bài test này đã COMMIT THẬT nên không có rollback nào dọn lại.
 *  Hai vụ việc/hai khách hàng/hai luật sư ở lại trong CSDL test MariaDB (`vk_crm_test_<lane>`) sau
 *  khi chạy — một sự đánh đổi chấp nhận được cho một CSDL chỉ dùng để chạy `test:mariadb`, cùng
 *  hình dạng với việc `RefreshDatabase` không dọn đĩa `storage/app/private` (xem `tests/Pest.php`).
 *
 * **Fix round 1, I2 — vì sao HAI `MatterType` riêng, không phải một loại dùng chung (bằng chứng đo
 * được, không chỉ suy luận).** Bản đầu dùng CHUNG một `$matterType` cho cả hai tiến trình. Khi tắt
 * khoá `Cache::lock('conflict-check')` để làm mutation probe cho chính khoá này, kết quả KHÔNG ổn
 * định: đôi khi cả hai ra XANH đúng như lỗ hổng `conflict-11` mô tả, nhưng đôi khi MariaDB tự phát
 * hiện một DEADLOCK thật ở `CodeSequence::next()` (`Matter::nextCode()` — hai tiến trình cùng
 * `INSERT ... ON DUPLICATE`/tranh khoá dòng `code_sequences` của CÙNG một khoá `matter:{year}:
 * {type->code}`, vì cùng loại vụ việc) — một `ERROR Illuminate\Database\QueryException: Deadlock
 * found...`. Đó VẪN là một FAIL hợp lệ (khẳng định `not->toStartWith('ERROR')` bắt được), nhưng
 * KHÔNG phải bằng chứng đúng chỗ brief đòi: "RED phải là một khẳng định `toContain('RED')` thất
 * bại, không phải một deadlock InnoDB". Cho mỗi tiến trình một `MatterType` RIÊNG (hai khoá
 * `code_sequences` khác nhau) loại bỏ hẳn nguồn tranh chấp phụ đó — phần DUY NHẤT hai tiến trình
 * còn chạm nhau là chính hành vi `RunConflictCheck` đang được đo (đọc chéo hai dòng `clients`
 * KHÔNG bị khoá bởi bên kia — xem `open_matter_probe.php`), nên tắt khoá giờ luôn cho ra hai
 * `GREEN` sạch, khiến `toContain('RED')` thất bại đúng cách mỗi lần — xem bằng chứng dán trong
 * `task-8-report.md`, "Fix round 1", mục R13(g).
 */
it('blocks at least one of two matters opened concurrently for opposing clients', function () {
    (new RolesAndPermissionsSeeder)->run();

    // Số căn cước SINH NGẪU NHIÊN mỗi lượt chạy — bắt buộc, không phải cẩn thận thừa: dữ liệu của
    // lượt chạy TRƯỚC không bị dọn (xem "Ghi nhận trung thực" ở trên), nên một số cố định sẽ khiến
    // RunConflictCheck khớp với CHÍNH những dòng residual đó, cho đỏ vì lý do sai — không phải vì
    // hai tiến trình lần này thật sự đụng nhau.
    $idNumberX = (string) random_int(100_000_000_000, 999_999_999_999);
    $idNumberW = (string) random_int(100_000_000_000, 999_999_999_999);

    $clientX = Client::factory()->create(['name' => 'Khách hàng X (kiểm tra đồng thời)', 'id_number' => $idNumberX]);
    $clientW = Client::factory()->create(['name' => 'Khách hàng W (kiểm tra đồng thời)', 'id_number' => $idNumberW]);
    $actorA = User::factory()->withRole(Role::Lawyer)->create();
    $actorB = User::factory()->withRole(Role::Lawyer)->create();
    // Hai LOẠI VỤ VIỆC riêng — xem "Fix round 1, I2" ở docblock lớp cho lý do: dùng chung một loại
    // khiến hai tiến trình tranh CÙNG một dòng code_sequences, và khi khoá bị tắt (mutation probe)
    // MariaDB có thể tự báo deadlock ở đó thay vì để RunConflictCheck thật sự chạy sai.
    $matterTypeA = MatterType::factory()->withStages()->create();
    $matterTypeB = MatterType::factory()->withStages()->create();

    // Xem docblock lớp: phải commit thật để hai tiến trình con (kết nối DB riêng) nhìn thấy được
    // dữ liệu vừa dựng — RefreshDatabase vẫn giữ bài test này trong một transaction chưa commit.
    DB::commit();

    $script = base_path('tests/concurrency/open_matter_probe.php');
    $titleA = 'X kiện W (kiểm tra đồng thời '.uniqid().')';
    $titleB = 'W kiện X (kiểm tra đồng thời '.uniqid().')';

    // Rào chắn khởi động: cả hai tiến trình bận chờ tệp này xuất hiện (xem docblock
    // open_matter_probe.php) — dựng SAU khi cả hai đã được khởi động, để thời gian bootstrap
    // Laravel không đều giữa hai tiến trình không tự nới rộng cửa sổ đua.
    $barrierFile = sys_get_temp_dir().'/vkcrm-concurrency-barrier-'.uniqid().'.txt';

    $processA = Process::timeout(30)->start([
        'php', $script, (string) $clientX->id, $idNumberW, (string) $actorA->id, (string) $matterTypeA->id, $titleA, $barrierFile,
    ]);
    $processB = Process::timeout(30)->start([
        'php', $script, (string) $clientW->id, $idNumberX, (string) $actorB->id, (string) $matterTypeB->id, $titleB, $barrierFile,
    ]);

    file_put_contents($barrierFile, '1');

    try {
        $resultA = $processA->wait();
        $resultB = $processB->wait();
    } finally {
        @unlink($barrierFile);
    }

    $outputA = trim($resultA->output());
    $outputB = trim($resultB->output());

    expect($outputA)->not->toStartWith('ERROR')
        ->and($outputB)->not->toStartWith('ERROR');

    // Đúng bullet cuối của test (g): dòng conflict_check_run lúc mở vụ có subject là vụ vừa tạo —
    // khẳng định lại ở đây, trên MariaDB thật, không chỉ ở bản SQLite của
    // ConflictCheckFlowTest::"links the conflict_check_run row...".
    foreach (Matter::query()->whereIn('title', [$titleA, $titleB])->get() as $matter) {
        $checkRun = Activity::query()
            ->where('event', 'conflict_check_run')
            ->where('subject_type', $matter->getMorphClass())
            ->where('subject_id', $matter->getKey())
            ->exists();

        expect($checkRun)->toBeTrue();
    }

    // R13(g): dưới khoá dùng chung, hai tiến trình bị TUẦN TỰ hoá — tiến trình chạy SAU luôn thấy
    // dữ liệu tiến trình chạy TRƯỚC đã commit, nên không thể cả hai cùng ra xanh.
    expect([$outputA, $outputB])->toContain('RED');
})->skip(
    fn (): bool => config('database.default') !== 'mariadb',
    'chỉ có ý nghĩa dưới MariaDB thật (bin/dev test:mariadb / wt-dev lane test:mariadb) — SQLite trong bộ nhớ không dựng lại được hai kết nối song song',
);
