<?php

use App\Actions\Matter\RequestHandoverPackage;
use App\Actions\Storage\StorageReadiness;
use App\Jobs\GenerateHandoverPackage;
use App\Support\Files\FreeSpace;
use App\Support\Storage\CredentialFileInspector;
use App\Support\Storage\GoogleDrive\DriveTokenProvider;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Schedule;
use Tests\Support\FakeCredentialFile;
use Tests\Support\FakeGoogleDrive;

/*
|--------------------------------------------------------------------------
| M14 Task 8 — tài liệu cài đặt, đặc tả và quy trình nói đúng điều mã làm
|--------------------------------------------------------------------------
|
| Phần 3 của Task 8 là tài liệu. Test đọc CHÍNH các tệp tài liệu rồi so với mã: con số thời gian của
| job gói bàn giao (rà soát Task 4, m3: `docs/CAI-DAT.md` còn ghi 600 giây và 15 phút sau khi mã đổi
| thành 1200/25), biến môi trường mới của khối M14 trong `.env.example`, các mục lịch `storage.*`, các
| dòng mà preflight production nối cho kho, và các đính chính SPEC mà kế hoạch M14 đòi. Đổi một con số,
| thêm một biến hay một mục lịch mà quên tài liệu thì đỏ ở đây.
*/

function t8Doc(string $path): string
{
    return (string) file_get_contents(base_path($path));
}

/** Đoạn của một tài liệu Markdown từ tiêu đề `$heading` tới tiêu đề kế tiếp cùng cấp hoặc cao hơn. */
function t8Section(string $document, string $heading): string
{
    $level = strspn($heading, '#');
    $after = (string) str($document)->after($heading);

    expect($after)->not->toBe($document, "Không thấy tiêu đề {$heading}.");

    $stop = preg_match('/\n#{1,'.$level.'} /u', $after, $match, PREG_OFFSET_CAPTURE) === 1 ? $match[0][1] : strlen($after);

    return substr($after, 0, $stop);
}

function t8Event(string $name): Event
{
    $event = collect(Schedule::events())->first(fn (Event $event): bool => $event->description === $name);

    expect($event)->not->toBeNull("Không có mục lịch {$name}.");

    return $event;
}

/** @return list<string> biến của khối "Kho tài liệu Google Drive (M14)" trong `.env.example`, kể cả dòng chú thích `# BIEN=` */
function t8StorageEnvKeys(): array
{
    $block = (string) str(t8Doc('.env.example'))->after('# --- Kho tài liệu Google Drive (M14) ---')->before("\n# --- ");

    preg_match_all('/^#?\s?([A-Z][A-Z0-9_]+)=/m', $block, $matches);

    return array_values(array_unique($matches[1]));
}

it('CAI-DAT nói đúng giờ chết của job gói bàn giao và hạn khoá của mục lịch queue.handover (rà soát Task 4, m3)', function () {
    $guide = t8Doc('docs/CAI-DAT.md');
    $lockMinutes = t8Event('queue.handover')->expiresAt;

    expect($guide)->toContain('giờ chết '.GenerateHandoverPackage::TIMEOUT_SECONDS.' giây')
        ->and($guide)->toContain('sau '.$lockMinutes.' phút (khoá chống chạy chồng của mục lịch)')
        ->and($guide)->not->toContain('giờ chết 600 giây')
        ->and($guide)->not->toContain('sau 15 phút lượt chạy kế tiếp');
});

it('SPEC §4.19 nói đúng ngưỡng "generating kẹt" của gói bàn giao sau M14', function () {
    $section = t8Section(t8Doc('docs/SPEC.md'), '### 4.19');

    expect($section)->toMatch('/Đính chính 2026-10-04 \([^)]*M14/u')
        ->and($section)->toContain(RequestHandoverPackage::STALE_AFTER_MINUTES.' phút');
});

it('khối M14 của .env.example có biến, và mỗi biến được CAI-DAT lẫn README nhắc tới', function () {
    $keys = t8StorageEnvKeys();

    expect($keys)->toContain('DOCUMENT_STORAGE')
        ->and($keys)->toContain('GOOGLE_DRIVE_CREDENTIALS_PATH')
        ->and($keys)->toContain('DOCUMENT_OFFICE_RECEIPTS_PATH');

    foreach (['docs/CAI-DAT.md', 'README.md'] as $path) {
        $document = t8Doc($path);

        foreach ($keys as $key) {
            expect(str_contains($document, '`'.$key))->toBeTrue("{$path} không nhắc biến {$key} của kho tài liệu.");
        }
    }
});

