<?php

use App\Actions\Storage\ImportOfficeReceipts;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| M14 Task 7 — `docs/SAO-LUU-KHOI-PHUC.md` nói đúng điều mã làm (kế hoạch R10)
|--------------------------------------------------------------------------
|
| Tài liệu cho chủ văn phòng là nơi họ học máy văn phòng làm gì. Các lời hứa đo được thì đo bằng chính
| mã: giờ của mục lịch, tên lệnh, đường biên nhận mà script gửi và CRM đọc, phạm vi chỉ đọc của remote
| kho. Câu "thùng rác không phải sao lưu" và ba câu nói thẳng của kế hoạch phải có mặt.
*/

function t7Guide(): string
{
    return (string) file_get_contents(base_path('docs/SAO-LUU-KHOI-PHUC.md'));
}

it('có Phụ lục D cài máy văn phòng, và bảng sao lưu ở chế độ kho', function () {
    $guide = t7Guide();

    expect($guide)->toContain('## Phụ lục D — Máy chủ văn phòng')
        ->and($guide)->toContain('### Khi đã bật kho tài liệu Google Drive (M14)')
        ->and($guide)->toContain('tools/backup/office-pull.sh')
        ->and($guide)->toContain('office-pull.sh --check-monthly')
        ->and($guide)->toContain('DOCUMENT_OFFICE_RECEIPTS_PATH')
        ->and($guide)->toContain('PENDING OWNER');
});

it('nói thẳng: thùng rác và phiên bản Drive không phải sao lưu; archive chỉ còn vùng đệm; tệp trên Kho không được văn phòng mã hoá', function () {
    $guide = t7Guide();

    expect($guide)->toContain('Thùng rác 30 ngày và lịch sử phiên bản của Google Drive KHÔNG phải sao lưu')
        ->and($guide)->toContain('Archive đêm chỉ còn chứa vùng đệm')
        ->and($guide)->toContain('Tệp trên Kho không được văn phòng mã hoá');
});

it('giờ nhập biên nhận trong tài liệu đúng mục lịch, và tên lệnh đúng lệnh có thật', function () {
    $event = collect(Schedule::events())->first(fn (Event $event) => $event->description === 'storage.office-receipts');

    expect($event)->not->toBeNull()
        ->and($event->getExpression())->toBe('0 7 * * *')
        ->and(t7Guide())->toContain('CRM đọc biên nhận lúc 07:00 hằng ngày (`vkcrm:storage:office-receipts`')
        ->and(array_key_exists('vkcrm:storage:office-receipts', Artisan::all()))->toBeTrue();
});

it('đường biên nhận: script gửi vào <thư mục sao lưu>/office-receipts/<thư mục môi trường>, đúng chỗ ví dụ của DOCUMENT_OFFICE_RECEIPTS_PATH', function () {
    $script = (string) file_get_contents(base_path('tools/backup/office-pull.sh'));
    $example = (string) file_get_contents(base_path('tools/backup/office-pull.conf.example'));

    expect($script)->toContain('destination="${BACKUPS_REMOTE}:${BACKUPS_FOLDER}/office-receipts/${ENV_FOLDER}/${receipt_name}"')
        ->and($example)->toContain('BACKUPS_FOLDER="VK-CRM-backups"')
        ->and($example)->toContain('ENV_FOLDER="vk-crm-production"')
        ->and(t7Guide())->toContain('DOCUMENT_OFFICE_RECEIPTS_PATH=gdrive:VK-CRM-backups/office-receipts/vk-crm-production');
});

it('tên tệp biên nhận của script khớp khuôn mà CRM đọc', function () {
    $script = (string) file_get_contents(base_path('tools/backup/office-pull.sh'));

    expect($script)->toContain('receipt_name="receipt-$(date -u +%Y%m%dT%H%M%SZ).json"')
        ->and(preg_match(ImportOfficeReceipts::FILE_PATTERN, 'receipt-'.gmdate('Ymd\THis\Z').'.json'))->toBe(1);
});

