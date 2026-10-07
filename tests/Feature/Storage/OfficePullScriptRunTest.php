<?php

use App\Actions\Storage\ImportOfficeReceipts;
use App\Support\Storage\OfficeReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Support\DocumentStoreFixtures;
use Tests\Support\FakeGoogleDrive;
use Tests\Support\OfficeReceiptFixtures as Receipts;

/*
|--------------------------------------------------------------------------
| M14 Task 7 — chạy THẬT `tools/backup/office-pull.sh` với một rclone giả (kế hoạch R10)
|--------------------------------------------------------------------------
|
| Test cấu trúc (`OfficeCopyStructureTest`) chỉ đọc văn bản của script. Tệp này chạy chính script
| bằng `bash` trong container, với `tests/Support/fake-rclone.sh` thay rclone: mỗi remote là một thư
| mục, không mạng, không Google (phán quyết C2). Nó chứng minh hai điều mà đọc văn bản không chứng
| minh được:
|
| 1. biên nhận script dựng ra được `OfficeReceipt::parse()` nhận, và lượt nhập của CRM đánh dấu đúng
|    các tệp trong đó — hai đầu nói cùng một khuôn;
| 2. tệp bị đổi trên kho sau khi đã có ở văn phòng làm lượt chép `--immutable` báo lỗi, không ghi đè,
|    và lỗi đó tới CRM qua `errors` của biên nhận kế tiếp.
|
| Chạy thật trên máy văn phòng với rclone thật và Shared Drive thử: PENDING OWNER (Task 8 Phần 2).
*/

