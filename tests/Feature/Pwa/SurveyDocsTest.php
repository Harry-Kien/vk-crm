<?php

use App\Http\Controllers\DocumentDownloadController;
use App\Models\Document;

/*
|--------------------------------------------------------------------------
| M12 Task 1 — tài liệu khảo sát PWA nói đúng điều Task 3 và chủ văn phòng cần biết
|--------------------------------------------------------------------------
|
| Task 1 không có mã ứng dụng: sản phẩm của nó là ba đoạn văn mà task sau làm theo —
| `docs/research/2026-10-01-pwa-khao-sat.md` (mục 3, phán quyết tạm), "## Ghi chú M12" của
| `docs/PROGRESS.md`, và danh sách kiểm tra máy thật `docs/research/2026-10-01-pwa-kiem-tra-may-that.md`.
| Vòng sửa 1 của rà soát tìm ra hai chỗ mà văn bản dẫn task sau đi sai:
|
|  - I1: route tải bí danh `/admin/documents/{document}/download` chỉ giúp app nội bộ trên iPhone
|    khi liên kết mở TRONG CÙNG CỬA SỔ. Hôm nay nút "Tải tệp" của tab Tài liệu gọi
|    `openUrlInNewTab()` và danh sách tệp trong hộp duyệt của tab Danh mục hồ sơ có
|    `target="_blank"`: tab mới không mở trong cửa sổ app, dù URL nằm trong scope. Văn bản phải nói
|    điều đó cho Task 3, và danh sách kiểm tra phải có bước tải tài liệu trong app nội bộ.
|  - I2: mục A của danh sách kiểm tra chạy SAU Task 3, khi liên kết tải đã nằm trong scope, nên nó
|    không đo được câu hỏi 1 gốc ("trình duyệt trong app có mang cookie ra ngoài scope không").
|    Văn bản không được để câu đó PENDING OWNER chờ mục A, và ghi chú dưới mục A phải nói đúng một
|    mã lỗi có nghĩa là gì (403 hết hạn, 429 quá lượt, 404 không thấy phiên hoặc không có quyền).
|
| Các nhãn và con số trong danh sách kiểm tra được so với nguồn thật (`lang/vi`, hằng số của model
| và controller), để một lần đổi nhãn nút hay thời hạn liên kết làm test đỏ thay vì để chủ văn
| phòng đi tìm một cái nút không còn tên đó.
*/

/** Đoạn của `$text` từ dòng bắt đầu bằng `$start` tới trước dòng bắt đầu bằng `$end` (hoặc hết tệp). */
function pwaSurveySection(string $text, string $start, ?string $end): string
{
    $from = strpos($text, "\n".$start);
    expect($from)->not->toBeFalse("thiếu đoạn bắt đầu bằng {$start}");

    $to = $end === null ? false : strpos($text, "\n".$end, $from + 1);

    return $to === false ? substr($text, $from) : substr($text, $from, $to - $from);
}

/** Một dòng bảng Markdown bắt đầu bằng `| $id |`. */
function pwaSurveyRow(string $section, string $id): string
{
    $matched = preg_match('/^\| '.preg_quote($id, '/').' \|.*$/m', $section, $match);
    expect($matched)->toBe(1, "thiếu dòng bảng {$id}");

    return $match[0];
}

function pwaSurveyFile(string $path): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents(base_path($path)));
}

/**
 * Văn xuôi đã gộp mọi khoảng trắng (kể cả xuống dòng) thành một dấu cách: tài liệu ngắt dòng ở
 * cột 100, nên một cụm từ có thể nằm vắt qua hai dòng — so trên văn bản gốc thì một khẳng định
 * "KHÔNG chứa" sẽ đúng một cách vô nghĩa.
 */
function pwaSurveyFlat(string $text): string
{
    return (string) preg_replace('/\s+/u', ' ', $text);
}

function pwaSurveyResearchRulings(): string
{
    return pwaSurveySection(pwaSurveyFile('docs/research/2026-10-01-pwa-khao-sat.md'), '## 3.', null);
}

function pwaSurveyProgressNotes(): string
{
    return pwaSurveySection(pwaSurveyFile('docs/PROGRESS.md'), '## Ghi chú M12', '## ');
}

function pwaSurveyChecklistSectionA(): string
{
    return pwaSurveySection(pwaSurveyFile('docs/research/2026-10-01-pwa-kiem-tra-may-that.md'), '## A.', '## B.');
}

it('I1: phán quyết tạm 1 nói cho Task 3 rằng liên kết tải của admin phải mở trong cùng cửa sổ', function (): void {
    foreach ([pwaSurveyResearchRulings(), pwaSurveyProgressNotes()] as $text) {
        expect(pwaSurveyFlat($text))
            ->toContain('cùng cửa sổ', 'openUrlInNewTab()', 'DocumentsRelationManager.php')
            ->toContain('target="_blank"', 'ChecklistRelationManager.php');
    }
});