it('remote kho của máy văn phòng là drive.readonly: tài liệu dặn, script từ chối chạy khi khác', function () {
    $script = (string) file_get_contents(base_path('tools/backup/office-pull.sh'));

    expect(t7Guide())->toContain('`scope>`: **`drive.readonly`**')
        ->and($script)->toContain('if [ "${SCOPE}" != "drive.readonly" ]; then');
});

it('diễn tập "mất kho" có đủ năm bước của kế hoạch, cộng bước trỏ máy văn phòng sang Kho mới', function () {
    $guide = t7Guide();
    $drill = substr($guide, (int) strpos($guide, '### Diễn tập "mất kho"'));

    expect($drill)->toContain('rclone copy vkoffice:kho vkkhomoi: --immutable')
        ->and($drill)->toContain('GOOGLE_DRIVE_SHARED_DRIVE_ID')
        ->and($drill)->toContain('php artisan vkcrm:storage:reindex --drive=')
        ->and($drill)->toContain('php artisan vkcrm:storage:verify --all')
        ->and($drill)->toContain('thư mục tháng của gốc MỚI')
        ->and($drill)->toContain('`receipted-<ngày>.txt`');
});

it('vai Người đóng góp trên "VK-CRM Backups" thêm VÀ SỬA được tệp: không hứa "chỉ thêm", nêu rủi ro ghi đè archive và bản đối chứng ở văn phòng', function () {
    $guide = (string) preg_replace('/\s+/u', ' ', t7Guide());
    $notes = substr($guide, (int) strpos($guide, '### Những điều cần biết'));

    expect($guide)->not->toContain('chỉ cho thêm tệp')
        ->and($guide)->not->toContain('không cho vào thùng rác được)')
        ->and($guide)->toContain('vai **Người đóng góp** trên "VK-CRM Backups" (thêm và sửa được tệp, không xoá được)')
        ->and($guide)->toContain('Vai Người đóng góp cho **thêm và sửa** tệp')
        ->and($notes)->toContain('**Token `vkbackups` ghi đè được archive.**')
        ->and($notes)->toContain('`ARCHIVE_DIR`')
        ->and($notes)->toContain('`--immutable`');
});

it('gỡ biên nhận bị từ chối, đang chờ hay bị bỏ qua: mục D.9 nói cách, và câu lỗi chỉ tới đúng mục đó', function () {
    $guide = (string) preg_replace('/\s+/u', ' ', t7Guide());
    $recovery = substr($guide, (int) strpos($guide, '### D.9 — '));

    expect(strpos($guide, '### D.9 — '))->not->toBeFalse()
        ->and($recovery)->toContain('`receipted.txt`')
        ->and($recovery)->toContain('`receipted-<ngày>.txt`')
        ->and($recovery)->toContain('php artisan vkcrm:storage:office-receipts')
        ->and($recovery)->toContain('w32tm /resync')
        ->and(__('office_copy.import.rejected'))->toContain('receipted.txt')
        ->and(__('office_copy.import.rejected'))->toContain('D.9')
        ->and(__('office_copy.import.deferred'))->toContain('receipted.txt')
        ->and(__('office_copy.import.deferred'))->toContain('D.9');
});

/*
 * Rà soát cuối M14 vòng sửa 1 (I8): bảng "Bản nào còn lại khi có sự cố" là thứ chủ văn phòng dùng để
 * cân rủi ro. Tài khoản dịch vụ có vai "Người quản lý nội dung": không xoá vĩnh viễn được, nhưng TẢI
 * ĐƯỢC phiên bản mới đè nội dung và cho tệp vào thùng rác — máy chủ web bị chiếm thì Kho không phải bản
 * an toàn. Chỉ bản ở văn phòng (chép thêm với `--immutable`) là an toàn.
 */
it('bảng sự cố: máy chủ web bị chiếm thì chỉ bản ở văn phòng an toàn; Kho có thể bị ghi đè hay cho vào thùng rác', function () {
    preg_match('/^\| Máy chủ web bị chiếm, mã độc tống tiền \|(.*)\|$/mu', t7Guide(), $row);

    expect($row)->toHaveCount(2)
        ->and(trim($row[1]))->toBe('Bản ở văn phòng; Kho có thể bị ghi đè nội dung hay cho vào thùng rác')
        ->and($row[1])->not->toContain('không xoá vĩnh viễn được');
});