it('CAI-DAT Bước 8 kể đủ các mục lịch của kho tài liệu, đọc từ lịch thật', function () {
    $names = collect(Schedule::events())
        ->map(fn (Event $event): ?string => $event->description)
        ->filter(fn (?string $name): bool => $name !== null && (str_starts_with($name, 'storage.') || $name === 'queue.storage'))
        ->values()
        ->all();

    expect($names)->toContain('queue.storage')
        ->and($names)->toContain('storage.push-pending')
        ->and($names)->toContain('storage.purge-staged')
        ->and($names)->toContain('storage.health')
        ->and($names)->toContain('storage.office-receipts');

    $step = t8Section(t8Doc('docs/CAI-DAT.md'), '### Bước 8');

    foreach ($names as $name) {
        expect(str_contains($step, '`'.$name.'`'))->toBeTrue("CAI-DAT Bước 8 không kể mục lịch {$name}.");
    }
});

it('CAI-DAT Bước 7 kể vkcrm:storage:check và mọi dòng mà preflight production nối cho kho', function () {
    FakeGoogleDrive::install();
    app()->instance(DriveTokenProvider::class, FakeGoogleDrive::tokenProvider());
    app()->instance(CredentialFileInspector::class, new FakeCredentialFile);
    app()->instance(FreeSpace::class, new FreeSpace(fn (string $path) => 1024 ** 3));
    config(['app.env' => 'production', 'vkcrm.storage.driver' => 'google_drive']);

    $readiness = app(StorageReadiness::class);
    $keys = [...array_column($readiness->rows(), 'key'), ...array_column($readiness->stateRows(), 'key')];
    $step = t8Section(t8Doc('docs/CAI-DAT.md'), '### Bước 7');

    expect($step)->toContain('vkcrm:storage:check');

    foreach ($keys as $key) {
        expect(str_contains($step, '`'.$key.'`'))->toBeTrue("CAI-DAT Bước 7 không kể dòng {$key}.");
    }
});

it('CAI-DAT có mục nâng cấp M14 và trỏ tới sổ tay kho; khoá dịch vụ đặt ngoài mã nguồn, có biến thể shared hosting', function () {
    $guide = t8Doc('docs/CAI-DAT.md');
    $upgrade = t8Section($guide, '### Bản cập nhật M14');

    expect($upgrade)->toContain('docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md')
        ->and($upgrade)->toContain('DOCUMENT_STORAGE=local')
        ->and($upgrade)->toContain('vkcrm:storage:enable')
        ->and($guide)->toContain('/etc/vkcrm/google-drive-key.json')
        ->and($guide)->toContain('.config/vkcrm/google-drive-key.json')
        ->and($guide)->toContain('0440')
        ->and($guide)->toContain('0400');
});

it('SPEC có đính chính M14 ngày 2026-10-04 ở §2, §4.11, §4.12, §4.19, §10, §11 và dòng M14 ở bảng §13', function () {
    $spec = t8Doc('docs/SPEC.md');

    foreach (['## 2.', '### 4.11', '### 4.12', '### 4.19', '## 10.', '## 11.', '## 13.'] as $heading) {
        expect(t8Section($spec, $heading))->toMatch('/Đính chính 2026-10-04 \([^)]*M14/u');
    }

    $milestones = t8Section($spec, '## 13.');

    expect($milestones)->toMatch('/^\| \*\*M14\*\* \|/m')
        ->and(t8Section($spec, '## 10.'))->toContain('không mã hoá phía văn phòng')
        ->and(t8Section($spec, '### 4.19'))->toContain('vkcrm:storage:destruction-list')
        ->and(t8Section($spec, '## 11.'))->toContain('DocumentDownloadFromRemoteTest');
});

it('QUY-TRINH có đoạn cho nhân sự: tài liệu chỉ mở qua CRM, không mở/chia sẻ/chép trên Drive, máy văn phòng là bản mã hoá', function () {
    $section = t8Section(t8Doc('docs/QUY-TRINH.md'), '### Tài liệu nằm trên kho Google Drive');

    expect($section)->toContain('chỉ mở qua CRM')
        ->and($section)->toContain('báo quản trị')
        ->and($section)->toContain('mã hoá');
});

it('PROGRESS có "Ghi chú M14" với mọi phán quyết R1–R15, C1–C7 và câu chờ chủ văn phòng', function () {
    $notes = t8Section(t8Doc('docs/PROGRESS.md'), '## Ghi chú M14');

    foreach (range(1, 15) as $n) {
        expect(str_contains($notes, "**R{$n}"))->toBeTrue("Ghi chú M14 thiếu phán quyết R{$n}.");
    }

    foreach (range(1, 7) as $n) {
        expect(str_contains($notes, "**C{$n}"))->toBeTrue("Ghi chú M14 thiếu phán quyết controller C{$n}.");
    }

    expect($notes)->toContain('M14 — xong phần mã, chờ chủ văn phòng');
});