it('I1: danh sách kiểm tra có bước tải tài liệu trong app nội bộ đã cài trên iPhone, gọi đúng tên nút', function (): void {
    $sectionA = pwaSurveyChecklistSectionA();

    $install = pwaSurveyRow($sectionA, 'A7');
    $documentsTab = pwaSurveyRow($sectionA, 'A8');
    $reviewModal = pwaSurveyRow($sectionA, 'A9');

    expect($install)->toContain('/admin')
        ->and($documentsTab)->toContain(__('matters.tabs.documents'))
        ->and($documentsTab)->toContain(__('documents.tab.actions.download'))
        ->and($documentsTab)->toContain('cửa sổ app')
        ->and($reviewModal)->toContain(__('matters.tabs.checklist'))
        ->and($reviewModal)->toContain(__('checklist.tab.actions.accept'))
        ->and($reviewModal)->toContain(__('checklist.tab.fields.documents_label'))
        ->and($reviewModal)->toContain(__('filament-actions::modal.actions.cancel.label'))
        ->and($reviewModal)->toContain('cửa sổ app');

    // Bảng kết quả phải có chỗ ghi các bước mới.
    expect(pwaSurveyFile('docs/research/2026-10-01-pwa-kiem-tra-may-that.md'))->toContain('| A7–A9 |');
});

it('I2: câu hỏi 1 gốc không còn chờ mục A — nó được thay bằng phán quyết tạm', function (): void {
    $researchRow = pwaSurveyRow(pwaSurveyResearchRulings(), '1');
    $progressItem = pwaSurveySection(pwaSurveyProgressNotes(), '1. **', '2. **');

    foreach ([$researchRow, $progressItem] as $text) {
        expect(pwaSurveyFlat($text))
            ->toContain('thay bằng phán quyết tạm')
            ->not->toContain('PENDING OWNER (mục A)')
            ->not->toContain('**PENDING OWNER** (mục A)');
    }
});

it('I2: ghi chú dưới mục A nói đúng mỗi mã lỗi của lượt tải trong scope nghĩa là gì', function (): void {
    $sectionA = pwaSurveyFlat(pwaSurveyChecklistSectionA());

    expect($sectionA)
        ->not->toContain('không mang cookie đăng nhập ra ngoài phạm vi app')
        ->toContain('403')
        ->toContain(Document::DOWNLOAD_LINK_MINUTES.' phút')
        ->toContain('429')
        ->toContain(DocumentDownloadController::DOWNLOADS_PER_MINUTE.' lượt')
        ->toContain('404')
        ->toContain('không đo câu hỏi cookie');
});

/**
 * M12 Task 9 vòng sửa 1 (I1): bốn bậc của một mốc hạn đi dưới CÙNG một `tag` (R11), và thông báo thay
 * một thông báo cùng `tag` còn trong khay thì im lặng trừ khi service worker đặt `renotify`. Văn bản
 * không thể đo chuông/rung — chỉ máy thật đo được, nên danh sách kiểm tra phải có đúng bước đó: để
 * nguyên thông báo bậc d3 trong khay, đưa mốc sang bậc d1, máy phải rung. Câu thông báo, tên tab, nút
 * và nhịp kiểm tra mốc hạn được so với nguồn thật như mọi bước khác của tệp này.
 */
it('Task 9 I1: danh sách kiểm tra có bước để nguyên thông báo d3 trong khay, đưa mốc sang d1, máy phải rung', function (): void {
    $checklist = pwaSurveyFile('docs/research/2026-10-01-pwa-kiem-tra-may-that.md');
    $row = pwaSurveyFlat(pwaSurveyRow(pwaSurveySection($checklist, '## D.', '## E.'), 'D9'));

    expect($row)->toContain(__('deadlines.tab.title'))
        ->toContain(__('deadlines.tab.actions.add'))
        ->toContain(__('deadlines.tab.actions.edit'))
        ->toContain(__('deadlines.tab.fields.due_date'))
        ->toContain(__('push.alerts.staff.deadline.upcoming'))
        ->toContain(__('push.alerts.staff.deadline.imminent'))
        ->toContain('30 phút một lần, 07:00–19:30')
        ->toContain('Để nguyên thông báo đó trong khay')
        ->toContain('rung hoặc đổ chuông')
        ->toContain('**im lặng** (không rung, không chuông) là KHÔNG ĐẠT')
        ->and(pwaSurveyFile('routes/console.php'))->toContain("->cron('*/30 7-19 * * *')")
        ->and($checklist)->toContain('| D1–D9 |')
        ->not->toContain('| D1–D8 |');
});
