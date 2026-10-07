<?php

/*
|--------------------------------------------------------------------------
| M11 Task 16 — tài liệu triển khai và hướng dẫn kết nối AI nói đúng điều mã đang làm
|--------------------------------------------------------------------------
|
| Đọc CHÍNH tài liệu rồi so với mã, không chép tay danh sách nào ở đây:
|
|  - danh sách PHP extension bắt buộc của `docs/CAI-DAT.md` (Bước 1) và của `README.md` bằng đúng
|    `vkcrm.deployment.required_extensions` — danh sách `vkcrm:preflight` kiểm ĐỎ. Trước Task 16
|    hai tài liệu thiếu `sodium` (M11 Task 1, D5) và `curl` (M11 Task 5), và còn gọi `curl` là
|    "nên có, chưa bắt buộc";
|  - đính chính mới nhất về extension ở SPEC §2 nêu cả hai;
|  - `docs/KET-NOI-AI.md` liệt kê đúng từng redirect URI mà đăng ký client động nhận
|    (`vkcrm.mcp.redirect_uris`), để nhân sự dùng client khác biết khi nào phải nhờ admin;
|  - mọi biến `MCP_*`/`PASSPORT_*` của `.env.example` được `docs/CAI-DAT.md` nêu tên;
|  - Bước 7 của `docs/CAI-DAT.md` nêu ba dòng MCP mới của `vkcrm:preflight`.
*/

/** Mọi tên trong backtick của một đoạn văn bản, theo thứ tự xuất hiện. */
function m11DocBacktickedNames(string $text): array
{
    preg_match_all('/`([a-z0-9_]+)`/', $text, $matches);

    return $matches[1];
}

/** Đoạn văn bản nằm giữa hai mốc (mốc đầu tính từ lần xuất hiện đầu tiên sau `$after`). */
function m11DocBetween(string $document, string $start, string $end, string $after = ''): string
{
    $offset = $after === '' ? 0 : strpos($document, $after);
    expect($offset)->not->toBeFalse("Không thấy mốc \"{$after}\"");

    $from = strpos($document, $start, (int) $offset);
    expect($from)->not->toBeFalse("Không thấy mốc \"{$start}\"");

    $to = strpos($document, $end, (int) $from + strlen($start));
    expect($to)->not->toBeFalse("Không thấy mốc \"{$end}\"");

    return substr($document, (int) $from + strlen($start), (int) $to - (int) $from - strlen($start));
}

function m11Sorted(array $values): array
{
    $values = array_values(array_unique($values));
    sort($values);

    return $values;
}

it('§M11 Task 16 docs/CAI-DAT.md Bước 1 liệt kê đúng danh sách extension mà preflight kiểm ĐỎ', function () {
    $guide = (string) file_get_contents(base_path('docs/CAI-DAT.md'));
    $block = m11DocBetween($guide, 'với ĐỦ các extension sau', 'Đây là kết quả', '### Bước 1 — Máy chủ cần có');

    expect(m11Sorted(m11DocBacktickedNames($block)))
        ->toBe(m11Sorted(config('vkcrm.deployment.required_extensions')))
        ->and(m11DocBacktickedNames($block))->toContain('sodium')->toContain('curl');
});

it('§M11 Task 16 README.md liệt kê đúng danh sách extension mà preflight kiểm ĐỎ', function () {
    $readme = (string) file_get_contents(base_path('README.md'));
    $block = m11DocBetween($readme, '**PHP 8.3 với đủ extension**:', '(nên có thêm');

    expect(m11Sorted(m11DocBacktickedNames($block)))
        ->toBe(m11Sorted(config('vkcrm.deployment.required_extensions')));
});

it('§M11 Task 16 SPEC §2 có đính chính nêu sodium và curl là extension bắt buộc', function () {
    $spec = (string) file_get_contents(base_path('docs/SPEC.md'));
    $section = m11DocBetween($spec, '## 2. Hạ tầng', '## 3. Kiến trúc ứng dụng');
    $correction = m11DocBetween($section, '**Đính chính 2026-10-07 (M11 Task 16).**', "\n\n");

    expect(m11DocBacktickedNames($correction))->toContain('sodium')->toContain('curl')
        ->and($correction)->toContain('required_extensions');
});

it('§M11 Task 16 docs/KET-NOI-AI.md nêu đúng từng redirect URI mà đăng ký client động nhận', function () {
    $guide = (string) file_get_contents(base_path('docs/KET-NOI-AI.md'));
    $uris = array_merge(...array_values(config('vkcrm.mcp.redirect_uris')));

    expect($uris)->not->toBeEmpty();

    foreach ($uris as $uri) {
        expect($guide)->toContain('`'.$uri.'`');
    }
});

it('§M11 Task 16 mọi biến MCP_* và PASSPORT_* của .env.example có tên trong docs/CAI-DAT.md', function () {
    $example = (string) file_get_contents(base_path('.env.example'));
    $guide = (string) file_get_contents(base_path('docs/CAI-DAT.md'));

    preg_match_all('/^#?\s*((?:MCP|PASSPORT)_[A-Z0-9_]+)=/m', $example, $matches);
    $variables = array_values(array_unique($matches[1]));

    expect($variables)->toContain('MCP_MATTER_DEFAULT')->toContain('PASSPORT_PRIVATE_KEY');

    foreach ($variables as $variable) {
        expect($guide)->toContain('`'.$variable.'`');
    }
});

it('§M11 Task 16 docs/CAI-DAT.md Bước 7 nêu các dòng MCP của vkcrm:preflight', function () {
    $guide = (string) file_get_contents(base_path('docs/CAI-DAT.md'));
    $step = m11DocBetween($guide, '### Bước 7 — `vkcrm:preflight`, rồi mới cache cấu hình', '### Bước 8');

    expect($step)->toContain('`mcp.redirect_domains`')
        ->toContain('1 giờ')
        ->toContain('khoá Passport');
});

it('§M11 Task 16 hai tài liệu cho nhân sự có mặt và được README, CAI-DAT trỏ tới', function () {
    expect(file_exists(base_path('docs/CHINH-SACH-AI.md')))->toBeTrue()
        ->and(file_exists(base_path('docs/KET-NOI-AI.md')))->toBeTrue();

    foreach (['README.md', 'docs/CAI-DAT.md'] as $document) {
        expect((string) file_get_contents(base_path($document)))
            ->toContain('docs/CHINH-SACH-AI.md')
            ->toContain('docs/KET-NOI-AI.md');
    }
});
