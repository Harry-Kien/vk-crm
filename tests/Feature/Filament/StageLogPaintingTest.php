<?php

use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Models\StageLog;

/**
 * Bộ test này đo một sự thật của dự án chứ không khẳng định một chuỗi: **panel không có bước dựng
 * CSS**. Máy dev và máy chủ chỉ có PHP trong Docker (CLAUDE.md), nên không có Tailwind nào chạy;
 * panel nạp `vendor/filament/filament/dist/theme.css` đã biên dịch sẵn, và tệp đó chỉ chứa các
 * lớp `fi-*` của chính Filament — KHÔNG một lớp tiện ích Tailwind nào. Hệ quả: một lớp như
 * `bg-gray-100` viết tay trong mã PHP tô ra đúng số không.
 *
 * Đó không phải chuyện thẩm mỹ. SPEC §7.2 đòi mỗi dòng tiến độ "hiện rõ đâu là ghi chú nội bộ
 * (nền xám, có nhãn Nội bộ) và đâu là nội dung đã công bố" — cái nền chính là thứ phân biệt "ghi
 * chú nội bộ" với "đã gửi cho khách" trên màn hình luật sư gõ vào mỗi ngày. Từ M3 tới trước
 * commit này, `renderInternalNote()` dùng `bg-gray-100 dark:bg-gray-700/50` nên yêu cầu đó CHƯA
 * TỪNG được đáp ứng một lần nào, trong khi mã trông như đã đáp ứng.
 */
function themeStylesheet(): string
{
    static $css = null;

    return $css ??= file_get_contents(base_path('vendor/filament/filament/dist/theme.css'));
}

/**
 * Bộ chọn lớp như trình duyệt đọc: Tailwind thoát `:`, `/` và `.` bằng dấu gạch chéo ngược khi
 * biên dịch, nên `dark:bg-gray-700/50` nằm trong tệp CSS dưới dạng `.dark\:bg-gray-700\/50`.
 *
 * @return list<string>
 */
function classTokens(string $html): array
{
    preg_match_all('/class="([^"]*)"/', $html, $matches);

    return array_values(array_unique(array_filter(
        explode(' ', implode(' ', array_map('trim', $matches[1]))),
        fn (string $token): bool => $token !== '',
    )));
}

function classExistsInTheme(string $token): bool
{
    $escaped = str_replace([':', '/', '.'], ['\\:', '\\/', '\\.'], $token);

    return str_contains(themeStylesheet(), '.'.$escaped);
}

/**
 * **Bản trước của test này đã thành VÔ NGHĨA, và nó vô nghĩa vì chính bản sửa mà nó đi kèm.** Nó
 * lọc các lớp CSS phát ra rồi khẳng định danh sách "lớp không có trong bảng kiểu dáng" là rỗng —
 * nhưng khi hai hàm render bỏ hết lớp Tailwind để chuyển sang `style=` viết thẳng, `classTokens()`
 * trả về `[]`, nên bộ lọc trả về `[]` và khẳng định xanh mà không đọc tới một byte nào của markup.
 * Một test không thể đỏ thì tệ hơn không có test.
 *
 * Nay nó đo hai thứ, và cả hai đều đỏ được:
 *
 *  1. **Hai hàm render KHÔNG phát ra một lớp CSS nào.** Đó là quyết định thật của dự án không có
 *     bước dựng CSS, phát biểu thành một khẳng định thay vì thành một bộ lọc rỗng. Ai thêm lại
 *     `bg-gray-100` là đỏ ngay ở đây — và nếu một ngày dự án có bước dựng CSS thì lớp đó vẫn phải
 *     có mặt trong bảng kiểu dáng được phục vụ, nhánh thứ hai của khẳng định canh điều đó.
 *  2. **Mọi biến màu chúng dùng đều là biến `FilamentColor` THẬT SỰ đăng ký.** Đây mới là chỗ
 *     hạng lỗi cũ chuyển sang sống: `var(--grey-500)` hay `var(--primary-450)` không tô gì cả,
 *     đúng như `text-amber-600` đã không tô gì suốt từ M3. Cùng hình dạng phép đo mà
 *     `DocumentsRelationManagerTest` dùng cho bộ chọn của nền dòng nhóm D: lấy tên ra khỏi markup
 *     thật, đối chiếu với nguồn thật.
 */