beforeEach(function () {
    // Thư mục tạm của hệ điều hành trong container, không thư mục mount từ Windows: rclone giả phải
    // mang bit chạy được.
    $this->dir = sys_get_temp_dir().'/office-pull-'.Str::lower(Str::random(10));
    File::ensureDirectoryExists($this->dir.'/root/kho/2026-10');
    File::ensureDirectoryExists($this->dir.'/root/backups/VK-CRM-backups/vk-crm-test');
    File::copy(base_path('tools/backup/office-pull.sh'), $this->dir.'/office-pull.sh');
    File::copy(base_path('tests/Support/fake-rclone.sh'), $this->dir.'/fake-rclone');
    chmod($this->dir.'/fake-rclone', 0755);
    File::put($this->dir.'/office-pull.conf', implode("\n", [
        'RCLONE="'.$this->dir.'/fake-rclone"',
        'ENV_FOLDER="vk-crm-test"',
        'ARCHIVE_DIR="'.$this->dir.'/archives"',
        'STATE_DIR="'.$this->dir.'/state"',
        '',
    ]));
    File::put($this->dir.'/root/backups/VK-CRM-backups/vk-crm-test/vk-crm-test-2026-10-07-02-00-00.zip', 'archive');
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

function t7RunOfficePull(string $dir, array $arguments = []): int
{
    $result = Process::path($dir)
        ->env([
            'FAKE_RCLONE_ROOT' => $dir.'/root',
            'FAKE_TEAM_DRIVE' => FakeGoogleDrive::DRIVE_ID,
            'FAKE_ROOT_FOLDER' => FakeGoogleDrive::ROOT_FOLDER_ID,
        ])
        ->timeout(60)
        ->run(['bash', 'office-pull.sh', ...$arguments]);

    return $result->exitCode();
}

/** @return array<string, string> tên tệp biên nhận => nội dung, theo thứ tự tên */
function t7SentReceipts(string $dir): array
{
    $receipts = [];

    foreach (File::glob($dir.'/root/backups/VK-CRM-backups/office-receipts/vk-crm-test/receipt-*.json') as $path) {
        $receipts[basename($path)] = (string) file_get_contents($path);
    }

    ksort($receipts);

    return $receipts;
}

it('biên nhận script dựng ra được CRM nhận, và đúng các tệp đã kiểm được đánh dấu', function () {
    $a = '1834/'.strtolower((string) Str::ulid()).'.pdf';
    $b = '1835/'.strtolower((string) Str::ulid());
    File::put($this->dir.'/root/kho/2026-10/'.str_replace('/', '~', $a), 'noi dung a');
    File::put($this->dir.'/root/kho/2026-10/'.str_replace('/', '~', $b).'~g2', 'noi dung b');
    $rowA = DocumentStoreFixtures::driveObject(['object_key' => $a, 'md5' => md5('noi dung a'), 'size' => 10]);
    $rowB = DocumentStoreFixtures::driveObject(['object_key' => $b, 'generation' => 2, 'md5' => md5('noi dung b'), 'size' => 10]);

    expect(t7RunOfficePull($this->dir))->toBe(0, (string) @file_get_contents($this->dir.'/office-pull.log'));

    $receipts = t7SentReceipts($this->dir);
    expect($receipts)->toHaveCount(1)
        ->and(preg_match(ImportOfficeReceipts::FILE_PATTERN, array_key_first($receipts)))->toBe(1)
        ->and(File::files($this->dir.'/archives'))->toHaveCount(1);

    $parsed = OfficeReceipt::parse(reset($receipts), FakeGoogleDrive::DRIVE_ID, FakeGoogleDrive::ROOT_FOLDER_ID, now());
    expect($parsed->errors)->toBe(0)
        ->and(collect($parsed->files)->pluck('key')->sort()->values()->all())->toBe(collect([$a, $b])->sort()->values()->all());

    Http::preventStrayRequests();
    Receipts::configure();
    Receipts::fakeRclone($receipts);

    $result = app(ImportOfficeReceipts::class)->handle();

    expect($result->rejected)->toBe(0)
        ->and($result->marked)->toBe(2)
        ->and(DB::table('drive_objects')->whereIn('id', [$rowA, $rowB])->whereNotNull('office_copied_at')->count())->toBe(2);
});

it('lượt sau chỉ ghi biên nhận cho tệp mới; đêm không có tệp mới vẫn gửi một biên nhận rỗng (nhịp sống)', function () {
    File::put($this->dir.'/root/kho/2026-10/1834~'.strtolower((string) Str::ulid()).'.pdf', 'cu');

    expect(t7RunOfficePull($this->dir))->toBe(0);
    sleep(1);
    expect(t7RunOfficePull($this->dir))->toBe(0);

    $new = '1835~'.strtolower((string) Str::ulid()).'.pdf';
    File::put($this->dir.'/root/kho/2026-10/'.$new, 'moi');
    sleep(1);
    expect(t7RunOfficePull($this->dir))->toBe(0);

    $files = array_map(fn (string $json) => array_column(json_decode($json, true)['files'], 'name'), array_values(t7SentReceipts($this->dir)));

    expect($files)->toHaveCount(3)
        ->and($files[0])->toHaveCount(1)
        ->and($files[1])->toBe([])
        ->and($files[2])->toBe([$new]);
});

it('tệp bị đổi trên kho sau khi đã ở văn phòng: không ghi đè, mã thoát 1, biên nhận mang errors > 0', function () {
    $key = '1834/'.strtolower((string) Str::ulid()).'.pdf';
    $drivePath = $this->dir.'/root/kho/2026-10/'.str_replace('/', '~', $key);
    File::put($drivePath, 'ban goc');

    expect(t7RunOfficePull($this->dir))->toBe(0);

    File::put($drivePath, 'ban bi sua');
    // Tên biên nhận mang giây UTC: lượt sau phải ở giây khác lượt trước.
    sleep(1);

    expect(t7RunOfficePull($this->dir))->toBe(1);

    $officeCopy = $this->dir.'/root/office/kho/2026-10/'.str_replace('/', '~', $key);
    $receipts = array_values(t7SentReceipts($this->dir));

    expect(file_get_contents($officeCopy))->toBe('ban goc')
        ->and($receipts)->toHaveCount(2)
        ->and(json_decode($receipts[1], true)['errors'])->toBeGreaterThan(0);
});

it('lượt khác đang giữ khoá (PID còn sống) → thoát 75, không gọi rclone', function () {
    File::ensureDirectoryExists($this->dir.'/state/office-pull.lock');
    File::put($this->dir.'/state/office-pull.lock/pid', (string) getmypid());

    expect(t7RunOfficePull($this->dir))->toBe(75)
        ->and(File::exists($this->dir.'/root/calls.log'))->toBeFalse()
        ->and(File::exists($this->dir.'/state/office-pull.lock/pid'))->toBeTrue();
});

it('remote kho không phải drive.readonly → thoát 2, không chép gì', function () {
    File::put($this->dir.'/root/kho/2026-10/1834~01k6xq0f9m2y7c4w8r3t5v6n1b.pdf', 'x');

    $result = Process::path($this->dir)
        ->env(['FAKE_RCLONE_ROOT' => $this->dir.'/root', 'FAKE_TEAM_DRIVE' => 'a', 'FAKE_ROOT_FOLDER' => 'b', 'FAKE_SCOPE' => 'drive'])
        ->timeout(60)
        ->run(['bash', 'office-pull.sh']);

    expect($result->exitCode())->toBe(2)
        ->and(File::isDirectory($this->dir.'/root/office'))->toBeFalse();
});

// ---------------------------------------------------------------------------------------------
// M14 Task 6 — rà soát Task 7: tên trùng trên kho (m1), trần số tệp mỗi biên nhận (m2)
// ---------------------------------------------------------------------------------------------

it('một tên có hai tệp trên kho (hai thư mục tháng): không vào biên nhận, không vào receipted.txt, có dòng log', function () {
    $twin = '1834~'.strtolower((string) Str::ulid()).'.pdf';
    $single = '1835~'.strtolower((string) Str::ulid()).'.pdf';
    File::ensureDirectoryExists($this->dir.'/root/kho/2026-11');
    File::put($this->dir.'/root/kho/2026-10/'.$twin, 'ban that');
    File::put($this->dir.'/root/kho/2026-11/'.$twin, 'ban gia mao');
    File::put($this->dir.'/root/kho/2026-10/'.$single, 'mot ban');

    expect(t7RunOfficePull($this->dir))->toBe(1);

    $receipt = json_decode((string) collect(t7SentReceipts($this->dir))->first(), true);

    expect(array_column($receipt['files'], 'name'))->toBe([$single])
        ->and($receipt['errors'])->toBeGreaterThan(0)
        ->and((string) file_get_contents($this->dir.'/state/receipted.txt'))->not->toContain($twin)
        ->and((string) file_get_contents($this->dir.'/office-pull.log'))->toContain($twin);
});

it('trần MAX_RECEIPT_FILES: mỗi lượt chỉ kiểm và ghi biên nhận cho tối đa chừng đó tệp; phần còn lại vào lượt sau', function () {
    File::append($this->dir.'/office-pull.conf', "MAX_RECEIPT_FILES=2\n");

    foreach (range(1, 3) as $i) {
        File::put($this->dir.'/root/kho/2026-10/18'.$i.'0~'.strtolower((string) Str::ulid()).'.pdf', 'tep '.$i);
    }

    expect(t7RunOfficePull($this->dir))->toBe(0);
    sleep(1);
    expect(t7RunOfficePull($this->dir))->toBe(0);

    $files = array_map(fn (string $json) => array_column(json_decode($json, true)['files'], 'name'), array_values(t7SentReceipts($this->dir)));

    expect($files)->toHaveCount(2)
        ->and($files[0])->toHaveCount(2)
        ->and($files[1])->toHaveCount(1)
        ->and(array_intersect($files[0], $files[1]))->toBe([])
        ->and((string) file_get_contents($this->dir.'/office-pull.log'))->toContain('lượt sau');
});

it('mặc định MAX_RECEIPT_FILES đủ nhỏ để biên nhận lọt trần 32 MiB của CRM (≈ 105 byte mỗi dòng)', function () {
    preg_match('/^MAX_RECEIPT_FILES="?(\d+)"?$/m', (string) file_get_contents(base_path('tools/backup/office-pull.sh')), $match);

    expect($match)->toHaveKey(1)
        ->and((int) $match[1] * 200)->toBeLessThan((int) config('vkcrm.storage.office.receipt_max_bytes'));
});
