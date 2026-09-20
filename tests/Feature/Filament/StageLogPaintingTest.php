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
 * Cái bẫy đã bắt được bốn test xanh-vì-lý-do-khác ở Task 6: khẳng định một lớp CSS có mặt trong
 * HTML không chứng minh lớp đó tô ra cái gì. Test này hỏi đúng câu phải hỏi — bảng kiểu dáng
 * thực sự được phục vụ có định nghĩa lớp đó không.
 */
it('emits no CSS class that the served stylesheet does not define', function () {
    $internal = (string) StageLogsRelationManager::renderInternalNote('Ghi chú nội bộ');

    $published = StageLog::factory()->make([
        'is_published' => true,
        'published_at' => now()->subDays(9),
        'public_content' => 'Đã nộp đơn lên toà',
    ]);
    $public = (string) StageLogsRelationManager::renderPublicContent($published->public_content, $published);

    $unknown = array_values(array_filter(
        [...classTokens($internal), ...classTokens($public)],
        fn (string $token): bool => ! classExistsInTheme($token),
    ));

    expect($unknown)->toBe([]);
});

/**
 * Cặp dương của test trên: nếu markup không mang lớp nào thì test kia xanh một cách rỗng tuếch.
 * SPEC §7.2 đòi một cái NỀN, nên ở đây đo đúng cái nền đó — một khai báo `background-color` viết
 * thẳng trong thuộc tính `style`, thứ không phụ thuộc vào bước dựng CSS nào cả.
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