it('paints with registered colour variables only, and emits no CSS class at all', function () {
    $internal = (string) StageLogsRelationManager::renderInternalNote('Ghi chú nội bộ');

    $published = StageLog::factory()->make([
        'is_published' => true,
        'published_at' => now()->subDays(9),
        'public_content' => 'Đã nộp đơn lên toà',
    ]);
    $public = (string) StageLogsRelationManager::renderPublicContent($published->public_content, $published);

    $markup = $internal.$public;
    $tokens = [...classTokens($internal), ...classTokens($public)];

    expect(array_values(array_filter($tokens, fn (string $token): bool => ! classExistsInTheme($token))))
        ->toBe([])
        ->and($tokens)->toBe([]);

    // Cặp dương của khẳng định dưới: danh sách biến màu KHÔNG rỗng, nên bộ lọc không xanh rỗng
    // tuếch như bản trước.
    expect(colourVariablesIn($markup))->not->toBeEmpty()
        ->and(unregisteredColourVariables($markup))->toBe([]);
});

/**
 * Test trên nói MÀU được lấy từ đâu; test này nói có một cái NỀN để tô — SPEC §7.2 đòi ghi chú
 * nội bộ hiện trên nền xám, và một markup đúng biến màu mà không có khai báo `background-color`
 * nào vẫn là một dòng không có nền.
 *
 * Nói thẳng giới hạn: `toContain('background-color:')` là một khẳng định CHUỖI, và PHP không có
 * bố cục để hỏi xem cái nền ấy có hiện ra hay không. Phép đo thật nằm ở docblock
 * `StageLogsRelationManager::renderInternalNote()` — bật và tắt lớp `.dark` trên trình duyệt,
 * đọc giá trị tính được — chứ không giả vờ là một test.
 */
it('paints the internal note background with an inline declaration', function () {
    $html = (string) StageLogsRelationManager::renderInternalNote('Ghi chú nội bộ');

    expect($html)->toContain('background-color:')
        ->and($html)->toContain(__('matters.stage_log_fields.internal_marker'))
        ->and($html)->toContain('Ghi chú nội bộ');
});

/**
 * SPEC §7.2 / §4.18: nhãn "Khách chưa xem" quá 5 ngày phải TÔ VÀNG. `text-amber-600` cũng là
 * một lớp không tồn tại, nên màu đó chưa từng hiện ra — cùng một lỗi, cùng một tệp, và nó nằm
 * trên chính nhãn nói rằng khách có thể chưa nhận được thông báo nào.
 */
it('paints the overdue read receipt with an inline colour', function () {
    $published = StageLog::factory()->make([
        'is_published' => true,
        'published_at' => now()->subDays(9),
        'public_content' => 'Đã nộp đơn lên toà',
    ]);

    $html = (string) StageLogsRelationManager::renderPublicContent($published->public_content, $published);

    expect(StageLogsRelationManager::readReceiptLabel($published)['highlighted'])->toBeTrue()
        ->and($html)->toContain('color:');
});

/** Chưa quá 5 ngày thì không tô vàng — cặp âm của test trên. */
it('does not highlight a read receipt that is not yet overdue', function () {
    $published = StageLog::factory()->make([
        'is_published' => true,
        'published_at' => now()->subDay(),
        'public_content' => 'Đã nộp đơn lên toà',
    ]);

    expect(StageLogsRelationManager::readReceiptLabel($published)['highlighted'])->toBeFalse();
});
