<?php

use App\Enums\Permission;
use App\Filament\Admin\Pages\TeamMember;
use App\Filament\Admin\Pages\TeamOverview;
use App\Support\Performance\ResponseTime;

/*
|--------------------------------------------------------------------------
| `docs/SPEC.md` nói đúng điều mã cuối cùng của M13 làm
|--------------------------------------------------------------------------
|
| Rà soát cuối làn M13, I2: bước "đối chiếu SPEC" của Task 8 được đánh dấu xong, nhưng bốn chỗ của SPEC
| còn tả mã trước các phán quyết: §7.5 nói "Theo dõi đội ngũ" có các cột N1–N11 (phán quyết N11 của Task 4
| chuyển N11 sang trang của một người), §6.14 "Định dạng" in "2 ngày 4 giờ" (`ResponseTime::label()` cố ý
| không gộp ngày: giờ làm việc), đoạn R19 kể sáu việc còn đổi được số kỳ đã đóng trong khi câu giải thích
| trên màn hình kể bảy cộng câu phạm vi xem, và §5 bổ sung M13 đếm "13 + 4 + 1" bỏ ba quyền tiếp nhận của
| M10. Mỗi test đọc CHÍNH đoạn SPEC rồi so với mã hay với câu trên màn hình, để lần lệch sau đỏ ngay.
| Hàm toàn cục mang tiền tố `m13fr1Spec`.
*/

function m13fr1Spec(): string
{
    return (string) file_get_contents(base_path('docs/SPEC.md'));
}

/** Đoạn của SPEC từ `$start` tới trước `$end` (lần xuất hiện đầu tiên sau `$start`). */
function m13fr1SpecSection(string $start, string $end): string
{
    $spec = m13fr1Spec();
    $from = strpos($spec, $start);

    expect($from)->not->toBeFalse("SPEC phải có \"{$start}\"");

    $to = strpos($spec, $end, $from + strlen($start));

    return substr($spec, $from, ($to === false ? strlen($spec) : $to) - $from);
}

/** Văn xuôi của SPEC xuống dòng ở cột 110: gộp mọi khoảng trắng thành một dấu cách trước khi so câu. */
function m13fr1SpecFlat(string $text): string
{
    return (string) preg_replace('/\s+/u', ' ', $text);
}

/** Dòng bảng Markdown của SPEC bắt đầu bằng `$cell` trong đoạn `$section`. */
function m13fr1SpecRow(string $section, string $cell): string
{
    $rows = array_values(array_filter(
        explode("\n", $section),
        fn (string $line): bool => str_starts_with($line, "| {$cell} "),
    ));

    expect($rows)->toHaveCount(1);

    return $rows[0];
}

it('gives Theo dõi đội ngũ exactly the columns the page shows, and puts N11 on the one-person page only', function () {
    $overviewCodes = array_values(array_filter(TeamOverview::EXPLAINED_CODES, fn (string $code): bool => $code !== 'not_applicable'));
    $memberCodes = array_values(array_filter(TeamMember::EXPLAINED_CODES, fn (string $code): bool => $code !== 'not_applicable'));
    $lastOverview = strtoupper(end($overviewCodes));

    $pages = m13fr1SpecSection('### 7.5 Theo dõi đội ngũ và hiệu suất', '- **Điều hướng.**');
    $overviewRow = m13fr1SpecRow($pages, '**Theo dõi đội ngũ**');

    expect($lastOverview)->toBe('N10')
        ->and($overviewRow)->toContain("các cột N1–{$lastOverview}")
        ->and($overviewRow)->not->toContain('N1–N11')
        ->and($overviewRow)->toContain('N11 chỉ ở trang của một người');

    $numbers = m13fr1SpecSection('**Các con số "bây giờ"**', '**Các con số "trong kỳ"**');
    $n11 = m13fr1SpecRow($numbers, 'N11');

    expect(in_array('n11', $memberCodes, true))->toBeTrue()
        ->and(in_array('n11', $overviewCodes, true))->toBeFalse()
        ->and($n11)->toContain('chỉ ở trang của một người');
});

it('prints durations in SPEC exactly as ResponseTime::label does, in hours and never in days', function () {
    $format = m13fr1SpecFlat(m13fr1SpecSection('**Định dạng.**', "\n\n"));

    expect($format)->toContain('"'.ResponseTime::label(3.5).'"')
        ->and($format)->toContain('"'.ResponseTime::label(52).'"')
        ->and($format)->not->toContain('ngày 4 giờ')
        ->and(ResponseTime::label(52))->not->toContain('ngày');
});

it('lists in R19 every change the closed-period explanation on screen lists, and the visibility clause', function () {
    $sentence = __('performance.explain.closed_period');

    // Câu trên màn hình: "… mỗi lần đều có dòng nhật ký: a, b, …, g. Ngoài ra, …".
    preg_match('/dòng nhật ký: (.+?)\. Ngoài ra/u', $sentence, $match);
    expect($match)->toHaveCount(2);

    $changes = array_map('trim', explode(',', $match[1]));
    $r19 = m13fr1SpecFlat(m13fr1SpecSection('**Kỳ đã đóng không trôi (R19).**', "\n\n"));

    expect($changes)->toHaveCount(7)
        ->and($changes)->toContain('huỷ một vụ việc');

    foreach ($changes as $change) {
        expect($r19)->toContain($change);
    }

    expect($r19)->toContain('không còn được xem');
});

it('counts the permissions of the M13 addition as the Permission enum does', function () {
    $addition = m13fr1SpecSection('> **Bổ sung 2026-10-04 (M13 — theo dõi đội ngũ và hiệu suất).**', "\n>\n");

    preg_match('/\((\d+) quyền gốc \+ (\d+) quyền tiền của M9 \+ (\d+) quyền tiếp nhận của M10 \+ (\d+) quyền của M13 = \*\*(\d+)\*\*/u', $addition, $count);

    expect($count)->toHaveCount(6)
        ->and((int) $count[5])->toBe(count(Permission::cases()))
        ->and((int) $count[1] + (int) $count[2] + (int) $count[3] + (int) $count[4])->toBe((int) $count[5])
        ->and((int) $count[4])->toBe(1);
});
